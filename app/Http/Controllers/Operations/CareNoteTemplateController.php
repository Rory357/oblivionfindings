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
        abort_unless($auth && $this->canAccessTemplates($auth), 403);

        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:255'],
            'status' => ['nullable', 'string', 'in:active,inactive'],
        ]);
        $search = trim((string) ($filters['q'] ?? ''));

        $templates = CareNoteTemplate::query()
            ->when($auth->organization_id, fn ($q) => $q->where('organization_id', $auth->organization_id))
            ->when($search !== '', fn ($q) => $q->where('name', 'like', '%'.$search.'%'))
            ->when(($filters['status'] ?? null) === 'active', fn ($q) => $q->where('is_active', true))
            ->when(($filters['status'] ?? null) === 'inactive', fn ($q) => $q->where('is_active', false))
            ->orderBy('name')
            ->paginate(20)
            ->through(fn (CareNoteTemplate $template) => [
                'id' => $template->id,
                'name' => $template->name,
                'template_type' => $template->template_type ?? 'general',
                'is_active' => (bool) $template->is_active,
                'fields_count' => is_array($template->fields)
                    ? count($template->fields)
                    : count((array) json_decode((string) $template->fields, true)),
                'created_at' => optional($template->created_at)->toISOString(),
            ])
            ->withQueryString();

        return inertia('operations/note-templates/Index', [
            'templates' => $templates,
            'filters' => [
                'q' => $filters['q'] ?? null,
                'status' => $filters['status'] ?? null,
            ],
        ]);
    }

    public function create(Request $request)
    {
        $auth = $request->user();
        abort_unless($auth && $this->canAccessTemplates($auth), 403);

        return inertia('operations/note-templates/Create');
    }

    public function store(Request $request)
    {
        $auth = $request->user();
        abort_unless($auth && $this->canAccessTemplates($auth), 403);

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
        abort_unless($auth && $this->canAccessTemplates($auth), 403);

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
        abort_unless($auth && $this->canAccessTemplates($auth), 403);

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
        abort_unless($auth && $this->canAccessTemplates($auth), 403);

        $template = CareNoteTemplate::query()
            ->when($auth->organization_id, fn ($q) => $q->where('organization_id', $auth->organization_id))
            ->findOrFail($template);

        $template->delete();

        return redirect()->back()->with('success', 'Note template deleted.');
    }

    private function canAccessTemplates($auth): bool
    {
        return $auth->canDo('note_templates.viewAny')
            || $auth->canDo('note_templates.create')
            || $auth->canDo('note_templates.edit')
            || $auth->canDo('note_templates.delete')
            || $auth->canDo('care_note_templates.viewAny');
    }
}
