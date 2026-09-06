<?php

namespace Modules\BillCom;

use App\Models\Site;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Walks Bill.com Items + recent invoices to determine which sites are actively
 * on a care plan, then flips care_plan_enabled on those sites.
 *
 * RESPECTS the manual override: care_plan_override IS NOT NULL means the human
 * has set this explicitly and the sync must not touch care_plan_enabled.
 *
 * Algorithm (line-item-level, not customer-level):
 *   1. Pull Items, classify each via name regex (default /care plan/i).
 *      Cache the classification; respect existing manual overrides on items
 *      (regex_set=false means a human last classified, don't overwrite).
 *   2. Walk invoices in the care-plan window. For each LINE ITEM that
 *      references a care-plan-classified Item, extract every domain from
 *      THAT line item's description. Each extracted domain is a "site that's
 *      currently on a care plan."
 *   3. For every site WHERE care_plan_override IS NULL:
 *      - If the site's domain is in the active set → set care_plan_enabled = true.
 *      - Otherwise → set care_plan_enabled = false.
 *   4. Sites with care_plan_override IS NOT NULL are skipped.
 *
 * Why line-item-level not customer-level: a single customer can have multiple
 * sites, where some are care-plan'd and some aren't. One real customer, for
 * example, hosts client-l.example AND client-m.example — only the latter
 * is on a care plan. The customer-level algorithm flagged both incorrectly.
 *
 * Trade-off: care-plan line items whose description has NO domain (e.g. a
 * generic "Monthly maintenance" line) won't flag any site. That's a feature,
 * not a bug — silent flagging from generic line items would re-introduce the
 * ambiguous-line-item problem this rewrite fixed. If a real client lacks a
 * domain in their care-plan line item's description, the operator sets
 * care_plan_override on the site manually.
 */
class CarePlanSyncService
{
    public function __construct(
        protected BillComClient $client,
    ) {}

    /**
     * @return array{
     *     dry_run: bool,
     *     items_synced: int,
     *     care_plan_items_count: int,
     *     active_care_plan_domains: int,
     *     active_care_plan_customers: int,
     *     unmatched_care_plan_domains: array<string, int>,
     *     care_plan_lineitems_without_domain: int,
     *     sites_set_true: int,
     *     sites_set_false: int,
     *     sites_skipped_due_to_override: int,
     * }
     */
    public function sync(int $windowDays, string $itemRegex, bool $dryRun = false): array
    {
        $stats = [
            'dry_run' => $dryRun,
            'items_synced' => 0,
            'care_plan_items_count' => 0,
            'active_care_plan_domains' => 0,
            'active_care_plan_customers' => 0,
            'unmatched_care_plan_domains' => [],
            'care_plan_lineitems_without_domain' => 0,
            'sites_set_true' => 0,
            'sites_set_false' => 0,
            'sites_skipped_due_to_override' => 0,
        ];

        // 1. Sync + classify Items.
        $carePlanItemIds = [];
        foreach ($this->client->items() as $row) {
            $id = (string) ($row['id'] ?? '');
            $name = (string) ($row['name'] ?? '');
            if ($id === '' || $name === '') {
                continue;
            }
            $stats['items_synced']++;

            $existing = BillComCarePlanItem::find($id);
            $regexMatches = preg_match($itemRegex, $name) === 1;

            // Don't clobber a human-set classification.
            if ($existing && ! $existing->regex_set) {
                $isCarePlan = $existing->is_care_plan;
            } else {
                $isCarePlan = $regexMatches;
            }

            if ($isCarePlan) {
                $carePlanItemIds[$id] = true;
                $stats['care_plan_items_count']++;
            }

            if (! $dryRun) {
                BillComCarePlanItem::updateOrCreate(
                    ['id' => $id],
                    [
                        'name' => $name,
                        'is_care_plan' => $isCarePlan,
                        'regex_set' => $existing && ! $existing->regex_set ? false : true,
                        'synced_at' => Carbon::now(),
                    ]
                );
            }
        }

        // 2. Walk invoices and build TWO active sets:
        //    a. Domain-level: extracted from line item descriptions when present
        //       (the precise case — used when a customer has multiple sites and
        //       only some are on care plans).
        //    b. Customer-level fallback: when a care-plan line item has NO
        //       extractable domain, mark the customer. Annual care-plan invoices
        //       often have generic descriptions like "WordPress Care Plan -
        //       12 months of backups, security scans..." with no per-site domain.
        //       For those, every site linked to the customer gets flagged.
        //
        // Trade-off: a customer with multiple sites all linked, only some
        // actually on a care plan, gets ALL their linked sites flagged when
        // there's an annual no-domain invoice. Mitigation: care_plan_override
        // lets the operator demote per-site manually.
        $since = Carbon::now()->subDays($windowDays);
        $activeDomains = []; // domain => true
        $activeCustomers = []; // customerId => true

        foreach ($this->client->invoicesSince($since) as $invoice) {
            $customerId = (string) ($invoice['customerId'] ?? '');
            $lineItems = is_array($invoice['invoiceLineItems'] ?? null) ? $invoice['invoiceLineItems'] : [];
            foreach ($lineItems as $li) {
                $itemId = (string) ($li['itemId'] ?? '');
                if ($itemId === '' || ! isset($carePlanItemIds[$itemId])) {
                    continue;
                }

                $desc = (string) ($li['description'] ?? '');
                $domains = InvoiceDomainExtractor::extractAll($desc);
                if ($domains === []) {
                    $stats['care_plan_lineitems_without_domain']++;
                    if ($customerId !== '') {
                        $activeCustomers[$customerId] = true;
                    }

                    continue;
                }
                foreach ($domains as $domain) {
                    $activeDomains[$domain] = true;
                }
            }
        }
        $stats['active_care_plan_domains'] = count($activeDomains);
        $stats['active_care_plan_customers'] = count($activeCustomers);

        // 3. Apply to sites — only those without a manual override.
        $matchedDomains = [];
        foreach (Site::query()->whereNotNull('bill_com_customer_id')->get() as $site) {
            if ($site->care_plan_override !== null) {
                $stats['sites_skipped_due_to_override']++;

                continue;
            }

            $domainHit = isset($activeDomains[$site->domain]);
            $customerHit = isset($activeCustomers[$site->bill_com_customer_id]);
            $shouldBeOnPlan = $domainHit || $customerHit;
            if ($domainHit) {
                $matchedDomains[$site->domain] = true;
            }

            if ($site->care_plan_enabled === $shouldBeOnPlan) {
                continue; // no change
            }

            if ($dryRun) {
                Log::info('bill_com.care_plan_sync.would_change', [
                    'site' => $site->domain,
                    'from' => $site->care_plan_enabled,
                    'to' => $shouldBeOnPlan,
                    'reason' => $domainHit ? 'domain_match' : 'customer_match',
                ]);
            } else {
                $site->forceFill(['care_plan_enabled' => $shouldBeOnPlan])->save();
            }

            $shouldBeOnPlan ? $stats['sites_set_true']++ : $stats['sites_set_false']++;
        }

        // Active care-plan domains that didn't match any Site row — surface
        // these so the operator can see "Bill.com says X is on a care plan but X
        // isn't in Clockwork yet."
        foreach ($activeDomains as $domain => $_) {
            if (! isset($matchedDomains[$domain])) {
                $stats['unmatched_care_plan_domains'][$domain] = 1;
            }
        }

        return $stats;
    }
}
