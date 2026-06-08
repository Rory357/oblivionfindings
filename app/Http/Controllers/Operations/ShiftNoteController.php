<?php

namespace App\Http\Controllers\Operations;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\ClientNote;
use App\Models\Shift;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ShiftNoteController extends Controller
{
    public function index(Request $request)
    {
        $auth = $request->user();
        abort_unless($auth && $auth->canDo('shifts.viewAny'), 403);

        $orgId = $auth->organization_id;

        // Base query: client notes linked to shifts
        $baseQuery = ClientNote::query()
            ->whereNotNull('shift_id')
            ->when($orgId, fn ($q) => $q->where('organization_id', $orgId));

        // Stats
        $stats = [
            'total' => (clone $baseQuery)->count(),
            'today' => (clone $baseQuery)->whereDate('created_at', today())->count(),
            'this_week' => (clone $baseQuery)->where('created_at', '>=', now()->startOfWeek())->count(),
            'flagged' => (clone $baseQuery)->where('is_flagged', true)->count(),
            'shifts_without_notes' => Shift::query()
                ->when($orgId, fn ($q) => $q->where('organization_id', $orgId))
                ->where('starts_at', '>=', now()->subDays(7))
                ->where('starts_at', '<=', now())
                ->whereDoesntHave('clientNotes')
                ->count(),
        ];

        // Chart: notes by type
        $chartByType = (clone $baseQuery)
            ->select('type', DB::raw('COUNT(*) as count'))
            ->groupBy('type')
            ->pluck('count', 'type')
            ->toArray();

        // Chart: daily trend (last 7 days)
        $chartDaily = [];
        for ($i = 6; $i >= 0; $i--) {
            $date = now()->subDays($i);
            $chartDaily[] = [
                'label' => $date->format('D'),
                'value' => (clone $baseQuery)->whereDate('created_at', $date->toDateString())->count(),
            ];
        }

        // Validate filters
        $filters = $request->only(['q', 'type', 'client_id', 'author_id', 'date_from', 'date_to', 'flagged', 'private']);

        // Build filtered query
        $query = ClientNote::query()
            ->whereNotNull('shift_id')
            ->when($orgId, fn ($q) => $q->where('organization_id', $orgId))
            ->with([
                'user:id,name',
                'client:id,first_name,last_name',
                'shift:id,starts_at,ends_at',
                'reviewer:id,name',
            ])
            ->when($filters['q'] ?? null, fn ($q, $search) => $q->where(function ($q) use ($search) {
                $q->where('body', 'like', "%{$search}%")
                  ->orWhere('subject', 'like', "%{$search}%");
            }))
            ->when($filters['type'] ?? null, fn ($q, $type) => $q->where('type', $type))
            ->when($filters['client_id'] ?? null, fn ($q, $clientId) => $q->where('client_id', $clientId))
            ->when($filters['author_id'] ?? null, fn ($q, $authorId) => $q->where('user_id', $authorId))
            ->when($filters['date_from'] ?? null, fn ($q, $from) => $q->whereDate('created_at', '>=', $from))
            ->when($filters['date_to'] ?? null, fn ($q, $to) => $q->whereDate('created_at', '<=', $to))
            ->when(isset($filters['flagged']) && $filters['flagged'], fn ($q) => $q->where('is_flagged', true))
            ->when(isset($filters['private']) && $filters['private'], fn ($q) => $q->where('is_private', true))
            ->orderByDesc('created_at')
            ->paginate(25)
            ->withQueryString();

        // Dropdown data
        $clients = Client::query()
            ->when($orgId, fn ($q) => $q->where('organization_id', $orgId))
            ->select('id', 'first_name', 'last_name')
            ->orderBy('last_name')
            ->get();

        $staff = User::query()
            ->when($orgId, fn ($q) => $q->where('organization_id', $orgId))
            ->select('id', 'name')
            ->orderBy('name')
            ->get();

        return inertia('operations/shift-notes/Index', [
            'stats' => $stats,
            'chart_by_type' => $chartByType,
            'chart_daily' => $chartDaily,
            'notes' => $query,
            'clients' => $clients,
            'staff' => $staff,
            'filters' => $filters,
        ]);
    }

    public function export(Request $request)
    {
        $auth = $request->user();
        abort_unless($auth && $auth->canDo('shifts.viewAny'), 403);

        $orgId = $auth->organization_id;
        $filters = $request->only(['q', 'type', 'client_id', 'author_id', 'date_from', 'date_to', 'flagged']);

        $notes = ClientNote::query()
            ->whereNotNull('shift_id')
            ->when($orgId, fn ($q) => $q->where('organization_id', $orgId))
            ->with(['user:id,name', 'client:id,first_name,last_name', 'shift:id,starts_at,ends_at'])
            ->when($filters['q'] ?? null, fn ($q, $search) => $q->where('body', 'like', "%{$search}%"))
            ->when($filters['type'] ?? null, fn ($q, $type) => $q->where('type', $type))
            ->when($filters['client_id'] ?? null, fn ($q, $id) => $q->where('client_id', $id))
            ->when($filters['author_id'] ?? null, fn ($q, $id) => $q->where('user_id', $id))
            ->when($filters['date_from'] ?? null, fn ($q, $d) => $q->whereDate('created_at', '>=', $d))
            ->when($filters['date_to'] ?? null, fn ($q, $d) => $q->whereDate('created_at', '<=', $d))
            ->when(isset($filters['flagged']) && $filters['flagged'], fn ($q) => $q->where('is_flagged', true))
            ->orderByDesc('created_at')
            ->get();

        $csv = "Date,Client,Author,Type,Content,Shift Start,Shift End,Flagged,Private\n";
        foreach ($notes as $note) {
            $client = $note->client ? "{$note->client->first_name} {$note->client->last_name}" : '';
            $author = $note->user->name ?? '';
            $shiftStart = $note->shift?->starts_at?->format('Y-m-d H:i') ?? '';
            $shiftEnd = $note->shift?->ends_at?->format('Y-m-d H:i') ?? '';
            $content = str_replace('"', '""', $note->body ?? '');
            $csv .= sprintf(
                "%s,\"%s\",\"%s\",\"%s\",\"%s\",\"%s\",\"%s\",%s,%s\n",
                $note->created_at->format('Y-m-d H:i'),
                $client,
                $author,
                $note->type ?? 'note',
                $content,
                $shiftStart,
                $shiftEnd,
                $note->is_flagged ? 'Yes' : 'No',
                $note->is_private ? 'Yes' : 'No'
            );
        }

        return response($csv, 200, [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename="shift-notes-export-' . now()->format('Y-m-d') . '.csv"',
        ]);
    }

    public function flag(Request $request, $note)
    {
        $auth = $request->user();
        abort_unless($auth && $auth->canDo('shifts.viewAny'), 403);

        $note = ClientNote::query()
            ->when($auth->organization_id, fn ($q) => $q->where('organization_id', $auth->organization_id))
            ->findOrFail($note);

        $data = $request->validate([
            'flagged_reason' => ['nullable', 'string', 'max:500'],
        ]);

        $note->update([
            'is_flagged' => !$note->is_flagged,
            'flagged_reason' => $note->is_flagged ? null : ($data['flagged_reason'] ?? null),
        ]);

        return redirect()->back()->with('success', $note->is_flagged ? 'Note flagged.' : 'Flag removed.');
    }

    public function markReviewed(Request $request, $note)
    {
        $auth = $request->user();
        abort_unless($auth && $auth->canDo('shifts.viewAny'), 403);

        $note = ClientNote::query()
            ->when($auth->organization_id, fn ($q) => $q->where('organization_id', $auth->organization_id))
            ->findOrFail($note);

        $note->update([
            'reviewed_at' => now(),
            'reviewed_by' => $auth->id,
        ]);

        return redirect()->back()->with('success', 'Note marked as reviewed.');
    }
}
