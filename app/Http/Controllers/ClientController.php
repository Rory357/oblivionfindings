<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\ClientDocument;
use App\Models\RespiteBooking;
use App\Models\RespiteBookingRequest;
use App\Models\Shift;
use App\Models\TimelineEvent;
use App\Models\User;
use App\Models\Site;
use App\Models\ServiceContext;
use App\Services\NotificationService;
use App\Services\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use App\Http\Requests\StoreClientRequest;
use App\Http\Requests\UpdateClientRequest;

class ClientController extends Controller
{
    public function index(Request $request)
    {
        $this->authorize('viewAny', Client::class);

        $user = auth()->user();

        $clients = Client::query()
            ->when(
                $user->hasRole('support_worker') && !$user->hasRole('admin', 'manager', 'coordinator'),
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
                'respiteBookings',
                'respiteBookingRequests',
            ])
            ->orderBy('last_name')
            ->get([
                'id',
                'site_id',
                'nhi_number',
                'first_name',
                'last_name',
                'status',
                'date_of_birth',
                'phone',
                'email',
                'address_line_1',
                'city',
                'postcode',
                'profile_photo_path',
            ]);

        $clients = $clients->map(function (Client $c) {
            $summary = $this->buildOnboardingSummaryFromCounts($c);
            $hasRespite = ((int) ($c->respite_bookings_count ?? 0) + (int) ($c->respite_booking_requests_count ?? 0)) > 0;
            return [
                'id' => $c->id,
                'nhi_number' => $c->nhi_number,
                'first_name' => $c->first_name,
                'last_name' => $c->last_name,
                'profile_photo_url' => $c->profile_photo_url,
                'avatar' => $c->avatar,
                'status' => $c->status,
                'site' => $c->site ? ['id' => $c->site->id, 'name' => $c->site->name] : null,
                'onboarding' => $summary,
                'has_respite' => $hasRespite,
            ];
        })->values();

        return inertia('operations/clients/index', [
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
            'onboardingWorkflow.steps',
        ]);

        // For modal / async detail views, return JSON.
        if ($request->wantsJson() || $request->boolean('modal')) {
            return response()->json([
                'client' => [
                    'id' => $client->id,
                    'first_name' => $client->first_name,
                    'last_name' => $client->last_name,
                'profile_photo_url' => $client->profile_photo_url,
                'avatar' => $client->avatar,
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

        return inertia('operations/clients/show', [
            'client' => [
                'id' => $client->id,
                'nhi_number' => $client->nhi_number,
                'first_name' => $client->first_name,
                'last_name' => $client->last_name,
                'profile_photo_url' => $client->profile_photo_url,
                'avatar' => $client->avatar,
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
            'onboarding' => [
                'checklist' => $this->buildOnboardingChecklist($client),
                'workflow' => $client->onboardingWorkflow ? [
                    'id' => $client->onboardingWorkflow->id,
                    'status' => $client->onboardingWorkflow->status,
                    'started_at' => $client->onboardingWorkflow->started_at?->toISOString(),
                    'completed_at' => $client->onboardingWorkflow->completed_at?->toISOString(),
                    'assigned_to' => $client->onboardingWorkflow->assignee ? [
                        'id' => $client->onboardingWorkflow->assignee->id,
                        'name' => $client->onboardingWorkflow->assignee->name,
                    ] : null,
                    'notes' => $client->onboardingWorkflow->notes,
                    'steps' => $client->onboardingWorkflow->steps->sortBy('step_order')->map(fn($s) => [
                        'id' => $s->id,
                        'step_name' => $s->step_name,
                        'step_order' => $s->step_order,
                        'is_required' => $s->is_required,
                        'status' => $s->status,
                        'completed_at' => $s->completed_at?->toISOString(),
                        'completed_by' => $s->completer ? ['id' => $s->completer->id, 'name' => $s->completer->name] : null,
                        'notes' => $s->notes,
                        'due_date' => $s->due_date?->toDateString(),
                    ])->values()->toArray(),
                ] : null,
            ],
            // Progress notes for client (last 20)
            'client_progress_notes' => \App\Models\ProgressNote::where('client_id', $client->id)
                ->with(['author:id,name', 'goal:id,title'])
                ->orderByDesc('created_at')
                ->limit(20)
                ->get(),

            // Service agreements
            'client_agreements' => \App\Models\ServiceAgreement::where('client_id', $client->id)
                ->orderByDesc('created_at')
                ->get(),

            // Active risks
            'client_risks' => \App\Models\ClientRisk::where('client_id', $client->id)
                ->where('active', true)
                ->orderByDesc('severity')
                ->limit(10)
                ->get(),

            // Recent incidents (last 5)
            'client_incidents' => \App\Models\ClientIncident::where('client_id', $client->id)
                ->with(['reporter:id,name'])
                ->orderByDesc('occurred_at')
                ->limit(5)
                ->get(),

            'care_plans_summary' => [
                'active_plan' => \App\Models\CarePlan::where('client_id', $client->id)
                    ->where('status', 'active')
                    ->withCount(['goals', 'goals as goals_completed' => fn($q) => $q->where('status', 'completed')])
                    ->with('goals:id,care_plan_id,title,status,progress_percentage,priority')
                    ->first(),
                'total_plans' => \App\Models\CarePlan::where('client_id', $client->id)->count(),
                'review_due' => \App\Models\CarePlan::where('client_id', $client->id)
                    ->where('status', 'active')
                    ->where(function ($q) {
                        $q->whereNull('next_review_at')->orWhere('next_review_at', '<=', now());
                    })->exists(),
                'recent_notes' => \App\Models\ProgressNote::where('client_id', $client->id)
                    ->with(['author:id,name', 'goal:id,title'])
                    ->orderByDesc('created_at')
                    ->limit(5)
                    ->get(),
            ],
            'respite' => [
                'bookings' => RespiteBooking::query()
                    ->where('client_id', $client->id)
                    ->orderByDesc('start_at')
                    ->limit(10)
                    ->with(['coordinator', 'shift'])
                    ->get()
                    ->map(fn($b) => [
                        'id' => $b->id,
                        'start_at' => optional($b->start_at)->toISOString(),
                        'end_at' => optional($b->end_at)->toISOString(),
                        'status' => $b->status,
                        'shift_id' => $b->shift?->id,
                        'coordinator' => $b->coordinator ? ['id' => $b->coordinator->id, 'name' => $b->coordinator->name] : null,
                    ])->values(),
                'requests' => RespiteBookingRequest::query()
                    ->where('client_id', $client->id)
                    ->orderByDesc('requested_start')
                    ->limit(10)
                    ->get()
                    ->map(fn($r) => [
                        'id' => $r->id,
                        'requested_start' => optional($r->requested_start)->toISOString(),
                        'requested_end' => optional($r->requested_end)->toISOString(),
                        'status' => $r->status,
                    ])->values(),
            ],
            'consents' => \App\Models\ClientConsent::where('client_id', $client->id)
                ->with('consentType:id,name,category')
                ->orderByDesc('created_at')
                ->get()
                ->map(fn($c) => [
                    'id' => $c->id,
                    'consent_type' => $c->consentType?->name ?? 'Unknown',
                    'consent_type_category' => $c->consentType?->category,
                    'status' => $c->status,
                    'given_at' => $c->given_at?->toISOString(),
                    'given_method' => $c->given_method,
                    'expires_at' => $c->expires_at?->toISOString(),
                    'is_expired' => $c->isExpired(),
                    'is_expiring_soon' => $c->isExpiringSoon(),
                    'withdrawn_at' => $c->withdrawn_at?->toISOString(),
                    'withdrawal_reason' => $c->withdrawal_reason,
                    'conditions' => $c->conditions,
                    'special_conditions' => $c->special_conditions,
                    'capacity_assessed' => $c->capacity_assessed,
                    'capacity_outcome' => $c->capacity_outcome,
                    'best_interests_decision' => $c->best_interests_decision,
                ]),
            'can' => [
                'edit' => $request->user()?->canDo('clients.update') ?? false,
                'assign_workers' => $request->user()?->canDo('clients.assignments.update') ?? false,
                'create_note' => $request->user()?->canDo('timeline.create') ?? false,
                'pin_handover' => $request->user()?->canDo('timeline.pin') ?? false,
                'manage_onboarding' => $request->user()?->canDo('clients.onboarding.manage') ?? false,
                'create_shift' => $request->user()?->canDo('shifts.create') ?? false,
                'manage_onboarding_workflow' => $request->user()?->canDo('onboarding.edit') ?? false,
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

    //     return inertia('operations/clients/show', [
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

        return inertia('operations/clients/create', [
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

            $clientFields = collect($data)->except([
                'create_client_portal_user',
            ])->all();

            // Default to onboarding status for new clients
            if (!isset($clientFields['status']) || $clientFields['status'] === 'active') {
                $clientFields['status'] = 'onboarding';
            }

            $auth = $request->user();

            $client = DB::transaction(function () use ($clientFields, $data, $auth) {
                $client = Client::create($clientFields);

                \App\Models\ClientOnboardingWorkflow::createForClient($client, $auth->id);

                if (!empty($data['create_client_portal_user'])) {
                    $clientEmail = trim((string) ($data['email'] ?? $client->email ?? ''));
                    if ($clientEmail !== '') {
                        $name = trim($client->first_name . ' ' . $client->last_name);
                        $clientUser = $this->findOrCreatePortalUser($clientEmail, $name, 'client');
                        $client->portalUsers()->syncWithoutDetaching([
                            $clientUser->id => ['relation' => 'client'],
                        ]);
                        $this->sendPasswordSetupEmail($clientEmail);
                    }
                }

                return $client;
            });

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

    private function findOrCreatePortalUser(string $email, string $name, string $roleName): User
    {
        $user = User::where('email', $email)->first();

        if (!$user) {
            $user = User::create([
                'name' => $name,
                'email' => $email,
                // Random placeholder; user sets their own password from reset email.
                'password' => Str::password(32),
                'role' => $roleName,
                'approved_at' => now(),
            ]);
        } else {
            if (!$user->approved_at) {
                $user->forceFill(['approved_at' => now()])->save();
            }
            if (empty($user->role)) {
                $user->forceFill(['role' => $roleName])->save();
            }
        }

        $role = \App\Models\Role::where('name', $roleName)->first();
        if ($role) {
            $user->roles()->syncWithoutDetaching([$role->id]);
        }

        return $user;
    }

    private function sendPasswordSetupEmail(string $email): void
    {
        Password::sendResetLink(['email' => $email]);
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

        return inertia('operations/clients/edit', [
            'client' => $client->only([
                'id','site_id','service_context_id','nhi_number','first_name','last_name','preferred_name','date_of_birth','gender','status',
                'phone','email','address_line_1','address_line_2','suburb','city','postcode',
                'profile_photo_path','funding_type','funding_notes',
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

    /**
     * Quick-update a single field on a client (e.g. risk_level, safeguarding_flag).
     */
    public function quickUpdate(Request $request, Client $client)
    {
        $auth = $request->user();
        abort_unless($auth && $auth->canDo('clients.update'), 403);

        $data = $request->validate([
            'risk_level' => ['nullable', 'in:low,medium,high,critical'],
            'safeguarding_flag' => ['nullable', 'boolean'],
            'key_worker_id' => ['nullable', 'integer', 'exists:users,id'],
        ]);

        $client->update(array_filter($data, fn ($v) => $v !== null));

        return redirect()->back()->with('success', 'Updated.');
    }


    public function updatePhoto(Request $request, Client $client)
    {
        $this->authorize('update', $client);

        $request->validate([
            'photo' => ['required', 'image', 'max:5120'], // 5MB
        ]);

        $path = $this->storeAvatar($request->file('photo'), 'profile-photos/clients');

        if ($client->profile_photo_path) {
            Storage::disk('public')->delete($client->profile_photo_path);
        }

        $client->forceFill(['profile_photo_path' => $path])->save();

        return back()->with('success', 'Client photo updated.');
    }

    /**
     * Remove the client's profile photo.
     */
    public function destroyPhoto(Request $request, Client $client)
    {
        $this->authorize('update', $client);

        if ($client->profile_photo_path) {
            Storage::disk('public')->delete($client->profile_photo_path);
        }

        $client->forceFill(['profile_photo_path' => null])->save();

        return back()->with('success', 'Client photo removed.');
    }

    /**
     * Store a square-cropped avatar (center crop) and resize to 512x512.
     */
    private function storeAvatar(UploadedFile $file, string $dir): string
    {
        try {
            $data = file_get_contents($file->getRealPath());
            $src = @imagecreatefromstring($data);
            if (!$src) {
                throw new \RuntimeException('Unable to read image');
            }

            $w = imagesx($src);
            $h = imagesy($src);
            $size = min($w, $h);
            $x = (int) floor(($w - $size) / 2);
            $y = (int) floor(($h - $size) / 2);

            $crop = imagecrop($src, ['x' => $x, 'y' => $y, 'width' => $size, 'height' => $size]);
            if (!$crop) {
                $crop = $src;
            }

            $dst = imagecreatetruecolor(512, 512);
            $white = imagecolorallocate($dst, 255, 255, 255);
            imagefill($dst, 0, 0, $white);
            imagecopyresampled($dst, $crop, 0, 0, 0, 0, 512, 512, imagesx($crop), imagesy($crop));

            ob_start();
            imagejpeg($dst, null, 85);
            $jpg = ob_get_clean();

            imagedestroy($dst);
            if ($crop !== $src) {
                imagedestroy($crop);
            }
            imagedestroy($src);

            $filename = trim($dir, '/') . '/' . Str::uuid()->toString() . '.jpg';
            Storage::disk('public')->put($filename, $jpg);
            return $filename;
        } catch (\Throwable $e) {
            return $file->storePublicly($dir, 'public');
        }
    }

}
