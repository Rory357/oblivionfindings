<?php

namespace App\Services\HealthSafety;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;
use LogicException;
use WeakReference;

/** Private request-bound equality evidence; neither a saved grant nor an escaped source builder. */
final readonly class WorkerParticipationRecipientSourceOrigin
{
    private function __construct(
        private int $receiverId,
        private int $meetingId,
        private string $sourceSql,
        private array $bindings,
        private string $timezone,
        private string $day,
        private WeakReference $request,
        private ?\Closure $originalLifetime,
    ) {}

    /** Apply only to the actual canonical meeting block inside the caller's independently scoped query. */
    public function applySourcePredicate(Builder|QueryBuilder $actualQuery, string $canonicalMeetingAlias = 'hs_committee_meetings'): Builder|QueryBuilder
    {
        $this->assertReceiver();
        $base = $actualQuery instanceof Builder ? $actualQuery->getQuery() : $actualQuery;
        $from = (string) $base->from;
        if (! preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $canonicalMeetingAlias)
            || ! in_array($from, ['hs_committee_meetings', 'hs_committee_meetings as '.$canonicalMeetingAlias], true)) {
            throw new LogicException('Recipient origin must qualify the canonical meeting query block.');
        }
        $actualQuery->where($canonicalMeetingAlias.'.id', $this->meetingId)->whereRaw('EXISTS ('.$this->sourceSql.')', $this->bindings);
        $actualQuery->whereRaw(new class(fn () => $this->assertReceiver()) extends \Illuminate\Database\Query\Expression {
            public function __construct(private readonly \Closure $assert) { parent::__construct('1 = 1'); }
            public function getValue(\Illuminate\Database\Grammar $grammar) { ($this->assert)(); return parent::getValue($grammar); }
        });
        $base->beforeQuery(fn () => $this->assertReceiver());
        $base->afterQuery(function ($rows) { $this->assertOriginal(); return $rows; });
        return $actualQuery;
    }

    /** Replays fixed original equality, never discovers a replacement actor/source baseline. */
    public function assertOriginal(): void
    {
        $this->assertReceiver();
        $result = DB::selectOne('SELECT EXISTS ('.$this->sourceSql.') AS admitted', $this->bindings);
        abort_unless((bool) ($result->admitted ?? false), 404, 'Meeting invitation source is no longer available.');
    }

    private function assertReceiver(): void
    {
        if ($this->originalLifetime !== null) { ($this->originalLifetime)(); }
        if ($this->request->get() !== request() || (int) (request()->user()?->id ?? 0) !== $this->receiverId
            || (int) (auth()->id() ?? 0) !== $this->receiverId) {
            throw new LogicException('Recipient origin belongs to its original authenticated request receiver.');
        }
        $zone = (string) (config('app.worker_timezone') ?: config('app.timezone') ?: 'UTC');
        abort_unless($zone === $this->timezone && now($zone)->toDateString() === $this->day, 404, 'Original recipient date evidence changed.');
    }
}
