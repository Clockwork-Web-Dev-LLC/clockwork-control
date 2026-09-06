<?php

namespace Modules\ContactForms;

use App\Http\Controllers\Controller;
use App\Models\ContactFormTest;
use App\Models\Site;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class FormsController extends Controller
{
    /**
     * Top-nav Forms page — fleet view of every configured form-test
     * across every site. Filtered by state, frequency, and a free-text
     * site search.
     */
    public function index(Request $request): View
    {
        $q = ContactFormTest::query()
            ->with(['site.server'])
            ->orderByDesc('state_changed_at')
            ->orderBy('site_id')
            ->orderBy('slot');

        $stateFilter = (string) $request->query('state', 'all');
        if (in_array($stateFilter, [
            ContactFormTest::STATE_PENDING,
            ContactFormTest::STATE_SUCCESS,
            ContactFormTest::STATE_FAILED,
        ], true)) {
            $q->where('state', $stateFilter);
        }

        $frequencyFilter = (string) $request->query('frequency', 'all');
        if (in_array($frequencyFilter, [
            ContactFormTest::FREQUENCY_DAILY,
            ContactFormTest::FREQUENCY_WEEKLY,
        ], true)) {
            $q->where('frequency', $frequencyFilter);
        }

        $search = trim((string) $request->query('q', ''));
        if ($search !== '') {
            $q->whereHas('site', fn ($q) => $q->where('domain', 'like', '%'.$search.'%'));
        }

        return view('dashboard.forms.index', [
            'tests' => $q->get(),
            'stateFilter' => $stateFilter,
            'frequencyFilter' => $frequencyFilter,
            'search' => $search,
        ]);
    }

    /**
     * Add a new form-test to a site. Enforces care-plan + 3-cap.
     */
    public function store(Request $request, Site $site): RedirectResponse
    {
        if (! $site->care_plan_enabled) {
            return back()->with('status_error', 'Contact form testing is part of the care plan. Enable the care plan on this site first.');
        }

        if ($site->contactFormTests()->count() >= ContactFormTest::MAX_PER_SITE) {
            return back()->with('status_error', 'Maximum of '.ContactFormTest::MAX_PER_SITE.' forms per site. Remove one to add another.');
        }

        $validated = $request->validate([
            'form_id' => ['required', 'string', 'max:64'],
            'form_plugin' => ['nullable', 'string', 'max:32'],
            'form_url' => ['nullable', 'url', 'max:255'],
            // Daily is accepted unconditionally; the UI only exposes it on
            // ?admin=1, but the controller stays plain so tinker / DB edits
            // also work.
            'frequency' => ['required', 'in:daily,weekly'],
        ]);

        // Dedupe — UNIQUE (site_id, form_id) would 500 the request otherwise.
        if ($site->contactFormTests()->where('form_id', $validated['form_id'])->exists()) {
            return back()->with('status_error', 'A form-test with form ID "'.$validated['form_id'].'" already exists on this site.');
        }

        $nextSlot = $this->nextSlot($site);

        ContactFormTest::create([
            'site_id' => $site->id,
            'slot' => $nextSlot,
            'form_id' => $validated['form_id'],
            'form_plugin' => (string) ($validated['form_plugin'] ?? $site->contact_form_plugin ?? ''),
            'form_url' => $validated['form_url'] ?? null,
            'frequency' => $validated['frequency'],
            'enabled' => true,
            'state' => ContactFormTest::STATE_PENDING,
        ]);

        return redirect()->route('sites.show', ['site' => $site, 'tab' => 'forms'])
            ->with('status', 'Form-test added.');
    }

    public function update(Request $request, Site $site, ContactFormTest $cft): RedirectResponse
    {
        $this->guard($site, $cft);

        $validated = $request->validate([
            'form_id' => ['nullable', 'string', 'max:64'],
            'form_plugin' => ['nullable', 'string', 'max:32'],
            'form_url' => ['nullable', 'url', 'max:255'],
            'frequency' => ['nullable', 'in:daily,weekly'],
            'enabled' => ['nullable', 'boolean'],
        ]);

        $cft->fill(array_filter($validated, fn ($v) => $v !== null && $v !== ''));
        if (array_key_exists('enabled', $validated)) {
            $cft->enabled = (bool) $validated['enabled'];
        }
        $cft->save();

        return redirect()->route('sites.show', ['site' => $site, 'tab' => 'forms'])
            ->with('status', 'Form-test updated.');
    }

    public function destroy(Site $site, ContactFormTest $cft): RedirectResponse
    {
        $this->guard($site, $cft);
        $cft->delete();

        return redirect()->route('sites.show', ['site' => $site, 'tab' => 'forms'])
            ->with('status', 'Form-test removed.');
    }

    public function testNow(Site $site, ContactFormTest $cft, ContactFormTester $tester): JsonResponse
    {
        $this->guard($site, $cft);
        $result = $tester->test($cft, 'lab');

        return response()->json($result, $result['result'] === ContactFormTester::RESULT_FAILED ? 422 : 200);
    }

    private function guard(Site $site, ContactFormTest $cft): void
    {
        abort_unless($cft->site_id === $site->id, 404);
    }

    private function nextSlot(Site $site): int
    {
        $taken = $site->contactFormTests()->pluck('slot')->all();
        for ($i = 1; $i <= ContactFormTest::MAX_PER_SITE; $i++) {
            if (! in_array($i, $taken, true)) {
                return $i;
            }
        }
        // Shouldn't reach here — count check above prevents it.
        abort(409, 'No slot available.');
    }
}
