<?php

namespace App\Console\Commands;

use App\Jobs\Notifications\DeliverBroadcastCommunicationJob;
use App\Models\Client;
use App\Models\ControlRoom\AlertSla;
use App\Models\ControlRoom\BroadcastIntent;
use App\Models\ControlRoom\Communication;
use App\Models\ControlRoom\Device;
use App\Models\ControlRoom\SignalSource;
use App\Models\ControlRoom\SlaDefinition;
use App\Models\ControlRoom\TriageQueue;
use App\Models\ControlRoomAlert;
use App\Models\Site;
use App\Models\User;
use App\Notifications\ControlRoom\BroadcastRecipientNotice;
use App\Services\ControlRoom\BroadcastRecipientLedger;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Example Control Room data for a LOCAL dev system only (Stephan, 24 Oct
 * 2026: "fill the local dev system with realistic example data"). Every
 * record is findable and removable:
 *  - houses end in " (demo)", alert references start "DEMO-",
 *  - queues and devices use a "demo-" code/uid,
 *  - messages carry template_used = "demo-control-room".
 * Running it again replaces the old example data. `--remove` deletes it.
 */
final class ControlRoomDemoData extends Command
{
    protected $signature = 'control-room:demo {--remove : Delete the example data and stop}';

    protected $description = 'Add (or remove) clearly-named example Control Room data. Local dev only.';

    public const SITE_SUFFIX = ' (demo)';

    public const REF_PREFIX = 'DEMO-';

    public const CODE_PREFIX = 'demo-';

    public const MESSAGE_TAG = 'demo-control-room';

    public function handle(): int
    {
        if (! app()->environment('local')) {
            $this->error('Example data is for a local dev system only. Nothing was changed.');

            return self::FAILURE;
        }

        $removed = $this->remove();
        $this->info("Removed old example data: {$removed} records.");
        if ($this->option('remove')) {
            return self::SUCCESS;
        }

        $created = DB::transaction(fn (): int => $this->seed());
        $this->info("Added example data: {$created} records. Remove it with: php artisan control-room:demo --remove");

        return self::SUCCESS;
    }

    private function remove(): int
    {
        return DB::transaction(function (): int {
            $count = 0;
            $siteIds = Site::withTrashed()->where('name', 'like', '%'.self::SITE_SUFFIX)->pluck('id');
            $alertIds = ControlRoomAlert::query()->where('reference_number', 'like', self::REF_PREFIX.'%')->pluck('id');

            $count += AlertSla::query()->whereIn('alert_id', $alertIds)->delete();

            // Example broadcasts: their inbox copies and their ledger entries.
            $demoMessages = Communication::query()
                ->where('template_used', self::MESSAGE_TAG)
                ->get(['id', 'broadcast_group_id', 'broadcast_outcome']);
            $noticeIds = $demoMessages
                ->map(fn (Communication $row) => is_array($row->broadcast_outcome)
                    ? ($row->broadcast_outcome['recipient_notification_id'] ?? null)
                    : null)
                ->filter()
                ->values();
            $count += DB::table('notifications')
                ->where('type', BroadcastRecipientNotice::class)
                ->whereIn('id', $noticeIds)
                ->delete();
            $groupIds = $demoMessages->pluck('broadcast_group_id')->filter()->unique()->values();

            $count += Communication::query()
                ->where('template_used', self::MESSAGE_TAG)
                ->orWhereIn('alert_id', $alertIds)
                ->delete();
            $count += BroadcastIntent::query()->whereIn('id', $groupIds)->delete();
            $count += ControlRoomAlert::query()->whereIn('id', $alertIds)->delete();
            $count += Device::withTrashed()->where('device_uid', 'like', self::CODE_PREFIX.'%')->forceDelete();
            $count += TriageQueue::query()->where('code', 'like', self::CODE_PREFIX.'%')->delete();
            $count += SlaDefinition::query()->where('code', 'like', self::CODE_PREFIX.'%')->delete();
            $count += Client::withTrashed()->whereIn('site_id', $siteIds)->forceDelete();
            $count += Site::withTrashed()->whereIn('id', $siteIds)->forceDelete();

            return $count;
        });
    }

    private function seed(): int
    {
        $count = 0;
        $now = now();

        // Staff to own alerts and receive messages: real local accounts.
        $staff = User::staff()->orderBy('id')->limit(6)->get(['id', 'name']);
        $owner = fn (int $index): ?int => $staff->get($index)?->id;

        $sites = collect(['Aurora House', 'Kōwhai Lodge', 'Tōtara House', 'Rimu Cottage'])
            ->mapWithKeys(fn (string $name) => [$name => Site::factory()->create(['name' => $name.self::SITE_SUFFIX])]);
        $count += $sites->count();

        $person = function (string $first, string $last, string $site) use (&$count, $sites): Client {
            $count++;

            return Client::factory()->create([
                'first_name' => $first,
                'last_name' => $last,
                'site_id' => $sites[$site]->id,
            ]);
        };
        $hemi = $person('Hemi', 'Walker', 'Aurora House');
        $kahu = $person('Kahu', 'Tipoki', 'Kōwhai Lodge');
        $mere = $person('Mere', 'Tane', 'Aurora House');
        $ben = $person('Ben', 'Kerr', 'Tōtara House');

        $senior = TriageQueue::create([
            'name' => 'Senior response (demo)',
            'code' => self::CODE_PREFIX.'senior-response',
            'tier' => 2,
            'is_active' => true,
        ]);
        $count++;

        // Response targets (Settings › Response targets). A timer only counts
        // when it belongs to one, so each example alert links to its level's.
        $definitions = collect([
            'critical' => ['Critical alerts (demo)', 3, 10, 60],
            'high' => ['High alerts (demo)', 5, 15, 120],
            'medium' => ['Medium and low alerts (demo)', 15, 30, 480],
        ])->map(function (array $row, string $severity) use (&$count) {
            [$name, $ack, $respond, $resolve] = $row;
            $count++;

            return SlaDefinition::create([
                'name' => $name,
                'code' => self::CODE_PREFIX.$severity,
                'description' => 'Example response target for the demo houses.',
                'severities' => $severity === 'medium' ? ['medium', 'low'] : [$severity],
                'acknowledge_target_minutes' => $ack,
                'response_target_minutes' => $respond,
                'resolution_target_minutes' => $resolve,
                'escalate_on_acknowledge_breach' => false,
                'escalate_on_response_breach' => false,
                'escalate_on_resolution_breach' => false,
                'business_hours_only' => false,
                'is_active' => true,
            ]);
        });

        $n = 0;
        $alert = function (array $fields, ?array $targets = null) use (&$count, &$n, $now, $definitions): ControlRoomAlert {
            $n++;
            $title = $fields['title'];
            unset($fields['title']);
            $alert = ControlRoomAlert::withoutEvents(fn () => ControlRoomAlert::create(array_merge([
                'reference_number' => self::REF_PREFIX.str_pad((string) $n, 4, '0', STR_PAD_LEFT),
                'status' => ControlRoomAlert::STATUS_OPEN,
                'escalation_level' => 0,
                'context' => ['title' => $title, 'demo' => true],
            ], $fields)));
            $count++;

            if ($targets !== null) {
                // Minutes from when it was raised: [acknowledge, respond, resolve].
                [$ack, $respond, $resolve] = $targets;
                $raised = Carbon::parse($alert->triggered_at);
                AlertSla::query()->updateOrCreate(['alert_id' => $alert->id], [
                    'sla_definition_id' => ($definitions[$alert->severity] ?? $definitions['medium'])->id,
                    'acknowledge_target_minutes' => $ack,
                    'response_target_minutes' => $respond,
                    'resolution_target_minutes' => $resolve,
                    'acknowledge_deadline' => $raised->copy()->addMinutes($ack),
                    'response_deadline' => $raised->copy()->addMinutes($respond),
                    'resolution_deadline' => $raised->copy()->addMinutes($resolve),
                    'acknowledged_at' => $alert->acknowledged_at,
                    'resolved_at' => $alert->resolved_at,
                    'acknowledge_breached' => ! $alert->acknowledged_at && $raised->copy()->addMinutes($ack)->lt($now),
                    'response_breached' => false,
                    'resolution_breached' => false,
                    'cycle_number' => 1,
                    'cycle_started_at' => $raised,
                ]);
                $count++;
            }

            return $alert;
        };

        $alert([
            'title' => 'Hemi pressed SOS on his pendant',
            'source' => 'oblivion_monitoring', 'alert_type' => 'sos_triggered', 'severity' => 'critical',
            'site_id' => $sites['Aurora House']->id, 'client_id' => $hemi->id,
            'triggered_at' => $now->copy()->subMinutes(1),
        ], [3, 10, 60]);
        $alert([
            'title' => 'Kahu left the Kōwhai Lodge safe zone',
            'source' => 'oblivion_monitoring', 'alert_type' => 'safe_zone_exit', 'severity' => 'high',
            'site_id' => $sites['Kōwhai Lodge']->id, 'client_id' => $kahu->id,
            'triggered_at' => $now->copy()->subMinutes(9),
        ], [5, 15, 60]);
        $alert([
            'title' => 'Possible fall reported in the lounge',
            'source' => 'oblivion_monitoring', 'alert_type' => 'fall_detected', 'severity' => 'critical',
            'status' => ControlRoomAlert::STATUS_ACK,
            'site_id' => $sites['Aurora House']->id, 'client_id' => $mere->id,
            'triggered_at' => $now->copy()->subMinutes(7),
            'acknowledged_at' => $now->copy()->subMinutes(5), 'acknowledged_by_user_id' => $owner(0),
            'assigned_to_user_id' => $owner(0), 'assigned_at' => $now->copy()->subMinutes(5),
        ], [3, 12, 60]);
        $alert([
            'title' => 'Kitchen smoke detector in alarm',
            'source' => 'sensor', 'alert_type' => 'smoke_alarm', 'severity' => 'critical',
            'status' => ControlRoomAlert::STATUS_TRIAGING,
            'site_id' => $sites['Tōtara House']->id,
            'triggered_at' => $now->copy()->subMinutes(18),
            'acknowledged_at' => $now->copy()->subMinutes(16), 'acknowledged_by_user_id' => $owner(1),
            'assigned_to_user_id' => $owner(1), 'assigned_at' => $now->copy()->subMinutes(16),
        ], [2, 10, 45]);
        $alert([
            'title' => 'Lone-worker check-in missed on the evening shift',
            'source' => 'shift_operations', 'alert_type' => 'missed_check_in', 'severity' => 'high',
            'site_id' => $sites['Tōtara House']->id, 'client_id' => $ben->id,
            'triggered_at' => $now->copy()->subMinutes(26),
            'escalation_level' => 1, 'escalated_at' => $now->copy()->subMinutes(6),
            'queue_id' => $senior->id,
        ], [10, 20, 120]);
        $alert([
            'title' => 'Community van not back from the Raglan outing',
            'source' => 'fleet', 'alert_type' => 'vehicle_overdue', 'severity' => 'medium',
            'status' => ControlRoomAlert::STATUS_ACK,
            'site_id' => $sites['Kōwhai Lodge']->id,
            'triggered_at' => $now->copy()->subMinutes(35),
            'acknowledged_at' => $now->copy()->subMinutes(30), 'acknowledged_by_user_id' => $owner(2),
            'assigned_to_user_id' => $owner(2), 'assigned_at' => $now->copy()->subMinutes(30),
        ], [15, 30, 240]);
        $alert([
            'title' => 'Bedroom door sensor battery low',
            'source' => 'sensor', 'alert_type' => 'battery_low', 'severity' => 'low',
            'site_id' => $sites['Rimu Cottage']->id,
            'triggered_at' => $now->copy()->subHours(26),
        ]);
        $alert([
            'title' => 'Bathroom motion sensor offline',
            'source' => 'sensor', 'alert_type' => 'device_offline', 'severity' => 'medium',
            'site_id' => $sites['Aurora House']->id,
            'triggered_at' => $now->copy()->subHours(2),
            'snoozed_until' => $now->copy()->addMinutes(45), 'snoozed_by_user_id' => $owner(1),
        ], [30, 60, 480]);
        $alert([
            'title' => 'Hemi returned to the house after a short walk',
            'source' => 'oblivion_monitoring', 'alert_type' => 'safe_zone_exit', 'severity' => 'high',
            'status' => ControlRoomAlert::STATUS_RESOLVED,
            'site_id' => $sites['Aurora House']->id, 'client_id' => $hemi->id,
            'triggered_at' => $now->copy()->subHours(5),
            'acknowledged_at' => $now->copy()->subHours(5)->addMinutes(2), 'acknowledged_by_user_id' => $owner(0),
            'resolved_at' => $now->copy()->subHours(4)->subMinutes(20), 'resolved_by_user_id' => $owner(0),
            'resolution_code' => 'resolved_on_site',
        ], [5, 15, 60]);

        // Devices for Monitoring › Coverage: reporting, quiet, never heard
        // from — and Rimu Cottage has none at all.
        $sources = SignalSource::query()->orderBy('id')->get(['id', 'name']);
        $device = function (string $name, string $type, string $site, ?Carbon $lastSignal, int $sourceIndex) use (&$count, $sites, $sources): void {
            Device::create([
                'name' => $name,
                'device_uid' => self::CODE_PREFIX.Str::slug($name.' '.$site),
                'type' => $type,
                'site_id' => $sites[$site]->id,
                'signal_source_id' => $sources->get($sourceIndex % max(1, $sources->count()))?->id,
                'status' => $lastSignal ? 'online' : 'offline',
                'last_signal_at' => $lastSignal,
                'last_seen_at' => $lastSignal,
            ]);
            $count++;
        };
        $device('Lounge fall sensor', 'sensor', 'Aurora House', $now->copy()->subMinutes(2), 0);
        $device('Front door contact', 'door', 'Aurora House', $now->copy()->subMinutes(4), 2);
        $device('Hemi’s SOS pendant', 'tracker', 'Aurora House', $now->copy()->subMinutes(1), 1);
        $device('Kitchen smoke detector', 'alarm_panel', 'Tōtara House', $now->copy()->subMinutes(18), 2);
        // Over 24 hours with nothing: Coverage reads it as Quiet.
        $device('Hallway motion sensor', 'sensor', 'Tōtara House', $now->copy()->subHours(30), 2);
        $device('Community van tracker', 'tracker', 'Kōwhai Lodge', $now->copy()->subMinutes(6), 1);
        $device('Back gate contact', 'door', 'Kōwhai Lodge', null, 2);

        // Broadcasts (one urgent message to many staff), sent through the real
        // path: the intent ledger and the real in-app delivery, so each in-app
        // recipient gets a proper inbox copy. Texts are only marked
        // delivered/failed here — nothing is actually sent.
        $actor = $staff->first() ? User::query()->find($staff->first()->id) : null;
        $broadcast = function (string $content, Carbon $sentAt, array $smsOutcomes, int $readers) use (&$count, $staff, $actor): void {
            if (! $actor) {
                return;
            }
            $group = (string) Str::uuid();
            $targets = User::query()
                ->whereIn('id', $staff->take(5)->pluck('id'))
                ->orderBy('id')
                ->get(['id', 'name', 'email', 'cellphone', 'work_phone']);
            foreach ($targets as $index => $target) {
                foreach (['in_app', 'sms'] as $channel) {
                    Communication::create([
                        'broadcast_group_id' => $group,
                        'channel' => $channel,
                        'direction' => 'outbound',
                        'purpose' => 'broadcast',
                        'target_user_id' => $target->id,
                        'target_phone' => $channel === 'sms' ? '+64 21 000 0'.str_pad((string) $index, 3, '0', STR_PAD_LEFT) : null,
                        'content' => $content,
                        'template_used' => self::MESSAGE_TAG,
                        'status' => 'pending',
                        'force_delivery' => false,
                        'sent_at' => $sentAt,
                        'initiated_by_user_id' => $actor->id,
                    ]);
                    $count++;
                }
            }
            app(BroadcastRecipientLedger::class)->record($actor, $group, [
                'content' => $content,
                'channels' => ['in_app', 'sms'],
                'target_roles' => [],
                'send_to_all' => true,
                'template' => null,
                'force_delivery' => false,
            ], $targets, $sentAt);
            $count++;

            $rows = Communication::query()->where('broadcast_group_id', $group)->orderBy('id')->get();
            foreach ($rows->where('channel', 'in_app')->values() as $index => $row) {
                DeliverBroadcastCommunicationJob::dispatchSync((int) $row->id);
                $row->refresh();
                if ($row->status !== 'delivered') {
                    continue;
                }
                $row->forceFill(['delivered_at' => $sentAt->copy()->addSeconds(15 + $index)])->save();
                $noticeId = is_array($row->broadcast_outcome) ? ($row->broadcast_outcome['recipient_notification_id'] ?? null) : null;
                if ($noticeId && $index < $readers) {
                    DB::table('notifications')->where('id', $noticeId)->update([
                        'read_at' => $sentAt->copy()->addMinutes(2 + $index * 3),
                    ]);
                }
            }
            foreach ($rows->where('channel', 'sms')->values() as $index => $row) {
                $status = $smsOutcomes[$index % count($smsOutcomes)];
                $row->forceFill([
                    'status' => $status,
                    'status_detail' => $status === 'failed' ? 'Number not reachable' : null,
                    'delivered_at' => $status === 'delivered' ? $sentAt->copy()->addSeconds(30 + $index) : null,
                ])->save();
            }
        };
        $broadcast(
            'Power cut at Aurora House. Generator is on — check medication fridges and call in when done.',
            $now->copy()->subMinutes(40),
            ['delivered', 'delivered', 'failed', 'delivered', 'sent'],
            3,
        );
        $broadcast(
            'Heavy rain warning for Raglan this afternoon. Outings to the coast are cancelled.',
            $now->copy()->subHours(6),
            ['delivered'],
            5,
        );

        // A response conversation about the safe-zone alert.
        $kahuAlert = ControlRoomAlert::query()->where('reference_number', self::REF_PREFIX.'0002')->first();
        foreach ([
            ['outbound', 'Kahu has left the safe zone near the river path. Can you check the gate and call me?', 8],
            ['inbound', 'On my way out now, will ring in two minutes.', 7],
        ] as [$direction, $content, $minutesAgo]) {
            Communication::create([
                'alert_id' => $kahuAlert?->id,
                'channel' => 'in_app',
                'direction' => $direction,
                'purpose' => 'update',
                'target_user_id' => $owner(2),
                'content' => $content,
                'template_used' => self::MESSAGE_TAG,
                'status' => 'delivered',
                'sent_at' => $now->copy()->subMinutes($minutesAgo),
                'delivered_at' => $now->copy()->subMinutes($minutesAgo),
                'initiated_by_user_id' => $direction === 'outbound' ? $owner(0) : $owner(2),
            ]);
            $count++;
        }

        return $count;
    }
}
