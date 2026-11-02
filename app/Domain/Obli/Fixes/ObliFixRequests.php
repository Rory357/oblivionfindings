<?php

namespace App\Domain\Obli\Fixes;

use App\Domain\Monitoring\Services\EffectiveMonitoringPolicyResolver;
use App\Domain\SecurityDevices\Management\Enums\CommandStatus;
use App\Domain\SecurityDevices\Management\Models\DeviceCommandRequest;
use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Services\SecurityDevicesAccessService;
use App\Domain\SecurityDevices\Setup\SecurityDevicesSettings;
use App\Models\User;
use App\Notifications\ObliFixNotification;
use App\Services\AuditLogger;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Throwable;

/**
 * S&D Phase I (M, pop-up 95): one-off requests to IT. Obli asks when a fix needs an administrator.
 *
 * Approving runs only through an existing governed path, and never from here:
 * - a fix from the list (a governed service action or restart) is approved once and IT is taken to
 *   that computer's existing governed form, where their own permissions, step-up confirmation, the
 *   computer's policy and the person's Allow all still apply; Obli watches for that command and
 *   tells the person how it went;
 * - anything else ("ask_it" commands) has no governed path, so approving tells the person IT will
 *   do it by hand, and nothing runs automatically.
 */
final class ObliFixRequests
{
    /**
     * Record a request (inside the engine's transaction) and tell IT after commit.
     *
     * @param  list<string>  $commands
     */
    public function create(object $session, string $kind, ?string $fixKey, string $what, array $commands): int
    {
        $id = (int) DB::table('obli_fix_requests')->insertGetId([
            'session_id' => $session->id, 'device_id' => $session->device_id, 'site_id' => $session->site_id, 'user_id' => $session->user_id,
            'kind' => $kind, 'fix_key' => $fixKey, 'what_encrypted' => Crypt::encryptString($what),
            'commands_encrypted' => Crypt::encryptString(json_encode(array_values($commands), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)),
            'state' => 'requested', 'asked_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        AuditLogger::logOrFail('obli.fix.request_asked', null, [
            'actor_id' => (int) $session->user_id, 'session' => $session->uuid, 'request_id' => $id, 'kind' => $kind,
            'fix_key' => $fixKey, 'device_id' => (int) $session->device_id, 'site_id' => (int) $session->site_id,
        ]);
        $deviceId = (int) $session->device_id;
        $siteId = (int) $session->site_id;
        DB::afterCommit(fn () => $this->tellIt($id, $deviceId, $siteId));

        return $id;
    }

    /** The open request for a session (requested, or approved and still being run). */
    public function open(int $sessionId): ?object
    {
        return DB::table('obli_fix_requests')->where('session_id', $sessionId)->whereIn('state', ['requested', 'approved'])
            ->whereNull('command_result')->orderByDesc('id')->lockForUpdate()->first();
    }

    public function cancelFor(int $sessionId, string $state = 'cancelled'): void
    {
        DB::table('obli_fix_requests')->where('session_id', $sessionId)->where('state', 'requested')
            ->update(['state' => $state, 'updated_at' => now()]);
    }

    public static function canDecide(User $viewer): bool
    {
        return $viewer->canDo(ObliFixList::MANAGE);
    }

    /**
     * Pop-up 95: the conversation, the exact commands and what approving would do.
     *
     * @return array<string, mixed>
     */
    public function panel(User $viewer, int $id): array
    {
        // The person's own words and Obli's commands: only for people who may see Obli's help.
        abort_unless(Screens\ObliHelpTab::canSee($viewer), 403);
        $request = DB::table('obli_fix_requests')->where('id', $id)->first();
        abort_unless($request !== null, 404);
        $device = $this->visibleDevice($viewer, (int) $request->device_id);
        $session = DB::table('obli_fix_sessions')->where('id', $request->session_id)->first();
        $asker = DB::table('users')->where('id', $request->user_id)->value('name');
        $decider = $request->decided_by_user_id ? DB::table('users')->where('id', $request->decided_by_user_id)->value('name') : null;
        $definition = $request->fix_key ? app(ObliFixList::class)->definition((string) $request->fix_key) : null;
        $commands = $this->commands($request, $definition);
        $live = $session !== null && in_array($session->state, ['waiting_it', 'fix_running'], true);

        return [
            'id' => (int) $request->id,
            'device' => ['id' => (int) $device->id, 'name' => (string) $device->name],
            'asker' => (string) ($asker ?? 'Someone who has left'),
            'asked_at' => CarbonImmutable::parse($request->asked_at)->toIso8601String(),
            'problem' => $session?->problem_encrypted ? Crypt::decryptString($session->problem_encrypted) : null,
            'problem_at' => $session ? CarbonImmutable::parse($session->created_at)->toIso8601String() : null,
            'what' => $request->what_encrypted ? Crypt::decryptString($request->what_encrypted) : null,
            'kind' => (string) $request->kind,
            'fix' => $definition ? ['key' => $definition['key'], 'label' => $definition['label']] : null,
            'commands' => $commands,
            'host' => $request->kind === 'fix' ? 'What will run · through the Oblivion service, from IT’s governed form' : 'What Obli asked IT to run by hand · written by Obli, not checked: read every line before running any of it',
            'state' => (string) $request->state,
            'route' => $request->route,
            'decided' => $request->decided_at ? ['by' => (string) ($decider ?? 'Someone who has left'), 'at' => CarbonImmutable::parse($request->decided_at)->toIso8601String()] : null,
            'result' => $request->command_result,
            'governed_href' => $request->kind === 'fix' && $definition ? $this->governedHref($device, $definition) : null,
            'session' => $session?->uuid,
            'can_decide' => self::canDecide($viewer) && $request->state === 'requested' && $live,
            'live' => $live,
        ];
    }

    /**
     * Approve once or decline. Approving a fix from the list returns the governed form to open;
     * approving anything else records that IT will do it by hand. Nothing runs from here.
     *
     * @return array{state: string, route: ?string, governed_href: ?string}
     */
    public function decide(User $viewer, int $id, string $decision, ?string $note): array
    {
        abort_unless(self::canDecide($viewer), 403);
        abort_unless(in_array($decision, ['approve', 'decline'], true), 422);
        $note = $note !== null ? trim(preg_replace('/\s+/u', ' ', $note) ?? '') : null;
        abort_if($note !== null && (mb_strlen($note) > 500 || preg_match('/[\p{Cc}\p{Cf}]/u', $note)), 422);

        $result = DB::transaction(function () use ($viewer, $id, $decision, $note): array {
            // Session before request, as the engine locks them.
            $candidate = DB::table('obli_fix_requests')->where('id', $id)->first();
            abort_unless($candidate !== null, 404);
            $session = DB::table('obli_fix_sessions')->where('id', $candidate->session_id)->lockForUpdate()->first();
            $request = DB::table('obli_fix_requests')->where('id', $id)->lockForUpdate()->first();
            $device = $this->visibleDevice($viewer, (int) $request->device_id);
            abort_unless($request->state === 'requested', 409, 'This request has already been answered.');
            // While IT decides the session's own clock stands still (Update 2 Nov): the request lasts WAIT_MINUTES from when Obli asked.
            $waitEnds = $session?->waiting_since ? CarbonImmutable::parse($session->waiting_since)->addMinutes(ObliFixSettings::WAIT_MINUTES) : null;
            if (! $session || $session->state !== 'waiting_it' || $waitEnds === null || $waitEnds->isPast()) {
                DB::table('obli_fix_requests')->where('id', $id)->update(['state' => 'expired', 'updated_at' => now()]);

                return ['expired' => true];
            }
            $definition = $request->fix_key ? app(ObliFixList::class)->definition((string) $request->fix_key) : null;
            $route = $decision === 'approve' ? ($request->kind === 'fix' && $definition ? 'governed' : 'by_hand') : null;
            DB::table('obli_fix_requests')->where('id', $id)->update([
                'state' => $decision === 'approve' ? 'approved' : 'declined', 'route' => $route,
                'decided_by_user_id' => $viewer->id, 'decided_at' => now(),
                'note_encrypted' => $note ? Crypt::encryptString($note) : null, 'updated_at' => now(),
            ]);
            AuditLogger::logOrFail($decision === 'approve' ? 'obli.fix.request_approved' : 'obli.fix.request_declined', null, [
                'actor_id' => (int) $viewer->id, 'request_id' => (int) $id, 'session' => $session->uuid, 'route' => $route,
                'fix_key' => $request->fix_key, 'device_id' => (int) $device->id, 'site_id' => (int) $request->site_id,
            ]);

            return ['state' => $decision === 'approve' ? 'approved' : 'declined', 'route' => $route, 'governed_href' => $route === 'governed' ? $this->governedHref($device, $definition) : null];
        });
        abort_if(isset($result['expired']), 409, 'Obli’s help session has ended, so this request has expired.');

        return $result;
    }

    /**
     * After IT approved a fix from the list: the governed command IT ran from the existing form for
     * it, if any. Read-only: the governed path owns the command.
     *
     * @return array{state: 'running'|'succeeded'|'failed', command_id: int}|null
     */
    public function governedProgress(object $request): ?array
    {
        if ($request->route !== 'governed' || ! $request->decided_at || ! $request->decided_by_user_id) {
            return null;
        }
        $definition = app(ObliFixList::class)->definition((string) $request->fix_key);
        if ($definition === null) {
            return null;
        }
        $capability = $definition['kind'] === 'service' ? 'endpoint.service.manage' : 'endpoint.computer.restart';
        $query = DeviceCommandRequest::query()->where('device_id', $request->device_id)->where('capability', $capability)
            ->where('requested_by_user_id', $request->decided_by_user_id)->where('created_at', '>=', $request->decided_at)->orderBy('id')->limit(10);
        if ($request->command_request_id) {
            $query = DeviceCommandRequest::query()->whereKey($request->command_request_id);
        }
        foreach ($query->get() as $command) {
            if ($definition['kind'] === 'service') {
                $parameters = is_array($command->encrypted_parameters) ? $command->encrypted_parameters : [];
                if (($parameters['service_name'] ?? null) !== $definition['service_name'] || ($parameters['operation'] ?? null) !== $definition['operation']) {
                    continue;
                }
            }
            $state = match ($command->status) {
                CommandStatus::Succeeded, CommandStatus::Reconciling, CommandStatus::Reconciled => 'succeeded',
                CommandStatus::Failed, CommandStatus::Rejected, CommandStatus::Expired, CommandStatus::Cancelled, CommandStatus::Blocked, CommandStatus::Mismatch, CommandStatus::Uncertain => 'failed',
                default => 'running',
            };

            return ['state' => $state, 'command_id' => (int) $command->id];
        }

        return null;
    }

    /** @param  array<string, mixed>  $definition */
    public function governedHref(Device $device, ?array $definition): ?string
    {
        if ($definition === null) {
            return null;
        }

        return $definition['kind'] === 'service'
            ? '/security-devices/devices/'.$device->id.'?section=management&action=endpoint.service.manage&command_service_name='.rawurlencode((string) $definition['service_name'])
            : '/security-devices/devices/'.$device->id.'?section=management&action=endpoint.computer.restart';
    }

    /** @return list<string> */
    private function commands(object $request, ?array $definition): array
    {
        if ($request->kind === 'fix' && $definition !== null) {
            return $definition['kind'] === 'service'
                ? ['# Through IT’s governed form; the person at the computer is asked first', 'SCM> '.$definition['operation'].' "'.$definition['service_name'].'"']
                : ['# Through IT’s governed form; the person at the computer is warned first', 'SCM> restart computer'];
        }
        try {
            $lines = json_decode(Crypt::decryptString((string) $request->commands_encrypted), true, 4, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return [];
        }

        return array_values(array_map(fn ($line): string => 'PS> '.$line, array_filter(is_array($lines) ? $lines : [], 'is_string')));
    }

    private function visibleDevice(User $viewer, int $deviceId): Device
    {
        $device = Device::query()->find($deviceId);
        abort_unless($device instanceof Device, 404);
        app(SecurityDevicesAccessService::class)->assertCanViewDevice($viewer, $device);

        return $device;
    }

    /** IT for that Site who may approve Obli's fixes, through the device-alert channels (app at least). */
    private function tellIt(int $requestId, int $deviceId, int $siteId): void
    {
        try {
            $settings = app(SecurityDevicesSettings::class);
            $people = app(EffectiveMonitoringPolicyResolver::class)->resolveItRecipients($siteId, $settings->role(SecurityDevicesSettings::DEVICES_ROLE))
                ->filter(fn (User $person): bool => $person->canDo(ObliFixList::MANAGE))->values();
            if ($people->isEmpty()) {
                return;
            }
            $channels = $settings->channels(SecurityDevicesSettings::DEVICES_APP, SecurityDevicesSettings::DEVICES_EMAIL, SecurityDevicesSettings::DEVICES_PUSH);
            $device = (string) (Device::query()->whereKey($deviceId)->value('name') ?? 'A computer');
            $site = (string) (DB::table('sites')->where('id', $siteId)->value('name') ?? 'a Site');
            Notification::send($people, new ObliFixNotification(
                'request', 'Obli asks you to approve a one-off fix', $device.' · '.$site,
                '/security-devices/devices/'.$deviceId.'?tab=obli&request='.$requestId,
                array_values(array_unique(['database', ...$channels])),
            ));
        } catch (Throwable $error) {
            Log::warning('Obli fix request notice deferred', ['request_id' => $requestId, 'error' => class_basename($error)]);
        }
    }
}
