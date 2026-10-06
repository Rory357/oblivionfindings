<?php

namespace App\Http\Middleware;

use App\Models\Client;
use App\Models\ClientMedication;
use App\Models\User;
use App\Services\Medication\Reporting\MedicationExportAudit;
use App\Services\Medication\Reporting\MedicationReportAccess;
use App\Services\Medication\Reporting\MedicationReportPeriod;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Guards retained PDF/CSV routes; streaming begins only after scope and audit succeed. */
final class MedicationExportGuard
{
    public function handle(Request $request, Closure $next, string $type): Response
    {
        $actor = $request->user();
        $access = app(MedicationReportAccess::class);
        abort_unless($actor && $access->canExport($actor, $type), 403);
        $purpose = app(MedicationExportAudit::class)->purpose($request);
        $request->validate(['client_id' => ['nullable', 'integer', 'min:1'], 'site_id' => ['nullable', 'integer', 'min:1']]);
        if ($request->filled('date')) {
            $request->merge(['period' => 'custom', 'date_from' => $request->input('date'), 'date_to' => $request->input('date')]);
        } elseif ($request->filled('from') || $request->filled('to')) {
            $request->merge(['period' => 'custom', 'date_from' => $request->input('from'), 'date_to' => $request->input('to')]);
        }
        $period = MedicationReportPeriod::fromRequest($request);
        if (in_array($type, ['mar', 'cd_register'], true)) {
            abort_if(\Carbon\CarbonImmutable::parse($period->from)->diffInDays(\Carbon\CarbonImmutable::parse($period->to)) >= 31, 422, 'PDF records support up to 31 NZ calendar days. Choose a shorter period.');
        }
        $request->merge(['date_from' => $period->from, 'date_to' => $period->to]);
        $clientId = $request->integer('client_id') ?: null;
        $sites = $access->siteIds($actor, $request->integer('site_id') ?: null, $clientId, in_array($type, ['controlled', 'cd_register'], true) ? 'controlled' : 'doses');
        $snapshot = $this->scopeEvidence($actor, $sites, $clientId);
        $response = $next($request);
        if ($response->isRedirection() || ! $response->isSuccessful()) {
            return $response;
        }
        if ($response instanceof StreamedResponse) {
            // Existing CSV controllers defer their query until the callback.
            // Buffer it now; no header or byte escapes before the final gate.
            ob_start();
            try {
                ($response->getCallback())();
                $bytes = ob_get_contents();
            } finally {
                ob_end_clean();
            }
            $response = new Response($bytes, $response->getStatusCode(), $response->headers->all());
        }
        app(MedicationExportAudit::class)->record($actor, $type, $sites, $period, $purpose, $clientId, ['legacy_route' => $request->route()->getName()], function (User $current) use ($sites, $clientId, $snapshot) {
            abort_unless(hash_equals($snapshot, $this->scopeEvidence($current, $sites, $clientId)), 409, 'The records or your access changed while the file was being prepared. Refresh and try again.');
        });
        $response->headers->set('Cache-Control', 'no-store');

        return $response;
    }

    private function scopeEvidence(User $actor, array $sites, ?int $clientId): string
    {
        $allowed = app(MedicationReportAccess::class)->clientIds($actor, $sites);
        $clients = Client::query()->whereIn('site_id', $sites)->when($clientId, fn ($q) => $q->whereKey($clientId))->orderBy('id')->get(['id', 'site_id']);
        // Retained controllers predate the per-person projection. A partially
        // readable house must use the new scoped route, never receive its full
        // legacy export. Exact one-person requests still work.
        abort_if($clients->isEmpty() && $clientId !== null, 404);
        abort_if(array_diff($clients->pluck('id')->all(), $allowed) !== [], 404);
        $medicines = ClientMedication::withTrashed()->whereIn('client_id', $clients->pluck('id'))->orderBy('id')->get(['id', 'client_id', 'controlled_drug']);

        return hash('sha256', json_encode([$clients->toArray(), $medicines->toArray(), $actor->canDo('medications.controlled.view')], JSON_THROW_ON_ERROR));
    }
}
