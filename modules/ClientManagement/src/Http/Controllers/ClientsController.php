<?php

namespace Modules\ClientManagement\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Site;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Modules\ClientManagement\Models\Client;
use Modules\ClientReports\Models\ClientReport;

class ClientsController extends Controller
{
    /**
     * Display a listing of clients.
     */
    public function index(Request $request): View
    {
        $q = trim((string) $request->query('q', ''));

        $query = Client::query()
            ->withCount(['sites', 'reports'])
            ->with(['sites:id,domain,client_id']);

        if ($q !== '') {
            $query->where(function ($sub) use ($q) {
                $sub->where('name', 'like', "%{$q}%")
                    ->orWhere('company_name', 'like', "%{$q}%")
                    ->orWhere('email', 'like', "%{$q}%")
                    ->orWhereHas('sites', function ($siteQuery) use ($q) {
                        $siteQuery->where('domain', 'like', "%{$q}%");
                    });
            });
        }

        $clients = $query->orderBy('name')->paginate(20)->withQueryString();

        $allSites = Site::query()
            ->where('is_inactive', false)
            ->orderBy('domain')
            ->get(['id', 'domain', 'client_id']);

        $totalClients = Client::count();
        $assignedSitesCount = Site::whereNotNull('client_id')->count();
        $totalReportsCount = ClientReport::whereNotNull('client_id')->count();

        return view('client-management::clients.index', compact(
            'clients',
            'allSites',
            'q',
            'totalClients',
            'assignedSitesCount',
            'totalReportsCount'
        ));
    }

    /**
     * Store a newly created client.
     */
    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'company_name' => ['nullable', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
            'additional_emails' => ['nullable', 'string', 'max:2000'],
            'phone' => ['nullable', 'string', 'max:50'],
            'address' => ['nullable', 'string', 'max:1000'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'site_ids' => ['nullable', 'array'],
            'site_ids.*' => ['integer', 'exists:sites,id'],
        ]);

        $additionalEmails = $this->parseEmails($validated['additional_emails'] ?? null);

        $client = Client::create([
            'name' => $validated['name'],
            'company_name' => $validated['company_name'] ?? null,
            'email' => strtolower(trim($validated['email'])),
            'additional_emails' => $additionalEmails,
            'phone' => $validated['phone'] ?? null,
            'address' => $validated['address'] ?? null,
            'notes' => $validated['notes'] ?? null,
        ]);

        if (! empty($validated['site_ids'])) {
            Site::whereIn('id', $validated['site_ids'])->update(['client_id' => $client->id]);
        }

        return redirect()->route('clients.show', $client)
            ->with('status', "Client '{$client->name}' created successfully.");
    }

    /**
     * Display the specified client with their sites and report history.
     */
    public function show(Client $client): View
    {
        $client->load([
            'sites' => function ($query) {
                $query->orderBy('domain');
            },
            'reports' => function ($query) {
                $query->with('site')->orderByDesc('created_at');
            },
        ]);

        $availableSites = Site::query()
            ->where('is_inactive', false)
            ->where(function ($q) use ($client) {
                $q->whereNull('client_id')
                    ->orWhere('client_id', '!=', $client->id);
            })
            ->orderBy('domain')
            ->get(['id', 'domain', 'client_id']);

        return view('client-management::clients.show', compact('client', 'availableSites'));
    }

    /**
     * Update the specified client.
     */
    public function update(Request $request, Client $client): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'company_name' => ['nullable', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
            'additional_emails' => ['nullable', 'string', 'max:2000'],
            'phone' => ['nullable', 'string', 'max:50'],
            'address' => ['nullable', 'string', 'max:1000'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'site_ids' => ['nullable', 'array'],
            'site_ids.*' => ['integer', 'exists:sites,id'],
        ]);

        $additionalEmails = $this->parseEmails($validated['additional_emails'] ?? null);

        $client->update([
            'name' => $validated['name'],
            'company_name' => $validated['company_name'] ?? null,
            'email' => strtolower(trim($validated['email'])),
            'additional_emails' => $additionalEmails,
            'phone' => $validated['phone'] ?? null,
            'address' => $validated['address'] ?? null,
            'notes' => $validated['notes'] ?? null,
        ]);

        if (array_key_exists('site_ids', $validated)) {
            $selectedSiteIds = array_map('intval', $validated['site_ids'] ?? []);

            // Detach sites no longer selected
            Site::where('client_id', $client->id)
                ->whereNotIn('id', $selectedSiteIds)
                ->update(['client_id' => null]);

            // Attach newly selected sites
            if (! empty($selectedSiteIds)) {
                Site::whereIn('id', $selectedSiteIds)->update(['client_id' => $client->id]);
            }
        }

        return redirect()->route('clients.show', $client)
            ->with('status', 'Client profile updated successfully.');
    }

    /**
     * Remove the specified client.
     */
    public function destroy(Client $client): RedirectResponse
    {
        $name = $client->name;
        $client->delete();

        return redirect()->route('clients.index')
            ->with('status', "Client '{$name}' deleted successfully.");
    }

    /**
     * Quick action to assign a site to this client.
     */
    public function assignSite(Request $request, Client $client): RedirectResponse
    {
        $validated = $request->validate([
            'site_id' => ['required', 'integer', 'exists:sites,id'],
        ]);

        $site = Site::findOrFail($validated['site_id']);
        $site->update(['client_id' => $client->id]);

        return back()->with('status', "Assigned {$site->domain} to {$client->name}.");
    }

    /**
     * Quick action to unassign a site from this client.
     */
    public function unassignSite(Client $client, Site $site): RedirectResponse
    {
        if ($site->client_id === $client->id) {
            $site->update(['client_id' => null]);
        }

        return back()->with('status', "Unassigned {$site->domain} from {$client->name}.");
    }

    /**
     * Parse and sanitize comma or newline-delimited emails into an array.
     *
     * @return array<int, string>
     */
    protected function parseEmails(?string $raw): array
    {
        if (empty($raw)) {
            return [];
        }

        $parts = preg_split('/[\r\n,]+/', $raw);
        $clean = [];

        foreach ($parts as $part) {
            $email = strtolower(trim((string) $part));
            if (! empty($email) && filter_var($email, FILTER_VALIDATE_EMAIL) && ! in_array($email, $clean, true)) {
                $clean[] = $email;
            }
        }

        return array_values($clean);
    }
}
