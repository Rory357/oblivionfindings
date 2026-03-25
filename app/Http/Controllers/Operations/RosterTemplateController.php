<?php

namespace App\Http\Controllers\Operations;

use App\Http\Controllers\Controller;
use App\Models\RosterTemplate;
use App\Models\Shift;
use Carbon\Carbon;
use Illuminate\Http\Request;

class RosterTemplateController extends Controller
{
    public function index(Request $request)
    {
        $auth = $request->user();
        abort_unless($auth && $auth->canDo('rostering.viewAny'), 403);

        $templates = RosterTemplate::query()
            ->when($auth->organization_id, fn ($q) => $q->where('organization_id', $auth->organization_id))
            ->with('creator:id,name')
            ->withCount('templateShifts')
            ->orderByDesc('updated_at')
            ->paginate(25)
            ->withQueryString();

        return inertia('operations/rostering/templates/Index', [
            'templates' => $templates,
        ]);
    }

    public function create(Request $request)
    {
        $auth = $request->user();
        abort_unless($auth && $auth->canDo('rostering.create'), 403);

        return inertia('operations/rostering/templates/Create');
    }

    public function store(Request $request)
    {
        $auth = $request->user();
        abort_unless($auth && $auth->canDo('rostering.create'), 403);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:1000'],
            'template_type' => ['nullable', 'string', 'in:weekly,fortnightly,monthly'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $template = RosterTemplate::create([
            'organization_id' => $auth->organization_id,
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'template_type' => $data['template_type'] ?? 'weekly',
            'is_active' => $data['is_active'] ?? true,
            'created_by' => $auth->id,
        ]);

        return redirect()->route('operations.rostering.templates.show', $template);
    }

    public function show(Request $request, $template)
    {
        $auth = $request->user();
        abort_unless($auth && $auth->canDo('rostering.viewAny'), 403);

        $template = RosterTemplate::where('organization_id', $auth->organization_id)
            ->with([
                'creator:id,name',
                'templateShifts.client:id,first_name,last_name',
                'templateShifts.user:id,name',
            ])
            ->findOrFail($template);

        return inertia('operations/rostering/templates/Show', [
            'template' => $template,
        ]);
    }

    public function edit(Request $request, $template)
    {
        $auth = $request->user();
        abort_unless($auth && $auth->canDo('rostering.edit'), 403);

        $template = RosterTemplate::where('organization_id', $auth->organization_id)
            ->with([
                'templateShifts.client:id,first_name,last_name',
                'templateShifts.user:id,name',
            ])
            ->findOrFail($template);

        return inertia('operations/rostering/templates/Edit', [
            'template' => $template,
        ]);
    }

    public function update(Request $request, $template)
    {
        $auth = $request->user();
        abort_unless($auth && $auth->canDo('rostering.edit'), 403);

        $template = RosterTemplate::where('organization_id', $auth->organization_id)->findOrFail($template);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:1000'],
            'template_type' => ['nullable', 'string', 'in:weekly,fortnightly,monthly'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $template->update([
            'name' => $data['name'],
            'description' => $data['description'] ?? $template->description,
            'template_type' => $data['template_type'] ?? $template->template_type,
            'is_active' => $data['is_active'] ?? $template->is_active,
        ]);

        return redirect()->route('operations.rostering.templates.show', $template);
    }

    public function destroy(Request $request, $template)
    {
        $auth = $request->user();
        abort_unless($auth && $auth->canDo('rostering.delete'), 403);

        $template = RosterTemplate::where('organization_id', $auth->organization_id)->findOrFail($template);
        $template->delete();

        return redirect()->route('operations.rostering.templates.index');
    }

    public function apply(Request $request, $template)
    {
        $auth = $request->user();
        abort_unless($auth && $auth->canDo('rostering.create'), 403);

        $template = RosterTemplate::where('organization_id', $auth->organization_id)
            ->with('templateShifts')
            ->findOrFail($template);

        $data = $request->validate([
            'week_start' => ['required', 'date'],
        ]);

        $weekStart = Carbon::parse($data['week_start'])->startOfDay();

        foreach ($template->templateShifts as $templateShift) {
            $shiftDate = $weekStart->copy()->addDays($templateShift->day_of_week);

            Shift::create([
                'client_id' => $templateShift->client_id,
                'user_id' => $templateShift->user_id,
                'service_context_id' => $templateShift->service_context_id,
                'starts_at' => $shiftDate->copy()->setTimeFromTimeString($templateShift->start_time),
                'ends_at' => $shiftDate->copy()->setTimeFromTimeString($templateShift->end_time),
                'location' => $templateShift->location,
                'status' => 'scheduled',
                'created_by' => $auth->id,
            ]);
        }

        return redirect()->route('operations.rostering.index');
    }
}
