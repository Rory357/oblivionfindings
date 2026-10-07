import { Button } from '@/components/ui/button';
import { StatusBadge, type StatusVariant } from '@/components/ui/status-badge';
import { WORKER_TIMEZONE } from '@/lib/datetime';
import { Link } from '@inertiajs/react';
import { RefreshCw, ShieldCheck } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';

type Freshness = 'current' | 'pending' | 'failed' | 'stale' | 'unverified';
export type EligibilityObservation = {
    shift_id: number;
    posture: 'clear' | 'warning' | 'blocked' | 'review' | 'unverified';
    freshness: Freshness;
    starts_at: string | null;
    checked_at: string | null;
    last_successful_at: string | null;
    block_count: number | null;
    warning_count: number | null;
    can_retry: boolean;
    retry_url: string | null;
};
type FeedPage = {
    data: EligibilityObservation[];
    current_page: number;
    last_page: number;
};
export type EligibilityDuty = { id: number; label: string; href?: string };
const endpoint = '/operations/workforce/eligibility-refresh';
const freshnessLabels: Record<Freshness, string> = {
    current: 'Up to date',
    pending: 'Recheck pending',
    failed: 'Check unavailable',
    stale: 'Recheck needed',
    unverified: 'Not checked yet',
};
const postureLabels: Record<EligibilityObservation['posture'], string> = {
    clear: 'No eligibility issues found',
    warning: 'Warnings to review',
    blocked: 'Assignment needs attention',
    review: 'Approval review needed',
    unverified: 'No result yet',
};

/** Fetch every page for the selected duties, without widening to an unfiltered feed. */
export async function loadEligibilityObservations(
    ids: number[],
    signal: AbortSignal,
): Promise<EligibilityObservation[]> {
    const rows: EligibilityObservation[] = [];
    for (let offset = 0; offset < ids.length; offset += 200) {
        const batch = ids.slice(offset, offset + 200);
        let page = 1;
        let lastPage = 1;
        do {
            const query = new URLSearchParams({ page: String(page) });
            batch.forEach((id) => query.append('shift_ids[]', String(id)));
            const response = await fetch(`${endpoint}?${query}`, {
                credentials: 'same-origin',
                headers: { Accept: 'application/json' },
                signal,
            });
            if (!response.ok)
                throw new Error('Eligibility updates could not be loaded.');
            const result: FeedPage = await response.json();
            if (
                !Array.isArray(result.data) ||
                result.current_page !== page ||
                !Number.isInteger(result.last_page) ||
                result.last_page < page ||
                result.last_page > 4
            ) {
                throw new Error('The eligibility response was incomplete.');
            }
            for (const row of result.data) {
                const count = (value: unknown) =>
                    value === null ||
                    (Number.isSafeInteger(value) && Number(value) >= 0);
                const date = (value: unknown) =>
                    value === null ||
                    (typeof value === 'string' &&
                        Number.isFinite(Date.parse(value)));
                const retryPath = `${endpoint}/${row?.shift_id}/retry`;
                const validRetry =
                    row?.retry_url === retryPath ||
                    (typeof window !== 'undefined' &&
                        row?.retry_url === window.location.origin + retryPath);
                if (
                    !row ||
                    !Number.isSafeInteger(row.shift_id) ||
                    !batch.includes(row.shift_id) ||
                    rows.some(
                        (existing) => existing.shift_id === row.shift_id,
                    ) ||
                    ![
                        'current',
                        'pending',
                        'failed',
                        'stale',
                        'unverified',
                    ].includes(row.freshness) ||
                    ![
                        'clear',
                        'warning',
                        'blocked',
                        'review',
                        'unverified',
                    ].includes(row.posture) ||
                    !count(row.block_count) ||
                    !count(row.warning_count) ||
                    !date(row.starts_at) ||
                    !date(row.checked_at) ||
                    !date(row.last_successful_at) ||
                    typeof row.can_retry !== 'boolean' ||
                    (row.can_retry ? !validRetry : row.retry_url !== null)
                )
                    throw new Error('The eligibility response was incomplete.');
                rows.push(row);
            }
            lastPage = result.last_page;
            page += 1;
        } while (page <= lastPage);
    }
    return rows;
}

export function EligibilityRefreshPanel({
    duties,
    workerTimezone = WORKER_TIMEZONE,
}: {
    duties: EligibilityDuty[];
    workerTimezone?: string;
}) {
    const idsKey = [...new Set(duties.map((duty) => duty.id))]
        .sort((a, b) => a - b)
        .join(',');
    const [revision, setRevision] = useState(0);
    const [state, setState] = useState<{
        scope: string;
        rows: EligibilityObservation[];
        loading: boolean;
        error: string | null;
    }>({
        scope: idsKey,
        rows: [],
        loading: true,
        error: null,
    });
    const [retrying, setRetrying] = useState<number | null>(null);
    const pendingRetry = useRef<number | null>(null);
    const currentScope = useRef(idsKey);
    useEffect(() => {
        currentScope.current = idsKey;
    }, [idsKey]);
    const mounted = useRef(true);
    const [unconfirmed, setUnconfirmed] = useState<Set<number>>(new Set());
    useEffect(() => {
        mounted.current = true;
        return () => {
            mounted.current = false;
        };
    }, []);
    useEffect(() => {
        pendingRetry.current = null;
        setRetrying(null);
        setUnconfirmed(new Set());
        setRetryMessage(null);
    }, [idsKey]);
    const [retryMessage, setRetryMessage] = useState<string | null>(null);
    const [expanded, setExpanded] = useState(false);
    useEffect(() => {
        const controller = new AbortController();
        setState((previous) => ({
            scope: idsKey,
            rows: previous.scope === idsKey ? previous.rows : [],
            loading: true,
            error: null,
        }));
        const ids = idsKey ? idsKey.split(',').map(Number) : [];
        void loadEligibilityObservations(ids, controller.signal)
            .then((rows) => {
                if (!controller.signal.aborted) {
                    setUnconfirmed(new Set());
                    setState({
                        scope: idsKey,
                        rows,
                        loading: false,
                        error: null,
                    });
                }
            })
            .catch(() => {
                if (!controller.signal.aborted)
                    setState((previous) => ({
                        ...previous,
                        loading: false,
                        error: 'Eligibility updates are unavailable. Try loading them again.',
                    }));
            });
        return () => controller.abort();
    }, [idsKey, revision]);
    const rows = state.scope === idsKey ? state.rows : [];
    const loading = state.scope !== idsKey || state.loading;
    const attention = rows.filter(
        (row) => row.freshness !== 'current' || row.posture !== 'clear',
    );
    const visible = expanded ? rows : attention.slice(0, 3);
    const format = (value: string | null) =>
        value
            ? new Intl.DateTimeFormat('en-NZ', {
                  timeZone: workerTimezone,
                  day: 'numeric',
                  month: 'short',
                  year: 'numeric',
                  hour: 'numeric',
                  minute: '2-digit',
              }).format(new Date(value))
            : 'No successful check recorded';
    const retry = async (row: EligibilityObservation) => {
        if (
            !row.can_retry ||
            !row.retry_url ||
            pendingRetry.current !== null ||
            unconfirmed.has(row.shift_id) ||
            loading ||
            state.error
        )
            return;
        pendingRetry.current = row.shift_id;
        const scope = idsKey;
        const current = () =>
            mounted.current &&
            currentScope.current === scope &&
            pendingRetry.current === row.shift_id;
        setRetrying(row.shift_id);
        setRetryMessage(null);
        try {
            const token = document.querySelector<HTMLMetaElement>(
                'meta[name="csrf-token"]',
            )?.content;
            const response = await fetch(row.retry_url, {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    Accept: 'application/json',
                    'Content-Type': 'application/json',
                    ...(token ? { 'X-CSRF-TOKEN': token } : {}),
                },
                body: '{}',
            });
            if (response.status !== 202) throw new Error('Retry not confirmed');
            const result = await response.json();
            if (result.queued !== true || result.shift_id !== row.shift_id)
                throw new Error('Retry not confirmed');
            if (!current()) return;
            setRetryMessage(
                'Recheck queued. Refresh results to see its progress.',
            );
            setRevision((value) => value + 1);
        } catch {
            if (!current()) return;
            setUnconfirmed((previous) => new Set(previous).add(row.shift_id));
            setRetryMessage(
                'We could not confirm the recheck request. It may already be queued. Refresh results before requesting it again.',
            );
        } finally {
            if (current()) {
                pendingRetry.current = null;
                setRetrying(null);
            }
        }
    };
    if (!idsKey) return null;
    return (
        <section
            aria-label="Eligibility updates"
            aria-busy={loading}
            className="space-y-3 rounded-xl border border-border bg-card p-4"
        >
            <div className="flex flex-wrap items-start justify-between gap-3">
                <div className="min-w-0">
                    <h2 className="text-section-title flex items-center gap-2">
                        <ShieldCheck className="h-5 w-5 text-primary" />{' '}
                        Eligibility updates
                    </h2>
                    <p className="mt-1 text-sm text-muted-foreground">
                        Changes to leave, credentials and safety information
                        trigger a recheck of current and published future worker
                        shifts.
                    </p>
                </div>
                <Button
                    variant="outline"
                    size="sm"
                    disabled={loading}
                    onClick={() => setRevision((value) => value + 1)}
                >
                    <RefreshCw className="h-4 w-4" />{' '}
                    {loading ? 'Loading checks…' : 'Refresh results'}
                </Button>
            </div>
            {state.error ? (
                <p role="alert" className="text-sm text-status-warning">
                    {state.error}{' '}
                    {rows.length
                        ? 'Previously loaded results below may be out of date.'
                        : ''}
                </p>
            ) : null}
            {retryMessage ? (
                <p
                    role={unconfirmed.size ? 'alert' : 'status'}
                    className="text-sm text-status-warning"
                >
                    {retryMessage}
                </p>
            ) : null}
            {!loading && !state.error ? (
                <p role="status" className="text-sm">
                    {rows.length === 0
                        ? 'No current or published future assigned shifts in this selection.'
                        : attention.length
                          ? `${attention.length} of ${rows.length} assigned ${rows.length === 1 ? 'shift needs' : 'shifts need'} attention.`
                          : `${rows.length} ${rows.length === 1 ? 'shift has an up-to-date check' : 'shifts have up-to-date checks'} with no eligibility issues found.`}
                </p>
            ) : null}
            {visible.map((row) => {
                const duty = duties.find((item) => item.id === row.shift_id);
                const old =
                    !!state.error || loading || row.freshness !== 'current';
                const variant: StatusVariant = old
                    ? 'warning'
                    : row.posture === 'clear'
                      ? 'success'
                      : row.posture === 'blocked'
                        ? 'critical'
                        : 'warning';
                return (
                    <article
                        key={row.shift_id}
                        className="space-y-2 rounded-lg border border-border p-3"
                    >
                        <div className="flex flex-wrap items-center justify-between gap-2">
                            <h3 className="text-sm font-semibold">
                                {duty?.label ?? `Shift #${row.shift_id}`}
                            </h3>
                            <StatusBadge variant={variant}>
                                {state.error || loading
                                    ? 'Previously loaded result'
                                    : freshnessLabels[row.freshness]}
                            </StatusBadge>
                        </div>
                        <p className="text-sm">
                            {old && row.posture !== 'unverified'
                                ? 'Last result: '
                                : ''}
                            {postureLabels[row.posture]}
                            {row.block_count
                                ? ` · ${row.block_count} blocking ${row.block_count === 1 ? 'check' : 'checks'}`
                                : ''}
                            {row.warning_count
                                ? ` · ${row.warning_count} ${row.warning_count === 1 ? 'warning' : 'warnings'}`
                                : ''}
                        </p>
                        <p className="text-caption text-muted-foreground">
                            Last successful check:{' '}
                            {format(row.last_successful_at)} · {workerTimezone}
                        </p>
                        {row.freshness === 'pending' ? (
                            <p className="text-sm text-muted-foreground">
                                A recheck is queued. Refresh results to see when
                                it finishes.
                            </p>
                        ) : null}
                        {old ? (
                            <p className="text-sm text-muted-foreground">
                                Review current evidence before acting on this
                                result. Assignments are unchanged.
                            </p>
                        ) : null}
                        <div className="flex flex-wrap gap-2">
                            {duty?.href ? (
                                <Button asChild size="sm" variant="outline">
                                    <Link href={duty.href}>Open shift</Link>
                                </Button>
                            ) : null}
                            {row.can_retry && row.retry_url ? (
                                <Button
                                    size="sm"
                                    variant="outline"
                                    disabled={
                                        retrying !== null ||
                                        unconfirmed.has(row.shift_id) ||
                                        row.freshness === 'pending' ||
                                        loading ||
                                        !!state.error
                                    }
                                    onClick={() => void retry(row)}
                                >
                                    {retrying === row.shift_id
                                        ? 'Requesting recheck…'
                                        : 'Recheck shift'}
                                </Button>
                            ) : null}
                        </div>
                    </article>
                );
            })}
            {rows.length > 0 && (expanded || rows.length > visible.length) ? (
                <Button
                    variant="ghost"
                    size="sm"
                    onClick={() => setExpanded((value) => !value)}
                >
                    {expanded
                        ? 'Show attention summary'
                        : `View all ${rows.length} results`}
                </Button>
            ) : null}
        </section>
    );
}
