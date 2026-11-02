<?php

namespace App\Domain\SecurityDevices\Credentials\Services;

use App\Domain\Monitoring\Services\CanonicalDeviceSiteResolver;
use App\Domain\SecurityDevices\Credentials\Enums\CredentialReferenceStatus;
use App\Domain\SecurityDevices\Credentials\Enums\CredentialTestStatus;
use App\Domain\SecurityDevices\Credentials\Models\CredentialReference;
use App\Domain\SecurityDevices\Hubs\Services\CoveringHubResolver;
use App\Domain\SecurityDevices\Management\Enums\CommandStatus;
use App\Domain\SecurityDevices\Management\Models\DeviceCommandAttempt;
use App\Domain\SecurityDevices\Management\Models\DeviceCommandRequest;
use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Screens\Setup\SetupLogins;
use App\Domain\SecurityDevices\Services\SecurityDevicesAccessService;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use UnexpectedValueException;

/**
 * Pop-up 63, "Update the saved login" (S&D Phase G): the login the hub uses to reach a device,
 * replaced from the device itself. It is the Site's saved login for the device's brand (one login
 * can serve several devices of that brand at the Site, and the pop-up says which).
 *
 * Replacing goes through today's path, CredentialReferenceManager: rotate() writes the new username
 * and password where logins are kept (encrypted in Oblivion, or your own vault) and pauses the login,
 * then test() checks it can be handed out again and turns it back on. Both are audited there.
 * It needs Setup's saved-logins key (securityDevices.savedLogins.manage) and the manager's own
 * check (securityDevices.commands.admin). Nothing secret is ever sent back: no password, no username,
 * no vault path.
 */
final class DeviceSavedLogin
{
    public const string FAILURE_WORDS = 'Someone may have changed the device’s password.';

    public function __construct(
        private readonly SecurityDevicesAccessService $access,
        private readonly CanonicalDeviceSiteResolver $sites,
        private readonly CredentialReferenceManager $manager,
        private readonly CoveringHubResolver $hubs,
    ) {}

    public static function canReplace(User $viewer): bool
    {
        return $viewer->canDo('securityDevices.savedLogins.manage') && $viewer->canDo('securityDevices.commands.admin');
    }

    /** The Site's saved login for the device's brand: the one its settings backup uses, or the only one. */
    public function reference(Device $device, int $siteId): ?CredentialReference
    {
        $provider = strtolower(trim((string) $device->provider));
        if ($provider === '' || $siteId < 1) {
            return null;
        }
        $candidates = CredentialReference::query()
            ->where('site_id', $siteId)
            ->where('provider', $provider)
            ->where('purpose', 'device_management')
            ->where('status', '!=', CredentialReferenceStatus::Revoked->value)
            ->whereNull('revoked_at')
            ->orderBy('id')
            ->get();
        $backup = $candidates->first(fn (CredentialReference $reference): bool => in_array('command:network.config.backup', $reference->capabilities ?? [], true));

        return $backup ?? ($candidates->count() === 1 ? $candidates->first() : null);
    }

    /**
     * For the device page and the pop-up: which login, whether it last failed and who else uses it.
     * Null when the viewer can't replace logins or the device has no saved login.
     *
     * @return array<string, mixed>|null
     */
    public function present(User $viewer, Device $device, ?int $siteId): ?array
    {
        if ($siteId === null || ! self::canReplace($viewer)) {
            return null;
        }
        $reference = $this->reference($device, $siteId);
        if (! $reference instanceof CredentialReference) {
            return null;
        }
        $failedAt = $this->failedAt($device, $reference);
        $others = $this->access->visibleDevicesForSite($viewer, $siteId)
            ->where('provider', $reference->provider)
            ->whereKeyNot($device->id)
            ->orderBy('name')
            ->limit(6)
            ->pluck('name');
        $othersCount = $this->access->visibleDevicesForSite($viewer, $siteId)
            ->where('provider', $reference->provider)
            ->whereKeyNot($device->id)
            ->count();
        $hub = $this->hubs->forSite($siteId);

        return [
            'id' => (int) $reference->id,
            'name' => SetupLogins::brand((string) $reference->provider).' login',
            'state' => match (true) {
                $failedAt !== null => ['tone' => 'critical', 'text' => 'Login failed'],
                $reference->status === CredentialReferenceStatus::Suspended => ['tone' => 'warning', 'text' => 'Paused'],
                $reference->test_status === CredentialTestStatus::Passed => ['tone' => 'success', 'text' => 'Working'],
                default => ['tone' => 'neutral', 'text' => 'Not tested yet'],
            },
            'failed_at' => $failedAt?->toIso8601String(),
            'used_by' => $hub !== null ? CoveringHubResolver::label($hub) : 'the Oblivion server',
            'shared_with' => ['count' => $othersCount, 'names' => $others->values()->all()],
            'store' => (string) config('monitoring.credentials.driver', 'database') === 'vault' ? 'in your HashiCorp Vault' : 'encrypted in Oblivion',
            'url' => route('security-devices.devices.saved-login.update', ['device' => $device->id], false),
        ];
    }

    /**
     * Replace the login with a new username and password. Returns only what happened.
     *
     * @return array{state: 'working'|'paused', message: string}
     */
    public function replace(User $viewer, Device $device, #[\SensitiveParameter] string $username, #[\SensitiveParameter] string $password): array
    {
        $this->access->assertCanViewDevice($viewer, $device);
        abort_unless(self::canReplace($viewer), 403);
        try {
            $siteId = $this->sites->resolve((int) $device->id);
        } catch (UnexpectedValueException) {
            abort(404);
        }
        $reference = $this->reference($device, $siteId);
        if (! $reference instanceof CredentialReference) {
            throw ValidationException::withMessages([
                'login' => 'This device has no saved login to update. Add one in Setup › Services & logins.',
            ]);
        }

        try {
            $rotated = $this->manager->rotate($viewer, $reference, '', ['username' => $username, 'password' => $password]);
        } catch (RuntimeException|\InvalidArgumentException) {
            throw ValidationException::withMessages([
                'login' => 'The new login couldn’t be saved. Nothing was changed; try again.',
            ]);
        }
        try {
            $this->manager->test($viewer, $rotated);
        } catch (RuntimeException) {
            return [
                'state' => 'paused',
                'message' => 'Saved, but Oblivion couldn’t read it back from where logins are kept, so it’s paused. Check Setup › Services & logins.',
            ];
        }

        return ['state' => 'working', 'message' => 'Login saved. The hub uses it from its next job.'];
    }

    /** When the device last refused this login: its settings backup said so, or the login's own test failed. */
    private function failedAt(Device $device, CredentialReference $reference): ?CarbonInterface
    {
        $request = DeviceCommandRequest::query()
            ->where('device_id', $device->id)
            ->where('capability', 'network.config.backup')
            ->latest('id')
            ->first(['id', 'status', 'updated_at']);
        if ($request !== null && in_array($request->status, [CommandStatus::Failed, CommandStatus::Mismatch], true)) {
            $attempt = DeviceCommandAttempt::query()
                ->where('device_command_request_id', $request->id)
                ->latest('id')
                ->first(['id', 'safe_result_summary', 'completed_at', 'updated_at']);
            $code = is_array($attempt?->safe_result_summary) ? ($attempt->safe_result_summary['reason_code'] ?? null) : null;
            if (in_array($code, ['invalid_credentials', 'auth_failed'], true)
                && ($reference->last_rotated_at === null || ($attempt->completed_at ?? $request->updated_at)?->gt($reference->last_rotated_at))) {
                return $attempt->completed_at ?? $attempt->updated_at ?? $request->updated_at;
            }
        }

        return $reference->test_status === CredentialTestStatus::Failed ? $reference->last_tested_at : null;
    }
}
