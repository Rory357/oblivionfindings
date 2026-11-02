<?php

namespace App\Domain\SecurityDevices\NetworkChecks;

use App\Domain\It\Enums\ItTicketCommandChannel;
use App\Domain\It\Exceptions\ItTicketCommandConflict;
use App\Domain\It\Services\ItTicketIntakeService;
use App\Domain\Monitoring\Contracts\CredentialLeaseProvider;
use App\Domain\Monitoring\Data\CredentialLease;
use App\Domain\Monitoring\Data\ProbeTarget;
use App\Domain\Monitoring\Models\Monitor;
use App\Domain\Monitoring\Models\MonitoringCollector;
use App\Domain\Monitoring\Services\CollectorCredentialLeaseSealer;
use App\Domain\Monitoring\Services\EgressPolicy;
use App\Domain\Obli\ObliAiSettings;
use App\Domain\SecurityDevices\Cctv\Services\CameraEndpointTrustService;
use App\Domain\SecurityDevices\Credentials\Models\CredentialReference;
use App\Domain\SecurityDevices\Hubs\Services\CoveringHubResolver;
use App\Domain\SecurityDevices\Management\Services\HubJobTypes;
use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\NetworkChecks\Jobs\AdvanceNetworkCheck;
use App\Domain\SecurityDevices\NetworkChecks\Models\NetworkCheckSession;
use App\Domain\SecurityDevices\NetworkChecks\Models\NetworkCheckStep;
use App\Domain\SecurityDevices\Screens\DeviceKind;
use App\Domain\SecurityDevices\Screens\Devices\DeviceDirectory;
use App\Domain\SecurityDevices\Screens\Devices\DeviceEntry;
use App\Domain\SecurityDevices\Screens\Devices\DeviceUpdates;
use App\Domain\SecurityDevices\Services\SecurityDevicesAccessService;
use App\Models\AppSetting;
use App\Models\Site;
use App\Models\User;
use App\Services\AuditLogger;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

/**
 * S&D Phase I (pop-up 97): "Obli checks <Site>'s network".
 *
 * IT asks a question about a Site's network. Obli (the AI, on the server only) picks up to six
 * checks from a fixed read-only list; the hub that watches the Site runs the live ones, against
 * devices Oblivion already knows there and inside the hub's allowed networks; Obli then explains
 * what was found. Nobody needs to be signed in at the Site.
 *
 * - Read-only: the checks only look. The hub keeps the same list and refuses anything else.
 * - Saved logins (the Site's UniFi login) reach the hub only as sealed leases, and only for a
 *   console whose certificate Oblivion already trusts. They're never shown or logged.
 * - Honest: each check's finding is written here from what really came back, and a check that
 *   couldn't be done says "Not available: <why>". The AI is told to use only those numbers, and an
 *   answer with a number that isn't in the results is marked.
 * - Limits: one running session per Site, six checks a question, each check bounded on the hub
 *   (20 seconds, a small answer), Obli's spending limits and off switch (Settings › Obli).
 * - Kept: each session is the Site's record (who asked, when, through which hub, what was found)
 *   for a year, and goes on the IT job when one is raised.
 */
final class NetworkChecks
{
    public const string HUB_FEATURE = 'network_checks_v1';

    public const int MAX_CHECKS = 6;

    public const int KEEP_DAYS = 365;

    /** A hub check not answered by then is "Not available". */
    public const int STEP_SECONDS = 180;

    /** A session still open after this ends, whatever it was waiting for. */
    public const int SESSION_MINUTES = 10;

    /** A sent check with no answer after this is offered again (re-running a read is harmless). */
    private const int RESEND_AFTER_SECONDS = 45;

    private const array NETWORK_KINDS = [DeviceKind::Router, DeviceKind::Switch, DeviceKind::WifiPoint, DeviceKind::NetworkDevice];

    private const array STATE_BADGE = [
        'planning' => ['label' => 'Planning', 'tone' => 'info'],
        'checking' => ['label' => 'Checking', 'tone' => 'info'],
        'answering' => ['label' => 'Writing up', 'tone' => 'info'],
        'answered' => ['label' => 'Answered', 'tone' => 'success'],
        'failed' => ['label' => 'Not finished', 'tone' => 'warning'],
    ];

    public function __construct(
        private readonly SecurityDevicesAccessService $access,
        private readonly DeviceDirectory $directory,
        private readonly CoveringHubResolver $hubs,
        private readonly EgressPolicy $egress,
        private readonly CameraEndpointTrustService $trust,
        private readonly CollectorCredentialLeaseSealer $sealer,
        private readonly CredentialLeaseProvider $leases,
        private readonly ObliAiSettings $ai,
        private readonly NetworkCheckProvider $provider,
        private readonly NetworkCheckStoredData $stored,
    ) {}

    /** IT: the people who may run scans and checks through a hub ("Scan now"). */
    public static function mayAsk(User $viewer): bool
    {
        return $viewer->canDo('securityDevices.devices.view') && $viewer->canDo('securityDevices.integrations.manage');
    }

    /* ------------------------------------------------------------------ */
    /*  Asking                                                             */
    /* ------------------------------------------------------------------ */

    /**
     * Whether Obli can check this Site's network now, and why not, in plain words.
     *
     * @return array{ok: bool, reason: ?string, hub: ?string}
     */
    public function readiness(int $siteId, string $siteName): array
    {
        $hub = $this->hubs->forSite($siteId);
        $label = $hub instanceof MonitoringCollector ? CoveringHubResolver::label($hub) : null;
        $reason = $this->aiReason($this->ai->current()) ?? match (true) {
            ! $hub instanceof MonitoringCollector => "Not available: no hub watches {$siteName}, so Obli can’t check its network.",
            ! in_array((string) $hub->status, ['online', 'degraded'], true) => "Not available: {$label} isn’t connected right now.",
            ! $hub->isApprovedForWork() => "Not available: {$label} is waiting to be approved.",
            ! HubJobTypes::runs($hub, self::HUB_FEATURE) => "Not available: {$label} needs its software update before Obli can run network checks through it.",
            ! in_array($siteId, $hub->authorisedSiteIds(), true) => "Not available: {$label} isn’t allowed to check {$siteName}’s networks.",
            default => null,
        };

        return ['ok' => $reason === null, 'reason' => $reason, 'hub' => $label];
    }

    /**
     * The pop-up's data: whether it can ask, and the running session (or the one asked for).
     *
     * @return array<string, mixed>
     */
    public function panel(User $viewer, Site $site, ?string $uuid): array
    {
        abort_unless(self::mayAsk($viewer), 403);
        $this->access->assertCanViewSite($viewer, (int) $site->id);
        $session = $uuid !== null
            ? NetworkCheckSession::query()->where('uuid', strtolower($uuid))->where('site_id', $site->id)->first()
            : NetworkCheckSession::query()->where('open_site_id', $site->id)->first();
        abort_if($uuid !== null && $session === null, 404);

        return [
            'site' => ['id' => (int) $site->id, 'name' => (string) $site->name],
            'ready' => $this->readiness((int) $site->id, (string) $site->name),
            'session' => $session ? $this->present($session, $viewer) : null,
        ];
    }

    public function ask(User $actor, Site $site, string $question, ?int $deviceId): NetworkCheckSession
    {
        abort_unless(self::mayAsk($actor), 403);
        $siteId = (int) $site->id;
        $this->access->assertCanViewSite($actor, $siteId);
        $question = trim(str_replace('"""', '"', $question));
        if (mb_strlen($question) < 10 || mb_strlen($question) > 1000 || ! mb_check_encoding($question, 'UTF-8') || preg_match('/(?![\t\r\n])\p{Cc}/u', $question) === 1) {
            throw ValidationException::withMessages(['question' => 'Say what’s wrong in 10 to 1,000 characters.']);
        }
        if ($deviceId !== null) {
            abort_unless($this->entries($actor, $siteId)->contains(fn (DeviceEntry $entry): bool => $entry->id() === $deviceId), 404);
        }
        $ready = $this->readiness($siteId, (string) $site->name);
        if (! $ready['ok']) {
            throw ValidationException::withMessages(['question' => (string) $ready['reason']]);
        }
        $hub = $this->hubs->forSite($siteId) ?? throw new RuntimeException('No hub covers this Site.');
        try {
            $session = DB::transaction(function () use ($actor, $siteId, $hub, $question, $deviceId): NetworkCheckSession {
                $session = NetworkCheckSession::query()->create([
                    'uuid' => (string) Str::uuid(),
                    'site_id' => $siteId,
                    'open_site_id' => $siteId,
                    'collector_id' => $hub->id,
                    'hub_label' => mb_substr(CoveringHubResolver::label($hub), 0, 191),
                    'device_id' => $deviceId,
                    'user_id' => $actor->id,
                    'asked_by' => mb_substr((string) $actor->name, 0, 191),
                    'question' => $question,
                    'state' => 'planning',
                    'expires_at' => now()->addDays(self::KEEP_DAYS),
                ]);
                AuditLogger::logOrFail('security_devices.network_check.asked', $session, [
                    'actor_id' => (int) $actor->id, 'site_id' => $siteId, 'collector_id' => (int) $hub->id, 'device_id' => $deviceId,
                ]);

                return $session;
            });
        } catch (UniqueConstraintViolationException) {
            $running = NetworkCheckSession::query()->where('open_site_id', $siteId)->first();
            throw ValidationException::withMessages(['question' => 'Obli is already checking '.$site->name.'’s network'
                .($running ? ' for '.$running->asked_by : '').'. One check at a time per Site; try again when it’s answered.']);
        }
        AdvanceNetworkCheck::dispatch((int) $session->id)->afterCommit();

        return $session;
    }

    public function find(User $viewer, string $uuid): NetworkCheckSession
    {
        abort_unless(self::mayAsk($viewer), 403);
        $session = NetworkCheckSession::query()->where('uuid', strtolower($uuid))->firstOrFail();
        $this->access->assertCanViewSite($viewer, (int) $session->site_id);

        return $session;
    }

    /* ------------------------------------------------------------------ */
    /*  Obli's turns (queued job and the minute tick)                      */
    /* ------------------------------------------------------------------ */

    /** Take the session's next step: plan, wait for the hub, or write the answer. */
    public function advance(int $sessionId): void
    {
        $session = DB::transaction(function () use ($sessionId): ?NetworkCheckSession {
            $session = NetworkCheckSession::query()->whereKey($sessionId)->lockForUpdate()->first();
            if (! $session instanceof NetworkCheckSession || ! $session->open() || ($session->lease_until !== null && $session->lease_until->isFuture())) {
                return null;
            }
            $session->forceFill(['lease_until' => now()->addSeconds(150)])->save();

            return $session;
        });
        if ($session === null) {
            return;
        }
        try {
            if ($session->created_at !== null && $session->created_at->lte(now()->subMinutes(self::SESSION_MINUTES))) {
                $this->end($session, 'failed', 'Obli didn’t finish within '.self::SESSION_MINUTES.' minutes. What it checked is below.');

                return;
            }
            if ($session->state === 'planning') {
                $this->plan($session);
            }
            $session->refresh();
            if ($session->state === 'checking' && $session->steps()->whereIn('state', ['queued', 'sent'])->doesntExist()) {
                $session->forceFill(['state' => 'answering'])->save();
            }
            if ($session->state === 'answering') {
                $this->answer($session);
            }
        } finally {
            NetworkCheckSession::query()->whereKey($sessionId)->update(['lease_until' => null]);
        }
    }

    private function plan(NetworkCheckSession $session): void
    {
        $actor = $session->user;
        $site = $session->site;
        $hub = MonitoringCollector::query()->find($session->collector_id);
        if (! $actor instanceof User || ! $site instanceof Site || ! $hub instanceof MonitoringCollector) {
            $this->end($session, 'failed', 'The hub or the person who asked is no longer in Oblivion, so nothing was checked.');

            return;
        }
        $entries = $this->entries($actor, (int) $site->id);
        $network = $this->networkEntries($entries, $session->device_id);
        $unifi = $this->unifi((int) $site->id, (string) $site->name, $hub, $entries);
        $data = [
            'site' => (string) $site->name,
            'hub' => (string) $session->hub_label,
            'hub_is_at_this_site' => (int) $hub->site_id === (int) $site->id,
            'asked_from_device_id' => $session->device_id,
            'devices' => $network->map(fn (DeviceEntry $entry): array => [
                'id' => $entry->id(),
                'name' => $entry->name(),
                'kind' => $entry->kind->value,
                'state' => $entry->state->value,
                'delay_check' => $this->delayReason($entry, $hub) ?? 'available',
            ])->values()->all(),
            'checks' => [
                'delay' => 'Live: the hub measures the delay and lost replies to one device (10 tries). Per device: see delay_check.',
                'gateway_speed' => $unifi['why'] === null ? 'Live: the gateway’s own last speed test, from the UniFi console. Available.' : 'Not available: '.$unifi['why'].'.',
                'switch_ports' => $unifi['why'] === null ? 'Live: each port on one switch: up or down, speed, errors. Switches only. Available.' : 'Not available: '.$unifi['why'].'.',
                'wifi' => $unifi['why'] === null ? 'Live: one Wi-Fi point: devices on it now, weak signals, how busy its radios are, and drop-outs in the last 72 hours if the console keeps them. Wi-Fi points only. Available.' : 'Not available: '.$unifi['why'].'.',
                'stored_outages' => $entries->contains(fn (DeviceEntry $entry): bool => NetworkCheckStoredData::isGateway($entry))
                    ? 'Stored: when the Site’s router dropped out in the last 14 days. Available.'
                    : 'Not available: Oblivion doesn’t know this Site’s router.',
                'stored_map' => 'Stored: what a device is linked to on the Site’s network map (or the map’s links when device_id is null). Available.',
                'stored_checks' => 'Stored: how one device answered Oblivion’s own checks in the last 24 hours. Available.',
            ],
        ];
        $result = $this->callAi($session, NetworkCheckInstructions::planMessage((string) $session->question, $data), NetworkCheckInstructions::planTool());
        if (is_string($result)) {
            $this->end($session, 'failed', $result);

            return;
        }
        $input = $result['input'];
        $lines = array_values(array_filter(array_map(fn (mixed $line): ?string => $this->words($line, 140), is_array($input['plan'] ?? null) ? array_slice($input['plan'], 0, 4) : [])));
        $checks = [];
        $byId = $network->keyBy(fn (DeviceEntry $entry): int => $entry->id());
        foreach (is_array($input['checks'] ?? null) ? array_slice($input['checks'], 0, self::MAX_CHECKS) : [] as $check) {
            $kind = is_array($check) ? ($check['kind'] ?? null) : null;
            $deviceId = is_array($check) && is_int($check['device_id'] ?? null) ? $check['device_id'] : null;
            $rule = is_string($kind) ? (NetworkCheckKinds::KINDS[$kind]['target'] ?? null) : null;
            $target = $deviceId !== null ? $byId->get($deviceId) : null;
            $ok = match ($rule) {
                'none' => true,
                'optional' => $deviceId === null || $target !== null,
                'any' => $target !== null,
                'switch' => $target?->kind === DeviceKind::Switch,
                'wifi_point' => $target?->kind === DeviceKind::WifiPoint,
                default => false,
            };
            $key = $kind.'|'.($rule === 'none' ? '' : (string) $deviceId);
            if ($ok && ! isset($checks[$key])) {
                $checks[$key] = ['kind' => $kind, 'target' => $rule === 'none' ? null : $target];
            }
        }
        $reply = $this->words($input['reply'] ?? null, 600);
        if ($checks === []) {
            $reply !== null
                ? $this->finishAnswered($session, $reply, null, false, false, ['lines' => [], 'intro' => null])
                : $this->end($session, 'failed', 'Obli couldn’t find a check that fits this question. Try saying what isn’t working, and where.');

            return;
        }

        $now = CarbonImmutable::now('UTC');
        $hubAtSite = (int) $hub->site_id === (int) $site->id;
        // The plan is paid for now: if saving its steps fails, the session ends rather than the
        // minute tick asking the AI (and charging the budget) again.
        try {
        DB::transaction(function () use ($session, $checks, $lines, $hub, $site, $entries, $unifi, $now, $hubAtSite): void {
            $position = 0;
            foreach ($checks as $check) {
                $kind = (string) $check['kind'];
                /** @var DeviceEntry|null $target */
                $target = $check['target'];
                $step = [
                    'uuid' => (string) Str::uuid(),
                    'session_id' => $session->id,
                    'position' => ++$position,
                    'kind' => $kind,
                    'source' => NetworkCheckKinds::KINDS[$kind]['source'],
                    'target_device_id' => $target?->id(),
                    'title' => mb_substr(NetworkCheckKinds::title($kind, $target?->name(), (string) $session->hub_label), 0, 191),
                    'expires_at' => $now->addSeconds(self::STEP_SECONDS),
                ];
                if (! NetworkCheckKinds::isHub($kind)) {
                    $result = $this->storedResult($kind, $target, $entries, $now);
                    NetworkCheckStep::query()->create($step + [
                        'state' => 'done', 'result' => $result, 'finding' => mb_substr(NetworkCheckKinds::finding($kind, $result), 0, 500), 'done_at' => $now,
                    ]);

                    continue;
                }
                $prepared = $this->hubParams($kind, $target, $hub, $unifi);
                NetworkCheckStep::query()->create($step + (is_string($prepared)
                    ? ['state' => 'not_available', 'finding' => mb_substr('Not available: '.$prepared, 0, 500), 'done_at' => $now]
                    : ['state' => 'queued', 'params' => $prepared, 'collector_id' => $hub->id]));
            }
            $session->forceFill([
                'plan' => [
                    'title' => $this->words($session->question, 60, true),
                    'lines' => $lines,
                    'intro' => $hubAtSite
                        ? "I’ll check through {$session->hub_label}, the hub at {$site->name}. These checks only look; I won’t change anything."
                        : "I’ll check through {$session->hub_label}, which watches {$site->name}. These checks only look; I won’t change anything.",
                ],
                'state' => 'checking',
                'planned_at' => $now,
            ])->save();
        });
        } catch (\Throwable $error) {
            report($error);
            $this->end($session->fresh() ?? $session, 'failed', 'Obli planned the checks but they couldn’t be started. Nothing was checked; try again.');
        }
    }

    private function answer(NetworkCheckSession $session): void
    {
        $actor = $session->user;
        $site = $session->site;
        $steps = $session->steps()->get();
        $entries = $actor instanceof User && $site instanceof Site ? $this->entries($actor, (int) $site->id) : collect();
        $network = $this->networkEntries($entries, $session->device_id);
        $names = $network->mapWithKeys(fn (DeviceEntry $entry): array => [$entry->id() => $entry->name()]);
        $data = [
            'site' => (string) ($site?->name ?? ''),
            'hub' => (string) $session->hub_label,
            'hub_is_at_this_site' => $site !== null && (int) MonitoringCollector::query()->whereKey($session->collector_id)->value('site_id') === (int) $site->id,
            'plan' => (array) (($session->plan ?? [])['lines'] ?? []),
            'devices' => $network->map(fn (DeviceEntry $entry): array => ['id' => $entry->id(), 'name' => $entry->name(), 'kind' => $entry->kind->value, 'state' => $entry->state->value])->values()->all(),
            'results' => $steps->map(fn (NetworkCheckStep $step): array => [
                'check' => $step->title,
                'kind' => $step->kind,
                'device_id' => $step->target_device_id,
                'outcome' => $step->state === 'done' ? 'done' : 'Not available',
                'finding' => $step->finding,
                'data' => $step->state === 'done' ? $step->result : null,
            ])->values()->all(),
        ];
        $result = $this->callAi($session, NetworkCheckInstructions::answerMessage((string) $session->question, $data), NetworkCheckInstructions::answerTool());
        if (is_string($result)) {
            $this->end($session, 'failed', $result === self::AI_FAILED_PLAN ? self::AI_FAILED_ANSWER : $result);

            return;
        }
        $answer = $this->words($result['input']['answer'] ?? null, 1500, false, true);
        if ($answer === null) {
            $this->end($session, 'failed', self::AI_FAILED_ANSWER);

            return;
        }
        $focus = is_int($result['input']['focus_device_id'] ?? null) && $names->has($result['input']['focus_device_id']) ? (int) $result['input']['focus_device_id'] : null;
        $suggestUnifi = ($result['input']['suggest_unifi'] ?? false) === true && $site instanceof Site && $this->unifiReference((int) $site->id) !== null;
        $known = $this->knownNumbers($session, $steps);
        $this->finishAnswered($session, $answer, $focus, $suggestUnifi, $this->hasUnknownNumber($answer, $known), $session->plan ?? []);
    }

    private const string AI_FAILED_PLAN = 'Obli couldn’t plan the checks just now. Try again in a few minutes.';

    private const string AI_FAILED_ANSWER = 'Obli ran the checks but couldn’t write up what it found. What it checked is below.';

    /**
     * One AI call, inside Obli's spending limits. Returns the tool input, or why not (plain words).
     *
     * @param  array{name: string, description: string, schema: array<string, mixed>}  $tool
     * @return array{input: array<string, mixed>}|string
     */
    private function callAi(NetworkCheckSession $session, string $message, array $tool): array|string
    {
        try {
            $prepared = DB::transaction(function () use ($message, $tool): array|string {
                $policy = $this->ai->current(true);
                $off = $this->aiReason($policy);
                if ($off !== null) {
                    return $off;
                }
                $bytes = strlen(NetworkCheckInstructions::SYSTEM) + strlen($message) + strlen(json_encode($tool, JSON_THROW_ON_ERROR)) + 1024;
                $inputRate = ObliAiSettings::micros((string) $policy['input_price_usd']);
                $outputRate = ObliAiSettings::micros((string) $policy['output_price_usd']);
                $reserve = ObliAiSettings::cost($bytes, (int) $policy['max_output_tokens'], $inputRate, $outputRate);
                $periods = ['day:'.now('UTC')->format('Y-m-d'), 'month:'.now('UTC')->format('Y-m')];
                $limits = [ObliAiSettings::micros((string) $policy['daily_budget_usd']), ObliAiSettings::micros((string) $policy['monthly_budget_usd'])];
                foreach ($periods as $index => $period) {
                    DB::table('obli_ai_budget_periods')->insertOrIgnore(['period' => $period, 'committed_micros' => 0]);
                    $budget = DB::table('obli_ai_budget_periods')->where('period', $period)->lockForUpdate()->first();
                    if ((int) $budget->committed_micros + $reserve > $limits[$index]) {
                        return 'Not available: Obli has reached its spending limit (Settings › Obli). '.($index === 0 ? 'Try again tomorrow.' : 'Try again next month.');
                    }
                }
                foreach ($periods as $period) {
                    DB::table('obli_ai_budget_periods')->where('period', $period)->increment('committed_micros', $reserve);
                }

                return [
                    'reserve' => $reserve, 'periods' => $periods, 'input_rate' => $inputRate, 'output_rate' => $outputRate,
                    'configuration' => [
                        'provider' => (string) $policy['provider'], 'model' => (string) $policy['model'],
                        'max_output_tokens' => (int) $policy['max_output_tokens'], 'api_key' => $this->ai->secret((int) $policy['version']),
                    ],
                ];
            });
        } catch (Throwable) {
            return 'Not available: Obli’s AI isn’t set up (Settings › Obli).';
        }
        if (is_string($prepared)) {
            return $prepared;
        }
        try {
            $answer = $this->provider->call($prepared['configuration'], NetworkCheckInstructions::SYSTEM, $message, $tool);
        } catch (Throwable) {
            $answer = null;
        } finally {
            unset($prepared['configuration']);
        }
        DB::transaction(function () use ($session, $prepared, $answer): void {
            foreach ($prepared['periods'] as $period) {
                DB::table('obli_ai_budget_periods')->where('period', $period)->lockForUpdate()->first();
            }
            $cost = $answer !== null ? ObliAiSettings::cost($answer['input_tokens'], $answer['output_tokens'], $prepared['input_rate'], $prepared['output_rate']) : $prepared['reserve'];
            $delta = $cost - $prepared['reserve'];
            if ($delta !== 0) {
                foreach ($prepared['periods'] as $period) {
                    DB::table('obli_ai_budget_periods')->where('period', $period)->increment('committed_micros', $delta);
                }
            }
            NetworkCheckSession::query()->whereKey($session->id)->increment('charged_micros', $cost);
        });

        return $answer === null ? self::AI_FAILED_PLAN : ['input' => $answer['input']];
    }

    /** Why Obli's AI can't be used, or null. @param array<string, mixed> $policy */
    private function aiReason(array $policy): ?string
    {
        if (! ($policy['enabled'] ?? false)) {
            return 'Not available: Obli’s AI is turned off in Settings › Obli.';
        }
        if (trim((string) ($policy['model'] ?? '')) === '' || trim((string) ($policy['input_price_usd'] ?? '')) === '' || trim((string) ($policy['output_price_usd'] ?? '')) === ''
            || ! in_array($policy['provider'] ?? null, ['openai', 'anthropic'], true)
            || ! AppSetting::query()->where('key', 'obli.ai.provider_secret')->exists()) {
            return 'Not available: Obli’s AI isn’t set up yet (Settings › Obli).';
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $plan
     */
    private function finishAnswered(NetworkCheckSession $session, string $answer, ?int $focus, bool $suggestUnifi, bool $unchecked, array $plan): void
    {
        DB::transaction(function () use ($session, $answer, $focus, $suggestUnifi, $unchecked, $plan): void {
            $session->forceFill([
                'plan' => $plan + ['title' => $this->words($session->question, 60, true)],
                'answer' => $answer,
                'focus_device_id' => $focus,
                'suggest_unifi' => $suggestUnifi,
                'unchecked_numbers' => $unchecked,
                'state' => 'answered',
                'failure' => null,
                'open_site_id' => null,
                'answered_at' => now(),
            ])->save();
            AuditLogger::logOrFail('security_devices.network_check.answered', $session, [
                'actor_id' => (int) $session->user_id, 'site_id' => (int) $session->site_id, 'collector_id' => (int) $session->collector_id,
                'checks' => $session->steps()->count(), 'charged_micros' => (int) $session->fresh()?->charged_micros,
            ]);
        });
    }

    private function end(NetworkCheckSession $session, string $state, string $failure): void
    {
        DB::transaction(function () use ($session, $state, $failure): void {
            NetworkCheckStep::query()->where('session_id', $session->id)->whereIn('state', ['queued', 'sent'])->update([
                'state' => 'not_available', 'finding' => 'Not available: Obli stopped before the hub answered', 'done_at' => now(),
            ]);
            $session->forceFill(['state' => $state, 'failure' => mb_substr($failure, 0, 191), 'open_site_id' => null])->save();
            AuditLogger::logOrFail('security_devices.network_check.'.($state === 'answered' ? 'answered' : 'ended'), $session, [
                'actor_id' => (int) $session->user_id, 'site_id' => (int) $session->site_id, 'failure' => $session->failure,
            ]);
        });
    }

    /* ------------------------------------------------------------------ */
    /*  The checks                                                         */
    /* ------------------------------------------------------------------ */

    /**
     * What a hub check is sent, or why it can't be done (plain words, no "Not available:").
     *
     * @param  array{why: ?string, console: ?DeviceEntry, address: ?string, pin: ?string, reference: ?string}  $unifi
     * @return array<string, mixed>|string
     */
    private function hubParams(string $kind, ?DeviceEntry $target, MonitoringCollector $hub, array $unifi): array|string
    {
        if ($kind === 'delay') {
            if (! $target instanceof DeviceEntry) {
                return 'no device was chosen';
            }

            return $this->delayReason($target, $hub) ?? ['address' => (string) $target->device->ip_address, '_device_id' => $target->id()];
        }
        if ($unifi['why'] !== null) {
            return $unifi['why'];
        }
        $params = ['console' => (string) $unifi['address'], 'port' => 443, 'trusted_pin' => (string) $unifi['pin']];
        if ($kind === 'gateway_speed') {
            return $params + ['_device_id' => $unifi['console']->id()];
        }
        if (! $target instanceof DeviceEntry) {
            return 'no device was chosen';
        }
        $ip = (string) $target->device->ip_address;
        $mac = self::mac($target->device->mac_address);
        if (filter_var($ip, FILTER_VALIDATE_IP) === false && $mac === '') {
            return 'Oblivion doesn’t know '.$target->name().'’s address, so the UniFi console can’t be asked about it';
        }

        return $params + [
            'address' => filter_var($ip, FILTER_VALIDATE_IP) !== false ? $ip : '',
            'mac' => $mac,
            ...($kind === 'wifi' ? ['hours' => 72] : []),
            '_device_id' => $unifi['console']->id(),
        ];
    }

    /** Why the hub can't measure the delay to this device, or null. */
    private function delayReason(DeviceEntry $entry, MonitoringCollector $hub): ?string
    {
        $ip = (string) $entry->device->ip_address;
        if (filter_var($ip, FILTER_VALIDATE_IP) === false || filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false) {
            return 'Oblivion has no private address for '.$entry->name();
        }
        try {
            $this->egress->authorise((int) $entry->siteId, $entry->id(), ProbeTarget::icmp($ip));
        } catch (Throwable) {
            return $entry->name().'’s address isn’t inside the hub’s allowed networks';
        }
        if (! $this->watchedBy($entry->id(), $hub)) {
            return CoveringHubResolver::label($hub).' doesn’t watch '.$entry->name().' yet';
        }

        return null;
    }

    private function watchedBy(int $deviceId, MonitoringCollector $hub): bool
    {
        return Monitor::query()->where('device_id', $deviceId)->where('collector_id', $hub->id)->where('is_enabled', true)->exists();
    }

    /**
     * The Site's UniFi console, if Oblivion can ask it through the hub: a saved UniFi login, a
     * known console with a private address the hub watches, and its trusted certificate.
     *
     * @param  Collection<int, DeviceEntry>  $entries
     * @return array{why: ?string, console: ?DeviceEntry, address: ?string, pin: ?string, reference: ?string}
     */
    private function unifi(int $siteId, string $siteName, MonitoringCollector $hub, Collection $entries): array
    {
        $none = static fn (string $why): array => ['why' => $why, 'console' => null, 'address' => null, 'pin' => null, 'reference' => null];
        $reference = $this->unifiReference($siteId);
        if ($reference === null) {
            return $none("there’s no UniFi login saved for {$siteName}");
        }
        $console = $entries
            ->filter(function (DeviceEntry $entry): bool {
                $words = implode(' ', [(string) $entry->device->manufacturer, (string) $entry->device->model, (string) $entry->device->category, (string) $entry->device->subcategory, $entry->name()]);

                return preg_match('/ubiquiti|unifi/i', $words) === 1
                    && ($entry->kind === DeviceKind::Router || preg_match('/console|cloud ?key|gateway|controller|dream ?machine|\budm|\buxg|\bucg/i', $words) === 1);
            })
            ->sortBy(fn (DeviceEntry $entry): int => $entry->kind === DeviceKind::Router ? 0 : 1)
            ->first(fn (DeviceEntry $entry): bool => filter_var((string) $entry->device->ip_address, FILTER_VALIDATE_IP) !== false
                && filter_var((string) $entry->device->ip_address, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false);
        if (! $console instanceof DeviceEntry) {
            return $none("Oblivion doesn’t know {$siteName}’s UniFi console address yet");
        }
        $address = (string) $console->device->ip_address;
        try {
            $this->egress->authorise($siteId, $console->id(), ProbeTarget::tls($address, 443));
        } catch (Throwable) {
            return $none('the UniFi console’s address isn’t inside the hub’s allowed networks');
        }
        if (! $this->watchedBy($console->id(), $hub)) {
            return $none(CoveringHubResolver::label($hub).' doesn’t watch the UniFi console ('.$console->name().') yet');
        }
        $pin = $this->trust->record($siteId, $address, 443)?->trusted_pin;
        if (! is_string($pin) || preg_match(CameraEndpointTrustService::PIN_PATTERN, $pin) !== 1) {
            return $none('trust the UniFi console’s certificate in Setup first, so the hub only sends the login to the real console');
        }

        return ['why' => null, 'console' => $console, 'address' => $address, 'pin' => $pin, 'reference' => $reference];
    }

    private function unifiReference(int $siteId): ?string
    {
        $reference = CredentialReference::query()
            ->where('site_id', $siteId)
            ->where('provider', 'unifi')
            ->whereIn('purpose', ['monitoring', 'discovery', 'device_management'])
            ->where('status', 'active')
            ->whereNotIn('rotation_status', ['overdue', 'failed'])
            ->orderByDesc('id')
            ->value('reference_key');

        return is_string($reference) && trim($reference) !== '' ? trim($reference) : null;
    }

    /**
     * A stored check, read now on the server.
     *
     * @param  Collection<int, DeviceEntry>  $entries
     * @return array<string, mixed>
     */
    private function storedResult(string $kind, ?DeviceEntry $target, Collection $entries, CarbonImmutable $now): array
    {
        return match ($kind) {
            'stored_outages' => $this->stored->outages($entries, $now),
            'stored_map' => $this->stored->map($entries, $target?->id()),
            'stored_checks' => $target instanceof DeviceEntry ? $this->stored->checks($target->device, $now) : ['checks' => 0, 'answered_pct' => null, 'typical_ms' => null, 'last_at' => null],
            default => [],
        };
    }

    /* ------------------------------------------------------------------ */
    /*  The hub's poll                                                     */
    /* ------------------------------------------------------------------ */

    /**
     * Results in, the next signed checks out (at most 3 a poll, for this hub's own sessions).
     *
     * @param  array<string, mixed>  $report
     * @return array{jobs: list<array<string, mixed>>}
     */
    public function hubWork(MonitoringCollector $hub, array $report): array
    {
        $touched = [];
        foreach (array_slice(is_array($report['results'] ?? null) ? $report['results'] : [], 0, 20) as $row) {
            if (is_array($row) && is_string($row['job'] ?? null)) {
                $sessionId = $this->record($hub, $row);
                if ($sessionId !== null) {
                    $touched[$sessionId] = true;
                }
            }
        }
        foreach ($this->expireSteps() as $sessionId) {
            $touched[$sessionId] = true;
        }
        foreach (array_keys($touched) as $sessionId) {
            if (NetworkCheckStep::query()->where('session_id', $sessionId)->whereIn('state', ['queued', 'sent'])->doesntExist()) {
                AdvanceNetworkCheck::dispatch((int) $sessionId);
            }
        }
        if (! HubJobTypes::runs($hub, self::HUB_FEATURE) || ! $hub->isApprovedForWork()) {
            return ['jobs' => []];
        }
        $now = CarbonImmutable::now('UTC');
        $siteIds = $hub->authorisedSiteIds();
        $out = [];
        $candidates = NetworkCheckStep::query()
            ->where('collector_id', $hub->id)
            ->where('expires_at', '>', $now)
            ->whereHas('session', fn ($query) => $query->whereIn('state', NetworkCheckSession::OPEN)->whereIn('site_id', $siteIds))
            ->where(fn ($query) => $query->where('state', 'queued')
                ->orWhere(fn ($sent) => $sent->where('state', 'sent')->where('sent_at', '<', $now->subSeconds(self::RESEND_AFTER_SECONDS))))
            ->orderBy('id')
            ->limit(3)
            ->get();
        foreach ($candidates as $candidate) {
            $payload = DB::transaction(function () use ($candidate, $hub, $now): ?array {
                $step = NetworkCheckStep::query()->whereKey($candidate->id)->lockForUpdate()->first();
                if (! $step instanceof NetworkCheckStep || ! in_array($step->state, ['queued', 'sent'], true) || (int) $step->collector_id !== (int) $hub->id) {
                    return null;
                }
                try {
                    $details = $this->details($hub, $step);
                } catch (DomainException $refused) {
                    $step->forceFill(['state' => 'not_available', 'finding' => mb_substr('Not available: '.$refused->getMessage(), 0, 500), 'done_at' => $now])->save();

                    return null;
                } catch (Throwable) {
                    return null; // Tried again next poll.
                }
                $step->forceFill(['state' => 'sent', 'sent_at' => $now])->save();

                return $details;
            });
            if ($payload !== null) {
                $out[] = $payload;
            }
        }

        return ['jobs' => $out];
    }

    /** The exact bytes the server signs and the hub verifies: the details without their signature. */
    public static function canonical(array $details): string
    {
        unset($details['signature']);

        return json_encode($details, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }

    /**
     * Strict parameters for each hub kind (the hub checks the same). Returns what is sent.
     *
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    public static function validate(string $kind, array $params): array
    {
        $keys = NetworkCheckKinds::HUB_PARAMS[$kind] ?? null;
        if ($keys === null) {
            throw new DomainException('that isn’t a check the hub runs');
        }
        $given = array_filter($params, static fn (string $key): bool => ! str_starts_with($key, '_'), ARRAY_FILTER_USE_KEY);
        // MySQL's json column reorders object keys, so compare them as a set, then send them in the
        // order the hub expects (it checks the exact order).
        $sameKeys = count($given) === count($keys) && array_diff(array_keys($given), $keys) === [];
        $sent = $sameKeys ? array_combine($keys, array_map(static fn (string $key): mixed => $given[$key], $keys)) : [];
        $private = static fn (mixed $value): bool => is_string($value) && filter_var($value, FILTER_VALIDATE_IP) !== false
            && filter_var($value, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false;
        $ok = $sameKeys && match ($kind) {
            'delay' => $private($sent['address']),
            default => $private($sent['console']) && $sent['port'] === 443
                && is_string($sent['trusted_pin']) && preg_match(CameraEndpointTrustService::PIN_PATTERN, $sent['trusted_pin']) === 1
                && (! array_key_exists('address', $sent) || $sent['address'] === '' || $private($sent['address']))
                && (! array_key_exists('mac', $sent) || $sent['mac'] === '' || preg_match('/\A[0-9a-f]{2}(:[0-9a-f]{2}){5}\z/', (string) $sent['mac']) === 1)
                && (! array_key_exists('address', $sent) || $sent['address'] !== '' || $sent['mac'] !== '')
                && (! array_key_exists('hours', $sent) || $sent['hours'] === 72),
        };
        if (! $ok) {
            throw new DomainException('the check had a detail Oblivion can’t send');
        }

        return $sent;
    }

    /** @return array<string, mixed> */
    private function details(MonitoringCollector $hub, NetworkCheckStep $step): array
    {
        $session = $step->session;
        if (! $session instanceof NetworkCheckSession || ! in_array((int) $session->site_id, $hub->authorisedSiteIds(), true)) {
            throw new DomainException('the Site isn’t one of the hub’s Sites');
        }
        $params = (array) $step->params;
        $deviceId = is_int($params['_device_id'] ?? null) ? $params['_device_id'] : 0;
        $sent = self::validate($step->kind, $params);
        $lease = null;
        if ($step->kind !== 'delay') {
            $reference = $this->unifiReference((int) $session->site_id) ?? throw new DomainException('there’s no UniFi login saved for the Site');
            $lease = $this->sealer->seal($hub, (string) $deviceId, 'https', (string) $sent['console'], $this->unifiLease((int) $session->site_id, $reference), (int) $session->site_id);
        }
        $details = [
            'job' => $step->uuid,
            'kind' => $step->kind,
            'site_id' => (int) $session->site_id,
            'device_id' => (string) $deviceId,
            'params' => $sent,
            'expires_at' => $step->expires_at->utc()->format(DATE_ATOM),
            'credential_lease' => $lease,
        ];
        $details['signature'] = base64_encode(sodium_crypto_sign_detached(self::canonical($details), $this->signingKey()));

        return $details;
    }

    /** A read-only lease for the Site's UniFi login (never a write capability). */
    private function unifiLease(int $siteId, string $reference): CredentialLease
    {
        $sets = [['monitoring.collector.unifi.read'], ['unifi.network.read'], ['monitoring.collector.https.read'], ['discovery.topology.read']];
        $stored = CredentialReference::query()->where('site_id', $siteId)->where('reference_key', $reference)->value('capabilities');
        foreach (is_array($stored) ? $stored : [] as $capability) {
            if (is_string($capability) && preg_match('/\b(unlock|disarm|arm|write|delete|reboot|exec|admin|restart)\b/i', $capability) !== 1 && str_contains($capability, 'read')) {
                $sets[] = [$capability];
            }
        }
        foreach ($sets as $capabilities) {
            try {
                return $this->leases->acquire($siteId, $reference, $capabilities);
            } catch (Throwable) {
            }
        }
        throw new DomainException('the saved UniFi login has no read-only access Oblivion can lend the hub');
    }

    /**
     * One result from the hub. Returns the session it belongs to, or null.
     *
     * @param  array<string, mixed>  $row
     */
    private function record(MonitoringCollector $hub, array $row): ?int
    {
        return DB::transaction(function () use ($hub, $row): ?int {
            $step = NetworkCheckStep::query()->where('uuid', strtolower((string) $row['job']))->lockForUpdate()->first();
            if (! $step instanceof NetworkCheckStep || (int) $step->collector_id !== (int) $hub->id || $step->state !== 'sent') {
                return null;
            }
            $now = CarbonImmutable::now('UTC');
            $result = ($row['state'] ?? null) === 'done' && is_array($row['result'] ?? null) && strlen(json_encode($row['result']) ?: '') <= 16_384
                ? NetworkCheckKinds::clean($step->kind, $row['result'])
                : null;
            if ($result !== null) {
                $names = $step->kind === 'switch_ports' && $step->target_device_id !== null ? $this->portNamesFor($step) : [];
                $step->forceFill(['state' => 'done', 'result' => $result, 'finding' => mb_substr(NetworkCheckKinds::finding($step->kind, $result, $names), 0, 500), 'done_at' => $now])->save();
            } else {
                $reason = is_string($row['reason'] ?? null) && isset(NetworkCheckKinds::HUB_REASONS[$row['reason']])
                    ? NetworkCheckKinds::HUB_REASONS[$row['reason']]
                    : (($row['state'] ?? null) === 'done' ? 'the hub’s answer didn’t look right' : 'the hub couldn’t do it');
                $step->forceFill(['state' => 'not_available', 'finding' => mb_substr('Not available: '.$reason, 0, 500), 'done_at' => $now])->save();
            }

            return (int) $step->session_id;
        });
    }

    /** @return array<int, string> */
    private function portNamesFor(NetworkCheckStep $step): array
    {
        $session = $step->session;
        $actor = $session?->user;
        if (! $actor instanceof User) {
            return [];
        }

        return $this->stored->portNames($this->entries($actor, (int) $session->site_id), (int) $step->target_device_id);
    }

    /** @return list<int> the sessions whose checks ran out of time */
    private function expireSteps(): array
    {
        $sessions = [];
        NetworkCheckStep::query()->whereIn('state', ['queued', 'sent'])->where('expires_at', '<=', now())->orderBy('id')->limit(200)->get()
            ->each(function (NetworkCheckStep $step) use (&$sessions): void {
                // Only while it is still waiting: a result the hub sent at this very moment wins.
                $changed = NetworkCheckStep::query()->whereKey($step->id)->where('state', $step->state)->update([
                    'state' => 'not_available',
                    'finding' => $step->state === 'sent' ? 'Not available: the hub didn’t answer in time' : 'Not available: the hub didn’t pick it up in time',
                    'done_at' => now(), 'updated_at' => now(),
                ]);
                if ($changed === 1) {
                    $sessions[(int) $step->session_id] = true;
                }
            });

        return array_keys($sessions);
    }

    /* ------------------------------------------------------------------ */
    /*  The minute tick, retention, IT jobs                                */
    /* ------------------------------------------------------------------ */

    /** Checks that ran out of time, and sessions whose next step is due. Returns sessions moved on. */
    public function sweep(): int
    {
        $this->expireSteps();
        $moved = 0;
        NetworkCheckSession::query()->whereIn('state', NetworkCheckSession::OPEN)
            ->where(fn ($query) => $query->whereNull('lease_until')->orWhere('lease_until', '<', now()))
            ->orderBy('id')->limit(50)->pluck('id')
            ->each(function (mixed $id) use (&$moved): void {
                $id = (int) $id;
                $session = NetworkCheckSession::query()->find($id);
                $due = $session instanceof NetworkCheckSession && ($session->state !== 'checking'
                    || $session->steps()->whereIn('state', ['queued', 'sent'])->doesntExist()
                    || $session->created_at?->lte(now()->subMinutes(self::SESSION_MINUTES)));
                if ($due) {
                    $this->advance($id);
                    $moved++;
                }
            });

        return $moved;
    }

    /** Sessions past their year go, with their checks. The copy on an IT job stays with the job. */
    public function prune(): int
    {
        $count = 0;
        NetworkCheckSession::query()->where('expires_at', '<', now())->whereNotIn('state', NetworkCheckSession::OPEN)->orderBy('id')->limit(1000)->get()
            ->each(function (NetworkCheckSession $session) use (&$count): void {
                $session->delete();
                $count++;
            });

        return $count;
    }

    /**
     * "Raise IT job": the session written up on a new IT ticket, through the IT intake service.
     *
     * @return array{id: int, reference: string, href: string}
     */
    public function raiseTicket(User $actor, NetworkCheckSession $session): array
    {
        abort_unless(self::mayAsk($actor), 403);
        $this->access->assertCanViewSite($actor, (int) $session->site_id);
        if ($session->open()) {
            throw ValidationException::withMessages(['ticket' => 'Wait for Obli’s answer before raising an IT job.']);
        }
        if ($session->ticket_id !== null) {
            $ticket = \App\Models\ItTicket::query()->find($session->ticket_id);
            if ($ticket !== null) {
                return ['id' => (int) $ticket->id, 'reference' => (string) $ticket->reference, 'href' => '/it/tickets/'.$ticket->id];
            }
        }
        $site = $session->site;
        $lines = [
            'Obli checked '.($site?->name ?? 'the Site').'’s network through '.$session->hub_label.'.',
            'Asked by '.$session->asked_by.', '.NetworkCheckKinds::when($session->created_at->getTimestamp()).':',
            (string) $session->question,
            '',
            'What Obli checked:',
        ];
        foreach ($session->steps()->get() as $step) {
            $lines[] = '- '.$step->title.': '.($step->finding ?? 'Not available').($step->done_at ? ' ('.NetworkCheckKinds::when($step->done_at->getTimestamp()).')' : '');
        }
        $lines[] = '';
        $lines[] = 'What Obli found:';
        $lines[] = $session->answer !== null ? str_replace('**', '', (string) $session->answer) : (string) ($session->failure ?? 'Obli didn’t write up an answer.');
        if ($session->unchecked_numbers) {
            $lines[] = '(Some numbers in Obli’s answer weren’t in what the hub reported. Check them against the list above.)';
        }
        $lines[] = '';
        $lines[] = 'Read-only: nothing on the network was changed.';
        $lines[] = 'Obli’s record: /security-devices/sites/'.$session->site_id.'?tab=network&obli='.$session->uuid;
        $hash = hash('sha256', 'network-check-ticket:'.$session->uuid.':'.$actor->id);
        $requestUuid = substr($hash, 0, 8).'-'.substr($hash, 8, 4).'-4'.substr($hash, 13, 3).'-8'.substr($hash, 17, 3).'-'.substr($hash, 20, 12);
        try {
            $result = app(ItTicketIntakeService::class)->createCommand($actor, [
                'request_uuid' => $requestUuid,
                'site_id' => (int) $session->site_id,
                'title' => mb_substr('Network: '.(string) (($session->plan ?? [])['title'] ?? $this->words($session->question, 60, true)), 0, 200),
                'description' => mb_substr(implode("\n", $lines), 0, 12000),
                'category' => 'network',
                'priority' => 'normal',
                'work_type' => 'incident',
                'requester_user_id' => $actor->id,
                'requested_for_user_id' => $actor->id,
            ], channel: ItTicketCommandChannel::Browser);
        } catch (ItTicketCommandConflict) {
            throw ValidationException::withMessages(['ticket' => 'This IT job was already raised differently. Open IT to find it.']);
        }
        DB::transaction(function () use ($session, $result, $actor): void {
            $session->forceFill(['ticket_id' => $result->ticket->id])->save();
            AuditLogger::logOrFail('security_devices.network_check.ticket_raised', $session, [
                'actor_id' => (int) $actor->id, 'site_id' => (int) $session->site_id, 'ticket_id' => (int) $result->ticket->id,
            ]);
        });

        return ['id' => (int) $result->ticket->id, 'reference' => (string) $result->ticket->reference, 'href' => '/it/tickets/'.$result->ticket->id];
    }

    /* ------------------------------------------------------------------ */
    /*  What the pop-up shows                                              */
    /* ------------------------------------------------------------------ */

    /** @return array<string, mixed> */
    public function present(NetworkCheckSession $session, User $viewer): array
    {
        $plan = is_array($session->plan) ? $session->plan : [];
        $focus = $session->focus_device_id !== null ? Device::query()->find($session->focus_device_id, ['id', 'name']) : null;
        $ticket = $session->ticket_id !== null ? \App\Models\ItTicket::query()->find($session->ticket_id, ['id', 'reference']) : null;
        $site = $session->site;

        return [
            'uuid' => $session->uuid,
            'state' => $session->state,
            'open' => $session->open(),
            'badge' => self::STATE_BADGE[$session->state] ?? self::STATE_BADGE['failed'],
            'site' => ['id' => (int) $session->site_id, 'name' => (string) ($site?->name ?? '')],
            'hub' => (string) $session->hub_label,
            'hub_at_site' => $site !== null && (int) MonitoringCollector::query()->whereKey($session->collector_id)->value('site_id') === (int) $site->id,
            'asked_by' => (string) $session->asked_by,
            'mine' => (int) $session->user_id === (int) $viewer->id,
            'asked_at' => $session->created_at?->toIso8601String(),
            'title' => (string) ($plan['title'] ?? $this->words($session->question, 60, true)),
            'question' => (string) $session->question,
            'intro' => is_string($plan['intro'] ?? null) ? $plan['intro'] : null,
            'plan' => array_values(array_filter((array) ($plan['lines'] ?? []), 'is_string')),
            'planned_at' => $session->planned_at?->toIso8601String(),
            'answer' => $session->answer,
            'answered_at' => $session->answered_at?->toIso8601String(),
            'unchecked_numbers' => (bool) $session->unchecked_numbers,
            'failure' => $session->failure,
            'steps' => $session->steps()->get()->map(fn (NetworkCheckStep $step): array => [
                'key' => $step->uuid,
                'title' => $step->title,
                'state' => $step->state,
                'finding' => $step->finding,
                'at' => ($step->done_at ?? $step->sent_at)?->toIso8601String(),
            ])->values()->all(),
            'focus' => $focus ? ['id' => (int) $focus->id, 'name' => (string) $focus->name, 'href' => '/security-devices/devices/'.$focus->id] : null,
            'unifi_href' => $session->suggest_unifi ? DeviceUpdates::UNIFI_URL : null,
            'ticket' => $ticket ? ['id' => (int) $ticket->id, 'reference' => (string) $ticket->reference, 'href' => '/it/tickets/'.$ticket->id] : null,
            // IT's own intake decides who may raise a job (it.request or it.manage).
            'can_ticket' => ! $session->open() && $ticket === null && ($viewer->canDo('it.request') || $viewer->canDo('it.manage')),
            'note' => 'Read-only: nothing on the network was changed · kept for a year with the Site’s records, and on the IT job if one is raised',
        ];
    }

    /* ------------------------------------------------------------------ */
    /*  Helpers                                                            */
    /* ------------------------------------------------------------------ */

    /** @return Collection<int, DeviceEntry> */
    private function entries(User $viewer, int $siteId): Collection
    {
        return $this->directory->entries($viewer, $siteId)->values();
    }

    /**
     * The network gear Obli may name (and the device asked from), at most 60.
     *
     * @param  Collection<int, DeviceEntry>  $entries
     * @return Collection<int, DeviceEntry>
     */
    private function networkEntries(Collection $entries, ?int $askedFrom): Collection
    {
        return $entries->filter(fn (DeviceEntry $entry): bool => in_array($entry->kind, self::NETWORK_KINDS, true) || $entry->id() === $askedFrom)
            ->sortBy(fn (DeviceEntry $entry): string => ($entry->id() === $askedFrom ? '0' : '1').$entry->sortKey())
            ->take(60)->values();
    }

    /** Plain text from the AI or a person: trimmed, bounded, no control characters. */
    private function words(mixed $value, int $length, bool $shorten = false, bool $lines = false): ?string
    {
        if (! is_string($value) || ! mb_check_encoding($value, 'UTF-8')) {
            return null;
        }
        $value = trim($lines ? preg_replace("/\n{3,}/", "\n\n", str_replace("\r", '', $value)) ?? '' : preg_replace('/\s+/u', ' ', $value) ?? '');
        if ($value === '' || preg_match($lines ? '/(?![\n\t])\p{Cc}/u' : '/\p{Cc}/u', $value) === 1) {
            return null;
        }
        if (mb_strlen($value) > $length) {
            if (! $shorten) {
                return null;
            }
            $value = rtrim(mb_substr($value, 0, $length - 1)).'…';
        }

        return $value;
    }

    /**
     * Every number Obli was given: the results, the findings, the question and the guidance.
     *
     * @param  Collection<int, NetworkCheckStep>  $steps
     * @return array<string, true>
     */
    private function knownNumbers(NetworkCheckSession $session, Collection $steps): array
    {
        $text = (string) $session->question.' '.implode(' ', NetworkCheckInstructions::GUIDANCE_NUMBERS);
        foreach ($steps as $step) {
            $text .= ' '.$step->title.' '.$step->finding.' '.json_encode($step->result ?? []);
        }
        $known = [];
        preg_match_all('/\d+(?:\.\d+)?/', $text, $matches);
        foreach ($matches[0] as $number) {
            $known[self::number($number)] = true;
        }

        return $known;
    }

    /** @param array<string, true> $known */
    private function hasUnknownNumber(string $answer, array $known): bool
    {
        preg_match_all('/\d+(?:\.\d+)?/', $answer, $matches);
        foreach ($matches[0] as $number) {
            if (! isset($known[self::number($number)])) {
                return true;
            }
        }

        return false;
    }

    private static function number(string $number): string
    {
        $number = ltrim($number, '0');
        $number = str_contains($number, '.') ? rtrim(rtrim($number, '0'), '.') : $number;

        return $number === '' || str_starts_with($number, '.') ? '0'.$number : $number;
    }

    private static function mac(mixed $value): string
    {
        $hex = is_string($value) ? strtolower((string) preg_replace('/[^0-9a-fA-F]/', '', $value)) : '';

        return strlen($hex) === 12 ? implode(':', str_split($hex, 2)) : '';
    }

    private function signingKey(): string
    {
        $key = base64_decode((string) config('monitoring.collector.signing_secret_key'), true);
        if (! is_string($key) || strlen($key) !== SODIUM_CRYPTO_SIGN_SECRETKEYBYTES) {
            throw new RuntimeException('The collector signing key is not set.');
        }

        return $key;
    }
}
