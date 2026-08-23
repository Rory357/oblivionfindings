<?php

namespace App\Services\Sites;

use App\Models\SiteChecklistTemplateItem;
use LogicException;

final class SiteChecklistFailureRiskMapper
{
    public const ORDINARY = 'ordinary';

    public const CRITICAL = 'critical';

    public const REQUIRED_ESCALATION_ACTION = 'checklist_critical_escalation';

    /** @return array<int, string> */
    public static function levels(): array
    {
        return [self::ORDINARY, self::CRITICAL];
    }

    /**
     * @return array{
     *     level: string,
     *     hazard_severity: string,
     *     hazard_likelihood: string,
     *     damage_severity: string,
     *     requires_hs_escalation: bool
     * }
     */
    public function forItem(SiteChecklistTemplateItem $item): array
    {
        return match ($item->failure_risk_level) {
            self::ORDINARY => [
                'level' => self::ORDINARY,
                'hazard_severity' => 'medium',
                'hazard_likelihood' => 'possible',
                'damage_severity' => 'minor',
                'requires_hs_escalation' => false,
            ],
            self::CRITICAL => [
                'level' => self::CRITICAL,
                'hazard_severity' => 'critical',
                'hazard_likelihood' => 'possible',
                'damage_severity' => 'critical',
                'requires_hs_escalation' => true,
            ],
            default => throw new LogicException(
                "Unsupported checklist failure risk level [{$item->failure_risk_level}].",
            ),
        };
    }
}
