/**
 * G3 History tab (board M5e, approved by Stephan 1 Nov 2026): everything done on a safety review, newest first, from
 * GET /health-safety/events/{id}/activity (read only — the server builds it from the audit log, owner changes and
 * corrections, and only for people who can read the whole review). Filtered by step or "Edits only" with the built
 * tier-2 tabs, never bordered pills (DESIGN.md anti-pattern). Nothing here can be changed.
 */
import axios from 'axios';
import {
    CheckCircle2,
    ClipboardCheck,
    List,
    PencilLine,
    Search,
    UserCheck,
    Wrench,
} from 'lucide-react';
import { useCallback, useEffect, useMemo, useState } from 'react';

import { TierTwoTabs } from '@/components/page/grouped-profile-nav';
import { Surface } from '@/components/signature/blocks';
import { PaneLink, type RecordProgressActivity } from '@/components/signature/record-progress';
import { StatusBadge } from '@/components/ui/status-badge';
import { formatDateTime } from '@/lib/datetime';

export type ReviewStepKey =
    | 'assigned'
    | 'investigated'
    | 'implemented'
    | 'check'
    | 'closed';

export type ReviewActivityEntry = {
    at: string;
    who: string | null;
    /** The viewer did this (the page says "You"). */
    you?: boolean;
    what: string;
    step: ReviewStepKey;
    kind: 'done' | 'owner' | 'edit' | 'failed';
    detail: {
        why?: string | null;
        before?: string | null;
        after?: string | null;
        /** A correction still waiting: proposed | reviewed. */
        status?: string | null;
    } | null;
};

export type ReviewActivity = {
    state: 'loading' | 'error' | 'ready';
    entries: ReviewActivityEntry[];
    reload: () => void;
};

/** Loads the review's activity once per page (and again after a change), shared by the step panes and History. */
export function useReviewActivity(
    eventId: number,
    enabled: boolean,
    version: unknown,
): ReviewActivity {
    const [state, setState] = useState<ReviewActivity['state']>('loading');
    const [entries, setEntries] = useState<ReviewActivityEntry[]>([]);
    const [tick, setTick] = useState(0);
    const reload = useCallback(() => setTick((n) => n + 1), []);

    useEffect(() => {
        if (!enabled) return;
        let live = true;
        setState((current) => (current === 'ready' ? current : 'loading'));
        axios
            .get<{ entries: ReviewActivityEntry[] }>(
                `/health-safety/events/${eventId}/activity`,
            )
            .then((response) => {
                if (!live) return;
                setEntries(response.data.entries ?? []);
                setState('ready');
            })
            .catch(() => {
                if (live) setState('error');
            });
        return () => {
            live = false;
        };
    }, [eventId, enabled, version, tick]);

    return { state, entries, reload };
}

export const REVIEW_STEP_LABELS: Record<ReviewStepKey, string> = {
    assigned: 'Assigned',
    investigated: 'Investigated',
    implemented: 'Implemented',
    check: 'Check it worked',
    closed: 'Closed',
};

function whoLabel(entry: ReviewActivityEntry): string {
    return entry.you ? 'You' : (entry.who ?? 'Someone no longer listed');
}

/** "Thu 29 Oct, 2:30 pm" in the worker's time. */
function when(iso: string): string {
    return formatDateTime(iso);
}

/** The few entries for one step, for the Progress card's "Activity at this step". */
export function stepActivity(
    activity: ReviewActivity,
    step: ReviewStepKey,
    limit = 4,
): RecordProgressActivity[] {
    return activity.entries
        .filter((entry) => entry.step === step)
        .slice(0, limit)
        .map((entry, index) => ({
            key: `${entry.at}-${index}`,
            time: when(entry.at),
            who: whoLabel(entry),
            what: entry.what,
            edit: entry.kind === 'edit',
        }));
}

const FILTERS: {
    key: 'all' | ReviewStepKey | 'edits';
    label: string;
    icon: typeof List;
}[] = [
    { key: 'all', label: 'Everything', icon: List },
    { key: 'assigned', label: 'Assigned', icon: UserCheck },
    { key: 'investigated', label: 'Investigated', icon: Search },
    { key: 'implemented', label: 'Implemented', icon: Wrench },
    { key: 'check', label: 'Check it worked', icon: ClipboardCheck },
    { key: 'closed', label: 'Closed', icon: CheckCircle2 },
    { key: 'edits', label: 'Edits only', icon: PencilLine },
];

const PAGE = 6;

export function ReviewHistory({ activity }: { activity: ReviewActivity }) {
    const [filter, setFilter] = useState<(typeof FILTERS)[number]['key']>('all');
    const [shown, setShown] = useState(PAGE);
    const counts = useMemo(() => {
        const out: Record<string, number> = { all: activity.entries.length, edits: 0 };
        for (const entry of activity.entries) {
            out[entry.step] = (out[entry.step] ?? 0) + 1;
            if (entry.kind === 'edit') out.edits += 1;
        }
        return out;
    }, [activity.entries]);
    const rows = activity.entries.filter((entry) =>
        filter === 'all' ? true : filter === 'edits' ? entry.kind === 'edit' : entry.step === filter,
    );
    // A step with nothing in it isn't offered (Everything and Edits only always are).
    const tabs = FILTERS.filter(
        (item) => item.key === 'all' || item.key === 'edits' || (counts[item.key] ?? 0) > 0,
    ).map((item) => ({ key: item.key, label: item.label, icon: item.icon, count: counts[item.key] ?? 0 }));

    return (
        <div className="grid min-w-0 gap-4">
            <TierTwoTabs
                tabs={tabs}
                activeTab={filter}
                onTab={(key) => {
                    setFilter(key as typeof filter);
                    setShown(PAGE);
                }}
                testIdPrefix="review-history"
                ariaLabel="Filter the activity"
                panelId="review-history-panel"
                renderLink={() => null}
            />
            <Surface title="Activity" sub="Everything done on this safety review, newest first" aria-label="Activity">
                <div id="review-history-panel" role="tabpanel" aria-label="Activity entries">
                    {activity.state === 'loading' ? (
                        <p className="sg-pane-empty">Loading the activity…</p>
                    ) : activity.state === 'error' ? (
                        <p className="sg-pane-empty">
                            The activity couldn’t be loaded.{' '}
                            <PaneLink onClick={activity.reload}>Try again</PaneLink>
                        </p>
                    ) : rows.length ? (
                        <ol className="sg-hist">
                            {rows.slice(0, shown).map((entry, index) => (
                                <HistoryRow key={`${entry.at}-${index}`} entry={entry} />
                            ))}
                        </ol>
                    ) : (
                        <p className="sg-pane-empty">Nothing recorded here yet.</p>
                    )}
                    <p className="sg-hist-foot">
                        <span>
                            Nothing here can be edited or deleted. Every change is kept with who made it, when and
                            why, for at least 10 years.
                        </span>
                        {rows.length > shown ? (
                            <span>
                                Showing {shown} of {rows.length} ·{' '}
                                <PaneLink onClick={() => setShown((n) => n + 20)}>Show more</PaneLink>
                            </span>
                        ) : null}
                    </p>
                </div>
            </Surface>
        </div>
    );
}

function HistoryRow({ entry }: { entry: ReviewActivityEntry }) {
    const detail = entry.detail;
    const waiting = detail?.status === 'proposed' || detail?.status === 'reviewed';
    return (
        <li
            className={
                entry.kind === 'edit' ? 'sg-he sg-he--edit' : entry.kind === 'failed' ? 'sg-he sg-he--failed' : 'sg-he'
            }
        >
            <time dateTime={entry.at}>{when(entry.at)}</time>
            <i aria-hidden="true" />
            <div className="min-w-0">
                <span>
                    <b>{whoLabel(entry)}</b> {entry.what}
                </span>
                {waiting ? (
                    <>
                        {' '}
                        <StatusBadge variant="warning" size="sm" label={detail?.status === 'reviewed' ? 'Waiting for approval' : 'Waiting for review'} />
                    </>
                ) : null}
                {detail && (detail.why || detail.before || detail.after) ? (
                    <div className="sg-he-ba">
                        {detail.why ? (
                            <span>
                                <b>Why:</b>
                                {detail.why}
                            </span>
                        ) : null}
                        {detail.before ? (
                            <span>
                                <b>Before:</b>
                                {detail.before}
                            </span>
                        ) : null}
                        {detail.after ? (
                            <span>
                                <b>After:</b>
                                {detail.after}
                            </span>
                        ) : null}
                    </div>
                ) : null}
            </div>
            <span className="sg-he-step">{REVIEW_STEP_LABELS[entry.step]}</span>
        </li>
    );
}
