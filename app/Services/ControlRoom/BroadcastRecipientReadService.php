<?php

namespace App\Services\ControlRoom;

use App\Domain\Hr\Services\HrCurrentStaffService;
use App\Models\AuditLog;
use App\Models\ControlRoom\BroadcastIntent;
use App\Models\ControlRoom\Communication;
use App\Models\User;
use App\Notifications\ControlRoom\BroadcastRecipientNotice;
use App\Support\ControlRoom\BroadcastCommandInput;
use Closure;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Illuminate\Contracts\Support\Responsable;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use LogicException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use WeakMap;

/**
 * Reads staff broadcast messages (BroadcastRecipientNotice) for the person they were
 * sent to — and nobody else.
 *
 * A notice is admitted only when ALL of these hold right now:
 *  - the viewer is the notice's own receiver AND the Communication's target;
 *  - the viewer is current approved staff with an active, in-date HR record;
 *  - when the broadcast picked roles, the viewer still holds one of them;
 *  - the notice, Communication, intent ledger and availability audit still link exactly
 *    (one notice per Communication, unchanged content digest, "available" outcome).
 * Site transfers and the sender leaving do not revoke it: it is a personal message.
 *
 * Every other caller sees no broadcast notices at all: exclude() is the default for
 * BoardPackAccessService::visibleNotificationQuery() and generic read/acknowledge writes.
 * Read producers opt in explicitly with read() / respond() and pass the context on.
 */
final class BroadcastRecipientReadService
{
    public const AVAILABILITY_ACTION = BroadcastRecipientAvailabilityService::AUDIT_ACTION;

    /** @var WeakMap<BroadcastRecipientReadContext, object> */
    private WeakMap $frames;

    /** @var WeakMap<BroadcastRecipientReadBatch, object> */
    private WeakMap $batches;

    /** @var WeakMap<Request, BroadcastRecipientReadContext>|null */
    private static ?WeakMap $responses = null;

    public function __construct()
    {
        $this->frames = new WeakMap;
        $this->batches = new WeakMap;
    }

    /** The reader context of the response currently being produced for this viewer, if any. Never manufactured. */
    public static function current(User $viewer): ?BroadcastRecipientReadContext
    {
        $context = self::$responses[request()] ?? null;
        if ($context === null || ! $context->owner->isActiveFor($context, $viewer)) {
            return null;
        }

        return $context;
    }

    /** Produce a plain array (no models, builders, paginators or closures) under one reader context. */
    public function read(User $viewer, Closure $produce): array
    {
        if (($outer = self::current($viewer)) !== null) {
            $result = $produce($outer);
            self::plain($result);

            return $result;
        }
        $context = $this->open($viewer);
        try {
            $result = $produce($context);
            self::plain($result);
            $this->finalProof($context);

            return $result;
        } finally {
            $this->frames[$context]->active = false;
        }
    }

    /** For typed read commands: a separate owner, current-staff check before and after the producer. */
    public function commandRead(User $viewer, Closure $produce): array
    {
        if (self::current($viewer) !== null) {
            throw new LogicException('Broadcast command requires a distinct non-response owner.');
        }
        $context = $this->open($viewer);
        $this->frames[$context]->commandActor = true;
        try {
            abort_unless(app(HrCurrentStaffService::class)->isCurrent($viewer), 403);
            $result = $produce($context);
            self::plain($result);
            $this->finalProof($context);

            return $result;
        } finally {
            $this->frames[$context]->active = false;
        }
    }

    /**
     * Produce and fully materialise a response (including Inertia props) under one reader
     * context, then re-check every broadcast it showed. Status, body, cookies and headers
     * are returned unchanged. Nested respond()/read() for the same viewer reuse the context.
     */
    public function respond(User $viewer, Request $request, Closure $produce): Response
    {
        if ($request !== request()) {
            throw new LogicException('Broadcast response owner cannot transfer.');
        }
        if (($outer = self::current($viewer)) !== null) {
            return $this->materialize($produce($outer), $request);
        }
        $context = $this->open($viewer);
        self::$responses ??= new WeakMap;
        self::$responses[$request] = $context;
        try {
            $response = $this->materialize($produce($context), $request);
            $this->finalProof($context);

            return $response;
        } finally {
            unset(self::$responses[$request]);
            $this->frames[$context]->active = false;
        }
    }

    /**
     * Whether $id is one of the viewer's own broadcast notices (current or ever linked).
     * Lets generic handlers answer their own staff message without a write, while a
     * foreign or unknown ID stays indistinguishable (404).
     */
    public function isOwnBroadcastNotice(User $viewer, string $id): bool
    {
        return in_array($id, $this->census($viewer), true);
    }

    /** The default, no-context path: the viewer's notifications without any broadcast notice. */
    public function exclude(MorphMany $query, User $viewer): MorphMany
    {
        $this->ownRelation($query, $viewer);
        $ids = $this->census($viewer);
        $query->where(fn (EloquentBuilder $ordinary) => $this->ordinary($ordinary->getQuery(), $ids));

        return $query;
    }

    /** Ordinary notifications plus this viewer's currently admitted broadcast notices. */
    public function scope(BroadcastRecipientReadContext $context, MorphMany $query): MorphMany
    {
        $frame = $this->frame($context);
        $this->ownRelation($query, $frame->viewer);
        $admitted = array_keys($frame->facts);
        $query->where(function (EloquentBuilder $visible) use ($frame, $admitted): void {
            $visible->where(fn (EloquentBuilder $ordinary) => $this->ordinary($ordinary->getQuery(), $frame->privateIds));
            if ($admitted !== []) {
                $visible->orWhere(function (EloquentBuilder $typed) use ($admitted, $frame): void {
                    $typed->where('notifications.type', BroadcastRecipientNotice::class)
                        ->where('notifications.notifiable_type', $frame->morph)
                        ->where('notifications.notifiable_id', $frame->viewerId)
                        ->whereIn('notifications.id', $admitted);
                });
            }
        });

        return $query;
    }

    /**
     * The canonical DTO for an admitted broadcast notice, or null for a genuinely ordinary
     * notification. A broadcast notice this viewer may not see is a 404 — never null, so it
     * can't fall back to being shown as an ordinary row.
     */
    public function project(BroadcastRecipientReadContext $context, DatabaseNotification $notice): ?array
    {
        $frame = $this->frame($context);
        $id = (string) $notice->getKey();
        $marked = in_array($id, $frame->privateIds, true)
            || $notice->getAttribute('type') === BroadcastRecipientNotice::class
            || (is_array($notice->getAttribute('data')) && ($notice->getAttribute('data')['kind'] ?? null) === 'broadcast_recipient');
        if (! $marked) {
            return null;
        }
        abort_unless(isset($frame->facts[$id]), 404);
        $facts = $frame->facts[$id];
        $frame->emitted[$id] = true;

        return [
            'id' => $id,
            'type' => 'BroadcastRecipientNotice',
            'created_at' => $facts['created_at'],
            'read_at' => $facts['read_at'],
            'acknowledged_at' => null,
            'escalation_count' => 0,
            'data' => [
                'schema_version' => 1,
                'type' => 'broadcast_recipient',
                'module' => 'control_room',
                'intent_id' => $facts['reference']['intent_id'],
                'communication_id' => $facts['reference']['communication_id'],
                'recipient_viewer_id' => $frame->viewerId,
                'reader_read_id' => $frame->readId,
                'title' => 'Staff message',
                'body' => $facts['body'],
                'url' => '/notifications?notification='.$id,
                'ack_required' => false,
            ],
        ];
    }

    public function envelope(BroadcastRecipientReadContext $context): array
    {
        $frame = $this->frame($context);

        return [
            'schema_version' => 1,
            'viewer_id' => $frame->viewerId,
            'read_id' => $frame->readId,
            'checked_at' => $frame->checkedAt,
            'capabilities' => [
                'mark_read' => $frame->eligible,
                'acknowledge' => false,
                'bulk_mark_read' => $frame->eligible,
            ],
        ];
    }

    /** One admitted broadcast notice by canonical UUID, independent of any list filter or page. */
    public function selected(BroadcastRecipientReadContext $context, string $id): array
    {
        abort_unless(BroadcastCommandInput::canonicalUuid($id), 404);
        $frame = $this->frame($context);
        abort_unless(isset($frame->facts[$id]), 404);
        $notice = $frame->viewer->notifications()->whereKey($id)->where('type', BroadcastRecipientNotice::class)->first();
        abort_unless($notice !== null, 404);

        return $this->project($context, $notice) ?? abort(404);
    }

    /** The viewer's admitted unread broadcast notices, oldest first (created_at, then id), at most 200. */
    public function unreadBatch(BroadcastRecipientReadContext $context, int $maximum = 200): BroadcastRecipientReadBatch
    {
        if ($maximum < 1 || $maximum > 200) {
            throw new LogicException('Broadcast read batches are bounded to 200.');
        }
        $frame = $this->frame($context);
        $ids = array_keys($frame->facts);
        $rows = $ids === [] ? collect() : $frame->viewer->notifications()
            ->where('type', BroadcastRecipientNotice::class)
            ->whereIn('id', $ids)
            ->whereNull('read_at')
            ->reorder()->orderBy('created_at')->orderBy('id')
            ->limit($maximum)->pluck('id');

        return $this->batch($context, $rows->map(fn ($id) => (string) $id)->all());
    }

    public function singleBatch(BroadcastRecipientReadContext $context, string $id): BroadcastRecipientReadBatch
    {
        $this->selected($context, $id);

        return $this->batch($context, [$id]);
    }

    /**
     * Rebuild a batch from exported references: every member must still be admitted with unchanged evidence.
     * The exact key set is required but not key order: a MySQL JSON column returns object keys reordered.
     */
    public function restoreBatch(BroadcastRecipientReadContext $context, array $references): BroadcastRecipientReadBatch
    {
        if (self::sortedKeys($references) !== ['records', 'version'] || $references['version'] !== 1
            || ! is_array($references['records']) || ! array_is_list($references['records']) || count($references['records']) > 200) {
            throw new LogicException('Invalid original broadcast read batch.');
        }
        $ids = [];
        foreach ($references['records'] as $reference) {
            if (! is_array($reference) || self::sortedKeys($reference) !== ['first_read_at', 'id', 'sha256']
                || ! BroadcastCommandInput::canonicalUuid($reference['id']) || isset($ids[$reference['id']])
                || ! is_string($reference['sha256']) || preg_match('/^[a-f0-9]{64}$/D', $reference['sha256']) !== 1
                || ! self::isoOrNull($reference['first_read_at'])) {
                throw new LogicException('Invalid original broadcast read reference.');
            }
            $ids[$reference['id']] = ['id' => $reference['id'], 'sha256' => $reference['sha256'], 'first_read_at' => $reference['first_read_at']];
        }
        // Keep the exported shape canonical whatever order the stored copy came back in.
        $references = ['version' => 1, 'records' => array_values($ids)];
        $current = $this->admit($this->frame($context)->viewer, array_keys($ids));
        foreach ($ids as $id => $reference) {
            abort_unless(isset($current[$id]), 404);
            if (! hash_equals($reference['sha256'], $current[$id]['sha256'])) {
                throw new LogicException('Original broadcast evidence changed.');
            }
            if ($reference['first_read_at'] !== null && $reference['first_read_at'] !== $current[$id]['read_at']) {
                throw new LogicException('Original broadcast first read changed.');
            }
        }
        $batch = new BroadcastRecipientReadBatch($context);
        $this->batches[$batch] = (object) ['ids' => array_keys($ids), 'export' => $references, 'expected' => []];

        return $batch;
    }

    /** The batch's notification rows, re-admitted and locked for update, in batch order. */
    public function batchRecords(BroadcastRecipientReadBatch $batch): Collection
    {
        $data = $this->batchData($batch);
        $frame = $this->frame($batch->context);
        if ($data->ids === []) {
            return collect();
        }
        $current = $this->admit($frame->viewer, $data->ids);
        $rows = DatabaseNotification::query()->whereIn('id', $data->ids)
            ->where('notifiable_type', $frame->morph)->where('notifiable_id', $frame->viewerId)
            ->where('type', BroadcastRecipientNotice::class)
            ->orderBy('id')->lockForUpdate()->get()->keyBy(fn ($row) => (string) $row->getKey());

        return collect($data->ids)->map(function (string $id) use ($current, $rows): DatabaseNotification {
            abort_unless(isset($current[$id], $rows[$id]), 404);

            return $rows[$id];
        });
    }

    public function batchReferences(BroadcastRecipientReadBatch $batch): array
    {
        return $this->batchData($batch)->export;
    }

    /** Fix the read_at each member must end with: its existing first read, or this command's server time. */
    public function expectReadAt(BroadcastRecipientReadBatch $batch, DateTimeImmutable $serverAt): void
    {
        $data = $this->batchData($batch);
        $current = $this->admit($this->frame($batch->context)->viewer, $data->ids);
        foreach ($data->ids as $id) {
            abort_unless(isset($current[$id]), 404);
            $data->expected[$id] = $current[$id]['read_at'] ?? self::iso($serverAt);
        }
        $this->frame($batch->context)->batches[] = $data;
    }

    // ───────────────────────── internals ─────────────────────────

    private function batch(BroadcastRecipientReadContext $context, array $ids): BroadcastRecipientReadBatch
    {
        if (count($ids) > 200 || count(array_unique($ids)) !== count($ids)) {
            throw new LogicException('Invalid bounded broadcast batch.');
        }
        $current = $ids === [] ? [] : $this->admit($this->frame($context)->viewer, $ids);
        $export = ['version' => 1, 'records' => []];
        foreach ($ids as $id) {
            abort_unless(isset($current[$id]), 404);
            $export['records'][] = ['id' => $id, 'sha256' => $current[$id]['sha256'], 'first_read_at' => $current[$id]['read_at']];
        }
        $batch = new BroadcastRecipientReadBatch($context);
        $this->batches[$batch] = (object) ['ids' => $ids, 'export' => $export, 'expected' => []];

        return $batch;
    }

    private function batchData(BroadcastRecipientReadBatch $batch): object
    {
        $this->frame($batch->context);

        return $this->batches[$batch] ?? throw new LogicException('Broadcast batch is not owned by this reader.');
    }

    private function open(User $viewer): BroadcastRecipientReadContext
    {
        if ((int) (auth()->id() ?? 0) !== (int) $viewer->getKey()) {
            throw new LogicException('Broadcast reads belong to the authenticated viewer.');
        }
        $context = new BroadcastRecipientReadContext($this);
        $privateIds = $this->census($viewer);
        $eligible = app(HrCurrentStaffService::class)->isCurrent($viewer);
        $this->frames[$context] = (object) [
            'active' => true,
            'viewer' => $viewer,
            'viewerId' => (int) $viewer->getKey(),
            'morph' => $viewer->getMorphClass(),
            'readId' => (string) Str::uuid(),
            'checkedAt' => self::iso(new DateTimeImmutable('now', new DateTimeZone('UTC'))),
            'eligible' => $eligible,
            'privateIds' => $privateIds,
            'facts' => $eligible ? $this->admit($viewer, $privateIds) : [],
            'emitted' => [],
            'batches' => [],
            'commandActor' => false,
        ];

        return $context;
    }

    public function isActiveFor(BroadcastRecipientReadContext $context, User $viewer): bool
    {
        $frame = $this->frames[$context] ?? null;

        return $frame !== null && $frame->active && $frame->viewerId === (int) $viewer->getKey()
            && $frame->morph === $viewer->getMorphClass();
    }

    private function frame(BroadcastRecipientReadContext $context): object
    {
        $frame = $this->frames[$context] ?? throw new LogicException('Broadcast context is not an original reader frame.');
        if (! $frame->active || (int) (auth()->id() ?? 0) !== $frame->viewerId) {
            throw new LogicException('Broadcast reader lifetime ended or changed receiver.');
        }

        return $frame;
    }

    /**
     * Re-check, at the end of the response or command, every broadcast notice that was shown
     * or acted on: still admitted with unchanged evidence, and (for commands) the exact read_at.
     */
    private function finalProof(BroadcastRecipientReadContext $context): void
    {
        $frame = $this->frame($context);
        if ($frame->commandActor) {
            abort_unless(app(HrCurrentStaffService::class)->isCurrent($frame->viewer), 403);
        }
        $ids = array_keys($frame->emitted);
        foreach ($frame->batches as $batch) {
            $ids = array_merge($ids, $batch->ids);
        }
        $ids = array_values(array_unique($ids));
        if ($ids === []) {
            return;
        }
        $current = $this->admit($frame->viewer, $ids);
        foreach ($ids as $id) {
            abort_unless(isset($current[$id]), 404, 'Broadcast recipient evidence changed.');
            if (isset($frame->facts[$id])) {
                abort_unless(hash_equals($frame->facts[$id]['sha256'], $current[$id]['sha256']), 404, 'Broadcast recipient evidence changed.');
            }
        }
        foreach ($frame->batches as $batch) {
            foreach ($batch->expected as $id => $readAt) {
                abort_unless(($current[$id]['read_at'] ?? null) === $readAt, 409, 'Broadcast read effect changed.');
            }
        }
    }

    /**
     * Admit the viewer's broadcast notices among $ids, in a fixed number of queries.
     *
     * @param  list<string>  $ids
     * @return array<string, array{reference:array,body:string,created_at:string,read_at:?string,sha256:string}>
     */
    private function admit(User $viewer, array $ids): array
    {
        $ids = array_values(array_filter($ids, fn ($id) => BroadcastCommandInput::canonicalUuid($id)));
        if ($ids === [] || ! app(HrCurrentStaffService::class)->isCurrent($viewer)) {
            return [];
        }
        $viewerId = (int) $viewer->getKey();
        $notices = DatabaseNotification::query()->whereIn('id', $ids)
            ->where('type', BroadcastRecipientNotice::class)
            ->where('notifiable_type', $viewer->getMorphClass())->where('notifiable_id', $viewerId)
            ->get()->keyBy(fn ($n) => (string) $n->getKey());

        $references = [];
        foreach ($notices as $id => $notice) {
            $ref = $notice->data;
            if (! is_array($ref) || $notice->acknowledged_at !== null) {
                continue;
            }
            $keys = array_keys($ref);
            sort($keys);
            if ($keys !== ['communication_id', 'intent_id', 'kind', 'schema_version'] || $ref['schema_version'] !== 1
                || $ref['kind'] !== 'broadcast_recipient' || ! BroadcastCommandInput::canonicalUuid($ref['intent_id'])
                || ! is_int($ref['communication_id']) || $ref['communication_id'] < 1 || $ref['communication_id'] > 9007199254740991) {
                continue;
            }
            $references[$id] = $ref;
        }
        if ($references === []) {
            return [];
        }

        $communications = Communication::query()
            ->whereIn('id', array_column($references, 'communication_id'))
            ->where('target_user_id', $viewerId)->where('purpose', 'broadcast')->where('channel', 'in_app')
            ->get()->keyBy(fn ($c) => (int) $c->getKey());
        $intents = BroadcastIntent::query()->whereIn('id', array_values(array_unique(array_column($references, 'intent_id'))))
            ->get()->keyBy(fn ($i) => (string) $i->getKey());
        $roles = $viewer->roles()->pluck('name')->all();
        $audits = AuditLog::query()->where('action', self::AVAILABILITY_ACTION)
            ->where('auditable_type', (new Communication)->getMorphClass())
            ->whereIn('auditable_id', $communications->keys()->all())
            ->whereNull('user_id')->whereNull('client_id')
            ->get()->groupBy(fn ($a) => (int) $a->auditable_id);
        $copies = DatabaseNotification::query()->where('type', BroadcastRecipientNotice::class)
            ->whereIn('data->communication_id', $communications->keys()->all())
            ->get(['id', 'data'])->groupBy(fn ($n) => (int) ($n->data['communication_id'] ?? 0));

        $admitted = [];
        foreach ($references as $id => $ref) {
            $row = $communications[$ref['communication_id']] ?? null;
            $intent = $intents[$ref['intent_id']] ?? null;
            if (! $row || ! $intent || (string) $row->broadcast_group_id !== (string) $intent->getKey()) {
                continue;
            }
            $captured = $intent->captured_intent;
            $outcome = is_array($row->broadcast_outcome) ? $row->broadcast_outcome : [];
            if (! is_array($captured) || ! hash_equals((string) $intent->payload_digest, BroadcastCommandInput::digestIntent($captured))
                || (string) $row->content !== (string) $captured['content']
                || (int) $row->initiated_by_user_id !== (int) $intent->actor_id
                || $row->delivery_key !== BroadcastRecipientLedger::memberKey((string) $intent->getKey(), $viewerId, 'in_app')
                || $row->broadcast_delivery_state !== 'in_app_available' || (int) $row->broadcast_attempt_count !== 1
                || ! BroadcastCommandInput::canonicalUuid($row->broadcast_attempt_token)
                || ($outcome['availability'] ?? null) !== 'available'
                || ($outcome['recipient_notification_id'] ?? null) !== $id) {
                continue;
            }
            if (! $captured['send_to_all'] && array_intersect($captured['target_roles'], $roles) === []) {
                continue;
            }
            $notice = $notices[$id];
            $availableAt = $outcome['recipient_available_at'] ?? null;
            if (! is_string($availableAt) || $availableAt !== self::iso($notice->created_at)) {
                continue;
            }
            $audit = $audits[(int) $row->getKey()] ?? collect();
            $expectedMeta = BroadcastRecipientAvailabilityService::auditMeta($intent, $row, $id, $ref);
            if ($audit->count() !== 1 || self::json($audit->first()->meta) !== self::json($expectedMeta)) {
                continue;
            }
            $copy = $copies[(int) $row->getKey()] ?? collect();
            if ($copy->count() !== 1 || (string) $copy->first()->getKey() !== $id) {
                continue;
            }
            $admitted[$id] = [
                'reference' => $ref,
                'body' => (string) $row->content,
                'created_at' => self::iso($notice->created_at),
                'read_at' => self::iso($notice->read_at),
                'sha256' => hash('sha256', self::json([
                    'id' => $id, 'receiver' => [$notice->notifiable_type, (int) $notice->notifiable_id],
                    'reference' => $ref, 'created_at' => self::iso($notice->created_at),
                    'communication' => [(int) $row->getKey(), $row->delivery_key, (string) $row->broadcast_attempt_token, hash('sha256', (string) $row->content)],
                    'intent' => [(string) $intent->getKey(), (int) $intent->actor_id, (string) $intent->payload_digest, (string) $intent->selection_target_digest],
                    'audit' => [(int) $audit->first()->getKey(), $audit->first()->meta],
                ])),
            ];
        }

        return $admitted;
    }

    /**
     * IDs of the viewer's own notifications that are, or were ever linked as, broadcast
     * notices — so they can never be shown or written as ordinary rows, even if retyped.
     *
     * @return list<string>
     */
    private function census(User $viewer): array
    {
        $viewerId = (int) $viewer->getKey();
        $ids = DatabaseNotification::query()
            ->where('notifiable_type', $viewer->getMorphClass())->where('notifiable_id', $viewerId)
            ->where(fn (EloquentBuilder $marked) => $this->marker($marked->getQuery()))
            ->pluck('id')->map(fn ($id) => (string) $id)->all();
        foreach (Communication::query()->where('target_user_id', $viewerId)->where('purpose', 'broadcast')
            ->where('channel', 'in_app')->whereNotNull('broadcast_outcome')->pluck('broadcast_outcome') as $outcome) {
            $linked = is_array($outcome) ? ($outcome['recipient_notification_id'] ?? null) : null;
            if (BroadcastCommandInput::canonicalUuid($linked)) {
                $ids[] = $linked;
            }
        }
        $ids = array_values(array_unique($ids));
        sort($ids);

        return $ids;
    }

    private function ordinary(Builder $query, array $ids): void
    {
        if ($ids !== []) {
            $query->whereNotIn('notifications.id', $ids);
        }
        $query->whereNot(fn (Builder $marked) => $this->marker($marked));
    }

    private function marker(Builder $query): void
    {
        $json = "CASE WHEN JSON_VALID(notifications.data) THEN notifications.data ELSE '{}' END";
        $query->where('notifications.type', BroadcastRecipientNotice::class)
            ->orWhereRaw("COALESCE(JSON_UNQUOTE(JSON_EXTRACT($json, '$.kind')), '') = ?", ['broadcast_recipient']);
    }

    private function ownRelation(MorphMany $query, User $viewer): void
    {
        $parent = $query->getParent();
        if (! $parent instanceof User || ! $query->getRelated() instanceof DatabaseNotification
            || (int) $parent->getKey() !== (int) $viewer->getKey() || $parent->getMorphClass() !== $viewer->getMorphClass()
            || $query->getQuery()->getQuery()->from !== 'notifications') {
            throw new LogicException('Broadcast requires the canonical own notification relation.');
        }
    }

    private function materialize(mixed $result, Request $request): Response
    {
        $response = $result instanceof Responsable ? $result->toResponse($request) : $result;
        if (! $response instanceof Response || $response instanceof StreamedResponse || $response instanceof BinaryFileResponse) {
            throw new LogicException('Broadcast response requires completed materialization.');
        }

        return $response;
    }

    private static function plain(mixed $value): void
    {
        if (is_array($value)) {
            foreach ($value as $item) {
                self::plain($item);
            }

            return;
        }
        if ($value !== null && ! is_scalar($value)) {
            throw new LogicException('Broadcast array read cannot retain lazy or ORM values.');
        }
    }

    public static function json(mixed $value): string
    {
        $normalize = function (mixed $item) use (&$normalize): mixed {
            if ($item instanceof DateTimeInterface) {
                return self::iso($item);
            }
            if (is_array($item)) {
                if (! array_is_list($item)) {
                    ksort($item);
                }
                foreach ($item as &$nested) {
                    $nested = $normalize($nested);
                }
            }

            return $item;
        };

        return json_encode($normalize($value), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    public static function iso(?DateTimeInterface $value): ?string
    {
        return $value === null ? null : DateTimeImmutable::createFromInterface($value)->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.000000\Z');
    }

    /** @return list<int|string> */
    private static function sortedKeys(array $value): array
    {
        $keys = array_keys($value);
        sort($keys);

        return $keys;
    }

    private static function isoOrNull(mixed $value): bool
    {
        return $value === null || (is_string($value) && preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}\.000000Z$/D', $value) === 1);
    }
}
