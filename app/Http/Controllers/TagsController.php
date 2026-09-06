<?php

namespace App\Http\Controllers;

use App\Models\Server;
use App\Models\Tag;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class TagsController extends Controller
{
    public function index(): View
    {
        $tags = Tag::query()
            ->withCount('servers')
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();

        return view('settings.tags.index', compact('tags'));
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validateTag($request);
        Tag::create($data);

        return redirect()->route('settings.tags.index')->with('status', 'Tag created.');
    }

    public function update(Request $request, Tag $tag): RedirectResponse
    {
        $data = $this->validateTag($request, $tag);
        $tag->update($data);

        return redirect()->route('settings.tags.index')->with('status', 'Tag updated.');
    }

    public function destroy(Tag $tag): RedirectResponse
    {
        $tag->delete();

        return redirect()->route('settings.tags.index')->with('status', 'Tag deleted.');
    }

    public function syncServer(Request $request, Server $server): RedirectResponse
    {
        $data = $request->validate([
            'tags' => ['array'],
            'tags.*' => ['integer', 'exists:tags,id'],
        ]);

        $server->tags()->sync($data['tags'] ?? []);

        return back()->with('status', 'Tags updated.');
    }

    private function validateTag(Request $request, ?Tag $tag = null): array
    {
        $unique = $tag ? 'unique:tags,name,'.$tag->id : 'unique:tags,name';

        return $request->validate([
            'name' => ['required', 'string', 'max:64', $unique],
            'color' => ['required', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'description' => ['nullable', 'string', 'max:255'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:9999'],
        ]);
    }
}
