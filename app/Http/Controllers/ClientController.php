<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\ClientDocument;
use App\Models\TimelineEvent;
use App\Models\User;
use App\Models\Site;
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
            ->with(['site:id,name,is_active'])
            ->orderBy('last_name')
            ->get(['id', 'site_id', 'first_name', 'last_name', 'status']);

        return inertia('clients/index', [
            'clients' => $clients,
        ]);
    }

    public function show(Request $request, Client $client)
    {
        $this->authorize('view', $client);

        AuditLogger::log('clients.view', $client);

        $client->load([
            'site:id,name',
            'supportWorkers:id,name,email',
            'medicalProfile',
            'medications',
            'conditions',
            'emergencyContacts',
            'portalUsers:id,name,email',
            'supportPlan',
            'assessments',
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
                'type' => $e->type,
                'occurred_at' => optional($e->occurred_at)->toISOString(),
                'subject' => $e->subject,
                'body' => $e->body,
                'actor' => $e->actor ? ['id' => $e->actor->id, 'name' => $e->actor->name] : null,
                'site' => $e->site ? ['id' => $e->site->id, 'name' => $e->site->name] : null,
            ])->values(),
            'can' => [
                'edit' => $request->user()?->canDo('clients.update') ?? false,
                'assign_workers' => $request->user()?->canDo('clients.assignments.update') ?? false,
                'create_note' => $request->user()?->canDo('timeline.create') ?? false,
            ],
        ]);
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

        return inertia('clients/create', [
            'sites' => $sites,
        ]);
    }

    public function store(StoreClientRequest $request)
    {
        $this->authorize('create', Client::class);

        $client = Client::create($request->validated());

        return redirect()
            ->route('clients.index')
            ->with('success', 'Client created.');
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

        return inertia('clients/edit', [
            'client' => $client->only([
                'id','site_id','first_name','last_name','preferred_name','date_of_birth','gender','status',
                'phone','email','address_line_1','address_line_2','suburb','city','postcode','funding_type','funding_notes',
            ]),
            'sites' => $sites,
        ]);
    }

    public function update(UpdateClientRequest $request, Client $client)
    {
        $this->authorize('update', $client);

        $client->update($request->validated());

        return redirect()
            ->route('clients.index')
            ->with('success', 'Client updated.');
    }
}
