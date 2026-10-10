<?php

namespace App\Http\Controllers\Operations;

use App\Domain\Rostering\RosterPublishValidator;
use App\Domain\Shifts\Lifecycle\ShiftLifecycleService;
use App\Domain\Shifts\Lifecycle\ShiftLifecycleSource;
use App\Http\Controllers\Controller;
use App\Http\Requests\Operations\Rostering\ApplyRosterTemplateRequest;
use App\Http\Requests\Operations\Rostering\StoreRosterTemplateRequest;
use App\Http\Requests\Operations\Rostering\UpdateRosterTemplateRequest;
use App\Models\Client;
use App\Models\RosterTemplate;
use App\Models\ServiceContext;
use App\Models\Shift;
use App\Models\User;
use App\Services\Operations\RosterTemplateAccessService;
use App\Services\Operations\RosterTemplateCommand;
use App\Services\Operations\RosterTemplateReceipt;
use App\Services\UserSiteAccessService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Throwable;

class RosterTemplateController extends Controller
{
    public function __construct(private readonly UserSiteAccessService $siteAccess) {}

    public function index(Request $request)
    {
        $actor = $request->user();
        abort_unless($actor, 403);
        $access = app(RosterTemplateAccessService::class);
        $capabilities = $access->capabilities($actor);
        abort_unless($capabilities['can_view'], 403);
        $request->validate(['week' => ['nullable', 'date']]);
        $timezone = $this->templateTimezone();
        $week = Carbon::parse($request->input('week') ?: now($timezone), $timezone)->startOfWeek(Carbon::MONDAY)->toDateString();
        if ($actor->canDo('rostering.viewAny')) {
            return redirect()->route('operations.rostering.index', ['tab' => 'templates', 'week' => $week]);
        }
        $options = $access->options($actor);

        return Inertia::render('operations/rostering/template-workspace', [
            'rosterTemplates' => $access->templates($actor), 'templateCapabilities' => $capabilities,
            'templateOptions' => $options, ...$options, 'workerTimezone' => $timezone, 'week' => $week,
            'urls' => ['index' => route('operations.rostering.templates.index'),
                'store' => $capabilities['can_create'] ? route('operations.rostering.templates.store') : null, 'roster' => null],
        ]);
    }

    public function store(StoreRosterTemplateRequest $request)
    {
        return $this->libraryCommand($request, 'create', $request->validated());
    }

    public function update(UpdateRosterTemplateRequest $request, $template)
    {
        return $this->libraryCommand($request, 'update', $request->validated(), (int) $template);
    }

    public function destroy(Request $request, $template)
    {
        return $this->libraryCommand($request, 'delete', [], (int) $template);
    }

    public function duplicate(Request $request, $template)
    {
        return $this->libraryCommand($request, 'duplicate', [], (int) $template);
    }

    private function libraryCommand(Request $request, string $action, array $values, ?int $templateId = null)
    {
        $receipts = app(RosterTemplateReceipt::class);
        $root = $receipts->begin($request);
        $actor = $request->user();
        abort_unless($actor, 403);
        $modern = $request->header('X-Roster-Template-Result') === 'committed-v1';
        $rules = ['week' => ['nullable', 'date']];
        if ($modern) {
            $rules['request_id'] = ['required', 'uuid'];
        }
        if ($action === 'create') {
            $rules['expected_source'] = ['prohibited'];
        } elseif ($modern || $request->exists('expected_source')) {
            $rules += ['expected_source' => ['required', 'array:template_id,source_revision'],
                'expected_source.template_id' => ['required', 'integer', 'min:1'],
                'expected_source.source_revision' => ['required', 'string', 'regex:/^[a-f0-9]{64}$/']];
        }
        $data = $request->validate($rules);
        $expected = isset($data['expected_source']) ? ['template_id' => (int) $data['expected_source']['template_id'],
            'source_revision' => $data['expected_source']['source_revision']] : null;
        // Resolve the redirect date before a write, with no post-commit read.
        $timezone = $this->templateTimezone();
        $week = Carbon::parse($data['week'] ?? now($timezone), $timezone)->startOfWeek(Carbon::MONDAY)->toDateString();
        $result = app(RosterTemplateCommand::class)->execute($actor, $action, $values, $templateId, $expected, $modern ? $data['request_id'] : null);
        $receipt = $receipts->committed($root, $result);
        $weekParameters = $modern || $request->filled('week') ? ['week' => $week] : [];
        $url = $result->canViewRoster
            ? route('operations.rostering.index', ['tab' => 'templates', ...$weekParameters])
            : route('operations.rostering.templates.index', $weekParameters);
        $response = redirect()->to($url);
        if ($modern) {
            $response->setStatusCode(303);
        }
        if ($receipt === null) {
            return $response->with('warning', 'The roster template result could not be confirmed. Reload the library before trying again.');
        }
        $status = match ($action) {
            'create' => 'Roster template created.', 'update' => 'Roster template updated.',
            'delete' => 'Roster template deleted.', 'duplicate' => 'Roster template duplicated.',
        };

        return $response->with('status', $status)->with('roster_template_result', $receipt);
    }

    private function templateTimezone(): string
    {
        return (string) (config('app.worker_timezone') ?: config('app.timezone') ?: 'UTC');
    }

    private function duplicateName(string $name): string
    {
        $base = trim(preg_replace('/\s*\(copy(?:\s+\d+)?\)$/i', '', $name)) ?: $name;
        $candidate = $base.' (copy)';
        $counter = 2;

        while (RosterTemplate::query()->where('name', $candidate)->exists()) {
            $candidate = $base.' (copy '.$counter.')';
            $counter++;
        }

        return $candidate;
    }

    public function apply(
        ApplyRosterTemplateRequest $request,
        $template,
        ShiftLifecycleService $lifecycle,
        RosterPublishValidator $validator,
    ) {
        $auth = $request->user();
        abort_unless($this->canUpdateTemplates($auth), 403);

        $template = RosterTemplate::query()
            ->with([
                'templateShifts.client.site',
                'templateShifts.serviceContext.site',
                'templateShifts.user',
            ])
            ->findOrFail($template);
        $this->assertTemplateRowsAccessible($auth, $template->templateShifts->all());

        $data = $request->validated();

        // Always anchor on the Monday of the chosen week. The pattern's day_of_week
        // (0 = Monday) is offset from here, so a non-Monday date must not be allowed
        // to silently shift the whole week.
        $weekStart = Carbon::parse($data['week_start'])->startOfWeek(Carbon::MONDAY)->startOfDay();

        // Stamp the one-week pattern across N cadence cycles. weekly = every week,
        // fortnightly = every 2nd week, monthly = every 4th week. cycles defaults to 1.
        $cycles = max(1, min(12, (int) ($data['cycles'] ?? 1)));
        $intervalWeeks = $this->cadenceIntervalWeeks($template->template_type);

        $idempotencyKey = $this->templateApplyIdempotencyKey($template, $weekStart, $auth, $cycles, $intervalWeeks);

        if (Cache::has($idempotencyKey)) {
            return redirect()
                ->route('operations.rostering.index', ['tab' => 'templates'])
                ->with('status', 'This template was already applied by you for that week in the last hour; no duplicate shifts were created.');
        }

        $occurrences = collect();
        for ($cycle = 0; $cycle < $cycles; $cycle++) {
            $anchor = $weekStart->copy()->addWeeks($cycle * $intervalWeeks);
            $occurrences = $occurrences->concat($this->buildTemplateOccurrences($template, $anchor, $auth));
        }
        $occurrences = $occurrences->values();

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

        $bulkOverrideRequest = (bool) ($data['confirm_warnings'] ?? false)
            ? [
                'override_acknowledged' => true,
                'override_reason' => 'Applied confirmed roster-template eligibility warnings after current recheck.',
            ]
            : null;

        try {
            DB::transaction(function () use ($occurrences, $auth, $lifecycle, $bulkOverrideRequest): void {
                foreach ($occurrences as $occurrence) {
                    $shift = $lifecycle->create(
                        $occurrence['attributes'],
                        $auth,
                        ShiftLifecycleSource::Bulk,
                    );

                    if ($occurrence['assignee'] instanceof User) {
                        $lifecycle->assign(
                            $shift,
                            $auth,
                            $occurrence['assignee'],
                            $bulkOverrideRequest,
                            source: ShiftLifecycleSource::Bulk,
                        );
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
                'is_lone_worker' => (bool) $templateShift->is_lone_worker,
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

    private function templateApplyIdempotencyKey(RosterTemplate $template, Carbon $weekStart, User $auth, int $cycles = 1, int $intervalWeeks = 1): string
    {
        return 'rostering:template-apply:'.sha1(implode('|', [
            $template->id,
            $weekStart->toDateString(),
            $auth->id,
            $cycles,
            $intervalWeeks,
        ]));
    }

    /**
     * Template rows must retain canonical Client ownership and may only refer
     * to Clients, service contexts and staff from Sites available to the actor.
     * The template catalogue itself is shared by this single application; Site
     * provenance lives on each row's references.
     *
     * @param  array<int, array<string, mixed>>  $rows
     */
    private function assertTemplateRowsAccessible(User $actor, array $rows): void
    {
        $siteIds = $this->siteAccess->accessibleSiteIds($actor, ['shifts.manageAny']);
        abort_if($siteIds === [], 403, UserSiteAccessService::DEFAULT_MESSAGE);

        if (collect($rows)->contains(function ($row): bool {
            $clientId = data_get($row, 'client_id');

            return ! is_numeric($clientId) || (int) $clientId < 1;
        })) {
            throw ValidationException::withMessages([
                'template_shifts' => 'Each template shift must be linked to a client.',
            ]);
        }

        $clientIds = collect($rows)->pluck('client_id')->filter()->map(fn ($id) => (int) $id)->unique();
        $serviceContextIds = collect($rows)->pluck('service_context_id')->filter()->map(fn ($id) => (int) $id)->unique();
        $staffIds = collect($rows)->pluck('user_id')->filter()->map(fn ($id) => (int) $id)->unique();

        $visibleClients = Client::query()
            ->whereKey($clientIds->all())
            ->whereIn('site_id', $siteIds)
            ->get(['id', 'site_id'])
            ->keyBy('id');
        abort_unless($visibleClients->count() === $clientIds->count(), 403, UserSiteAccessService::DEFAULT_MESSAGE);

        $visibleServiceContexts = collect();
        if ($serviceContextIds->isNotEmpty()) {
            $visibleServiceContexts = ServiceContext::query()
                ->availableToSites($siteIds)
                ->whereKey($serviceContextIds->all())
                ->get(['id', 'site_id', 'is_active'])
                ->keyBy('id');
            abort_unless($visibleServiceContexts->count() === $serviceContextIds->count(), 403, UserSiteAccessService::DEFAULT_MESSAGE);
        }

        if ($staffIds->isNotEmpty()) {
            $visibleStaffCount = $this->siteAccess
                ->applyStaffScope(User::query(), $actor, ['shifts.manageAny'])
                ->whereKey($staffIds)
                ->count();
            abort_unless($visibleStaffCount === $staffIds->count(), 403, UserSiteAccessService::DEFAULT_MESSAGE);
        }

        foreach ($rows as $row) {
            $serviceContextId = data_get($row, 'service_context_id');
            if (! is_numeric($serviceContextId) || (int) $serviceContextId < 1) {
                continue;
            }

            $client = $visibleClients->get((int) data_get($row, 'client_id'));
            $serviceContext = $visibleServiceContexts->get((int) $serviceContextId);
            if (! $client instanceof Client
                || ! $serviceContext instanceof ServiceContext
                || ! $serviceContext->is_active
                || ($serviceContext->site_id !== null
                    && (int) $serviceContext->site_id !== (int) $client->site_id)
            ) {
                throw ValidationException::withMessages([
                    'template_shifts' => 'Each service context must be active and available to the template shift client\'s Site.',
                ]);
            }
        }
    }

    private function cadenceIntervalWeeks(?string $cadence): int
    {
        return match ($cadence) {
            'fortnightly' => 2,
            'monthly' => 4,
            default => 1,
        };
    }

    private function normalizeTemplateShift(array $row): array
    {
        if (empty($row['client_id'])) {
            throw ValidationException::withMessages([
                'template_shifts' => 'Each template shift must be linked to a client.',
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
        $row['is_lone_worker'] = (bool) ($row['is_lone_worker'] ?? false);

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
            'is_lone_worker' => $row['is_lone_worker'],
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
