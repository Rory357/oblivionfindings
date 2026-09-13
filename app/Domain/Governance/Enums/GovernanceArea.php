<?php

namespace App\Domain\Governance\Enums;

enum GovernanceArea: string
{
    case Meetings = 'meetings';
    case Resolutions = 'resolutions';
    case Risks = 'risks';
    case Compliance = 'compliance';
    case Budgets = 'budgets';
    case SpendApprovals = 'spend_approvals';
    case ActionItems = 'action_items';
    case Policies = 'policies';

    public function label(): string
    {
        return match ($this) {
            self::Meetings => 'Meetings',
            self::Resolutions => 'Resolutions',
            self::Risks => 'Risk Register',
            self::Compliance => 'Compliance',
            self::Budgets => 'Budgets',
            self::SpendApprovals => 'Spend Approvals',
            self::ActionItems => 'Action Items',
            self::Policies => 'Policies',
        };
    }
}
