/**
 * Board M11 (G3, approved by Stephan 1 Nov 2026): "close anyway" requests waiting for this person's decision, leading
 * the Overview of the people who decide them. Each row opens the same Decide pop-up as the safety review page, over
 * the Overview so its header stays visible. Nothing waiting → nothing drawn (the header box already says 0).
 */
import { LeadBlock, daysSince } from '@/components/health-safety/register-kit';
import { DecideCloseAnywayDialog, type DecideReview } from '@/components/health-safety/review-dialogs';
import type { EventDetail } from '@/components/health-safety/event-detail-dialog';
import { formatDateOnly, toDateInput } from '@/lib/datetime';
import axios from 'axios';
import { useCallback, useEffect, useState } from 'react';

export type CloseAnywayRequest = {
    review: DecideReview;
    exception: EventDetail['closure_exceptions'][number];
};

/** Fetches the waiting requests; reloads after a decision. */
export function useCloseAnywayRequests(enabled: boolean) {
    const [rows, setRows] = useState<CloseAnywayRequest[]>([]);
    const [tick, setTick] = useState(0);
    const reload = useCallback(() => setTick((n) => n + 1), []);
    useEffect(() => {
        if (!enabled) return;
        let live = true;
        axios
            .get<{ requests: CloseAnywayRequest[] }>('/health-safety/events/close-anyway-requests')
            .then((response) => {
                if (live) setRows(response.data.requests ?? []);
            })
            .catch(() => {
                if (live) setRows([]);
            });
        return () => {
            live = false;
        };
    }, [enabled, tick]);
    return { rows, reload };
}

function ageNote(day: string | null): string | null {
    const days = daysSince(day);
    if (days === null) return null;
    return days === 0 ? 'today' : days === 1 ? '1 day' : `${days} days`;
}

export function CloseAnywayWorklist({ enabled, viewerName }: { enabled: boolean; viewerName?: string | null }) {
    const { rows, reload } = useCloseAnywayRequests(enabled);
    const [deciding, setDeciding] = useState<CloseAnywayRequest | null>(null);
    if (!enabled || rows.length === 0) return null;
    return (
        <>
            <LeadBlock
                title="Close-anyway requests"
                sub="Waiting for your decision"
                action={{ label: 'All tasks', href: '/tasks' }}
                rows={rows.map((row) => {
                    const asked = row.exception.requested_at ? toDateInput(row.exception.requested_at) : null;
                    const covered = row.review.close_readiness.requirements
                        .filter((r) => row.exception.scope.includes(r.key))
                        .map((r) => r.label.replace(/\.$/, '').toLowerCase())
                        .join(', ');
                    return {
                        key: row.exception.id,
                        when: asked ? { day: formatDateOnly(asked).replace(/ \d{4}$/, ''), note: ageNote(asked) } : null,
                        title: [row.review.title ?? row.review.reference_number, row.review.site?.name].filter(Boolean).join(' — '),
                        sub: [
                            `Asked by ${row.exception.requester?.name ?? 'someone'}`,
                            covered || null,
                            row.review.reference_number,
                        ]
                            .filter(Boolean)
                            .join(' · '),
                        chip: { label: 'Waiting for a decision', tone: 'warning' as const },
                        action: {
                            label: 'Decide',
                            ariaLabel: `Decide: close anyway? ${row.review.title ?? row.review.reference_number}`,
                            onClick: () => setDeciding(row),
                        },
                    };
                })}
            />
            {deciding ? (
                <DecideCloseAnywayDialog
                    open
                    onClose={() => {
                        setDeciding(null);
                        reload();
                    }}
                    d={deciding.review}
                    exception={deciding.exception}
                    viewerName={viewerName}
                />
            ) : null}
        </>
    );
}
