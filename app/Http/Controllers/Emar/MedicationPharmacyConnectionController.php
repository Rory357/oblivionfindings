<?php

namespace App\Http\Controllers\Emar;

use App\Http\Controllers\Controller;
use App\Models\MedicationPharmacyConnection;
use App\Models\MedicationPharmacyOrder;
use App\Services\Medication\PharmacyConnect\PharmacyAcknowledgmentVerifier;
use App\Services\Medication\PharmacyConnect\PharmacyConnectionException;
use App\Services\Medication\PharmacyConnect\PharmacyConnectionService;
use App\Services\Medication\PharmacyConnect\PharmacyDispatchService;
use App\Services\Medication\PharmacyConnect\PharmacyPartnerRegistry;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;

final class MedicationPharmacyConnectionController extends Controller
{
    public function __construct(private readonly PharmacyConnectionService $connections, private readonly PharmacyDispatchService $dispatches,
        private readonly PharmacyPartnerRegistry $partners, private readonly PharmacyAcknowledgmentVerifier $acknowledgments) {}

    public function index(Request $request)
    {
        if (! $request->expectsJson()) {
            return Inertia::render('emar/pharmacy/index', $this->connections->read($request->user()))->toResponse($request)
                ->header('Cache-Control', 'private, no-store');
        }

        return $this->respond(fn () => $this->connections->read($request->user()));
    }

    public function directory(Request $request)
    {
        return Inertia::render('emar/ConnectedServices')->toResponse($request)->header('Cache-Control', 'private, no-store');
    }

    public function store(Request $request): JsonResponse
    {
        return $this->respond(fn () => $this->connections->save($request->user(), $this->connectionInput($request)), 201);
    }

    public function update(Request $request, int $connection): JsonResponse
    {
        return $this->respond(fn () => $this->connections->save($request->user(), $this->connectionInput($request, true), $connection));
    }

    public function order(Request $request, MedicationPharmacyOrder $order): JsonResponse
    {
        return $this->respond(fn () => $this->dispatches->read($request->user(), $order));
    }

    public function dispatch(Request $request, MedicationPharmacyOrder $order): JsonResponse
    {
        $data = $request->validate(['connection_id' => 'required|integer|min:1', 'request_uuid' => 'required|uuid']);

        return $this->respond(fn () => $this->dispatches->queue($request->user(), $order, $data['connection_id'], $data['request_uuid']), 202);
    }

    public function retry(Request $request, MedicationPharmacyOrder $order, int $dispatch): JsonResponse
    {
        return $this->command($request, $order, $dispatch, 'retry');
    }

    public function cancel(Request $request, MedicationPharmacyOrder $order, int $dispatch): JsonResponse
    {
        return $this->command($request, $order, $dispatch, 'cancel');
    }

    public function resolve(Request $request, MedicationPharmacyOrder $order, int $dispatch): JsonResponse
    {
        return $this->command($request, $order, $dispatch, 'resolve');
    }

    public function acknowledge(Request $request, int $connection): JsonResponse
    {
        return $this->respond(function () use ($request, $connection): array {
            // No user session authenticates this endpoint. The signature binds the raw body and this exact connection.
            // EA-137: an unknown connection, an unconfigured partner and a bad
            // signature all answer the same 401, after the same signature
            // check, so the endpoint reveals nothing before authentication.
            $record = MedicationPharmacyConnection::query()->find($connection);
            $secret = null;
            if ($record !== null) {
                try {
                    $secret = $this->partners->partner($record->partner_key)['acknowledgment_secret'];
                } catch (PharmacyConnectionException) {
                    $secret = null;
                }
            }
            $body = $request->getContent();
            $data = $this->acknowledgments->verify($connection, $body, (string) $request->header('X-Pharmacy-Timestamp'),
                (string) $request->header('X-Pharmacy-Signature'), $secret ?? bin2hex(random_bytes(32)));
            if ($record === null || $secret === null) {
                throw new PharmacyConnectionException('acknowledgment_unauthenticated', 'The pharmacy acknowledgment could not be authenticated.', 401);
            }

            return $this->dispatches->acknowledge($record, $data, hash('sha256', $body));
        });
    }

    private function connectionInput(Request $request, bool $update = false): array
    {
        return $request->validate(['name' => 'required|string|max:100', 'partner_key' => 'required|string|max:80|regex:/^[a-zA-Z0-9_-]+$/',
            'site_ids' => 'required|array|min:1|max:100', 'site_ids.*' => 'required|integer|min:1|distinct', 'enabled' => 'required|boolean',
            'expected_version' => $update ? 'required|integer|min:1' : 'prohibited',
            'url' => 'prohibited', 'endpoint' => 'prohibited', 'credentials' => 'prohibited', 'token' => 'prohibited', 'secret' => 'prohibited']);
    }

    private function command(Request $request, MedicationPharmacyOrder $order, int $dispatch, string $action): JsonResponse
    {
        $data = $request->validate(['request_uuid' => 'required|uuid', 'expected_state' => 'required|in:queued,sending,sent,accepted,rejected,failed,unknown,cancelled',
            'confirmed_not_received' => $action === 'resolve' ? 'required|accepted' : 'prohibited',
            'reference' => $action === 'resolve' ? 'required|string|max:500' : 'prohibited']);
        if ($action === 'resolve') {
            $data['confirmed_not_received'] = true;
        }

        return $this->respond(fn () => $this->dispatches->command($request->user(), $order, $dispatch, $action, $data), $action === 'retry' ? 202 : 200);
    }

    private function respond(Closure $callback, int $status = 200): JsonResponse
    {
        try {
            return response()->json(['success' => true, ...$callback()], $status)->header('Cache-Control', 'private, no-store');
        } catch (PharmacyConnectionException $exception) {
            return response()->json(['success' => false, 'code' => $exception->errorCode, 'message' => $exception->getMessage()], $exception->status)
                ->header('Cache-Control', 'private, no-store');
        }
    }
}
