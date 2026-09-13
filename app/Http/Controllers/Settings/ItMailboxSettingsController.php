<?php

namespace App\Http\Controllers\Settings;

use App\Domain\It\Services\ItMailboxConfigurationService;
use App\Domain\It\Services\ItMailboxConnectionPresenter;
use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\UpdateItMailboxRequest;
use App\Jobs\PollItMailboxJob;
use App\Models\ItMailboxConnection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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
    public function index(Request $request): Response|JsonResponse
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
            $payload[$provider] = app(ItMailboxConnectionPresenter::class)->present($provider, $connection);
        }
        if ($request->expectsJson()) {
            return response()->json(['connections' => $payload]);
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
    public function updateMailbox(UpdateItMailboxRequest $request, string $provider): RedirectResponse|JsonResponse
    {
        $connection = app(ItMailboxConfigurationService::class)->changeMailbox($request, $provider, $request->validated());
        if ($request->expectsJson()) {
            return response()->json(['status' => 'saved', 'connection' => app(ItMailboxConnectionPresenter::class)->present($provider, $connection)]);
        }

        return redirect()->route('settings.it-mailbox')
            ->with('success', 'Support mailbox saved after a provider read-access check. A completed poll remains unverified.');
    }

    /** Kick a poll without waiting for the hourly schedule. */
    public function pollNow(Request $request): RedirectResponse|JsonResponse
    {
        $this->authorizeManage($request);

        $input = $request->validate([
            'provider' => ['required', 'in:microsoft,google'],
            'connection_id' => ['required', 'integer', 'min:1'],
            'expected_version' => ['required', 'integer', 'min:1'],
        ]);
        $connection = DB::transaction(function () use ($request, $input): ItMailboxConnection {
            $this->authorizeManage($request);
            $current = ItMailboxConnection::query()->where('provider', $input['provider'])->lockForUpdate()->first();
            abort_unless($current && (int) $current->id === (int) $input['connection_id']
                && $current->configuration_version === (int) $input['expected_version'], 409, 'The connection changed. Reload its current state.');
            abort_unless(app(ItMailboxConnectionPresenter::class)->present($input['provider'], $current)['can_poll'], 409,
                'The connection cannot poll yet. Reload its authorization, retry time and active-operation state.');

            return $current;
        });
        PollItMailboxJob::dispatch((int) $connection->id, $connection->configuration_version);
        if ($request->expectsJson()) {
            return response()->json([
                'status' => 'requested',
                'connection' => app(ItMailboxConnectionPresenter::class)->present($input['provider'], $connection->fresh()),
            ]);
        }

        return redirect()->route('settings.it-mailbox')
            ->with('success', 'Mailbox poll requested. Connections awaiting authorization, retry time or another active poll are skipped; review the recorded result.');
    }

    private function authorizeManage(Request $request): void
    {
        abort_unless($request->user()?->fresh()?->canDo('integrations.manage_secrets'), 403);
    }
}
