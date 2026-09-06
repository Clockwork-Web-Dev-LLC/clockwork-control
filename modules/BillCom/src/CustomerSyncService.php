<?php

namespace Modules\BillCom;

use App\Models\Site;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Pulls Bill.com customers + recent invoices, extracts site domains from
 * invoice line item descriptions, links matching sites to customers.
 *
 * Output is the per-site `bill_com_customer_id` + `bill_com_customer_name` +
 * `bill_com_linked_via_invoice` populated for every site we can match. Also
 * refreshes the local `bill_com_customers` cache.
 *
 * Idempotent: re-running with no Bill.com changes is a no-op (apart from
 * touching `synced_at`). Re-linking the same site is fine — last-write-wins
 * and we always pick the most recent invoice as the source.
 *
 * Read-only on the Bill.com side; writes only to the local cache + sites.
 */
class CustomerSyncService
{
    public function __construct(
        protected BillComClient $client,
    ) {}

    /**
     * @return array{
     *     dry_run: bool,
     *     customers_synced: int,
     *     sites_linked: int,
     *     sites_relinked: int,
     *     unmatched_domains: array<string, int>,
     * }
     */
    public function sync(int $windowDays, bool $dryRun = false): array
    {
        $stats = [
            'dry_run' => $dryRun,
            'customers_synced' => 0,
            'sites_linked' => 0,
            'sites_relinked' => 0,
            'unmatched_domains' => [],
        ];

        // 1. Cache customer list locally so per-site UI dropdowns are instant.
        $customerIndex = []; // id => row
        foreach ($this->client->customers() as $row) {
            $id = (string) ($row['id'] ?? '');
            if ($id === '') {
                continue;
            }
            $customerIndex[$id] = $row;

            if (! $dryRun) {
                BillComCustomer::updateOrCreate(
                    ['id' => $id],
                    [
                        'name' => (string) ($row['name'] ?? ''),
                        'company_name' => isset($row['companyName']) ? (string) $row['companyName'] : null,
                        'email' => isset($row['email']) ? (string) $row['email'] : null,
                        'archived' => (bool) ($row['archived'] ?? false),
                        'synced_at' => Carbon::now(),
                    ]
                );
            }
            $stats['customers_synced']++;
        }

        // 2. Walk recent invoices, extract domains from line item descriptions,
        //    link sites. We track most-recent-invoice-per-domain so when a site
        //    appears in multiple invoices we pick the freshest one for the link.
        $since = Carbon::now()->subDays($windowDays);
        $domainToBest = []; // domain => ['customerId', 'customerName', 'invoiceNumber', 'invoiceAt']

        foreach ($this->client->invoicesSince($since) as $invoice) {
            $customerId = (string) ($invoice['customerId'] ?? '');
            $invoiceNumber = (string) ($invoice['invoiceNumber'] ?? $invoice['id'] ?? '');
            $invoiceDate = isset($invoice['invoiceDate']) ? Carbon::parse((string) $invoice['invoiceDate']) : null;
            $lineItems = is_array($invoice['invoiceLineItems'] ?? null) ? $invoice['invoiceLineItems'] : [];
            $customerName = (string) ($customerIndex[$customerId]['name'] ?? '');

            foreach ($lineItems as $li) {
                $desc = (string) ($li['description'] ?? '');
                // Extract ALL domains — some operators' "HOSTING WEBSITES:" lines often
                // name two or three sites that all belong to the same customer.
                foreach (InvoiceDomainExtractor::extractAll($desc) as $domain) {
                    // Pick most recent invoice if we've seen this domain before.
                    $existing = $domainToBest[$domain] ?? null;
                    if ($existing === null
                        || ($invoiceDate && $existing['invoiceAt'] && $invoiceDate->greaterThan($existing['invoiceAt']))
                    ) {
                        $domainToBest[$domain] = [
                            'customerId' => $customerId,
                            'customerName' => $customerName,
                            'invoiceNumber' => $invoiceNumber,
                            'invoiceAt' => $invoiceDate,
                        ];
                    }
                }
            }
        }

        // 3. Apply links to matching sites.
        foreach ($domainToBest as $domain => $best) {
            $site = Site::query()->where('domain', $domain)->first();
            if ($site === null) {
                $stats['unmatched_domains'][$domain] = ($stats['unmatched_domains'][$domain] ?? 0) + 1;

                continue;
            }

            $isRelink = $site->bill_com_customer_id !== null
                && $site->bill_com_customer_id !== $best['customerId'];

            if ($dryRun) {
                Log::info('bill_com.sync.would_link', [
                    'site' => $site->domain,
                    'customer_id' => $best['customerId'],
                    'customer_name' => $best['customerName'],
                    'invoice' => $best['invoiceNumber'],
                ]);
            } else {
                $site->forceFill([
                    'bill_com_customer_id' => $best['customerId'],
                    'bill_com_customer_name' => $best['customerName'],
                    'bill_com_linked_via_invoice' => $best['invoiceNumber'],
                    'bill_com_linked_at' => Carbon::now(),
                ])->save();
            }

            $isRelink ? $stats['sites_relinked']++ : $stats['sites_linked']++;
        }

        return $stats;
    }
}
