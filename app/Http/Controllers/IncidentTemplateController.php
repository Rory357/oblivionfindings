<?php

namespace App\Http\Controllers;

use App\Models\IncidentTemplate;
use Illuminate\Http\Request;

class IncidentTemplateController extends Controller
{
    public function index(Request $request)
    {
        abort_unless($request->user()?->canDo('incidents.templates.manage'), 403);

        $templates = IncidentTemplate::query()
            ->orderByDesc('is_active')
            ->orderBy('name')
            ->get();

        return inertia('incidents/templates/index', [
            'templates' => $templates,
        ]);
    }

    public function create(Request $request)
    {
        abort_unless($request->user()?->canDo('incidents.templates.manage'), 403);

        return inertia('incidents/templates/edit', [
            'template' => null,
        ]);
    }

    public function store(Request $request)
    {
        abort_unless($request->user()?->canDo('incidents.templates.manage'), 403);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'type' => ['nullable', 'string', 'max:120'],
            'severity' => ['nullable', 'in:low,medium,high'],
            'default_description' => ['nullable', 'string'],
            'prompts' => ['nullable', 'array'],
            'checklist' => ['nullable', 'array'],
            'is_active' => ['boolean'],
        ]);

        $template = IncidentTemplate::create($data);

        return redirect()->route('incidents.templates.edit', $template)->with('success', 'Template created.');
    }

    public function edit(Request $request, IncidentTemplate $template)
    {
        abort_unless($request->user()?->canDo('incidents.templates.manage'), 403);

        return inertia('incidents/templates/edit', [
            'template' => $template,
        ]);
    }

    public function update(Request $request, IncidentTemplate $template)
    {
        abort_unless($request->user()?->canDo('incidents.templates.manage'), 403);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'type' => ['nullable', 'string', 'max:120'],
            'severity' => ['nullable', 'in:low,medium,high'],
            'default_description' => ['nullable', 'string'],
            'prompts' => ['nullable', 'array'],
            'checklist' => ['nullable', 'array'],
            'is_active' => ['boolean'],
        ]);

        $template->update($data);

        return back()->with('success', 'Template updated.');
    }
}
