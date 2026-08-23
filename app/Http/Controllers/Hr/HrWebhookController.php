<?php

namespace App\Http\Controllers\Hr;

use App\Domain\Hr\Models\HrWebhookDelivery;
use App\Domain\Hr\Models\HrWebhookEndpoint;
use App\Domain\Hr\Services\HrWebhookService;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class HrWebhookController extends Controller
{
    public function __construct(
        private readonly HrWebhookService $webhookService,
    ) {}

    public function index(Request $request)
    {
        $user = $this->authorizedActor($request);

        $endpoints = $this->webhookService->endpointsForApplication($user)
            ->map(fn (HrWebhookEndpoint $endpoint) => [
                'id' => $endpoint->id,
                'name' => $endpoint->name,
                'target_url' => $endpoint->target_url,
                'event_types' => $endpoint->event_types ?? [],
                'headers' => $endpoint->headers ?? [],
                'timeout_seconds' => (int) $endpoint->timeout_seconds,
                'retry_limit' => (int) $endpoint->retry_limit,
                'is_active' => (bool) $endpoint->is_active,
                'last_delivery_at' => optional($endpoint->last_delivery_at)->toDateTimeString(),
                'last_status' => $endpoint->last_status,
                'last_error' => $endpoint->last_error,
                'deliveries_count' => (int) $endpoint->deliveries_count,
                'failed_deliveries_count' => (int) $endpoint->failed_deliveries_count,
            ])
            ->values();

        $deliveries = HrWebhookDelivery::query()
            ->with('endpoint:id,name')
            ->withExists(['retry as has_retry'])
            ->orderByDesc('id')
            ->limit(100)
            ->get()
            ->map(fn (HrWebhookDelivery $delivery) => [
                'id' => $delivery->id,
                'endpoint_id' => $delivery->endpoint_id,
                'retry_of_id' => $delivery->retry_of_id,
                'has_retry' => (bool) $delivery->has_retry,
                'endpoint_name' => $delivery->endpoint?->name,
                'event_type' => $delivery->event_type,
                'status' => $delivery->status,
                'attempts' => (int) $delivery->attempts,
                'max_attempts' => (int) $delivery->max_attempts,
                'queued_at' => optional($delivery->queued_at)->toDateTimeString(),
                'delivered_at' => optional($delivery->delivered_at)->toDateTimeString(),
                'failed_at' => optional($delivery->failed_at)->toDateTimeString(),
                'response_code' => $delivery->response_code,
                'error_message' => $delivery->error_message,
            ])
            ->values();

        return Inertia::render('hr/settings/webhooks', [
            'endpoints' => $endpoints,
            'deliveries' => $deliveries,
            'eventOptions' => $this->webhookService->eventOptions(),
            'can' => [
                'manage' => $user->canDo('hr.settings.manage'),
            ],
        ]);
    }

    public function store(Request $request)
    {
        $user = $this->authorizedActor($request);
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'target_url' => ['required', 'url', 'max:1500'],
            'signing_secret' => ['nullable', 'string', 'max:255'],
            'event_types' => ['required', 'array', 'min:1'],
            'event_types.*' => ['string', Rule::in(array_merge(HrWebhookService::SUPPORTED_EVENTS, ['*']))],
            'headers' => ['nullable', 'array'],
            'headers.*' => ['nullable', 'string', 'max:500'],
            'timeout_seconds' => ['nullable', 'integer', 'min:2', 'max:30'],
            'retry_limit' => ['nullable', 'integer', 'min:1', 'max:6'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $this->webhookService->createEndpoint($user, $validated);

        return redirect()->back()->with('success', 'Webhook endpoint created.');
    }

    public function update(Request $request, string $endpoint)
    {
        $user = $this->authorizedActor($request);
        $endpointRecord = $this->endpoint($endpoint);
        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:120'],
            'target_url' => ['sometimes', 'url', 'max:1500'],
            'signing_secret' => ['nullable', 'string', 'max:255'],
            'event_types' => ['sometimes', 'array', 'min:1'],
            'event_types.*' => ['string', Rule::in(array_merge(HrWebhookService::SUPPORTED_EVENTS, ['*']))],
            'headers' => ['nullable', 'array'],
            'headers.*' => ['nullable', 'string', 'max:500'],
            'timeout_seconds' => ['sometimes', 'integer', 'min:2', 'max:30'],
            'retry_limit' => ['sometimes', 'integer', 'min:1', 'max:6'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $this->webhookService->updateEndpoint($user, $endpointRecord, $validated);

        return redirect()->back()->with('success', 'Webhook endpoint updated.');
    }

    public function toggle(Request $request, string $endpoint)
    {
        $user = $this->authorizedActor($request);
        $endpointRecord = $this->endpoint($endpoint);
        $wasActive = (bool) $endpointRecord->is_active;
        $this->webhookService->updateEndpoint($user, $endpointRecord, [
            'is_active' => ! $wasActive,
        ]);

        return redirect()->back()->with('success', $wasActive ? 'Webhook endpoint paused.' : 'Webhook endpoint resumed.');
    }

    public function retryDelivery(Request $request, string $delivery)
    {
        $user = $this->authorizedActor($request);
        $deliveryRecord = $this->delivery($delivery);
        $this->webhookService->queueRetry($user, $deliveryRecord);

        return redirect()->back()->with('success', 'Webhook delivery retry queued.');
    }

    private function authorizedActor(Request $request): User
    {
        $actor = $request->user();
        abort_unless($actor instanceof User && $actor->canDo('hr.settings.manage'), 403);

        return $actor;
    }

    private function endpoint(string $endpoint): HrWebhookEndpoint
    {
        abort_unless(ctype_digit($endpoint) && (int) $endpoint > 0, 404);

        return HrWebhookEndpoint::query()->findOrFail((int) $endpoint);
    }

    private function delivery(string $delivery): HrWebhookDelivery
    {
        abort_unless(ctype_digit($delivery) && (int) $delivery > 0, 404);

        return HrWebhookDelivery::query()->findOrFail((int) $delivery);
    }
}
