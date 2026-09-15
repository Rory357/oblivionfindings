import { Link } from '@inertiajs/react';
import { ShieldAlert } from 'lucide-react';

import { Button } from '@/components/ui/button';
import { cn } from '@/lib/utils';

/** Settings deep link that opens the "How the board votes" section. */
export const VOTING_RULES_SETTINGS_HREF = '/governance/settings?section=rules';

/**
 * Blocked-state banner shown on the Resolutions pages while the board's
 * voting rules aren't confirmed: says what's blocked, why, and who can fix
 * it — as visible text, never only a disabled button (vocabulary.md).
 */
export function VotingSwitchedOffBanner({
    canSwitchOn,
    className,
}: {
    /** The viewer can confirm the voting rules (governance settings managers). */
    canSwitchOn: boolean;
    className?: string;
}) {
    return (
        <div
            role="status"
            data-test="voting-switched-off"
            className={cn(
                'flex flex-wrap items-start gap-3 rounded-xl border border-status-critical/40 bg-status-critical-bg p-4',
                className,
            )}
        >
            <ShieldAlert
                className="mt-0.5 size-5 shrink-0 text-status-critical"
                aria-hidden="true"
            />
            <div className="min-w-0 flex-1">
                <p className="text-section-title text-status-critical">
                    Board voting is switched off
                </p>
                <p className="mt-0.5 text-sm text-foreground">
                    Voting can’t open on any resolution until the board’s voting
                    rules are confirmed.{' '}
                    {canSwitchOn
                        ? 'You can do this in Settings, under “How the board votes”.'
                        : 'Ask the chair or board secretary to confirm them in Settings.'}
                </p>
            </div>
            {canSwitchOn ? (
                <Button asChild size="sm" variant="outline">
                    <Link href={VOTING_RULES_SETTINGS_HREF}>
                        Go to how the board votes
                    </Link>
                </Button>
            ) : null}
        </div>
    );
}
