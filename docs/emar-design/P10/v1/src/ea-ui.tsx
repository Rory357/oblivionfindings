/* P10’s shared pieces: the live strip (Q5) that the grantee sees on the
 * Emergency access page, the person’s MAR and the record dialog; the grant
 * state badge; and the “who” cell. Built from the app’s real StatusBadge,
 * PersonDisc and Button, with the fixed status pairs (Notice). */
import { PersonDisc } from '@/components/lists/entity-cells';
import { Button } from '@/components/ui/button';
import { StatusBadge } from '@/components/ui/status-badge';
import { Clock3, KeyRound, TimerReset } from 'lucide-react';
import { PEOPLE, STAFF_ROLE, type Grant } from './data';
import { POLICY, atText, currentReview, endOf, fmtMin, isLive, isOverdue, latestOf, minutesLeft, needsReview, toMin, type EaSettings } from './model';
import { StateLine, Notice } from './ui';

/** Q5: amber at 10 minutes; Extend is offered once 10 minutes or less remain. */
export const ENDING_SOON = 10;
export const canExtendNow = (g: Grant) => isLive(g) && minutesLeft(g) <= ENDING_SOON && toMin(endOf(g)) < toMin(latestOf(g));
export function LiveStrip({ g, onMar, onExtend, onDone, compact }: { g: Grant; onMar?: () => void; onExtend: () => void; onDone: () => void; compact?: boolean }) {
    const p = PEOPLE[g.pid];
    const left = minutesLeft(g);
    const soon = left <= ENDING_SOON;
    const atLatest = toMin(endOf(g)) >= toMin(latestOf(g));
    return (
        <Notice
            tone={soon ? 'warning' : 'info'}
            icon={soon ? TimerReset : KeyRound}
            live="status"
            title={`Emergency access for ${p.pref} — ends ${atText(endOf(g))} (${fmtMin(left)} left)`}
            actions={
                <>
                    {onMar ? (
                        <Button size="sm" variant="outline" onClick={onMar}>
                            Open {p.pref}’s MAR
                        </Button>
                    ) : null}
                    {canExtendNow(g) ? (
                        <Button size="sm" onClick={onExtend} data-return={`extend-${g.id}`}>
                            Extend by {fmtMin(POLICY.ext)}
                        </Button>
                    ) : null}
                    <Button size="sm" variant="outline" onClick={onDone} data-return={`done-${g.id}`}>
                        I’m done
                    </Button>
                </>
            }
        >
            {compact ? (
                <>This covers {p.pref} only — not a round or anyone else.</>
            ) : (
                <>
                    This covers {p.pref} only — not a round or anyone else. Controlled medicines still need your own permission and a witness.{' '}
                    {soon ? (atLatest ? `It can’t be extended — it already runs to the longest time, ${atText(latestOf(g))}.` : `You can extend it by ${fmtMin(POLICY.ext)}, never past ${atText(latestOf(g))}.`) : `You can extend it once ${ENDING_SOON} minutes or less remain.`}
                </>
            )}
        </Notice>
    );
}

/** One state per grant: running · to review · overdue · justified · not justified. Nothing green that is a risk. */
export function GrantBadge({ g, ea }: { g: Grant; ea: EaSettings }) {
    if (isLive(g))
        return (
            <StatusBadge variant={minutesLeft(g) <= ENDING_SOON ? 'warning' : 'info'} className="rounded-[8px]">
                Running
            </StatusBadge>
        );
    if (needsReview(g))
        return (
            <StatusBadge variant={isOverdue(g, ea) ? 'critical' : 'neutral'} className="rounded-[8px]">
                {isOverdue(g, ea) ? 'Review overdue' : 'To review'}
            </StatusBadge>
        );
    const r = currentReview(g);
    return (
        <StatusBadge variant={r.outcome === 'justified' ? 'success' : 'warning'} className="rounded-[8px]">
            {r.outcome === 'justified' ? 'Justified' : 'Not justified'}
            {g.reviews.length > 1 ? ' · corrected' : ''}
        </StatusBadge>
    );
}

export function WhoCell({ name, sub }: { name: string; sub?: string }) {
    return (
        <span className="flex min-w-0 items-center gap-2 py-1 text-[12.5px]">
            <PersonDisc name={name} size={24} />
            <span className="min-w-0">
                <span className="block truncate">{name}</span>
                <span className="block text-[11.5px] whitespace-normal text-muted-foreground">{sub ?? STAFF_ROLE[name] ?? ''}</span>
            </span>
        </span>
    );
}

export function EndsCell({ g }: { g: Grant }) {
    const left = minutesLeft(g);
    return (
        <span className="flex flex-col items-start gap-0.5 py-1">
            <span className="text-[12.5px]">{atText(endOf(g))}</span>
            <StateLine tone={left <= ENDING_SOON ? 'warning' : undefined} icon={Clock3}>
                {fmtMin(left)} left
            </StateLine>
        </span>
    );
}

/** What was done under a grant, in a line (Q6). */
export function didSummary(g: Grant) {
    const doses = g.activity.filter((a) => a.kind === 'dose').length;
    const orders = g.activity.filter((a) => a.kind === 'order').length;
    const opened = g.activity.some((a) => a.kind === 'viewed');
    const parts = [opened ? 'Opened the chart' : null, doses ? `${doses} ${doses === 1 ? 'dose' : 'doses'} recorded` : null, orders ? `${orders} order ${orders === 1 ? 'change' : 'changes'}` : null].filter(Boolean);
    return `${parts.join(' · ') || 'Nothing opened'}${!doses && !orders ? ' · nothing recorded' : ''}`;
}
