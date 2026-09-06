<?php

namespace App\Http\Controllers;

use App\Models\NotificationLog;
use App\Models\NotificationOffWindow;
use App\Models\NotificationRecipient;
use App\Services\Twilio\OnCallResolver;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\View\View;
use Modules\Core\Contracts\SmsNotifier;

/**
 * Settings UI for SMS notifications. Recipients + their off-windows
 * live in the database; the Twilio API credentials live in env. The
 * "Currently on-call" widget at the top is a trust-building view that
 * the operator can glance at any time to know who would be paged right now.
 */
class NotificationSettingsController extends Controller
{
    public function __construct(
        protected OnCallResolver $resolver,
        protected SmsNotifier $sms,
    ) {}

    public function index(): View
    {
        $recipients = NotificationRecipient::with([
            'offWindows' => fn ($q) => $q->orderBy('start_dow')->orderBy('start_time'),
        ])->orderBy('name')->get();

        $onCall = $this->resolver->activeAt(Carbon::now());
        $recentLogs = NotificationLog::with('recipient', 'site')
            ->orderByDesc('sent_at')
            ->limit(20)
            ->get();

        return view('settings.notifications', [
            'recipients' => $recipients,
            'onCall' => $onCall,
            'twilioConfigured' => $this->sms->isConfigured(),
            'twilioFrom' => $this->sms->fromNumber(),
            'recentLogs' => $recentLogs,
        ]);
    }

    public function storeRecipient(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'phone' => ['required', 'string', 'regex:/^\+\d{8,15}$/'],
            'email_fallback' => ['nullable', 'email', 'max:255'],
            'enabled' => ['nullable', 'boolean'],
        ]);

        NotificationRecipient::create([
            'name' => $data['name'],
            'phone' => $data['phone'],
            'email_fallback' => $data['email_fallback'] ?? null,
            'enabled' => (bool) ($data['enabled'] ?? true),
        ]);

        return back()->with('status', "Added {$data['name']}.");
    }

    public function updateRecipient(Request $request, NotificationRecipient $recipient): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'phone' => ['required', 'string', 'regex:/^\+\d{8,15}$/'],
            'email_fallback' => ['nullable', 'email', 'max:255'],
            'enabled' => ['nullable', 'boolean'],
        ]);

        $recipient->update([
            'name' => $data['name'],
            'phone' => $data['phone'],
            'email_fallback' => $data['email_fallback'] ?? null,
            'enabled' => (bool) ($data['enabled'] ?? false),
        ]);

        return back()->with('status', "Updated {$recipient->name}.");
    }

    public function destroyRecipient(NotificationRecipient $recipient): RedirectResponse
    {
        $name = $recipient->name;
        $recipient->delete();

        return back()->with('status', "Removed {$name}.");
    }

    public function storeOffWindow(Request $request, NotificationRecipient $recipient): RedirectResponse
    {
        $data = $request->validate([
            'label' => ['required', 'string', 'max:100'],
            'start_dow' => ['required', 'integer', 'between:0,6'],
            'start_time' => ['required', 'regex:/^\d{1,2}:\d{2}$/'],
            'end_dow' => ['required', 'integer', 'between:0,6'],
            'end_time' => ['required', 'regex:/^\d{1,2}:\d{2}$/'],
            'timezone' => ['nullable', 'string', 'max:64'],
            'enabled' => ['nullable', 'boolean'],
        ]);

        $recipient->offWindows()->create([
            'label' => $data['label'],
            'start_dow' => (int) $data['start_dow'],
            'start_time' => $data['start_time'].':00',
            'end_dow' => (int) $data['end_dow'],
            'end_time' => $data['end_time'].':00',
            'timezone' => $data['timezone'] ?: 'America/New_York',
            'enabled' => (bool) ($data['enabled'] ?? true),
        ]);

        return back()->with('status', "Added off-window \"{$data['label']}\" for {$recipient->name}.");
    }

    public function updateOffWindow(Request $request, NotificationOffWindow $window): RedirectResponse
    {
        $data = $request->validate([
            'label' => ['required', 'string', 'max:100'],
            'start_dow' => ['required', 'integer', 'between:0,6'],
            'start_time' => ['required', 'regex:/^\d{1,2}:\d{2}$/'],
            'end_dow' => ['required', 'integer', 'between:0,6'],
            'end_time' => ['required', 'regex:/^\d{1,2}:\d{2}$/'],
            'timezone' => ['nullable', 'string', 'max:64'],
            'enabled' => ['nullable', 'boolean'],
        ]);

        $window->update([
            'label' => $data['label'],
            'start_dow' => (int) $data['start_dow'],
            'start_time' => $data['start_time'].':00',
            'end_dow' => (int) $data['end_dow'],
            'end_time' => $data['end_time'].':00',
            'timezone' => $data['timezone'] ?: 'America/New_York',
            'enabled' => (bool) ($data['enabled'] ?? false),
        ]);

        return back()->with('status', "Updated off-window \"{$data['label']}\".");
    }

    public function destroyOffWindow(NotificationOffWindow $window): RedirectResponse
    {
        $label = $window->label;
        $window->delete();

        return back()->with('status', "Removed off-window \"{$label}\".");
    }

    public function testRecipient(NotificationRecipient $recipient): RedirectResponse
    {
        if (! $this->sms->isConfigured()) {
            return back()->with('status_error', 'SMS is not configured — install the Twilio module and set TWILIO_* in .env first.');
        }

        $ok = $this->sms->test($recipient);

        return back()->with(
            $ok ? 'status' : 'status_error',
            $ok
                ? "Test SMS sent to {$recipient->name} at {$recipient->phone}."
                : "Test SMS to {$recipient->name} failed — check the notification log for the error."
        );
    }
}
