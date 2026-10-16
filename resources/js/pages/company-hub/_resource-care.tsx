import { Button } from '@/components/ui/button';
import { formatDate, toDateInput } from '@/lib/datetime';
import { Link, router } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import type { EditorialPage } from './_editorial';
import { ResourceReview } from './_resource-review';

export type ResourceReport = {
    id: number;
    issue: string;
    note: string;
    created_at: string;
    resolved: boolean;
    response: string;
    version: number;
};
export function ResourceCare({
    pages,
    actorId = null,
}: {
    pages: EditorialPage[];
    actorId?: number | null;
}) {
    const [all, setAll] = useState(false);
    const [selected, setSelected] = useState<
        (ResourceReport & { page: EditorialPage }) | null
    >(null);
    const [message, setMessage] = useState('');
    const [selectionActor, setSelectionActor] = useState(actorId);
    const available =
        selected &&
        selectionActor === actorId &&
        pages.some(
            (page) =>
                page.id === selected.page.id &&
                page.reports?.some((report) => report.id === selected.id),
        );
    useEffect(() => {
        if (selected && !available) setSelected(null);
    }, [selected, available]);
    const reports = pages
        .flatMap((page) =>
            (page.reports ?? []).map((report) => ({ ...report, page })),
        )
        .filter((r) => all || !r.resolved);
    const due = pages.filter(
        (p) => p.review_on && p.review_on <= toDateInput(new Date()),
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
                {message && (
                    <p role="status" className="text-sm text-muted-foreground">
                        {message}
                    </p>
                )}
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
                                setSelectionActor(actorId);
                                setMessage('');
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
                        {page.owner} · {formatDate(page.review_on)}
                    </span>
                </Link>
            ))}
            {selected && available && (
                <ResourceReview
                    key={`${actorId}:${selected.page.id}:${selected.id}`}
                    report={selected}
                    actorId={actorId}
                    close={() => setSelected(null)}
                    onSaved={() => {
                        setMessage(
                            selected.resolved
                                ? 'The correction has been reopened.'
                                : 'Your response has been saved and the correction resolved.',
                        );
                        setSelected(null);
                        router.reload({
                            only: ['editorial'],
                            preserveScroll: true,
                        });
                    }}
                />
            )}
        </div>
    );
}
