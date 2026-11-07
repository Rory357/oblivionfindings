<?php

namespace App\Domain\Obli\Fixes;

use App\Domain\It\Services\ItWorkAccessService;
use App\Domain\Monitoring\Services\EffectiveMonitoringPolicyResolver;
use App\Domain\Obli\ObliSignIn;
use App\Domain\SecurityDevices\Setup\SecurityDevicesSettings;
use App\Models\User;
use App\Notifications\ObliFixNotification;
use App\Services\AuditLogger;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * S&D Phase I (N): when a staff computer had no internet, the tray ran its built-in checks with no
 * AI and kept a small note. When Oblivion answers again it posts the note here, and IT for the
 * person's Site is told through the device-alert channels. Kept 90 days.
 */
final class ObliOfflineReports
{
    /** The tray's note (OfflineChecks.Remember): only these yes/no checks are kept. */
    private const CHECKS = ['NetworkUp' => 'Network connected', 'GatewayAnswers' => 'The house router answered', 'NamesWork' => 'Website names worked (DNS)', 'ServerAnswers' => 'Oblivion answered'];

    public function __construct(private readonly ObliSignIn $signIn, private readonly ItWorkAccessService $access) {}

    /** @return array{received: bool} */
    public function store(#[\SensitiveParameter] ?string $token, array $input): array
    {
        abort_if(array_diff(array_keys($input), ['started_at', 'ended_at', 'checks', 'site_id']) !== [], 422);
        abort_unless(Schema::hasTable('obli_offline_reports'), 404);
        $started = self::time($input['started_at'] ?? null);
        $ended = self::time($input['ended_at'] ?? null);
        abort_if($started === null || $ended === null || $started->gt($ended) || $ended->gt(now()->addMinutes(2)) || $started->lt(now()->subDays(14)), 422);
        abort_unless(is_array($input['checks'] ?? null) && strlen(json_encode($input['checks'])) <= 4096, 422);
        $checks = [];
        foreach (self::CHECKS as $key => $label) {
            if (array_key_exists($key, $input['checks'])) {
                abort_unless(is_bool($input['checks'][$key]), 422);
                $checks[] = ['key' => $key, 'label' => $label, 'ok' => $input['checks'][$key]];
            }
        }

        return $this->signIn->withSession($token, function (User $actor) use ($input, $started, $ended, $checks): array {
            $sites = $this->access->approvedSiteIds($actor, true);
            $siteId = isset($input['site_id']) && is_int($input['site_id']) && in_array($input['site_id'], $sites, true) ? $input['site_id'] : (count($sites) === 1 ? $sites[0] : null);
            $hash = hash('sha256', $actor->id.'|'.$started->toIso8601String().'|'.$ended->toIso8601String());
            $inserted = DB::table('obli_offline_reports')->insertOrIgnore([
                'user_id' => $actor->id, 'site_id' => $siteId, 'dedupe_hash' => $hash, 'started_at' => $started, 'ended_at' => $ended,
                'checks' => json_encode($checks, JSON_THROW_ON_ERROR), 'created_at' => now(),
            ]);
            if ($inserted > 0) {
                AuditLogger::logOrFail('obli.offline_report.received', null, ['actor_id' => (int) $actor->id, 'site_id' => $siteId, 'minutes' => (int) abs($started->diffInMinutes($ended))]);
                $tellSites = $siteId !== null ? [$siteId] : array_slice($sites, 0, 3);
                $name = (string) $actor->name;
                DB::afterCommit(fn () => $this->tellIt($tellSites, $name, $started, $ended));
            }

            return ['received' => true];
        });
    }

    /** @param  list<int>  $siteIds */
    private function tellIt(array $siteIds, string $who, CarbonImmutable $started, CarbonImmutable $ended): void
    {
        try {
            $settings = app(SecurityDevicesSettings::class);
            $channels = $settings->channels(SecurityDevicesSettings::DEVICES_APP, SecurityDevicesSettings::DEVICES_EMAIL, SecurityDevicesSettings::DEVICES_PUSH);
            if ($channels === []) {
                return;
            }
            foreach ($siteIds as $siteId) {
                $people = app(EffectiveMonitoringPolicyResolver::class)->resolveItRecipients($siteId, $settings->role(SecurityDevicesSettings::DEVICES_ROLE));
                if ($people->isEmpty()) {
                    continue;
                }
                $site = (string) (DB::table('sites')->where('id', $siteId)->value('name') ?? 'a Site');
                $minutes = max(1, (int) abs($started->diffInMinutes($ended)));
                Notification::send($people, new ObliFixNotification(
                    'offline', 'A staff computer had no internet for '.($minutes === 1 ? '1 minute' : $minutes.' minutes'), $site.' · '.$who,
                    '/security-devices/sites/'.$siteId, $channels,
                ));
            }
        } catch (Throwable $error) {
            Log::warning('Obli offline report notice deferred', ['error' => class_basename($error)]);
        }
    }

    private static function time(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || strlen($value) > 40) {
            return null;
        }
        try {
            return CarbonImmutable::parse($value)->utc();
        } catch (Throwable) {
            return null;
        }
    }
}
