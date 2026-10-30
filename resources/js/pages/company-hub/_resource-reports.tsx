import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetFooter,
    SheetHeader,
    SheetTitle,
} from '@/components/ui/sheet';
import { Textarea } from '@/components/ui/textarea';
import { useSettingsLeaveConfirmation } from '@/hooks/use-settings-leave-confirmation';
import { formatRelative } from '@/lib/datetime';
import { CheckCircle2, Clock3, MessageSquare, RefreshCw } from 'lucide-react';
import { useRef, useState } from 'react';
import { hubRequest } from './_api';

export type OwnResourceReport = {
    id: number;
    revision_id: number;
    current_revision: boolean;
    issue: string;
    note: string;
    resolved: boolean;
    response: string;
    version: number;
    created_at: string;
    updated_at: string;
};
export type ResourceReportContext = {
    actor_id: number;
    page_id: number;
    revision_id: number;
    reports: OwnResourceReport[];
    next_cursor: number | null;
};

export function ResourceReports({ data }: { data: ResourceReportContext }) {
    return (
        <ResourceReportPanel
            key={`${data.actor_id}:${data.page_id}:${data.revision_id}`}
            data={data}
        />
    );
}

function ResourceReportPanel({ data }: { data: ResourceReportContext }) {
    const [open, setOpen] = useState(false),
        [view, setView] = useState<'new' | 'history'>('new');
    const [issue, setIssue] = useState('Out of date'),
        [note, setNote] = useState('');
    const [reports, setReports] = useState(data.reports),
        [cursor, setCursor] = useState(data.next_cursor);
    const [busy, setBusy] = useState(false),
        [error, setError] = useState(''),
        [saved, setSaved] = useState(false);
    const pending = useRef(false);
    const leave = useSettingsLeaveConfirmation(
        !!note.trim() || busy,
        'Leave this correction?',
        'Your unsent correction stays here while this page is open. Leaving the page discards it. A correction already sent may still finish; check your reports before sending it again.',
    );
    const sameContext = (
        result: Pick<
            ResourceReportContext,
            'actor_id' | 'page_id' | 'revision_id'
        >,
    ) =>
        result.actor_id === data.actor_id &&
        result.page_id === data.page_id &&
        result.revision_id === data.revision_id;
    const refresh = async (more = false) => {
        if (pending.current) return;
        pending.current = true;
        setBusy(true);
        setError('');
        try {
            const result = await hubRequest<ResourceReportContext>(
                `/company-hub/pages/${data.page_id}/reports${more && cursor ? `?before=${cursor}` : ''}`,
                'GET',
            );
            if (!sameContext(result))
                throw new Error(
                    'Your account or this resource changed. Reload the page to see your current reports.',
                );
            setReports((current) =>
                more
                    ? [
                          ...current,
                          ...result.reports.filter(
                              (report) =>
                                  !current.some(
                                      (item) => item.id === report.id,
                                  ),
                          ),
                      ]
                    : result.reports,
            );
            setCursor(result.next_cursor);
        } catch (failure) {
            setError((failure as Error).message);
        } finally {
            pending.current = false;
            setBusy(false);
        }
    };
    const submit = async () => {
        if (pending.current || !note.trim()) return;
        pending.current = true;
        setBusy(true);
        setError('');
        try {
            const result = await hubRequest<
                Pick<
                    ResourceReportContext,
                    'actor_id' | 'page_id' | 'revision_id'
                > & { saved: boolean; report: OwnResourceReport }
            >(`/company-hub/pages/${data.page_id}/reports`, 'POST', {
                actor_id: data.actor_id,
                revision_id: data.revision_id,
                issue,
                note: note.trim(),
            });
            if (!result.saved || !sameContext(result) || !result.report?.id)
                throw new Error(
                    'We could not confirm your report. Reload the page before sending it again.',
                );
            setReports((current) => [
                result.report,
                ...current.filter((item) => item.id !== result.report.id),
            ]);
            setSaved(true);
            setNote('');
            setView('history');
        } catch (failure) {
            setError((failure as Error).message);
        } finally {
            pending.current = false;
            setBusy(false);
        }
    };
    return (
        <>
            <Button
                variant="ghost"
                onClick={() => {
                    setOpen(true);
                    setView('new');
                    setSaved(false);
                    setError('');
                }}
            >
                Report an issue
            </Button>
            {reports.length > 0 && (
                <Button
                    variant="ghost"
                    onClick={() => {
                        setOpen(true);
                        setView('history');
                        setSaved(false);
                        void refresh();
                    }}
                >
                    Your reports
                </Button>
            )}
            <Sheet
                open={open}
                onOpenChange={(value) => {
                    if (!pending.current) setOpen(value);
                }}
            >
                <SheetContent
                    side="right"
                    className="gap-0 overflow-hidden p-0 sm:max-w-[600px]"
                    overlayClassName="bg-black/30 backdrop-blur-[2px]"
                    closeButtonClassName={
                        busy ? 'pointer-events-none opacity-30' : ''
                    }
                    closeLabel="Close corrections"
                    onPointerDownOutside={(event) => event.preventDefault()}
                >
                    <SheetHeader className="shrink-0 border-b px-7 pt-7 pr-14 pb-6">
                        <SheetTitle className="flex items-center gap-2 text-xl">
                            <MessageSquare className="size-5 text-primary" />
                            Help keep this resource useful
                        </SheetTitle>
                        <SheetDescription>
                            Your correction goes to authorised page editors. You
                            can return here to see their response.
                        </SheetDescription>
                    </SheetHeader>
                    <div className="flex gap-2 border-b border-border px-6 py-3">
                        <Button
                            variant={view === 'new' ? 'secondary' : 'ghost'}
                            disabled={busy}
                            aria-pressed={view === 'new'}
                            onClick={() => setView('new')}
                        >
                            New correction
                        </Button>
                        <Button
                            variant={view === 'history' ? 'secondary' : 'ghost'}
                            disabled={busy}
                            aria-pressed={view === 'history'}
                            onClick={() => {
                                setView('history');
                                void refresh();
                            }}
                        >
                            Your reports
                        </Button>
                    </div>
                    <div className="min-h-0 flex-1 overflow-y-auto overscroll-contain px-7 py-6">
                        {saved && (
                            <p
                                role="status"
                                className="mb-5 flex items-center gap-2 text-sm"
                            >
                                <CheckCircle2 className="size-4 text-primary" />
                                Your correction has been sent for review.
                            </p>
                        )}
                        {view === 'new' ? (
                            <div className="space-y-5">
                                <div>
                                    <Label id="resource-issue-label">
                                        What needs attention?
                                    </Label>
                                    <div
                                        role="group"
                                        aria-labelledby="resource-issue-label"
                                        className="mt-3 flex flex-wrap gap-2"
                                    >
                                        {[
                                            'Out of date',
                                            'Incorrect information',
                                            'Broken link',
                                            'Other',
                                        ].map((value) => (
                                            <Button
                                                key={value}
                                                variant={
                                                    issue === value
                                                        ? 'secondary'
                                                        : 'outline'
                                                }
                                                aria-pressed={issue === value}
                                                className="rounded-full"
                                                disabled={busy}
                                                onClick={() => setIssue(value)}
                                            >
                                                {value}
                                            </Button>
                                        ))}
                                    </div>
                                </div>
                                <div>
                                    <Label htmlFor="resource-report-note">
                                        What should we look at?
                                    </Label>
                                    <Textarea
                                        id="resource-report-note"
                                        className="mt-2 min-h-32"
                                        value={note}
                                        maxLength={2000}
                                        disabled={busy}
                                        onChange={(event) =>
                                            setNote(event.target.value)
                                        }
                                        placeholder="Point us to the detail or link that needs attention…"
                                    />
                                    <p className="mt-2 text-xs text-muted-foreground">
                                        Only you and authorised page editors can
                                        see your correction. Unsent text stays
                                        here while this page is open.
                                    </p>
                                </div>
                            </div>
                        ) : (
                            <section aria-label="Your correction history">
                                <div className="mb-4 flex items-center justify-between gap-3">
                                    <h2 className="font-semibold">
                                        Your corrections and responses
                                    </h2>
                                    <Button
                                        variant="ghost"
                                        size="sm"
                                        disabled={busy}
                                        onClick={() => void refresh()}
                                    >
                                        <RefreshCw className="size-3" />
                                        Refresh
                                    </Button>
                                </div>
                                {reports.length === 0 ? (
                                    <p className="py-6 text-sm text-muted-foreground">
                                        You have not reported an issue with this
                                        resource.
                                    </p>
                                ) : (
                                    <div className="divide-y divide-border">
                                        {reports.map((report) => (
                                            <article
                                                key={report.id}
                                                aria-label={`Correction ${report.id}`}
                                                className="space-y-3 py-5 first:pt-0"
                                            >
                                                <div className="flex flex-wrap items-center justify-between gap-2">
                                                    <h3 className="text-sm font-semibold">
                                                        {report.issue}
                                                    </h3>
                                                    <span className="flex items-center gap-1.5 text-xs text-muted-foreground">
                                                        {report.resolved ? (
                                                            <CheckCircle2 className="size-4 text-primary" />
                                                        ) : (
                                                            <Clock3 className="size-4" />
                                                        )}
                                                        {report.resolved
                                                            ? 'Resolved'
                                                            : 'With the page editors'}
                                                    </span>
                                                </div>
                                                <p className="text-sm leading-6 break-words whitespace-pre-wrap">
                                                    {report.note}
                                                </p>
                                                <p className="text-xs text-muted-foreground">
                                                    Sent{' '}
                                                    {formatRelative(
                                                        report.created_at,
                                                    )}
                                                    {!report.current_revision &&
                                                        ' · An earlier version of this resource'}
                                                </p>
                                                {report.response ? (
                                                    <div className="border-l-2 border-primary/40 py-1 pl-4">
                                                        <h4 className="text-xs font-semibold">
                                                            Response from the
                                                            page editors
                                                        </h4>
                                                        <p className="mt-2 text-sm leading-6 break-words whitespace-pre-wrap">
                                                            {report.response}
                                                        </p>
                                                        <p className="mt-2 text-xs text-muted-foreground">
                                                            Updated{' '}
                                                            {formatRelative(
                                                                report.updated_at,
                                                            )}
                                                        </p>
                                                    </div>
                                                ) : (
                                                    <p className="text-xs text-muted-foreground">
                                                        The editors’ response
                                                        will appear here.
                                                    </p>
                                                )}
                                            </article>
                                        ))}
                                    </div>
                                )}
                                {cursor !== null && (
                                    <Button
                                        variant="outline"
                                        disabled={busy}
                                        onClick={() => void refresh(true)}
                                    >
                                        Earlier reports
                                    </Button>
                                )}
                            </section>
                        )}
                        {error && (
                            <p
                                role="alert"
                                className="mt-4 text-sm text-destructive"
                            >
                                {error}
                            </p>
                        )}
                    </div>
                    <SheetFooter className="mt-0 shrink-0 flex-row justify-between border-t px-7 py-5">
                        <Button
                            variant="outline"
                            disabled={busy}
                            onClick={() => setOpen(false)}
                        >
                            {note.trim() ? 'Keep draft and close' : 'Done'}
                        </Button>
                        {view === 'new' && (
                            <Button
                                disabled={busy || !note.trim()}
                                onClick={() => void submit()}
                            >
                                {busy ? 'Sending…' : 'Send correction'}
                            </Button>
                        )}
                    </SheetFooter>
                </SheetContent>
            </Sheet>
            {leave.confirmation}
        </>
    );
}
