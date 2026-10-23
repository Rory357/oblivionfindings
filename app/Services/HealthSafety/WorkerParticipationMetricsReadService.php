<?php

namespace App\Services\HealthSafety;

use App\Models\User;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use LogicException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/** Participation operands retain the original source scope through the outer response proof. */
final class WorkerParticipationMetricsReadService
{
    private const SCOPE = 'current_readable_sources';

    public function __construct(private readonly WorkerParticipationAccessService $access) {}

    public function overview(?User $viewer, int|array|null $requestedSites = null, ?HealthSafetyReadContext $context = null): array
    {
        return $this->consume($viewer, $requestedSites, $context, ['pct' => null, 'committees' => null], function (HealthSafetyReadContext $active) use ($requestedSites): array {
            $totalSites = $this->access->siteDenominator($active, $requestedSites === null ? null : (array) $requestedSites);
            // Preserve the existing definition: any retained representative, independent of status.
            $sitesWithRep = $this->scope($this->access->query('representatives', $active), $requestedSites)
                ->whereNotNull('site_id')->distinct('site_id')->count('site_id');
            return ['pct' => $totalSites > 0 ? (int) round($sitesWithRep / $totalSites * 100) : null,
                'committees' => $this->scope($this->access->query('committees', $active), $requestedSites)->count()];
        });
    }

    /** Absolute instant bounds by default; date-filter callers opt into inclusive worker civil days. */
    public function monthly(?User $viewer, CarbonInterface $from, CarbonInterface $to, int|array|null $requestedSites = null, ?HealthSafetyReadContext $context = null, bool $workerCivilDates = false): array
    {
        return $this->consume($viewer, $requestedSites, $context, ['engagement' => null, 'consultation' => null], function (HealthSafetyReadContext $active) use ($requestedSites, $from, $to, $workerCivilDates): array {
            $zone = (string) (config('app.worker_timezone') ?: config('app.timezone', 'UTC'));
            $start = $workerCivilDates ? CarbonImmutable::parse($from->format('Y-m-d'), $zone)->startOfDay()->utc() : CarbonImmutable::instance($from)->utc();
            $end = $workerCivilDates ? CarbonImmutable::parse($to->format('Y-m-d'), $zone)->endOfDay()->utc() : CarbonImmutable::instance($to)->utc();
            $buckets = []; $bindings = [];
            for ($month = $start->setTimezone($zone)->startOfMonth(); $month <= $end->setTimezone($zone); $month = $month->addMonth()) {
                $buckets[] = 'WHEN scheduled_at >= ? AND scheduled_at < ? THEN ?';
                array_push($bindings, $month->utc()->format('Y-m-d H:i:s.u'), $month->addMonth()->utc()->format('Y-m-d H:i:s.u'), $month->format('Y-m'));
            }
            $monthSql = $buckets === [] ? 'NULL' : 'CASE '.implode(' ', $buckets).' ELSE NULL END';
            $meetings = $this->scope($this->access->query('meetings', $active), $requestedSites)
                ->whereBetween('scheduled_at', [$start->format('Y-m-d H:i:s.u'), $end->format('Y-m-d H:i:s.u')])
                ->selectRaw($monthSql." as m, SUM(status = 'completed') as done, COUNT(*) as total", $bindings)
                ->groupBy('m')->get();
            $consultations = $this->scope($this->access->query('consultations', $active), $requestedSites)
                ->whereBetween('consultation_date', [$from->format('Y-m-d'), $to->format('Y-m-d')])
                ->selectRaw("DATE_FORMAT(consultation_date, '%Y-%m') as m, SUM(status IN ('actioned', 'closed')) as done, COUNT(*) as total")
                ->groupBy('m')->get();
            $ratios = static function ($rows): array {
                $result = [];
                foreach ($rows as $row) { $result[$row->m] = (int) $row->total > 0 ? (int) round((int) $row->done / (int) $row->total * 100) : null; }
                return $result;
            };
            return ['engagement' => $ratios($meetings), 'consultation' => $ratios($consultations)];
        });
    }

    private function consume(?User $viewer, int|array|null $requestedSites, ?HealthSafetyReadContext $context, array $withheld, callable $operation): array
    {
        if ($viewer === null) {
            if ($context !== null) { throw new LogicException('A supplied participation metrics context requires its viewer.'); }
            return [...$withheld, 'source_access' => 'restricted', 'scope' => self::SCOPE, 'viewer_id' => null];
        }
        try {
            $consume = fn ($current, $reads, $active): array => [...$operation($active), 'source_access' => 'readable', 'scope' => self::SCOPE, 'viewer_id' => (int) $current->id];
            return is_array($requestedSites)
                ? $this->access->materializeSubset($viewer, $requestedSites, $consume, $context)
                : $this->access->materialize($viewer, $consume, $context, $requestedSites);
        } catch (HttpExceptionInterface $error) {
            if (! in_array($error->getStatusCode(), [403, 404], true)) { throw $error; }
            if ($context !== null) { $context->assertActive(); $context->assertViewer($viewer); }
            return [...$withheld, 'source_access' => $error->getStatusCode() === 403 ? 'restricted' : 'unavailable', 'scope' => self::SCOPE, 'viewer_id' => null];
        }
    }

    private function scope(Builder $query, int|array|null $requestedSites): Builder
    {
        if ($requestedSites === null) { return $query; }
        return $query->getModel()->getTable() === 'hs_committee_meetings'
            ? $query->whereHas('committee', fn (Builder $parent) => $parent->whereIn('site_id', (array) $requestedSites))
            : $query->whereIn('site_id', (array) $requestedSites);
    }
}
