<?php

namespace App\Http\Controllers\Operations;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\RosterTemplate;
use App\Models\ServiceContext;
use App\Models\Shift;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class RosterTemplateController extends Controller
{
    public function index(Request $request)
    {
        $auth = $request->user();
        abort_unless($this->canViewTemplates($auth), 403);

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
        abort_unless($this->canCreateTemplates($auth), 403);

        return inertia('operations/rostering/templates/Create', [
            ...$this->formOptions(),
        ]);
    }

    public function store(Request $request)
    {
        $auth = $request->user();
        abort_unless($this->canCreateTemplates($auth), 403);

        $data = $this->validateTemplatePayload($request);

        $template = RosterTemplate::create([
            'organization_id' => $auth->organization_id,
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'template_type' => $data['template_type'] ?? 'weekly',
            'is_active' => $data['is_active'] ?? true,
            'created_by' => $auth->id,
        ]);

        $template->templateShifts()->createMany(
            collect($data['template_shifts'])
                ->map(fn (array $row) => [
                    ...$this->normalizeTemplateShift($row),
                    'organization_id' => $auth->organization_id,
                ])
                ->all()
        );

        return redirect()->route('operations.rostering.templates.show', $template);
    }

    public function show(Request $request, $template)
    {
        $auth = $request->user();
        abort_unless($this->canViewTemplates($auth), 403);

        $template = RosterTemplate::where('organization_id', $auth->organization_id)
            ->with([
                'creator:id,name',
                'templateShifts.client:id,first_name,last_name',
                'templateShifts.user:id,name',
                'templateShifts.serviceContext:id,name,type',
            ])
            ->findOrFail($template);

        return inertia('operations/rostering/templates/Show', [
            'template' => $template,
        ]);
    }

    public function edit(Request $request, $template)
    {
        $auth = $request->user();
        abort_unless($this->canUpdateTemplates($auth), 403);

        $template = RosterTemplate::where('organization_id', $auth->organization_id)
            ->with([
                'templateShifts.client:id,first_name,last_name',
                'templateShifts.user:id,name',
                'templateShifts.serviceContext:id,name,type',
            ])
            ->findOrFail($template);

        return inertia('operations/rostering/templates/Edit', [
            'template' => $template,
            ...$this->formOptions(),
        ]);
    }

    public function update(Request $request, $template)
    {
        $auth = $request->user();
        abort_unless($this->canUpdateTemplates($auth), 403);

        $template = RosterTemplate::where('organization_id', $auth->organization_id)->findOrFail($template);
        $data = $this->validateTemplatePayload($request);

        $template->update([
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'template_type' => $data['template_type'] ?? $template->template_type,
            'is_active' => $data['is_active'] ?? $template->is_active,
        ]);

        $template->templateShifts()->delete();
        $template->templateShifts()->createMany(
            collect($data['template_shifts'])
                ->map(fn (array $row) => [
                    ...$this->normalizeTemplateShift($row),
                    'organization_id' => $auth->organization_id,
                ])
                ->all()
        );

        return redirect()->route('operations.rostering.templates.show', $template);
    }

    public function destroy(Request $request, $template)
    {
        $auth = $request->user();
        abort_unless($this->canDeleteTemplates($auth), 403);

        $template = RosterTemplate::where('organization_id', $auth->organization_id)->findOrFail($template);
        $template->delete();

        return redirect()->route('operations.rostering.templates.index');
    }

    public function apply(Request $request, $template)
    {
        $auth = $request->user();
        abort_unless($this->canUpdateTemplates($auth), 403);

        $template = RosterTemplate::where('organization_id', $auth->organization_id)
            ->with('templateShifts')
            ->findOrFail($template);

        $data = $request->validate([
            'week_start' => ['required', 'date'],
        ]);

        $weekStart = Carbon::parse($data['week_start'])->startOfDay();

        foreach ($template->templateShifts as $templateShift) {
            $shiftDate = $weekStart->copy()->addDays($templateShift->day_of_week);
            $window = $this->buildOccurrenceWindow(
                $shiftDate,
                (string) $templateShift->start_time,
                (string) $templateShift->end_time,
            );

            Shift::create([
                'client_id' => $templateShift->client_id,
                'user_id' => $templateShift->user_id,
                'service_context_id' => $templateShift->service_context_id,
                'starts_at' => $window['starts_at'],
                'ends_at' => $window['ends_at'],
                'location' => $templateShift->location,
                'notes' => $templateShift->notes,
                'status' => 'scheduled',
                'shift_type' => $templateShift->shift_type ?? 'standard',
                'is_sleepover' => (bool) $templateShift->is_sleepover,
                'is_on_call' => (bool) $templateShift->is_on_call,
                'expected_break_minutes' => $templateShift->expected_break_minutes,
                'created_by' => $auth->id,
            ]);
        }

        return redirect()->route('operations.rostering.index');
    }

    private function validateTemplatePayload(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:1000'],
            'template_type' => ['nullable', 'string', 'in:weekly,fortnightly,monthly'],
            'is_active' => ['nullable', 'boolean'],
            'template_shifts' => ['required', 'array', 'min:1'],
            'template_shifts.*.client_id' => ['nullable', 'integer', 'exists:clients,id'],
            'template_shifts.*.user_id' => ['nullable', 'integer', 'exists:users,id'],
            'template_shifts.*.service_context_id' => ['nullable', 'integer', 'exists:service_contexts,id'],
            'template_shifts.*.day_of_week' => ['required', 'integer', 'min:0', 'max:6'],
            'template_shifts.*.start_time' => ['required', 'date_format:H:i'],
            'template_shifts.*.end_time' => ['required', 'date_format:H:i'],
            'template_shifts.*.shift_type' => ['nullable', 'string', 'in:standard,sleepover,on_call,split,travel'],
            'template_shifts.*.is_sleepover' => ['nullable', 'boolean'],
            'template_shifts.*.is_on_call' => ['nullable', 'boolean'],
            'template_shifts.*.expected_break_minutes' => ['nullable', 'integer', 'min:0', 'max:720'],
            'template_shifts.*.required_skills' => ['nullable', 'array'],
            'template_shifts.*.required_skills.*' => ['string', 'max:100'],
            'template_shifts.*.location' => ['nullable', 'string', 'max:255'],
            'template_shifts.*.notes' => ['nullable', 'string', 'max:2000'],
        ]);
    }

    private function normalizeTemplateShift(array $row): array
    {
        if (empty($row['client_id']) && empty($row['service_context_id'])) {
            throw ValidationException::withMessages([
                'template_shifts' => 'Each template shift must be linked to a client or a service context.',
            ]);
        }

        if (($row['start_time'] ?? null) === ($row['end_time'] ?? null)) {
            throw ValidationException::withMessages([
                'template_shifts' => 'Template shift start and end times cannot be the same.',
            ]);
        }

        $row['shift_type'] = $row['shift_type'] ?? 'standard';
        $row['is_sleepover'] = (bool) ($row['is_sleepover'] ?? false);
        $row['is_on_call'] = (bool) ($row['is_on_call'] ?? false);

        if ($row['shift_type'] === 'sleepover') {
            $row['is_sleepover'] = true;
        }

        if ($row['shift_type'] === 'on_call') {
            $row['is_on_call'] = true;
        }

        return [
            'client_id' => $row['client_id'] ?: null,
            'user_id' => $row['user_id'] ?: null,
            'service_context_id' => $row['service_context_id'] ?: null,
            'day_of_week' => (int) $row['day_of_week'],
            'start_time' => $row['start_time'],
            'end_time' => $row['end_time'],
            'shift_type' => $row['shift_type'],
            'is_sleepover' => $row['is_sleepover'],
            'is_on_call' => $row['is_on_call'],
            'expected_break_minutes' => filled($row['expected_break_minutes'] ?? null)
                ? (int) $row['expected_break_minutes']
                : null,
            'required_skills' => array_values(array_filter($row['required_skills'] ?? [])),
            'location' => $row['location'] ?: null,
            'notes' => $row['notes'] ?: null,
        ];
    }

    private function buildOccurrenceWindow(Carbon $shiftDate, string $startTime, string $endTime): array
    {
        $startsAt = $shiftDate->copy()->setTimeFromTimeString($startTime);
        $endsAt = $shiftDate->copy()->setTimeFromTimeString($endTime);

        if ($endsAt->lessThanOrEqualTo($startsAt)) {
            $endsAt->addDay();
        }

        return [
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
        ];
    }

    private function formOptions(): array
    {
        return [
            'clients' => Client::query()
                ->with('site:id,name')
                ->orderBy('first_name')
                ->get(['id', 'first_name', 'last_name', 'service_context_id', 'site_id']),
            'staff' => User::staff()->orderBy('name')->get(['id', 'name', 'email']),
            'serviceContexts' => ServiceContext::query()
                ->orderBy('name')
                ->get(['id', 'name', 'type', 'is_active']),
        ];
    }

    private function canViewTemplates($auth): bool
    {
        return (bool) $auth && ($auth->canDo('roster_templates.viewAny') || $auth->canDo('rostering.viewAny'));
    }

    private function canCreateTemplates($auth): bool
    {
        return (bool) $auth && ($auth->canDo('roster_templates.create') || $auth->canDo('rostering.create'));
    }

    private function canUpdateTemplates($auth): bool
    {
        return (bool) $auth && ($auth->canDo('roster_templates.update') || $auth->canDo('rostering.edit'));
    }

    private function canDeleteTemplates($auth): bool
    {
        return (bool) $auth && ($auth->canDo('roster_templates.delete') || $auth->canDo('rostering.delete'));
    }
}
