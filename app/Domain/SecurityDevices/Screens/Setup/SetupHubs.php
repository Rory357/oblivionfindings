<?php

namespace App\Domain\SecurityDevices\Screens\Setup;

use App\Domain\Monitoring\Models\MonitoringCollector;
use App\Domain\SecurityDevices\Hubs\Services\HubActionRules;
use App\Domain\SecurityDevices\Hubs\Services\HubUpdatePolicy;
use App\Domain\SecurityDevices\Screens\Hubs\HubPanelPresenter;
use App\Domain\SecurityDevices\Services\SecurityDevicesAccessService;
use App\Models\User;
use Carbon\CarbonImmutable;

/**
 * Setup › Hubs & networks › Hubs (drawing pg-sdr-15p): a card for each hub at the viewer's Sites, a banner when a
 * hub's last update failed and it went back, what happened in the hub's own words (its update report), and how hub
 * updates work. Cards open the hub panel; Try again and Hold updates are the hub panel's own governed actions.
 *
 * Hub updates wait for the test hub (Stephan, 3 Nov 2026: "Wait for the test hub"): see HubUpdatePolicy.
 */
final class SetupHubs
{
    /** An update that failed this long ago is history, not news. */
    private const BANNER_DAYS = 7;

    public function __construct(
        private readonly SecurityDevicesAccessService $access,
        private readonly HubPanelPresenter $panels,
    ) {}

    /** @return array<string, mixed> */
    public function present(User $viewer): array
    {
        $how = self::how();
        if (! HubPanelPresenter::mayView($viewer)) {
            return ['cards' => [], 'banner' => null, 'for_it' => null, 'how' => $how, 'can' => ['add' => false, 'hold' => false], 'held' => false, 'locked' => true];
        }
        $siteIds = $this->access->accessibleSiteIds($viewer);
        $hubs = $siteIds === [] ? collect() : MonitoringCollector::query()
            ->whereNull('revoked_at')
            ->whereNotNull('enrolled_at')
            ->whereIn('site_id', $siteIds)
            ->orderBy('name')
            ->orderBy('id')
            ->get();
        $panels = $hubs->map(fn (MonitoringCollector $hub): array => ['hub' => $hub, 'panel' => $this->panels->present($viewer, $hub)]);
        $held = $panels->isNotEmpty() && (bool) $panels->first()['panel']['updates']['held'];
        $failed = $panels
            ->filter(fn (array $row): bool => $this->failedRecently($row['panel']['updates']['last']))
            ->sortByDesc(fn (array $row): string => (string) $row['panel']['updates']['last']['at'])
            ->first();

        return [
            'cards' => $panels->map(fn (array $row): array => $this->card($row['panel']))->values()->all(),
            'banner' => $failed === null ? null : $this->banner($failed['hub'], $failed['panel'], $held),
            'for_it' => $failed === null ? null : $this->forIt($failed['hub'], $failed['panel']),
            'how' => $how,
            'can' => [
                'add' => HubActionRules::allows($viewer, 'securityDevices.hubs.approve'),
                'hold' => HubActionRules::allows($viewer, 'securityDevices.hubs.approve'),
            ],
            'held' => $held,
            'locked' => false,
        ];
    }

    /** @param  array<string, mixed>|null  $last */
    private function failedRecently(?array $last): bool
    {
        return $last !== null
            && in_array($last['outcome'] ?? null, ['failed', 'rolled_back'], true)
            && is_string($last['at'] ?? null)
            && CarbonImmutable::parse($last['at'])->gt(CarbonImmutable::now()->subDays(self::BANNER_DAYS));
    }

    /**
     * @param  array<string, mixed>  $panel
     * @return array<string, mixed>
     */
    private function card(array $panel): array
    {
        $hub = $panel['hub'];
        $updates = $panel['updates'];
        $last = $updates['last'];
        $lines = [];
        $lines[] = match ($hub['state']) {
            'online' => ['tone' => 'success', 'text' => 'Online · heard {ago}', 'at' => $hub['heard_at']],
            'not_answering' => ['tone' => 'critical', 'text' => 'Not answering · last heard {ago}', 'at' => $hub['heard_at']],
            'waiting_for_approval' => ['tone' => 'warning', 'text' => 'Waiting for an admin to approve it', 'at' => null],
            default => ['tone' => 'info', 'text' => 'Approved · waiting for its first check-in', 'at' => null],
        };
        if ($last !== null && $this->failedRecently($last)) {
            $lines[] = ['tone' => 'warning', 'text' => '**'.($last['to'] ?? 'The new version').'** failed {time}, back on '.($last['from'] ?? 'its version before'), 'at' => $last['at']];
        } elseif ($last !== null && $last['outcome'] === 'installed') {
            $lines[] = ['tone' => 'success', 'text' => 'Updated to **'.($last['to'] ?? 'the new version').'** {time}', 'at' => $last['at']];
        }
        $sites = array_map(fn (array $site): string => (string) $site['name'], $panel['sites']);
        if ($sites !== []) {
            $lines[] = ['tone' => 'info', 'text' => 'Watches **'.self::listWords($sites).'**', 'at' => null];
        }
        $lines[] = $updates['ring'] === 'early'
            ? ['tone' => 'info', 'text' => '**Test first**: gets updates before the other hubs', 'at' => null]
            : $this->waitingLine($updates['waiting']);

        return [
            'id' => (int) $hub['id'],
            'name' => (string) $hub['label'],
            'sub' => trim(implode(' · ', array_filter([
                match ($hub['platform']) { 'windows' => 'Windows', 'linux' => 'Raspberry Pi or Linux', default => null },
                $hub['version'] ? 'version '.$hub['version'] : null,
            ]))),
            'flag' => match (true) {
                $hub['state'] === 'not_answering' => ['tone' => 'critical', 'text' => 'Not answering'],
                $last !== null && $this->failedRecently($last) => ['tone' => 'warning', 'text' => 'Update failed'],
                $hub['state'] === 'waiting_for_approval' => ['tone' => 'warning', 'text' => 'Waiting for approval'],
                $hub['state'] === 'online' => ['tone' => 'success', 'text' => 'Online'],
                default => ['tone' => 'neutral', 'text' => 'Starting'],
            },
            'lines' => array_values(array_filter($lines)),
        ];
    }

    /** @param  array<string, mixed>|null  $waiting */
    private function waitingLine(?array $waiting): ?array
    {
        if ($waiting === null) {
            return ['tone' => 'info', 'text' => 'Gets updates with everyone', 'at' => null];
        }
        $test = $waiting['test_hub'] ?? 'the test hub';

        return match ($waiting['state']) {
            'held' => ['tone' => 'warning', 'text' => 'Stays on its version: **'.$waiting['version'].'** failed on '.$test, 'at' => null],
            'waiting' => ['tone' => 'info', 'text' => 'Waits for '.$test.' to have **'.$waiting['version'].'** working', 'at' => null],
            'day' => ['tone' => 'info', 'text' => 'Gets **'.$waiting['version'].'** {time}, a day after it came out', 'at' => $waiting['ready_at']],
            default => ['tone' => 'info', 'text' => 'Gets **'.$waiting['version'].'** in its next update window', 'at' => null],
        };
    }

    /**
     * @param  array<string, mixed>  $panel
     * @return array<string, mixed>
     */
    private function banner(MonitoringCollector $hub, array $panel, bool $held): array
    {
        $last = $panel['updates']['last'];
        $to = $last['to'] ?? 'the new version';
        $from = $last['from'];
        $label = (string) $panel['hub']['label'];
        $rolledBack = $last['outcome'] === 'rolled_back';
        $title = $rolledBack && $from !== null
            ? "{$label} couldn’t update to {$to}, so it went back to {$from}"
            : "{$label} couldn’t update to {$to}".($from !== null ? "; it’s still on {$from}" : '');
        $online = $panel['hub']['state'] === 'online';
        $sub = 'It tried {time}'.($online ? ' and is working normally again.' : '. It isn’t answering now.');
        $then = $panel['updates']['ring'] === 'early'
            ? 'The other hubs keep their version until a test-first hub has '.$to.' working. '
            : '';
        $then .= $held
            ? 'Updates are on hold for every hub until you let them go.'
            : 'It tries again in its next update window. Try again starts it now; Hold updates stops every hub updating until you let it go.';

        return [
            'hub_id' => (int) $hub->id,
            'label' => $rolledBack ? 'Update failed · back on '.($from ?? 'its version before').' by itself' : 'Update failed',
            'title' => $title,
            'text' => $sub,
            'at' => $last['at'],
            'then' => $then,
            'can' => [
                'try_again' => (bool) $panel['can']['check_update'],
            ],
        ];
    }

    /**
     * What the hub's own update report says, for IT.
     *
     * @param  array<string, mixed>  $panel
     * @return list<array{0: string, 1: string}>
     */
    private function forIt(MonitoringCollector $hub, array $panel): array
    {
        $state = is_array($hub->update_state) ? $hub->update_state : [];
        $detail = is_string($state['detail'] ?? null) && trim($state['detail']) !== '' ? trim($state['detail']) : null;
        $updates = $panel['updates'];
        $rows = [];
        $rows[] = ['What it reported', $detail ?? 'No detail in its report'];
        $rows[] = ['Versions', ($updates['last']['from'] ?? '?').' → '.($updates['last']['to'] ?? '?').($updates['last']['outcome'] === 'rolled_back' ? ' → back to '.($updates['last']['from'] ?? '?') : '')];
        if ($updates['last']['outcome'] === 'rolled_back') {
            $rows[] = ['Gone back by', 'The hub’s own updater, after '.HubUpdatePolicy::ROLLBACK_AFTER_MINUTES.' minutes with no check-in'];
        }
        $rows[] = ['Gets updates', ($updates['ring'] === 'early' ? 'Test first' : 'With everyone').' · '.self::clock($updates['window_start']).' – '.self::clock($updates['window_end'])];

        return $rows;
    }

    /** @return list<array{tone: string, text: string, at: null}> */
    private static function how(): array
    {
        return [
            ['tone' => 'info', 'text' => 'Hubs update themselves overnight, in their update window', 'at' => null],
            ['tone' => 'info', 'text' => '**Test first** hubs get a new version first (Edit hub)', 'at' => null],
            ['tone' => 'info', 'text' => 'A new version that can’t check in within '.HubUpdatePolicy::ROLLBACK_AFTER_MINUTES.' minutes is swapped back by the hub itself, which tries again the next night', 'at' => null],
            ['tone' => 'info', 'text' => 'The other hubs take it **a day after it comes out, and only once a test-first hub has it working**. If the test hub’s update fails, they keep their version. With no test-first hub, they wait the day', 'at' => null],
            ['tone' => 'warning', 'text' => '**Hold updates** stops every hub updating until you let it go', 'at' => null],
        ];
    }

    /** @param  list<string>  $names */
    private static function listWords(array $names): string
    {
        if (count($names) <= 1) {
            return $names[0] ?? '';
        }

        return implode(', ', array_slice($names, 0, -1)).' and '.end($names);
    }

    private static function clock(string $value): string
    {
        $time = CarbonImmutable::createFromFormat('H:i', substr($value, 0, 5));

        return $time === false ? $value : strtolower($time->format('g:i a'));
    }
}
