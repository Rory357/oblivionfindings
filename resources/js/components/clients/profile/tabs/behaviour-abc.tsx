/* Behaviour / ABC tab for the client profile. Replaces the old duplicate
 * "Clinical Observations" list (which mirrored Health Monitoring) with a
 * behaviour-focused workspace: PBS pattern analytics over the structured
 * behaviour_abc_entries store, plus a paginated ABC log whose rows open the
 * Add-Client-style AbcEntryDialog to view / edit. Create + edit both flow
 * through openProfileDialog('abc'); the log re-fetches whenever `refreshToken`
 * changes (bumped when the dialog closes). */
import {
    BehaviourInsightsCard,
    type BehaviourPattern,
} from '@/components/behaviour-insights-card';
import type { AbcEntryRow } from '@/components/clients/profile/abc-dialog';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { cn } from '@/lib/utils';
import {
    AlertTriangle,
    ChevronRight,
    Flag,
    HeartCrack,
    Plus,
    Stethoscope,
} from 'lucide-react';
import { useCallback, useEffect, useState } from 'react';

type Paginated = {
    data: AbcEntryRow[];
    current_page: number;
    last_page: number;
    total: number;
};

const INTENSITY_TONE: Record<string, string> = {
    low: 'text-status-success border-status-success/40',
    medium: 'text-status-warning border-status-warning/40',
    high: 'text-status-critical border-status-critical/40',
};
const INTENSITY_DOT: Record<string, string> = {
    low: 'bg-status-success',
    medium: 'bg-status-warning',
    high: 'bg-status-critical',
};

function timeAgo(iso?: string | null): string {
    if (!iso) return '';
    const d = new Date(iso);
    const diffH = Math.floor((Date.now() - d.getTime()) / 3600000);
    if (diffH < 1) return 'just now';
    if (diffH < 24) return `${diffH}h ago`;
    const days = Math.floor(diffH / 24);
    if (days === 1) return 'yesterday';
    if (days < 7) return `${days}d ago`;
    return d.toLocaleDateString('en-NZ', { day: 'numeric', month: 'short' });
}

export function BehaviourAbcTab({
    clientId,
    patterns,
    canRecord,
    onNewEntry,
    onOpenEntry,
    refreshToken,
}: {
    clientId: number;
    patterns?: BehaviourPattern;
    canRecord: boolean;
    onNewEntry: () => void;
    onOpenEntry: (entry: AbcEntryRow) => void;
    refreshToken: number;
}) {
    const [entries, setEntries] = useState<AbcEntryRow[]>([]);
    const [loading, setLoading] = useState(true);
    const [page, setPage] = useState(1);
    const [lastPage, setLastPage] = useState(1);
    const [total, setTotal] = useState(0);

    const fetchEntries = useCallback(
        async (p: number) => {
            setLoading(true);
            try {
                const res = await fetch(
                    `/clients/${clientId}/behaviour/abc?page=${p}`,
                    { headers: { Accept: 'application/json' } },
                );
                if (!res.ok) throw new Error('failed');
                const json: Paginated = await res.json();
                setEntries(json.data);
                setLastPage(json.last_page);
                setTotal(json.total);
            } catch {
                setEntries([]);
            } finally {
                setLoading(false);
            }
        },
        [clientId],
    );

    useEffect(() => {
        fetchEntries(page);
    }, [page, fetchEntries]);

    // Re-fetch when the dialog closes (create / edit / delete may have changed the log).
    useEffect(() => {
        if (refreshToken === 0) return;
        if (page !== 1) {
            setPage(1);
        } else {
            fetchEntries(1);
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [refreshToken]);

    return (
        <div className="space-y-6">
            <div className="flex flex-wrap items-center justify-between gap-3">
                <div className="flex items-center gap-3">
                    <span className="flex h-10 w-10 items-center justify-center rounded-xl bg-accent text-primary">
                        <Stethoscope className="h-[19px] w-[19px]" />
                    </span>
                    <div>
                        <h2 className="text-lg font-semibold leading-tight">
                            Behaviour observations
                        </h2>
                        <p className="text-sm text-muted-foreground">
                            ABC charting &amp; behaviour support
                        </p>
                    </div>
                </div>
                {canRecord ? (
                    <Button onClick={onNewEntry} data-test="abc-new-entry">
                        <Plus className="mr-1.5 h-4 w-4" />
                        New ABC entry
                    </Button>
                ) : null}
            </div>

            <BehaviourInsightsCard
                patterns={patterns}
                description="Rolled-up trends from ABC charts and flagged daily notes for this client."
            />

            <Card data-test="abc-log">
                <CardContent className="p-0">
                    <div className="flex items-center justify-between border-b px-5 py-3.5">
                        <h3 className="text-[15px] font-semibold">ABC log</h3>
                        <span className="text-xs text-muted-foreground">
                            {total} {total === 1 ? 'entry' : 'entries'}
                        </span>
                    </div>

                    {loading ? (
                        <p className="py-10 text-center text-sm text-muted-foreground">
                            Loading ABC log…
                        </p>
                    ) : entries.length === 0 ? (
                        <div className="flex flex-col items-center justify-center gap-2 py-12 text-center">
                            <span className="grid h-11 w-11 place-items-center rounded-xl bg-accent text-primary">
                                <Stethoscope className="h-5 w-5" />
                            </span>
                            <p className="text-sm font-medium">No ABC entries yet</p>
                            <p className="max-w-sm text-xs text-muted-foreground">
                                Log an Antecedent → Behaviour → Consequence record to
                                start building the behaviour picture.
                            </p>
                            {canRecord ? (
                                <Button
                                    size="sm"
                                    className="mt-1"
                                    onClick={onNewEntry}
                                    data-test="abc-log-empty-cta"
                                >
                                    <Plus className="mr-1.5 h-4 w-4" />
                                    Log first ABC entry
                                </Button>
                            ) : null}
                        </div>
                    ) : (
                        <div className="divide-y">
                            {entries.map((e) => {
                                const tone = INTENSITY_TONE[e.intensity ?? 'low'];
                                return (
                                    // eslint-disable-next-line no-restricted-syntax -- whole-row clickable ABC log card opening the entry dialog.
                                    <button
                                        key={e.id}
                                        type="button"
                                        onClick={() => onOpenEntry(e)}
                                        data-test="abc-entry-row"
                                        className="flex w-full items-start gap-3 px-5 py-3.5 text-left transition-colors hover:bg-accent/50"
                                    >
                                        <span
                                            className={cn(
                                                'mt-1.5 h-2.5 w-2.5 shrink-0 rounded-full',
                                                INTENSITY_DOT[e.intensity ?? 'low'],
                                            )}
                                        />
                                        <div className="min-w-0 flex-1">
                                            <div className="flex flex-wrap items-center gap-2">
                                                <span className="truncate text-sm font-semibold">
                                                    {e.behaviour || 'Behaviour'}
                                                </span>
                                                {e.behaviour_function_label ? (
                                                    <Badge variant="outline" className="text-[10px]">
                                                        {e.behaviour_function_label}
                                                    </Badge>
                                                ) : null}
                                                <Badge
                                                    variant="outline"
                                                    className={cn('text-[10px] capitalize', tone)}
                                                >
                                                    {e.intensity ?? 'low'}
                                                </Badge>
                                                {e.escalated ? (
                                                    <Badge
                                                        variant="outline"
                                                        className="gap-1 border-status-warning/40 text-[10px] text-status-warning"
                                                    >
                                                        <AlertTriangle className="h-3 w-3" /> Escalated
                                                    </Badge>
                                                ) : null}
                                                {e.harm_occurred ? (
                                                    <Badge
                                                        variant="outline"
                                                        className="gap-1 border-status-critical/40 text-[10px] text-status-critical"
                                                    >
                                                        <HeartCrack className="h-3 w-3" /> Harm
                                                    </Badge>
                                                ) : null}
                                                {e.requires_followup && !e.followup_completed ? (
                                                    <Badge
                                                        variant="outline"
                                                        className="gap-1 text-[10px]"
                                                    >
                                                        <Flag className="h-3 w-3" /> Follow-up
                                                    </Badge>
                                                ) : null}
                                            </div>
                                            <p className="mt-1 truncate text-xs text-muted-foreground">
                                                {e.setting ? `${e.setting} · ` : ''}
                                                A: {e.antecedent || '—'} → C: {e.consequence || '—'}
                                            </p>
                                        </div>
                                        <div className="ml-2 flex shrink-0 items-center gap-2">
                                            <div className="text-right">
                                                <p className="text-xs text-muted-foreground">
                                                    {timeAgo(e.occurred_at)}
                                                </p>
                                                {e.recorder ? (
                                                    <p className="text-[10px] text-muted-foreground">
                                                        {e.recorder.name}
                                                    </p>
                                                ) : null}
                                            </div>
                                            <ChevronRight className="h-4 w-4 text-muted-foreground" />
                                        </div>
                                    </button>
                                );
                            })}
                        </div>
                    )}

                    {lastPage > 1 ? (
                        <div className="flex items-center justify-between border-t px-5 py-3">
                            <span className="text-xs text-muted-foreground">
                                Page {page} of {lastPage}
                            </span>
                            <div className="flex gap-1">
                                <Button
                                    variant="outline"
                                    size="sm"
                                    disabled={page <= 1}
                                    onClick={() => setPage((p) => Math.max(1, p - 1))}
                                >
                                    Prev
                                </Button>
                                <Button
                                    variant="outline"
                                    size="sm"
                                    disabled={page >= lastPage}
                                    onClick={() => setPage((p) => Math.min(lastPage, p + 1))}
                                >
                                    Next
                                </Button>
                            </div>
                        </div>
                    ) : null}
                </CardContent>
            </Card>
        </div>
    );
}
