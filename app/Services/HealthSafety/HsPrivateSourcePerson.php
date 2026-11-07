<?php

namespace App\Services\HealthSafety;

use App\Models\HsEvent;
use App\Models\SafeguardingConcern;
use App\Models\SubstanceExposureRecord;
use App\Models\User;

/**
 * Safety reviews made from a safeguarding concern or a private chemical exposure (readiness audit B1, 6 Nov 2026):
 * the person (client, worker) and the reporter are named only to someone who can open the concern or exposure itself,
 * the same rule the review's title follows. Everyone else with H&S read access at that Site sees the review, its Site
 * and its progress, but not whose it is. Used by the review list, its page and PDF, and /tasks.
 */
final class HsPrivateSourcePerson
{
    /** @var array<string, bool> */
    private array $memo = [];

    public function hides(HsEvent $event, User $viewer): bool
    {
        return match (self::sourceKind($event)) {
            'exposure' => ! app(ChemicalExposureAccessService::class)->canRead($viewer),
            'safeguarding' => ! $this->canSeeConcern($viewer, (int) $event->source_id),
            default => false,
        };
    }

    /** A review whose person is private to its source. /tasks never names them: its titles already say kind and Site. */
    public static function isPrivateSource(HsEvent $event): bool
    {
        return self::sourceKind($event) !== null;
    }

    /**
     * F5 (Stephan, 6 Nov 2026: "Show it, let a person close it"): whether this review's chemical exposure was marked
     * entered in error. The review stays open until a person records the WorkSafe decision and closes it.
     */
    public static function exposureEnteredInError(HsEvent $event): bool
    {
        return self::sourceKind($event) === 'exposure' && (int) $event->source_id > 0
            && ChemicalExposureSourceService::enteredInError((int) $event->source_id);
    }

    private static function sourceKind(HsEvent $event): ?string
    {
        $type = ltrim((string) $event->getRawOriginal('source_type'), '\\');
        $basename = class_basename($type);

        return match (true) {
            $basename === 'SubstanceExposureRecord' || $type === (new SubstanceExposureRecord)->getMorphClass() => 'exposure',
            $basename === 'SafeguardingConcern' || $type === (new SafeguardingConcern)->getMorphClass() => 'safeguarding',
            default => null,
        };
    }

    private function canSeeConcern(User $viewer, int $concernId): bool
    {
        if ($concernId <= 0) {
            return false;
        }
        $key = $viewer->id.':'.$concernId;

        return $this->memo[$key] ??= (($concern = SafeguardingConcern::query()->find($concernId)) !== null
            && $viewer->can('viewDetail', $concern));
    }
}
