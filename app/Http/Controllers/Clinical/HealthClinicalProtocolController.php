<?php

namespace App\Http\Controllers\Clinical;

use App\Domain\Clinical\Enums\ObservationType;
use App\Domain\Clinical\Enums\ProtocolFrequency;
use App\Domain\Clinical\Models\ClinicalProtocol;
use App\Domain\Clinical\Services\ClinicalDashboardService;
use App\Domain\Clinical\Services\ClinicalSiteAccessService;
use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Response;

class HealthClinicalProtocolController extends Controller
{
    public function __construct(
        private readonly ClinicalDashboardService $dashboardService,
        private readonly ClinicalSiteAccessService $siteAccess,
    ) {}

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', ClinicalProtocol::class);

        $filters = $request->validate([
            'client_id' => ['nullable', 'integer', 'exists:clients,id'],
            'observation_type' => ['nullable', 'string', Rule::in($this->observationTypeValues())],
            'frequency' => ['nullable', 'string', Rule::in($this->frequencyValues())],
            'status' => ['nullable', 'string', 'in:active,inactive'],
        ]);

        if (! empty($filters['client_id'])) {
            $this->siteAccess->assertCanAccessClient(
                $request->user(),
                Client::query()->findOrFail((int) $filters['client_id']),
            );
        }

        $protocols = $this->dashboardService
            ->getProtocolRegister($request->user(), $filters)
            ->through(fn (ClinicalProtocol $protocol) => $this->serializeProtocol($protocol));

        $kpis = $this->dashboardService->getKpis($request->user());

        return inertia('health-clinical/Protocols', [
            'protocols' => $protocols,
            'stats' => $this->dashboardService->getProtocolRegisterStats($request->user()),
            'kpis' => $kpis,
            'tab_counts' => $this->dashboardService->getTabCounts($request->user(), $kpis),
            'filters' => $filters,
            'filter_options' => $this->filterOptions($request->user()),
            'can_manage' => $request->user()?->canDo('clinical.protocols.manage') ?? false,
        ]);
    }

    public function create(Request $request): Response
    {
        $this->authorize('create', ClinicalProtocol::class);

        return inertia('health-clinical/protocols/Create', [
            'form_options' => $this->formOptions($request->user()),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('create', ClinicalProtocol::class);

        $data = $this->validatedProtocolData($request);
        // Per-client assignment guard (mirrors storeObservation/storeEvent) — without
        // it a client_id swap would attach a protocol to a client outside the actor's
        // remit, altering that client's observation schedule + missed-obs alerts.
        $this->authorize('view', Client::findOrFail($data['client_id']));

        $protocol = ClinicalProtocol::create([
            ...$data,
            'created_by' => $request->user()->id,
        ]);

        return redirect()
            ->route('health-clinical.protocols.index')
            ->with('success', "Protocol {$protocol->name} created.");
    }

    public function edit(Request $request, ClinicalProtocol $protocol): Response
    {
        $this->authorize('update', $protocol);

        $protocol->load(['client:id,first_name,last_name', 'creator:id,name'])
            ->loadCount([
                'schedules',
                'schedules as pending_schedules_count' => fn ($query) => $query->where('status', 'pending'),
                'schedules as overdue_schedules_count' => fn ($query) => $query
                    ->where('status', 'pending')
                    ->where('due_at', '<', now()),
                'schedules as completed_schedules_30d_count' => fn ($query) => $query
                    ->where('status', 'completed')
                    ->where('completed_at', '>=', now()->subDays(30)),
            ]);

        return inertia('health-clinical/protocols/Edit', [
            'protocol' => $this->serializeProtocol($protocol),
            'form_options' => $this->formOptions($request->user()),
            'can_edit_structure' => ((int) $protocol->schedules_count) === 0,
        ]);
    }

    public function update(Request $request, ClinicalProtocol $protocol): RedirectResponse
    {
        $this->authorize('update', $protocol);
        $this->authorize('view', $protocol->loadMissing('client')->client);

        $protocol->update($this->validatedProtocolData($request, $protocol));

        return redirect()
            ->route('health-clinical.protocols.index')
            ->with('success', "Protocol {$protocol->name} updated.");
    }

    public function toggleActive(Request $request, ClinicalProtocol $protocol): RedirectResponse
    {
        $this->authorize('update', $protocol);
        $this->authorize('view', $protocol->loadMissing('client')->client);

        $protocol->update([
            'is_active' => ! $protocol->is_active,
        ]);

        return back()->with(
            'success',
            $protocol->is_active
                ? "Protocol {$protocol->name} activated."
                : "Protocol {$protocol->name} deactivated."
        );
    }

    /**
     * @return array{
     *     clients: Collection<int, Client>,
     *     observation_types: array<int, array{value: string, label: string}>,
     *     frequencies: array<int, array{value: string, label: string}>,
     *     statuses: array<int, array{value: string, label: string}>,
     * }
     */
    private function filterOptions(User $user): array
    {
        return [
            'clients' => $this->siteAccess->applyClientScope(Client::query(), $user)
                ->orderBy('first_name')
                ->get(['id', 'first_name', 'last_name']),
            'observation_types' => $this->observationTypeOptions(),
            'frequencies' => $this->frequencyOptions(),
            'statuses' => [
                ['value' => 'active', 'label' => 'Active'],
                ['value' => 'inactive', 'label' => 'Inactive'],
            ],
        ];
    }

    /**
     * @return array{
     *     clients: Collection<int, Client>,
     *     observation_types: array<int, array{value: string, label: string}>,
     *     frequencies: array<int, array{value: string, label: string}>,
     * }
     */
    private function formOptions(User $user): array
    {
        return [
            'clients' => $this->siteAccess->applyClientScope(Client::query(), $user)
                ->orderBy('first_name')
                ->get(['id', 'first_name', 'last_name']),
            'observation_types' => $this->observationTypeOptions(),
            'frequencies' => $this->frequencyOptions(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function validatedProtocolData(Request $request, ?ClinicalProtocol $protocol = null): array
    {
        $commonRules = [
            'name' => ['required', 'string', 'max:255'],
            'instructions' => ['nullable', 'string', 'max:4000'],
            'alert_if_missed_hours' => ['required', 'integer', 'min:1', 'max:8760'],
            'is_active' => ['nullable', 'boolean'],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date', 'after_or_equal:starts_at'],
        ];

        if (! $protocol) {
            $validated = $request->validate([
                'client_id' => ['required', 'integer', 'exists:clients,id'],
                'observation_type' => ['required', 'string', Rule::in($this->observationTypeValues())],
                'frequency' => ['required', 'string', Rule::in($this->frequencyValues())],
                'custom_frequency_hours' => [
                    'nullable',
                    'integer',
                    'min:1',
                    'max:8760',
                    Rule::requiredIf($request->input('frequency') === ProtocolFrequency::Custom->value),
                ],
                ...$commonRules,
            ]);

            return $this->normaliseProtocolData($validated, true);
        }

        $hasScheduleHistory = $protocol->schedules()->exists();

        $structuralRules = $hasScheduleHistory
            ? [
                'observation_type' => ['prohibited'],
                'frequency' => ['prohibited'],
                'custom_frequency_hours' => ['prohibited'],
            ]
            : [
                'observation_type' => ['required', 'string', Rule::in($this->observationTypeValues())],
                'frequency' => ['required', 'string', Rule::in($this->frequencyValues())],
                'custom_frequency_hours' => [
                    'nullable',
                    'integer',
                    'min:1',
                    'max:8760',
                    Rule::requiredIf($request->input('frequency') === ProtocolFrequency::Custom->value),
                ],
            ];

        $validated = $request->validate([
            'client_id' => ['prohibited'],
            ...$structuralRules,
            ...$commonRules,
        ]);

        return $this->normaliseProtocolData($validated, false, $protocol);
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    private function normaliseProtocolData(array $validated, bool $isCreate, ?ClinicalProtocol $protocol = null): array
    {
        $frequency = $validated['frequency']
            ?? $protocol?->frequency?->value;

        if ($frequency !== ProtocolFrequency::Custom->value) {
            $validated['custom_frequency_hours'] = null;
        }

        $validated['name'] = trim((string) $validated['name']);
        $validated['instructions'] = filled($validated['instructions'] ?? null)
            ? trim((string) $validated['instructions'])
            : null;
        $validated['starts_at'] = $validated['starts_at'] ?? null;
        $validated['ends_at'] = $validated['ends_at'] ?? null;
        $validated['is_active'] = $validated['is_active']
            ?? ($isCreate ? true : $protocol?->is_active ?? true);

        return $validated;
    }

    /**
     * @return array{
     *     id: int,
     *     client_id: int,
     *     name: string,
     *     observation_type: string,
     *     observation_type_label: string,
     *     frequency: string,
     *     frequency_label: string,
     *     custom_frequency_hours: int|null,
     *     instructions: string|null,
     *     alert_if_missed_hours: int,
     *     is_active: bool,
     *     starts_at: string|null,
     *     ends_at: string|null,
     *     created_at: string|null,
     *     updated_at: string|null,
     *     client: array{id: int, first_name: string, last_name: string}|null,
     *     creator: array{id: int, name: string}|null,
     *     schedule_counts: array{total: int, pending: int, overdue: int, completed_30d: int},
     *     has_schedule_history: bool,
     * }
     */
    private function serializeProtocol(ClinicalProtocol $protocol): array
    {
        $protocol->loadMissing(['client:id,first_name,last_name', 'creator:id,name']);

        return [
            'id' => $protocol->id,
            'client_id' => $protocol->client_id,
            'name' => $protocol->name,
            'observation_type' => $protocol->observation_type->value,
            'observation_type_label' => $protocol->observation_type->label(),
            'frequency' => $protocol->frequency->value,
            'frequency_label' => $protocol->frequency->label(),
            'custom_frequency_hours' => $protocol->custom_frequency_hours,
            'instructions' => $protocol->instructions,
            'alert_if_missed_hours' => $protocol->alert_if_missed_hours,
            'is_active' => $protocol->is_active,
            'starts_at' => $protocol->starts_at?->toDateString(),
            'ends_at' => $protocol->ends_at?->toDateString(),
            'created_at' => $protocol->created_at?->toISOString(),
            'updated_at' => $protocol->updated_at?->toISOString(),
            'client' => $protocol->client ? [
                'id' => $protocol->client->id,
                'first_name' => $protocol->client->first_name,
                'last_name' => $protocol->client->last_name,
            ] : null,
            'creator' => $protocol->creator ? [
                'id' => $protocol->creator->id,
                'name' => $protocol->creator->name,
            ] : null,
            'schedule_counts' => [
                'total' => (int) ($protocol->schedules_count ?? 0),
                'pending' => (int) ($protocol->pending_schedules_count ?? 0),
                'overdue' => (int) ($protocol->overdue_schedules_count ?? 0),
                'completed_30d' => (int) ($protocol->completed_schedules_30d_count ?? 0),
            ],
            'has_schedule_history' => ((int) ($protocol->schedules_count ?? 0)) > 0,
        ];
    }

    /**
     * @return array<int, array{value: string, label: string}>
     */
    private function observationTypeOptions(): array
    {
        return array_map(
            fn (ObservationType $type) => [
                'value' => $type->value,
                'label' => $type->label(),
            ],
            ObservationType::cases(),
        );
    }

    /**
     * @return array<int, string>
     */
    private function observationTypeValues(): array
    {
        return array_map(
            fn (ObservationType $type) => $type->value,
            ObservationType::cases(),
        );
    }

    /**
     * @return array<int, array{value: string, label: string}>
     */
    private function frequencyOptions(): array
    {
        return array_map(
            fn (ProtocolFrequency $frequency) => [
                'value' => $frequency->value,
                'label' => $frequency->label(),
            ],
            ProtocolFrequency::cases(),
        );
    }

    /**
     * @return array<int, string>
     */
    private function frequencyValues(): array
    {
        return array_map(
            fn (ProtocolFrequency $frequency) => $frequency->value,
            ProtocolFrequency::cases(),
        );
    }
}
