<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\ClientDocument;
use App\Models\Shift;
use App\Models\TimelineEvent;
use App\Models\User;
use App\Models\Site;
use App\Models\ServiceContext;
use App\Services\NotificationService;
use App\Services\AuditLogger;
use Illuminate\Http\Request;
use App\Http\Requests\StoreClientRequest;
use App\Http\Requests\UpdateClientRequest;

class ClientController extends Controller
{
    public function index()
    {
        $this->authorize('viewAny', Client::class);

        $user = auth()->user();

        $clients = Client::query()
            ->when(
                $user->hasRole('support_worker'),
                fn($q) => $q->whereHas('supportWorkers', fn($q) => $q->whereKey($user->id))
            )
            ->with(['site:id,name,is_active', 'onboardingOverrides:id,client_id,key,value'])
            ->withCount([
                'portalUsers',
                'medications',
                'conditions',
                'emergencyContacts',
                'assessments',
                'documents',
                'supportPlan',
            ])
            ->orderBy('last_name')
            ->get([
                'id',
                'site_id',
                'first_name',
                'last_name',
                'status',
                'date_of_birth',
                'phone',
                'email',
                'address_line_1',
                'city',
                'postcode',
            ]);

        $clients = $clients->map(function (Client $c) {
            $summary = $this->buildOnboardingSummaryFromCounts($c);
            return [
                'id' => $c->id,
                'first_name' => $c->first_name,
                'last_name' => $c->last_name,
                'status' => $c->status,
                'site' => $c->site ? ['id' => $c->site->id, 'name' => $c->site->name] : null,
                'onboarding' => $summary,
            ];
        })->values();

        return inertia('clients/index', [
            'clients' => $clients,
        ]);
    }

    private function buildOnboardingSummaryFromCounts(Client $client): array
    {
        $overrides = $client->onboardingOverrides
            ->keyBy('key')
            ->map(fn($o) => (bool) $o->value)
            ->toArray();

        $hasProfile = (bool) ($client->first_name && $client->last_name)
            && (bool) $client->date_of_birth
            && (bool) ($client->phone || $client->email)
            && (bool) ($client->address_line_1 || $client->city || $client->postcode);

        $items = [
            ['key' => 'profile', 'has_data' => $hasProfile, 'override' => (bool) ($overrides['profile'] ?? false)],
            ['key' => 'next_of_kin', 'has_data' => (int) ($client->portal_users_count ?? 0) > 0, 'override' => (bool) ($overrides['next_of_kin'] ?? false)],
            ['key' => 'medications', 'has_data' => (int) ($client->medications_count ?? 0) > 0, 'override' => (bool) ($overrides['medications'] ?? false)],
            ['key' => 'conditions', 'has_data' => (int) ($client->conditions_count ?? 0) > 0, 'override' => (bool) ($overrides['conditions'] ?? false)],
            ['key' => 'emergency_contacts', 'has_data' => (int) ($client->emergency_contacts_count ?? 0) > 0, 'override' => (bool) ($overrides['emergency_contacts'] ?? false)],
            ['key' => 'history', 'has_data' => ((int) ($client->assessments_count ?? 0) > 0) || ((int) ($client->support_plan_count ?? 0) > 0), 'override' => (bool) ($overrides['history'] ?? false)],
            ['key' => 'documents', 'has_data' => (int) ($client->documents_count ?? 0) > 0, 'override' => (bool) ($overrides['documents'] ?? false)],
        ];

        $total = count($items);
        $completed = 0;
        foreach ($items as $i) {
            if (($i['has_data'] ?? false) || ($i['override'] ?? false)) {
                $completed++;
            }
        }

        $percent = $total > 0 ? (int) round(($completed / $total) * 100) : 0;

        return [
            'completed' => $completed,
            'total' => $total,
            'percent' => $percent,
            'status' => $completed === $total ? 'complete' : 'incomplete',
        ];
    }

    public function show(Request $request, Client $client)
    {
        $this->authorize('view', $client);

        AuditLogger::log('clients.view', $client);

        $client->load([
            'site:id,name',
            'serviceContext:id,type,name',
            'supportWorkers:id,name,email',
            'medicalProfile',
            'medications',
            'conditions',
            'emergencyContacts',
            'portalUsers:id,name,email',
            'supportPlan',
            'assessments',
            'onboardingOverrides',
        ]);

        // For modal / async detail views, return JSON.
        if ($request->wantsJson() || $request->boolean('modal')) {
            return response()->json([
                'client' => [
                    'id' => $client->id,
                    'first_name' => $client->first_name,
                    'last_name' => $client->last_name,
                    'status' => $client->status,
                    'site' => $client->site
                        ? [
                            'id' => $client->site->id,
                            'name' => $client->site->name,
                        ]
                        : null,
                    'support_workers' => $client->supportWorkers->map(fn($u) => [
                        'id' => $u->id,
                        'name' => $u->name,
                        'email' => $u->email,
                    ])->values(),
                ],
            ]);
        }

        $nextShift = Shift::query()
            ->where('client_id', $client->id)
            ->where('starts_at', '>=', now())
            ->whereNotIn('status', ['cancelled'])
            ->with(['staff:id,name,email'])
            ->orderBy('starts_at')
            ->first();

        $lastShift = Shift::query()
            ->where('client_id', $client->id)
            ->where('starts_at', '<', now())
            ->whereNotIn('status', ['cancelled'])
            ->with(['staff:id,name,email'])
            ->orderByDesc('starts_at')
            ->first();

        $documents = ClientDocument::query()
            ->where('client_id', $client->id)
            ->orderByDesc('created_at')
            ->get(['id','title','category','version','effective_date','expiry_date','portal_visible','notes','original_name','mime_type','size_bytes','created_at']);

        $events = TimelineEvent::query()
            ->where('client_id', $client->id)
            ->orderByDesc('occurred_at')
            ->limit(80)
            ->with(['actor:id,name', 'site:id,name'])
            ->get();

        $handover = TimelineEvent::query()
            ->where('client_id', $client->id)
            ->where('type', 'handover')
            ->where('is_pinned', true)
            ->orderByDesc('occurred_at')
            ->limit(5)
            ->with(['actor:id,name'])
            ->get();

        return inertia('clients/show', [
            'client' => [
                'id' => $client->id,
                'first_name' => $client->first_name,
                'last_name' => $client->last_name,
                'preferred_name' => $client->preferred_name,
                'date_of_birth' => optional($client->date_of_birth)->toDateString(),
                'gender' => $client->gender,
                'status' => $client->status,
                'phone' => $client->phone,
                'email' => $client->email,
                'address_line_1' => $client->address_line_1,
                'address_line_2' => $client->address_line_2,
                'suburb' => $client->suburb,
                'city' => $client->city,
                'postcode' => $client->postcode,
                'funding_type' => $client->funding_type,
                'funding_notes' => $client->funding_notes,
                'site' => $client->site ? ['id' => $client->site->id, 'name' => $client->site->name] : null,
                'service_context' => $client->serviceContext ? [
                    'id' => $client->serviceContext->id,
                    'type' => $client->serviceContext->type?->value,
                    'name' => $client->serviceContext->name,
                ] : null,
                'support_workers' => $client->supportWorkers->map(fn($u) => ['id' => $u->id, 'name' => $u->name, 'email' => $u->email])->values(),
            ],
            'medical' => [
                'profile' => $client->medicalProfile,
                'medications' => $client->medications,
                'conditions' => $client->conditions,
                'emergency_contacts' => $client->emergencyContacts,
            ],
            'support_plan' => $client->supportPlan,
            'assessments' => $client->assessments
                ->sortByDesc(fn($a) => $a->assessed_at ?? $a->created_at)
                ->values(),
            'documents' => $documents,
            'portal_users' => $client->portalUsers->map(fn($u) => [
                'id' => $u->id,
                'name' => $u->name,
                'email' => $u->email,
                'relation' => $u->pivot?->relation,
            ])->values(),
            'events' => $events->map(fn($e) => [
                'id' => $e->id,
                'source_id' => $e->source_id,
                'source_type' => $e->source_type,
                'type' => $e->type,
                'occurred_at' => optional($e->occurred_at)->toISOString(),
                'subject' => $e->subject,
                'body' => $e->body,
                'meta' => $e->meta ?? [],
                'visibility' => $e->visibility,
                'is_pinned' => (bool) $e->is_pinned,
                'shift_id' => $e->shift_id,
                'actor' => $e->actor ? ['id' => $e->actor->id, 'name' => $e->actor->name] : null,
                'site' => $e->site ? ['id' => $e->site->id, 'name' => $e->site->name] : null,
            ])->values(),
            'handover' => $handover->map(fn($e) => [
                'id' => $e->id,
                'source_id' => $e->source_id,
                'source_type' => $e->source_type,
                'type' => $e->type,
                'occurred_at' => optional($e->occurred_at)->toISOString(),
                'subject' => $e->subject,
                'body' => $e->body,
                'is_pinned' => (bool) $e->is_pinned,
                'actor' => $e->actor ? ['id' => $e->actor->id, 'name' => $e->actor->name] : null,
            ])->values(),
            'shifts_summary' => [
                'next' => $nextShift ? [
                    'id' => $nextShift->id,
                    'starts_at' => optional($nextShift->starts_at)->toISOString(),
                    'ends_at' => optional($nextShift->ends_at)->toISOString(),
                    'status' => $nextShift->status,
                    'location' => $nextShift->location,
                    'staff' => $nextShift->staff ? ['id' => $nextShift->staff->id, 'name' => $nextShift->staff->name, 'email' => $nextShift->staff->email] : null,
                ] : null,
                'last' => $lastShift ? [
                    'id' => $lastShift->id,
                    'starts_at' => optional($lastShift->starts_at)->toISOString(),
                    'ends_at' => optional($lastShift->ends_at)->toISOString(),
                    'status' => $lastShift->status,
                    'location' => $lastShift->location,
                    'staff' => $lastShift->staff ? ['id' => $lastShift->staff->id, 'name' => $lastShift->staff->name, 'email' => $lastShift->staff->email] : null,
                ] : null,
            ],
            'onboarding' => $this->buildOnboardingChecklist($client),
            'can' => [
                'edit' => $request->user()?->canDo('clients.update') ?? false,
                'assign_workers' => $request->user()?->canDo('clients.assignments.update') ?? false,
                'create_note' => $request->user()?->canDo('timeline.create') ?? false,
                'pin_handover' => $request->user()?->canDo('timeline.pin') ?? false,
                'manage_onboarding' => $request->user()?->canDo('clients.onboarding.manage') ?? false,
                'create_shift' => $request->user()?->canDo('shifts.create') ?? false,
            ],
        ]);
    }

    private function buildOnboardingChecklist(Client $client): array
    {
        $overrides = $client->onboardingOverrides
            ->keyBy('key')
            ->map(fn($o) => (bool) $o->value)
            ->toArray();

        $hasProfile = (bool) ($client->first_name && $client->last_name)
            && (bool) $client->date_of_birth
            && (bool) ($client->phone || $client->email)
            && (bool) ($client->address_line_1 || $client->city || $client->postcode);

        $items = [
            [
                'key' => 'profile',
                'label' => 'Profile details',
                'has_data' => $hasProfile,
                'override' => (bool) ($overrides['profile'] ?? false),
            ],
            [
                'key' => 'next_of_kin',
                'label' => 'Next of kin / portal contacts',
                'has_data' => $client->portalUsers->count() > 0,
                'override' => (bool) ($overrides['next_of_kin'] ?? false),
            ],
            [
                'key' => 'medications',
                'label' => 'Medications',
                'has_data' => $client->medications->count() > 0,
                'override' => (bool) ($overrides['medications'] ?? false),
            ],
            [
                'key' => 'conditions',
                'label' => 'Medical conditions',
                'has_data' => $client->conditions->count() > 0,
                'override' => (bool) ($overrides['conditions'] ?? false),
            ],
            [
                'key' => 'emergency_contacts',
                'label' => 'Emergency contacts',
                'has_data' => $client->emergencyContacts->count() > 0,
                'override' => (bool) ($overrides['emergency_contacts'] ?? false),
            ],
            [
                'key' => 'history',
                'label' => 'History (assessments or support plan)',
                'has_data' => ($client->assessments->count() > 0) || (bool) $client->supportPlan,
                'override' => (bool) ($overrides['history'] ?? false),
            ],
            [
                'key' => 'documents',
                'label' => 'Documents',
                'has_data' => $client->documents()->exists(),
                'override' => (bool) ($overrides['documents'] ?? false),
            ],
        ];

        $items = array_map(function ($i) {
            $i['complete'] = (bool) ($i['has_data'] || $i['override']);
            return $i;
        }, $items);

        $total = count($items);
        $completed = count(array_filter($items, fn($i) => $i['complete']));
        $percent = $total > 0 ? (int) round(($completed / $total) * 100) : 0;

        return [
            'items' => $items,
            'completed' => $completed,
            'total' => $total,
            'percent' => $percent,
            'status' => $completed === $total ? 'complete' : 'incomplete',
        ];
    }



    // public function show(Client $client)
    // {
    //     $this->authorize('view', $client);

    //     return inertia('clients/show', [
    //         'client' => $client->load('supportWorkers'),
    //     ]);
    // }

    public function create()
    {
        $this->authorize('create', Client::class);

        $sites = Site::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name']);

        $serviceContexts = ServiceContext::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'type', 'name']);

        return inertia('clients/create', [
            'sites' => $sites,
            'serviceContexts' => $serviceContexts,
            'defaultServiceContextId' => ServiceContext::defaultId(),
        ]);
    }

    public function store(StoreClientRequest $request)
    {
        $this->authorize('create', Client::class);

        try {
            $data = $request->validated();

            // If not specified, apply organisation default service context (if configured).
            if (empty($data['service_context_id'])) {
                $data['service_context_id'] = ServiceContext::defaultId();
            }

            $client = Client::create($data);

            app(NotificationService::class)->notifyCrud($request->user(), 'created', 'client', $client, $client, [
                'title' => "Client created: {$client->first_name} {$client->last_name}",
                'url' => url("/clients/{$client->id}"),
            ]);

            return redirect()
                ->route('clients.index')
                ->with('success', 'Client created successfully.');
        } catch (\Throwable $e) {
            report($e);
            return back()
                ->withInput()
                ->with('error', 'Failed to create client: ' . $e->getMessage());
        }
    }

    public function edit(Client $client)
    {
        $this->authorize('update', $client);

        $sitesQuery = Site::query()->orderBy('name');
        // Keep inactive site visible if client currently assigned to it
        $sitesQuery->where('is_active', true);
        if ($client->site_id) {
            $sitesQuery->orWhere('id', $client->site_id);
        }

        $sites = $sitesQuery->get(['id', 'name', 'is_active']);

        $serviceContextsQuery = ServiceContext::query()->orderBy('name');
        $serviceContextsQuery->where('is_active', true);
        if ($client->service_context_id) {
            $serviceContextsQuery->orWhere('id', $client->service_context_id);
        }
        $serviceContexts = $serviceContextsQuery->get(['id', 'type', 'name', 'is_active']);

        return inertia('clients/edit', [
            'client' => $client->only([
                'id','site_id','service_context_id','first_name','last_name','preferred_name','date_of_birth','gender','status',
                'phone','email','address_line_1','address_line_2','suburb','city','postcode','funding_type','funding_notes',
            ]),
            'sites' => $sites,
            'serviceContexts' => $serviceContexts,
            'defaultServiceContextId' => ServiceContext::defaultId(),
        ]);
    }

    public function update(UpdateClientRequest $request, Client $client)
    {
        $this->authorize('update', $client);

        try {
            $client->update($request->validated());

            app(NotificationService::class)->notifyCrud($request->user(), 'updated', 'client', $client, $client, [
                'title' => "Client updated: {$client->first_name} {$client->last_name}",
                'url' => url("/clients/{$client->id}"),
            ]);

            return redirect()
                ->route('clients.index')
                ->with('success', 'Client updated successfully.');
        } catch (\Throwable $e) {
            report($e);
            return back()
                ->withInput()
                ->with('error', 'Failed to update client: ' . $e->getMessage());
        }
    }
}
