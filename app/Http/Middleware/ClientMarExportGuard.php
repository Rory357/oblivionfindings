<?php

namespace App\Http\Middleware;

use App\Models\Client;
use App\Services\MarScheduleService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Bind the retained one-person CSV to its route before the shared export gate. */
final class ClientMarExportGuard
{
    public function handle(Request $request, Closure $next): Response
    {
        $client = $request->route('client');
        abort_unless($client instanceof Client, 404);
        $request->validate(['client_id' => ['nullable', 'integer', 'min:1']]);
        abort_if($request->filled('client_id') && $request->integer('client_id') !== (int) $client->id, 404);

        $date = app(MarScheduleService::class)->dateFromInput($request->query('date'))->toDateString();
        $request->merge(['client_id' => (int) $client->id, 'date' => $date]);

        return app(MedicationExportGuard::class)->handle($request, $next, 'doses');
    }
}
