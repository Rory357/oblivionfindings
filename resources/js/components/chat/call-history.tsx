import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { formatDateTime } from '@/lib/datetime';
import { History } from 'lucide-react';
import { useCallback, useEffect, useRef, useState } from 'react';
import { ChatError, conversationPath } from './api';
import { callOutcomeLabels } from './call-outcome';
import { CallSummary, type CallSummaryTarget } from './call-summary';
import { chatActorRequest } from './chat-actor-request';

type HistoryCall = CallSummaryTarget & {
    status: string;
    direction: 'incoming' | 'outgoing';
    outcome: string;
    created_at: string;
    ended_at: string | null;
    connected_at: string | null;
    participants: { id: number; name: string }[];
};
type HistoryResponse = { calls: HistoryCall[]; next_cursor: string | null };
type CallHistoryProps = {
    conversationId: number;
    actorId: number;
    initiallyOpen?: boolean;
    initialFilter?: 'all' | 'missed';
    hideTrigger?: boolean;
};
export function CallHistory(props: CallHistoryProps) {
    return (
        <CallHistorySession
            key={`${props.actorId}:${props.conversationId}`}
            {...props}
        />
    );
}
function CallHistorySession({
    conversationId,
    actorId,
    initiallyOpen = false,
    initialFilter = 'all',
    hideTrigger = false,
}: CallHistoryProps) {
    const [open, setOpen] = useState(initiallyOpen);
    const [rows, setRows] = useState<HistoryCall[]>([]);
    const [error, setError] = useState('');
    const [loading, setLoading] = useState(false);
    const [summary, setSummary] = useState<CallSummaryTarget | null>(null);
    const [filter, setFilter] = useState<'all' | 'missed'>(initialFilter);
    const [nextCursor, setNextCursor] = useState<string | null>(null);
    const generation = useRef(0);
    const controller = useRef<AbortController | null>(null);
    const invalidate = useCallback(() => {
        generation.current++;
        controller.current?.abort();
    }, []);
    const load = useCallback(
        async (before?: string) => {
            const request = ++generation.current;
            controller.current?.abort();
            const current = new AbortController();
            controller.current = current;
            setLoading(true);
            setError('');
            try {
                const params = new URLSearchParams({ filter });
                if (before) params.set('before', before);
                const result = await chatActorRequest<HistoryResponse>(
                    actorId,
                    `${conversationPath(conversationId)}/calls?${params}`,
                    { signal: current.signal },
                );
                if (request !== generation.current) return;
                setRows((current) =>
                    before
                        ? [
                              ...current,
                              ...result.calls.filter(
                                  (call) =>
                                      !current.some(
                                          (row) => row.id === call.id,
                                      ),
                              ),
                          ]
                        : result.calls,
                );
                setNextCursor(result.next_cursor ?? null);
            } catch (failure) {
                if (request !== generation.current) return;
                if (
                    failure instanceof ChatError &&
                    [401, 403, 404, 419].includes(failure.status)
                ) {
                    setRows([]);
                    setNextCursor(null);
                }
                setError((failure as Error).message);
            } finally {
                if (request === generation.current) setLoading(false);
            }
        },
        [conversationId, actorId, filter],
    );
    useEffect(() => {
        setRows([]);
        setNextCursor(null);
        if (open) void load();
        return invalidate;
    }, [open, load, invalidate]);
    return (
        <>
            {!hideTrigger && (
                <Button
                    variant="ghost"
                    size="icon"
                    className="frontline-tap"
                    aria-label="Call history"
                    onClick={() => setOpen(true)}
                >
                    <History className="size-5" />
                </Button>
            )}
            <Dialog open={open} onOpenChange={setOpen}>
                <DialogContent
                    style={{
                        maxWidth: 'min(92vw, 480px)',
                        width: 'min(92vw, 480px)',
                    }}
                    className="max-h-[85dvh] overflow-y-auto"
                >
                    <DialogHeader>
                        <DialogTitle>Call history</DialogTitle>
                        <DialogDescription>
                            Calls you started or were invited to in this
                            conversation. No audio recordings are stored.
                        </DialogDescription>
                    </DialogHeader>
                    <div
                        role="group"
                        aria-label="Filter call history"
                        className="flex gap-2"
                    >
                        <Button
                            className="frontline-tap"
                            variant={filter === 'all' ? 'default' : 'outline'}
                            aria-pressed={filter === 'all'}
                            onClick={() => setFilter('all')}
                        >
                            All calls
                        </Button>
                        <Button
                            className="frontline-tap"
                            variant={
                                filter === 'missed' ? 'default' : 'outline'
                            }
                            aria-pressed={filter === 'missed'}
                            onClick={() => setFilter('missed')}
                        >
                            Missed
                        </Button>
                    </div>
                    <div className="max-h-80 space-y-3 overflow-y-auto">
                        {loading && (
                            <p role="status" className="text-sm">
                                Loading calls…
                            </p>
                        )}
                        {error && (
                            <p
                                role="alert"
                                className="text-sm text-status-critical"
                            >
                                {error}
                            </p>
                        )}
                        {!loading && !error && !rows.length && (
                            <p className="py-5 text-sm text-muted-foreground">
                                {filter === 'missed'
                                    ? 'No missed calls.'
                                    : 'No calls yet.'}
                            </p>
                        )}
                        {rows.map((call) => (
                            <article
                                key={call.id}
                                className="space-y-2 rounded-xl border p-3 text-sm"
                            >
                                <p className="font-medium capitalize">
                                    {callOutcomeLabels[call.outcome] ?? 'Call'}{' '}
                                    · {formatDateTime(call.created_at)}
                                </p>
                                <p className="text-xs text-muted-foreground">
                                    {call.direction === 'outgoing'
                                        ? 'Outgoing'
                                        : 'Incoming'}
                                </p>
                                <p className="text-muted-foreground">
                                    {call.participants
                                        .map((person) => person.name)
                                        .join(', ')}
                                </p>
                                {call.ended_at && call.can_record_note && (
                                    <Button
                                        variant="outline"
                                        className="frontline-tap"
                                        onClick={() => {
                                            setOpen(false);
                                            setSummary(call);
                                        }}
                                    >
                                        Add reviewed summary
                                    </Button>
                                )}
                            </article>
                        ))}
                        {nextCursor && (
                            <Button
                                variant="outline"
                                className="frontline-tap w-full"
                                disabled={loading}
                                onClick={() => void load(nextCursor)}
                            >
                                Load older calls
                            </Button>
                        )}
                        {error && (
                            <Button
                                variant="outline"
                                className="frontline-tap"
                                disabled={loading}
                                onClick={() =>
                                    void load(
                                        rows.length
                                            ? (nextCursor ?? undefined)
                                            : undefined,
                                    )
                                }
                            >
                                Try again
                            </Button>
                        )}
                    </div>
                </DialogContent>
            </Dialog>
            <CallSummary
                key={`${actorId}:${summary?.id ?? 'none'}`}
                actorId={actorId}
                call={summary}
                onClose={() => setSummary(null)}
            />
        </>
    );
}
