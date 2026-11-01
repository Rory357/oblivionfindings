<?php

namespace App\Domain\SecurityDevices\Hubs\Http\Controllers;

use App\Domain\Monitoring\Models\MonitoringCollector;
use App\Domain\Monitoring\Services\CollectorEnrollmentService;
use App\Domain\SecurityDevices\Endpoints\Services\EndpointReleaseCatalogue;
use App\Domain\SecurityDevices\Endpoints\Services\EndpointReleaseUnavailable;
use App\Domain\SecurityDevices\Hubs\Models\SetupCode;
use App\Domain\SecurityDevices\Hubs\Services\HubApprovalService;
use App\Domain\SecurityDevices\Hubs\Services\HubInstallationSettings;
use App\Domain\SecurityDevices\Hubs\Services\HubUpdatePolicy;
use App\Domain\SecurityDevices\Hubs\Services\SetupCodeService;
use App\Domain\SecurityDevices\Services\SecurityDevicesAccessService;
use App\Http\Controllers\Controller;
use App\Models\Site;
use App\Models\User;
use App\Services\AuditLogger;
use App\Support\SecurityPolicy;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Setup › Hubs & networks › Hubs: add a hub (pop-up 12), approve (79), remove (14),
 * edit (74), replace (73) and hold updates for every hub (D3).
 */
final class HubAdminController extends Controller
{
    public function __construct(
        private readonly SecurityDevicesAccessService $access,
        private readonly SetupCodeService $codes,
    ) {}

    /** Add a hub, step 2: a setup code for this Site, carried in the download's file name. */
    public function issue(Request $request, HubInstallationSettings $settings): JsonResponse
    {
        $actor = $this->actor($request, 'securityDevices.integrations.manage');
        $validated = $request->validate([
            'site_id' => ['required', 'integer', 'min:1'],
            'platform' => ['required', 'in:windows,linux'],
            'name' => ['required', 'string', 'min:2', 'max:120', 'not_regex:/[\x00-\x1F\x7F]/'],
            'covered_site_ids' => ['sometimes', 'array', 'max:64'],
            'covered_site_ids.*' => ['integer', 'min:1'],
        ]);
        $covered = array_values(array_unique(array_map('intval', $validated['covered_site_ids'] ?? [])));
        $this->access->assertCanViewSite($actor, (int) $validated['site_id']);
        foreach ($covered as $siteId) {
            $this->access->assertCanViewSite($actor, $siteId);
        }
        $site = $this->operationalSite((int) $validated['site_id']);

        try {
            $issued = $this->codes->issueHub($actor, $site, (string) $validated['name'], $covered, (string) $validated['platform']);
        } catch (RuntimeException $exception) {
            throw ValidationException::withMessages(['site_id' => 'Finish Setup › Hubs & networks first: '.$exception->getMessage()]);
        } catch (DomainException $exception) {
            throw ValidationException::withMessages(['site_id' => $exception->getMessage()]);
        }

        return response()->json($this->codePayload($issued['code'], $issued['plain'], $settings), 201, $this->noStore());
    }

    /** Pop-up 23, "Install Oblivion on a computer": a download for this Site, staff (Obli only) or full (IT). */
    public function issueComputer(Request $request, HubInstallationSettings $settings): JsonResponse
    {
        $actor = $this->actor($request, 'securityDevices.commands.admin');
        $validated = $request->validate([
            'site_id' => ['required', 'integer', 'min:1'],
            'install_mode' => ['required', 'in:staff,full'],
        ]);
        $this->access->assertCanViewSite($actor, (int) $validated['site_id']);
        try {
            $issued = $this->codes->issueComputer($actor, $this->operationalSite((int) $validated['site_id']), (string) $validated['install_mode']);
        } catch (RuntimeException|DomainException $exception) {
            throw ValidationException::withMessages(['site_id' => $exception->getMessage()]);
        }

        return response()->json([
            ...$this->codePayload($issued['code'], $issued['plain'], $settings),
            'install_mode' => $issued['code']->install_mode,
        ], 201, $this->noStore());
    }

    /** The installer, named for this Site with its code, e.g. "Oblivion-Setup (Tōtara House) [oblivionfindings.com T6WD-3KQN].exe". */
    public function download(Request $request, SetupCode $setupCode): Response
    {
        $actor = $this->actor($request, $setupCode->role === 'computer' ? 'securityDevices.commands.admin' : 'securityDevices.integrations.manage');
        $this->assertOwnSiteCode($actor, $setupCode);
        abort_unless($setupCode->platform === 'windows', 404);
        $plain = $this->codes->plainCode($setupCode);
        abort_if($plain === null, 410, 'This setup code can no longer be used. Make a new download.');

        try {
            $catalogue = EndpointReleaseCatalogue::configured();
            $artifact = collect($catalogue->catalogue()['artifacts'])
                ->filter(fn (array $item): bool => $item['platform'] === 'windows' && str_ends_with($item['filename'], '.exe')
                    && in_array($setupCode->role === 'hub' ? 'site' : 'computer', $item['roles'], true))
                ->sortByDesc('release_revision')
                ->first();
            abort_if($artifact === null, 409, 'No Windows installer has been published yet.');
            $download = $catalogue->download((string) $artifact['id'], (string) $artifact['sha256']);
        } catch (EndpointReleaseUnavailable) {
            abort(409, 'No Windows installer has been published yet.');
        }
        AuditLogger::logOrFail('security_devices.setup_code.downloaded', $setupCode, ['actor_id' => (int) $actor->id, 'site_id' => (int) $setupCode->site_id]);
        $stream = $download['stream'];

        return response()->streamDownload(function () use ($stream): void {
            try {
                fpassthru($stream);
            } finally {
                fclose($stream);
            }
        }, $this->codes->installerFileName($setupCode, $plain), [
            'Content-Type' => 'application/vnd.microsoft.portable-executable',
            'Content-Length' => (string) $download['artifact']['size'],
            'X-Content-Type-Options' => 'nosniff',
            ...$this->noStore(),
        ]);
    }

    /** Add a hub, step 4: waits live for the hub, then asks for approval. */
    public function status(Request $request, SetupCode $setupCode): JsonResponse
    {
        $actor = $this->actor($request, 'securityDevices.integrations.manage');
        $this->assertOwnSiteCode($actor, $setupCode);
        $collector = $setupCode->enrollment?->consumedCollector;

        return response()->json([
            'setup_code_id' => (int) $setupCode->id,
            'code_state' => $setupCode->state(),
            'expires_at' => $setupCode->expires_at->toIso8601String(),
            'hub' => $collector === null ? null : $this->hubPayload($collector),
        ], 200, $this->noStore());
    }

    public function cancel(Request $request, SetupCode $setupCode): JsonResponse
    {
        $actor = $this->actor($request, 'securityDevices.integrations.manage');
        $this->assertOwnSiteCode($actor, $setupCode);
        $code = $this->codes->cancel($setupCode, $actor);

        return response()->json(['setup_code_id' => (int) $code->id, 'code_state' => $code->state()]);
    }

    /** Pop-up 79: "Approve Kauri House hub?" */
    public function approve(Request $request, MonitoringCollector $collector, HubApprovalService $approvals): JsonResponse
    {
        $actor = $this->actor($request, 'securityDevices.hubs.approve');
        try {
            $hub = $approvals->approve($collector, $actor);
        } catch (DomainException $exception) {
            throw ValidationException::withMessages(['hub' => $exception->getMessage()]);
        }

        return response()->json(['hub' => $this->hubPayload($hub)]);
    }

    /** Pop-up 14: "Remove Head office hub?" Its setup key stops at once and its logins are wiped. */
    public function remove(Request $request, MonitoringCollector $collector, HubApprovalService $approvals, CollectorEnrollmentService $enrollments): JsonResponse
    {
        $actor = $this->actor($request, 'securityDevices.hubs.approve');
        $approvals->assertMayManage($actor, $collector);
        $hub = $enrollments->revoke($collector, (int) $actor->id);

        return response()->json(['hub' => $this->hubPayload($hub)]);
    }

    /** Pop-up 74: name, covered Sites, update ring and night window. */
    public function update(Request $request, MonitoringCollector $collector, CollectorEnrollmentService $enrollments): JsonResponse
    {
        $actor = $this->actor($request, 'securityDevices.integrations.manage');
        foreach ($collector->authorisedSiteIds() as $siteId) {
            $this->access->assertCanViewSite($actor, (int) $siteId);
        }
        $validated = $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:120', 'not_regex:/[\x00-\x1F\x7F]/'],
            'covered_site_ids' => ['sometimes', 'array', 'max:64'],
            'covered_site_ids.*' => ['integer', 'min:1'],
            'update_ring' => ['required', 'in:'.implode(',', HubUpdatePolicy::RINGS)],
            'update_window_start' => ['required', 'regex:/\A([01]\d|2[0-3]):[0-5]\d\z/'],
            'update_window_end' => ['required', 'regex:/\A([01]\d|2[0-3]):[0-5]\d\z/', 'different:update_window_start'],
        ]);
        $covered = array_values(array_unique(array_map('intval', $validated['covered_site_ids'] ?? [])));
        foreach ($covered as $siteId) {
            $this->access->assertCanViewSite($actor, $siteId);
        }
        try {
            $hub = $enrollments->updateSettings($collector, (int) $actor->id, (string) $validated['name'], $covered);
        } catch (DomainException $exception) {
            throw ValidationException::withMessages(['name' => $exception->getMessage()]);
        }
        $before = $hub->only(['update_ring', 'update_window_start', 'update_window_end']);
        $hub->forceFill([
            'update_ring' => $validated['update_ring'],
            'update_window_start' => $validated['update_window_start'],
            'update_window_end' => $validated['update_window_end'],
        ])->save();
        if ($before !== $hub->only(['update_ring', 'update_window_start', 'update_window_end'])) {
            AuditLogger::logOrFail('security_devices.hub.updates_changed', $hub, [
                'actor_id' => (int) $actor->id, 'site_id' => (int) $hub->site_id, 'before' => $before,
                'after' => $hub->only(['update_ring', 'update_window_start', 'update_window_end']),
            ]);
        }

        return response()->json(['hub' => $this->hubPayload($hub->fresh())]);
    }

    /** Pop-up 73: one download for the new computer; the handover happens when it's approved. */
    public function replace(Request $request, MonitoringCollector $collector, HubApprovalService $approvals, HubInstallationSettings $settings): JsonResponse
    {
        $actor = $this->actor($request, 'securityDevices.hubs.approve');
        $approvals->assertMayManage($actor, $collector);
        abort_if($collector->revoked_at !== null, 409, 'This hub has already been removed.');
        $validated = $request->validate(['platform' => ['required', 'in:windows,linux']]);
        try {
            $issued = $this->codes->issueHub($actor, $this->operationalSite((int) $collector->site_id), (string) $collector->name, $collector->coveredSiteIds(), (string) $validated['platform'], $collector);
        } catch (RuntimeException|DomainException $exception) {
            throw ValidationException::withMessages(['platform' => $exception->getMessage()]);
        }

        return response()->json($this->codePayload($issued['code'], $issued['plain'], $settings), 201, $this->noStore());
    }

    /** D3: hold updates for every hub, or let them carry on. */
    public function hold(Request $request, HubUpdatePolicy $updates): JsonResponse
    {
        $actor = $this->actor($request, 'securityDevices.hubs.approve');
        $validated = $request->validate(['held' => ['required', 'boolean']]);
        $updates->setHold((bool) $validated['held'], $actor);

        return response()->json($updates->hold());
    }

    /** @return array<string, mixed> */
    private function codePayload(SetupCode $code, string $plain, HubInstallationSettings $settings): array
    {
        $host = $settings->serverHost();

        return [
            'setup_code_id' => (int) $code->id,
            'code' => $plain,
            'platform' => $code->platform,
            'expires_at' => $code->expires_at->toIso8601String(),
            'file_name' => $code->platform === 'windows' ? $this->codes->installerFileName($code, $plain) : null,
            'download_url' => $code->platform === 'windows' ? route('security-devices.hubs.setup-codes.download', $code) : null,
            'linux_command' => $code->platform === 'linux' ? "curl -fsSL https://{$host}/hub/install.sh | sudo bash -s -- {$plain}" : null,
        ];
    }

    /** @return array<string, mixed> */
    private function hubPayload(MonitoringCollector $hub): array
    {
        return [
            'id' => (int) $hub->id,
            'name' => (string) $hub->name,
            'site_id' => (int) $hub->site_id,
            'covered_site_ids' => $hub->coveredSiteIds(),
            'state' => match (true) {
                $hub->revoked_at !== null => 'removed',
                $hub->approved_at === null => 'waiting_for_approval',
                default => (string) $hub->status,
            },
            'software_version' => $hub->softwareVersion(),
            'last_heartbeat_at' => ($hub->last_heartbeat_at ?? $hub->last_seen_at)?->toIso8601String(),
            'approved_at' => $hub->approved_at?->toIso8601String(),
            'update_ring' => (string) $hub->update_ring,
            'update_window_start' => (string) $hub->update_window_start,
            'update_window_end' => (string) $hub->update_window_end,
            'update_state' => $hub->update_state,
        ];
    }

    private function assertOwnSiteCode(User $actor, SetupCode $code): void
    {
        $this->access->assertCanViewSite($actor, (int) $code->site_id);
    }

    private function operationalSite(int $siteId): Site
    {
        return Site::query()->whereKey($siteId)->where('is_active', true)
            ->where(fn ($query) => $query->whereNull('archived')->orWhere('archived', false))
            ->firstOrFail();
    }

    /** Same sign-in rules as the hub wizard, plus the permission this action needs. */
    private function actor(Request $request, string $permission): User
    {
        abort_unless($request->isSecure() || app()->environment(['local', 'testing']), 400);
        $actor = $request->user();
        abort_unless(
            $actor !== null
            && $actor->approved_at !== null
            && $actor->email_verified_at !== null
            && ! $actor->external_clinical_account
            && $actor->canDo('securityDevices.viewAny')
            && $actor->canDo($permission)
            && (! SecurityPolicy::forceTwoFactor() || $actor->two_factor_confirmed_at !== null),
            403,
        );
        foreach (['client', 'next_of_kin', 'external_clinician'] as $role) {
            abort_if($actor->role === $role || $actor->hasRole($role), 403);
        }

        return $actor;
    }

    /** @return array<string, string> */
    private function noStore(): array
    {
        return ['Cache-Control' => 'private, no-store, max-age=0', 'Pragma' => 'no-cache', 'X-Content-Type-Options' => 'nosniff'];
    }
}
