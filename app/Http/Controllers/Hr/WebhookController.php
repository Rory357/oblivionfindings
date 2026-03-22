<?php

namespace App\Http\Controllers\Hr;

use App\Http\Controllers\Controller;
use App\Domain\Hr\Models\HrWebhook;
use App\Domain\Hr\Services\WebhookDispatchService;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;

class WebhookController extends Controller
{
    public function __construct(
        private readonly WebhookDispatchService $webhookService,
    ) {}

    /**
     * List all webhooks for the tenant.
     */
    public function index(Request $request)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.settings.manage'), 403);

        $webhooks = HrWebhook::forTenant($user->tenant_id)
            ->with('creator:id,name')
            ->orderByDesc('created_at')
            ->get();

        return Inertia::render('hr/settings/webhooks', [
            'webhooks' => $webhooks,
            'availableEvents' => $this->webhookService->getAvailableEvents(),
        ]);
    }

    /**
     * Create a new webhook.
     */
    public function store(Request $request)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.settings.manage'), 403);

        $validated = $request->validate([
            'url' => ['required', 'url', 'max:2048'],
            'events' => ['required', 'array', 'min:1'],
            'events.*' => ['string', 'in:' . implode(',', WebhookDispatchService::EVENTS)],
        ]);

        HrWebhook::create([
            'tenant_id' => $user->tenant_id,
            'url' => $validated['url'],
            'secret' => Str::random(64),
            'events' => $validated['events'],
            'is_active' => true,
            'created_by' => $user->id,
        ]);

        return redirect()->route('hr.settings.webhooks')
            ->with('success', 'Webhook created successfully.');
    }

    /**
     * Update an existing webhook.
     */
    public function update(Request $request, HrWebhook $webhook)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.settings.manage'), 403);
        abort_unless($webhook->tenant_id === $user->tenant_id, 403);

        $validated = $request->validate([
            'url' => ['required', 'url', 'max:2048'],
            'events' => ['required', 'array', 'min:1'],
            'events.*' => ['string', 'in:' . implode(',', WebhookDispatchService::EVENTS)],
            'is_active' => ['boolean'],
        ]);

        $webhook->update($validated);

        return redirect()->route('hr.settings.webhooks')
            ->with('success', 'Webhook updated successfully.');
    }

    /**
     * Delete a webhook.
     */
    public function destroy(Request $request, HrWebhook $webhook)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.settings.manage'), 403);
        abort_unless($webhook->tenant_id === $user->tenant_id, 403);

        $webhook->delete();

        return redirect()->route('hr.settings.webhooks')
            ->with('success', 'Webhook deleted successfully.');
    }

    /**
     * Send a test payload to the webhook.
     */
    public function test(Request $request, HrWebhook $webhook)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.settings.manage'), 403);
        abort_unless($webhook->tenant_id === $user->tenant_id, 403);

        $result = $this->webhookService->sendTest($webhook);

        return back()->with(
            $result['success'] ? 'success' : 'error',
            $result['success']
                ? "Test webhook sent successfully (HTTP {$result['status']})."
                : "Test webhook failed: {$result['body']}"
        );
    }
}
