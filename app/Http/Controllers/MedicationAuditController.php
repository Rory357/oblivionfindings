<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\SanitizesCsvOutput;
use App\Models\AuditLog;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class MedicationAuditController extends Controller
{
    use SanitizesCsvOutput;

    private function baseQuery()
    {
        return AuditLog::query()
            ->with(['user:id,name,email', 'client:id,first_name,last_name'])
            ->whereIn('auditable_type', [
                \App\Models\ClientMedication::class,
                \App\Models\ClientMedicationAdministration::class,
                \App\Models\ClientControlledDrugEntry::class,
                \App\Models\ClientControlledDrugDiscrepancy::class,
                \App\Models\ClientBreakGlassAccess::class,
            ])
            ->orderByDesc('id');
    }

    public function index(Request $request)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('medications.audit.view'), 403);

        $q = $this->baseQuery();

        if ($request->filled('client_id')) {
            $q->where('client_id', (int) $request->query('client_id'));
        }
        if ($request->filled('user_id')) {
            $q->where('user_id', (int) $request->query('user_id'));
        }
        if ($request->filled('from')) {
            $q->whereDate('created_at', '>=', $request->query('from'));
        }
        if ($request->filled('to')) {
            $q->whereDate('created_at', '<=', $request->query('to'));
        }

        $logs = $q->limit(200)->get()->map(fn ($l) => [
            'id' => $l->id,
            'created_at' => $l->created_at,
            'action' => $l->action,
            'auditable_type' => class_basename($l->auditable_type),
            'auditable_id' => $l->auditable_id,
            'client' => $l->client ? [
                'id' => $l->client->id,
                'name' => trim($l->client->first_name . ' ' . $l->client->last_name),
            ] : null,
            'user' => $l->user ? [
                'id' => $l->user->id,
                'name' => $l->user->name,
            ] : null,
            'meta' => $l->meta,
        ])->values();

        return inertia('medications/audit', [
            'filters' => [
                'client_id' => $request->query('client_id'),
                'user_id' => $request->query('user_id'),
                'from' => $request->query('from'),
                'to' => $request->query('to'),
            ],
            'logs' => $logs,
        ]);
    }

    public function exportCsv(Request $request): StreamedResponse
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('medications.reports.export'), 403);

        $q = $this->baseQuery();
        if ($request->filled('client_id')) $q->where('client_id', (int) $request->query('client_id'));
        if ($request->filled('user_id')) $q->where('user_id', (int) $request->query('user_id'));
        if ($request->filled('from')) $q->whereDate('created_at', '>=', $request->query('from'));
        if ($request->filled('to')) $q->whereDate('created_at', '<=', $request->query('to'));

        $filename = 'medications_audit_' . now()->format('Y-m-d_His') . '.csv';

        return response()->streamDownload(function () use ($q) {
            $out = fopen('php://output', 'w');
            $this->putCsv($out, ['Time', 'Action', 'Type', 'ID', 'Client', 'User', 'Meta']);
            $q->limit(5000)->get()->each(function ($l) use ($out) {
                $this->putCsv($out, [
                    optional($l->created_at)->toDateTimeString(),
                    $l->action,
                    class_basename($l->auditable_type),
                    $l->auditable_id,
                    $l->client ? trim($l->client->first_name . ' ' . $l->client->last_name) : '',
                    $l->user?->name ?? '',
                    json_encode($l->meta ?? []),
                ]);
            });
            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv']);
    }
}
