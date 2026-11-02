/**
 * Side-column cards shared by the restraint record (G4-5) and the workplace injury record (G4-8), approved by Stephan
 * 1 Nov 2026: "The safety review" — its own record, as a card in the body (the header carries only the source record's
 * own track) — and "Who finishes what".
 *
 * Decision 6: the server sends the safety review summary only to a viewer who can open the review. Without it these
 * cards say nothing about it: no card, no "hidden" line and no closer.
 */
import { Checks, KnowList, Surface, type CheckItem } from '@/components/signature/blocks';
import { StatusBadge } from '@/components/ui/status-badge';
import { formatDate } from '@/lib/datetime';
import { hsBadge, hsLabel } from '@/lib/health-safety/hs-labels';
import type { ReactNode } from 'react';

/** Mirrors App\Support\HealthSafety\LinkedSafetyReviewSummary. */
export type LinkedSafetyReview = {
    id: number;
    reference: string | null;
    status: string;
    severity: string | null;
    url: string;
    owner: string | null;
    assigned_at: string | null;
    investigation: { required: boolean; started: boolean; done: boolean; done_at: string | null };
    actions: { total: number; done: number; last_done_at: string | null; checked: number; failed: number };
    worksafe: { decided: boolean; notifiable: boolean | null; decided_at: string | null };
    closed_at: string | null;
};

const day = (value: string | null | undefined) => (value ? formatDate(value) : null);
const join = (...parts: (string | null | undefined | false)[]) => parts.filter(Boolean).join(' · ');

/** The review's five steps as rings, each with what the server knows about it. */
export function linkedReviewChecks(r: LinkedSafetyReview, viewerName?: string | null): CheckItem[] {
    const closed = r.status === 'closed';
    const { total, done, checked, failed } = r.actions;
    const owner = r.owner ? (viewerName && r.owner === viewerName ? 'You' : r.owner) : null;
    return [
        {
            label: 'Assigned',
            state: owner || closed ? 'success' : 'none',
            note: owner ? join(owner, day(r.assigned_at)) : closed ? 'Done' : 'No owner yet',
        },
        {
            label: 'Investigated',
            state: !r.investigation.required || r.investigation.done ? 'success' : r.investigation.started ? 'info' : 'none',
            note: !r.investigation.required
                ? `Not needed${r.severity ? ` at ${hsLabel('severity', r.severity)} severity` : ''}`
                : r.investigation.done
                  ? join('Findings agreed', day(r.investigation.done_at))
                  : r.investigation.started
                    ? 'Under way'
                    : 'Not started',
        },
        {
            label: 'Implemented',
            state: total === 0 || done === total ? 'success' : done > 0 ? 'info' : 'none',
            note: total === 0 ? 'None' : join(`${done} of ${total}`, done === total ? day(r.actions.last_done_at) : null),
        },
        {
            label: 'Check it worked',
            state: failed > 0 ? 'critical' : total === 0 || checked === total ? 'success' : checked > 0 ? 'info' : 'none',
            note: total === 0 ? 'Nothing to check' : failed > 0 ? 'Didn’t work' : checked === total ? 'All checked' : `${checked} of ${total} checked`,
        },
        {
            label: 'Closed',
            state: closed ? 'success' : 'none',
            note: closed ? (day(r.closed_at) ?? 'Closed') : r.worksafe.decided ? undefined : 'After the WorkSafe decision',
        },
    ];
}

/** One plain line under the steps: what the review is waiting for. */
export function linkedReviewLine(r: LinkedSafetyReview, viewerName?: string | null): string {
    if (r.status === 'closed') return `Closed${r.closed_at ? ` ${formatDate(r.closed_at)}` : ''}.`;
    const ownerLine = r.owner ? (viewerName && r.owner === viewerName ? 'You own it.' : `${r.owner} owns it.`) : null;
    if (r.actions.failed > 0)
        return ['A check said a fix didn’t work. A new action is needed before the safety review can close.', ownerLine].filter(Boolean).join(' ');
    if (!r.owner) {
        return r.worksafe.decided
            ? 'No owner yet. It needs an owner before it can close.'
            : 'No owner yet. The WorkSafe decision is next; once it’s recorded the safety review can close.';
    }
    if (!r.worksafe.decided) return `${ownerLine} The WorkSafe decision is still to record.`;
    return ownerLine ?? '';
}

export function LinkedSafetyReviewCard({ review, viewerName }: { review: LinkedSafetyReview | null | undefined; viewerName?: string | null }) {
    if (!review) return null;
    const status = hsBadge('review_status', review.status);
    return (
        <Surface
            title="The safety review"
            sub={join('Its own record', review.reference)}
            action={{ label: 'Go to record', href: review.url, ariaLabel: `Go to record: safety review ${review.reference ?? ''}`.trim() }}
            aria-label="The safety review"
        >
            <div className="grid gap-3 px-[18px] pt-1 pb-4">
                <div>
                    <StatusBadge variant={status.variant}>{status.label}</StatusBadge>
                </div>
                <Checks items={linkedReviewChecks(review, viewerName)} single />
                <p className="text-caption">{linkedReviewLine(review, viewerName)}</p>
            </div>
        </Surface>
    );
}

/** "Who finishes what": the source record's closer, then — only when the viewer can open it — the safety review's. */
export function WhoFinishesCard({ record, review }: { record: ReactNode; review?: ReactNode | null }) {
    return (
        <KnowList
            title="Who finishes what"
            aria-label="Who finishes what"
            items={[{ tone: 'info', text: record }, ...(review ? [{ tone: 'info' as const, text: review }] : [])]}
        />
    );
}

/** The safety review's closers, in words (the server's close rule: its owner, or someone who can close any review). */
export function reviewCloserSentence(review: LinkedSafetyReview, viewerName?: string | null): string {
    const owner = review.owner ? (viewerName && review.owner === viewerName ? 'you (its owner)' : `${review.owner} (its owner)`) : 'its owner once someone takes it on';
    return `The safety review closes separately: by ${owner}, or by a health and safety manager who can close any review.`;
}
