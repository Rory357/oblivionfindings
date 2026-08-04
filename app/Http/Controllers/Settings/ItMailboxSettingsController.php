<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\UpdateItMailboxRequest;
use App\Jobs\PollItMailboxJob;
use App\Models\ItMailboxConnection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Settings surface for the IT support-mailbox connection (email-to-ticket,
 * E6). Shows per-provider connection status, lets the admin point the
 * Microsoft connection at a delegated support@ mailbox, and triggers an
 * on-demand poll. OAuth connect/disconnect lives on ItMailboxOAuthController.
 */
class ItMailboxSettingsController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorizeManage($request);

        // House rule: guard new-table reads so a request racing the deploy's
        // migration step renders empty instead of a 500.
        $connections = Schema::hasTable('it_mailbox_connections')
            ? ItMailboxConnection::query()
                ->get()
                ->keyBy('provider')
            : collect();

        $payload = [];
        foreach ([ItMailboxConnection::PROVIDER_MICROSOFT, ItMailboxConnection::PROVIDER_GOOGLE] as $provider) {
            $connection = $connections->get($provider);
            $payload[$provider] = [
                'configured' => ! empty(config("services.{$provider}.client_id"))
                    && ! empty(config("services.{$provider}.client_secret")),
                'status' => $connection->status ?? null,
                'account_email' => $connection?->account_email,
                'account_name' => $connection?->account_name,
                'mailbox_email' => $connection?->mailbox_email,
                'effective_mailbox' => $connection?->mailboxEmail(),
                'last_polled_at' => $connection?->last_polled_at?->toIso8601String(),
                'last_error' => $connection?->last_error,
            ];
        }

        return Inertia::render('settings/it-mailbox', [
            'connections' => $payload,
        ]);
    }

    /**
     * Point the connection at a delegated support mailbox (Microsoft — the
     * connected account reads support@ via Mail.ReadWrite.Shared). Null falls
     * back to the account's own inbox. Gmail always reads its own inbox.
     */
    public function updateMailbox(UpdateItMailboxRequest $request, string $provider): RedirectResponse
    {
        $validated = $request->validated();

        $connection = ItMailboxConnection::query()
            ->where('provider', $provider)
            ->first();

        if (! $connection) {
            return redirect()->route('settings.it-mailbox')
                ->with('error', 'Connect '.ucfirst($provider).' first, then set the mailbox.');
        }

        $connection->update(['mailbox_email' => $validated['mailbox_email'] ?? null]);

        return redirect()->route('settings.it-mailbox')
            ->with('success', 'Support mailbox updated.');
    }

    /** Kick a poll without waiting for the hourly schedule. */
    public function pollNow(Request $request): RedirectResponse
    {
        $this->authorizeManage($request);

        PollItMailboxJob::dispatch();

        return redirect()->route('settings.it-mailbox')
            ->with('success', 'Mailbox poll started — new mail becomes tickets in a moment.');
    }

    private function authorizeManage(Request $request): void
    {
        abort_unless($request->user()?->canDo('integrations.manage_secrets'), 403);
    }
}
