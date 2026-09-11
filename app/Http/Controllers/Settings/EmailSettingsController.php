<?php

namespace App\Http\Controllers\Settings;

use App\Domain\It\Services\ItEmailDeliveryService;
use App\Domain\It\Services\ItOutboundMailer;
use App\Http\Controllers\Controller;
use App\Models\ItEmailDelivery;
use App\Services\EmailConfiguration;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;

class EmailSettingsController extends Controller
{
    public function __construct(private readonly EmailConfiguration $settings) {}

    public function index(Request $request)
    {
        $this->authorizeAccess($request);
        $state = $this->state($request);

        return $request->expectsJson() ? response()->json(['data' => $state]) : inertia('settings/email-settings', $state);
    }

    public function update(Request $request)
    {
        $this->authorizeAccess($request);
        $saved = $this->settings->save($request);

        return $request->expectsJson() ? response()->json(['data' => $this->state($request, $saved)])
            : back()->with('success', 'Email settings saved.');
    }

    public function test(Request $request, ItEmailDeliveryService $deliveries)
    {
        $this->authorizeAccess($request);
        abort_unless($this->settings->canManage($request->user()?->fresh()), 403);
        $input = $request->validate(['request_uuid' => ['required', 'uuid'], 'expected_version' => ['required', 'integer', 'min:1'], 'expected_actor_id' => ['required', 'integer', 'min:1']]);
        abort_unless((int) $input['expected_actor_id'] === (int) $request->user()->id, 403);
        $delivery = $deliveries->prepareConfigurationTest($request, $input['request_uuid'], (int) $input['expected_version']);
        $deliveries->dispatchPending(limit: 1, deliveryId: $delivery->id);

        return response()->json(['data' => $this->testState($delivery->fresh())]);
    }

    public function showTest(Request $request, string $uuid)
    {
        $this->authorizeAccess($request);
        abort_unless($this->settings->canManage($request->user()?->fresh()), 403);
        $delivery = ItEmailDelivery::query()->where('recipient_user_id', $request->user()->id)
            ->where('notification_type', 'it_email_configuration_test')->where('notification_uuid', $uuid)->firstOrFail();

        return response()->json(['data' => $this->testState($delivery)]);
    }

    private function authorizeAccess(Request $request): void
    {
        abort_unless($request->user()?->fresh()?->canDo('settings.access.manage'), 403);
    }

    private function state(Request $request, ?array $configuration = null): array
    {
        $canManage = $this->settings->canManage($request->user()?->fresh());
        $configuration ??= $this->settings->current();
        $issue = null;
        if ($configuration['support_enabled']) {
            try {
                $this->settings->supportConnection($configuration);
            } catch (\DomainException $exception) {
                $issue = $exception->getMessage();
            }
        }
        $test = $canManage ? ItEmailDelivery::query()->where('recipient_user_id', $request->user()->id)
            ->where('notification_type', 'it_email_configuration_test')->latest('id')->first() : null;

        return [
            'actor_id' => (int) $request->user()->id,
            'settings' => Arr::except($configuration, 'support_connection_scope_hash'),
            'can_manage' => $canManage,
            'connections' => $canManage ? $this->settings->connections() : [],
            'smtp_password_saved' => $this->settings->passwordSaved(),
            'capture_mode' => app(ItOutboundMailer::class)->captureMode(),
            'delivery_issue' => $issue,
            'last_test' => $test ? $this->testState($test) : null,
        ];
    }

    private function testState(ItEmailDelivery $delivery): array
    {
        return [
            'request_uuid' => $delivery->notification_uuid,
            'configuration_version' => $delivery->notification_context['configuration_version'] ?? null,
            'capture_mode' => $delivery->notification_context['capture_mode'] ?? null,
            'status' => $delivery->status,
            'recipient_email' => $delivery->recipient_email,
            'attempt_count' => $delivery->attempt_count,
            'created_at' => $delivery->created_at?->toIso8601String(),
            'accepted_at' => $delivery->accepted_at?->toIso8601String(),
            'delivered_at' => $delivery->delivered_at?->toIso8601String(),
            'failed_at' => $delivery->failed_at?->toIso8601String(),
        ];
    }
}
