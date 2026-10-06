<?php

namespace App\Services\Medication;

use App\Http\Controllers\Emar\MedicationOrdersController;
use App\Models\Client;
use App\Models\ClientMedication;
use App\Models\MedicationCovertAuthorisation;
use App\Models\MedicationOrderRevision;
use App\Models\User;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Inertia\Inertia;

/** Existing entry points share P04's source/version/check authority and receipts. */
final class MedicationLegacyOrderBridge
{
    public function __construct(private readonly MedicationOrderWorkflow $orders, private readonly MedicationRecordAccess $access) {}

    public function enter(Request $request, ?ClientMedication $medication = null): mixed
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 403);
        $clientId = $request->integer('client_id') ?: (int) $medication?->client_id;
        if (is_array($request->input('source')) && is_array($request->input('prescription'))) {
            $this->orders->enter($actor, $clientId, $medication?->id, $request->all(), $request->file('source_file'));

            return back()->with('success', 'Order version saved. Waiting to be checked.');
        }
        $callback = function (Client $client, ?ClientMedication $order, User $locked) use ($request) {
            $this->access->assertReadable($locked, $client);
            $this->orders->assertControlled($locked, $order ?? $request->boolean('controlled_drug', $request->boolean('is_controlled_drug')));
            $this->conflict($request, 'Enter the prescription from its source in Orders. The checked version stays in effect until the change is checked.',
                $this->url($client->id, $order?->id, 'entry'));
        };
        if ($medication === null) {
            return $this->orders->forClient($actor, $clientId, 'medications.orders.manage', fn ($client, $locked) => $callback($client, null, $locked));
        }

        return $this->orders->forMedication($actor, $medication->id, 'medications.orders.manage', $callback, expectedClientId: $clientId);
    }

    public function check(Request $request, ClientMedication $medication, bool $sendBack = false): mixed
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 403);
        if ($request->filled('revision_id')) {
            abort_if($request->filled('client_id') && $request->integer('client_id') !== (int) $medication->client_id, 404);
            $revision = MedicationOrderRevision::query()->canonicalVersion()->whereKey($request->integer('revision_id'))
                ->where('client_medication_id', $medication->id)->where('client_id', $medication->client_id)->firstOrFail();
            if ($sendBack) {
                $this->orders->sendBack($actor, $revision->id, (string) $request->input('reason', $request->input('rejection_reason', '')));
            } else {
                $this->orders->check($actor, $revision->id, $request->all());
            }

            return back()->with('success', $sendBack ? 'Order version sent back.' : 'Order version checked.');
        }

        return $this->orders->forMedication($actor, $medication->id, 'medications.orders.verify', function ($client, $order, $locked) use ($request, $sendBack) {
            $this->access->assertReadable($locked, $client);
            $this->orders->assertControlled($locked, $order);
            $waiting = MedicationOrderRevision::query()->canonicalVersion()->where('client_id', $client->id)->where('client_medication_id', $order->id)->where('status', 'pending')->exists();
            $action = $waiting ? 'check' : ($order->approval_status !== 'verified' ? 'entry' : 'view');
            $this->conflict($request, 'Open the order version and its source in Orders to check it or send it back.',
                $this->url($client->id, $order->id, $action).($sendBack && $waiting ? '&mode=send_back' : ''));
        }, expectedClientId: $request->integer('client_id') ?: (int) $medication->client_id);
    }

    public function covert(Request $request): mixed
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 403);
        $medicationId = $request->integer('client_medication_id');
        if ($request->has('capacity_record') && $request->hasFile('gp_file')) {
            $order = ClientMedication::query()->findOrFail($medicationId);
            abort_unless($request->integer('client_id') === (int) $order->client_id, 404);

            return app(MedicationOrdersController::class)->authoriseCovert($request, $medicationId);
        }

        return $this->orders->forOfficeMedication($actor, $medicationId, function ($client, $order, $locked) use ($request) {
            abort_unless($request->integer('client_id') === (int) $client->id, 404);
            $this->access->assertReadable($locked, $client);
            $this->orders->assertControlled($locked, $order);
            $this->conflict($request, 'Record the capacity assessment, consultation, pharmacist advice and signed prescriber source in Orders before authorising covert giving.',
                $this->url($client->id, $order->id, 'covert'));
        });
    }

    public function revokeCovert(Request $request, MedicationCovertAuthorisation $authorisation): mixed
    {
        return app(MedicationOrdersController::class)->revokeCovert($request, $authorisation->id);
    }

    private function url(int $clientId, ?int $medicationId, string $action): string
    {
        return '/emar/prescriptions?'.http_build_query(['client_id' => $clientId, 'order_id' => $medicationId, 'action' => $action]);
    }

    private function conflict(Request $request, string $message, string $url): never
    {
        // Throw inside the source scope so the incompatible attempt rolls back
        // even break-glass/audit side effects. It cannot publish a prescription.
        $response = $request->header('X-Inertia')
            ? Inertia::location($url)
            : ($request->expectsJson()
                ? response()->json(['message' => $message, 'orders_url' => $url], 409)->header('Cache-Control', 'private, no-store')
                : redirect()->to($url, 303)->with('info', $message));
        throw new HttpResponseException($response);
    }
}
