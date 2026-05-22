<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\FamilyVisitRequest;
use App\Models\TimelineEvent;
use Illuminate\Http\Request;

class ClientVisitRequestController extends Controller
{
    public function index(Request $request, Client $client)
    {
        $this->authorize('view', $client);

        $filter = $request->query('filter', 'pending');

        $query = FamilyVisitRequest::where('client_id', $client->id)
            ->with(['user:id,name,email', 'reviewer:id,name'])
            ->orderByDesc('created_at');

        if ($filter === 'pending') {
            $query->where('status', 'pending');
        } elseif ($filter === 'approved') {
            $query->where('status', 'approved');
        } elseif ($filter === 'declined') {
            $query->where('status', 'declined');
        }
        // 'all' = no filter

        $requests = $query->paginate(20)->withQueryString();

        $stats = [
            'pending' => FamilyVisitRequest::where('client_id', $client->id)->where('status', 'pending')->count(),
            'approved_this_month' => FamilyVisitRequest::where('client_id', $client->id)
                ->where('status', 'approved')
                ->where('reviewed_at', '>=', now()->startOfMonth())
                ->count(),
            'total' => FamilyVisitRequest::where('client_id', $client->id)->count(),
        ];

        return inertia('operations/clients/visit-requests', [
            'client' => [
                'id' => $client->id,
                'first_name' => $client->first_name,
                'last_name' => $client->last_name,
            ],
            'requests' => $requests,
            'filter' => $filter,
            'stats' => $stats,
        ]);
    }

    public function approve(Request $request, Client $client, FamilyVisitRequest $visit)
    {
        $this->authorize('view', $client);
        abort_unless($visit->client_id === $client->id, 404);
        abort_unless($visit->status === 'pending', 422);

        $data = $request->validate([
            'review_notes' => 'nullable|string|max:1000',
        ]);

        $visit->update([
            'status' => 'approved',
            'reviewed_by' => $request->user()->id,
            'reviewed_at' => now(),
            'review_notes' => $data['review_notes'] ?? null,
        ]);

        app(\App\Services\Timeline\TimelineEmitter::class)->record([
            'source_type' => FamilyVisitRequest::class,
            'source_id' => $visit->id,
            'occurred_at' => now(),
            'type' => 'visit_approved',
            'actor_user_id' => $request->user()->id,
            'client_id' => $client->id,
            'site_id' => $client->site_id,
            'subject' => 'Family visit approved for ' . $visit->requested_date->format('j M'),
            'body' => $data['review_notes'] ?? null,
            'visibility' => 'portal',
            'is_pinned' => false,
            'created_by' => $request->user()->id,
        ]);

        return redirect()->back()->with('success', 'Visit request approved.');
    }

    public function decline(Request $request, Client $client, FamilyVisitRequest $visit)
    {
        $this->authorize('view', $client);
        abort_unless($visit->client_id === $client->id, 404);
        abort_unless($visit->status === 'pending', 422);

        $data = $request->validate([
            'review_notes' => 'nullable|string|max:1000',
        ]);

        $visit->update([
            'status' => 'declined',
            'reviewed_by' => $request->user()->id,
            'reviewed_at' => now(),
            'review_notes' => $data['review_notes'] ?? null,
        ]);

        app(\App\Services\Timeline\TimelineEmitter::class)->record([
            'source_type' => FamilyVisitRequest::class,
            'source_id' => $visit->id,
            'occurred_at' => now(),
            'type' => 'visit_declined',
            'actor_user_id' => $request->user()->id,
            'client_id' => $client->id,
            'site_id' => $client->site_id,
            'subject' => 'Family visit declined for ' . $visit->requested_date->format('j M'),
            'body' => $data['review_notes'] ?? null,
            'visibility' => 'portal',
            'is_pinned' => false,
            'created_by' => $request->user()->id,
        ]);

        return redirect()->back()->with('success', 'Visit request declined.');
    }
}
