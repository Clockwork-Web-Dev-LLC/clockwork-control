<?php

namespace Modules\ClientReports\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Modules\ClientReports\Models\ClientReportTemplate;

class TemplatesController extends Controller
{
    public function index(): View
    {
        $templates = ClientReportTemplate::query()
            ->withCount(['reports', 'schedules'])
            ->orderByDesc('is_default')
            ->orderBy('name')
            ->get();

        return view('client-reports::templates.index', compact('templates'));
    }

    public function create(): View
    {
        $sections = ClientReportTemplate::SECTIONS;
        $template = new ClientReportTemplate([
            'sections' => array_keys($sections),
            'is_default' => false,
        ]);

        return view('client-reports::templates.form', compact('template', 'sections'));
    }

    public function store(Request $request): RedirectResponse
    {
        $validKeys = implode(',', array_keys(ClientReportTemplate::SECTIONS));

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'sections' => ['required', 'array', 'min:1'],
            'sections.*' => ['string', "in:{$validKeys}"],
            'is_default' => ['sometimes', 'boolean'],
        ]);

        $isDefault = $request->boolean('is_default');

        if ($isDefault) {
            ClientReportTemplate::query()->update(['is_default' => false]);
        }

        // If this is the very first template, make it default automatically
        if (ClientReportTemplate::query()->count() === 0) {
            $isDefault = true;
        }

        $template = ClientReportTemplate::create([
            'name' => $validated['name'],
            'sections' => array_values($validated['sections']),
            'is_default' => $isDefault,
        ]);

        return redirect()->route('client-reports.templates.index')
            ->with('status', "Template '{$template->name}' created successfully.");
    }

    public function edit(ClientReportTemplate $template): View
    {
        $sections = ClientReportTemplate::SECTIONS;

        return view('client-reports::templates.form', compact('template', 'sections'));
    }

    public function update(Request $request, ClientReportTemplate $template): RedirectResponse
    {
        $validKeys = implode(',', array_keys(ClientReportTemplate::SECTIONS));

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'sections' => ['required', 'array', 'min:1'],
            'sections.*' => ['string', "in:{$validKeys}"],
            'is_default' => ['sometimes', 'boolean'],
        ]);

        $isDefault = $request->boolean('is_default');

        if ($isDefault) {
            ClientReportTemplate::query()->where('id', '!=', $template->id)->update(['is_default' => false]);
        } elseif ($template->is_default) {
            // Cannot unset default if no other default template exists
            $hasOtherDefault = ClientReportTemplate::query()
                ->where('id', '!=', $template->id)
                ->where('is_default', true)
                ->exists();

            if (! $hasOtherDefault) {
                $isDefault = true;
            }
        }

        $template->update([
            'name' => $validated['name'],
            'sections' => array_values($validated['sections']),
            'is_default' => $isDefault,
        ]);

        return redirect()->route('client-reports.templates.index')
            ->with('status', "Template '{$template->name}' updated successfully.");
    }

    public function destroy(ClientReportTemplate $template): RedirectResponse
    {
        if ($template->is_default) {
            return back()->with('status_error', 'Cannot delete the default template. Set another template as default first.');
        }

        if (ClientReportTemplate::count() <= 1) {
            return back()->with('status_error', 'Cannot delete the only remaining report template.');
        }

        $name = $template->name;
        $template->delete();

        return redirect()->route('client-reports.templates.index')
            ->with('status', "Template '{$name}' deleted.");
    }
}
