<?php

namespace App\Http\Controllers\Respite;

use App\Http\Controllers\Controller;
use App\Models\ProcedureTemplate;
use App\Events\Respite\RespiteEvent;
use Illuminate\Http\Request;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class RespiteProcedureTemplateController extends Controller
{
    public function index(): Response
    {
        $templates = ProcedureTemplate::query()
            ->where('domain', 'respite')
            ->orderByDesc('version')
            ->paginate(20);

        return Inertia::render('respite/procedures/index', [
            'templates' => $templates,
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('respite/procedures/create');
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'version' => 'required|integer|min:1',
            'trigger_event' => 'nullable|string|max:255',
            'description' => 'nullable|string',
            'steps_json' => 'required|array',
            'required_roles' => 'nullable|array',
            'active' => 'boolean',
        ]);

        $validated['domain'] = 'respite';
        $validated['created_by'] = auth()->id();

        $template = ProcedureTemplate::create($validated);

        event(new RespiteEvent('respite.procedure_template.created', [
            'id' => $template->id,
            'name' => $template->name,
            'version' => $template->version,
        ]));

        return redirect()
            ->route('respite.procedures.show', $template)
            ->with('success', 'Procedure template created.');
    }

    public function show(ProcedureTemplate $template): Response
    {
        return Inertia::render('respite/procedures/show', [
            'template' => $template,
        ]);
    }

    public function update(Request $request, ProcedureTemplate $template): RedirectResponse
    {
        $validated = $request->validate([
            'description' => 'nullable|string',
            'steps_json' => 'nullable|array',
            'required_roles' => 'nullable|array',
            'active' => 'boolean',
        ]);

        $validated['updated_by'] = auth()->id();
        $template->update($validated);

        event(new RespiteEvent('respite.procedure_template.updated', [
            'id' => $template->id,
            'name' => $template->name,
            'version' => $template->version,
        ]));

        return back()->with('success', 'Procedure template updated.');
    }
}
