<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Client;
use App\Models\User;
use Illuminate\Http\Request;

class AuditLogController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('audit.viewAny'), 403);

        $filters = $request->validate([
            'action' => ['nullable', 'string', 'max:120'],
            'user_id' => ['nullable', 'integer'],
            'client_id' => ['nullable', 'integer'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date'],
            'q' => ['nullable', 'string', 'max:200'],
        ]);

        $q = AuditLog::query()
            ->with([
                'user:id,name,email',
                'client:id,first_name,last_name',
            ])
            ->orderByDesc('created_at');

        if (!empty($filters['action'])) {
            $q->where('action', 'like', '%' . $filters['action'] . '%');
        }
        if (!empty($filters['user_id'])) {
            $q->where('user_id', $filters['user_id']);
        }
        if (!empty($filters['client_id'])) {
            $q->where('client_id', $filters['client_id']);
        }
        if (!empty($filters['date_from'])) {
            $q->whereDate('created_at', '>=', $filters['date_from']);
        }
        if (!empty($filters['date_to'])) {
            $q->whereDate('created_at', '<=', $filters['date_to']);
        }
        if (!empty($filters['q'])) {
            $needle = $filters['q'];
            $q->where(function ($sub) use ($needle) {
                $sub->where('action', 'like', "%{$needle}%")
                    ->orWhere('auditable_type', 'like', "%{$needle}%")
                    ->orWhere('ip_address', 'like', "%{$needle}%")
                    ->orWhereHas('user', fn ($uq) => $uq->where('name', 'like', "%{$needle}%")
                        ->orWhere('email', 'like', "%{$needle}%"))
                    ->orWhereHas('client', fn ($cq) => $cq->where('first_name', 'like', "%{$needle}%")
                        ->orWhere('last_name', 'like', "%{$needle}%"));
            });
        }

        $logs = $q->paginate(50)->withQueryString();

        // Small reference lists for filters
        $users = User::query()
            ->orderBy('name')
            ->limit(250)
            ->get(['id', 'name', 'email']);

        $clients = Client::query()
            ->orderBy('first_name')
            ->limit(250)
            ->get(['id', 'first_name', 'last_name']);

        return inertia('audit/index', [
            'logs' => $logs,
            'filters' => $filters,
            'filter_options' => [
                'users' => $users,
                'clients' => $clients,
            ],
        ]);
    }
}
