<?php

namespace App\Services\HealthSafety;

use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpKernel\Exception\HttpException;

/** First Aid aggregates use the same current subject boundary as record reads. */
final class FirstAidMetricsService
{
    private const WITHHELD_METRICS = ['activity' => ['treatments' => null, 'ambulance' => null, 'hospital' => null], 'by_type' => null, 'by_outcome' => null, 'scope' => 'current_readable_sources'];

    public function __construct(private readonly FirstAidAccessService $access) {}

    public function forViewer(?User $viewer, CarbonInterface $from, CarbonInterface $to, int|array|null $requestedSites = null, ?HealthSafetyReadContext $context = null): array
    {
        if ($viewer === null) {
            if ($context !== null) {
                throw new \LogicException('A supplied metrics context requires its viewer.');
            }
            return [...self::WITHHELD_METRICS, 'source_access' => 'restricted'];
        }

        try {
            $consume = function (HealthSafetyReadContext $active) use ($viewer, $from, $to, $requestedSites): array {
                $active->assertViewer($viewer);
                return HealthSafetyReadMaterialisationProof::within($active, fn ($proof) => $this->access->withReadContext($active,
                    function ($current, $reads, $sites) use ($active, $proof, $from, $to, $requestedSites): array {
                        abort_unless($current->canDo('hazards.view'), 403);
                        if (! Schema::hasTable('first_aid_records')) {
                            return [...self::WITHHELD_METRICS, 'source_access' => 'unavailable'];
                        }
                        $query = $this->access->readableQuery($current, $reads, $sites, false, $active)->whereBetween('treatment_date', [$from, $to]);
                        if ($requestedSites !== null) {
                            $query->whereIn('site_id', is_array($requestedSites) ? $requestedSites : [$requestedSites]);
                        }
                        // The actual source query remains registered through the
                        // outer Overview's final response barrier.
                        $rows = $proof->protect($query)->orderBy('id')->get(['id', 'site_id', 'client_id', 'treated_person_type', 'treated_person_id',
                            'treatment_date', 'injury_illness_type', 'treatment_outcome', 'ambulance_called']);
                        $breakdown = fn (string $field, string $label): array => $rows->filter(fn ($row) => $row->{$field} !== null)
                            ->groupBy($field)->map(fn ($group, $value) => [$label => (string) $value, 'count' => $group->count()])
                            ->sortByDesc('count')->values()->all();

                        return ['activity' => ['treatments' => $rows->count(), 'ambulance' => $rows->where('ambulance_called', true)->count(), 'hospital' => $rows->where('treatment_outcome', 'sent_to_hospital')->count()],
                            'by_type' => $breakdown('injury_illness_type', 'type'), 'by_outcome' => $breakdown('treatment_outcome', 'outcome'), 'source_access' => 'readable', 'scope' => 'current_readable_sources'];
                    }));
            };
            return $context !== null ? $consume($context) : HealthSafetyReadContext::within($viewer, FirstAidAccessService::PERMISSIONS, $consume);
        } catch (HttpException $error) {
            if (! in_array($error->getStatusCode(), [403, 404], true)) {
                throw $error;
            }

            // A denied First Aid source audience cannot contribute to a broader analytics page.
            // An unavailable operand is not evidence that no treatment happened.
            return [...self::WITHHELD_METRICS, 'source_access' => $error->getStatusCode() === 403 ? 'restricted' : 'unavailable'];
        }
    }
}
