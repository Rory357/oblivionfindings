<?php

namespace App\Domain\SecurityDevices\Console\Moves;

use App\Domain\SecurityDevices\Models\DeviceEvent;
use App\Models\ControlRoom\Signal;
use App\Models\ControlRoomAlert;
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
 * Today's door events keep the person's name in five places:
 *  - integration_events: normalized_payload.actor_name, and the name inside normalized_payload.summary
 *    (UniFi Access events, UnifiAdapter::normalizeAccessLogEntry);
 *  - timeline_events of type "unifi_access": meta.user, and the name inside subject and body
 *    (SiteIntegrationController::syncAccessEvents);
 *  - device_events: payload.actor_name (read by the door page);
 *  - control_room_signals made from those integration events (IntegrationSignalNormaliser):
 *    payload.actor_name and payload.summary, and normalized_data.description, which copies the summary
 *    (Stephan, 2 Nov: "Yes, same 90 days");
 *  - control_room_alerts opened from those signals (SignalProcessingService): context.signal_payload and
 *    context.normalized_data, the same copies. An alert counts as older than 90 days only when its last
 *    signal is too, because a later signal replaces signal_payload.
 *
 * The provider's own raw_payload is left out: verified UniFi events don't keep one
 * (ProviderEventProjector), and older copies hold the name inside the provider's nested structure,
 * not in the same simple field. The dry run still counts them.
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
        // The provider's own copy isn't cleared (see above): listed so nobody thinks it's gone.
        $rows[] = ['integration_events', 'raw_payload (the provider’s own copy)', self::countOrNotAvailable('integration_events', fn ($query) => $query->where('occurred_at', '<', $cutoff)->whereNotNull('raw_payload')).' · not covered'];
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
            'control_room_signals' => [
                'where' => 'payload: actor_name and summary; normalized_data: description (copied from door events)',
                'columns' => ['id', 'site_id', 'signal_type_code', 'occurred_at', 'payload', 'normalized_data'],
                'fields' => ['actor_name', 'summary', 'description'],
                'query' => fn (): Builder => Signal::query()
                    ->where('occurred_at', '<', $cutoff)
                    ->whereNotNull('payload->actor_name'),
                'clear' => function (Model $signal): ?array {
                    $payload = is_array($signal->payload) ? $signal->payload : null;
                    if ($payload === null || ! array_key_exists('actor_name', $payload)) {
                        return null;
                    }
                    [$cleared, $normalized] = self::withoutNameInControlRoomCopy($payload, is_array($signal->normalized_data) ? $signal->normalized_data : null);
                    $changes = ['payload' => json_encode($cleared, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)];
                    if ($normalized !== null) {
                        $changes['normalized_data'] = json_encode($normalized, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
                    }

                    return $changes;
                },
            ],
            'control_room_alerts' => [
                'where' => 'context: signal_payload actor_name and summary; normalized_data description (copied from those signals)',
                'columns' => ['id', 'site_id', 'alert_type', 'triggered_at', 'context'],
                'fields' => ['signal_payload.actor_name', 'signal_payload.summary', 'normalized_data.description'],
                'query' => fn (): Builder => ControlRoomAlert::query()
                    ->where('triggered_at', '<', $cutoff)
                    ->whereNotNull('context->signal_payload->actor_name')
                    ->where(fn (Builder $last): Builder => $last
                        ->whereNull('context->last_signal_at')
                        ->orWhere('context->last_signal_at', '<', $cutoff->toISOString())),
                'clear' => function (Model $alert) use ($cutoff): ?array {
                    $context = is_array($alert->context) ? $alert->context : null;
                    $payload = is_array($context['signal_payload'] ?? null) ? $context['signal_payload'] : null;
                    if ($payload === null || ! array_key_exists('actor_name', $payload)) {
                        return null;
                    }
                    // A later signal joined the alert: its copy isn't 90 days old yet.
                    $last = $context['last_signal_at'] ?? null;
                    if (is_string($last) && $last !== '' && CarbonImmutable::parse($last)->greaterThanOrEqualTo($cutoff)) {
                        return null;
                    }
                    [$cleared, $normalized] = self::withoutNameInControlRoomCopy($payload, is_array($context['normalized_data'] ?? null) ? $context['normalized_data'] : null);
                    $context['signal_payload'] = $cleared;
                    if ($normalized !== null) {
                        $context['normalized_data'] = $normalized;
                    }

                    return ['context' => json_encode($context, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)];
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

    /** A read-only count for the dry run's "not covered" lines; "Not available" when the table or column isn't there. */
    private static function countOrNotAvailable(string $table, callable $where): string
    {
        try {
            return (string) $where(DB::table($table))->count();
        } catch (\Throwable) {
            return 'Not available';
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private static function withoutName(array $payload): array
    {
        unset($payload['actor_name']);
        if (array_key_exists('summary', $payload)) {
            $payload['summary'] = self::summaryWithoutName($payload['direction'] ?? null, $payload['door_name'] ?? null);
        }

        return $payload;
    }

    /**
     * A Control Room copy of a door event without the name: the payload loses actor_name and its summary
     * is rewritten, and the operator-facing description, a copy of that summary, is rewritten too.
     *
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>|null  $normalized
     * @return array{0: array<string, mixed>, 1: array<string, mixed>|null} the new payload, and the new normalized data (null when it holds no name)
     */
    private static function withoutNameInControlRoomCopy(array $payload, ?array $normalized): array
    {
        $cleared = self::withoutName($payload);
        $name = is_string($payload['actor_name'] ?? null) ? trim($payload['actor_name']) : '';
        $description = $normalized['description'] ?? null;
        if ($normalized === null || ! is_string($description)
            || ($description !== ($payload['summary'] ?? null) && ($name === '' || ! str_contains($description, $name)))) {
            return [$cleared, null];
        }
        $normalized['description'] = $cleared['summary'] ?? self::summaryWithoutName($payload['direction'] ?? null, $payload['door_name'] ?? null);

        return [$cleared, $normalized];
    }

    /** The door event in words, without who: "ACCESS at Front door (name cleared after 90 days)". */
    private static function summaryWithoutName(mixed $direction, mixed $door): string
    {
        $action = is_string($direction) && trim($direction) !== '' ? mb_strtoupper(trim($direction)) : 'ACCESS';
        $door = is_string($door) ? trim($door) : '';

        return ($door !== '' ? "{$action} at {$door}" : $action).' (name cleared after '.self::KEEP_DAYS.' days)';
    }
}
