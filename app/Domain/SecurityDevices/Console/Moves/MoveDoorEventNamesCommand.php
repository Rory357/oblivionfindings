<?php

namespace App\Domain\SecurityDevices\Console\Moves;

use App\Domain\SecurityDevices\Models\DeviceEvent;
use App\Models\Integration\IntegrationEvent;
use App\Models\TimelineEvent;
use App\Models\User;
use App\Services\AuditLogger;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * S&D data move "Names on old door events older than 90 days" (D14; plan: "Names older than 90 days
 * are cleared only after your yes and a backup").
 *
 * Today's door events keep the person's name in three places:
 *  - integration_events: normalized_payload.actor_name, and the name inside normalized_payload.summary
 *    (UniFi Access events, UnifiAdapter::normalizeAccessLogEntry);
 *  - timeline_events of type "unifi_access": meta.user, and the name inside subject and body
 *    (SiteIntegrationController::syncAccessEvents);
 *  - device_events: payload.actor_name (read by the door page).
 *
 * The dry run counts the rows older than 90 days that still hold a name. Clearing them cannot be
 * undone, so it needs `--apply --i-have-a-backup`. It removes the name and rewrites the summary
 * without it ("ACCESS at Front door"); the rest of each row stays. It writes through the query builder,
 * so no model-level audit copies the old name into the audit log; each row gets one audit entry
 * without the name. The new Access tables clear their own names (UnifiAccessSync::forgetOldNames)
 * and are not touched here.
 */
final class MoveDoorEventNamesCommand extends DataMoveCommand
{
    protected $signature = 'security-devices:moves:door-event-names
        {--apply : Clear the names. It can’t be undone, so it also needs --i-have-a-backup}
        {--i-have-a-backup : Confirm a full backup was taken and MASTER said OK, before clearing}
        {--actor= : The user id every change is recorded against (needed with --apply)}
        {--force : Let --apply run in production}';

    protected $description = 'S&D data move (D14): count, and with --apply --i-have-a-backup clear, names on door events older than 90 days';

    private const string MOVE = 'door_event_names';

    private const int KEEP_DAYS = 90;

    private const int CHUNK = 200;

    public function handle(): int
    {
        if ($this->applying() && ! $this->option('i-have-a-backup')) {
            $this->components->error('Clearing names can’t be undone. Take a full backup, get MASTER’s OK, then run it again with --apply --i-have-a-backup.');

            return self::FAILURE;
        }
        $actor = null;
        if ($this->applying() && ! ($actor = $this->actorForApply()) instanceof User) {
            return self::FAILURE;
        }

        $cutoff = CarbonImmutable::now('UTC')->subDays(self::KEEP_DAYS);
        $sources = $this->sources($cutoff);

        $this->components->info('Names on door events older than '.self::KEEP_DAYS.' days (D14)'.($this->applying() ? '' : ' (dry run)'));
        $this->line('  Older than: '.$cutoff->setTimezone('Pacific/Auckland')->format('j M Y, g:i a').' (NZ time)');
        $rows = [];
        $total = 0;
        foreach ($sources as $table => $source) {
            $count = $source['query']()->count();
            $total += $count;
            $rows[] = [$table, $source['where'], $count];
        }
        $rows[] = ['security_access_events', 'actor_name (the new Access tables)', 'Not touched: cleared by the Access sync itself'];
        // Copies this command doesn't clear yet (waiting for Stephan's answer on D14's reach): listed so nobody thinks they're gone.
        $rows[] = ['control_room_signals', 'payload: actor_name (copied from denied or forced door events)', self::countOrNotAvailable('control_room_signals', fn ($query) => $query->where('occurred_at', '<', $cutoff)->whereNotNull('payload->actor_name')).' · not covered yet'];
        $rows[] = ['integration_events', 'raw_payload (the provider’s own copy)', self::countOrNotAvailable('integration_events', fn ($query) => $query->where('occurred_at', '<', $cutoff)->whereNotNull('raw_payload')).' · not covered yet'];
        $this->section('Rows older than '.self::KEEP_DAYS.' days that still hold a name', ['Table', 'Where the name is', 'Rows'], $rows);

        if (! $actor instanceof User) {
            $this->newLine();
            if ($total > 0) {
                $this->components->warn('Clearing can’t be undone. Only after a full backup and MASTER’s OK: --apply --i-have-a-backup --actor=<user id>.');
            }
            $this->dryRunFooter($total, "clear the names on {$total} rows (with --i-have-a-backup)");

            return self::SUCCESS;
        }

        $this->newLine();
        $this->components->warn("Clearing names on {$total} rows now. This can’t be undone except from the backup.");

        $done = [];
        foreach ($sources as $table => $source) {
            $cleared = 0;
            $source['query']()->select($source['columns'])->chunkById(self::CHUNK, function (Collection $events) use ($table, $source, $actor, &$cleared): void {
                DB::transaction(function () use ($events, $table, $source, $actor, &$cleared): void {
                    foreach ($events as $event) {
                        $changes = $source['clear']($event);
                        if ($changes === null) {
                            continue;
                        }
                        DB::table($table)->where('id', $event->getKey())->update($changes);
                        AuditLogger::logOrFail('security_devices.data_move.'.self::MOVE.'.cleared', $event, [
                            'actor_id' => (int) $actor->id,
                            'table' => $table,
                            'fields' => $source['fields'],
                        ]);
                        $cleared++;
                    }
                });
            });
            $done[] = [$table, $cleared];
        }

        $this->newLine();
        $this->components->info('Done. Each cleared row is in the audit log, without the name.');
        $this->section('Names cleared', ['Table', 'Rows'], $done);

        return self::SUCCESS;
    }

    /**
     * Each place a door event keeps a name: how to find the rows, and what to write instead.
     *
     * @return array<string, array{where: string, columns: list<string>, fields: list<string>, query: callable(): Builder<Model>, clear: callable(Model): ?array<string, mixed>}>
     */
    private function sources(CarbonImmutable $cutoff): array
    {
        return [
            'integration_events' => [
                'where' => 'normalized_payload: actor_name and summary',
                'columns' => ['id', 'site_id', 'canonical_device_id', 'occurred_at', 'normalized_payload'],
                'fields' => ['actor_name', 'summary'],
                'query' => fn (): Builder => IntegrationEvent::query()
                    ->where('occurred_at', '<', $cutoff)
                    ->whereNotNull('normalized_payload->actor_name'),
                'clear' => function (Model $event): ?array {
                    $payload = is_array($event->normalized_payload) ? $event->normalized_payload : null;
                    if ($payload === null || ! array_key_exists('actor_name', $payload)) {
                        return null;
                    }

                    return ['normalized_payload' => json_encode(self::withoutName($payload), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)];
                },
            ],
            'timeline_events' => [
                'where' => 'type unifi_access: meta.user, subject and body',
                'columns' => ['id', 'type', 'site_id', 'occurred_at', 'meta'],
                'fields' => ['user', 'subject', 'body'],
                'query' => fn (): Builder => TimelineEvent::query()
                    ->where('type', 'unifi_access')
                    ->where('occurred_at', '<', $cutoff)
                    ->whereNotNull('meta->user'),
                'clear' => function (Model $event): ?array {
                    $meta = is_array($event->meta) ? $event->meta : null;
                    if ($meta === null || ! array_key_exists('user', $meta)) {
                        return null;
                    }
                    unset($meta['user']);
                    $summary = self::summaryWithoutName($meta['direction'] ?? null, $meta['door'] ?? null);

                    return [
                        'meta' => json_encode($meta, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                        'subject' => $summary,
                        'body' => $summary,
                    ];
                },
            ],
            'device_events' => [
                'where' => 'payload: actor_name (and summary, if any)',
                'columns' => ['id', 'device_id', 'occurred_at', 'payload'],
                'fields' => ['actor_name', 'summary'],
                'query' => fn (): Builder => DeviceEvent::query()
                    ->where('occurred_at', '<', $cutoff)
                    ->whereNotNull('payload->actor_name'),
                'clear' => function (Model $event): ?array {
                    $payload = is_array($event->payload) ? $event->payload : null;
                    if ($payload === null || ! array_key_exists('actor_name', $payload)) {
                        return null;
                    }

                    return ['payload' => json_encode(self::withoutName($payload), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)];
                },
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    /** A read-only count for the dry run's "not covered" lines; "Not available" when the table or column isn't there. */
    private static function countOrNotAvailable(string $table, callable $where): string
    {
        try {
            return (string) $where(DB::table($table))->count();
        } catch (\Throwable) {
            return 'Not available';
        }
    }

    private static function withoutName(array $payload): array
    {
        unset($payload['actor_name']);
        if (array_key_exists('summary', $payload)) {
            $payload['summary'] = self::summaryWithoutName($payload['direction'] ?? null, $payload['door_name'] ?? null);
        }

        return $payload;
    }

    /** The door event in words, without who: "ACCESS at Front door (name cleared after 90 days)". */
    private static function summaryWithoutName(mixed $direction, mixed $door): string
    {
        $action = is_string($direction) && trim($direction) !== '' ? mb_strtoupper(trim($direction)) : 'ACCESS';
        $door = is_string($door) ? trim($door) : '';

        return ($door !== '' ? "{$action} at {$door}" : $action).' (name cleared after '.self::KEEP_DAYS.' days)';
    }
}
