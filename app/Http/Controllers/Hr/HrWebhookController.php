<?php

namespace App\Http\Controllers\Hr;

use App\Domain\Hr\Models\HrWebhookDelivery;
use App\Domain\Hr\Models\HrWebhookEndpoint;
use App\Domain\Hr\Services\HrWebhookService;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Hr\Concerns\ResolvesHrTenant;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class HrWebhookController extends Controller
{
    use ResolvesHrTenant;

    public function __construct(
        private readonly HrWebhookService $webhookService,
    ) {}

    public function index(Request $request)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.settings.manage'), 403);

        $tenantId = $this->resolveHrTenantIdForUser($user);

        $endpoints = $this->webhookService->endpointsForTenant($tenantId)
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
            ->forTenant($tenantId)
            ->with('endpoint:id,name')
            ->orderByDesc('id')
            ->limit(100)
            ->get()
            ->map(fn (HrWebhookDelivery $delivery) => [
                'id' => $delivery->id,
                'endpoint_id' => $delivery->endpoint_id,
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
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.settings.manage'), 403);
        $tenantId = $this->resolveHrTenantIdForUser($user);

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

        $this->webhookService->createEndpoint($tenantId, $user->id, $validated);

        return redirect()->back()->with('success', 'Webhook endpoint created.');
    }

    public function update(Request $request, HrWebhookEndpoint $endpoint)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.settings.manage'), 403);
        $tenantId = $this->resolveHrTenantIdForUser($user);
        $this->assertHrTenantAccess($tenantId, $endpoint->tenant_id);

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

        $this->webhookService->updateEndpoint($endpoint, $user->id, $validated);

        return redirect()->back()->with('success', 'Webhook endpoint updated.');
    }

    public function toggle(Request $request, HrWebhookEndpoint $endpoint)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.settings.manage'), 403);
        $tenantId = $this->resolveHrTenantIdForUser($user);
        $this->assertHrTenantAccess($tenantId, $endpoint->tenant_id);

        $wasActive = (bool) $endpoint->is_active;
        $this->webhookService->updateEndpoint($endpoint, $user->id, [
            'is_active' => ! $wasActive,
        ]);

        return redirect()->back()->with('success', $wasActive ? 'Webhook endpoint paused.' : 'Webhook endpoint resumed.');
    }

    public function retryDelivery(Request $request, HrWebhookDelivery $delivery)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.settings.manage'), 403);
        $tenantId = $this->resolveHrTenantIdForUser($user);
        $this->assertHrTenantAccess($tenantId, $delivery->tenant_id);

        $this->webhookService->queueRetry($delivery);

        return redirect()->back()->with('success', 'Webhook delivery retry queued.');
    }
}
