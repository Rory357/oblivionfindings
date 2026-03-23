<?php

namespace App\Http\Controllers\Operations;

use App\Http\Controllers\Controller;
use App\Models\CareNoteTemplate;
use Illuminate\Http\Request;

class CareNoteTemplateController extends Controller
{
    public function index(Request $request)
    {
        $auth = $request->user();
        abort_unless($auth && $auth->canDo('note_templates.viewAny'), 403);

        $templates = CareNoteTemplate::query()
            ->when($auth->organization_id, fn ($q) => $q->where('organization_id', $auth->organization_id))
            ->orderBy('name')
            ->paginate(20)
            ->withQueryString();

        return inertia('operations/note-templates/Index', [
            'templates' => $templates,
        ]);
    }

    public function create(Request $request)
    {
        $auth = $request->user();
        abort_unless($auth && $auth->canDo('note_templates.create'), 403);

        return inertia('operations/note-templates/Create');
    }

    public function store(Request $request)
    {
        $auth = $request->user();
        abort_unless($auth && $auth->canDo('note_templates.create'), 403);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'fields' => ['required', 'array'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        CareNoteTemplate::create([
            'organization_id' => $auth->organization_id,
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'fields' => $data['fields'],
            'is_active' => $data['is_active'] ?? true,
            'created_by' => $auth->id,
        ]);

        return redirect()->back()->with('success', 'Note template created.');
    }

    public function edit(Request $request, $template)
    {
        $auth = $request->user();
        abort_unless($auth && $auth->canDo('note_templates.edit'), 403);

        $template = CareNoteTemplate::query()
            ->when($auth->organization_id, fn ($q) => $q->where('organization_id', $auth->organization_id))
            ->findOrFail($template);

        return inertia('operations/note-templates/Edit', [
            'template' => $template,
        ]);
    }

    public function update(Request $request, $template)
    {
        $auth = $request->user();
        abort_unless($auth && $auth->canDo('note_templates.edit'), 403);

        $template = CareNoteTemplate::query()
            ->when($auth->organization_id, fn ($q) => $q->where('organization_id', $auth->organization_id))
            ->findOrFail($template);

        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'fields' => ['sometimes', 'required', 'array'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $template->update($data);

        return redirect()->back()->with('success', 'Note template updated.');
    }

    public function destroy(Request $request, $template)
    {
        $auth = $request->user();
        abort_unless($auth && $auth->canDo('note_templates.delete'), 403);

        $template = CareNoteTemplate::query()
            ->when($auth->organization_id, fn ($q) => $q->where('organization_id', $auth->organization_id))
            ->findOrFail($template);

        $template->delete();

        return redirect()->back()->with('success', 'Note template deleted.');
    }
}
