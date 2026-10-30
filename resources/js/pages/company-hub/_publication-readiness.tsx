import { Button } from '@/components/ui/button';
import { CheckCircle2, FilePenLine, LockKeyhole } from 'lucide-react';
import { useState } from 'react';

export type HubReadiness = {
    actor_id: number;
    page_id: number;
    version: number;
    content_ready: boolean;
    editable: boolean;
    blocked_reason: 'review' | 'schedule' | null;
    blocking_revision_id: number | null;
    issues: Array<{
        section_id: string | null;
        block_id: string | null;
        field: string;
        code: string;
        message: string;
    }>;
};

export function PublicationReadiness({
    readiness,
    dirty,
    saving,
    onFix,
    onReview,
}: {
    readiness: HubReadiness;
    dirty: boolean;
    saving: boolean;
    onFix: (issue: HubReadiness['issues'][number]) => void;
    onReview: () => void;
}) {
    const [expanded, setExpanded] = useState(false);
    if (!readiness.editable) {
        return (
            <section
                aria-label="Publication status"
                className="flex flex-wrap items-start gap-3 rounded-xl border border-primary/20 bg-primary/5 p-4"
            >
                <LockKeyhole className="mt-0.5 size-5 text-primary" />
                <div className="min-w-0 flex-1">
                    <h2 className="text-sm font-semibold">
                        {readiness.blocked_reason === 'schedule'
                            ? 'This draft is scheduled'
                            : 'This draft is awaiting review'}
                    </h2>
                    <p className="mt-1 text-sm text-muted-foreground">
                        {readiness.blocked_reason === 'schedule'
                            ? 'Cancel the scheduled publication in Versions before changing this draft.'
                            : 'Withdraw the review, or have the reviewer return it for changes, before editing.'}
                    </p>
                </div>
                <Button size="sm" variant="outline" onClick={onReview}>
                    View review and schedule
                </Button>
            </section>
        );
    }
    if (dirty || saving)
        return (
            <p role="status" className="px-1 text-sm text-muted-foreground">
                {saving
                    ? 'Saving your draft and checking what is ready…'
                    : 'Save your draft to update the publication checks.'}
            </p>
        );
    return (
        <section
            aria-label="Publication readiness"
            className="rounded-lg border bg-card px-3 py-2"
        >
            <div className="flex items-center gap-2">
                {readiness.content_ready ? (
                    <CheckCircle2 className="size-4 text-status-success" />
                ) : (
                    <FilePenLine className="size-4 text-primary" />
                )}
                <h2 className="text-sm font-semibold">
                    {readiness.content_ready
                        ? 'Content ready for publication'
                        : 'Before colleagues see this'}
                </h2>
                {!readiness.content_ready && (
                    <Button
                        size="sm"
                        variant="ghost"
                        className="ml-auto h-7"
                        aria-expanded={expanded}
                        onClick={() => setExpanded((value) => !value)}
                    >
                        {expanded
                            ? 'Hide checks'
                            : `View ${readiness.issues.length} ${readiness.issues.length === 1 ? 'check' : 'checks'}`}
                    </Button>
                )}
            </div>
            {!readiness.content_ready && expanded && (
                <>
                    <p className="mt-2 text-sm text-muted-foreground">
                        Your draft is saved. Finish these details, or hide
                        unfinished blocks, before submitting or publishing.
                    </p>
                    <ul className="mt-3 divide-y divide-border">
                        {readiness.issues.map((issue, index) => (
                            <li
                                key={`${issue.field}:${index}`}
                                className="flex items-center justify-between gap-4 py-2 text-sm"
                            >
                                <span>{issue.message}</span>
                                <Button
                                    size="sm"
                                    variant="ghost"
                                    className="shrink-0"
                                    onClick={() => onFix(issue)}
                                >
                                    {issue.block_id
                                        ? 'Edit block'
                                        : 'Page settings'}
                                </Button>
                            </li>
                        ))}
                    </ul>
                </>
            )}
        </section>
    );
}
