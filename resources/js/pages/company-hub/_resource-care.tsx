import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { Link, router } from '@inertiajs/react';
import { useState } from 'react';
import { hubRequest } from './_api';
import type { EditorialPage } from './_editorial';

export type ResourceReport = {
    id: number;
    issue: string;
    note: string;
    created_at: string;
    resolved: boolean;
    response: string;
    version: number;
};
export function ReportResource({ pageId }: { pageId: number }) {
    const [open, setOpen] = useState(false),
        [issue, setIssue] = useState('Out of date'),
        [note, setNote] = useState(''),
        [busy, setBusy] = useState(false),
        [error, setError] = useState(''),
        [saved, setSaved] = useState(false);
    return (
        <>
            <Button
                variant="ghost"
                onClick={() => {
                    setOpen(true);
                    setSaved(false);
                }}
            >
                Report an issue
            </Button>
            <Dialog open={open} onOpenChange={setOpen}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>
                            Help keep this resource useful
                        </DialogTitle>
                        <DialogDescription>
                            Share a correction with the people who maintain this
                            page. Your report is visible to authorised page
                            editors.
                        </DialogDescription>
                    </DialogHeader>
                    {saved ? (
                        <p role="status" className="py-5">
                            Your report has been saved for the page editors to
                            review.
                        </p>
                    ) : (
                        <div className="space-y-4 py-3">
                            <Label className="grid gap-2">
                                What needs attention?
                                <select
                                    className="hub-native-select"
                                    value={issue}
                                    onChange={(e) => setIssue(e.target.value)}
                                >
                                    {[
                                        'Out of date',
                                        'Incorrect information',
                                        'Broken link',
                                        'Other',
                                    ].map((value) => (
                                        <option key={value}>{value}</option>
                                    ))}
                                </select>
                            </Label>
                            <Label className="grid gap-2">
                                Details
                                <Textarea
                                    value={note}
                                    maxLength={2000}
                                    onChange={(e) => setNote(e.target.value)}
                                    placeholder="Tell us what needs correcting…"
                                />
                            </Label>
                            {error && (
                                <p
                                    role="alert"
                                    className="text-sm text-destructive"
                                >
                                    {error}
                                </p>
                            )}
                        </div>
                    )}
                    <DialogFooter>
                        <Button
                            variant="outline"
                            onClick={() => setOpen(false)}
                        >
                            {saved ? 'Done' : 'Cancel'}
                        </Button>
                        {!saved && (
                            <Button
                                disabled={busy || !note.trim()}
                                onClick={async () => {
                                    setBusy(true);
                                    setError('');
                                    try {
                                        await hubRequest(
                                            `/company-hub/pages/${pageId}/reports`,
                                            'POST',
                                            { issue, note },
                                        );
                                        setSaved(true);
                                        setNote('');
                                    } catch (e) {
                                        setError((e as Error).message);
                                    } finally {
                                        setBusy(false);
                                    }
                                }}
                            >
                                Send report
                            </Button>
                        )}
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </>
    );
}
export function ResourceCare({ pages }: { pages: EditorialPage[] }) {
    const [all, setAll] = useState(false);
    const [selected, setSelected] = useState<
        (ResourceReport & { page: EditorialPage }) | null
    >(null);
    const [response, setResponse] = useState(''),
        [error, setError] = useState(''),
        [busy, setBusy] = useState(false);
    const reports = pages
        .flatMap((page) =>
            (page.reports ?? []).map((report) => ({ ...report, page })),
        )
        .filter((r) => all || !r.resolved);
    const due = pages.filter(
        (p) =>
            p.review_on &&
            p.review_on <= new Date().toLocaleDateString('en-CA'),
    );
    return (
        <div className="space-y-5">
            <div className="flex items-center justify-between">
                <h2 className="text-xl font-semibold">
                    Keep your resources useful
                </h2>
                <Button variant="outline" onClick={() => setAll(!all)}>
                    {all ? 'Show open reports' : 'Include resolved reports'}
                </Button>
            </div>
            <div className="space-y-3">
                {reports.map((report) => (
                    <article className="hub-update-row" key={report.id}>
                        <div className="min-w-0 flex-1">
                            <p className="text-xs text-primary">
                                {report.resolved ? 'Resolved' : 'Open'} ·{' '}
                                {report.issue}
                            </p>
                            <h3 className="mt-1 font-semibold">
                                {report.page.title}
                            </h3>
                            <p className="mt-2 text-sm whitespace-pre-wrap">
                                {report.note}
                            </p>
                            {report.response && (
                                <p className="mt-2 text-xs text-muted-foreground">
                                    Editor response: {report.response}
                                </p>
                            )}
                        </div>
                        <Button
                            variant="outline"
                            onClick={() => {
                                setSelected(report);
                                setResponse(report.response);
                                setError('');
                            }}
                        >
                            {report.resolved
                                ? 'Reopen report'
                                : 'Review report'}
                        </Button>
                        <Link
                            className="text-xs text-primary"
                            href={`/company-hub/manage/${report.page.id}/edit`}
                        >
                            Edit page
                        </Link>
                    </article>
                ))}
                {!reports.length && (
                    <p className="hub-empty-note">
                        No resource reports to show.
                    </p>
                )}
            </div>
            <h3 className="font-semibold">Reviews due · {due.length}</h3>
            {due.map((page) => (
                <Link
                    className="hub-update-row"
                    key={page.id}
                    href={`/company-hub/manage/${page.id}/edit`}
                >
                    <span className="flex-1">{page.title}</span>
                    <span className="text-xs text-muted-foreground">
                        {page.owner} · {page.review_on}
                    </span>
                </Link>
            ))}
            <Dialog
                open={!!selected}
                onOpenChange={(open) => !open && setSelected(null)}
            >
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>
                            {selected?.resolved
                                ? 'Reopen resource report'
                                : 'Resolve resource report'}
                        </DialogTitle>
                        <DialogDescription>
                            Record what you changed or why this needs another
                            look. Edits to the resource follow its usual
                            publishing process.
                        </DialogDescription>
                    </DialogHeader>
                    <Label className="grid gap-2">
                        Editor response
                        <Textarea
                            value={response}
                            maxLength={2000}
                            onChange={(e) => setResponse(e.target.value)}
                        />
                    </Label>
                    {error && (
                        <p role="alert" className="text-sm text-destructive">
                            {error}
                        </p>
                    )}
                    <DialogFooter>
                        <Button
                            variant="outline"
                            onClick={() => setSelected(null)}
                        >
                            Cancel
                        </Button>
                        <Button
                            disabled={busy || !response.trim()}
                            onClick={async () => {
                                if (!selected) return;
                                setBusy(true);
                                setError('');
                                try {
                                    await hubRequest(
                                        `/company-hub/manage/${selected.page.id}/reports/${selected.id}`,
                                        'PUT',
                                        {
                                            resolved: !selected.resolved,
                                            response,
                                            version: selected.version,
                                        },
                                    );
                                    setSelected(null);
                                    router.reload({
                                        only: ['editorial'],
                                        preserveScroll: true,
                                    });
                                } catch (e) {
                                    setError((e as Error).message);
                                } finally {
                                    setBusy(false);
                                }
                            }}
                        >
                            {selected?.resolved
                                ? 'Reopen report'
                                : 'Mark resolved'}
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </div>
    );
}
