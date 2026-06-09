<?php

namespace App\Http\Controllers\Operations;

use App\Domain\Rostering\RosterPublishValidator;
use App\Domain\Shifts\Lifecycle\ShiftLifecycleService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Operations\Rostering\ApplyRosterTemplateRequest;
use App\Http\Requests\Operations\Rostering\StoreRosterTemplateRequest;
use App\Http\Requests\Operations\Rostering\UpdateRosterTemplateRequest;
use App\Models\RosterTemplate;
use App\Models\Shift;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

class RosterTemplateController extends Controller
{
    public function store(StoreRosterTemplateRequest $request)
    {
        $auth = $request->user();
        abort_unless($this->canCreateTemplates($auth), 403);

        $data = $request->validated();

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

        return redirect()
            ->route('operations.rostering.index', ['tab' => 'templates'])
            ->with('status', 'Roster template created.');
    }

    public function update(UpdateRosterTemplateRequest $request, $template)
    {
        $auth = $request->user();
        abort_unless($this->canUpdateTemplates($auth), 403);

        $template = RosterTemplate::where('organization_id', $auth->organization_id)->findOrFail($template);
        $data = $request->validated();

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

        return redirect()
            ->route('operations.rostering.index', ['tab' => 'templates'])
            ->with('status', 'Roster template updated.');
    }

    public function destroy(Request $request, $template)
    {
        $auth = $request->user();
        abort_unless($this->canDeleteTemplates($auth), 403);

        $template = RosterTemplate::where('organization_id', $auth->organization_id)->findOrFail($template);
        $template->delete();

        return redirect()
            ->route('operations.rostering.index', ['tab' => 'templates'])
            ->with('status', 'Roster template deleted.');
    }

    public function apply(
        ApplyRosterTemplateRequest $request,
        $template,
        ShiftLifecycleService $lifecycle,
        RosterPublishValidator $validator,
    ) {
        $auth = $request->user();
        abort_unless($this->canUpdateTemplates($auth), 403);

        $template = RosterTemplate::where('organization_id', $auth->organization_id)
            ->with([
                'templateShifts.client.site',
                'templateShifts.serviceContext.site',
                'templateShifts.user',
            ])
            ->findOrFail($template);

        $data = $request->validated();

        $weekStart = Carbon::parse($data['week_start'])->startOfDay();

        $idempotencyKey = $this->templateApplyIdempotencyKey($template, $weekStart, $auth);

        if (Cache::has($idempotencyKey)) {
            return redirect()
                ->route('operations.rostering.index', ['tab' => 'templates'])
                ->with('status', 'This template was already applied by you for that week in the last hour; no duplicate shifts were created.');
        }

        $occurrences = $this->buildTemplateOccurrences($template, $weekStart, $auth);
        $preflight = $validator->validateProposedShifts($occurrences->pluck('proposed'));

        if ($preflight['blocks'] !== []) {
            throw ValidationException::withMessages([
                'preflight_blocks' => $this->formatPreflightMessages(
                    $preflight['blocks'],
                    'Template apply is blocked. Resolve these conflicts before creating shifts.',
                ),
            ]);
        }

        if ($preflight['warnings'] !== [] && ! (bool) ($data['confirm_warnings'] ?? false)) {
            throw ValidationException::withMessages([
                'preflight_warnings' => $this->formatPreflightMessages(
                    $preflight['warnings'],
                    'Review these warnings before applying the template.',
                ),
            ]);
        }

        if (! Cache::add($idempotencyKey, now()->toIso8601String(), now()->addHour())) {
            return redirect()
                ->route('operations.rostering.index', ['tab' => 'templates'])
                ->with('status', 'This template was already applied by you for that week in the last hour; no duplicate shifts were created.');
        }

        try {
            DB::transaction(function () use ($occurrences, $auth, $lifecycle): void {
                foreach ($occurrences as $occurrence) {
                    $shift = $lifecycle->create($occurrence['attributes'], $auth);

                    if ($occurrence['assignee'] instanceof User) {
                        $lifecycle->assign($shift, $auth, $occurrence['assignee']);
                    }
                }
            });
        } catch (Throwable $exception) {
            Cache::forget($idempotencyKey);

            throw $exception;
        }

        return redirect()
            ->route('operations.rostering.index', ['week' => $weekStart->toDateString()])
            ->with('status', 'Roster template applied.');
    }

    private function buildTemplateOccurrences(RosterTemplate $template, Carbon $weekStart, User $auth): Collection
    {
        return $template->templateShifts->values()->map(function ($templateShift) use ($weekStart, $auth): array {
            $shiftDate = $weekStart->copy()->addDays($templateShift->day_of_week);
            $window = $this->buildOccurrenceWindow(
                $shiftDate,
                (string) $templateShift->start_time,
                (string) $templateShift->end_time,
            );
            $client = $templateShift->client_id ? $templateShift->client : null;
            $serviceContext = $templateShift->service_context_id ? $templateShift->serviceContext : null;
            $site = $client?->site ?: $serviceContext?->site;
            $assignee = $templateShift->user_id ? $templateShift->user : null;

            $attributes = [
                'organization_id' => $auth->organization_id,
                'client_id' => $templateShift->client_id,
                'site_id' => $site?->id,
                'user_id' => null,
                'service_context_id' => $templateShift->service_context_id,
                'starts_at' => $window['starts_at'],
                'ends_at' => $window['ends_at'],
                'location' => $templateShift->location,
                'notes' => $templateShift->notes,
                'status' => 'draft',
                'shift_type' => $templateShift->shift_type ?? 'standard',
                'is_sleepover' => (bool) $templateShift->is_sleepover,
                'is_on_call' => (bool) $templateShift->is_on_call,
                'expected_break_minutes' => $templateShift->expected_break_minutes,
                'created_by' => $auth->id,
            ];

            $proposed = Shift::make([
                ...$attributes,
                'user_id' => $templateShift->user_id,
            ]);

            if ($client) {
                $proposed->setRelation('client', $client);
            }

            if ($site) {
                $proposed->setRelation('site', $site);
            }

            if ($serviceContext) {
                $proposed->setRelation('serviceContext', $serviceContext);
            }

            if ($assignee) {
                $proposed->setRelation('staff', $assignee);
            }

            return [
                'attributes' => $attributes,
                'assignee' => $assignee,
                'proposed' => $proposed,
            ];
        });
    }

    private function formatPreflightMessages(array $issues, string $heading): string
    {
        return collect($issues)
            ->map(function (array $issue): string {
                $parts = [
                    isset($issue['template_row']) ? 'Row '.$issue['template_row'] : null,
                    $issue['client'] ?? null,
                    $issue['staff'] ?? null,
                    $issue['starts_at'] ?? null,
                    $issue['message'] ?? null,
                ];

                return collect($parts)->filter()->implode(' - ');
            })
            ->prepend($heading)
            ->implode("\n");
    }

    private function templateApplyIdempotencyKey(RosterTemplate $template, Carbon $weekStart, User $auth): string
    {
        return 'rostering:template-apply:'.sha1(implode('|', [
            $auth->organization_id ?? 'global',
            $template->id,
            $weekStart->toDateString(),
            $auth->id,
        ]));
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
