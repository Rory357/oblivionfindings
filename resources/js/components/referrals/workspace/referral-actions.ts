import type { MenuItem } from '@/components/lists';
import {
    ArrowUpRight,
    Flag,
    HeartHandshake,
    Phone,
    Users,
    Workflow,
} from 'lucide-react';
import type { Permissions, ReferralSummary } from '../types';

/** In-place actions that open the referral's own wizard on the workspace. */
export type WorkspaceAction = 'assign' | 'contact' | 'transition' | 'priority';

/**
 * Why Set priority is unavailable, or undefined when it can be used. Anyone who works the
 * referral may set it: whoever can edit it or move it between stages (the server re-checks).
 */
export function priorityBlocked(
    record: Pick<ReferralSummary, 'stage'>,
    permissions: Permissions,
): string | undefined {
    if (!permissions.edit && !permissions.transition)
        return 'Requires permission to edit or move referrals';
    if (record.stage === 'closed')
        return 'Reopen the referral before changing its priority';
    if (record.stage === 'converted')
        return 'Handed-over referrals keep their last priority';
    return undefined;
}

export type ReferralActionHandlers = {
    open: () => void;
    act: (action: WorkspaceAction) => void;
    handover: () => void;
};

/**
 * The ONE MenuItem[] for a referral on every workspace surface — card kebab,
 * table kebab, pipeline card and right-click (LIST_STYLE_GUIDE §1). Blocked
 * actions stay listed with the reason (§4); the server re-checks every one.
 */
export function referralActions(
    record: Pick<ReferralSummary, 'stage'>,
    permissions: Permissions,
    run: ReferralActionHandlers,
): MenuItem[] {
    const closed = record.stage === 'closed';
    const handoverAccess =
        permissions.convert &&
        permissions['assessment.view'] &&
        permissions['funding.view'];
    return [
        { label: 'Open referral', icon: ArrowUpRight, onClick: run.open },
        {
            label: 'Assign owner',
            icon: Users,
            onClick: () => run.act('assign'),
            disabled: !permissions.assign
                ? 'Requires Assign referrals'
                : closed
                  ? 'Reopen the referral before changing its assignment'
                  : undefined,
        },
        {
            label: 'Log contact',
            icon: Phone,
            onClick: () => run.act('contact'),
            disabled: !permissions.contact
                ? 'Requires Log contact'
                : closed
                  ? 'Reopen the referral before recording contact'
                  : undefined,
        },
        {
            label: 'Set priority',
            icon: Flag,
            onClick: () => run.act('priority'),
            disabled: priorityBlocked(record, permissions),
        },
        {
            label: 'Review handover',
            icon: HeartHandshake,
            onClick: run.handover,
            disabled: !permissions.convert
                ? 'Requires Create / link supported person'
                : !handoverAccess
                  ? 'Requires assessment and funding access'
                  : closed
                    ? 'Closed referrals are not handed over'
                    : undefined,
        },
        ...(permissions.transition &&
        !['closed', 'converted'].includes(record.stage)
            ? [
                  {
                      label: 'Review stage change',
                      icon: Workflow,
                      onClick: () => run.act('transition'),
                  },
              ]
            : []),
    ];
}
