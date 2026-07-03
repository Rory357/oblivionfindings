/* Right-side preview drawer for the All Tasks queue. Opens on row click,
 * fetches the permission-scoped detail payload (item + audit timeline +
 * canAssign) from GET /tasks/detail, and carries the deep link that used to
 * live on the row itself. Assignment mirrors the queue's one write action. */
import { Button } from '@/components/ui/button';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetHeader,
    SheetTitle,
} from '@/components/ui/sheet';
import { StatusBadge } from '@/components/ui/status-badge';
import { formatDateTime } from '@/lib/datetime';
import { cn } from '@/lib/utils';
import { router } from '@inertiajs/react';
import { AlertTriangle, ExternalLink, History, Loader2, UserCheck, UserX } from 'lucide-react';
import { useCallback, useEffect, useRef, useState, type ReactNode } from 'react';
import { dueInfo, humanise, SEVERITY_VARIANT, taskNumericId, type TaskItem } from './types';

interface TimelineEntry {
    id: number;
    action: string;
    user: string | null;
    at: string | null;
}

interface TaskDetail {
    item: TaskItem;
    timeline: TimelineEntry[];
    canAssign: boolean;
}

function MetaRow({ label, children }: { label: string; children: ReactNode }) {
    return (
        <div className="flex items-baseline justify-between gap-4 py-1.5">
            <dt className="shrink-0 text-xs font-semibold text-muted-foreground">{label}</dt>
            <dd className="min-w-0 text-right text-sm">{children}</dd>
        </div>
    );
}

export function TaskDetailDrawer({
    item,
    currentUserId,
    onClose,
}: {
    item: TaskItem | null;
    currentUserId: number;
    onClose: () => void;
}) {
    const [detail, setDetail] = useState<TaskDetail | null>(null);
    const [loading, setLoading] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const [assigning, setAssigning] = useState(false);
    // Guards against a slow response landing after the user opened another row.
    const seq = useRef(0);

    const fetchDetail = useCallback(async (target: TaskItem) => {
        const mySeq = ++seq.current;
        setLoading(true);
        setError(null);
        try {
            const params = new URLSearchParams({ source: target.source, id: taskNumericId(target) });
            const res = await fetch(`/tasks/detail?${params.toString()}`, {
                headers: { Accept: 'application/json' },
                credentials: 'same-origin',
            });
            if (!res.ok) throw new Error(`HTTP ${res.status}`);
            const data = (await res.json()) as TaskDetail;
            if (seq.current === mySeq) setDetail(data);
        } catch {
            if (seq.current === mySeq) setError('Could not load this task. It may have been removed, or you may not have access.');
        } finally {
            if (seq.current === mySeq) setLoading(false);
        }
    }, []);

    useEffect(() => {
        if (!item) return;
        setDetail(null);
        setError(null);
        void fetchDetail(item);
    }, [item, fetchDetail]);

    // Prefer the freshly fetched item (it reflects assignment changes) over
    // the possibly stale row the drawer was opened from.
    const display = detail?.item ?? item;

    const assign = (assigneeId: number | null) => {
        if (!item) return;
        setAssigning(true);
        router.post(
            `/tasks/${item.source}/${taskNumericId(item)}/assign`,
            { assignee_id: assigneeId },
            {
                preserveState: true,
                preserveScroll: true,
                onSuccess: () => void fetchDetail(item),
                onFinish: () => setAssigning(false),
            },
        );
    };

    const due = display ? dueInfo(display) : null;
    const assignedToMe = display?.assignee?.id === currentUserId;

    return (
        <Sheet open={item !== null} onOpenChange={(open) => !open && onClose()}>
            <SheetContent side="right" data-test="tasks-drawer" className="w-full gap-0 sm:max-w-lg">
                {display ? (
                    <>
                        <SheetHeader className="border-b border-border pb-4">
                            <div className="flex flex-wrap items-center gap-1.5 pr-8">
                                {display.ref ? (
                                    <span className="rounded-md bg-muted px-1.5 py-0.5 font-mono text-[11px] font-semibold text-muted-foreground">
                                        {display.ref}
                                    </span>
                                ) : null}
                                <StatusBadge variant={SEVERITY_VARIANT[display.severity]} size="sm">
                                    {humanise(display.severity)}
                                </StatusBadge>
                                <StatusBadge variant="neutral" size="sm">
                                    {humanise(display.status)}
                                </StatusBadge>
                            </div>
                            <SheetTitle className="text-base leading-snug">{display.title}</SheetTitle>
                            <SheetDescription>
                                {display.type ? `${display.type} · ` : ''}
                                {display.sourceLabel}
                            </SheetDescription>
                        </SheetHeader>

                        <div className="flex-1 overflow-y-auto px-4 py-4">
                            {display.description ? (
                                <p className="mb-4 text-sm whitespace-pre-line text-muted-foreground">{display.description}</p>
                            ) : null}

                            <dl className="divide-y divide-border rounded-lg border border-border px-3 py-1">
                                <MetaRow label="Assignee">
                                    {display.assignee ? (
                                        display.assignee.name
                                    ) : (
                                        <span className="text-muted-foreground">Unassigned</span>
                                    )}
                                </MetaRow>
                                <MetaRow label="Client">
                                    <span className="text-muted-foreground">{display.client?.name ?? '—'}</span>
                                </MetaRow>
                                <MetaRow label="Site">
                                    <span className="text-muted-foreground">{display.site?.name ?? '—'}</span>
                                </MetaRow>
                                <MetaRow label="Due">
                                    <span className={due?.className}>{due?.label ?? '—'}</span>
                                </MetaRow>
                                <MetaRow label="Created">
                                    <span className="text-muted-foreground">
                                        {display.createdAt ? formatDateTime(display.createdAt) : '—'}
                                    </span>
                                </MetaRow>
                            </dl>

                            {/* ── Activity timeline ── */}
                            <div className="mt-5">
                                <h3 className="mb-2 flex items-center gap-1.5 text-xs font-semibold tracking-wide text-muted-foreground uppercase">
                                    <History className="h-3.5 w-3.5" />
                                    Activity
                                </h3>
                                {loading ? (
                                    <div className="flex items-center gap-2 py-3 text-sm text-muted-foreground">
                                        <Loader2 className="h-4 w-4 animate-spin" />
                                        Loading activity…
                                    </div>
                                ) : error ? (
                                    <div className="flex flex-col items-start gap-2 rounded-lg border border-border bg-muted/40 p-3 text-sm text-muted-foreground">
                                        <span className="flex items-center gap-2">
                                            <AlertTriangle className="h-4 w-4 text-status-warning" />
                                            {error}
                                        </span>
                                        <Button variant="outline" size="sm" onClick={() => item && void fetchDetail(item)}>
                                            Retry
                                        </Button>
                                    </div>
                                ) : detail && detail.timeline.length > 0 ? (
                                    <ul className="space-y-0">
                                        {detail.timeline.map((entry, i) => (
                                            <li key={entry.id} className="relative flex gap-3 pb-3 last:pb-0">
                                                {/* Rail connecting the dots (skipped after the last entry). */}
                                                {i < detail.timeline.length - 1 ? (
                                                    <span className="absolute top-3 left-[3.5px] h-full w-px bg-border" aria-hidden />
                                                ) : null}
                                                <span className="mt-1.5 h-2 w-2 shrink-0 rounded-full bg-primary/60" aria-hidden />
                                                <div className="min-w-0 text-sm">
                                                    <div className="font-medium">{humanise(entry.action)}</div>
                                                    <div className="text-xs text-muted-foreground">
                                                        {entry.user ?? 'System'}
                                                        {entry.at ? ` · ${formatDateTime(entry.at)}` : ''}
                                                    </div>
                                                </div>
                                            </li>
                                        ))}
                                    </ul>
                                ) : (
                                    <p className="py-1 text-sm text-muted-foreground">No recorded activity yet.</p>
                                )}
                            </div>
                        </div>

                        {/* ── Footer actions ── */}
                        <div className="flex flex-wrap items-center gap-2 border-t border-border p-4">
                            <Button
                                className="flex-1"
                                disabled={!display.link}
                                onClick={() => display.link && router.visit(display.link)}
                            >
                                <ExternalLink className="h-4 w-4" />
                                Open record
                            </Button>
                            {detail?.canAssign ? (
                                assignedToMe ? (
                                    <Button variant="outline" disabled={assigning} onClick={() => assign(null)}>
                                        <UserX className="h-4 w-4" />
                                        Unassign
                                    </Button>
                                ) : (
                                    <>
                                        <Button variant="outline" disabled={assigning} onClick={() => assign(currentUserId)}>
                                            <UserCheck className="h-4 w-4" />
                                            Assign to me
                                        </Button>
                                        {display.assignee ? (
                                            <Button variant="outline" disabled={assigning} onClick={() => assign(null)}>
                                                <UserX className="h-4 w-4" />
                                                Unassign
                                            </Button>
                                        ) : null}
                                    </>
                                )
                            ) : null}
                        </div>
                    </>
                ) : (
                    <div className={cn('flex flex-1 items-center justify-center text-sm text-muted-foreground')}>
                        {/* Radix requires a title even while empty/closing. */}
                        <SheetTitle className="sr-only">Task detail</SheetTitle>
                    </div>
                )}
            </SheetContent>
        </Sheet>
    );
}

export default TaskDetailDrawer;
