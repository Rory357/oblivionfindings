import { KbPreview, type KbRow } from '@/components/it/it-wizards';
import { KnowledgeDiagrams } from '@/components/it/knowledge-diagrams';
import {
    KNOWLEDGE_DOCUMENT_TYPES,
    KNOWLEDGE_SECTIONS,
} from '@/components/it/knowledge-document';
import { useKnowledgeRaster } from '@/components/it/knowledge-raster';
import { KnowledgeRelatedRecords } from '@/components/it/knowledge-related-records';
import {
    AlertDialog,
    AlertDialogAction,
    AlertDialogCancel,
    AlertDialogContent,
    AlertDialogDescription,
    AlertDialogFooter,
    AlertDialogHeader,
    AlertDialogTitle,
} from '@/components/ui/alert-dialog';
import { Button } from '@/components/ui/button';
import { Textarea } from '@/components/ui/textarea';
import { WizardShell } from '@/components/wizard/shell';
import { formatDateOnly, formatDateTime } from '@/lib/datetime';
import { router } from '@inertiajs/react';
import axios from 'axios';
import { FileClock, FileText } from 'lucide-react';
import { useEffect, useLayoutEffect, useRef, useState } from 'react';

type Content = Partial<
    Pick<
        KbRow,
        | 'title'
        | 'category'
        | 'document_type'
        | 'body'
        | 'structured_content'
        | 'audience'
        | 'site_scope'
        | 'review_due_at'
        | 'related_records'
        | 'diagrams'
        | 'file_ids'
        | 'tags'
    >
> & {
    metadata?: {
        owner: string | null;
        service: string | null;
        sites: string[];
    };
};
type Revision = {
    id: number;
    number: number;
    event: string;
    recorded_at: string;
    published_at: string | null;
    recorded_by: string | null;
    content: Content;
};
type Payload = {
    actor_user_id: number;
    article_id: number;
    lock_version: number;
    current_content: Content;
    revisions: Revision[];
    next_before_revision: number | null;
    working_copy: { status: string; content: Content } | null;
};

export function KnowledgeRevisionDialog({
    article,
    actorId,
    onClose,
}: {
    article: KbRow;
    actorId: number;
    onClose: () => void;
}) {
    const [data, setData] = useState<Payload | null>(null);
    const [loadedArticle, setLoadedArticle] = useState<KbRow | null>(null);
    const [needsPageRefresh, setNeedsPageRefresh] = useState(false);
    const latestArticle = useRef(article);
    const closeLatest = useRef(onClose);
    useLayoutEffect(() => {
        latestArticle.current = article;
        closeLatest.current = onClose;
    }, [article, onClose]);
    const [error, setError] = useState('');
    const [reload, setReload] = useState(0);
    const [beforeRevision, setBeforeRevision] = useState<number | null>(null);
    const [selection, setSelection] = useState(0);
    const [busy, setBusy] = useState(false);
    const [confirmation, setConfirmation] = useState<
        'publish' | 'restore' | 'discard' | null
    >(null);
    const [reason, setReason] = useState('');
    useEffect(() => {
        const controller = new AbortController();
        setData(null);
        setLoadedArticle(null);
        setError('');
        setSelection(0);
        setConfirmation(null);
        setNeedsPageRefresh(false);
        axios
            .get<Payload>(`/it/knowledge/${article.id}/history`, {
                signal: controller.signal,
                params: {
                    actor_user_id: actorId,
                    before_revision: beforeRevision ?? undefined,
                },
                timeout: 30000,
            })
            .then(({ data: result }) => {
                if (
                    controller.signal.aborted ||
                    latestArticle.current !== article
                )
                    return;
                if (result.actor_user_id !== actorId) {
                    closeLatest.current();
                    return;
                }
                if (
                    result.article_id !== article.id ||
                    result.lock_version !== article.lock_version ||
                    !Array.isArray(result.revisions) ||
                    !result.current_content ||
                    typeof result.current_content.title !== 'string'
                ) {
                    setNeedsPageRefresh(true);
                    throw new Error(
                        'The article or account changed. Close this window and refresh Knowledge before continuing.',
                    );
                }
                setLoadedArticle(article);
                setData(result);
            })
            .catch((cause: unknown) => {
                if (
                    controller.signal.aborted ||
                    latestArticle.current !== article
                )
                    return;
                setData(null);
                if (
                    axios.isAxiosError(cause) &&
                    [401, 403, 404, 419].includes(cause.response?.status ?? 0)
                ) {
                    closeLatest.current();
                    return;
                }
                setError(
                    axios.isAxiosError(cause) &&
                        [401, 403, 404, 419].includes(
                            cause.response?.status ?? 0,
                        )
                        ? 'This history is no longer available to your current account. Close this window and refresh Knowledge.'
                        : cause instanceof Error && !axios.isAxiosError(cause)
                          ? cause.message
                          : 'Revision history could not be loaded. Try again.',
                );
            });
        return () => controller.abort();
    }, [article, actorId, reload, beforeRevision]);
    const current =
        loadedArticle === article &&
        data?.actor_user_id === actorId &&
        data.article_id === article.id &&
        data.lock_version === article.lock_version
            ? data
            : null;
    const selected = selection === 0 ? null : current?.revisions[selection - 1];
    const proposal = current?.working_copy?.content ?? current?.current_content;
    const publication = current?.current_content;
    const displayed = selected?.content ?? proposal ?? {};
    const rasterRevision =
        selected?.published_at &&
        ['published', 'previous_publication_captured'].includes(selected.event)
            ? selected.id
            : undefined;
    const raster = useKnowledgeRaster({
        actorId,
        articleId: article.id,
        revisionId: rasterRevision,
    });
    const command = () => {
        if (!current || busy || !confirmation) return;
        const action = confirmation;
        const path =
            action === 'publish'
                ? `/it/kb/${article.id}/publish`
                : `/it/knowledge/${article.id}/${action}-revision`;
        setBusy(true);
        setError('');
        router.post(
            path,
            {
                actor_user_id: actorId,
                lock_version: current.lock_version,
                ...(action === 'restore' && selected
                    ? { revision_id: selected.id }
                    : {}),
                ...(action === 'discard' ? { reason } : {}),
            },
            {
                preserveScroll: true,
                onSuccess: (page) => {
                    const flash = (
                        page.props.flash as { error?: string } | undefined
                    )?.error;
                    if (flash) {
                        setError(flash);
                        return;
                    }
                    onClose();
                },
                onError: (errors) => {
                    if (
                        errors.knowledge_relationship_access ||
                        errors.lock_version
                    ) {
                        setData(null);
                        setNeedsPageRefresh(true);
                    }
                    setError(
                        Object.values(errors)[0] ??
                            'The revision could not be updated. Your selection is retained.',
                    );
                },
                onFinish: () => {
                    setBusy(false);
                    setConfirmation(null);
                },
            },
        );
    };
    const steps = [
        {
            key: 'current',
            label: current?.working_copy
                ? 'Proposed revision'
                : 'Current article',
            blurb: 'Review the complete content',
            icon: FileText,
        },
        ...(current?.revisions ?? []).map((revision) => ({
            key: String(revision.id),
            label: `Revision ${revision.number}`,
            blurb: formatDateTime(revision.recorded_at),
            icon: FileClock,
        })),
    ];
    return (
        <>
            <WizardShell
                open
                onClose={() => {
                    if (!busy) onClose();
                }}
                title={
                    current
                        ? `Revision history: ${article.title}`
                        : 'Revision history'
                }
                description="Review the proposal and earlier publications. Restoring an earlier version creates a draft for review."
                railIcon={FileClock}
                railTitle="Revision history"
                railSub={current ? article.title : 'Current access check'}
                steps={steps.map((step) => ({ ...step, disabled: busy }))}
                stepIndex={selection}
                onStepClick={setSelection}
                headerLabel={
                    selected
                        ? `Revision ${selected.number}`
                        : current?.working_copy
                          ? 'Review proposed revision'
                          : 'Current article'
                }
                footerStart={
                    <>
                        <Button
                            variant="outline"
                            disabled={busy}
                            onClick={onClose}
                        >
                            Close
                        </Button>
                        {beforeRevision !== null && (
                            <Button
                                variant="outline"
                                disabled={busy || !current}
                                onClick={() => setBeforeRevision(null)}
                            >
                                Latest revisions
                            </Button>
                        )}
                        {current?.next_before_revision && (
                            <Button
                                variant="outline"
                                disabled={busy}
                                onClick={() =>
                                    setBeforeRevision(
                                        current.next_before_revision,
                                    )
                                }
                            >
                                Older revisions
                            </Button>
                        )}
                    </>
                }
                footerEnd={
                    current ? (
                        <>
                            {selection === 0 &&
                                current.working_copy &&
                                article.can.author && (
                                    <Button
                                        variant="outline"
                                        disabled={busy || !reason.trim()}
                                        onClick={() =>
                                            setConfirmation('discard')
                                        }
                                    >
                                        Discard proposed revision
                                    </Button>
                                )}
                            {selected &&
                                article.can.author &&
                                ['published', 'draft'].includes(
                                    article.status,
                                ) &&
                                current.working_copy?.status !==
                                    'in_review' && (
                                    <Button
                                        disabled={busy}
                                        onClick={() =>
                                            setConfirmation('restore')
                                        }
                                    >
                                        Restore as draft
                                    </Button>
                                )}
                            {selection === 0 &&
                                article.can.review &&
                                (current.working_copy?.status ??
                                    article.status) === 'in_review' && (
                                    <Button
                                        disabled={busy}
                                        onClick={() =>
                                            setConfirmation('publish')
                                        }
                                    >
                                        Approve & publish
                                    </Button>
                                )}
                        </>
                    ) : undefined
                }
            >
                {error && (
                    <div
                        role="alert"
                        className="mb-4 rounded-lg border border-destructive/30 p-3 text-sm"
                    >
                        {error}
                        <Button
                            className="ml-2"
                            variant="outline"
                            disabled={busy}
                            onClick={() => {
                                if (needsPageRefresh)
                                    router.reload({ preserveScroll: true });
                                else setReload((value) => value + 1);
                            }}
                        >
                            {needsPageRefresh ? 'Refresh Knowledge' : 'Retry'}
                        </Button>
                    </div>
                )}
                {!current && !error && (
                    <p role="status">Loading revision history…</p>
                )}
                {current && (
                    <div className="space-y-5">
                        {selection === 0 && current.working_copy && (
                            <p className="rounded-lg border border-border bg-muted p-3 text-sm">
                                The approved publication remains available while
                                this proposed revision is reviewed.
                            </p>
                        )}
                        <h2 className="text-section-title">
                            {displayed.title}
                        </h2>
                        <dl className="grid gap-3 text-sm sm:grid-cols-2">
                            <div>
                                <dt className="font-medium">Document type</dt>
                                <dd>
                                    {KNOWLEDGE_DOCUMENT_TYPES.find(
                                        (type) =>
                                            type.value ===
                                            displayed.document_type,
                                    )?.label ?? 'Guide'}
                                </dd>
                            </div>
                            <div>
                                <dt className="font-medium">Category</dt>
                                <dd>{displayed.category ?? 'Not recorded'}</dd>
                            </div>
                            <div>
                                <dt className="font-medium">Tags</dt>
                                <dd>
                                    {displayed.tags?.length
                                        ? displayed.tags.join(', ')
                                        : 'No tags'}
                                </dd>
                            </div>
                            <div>
                                <dt className="font-medium">Audience</dt>
                                <dd>
                                    {displayed.audience?.replaceAll('_', ' ') ??
                                        'Not recorded'}
                                </dd>
                            </div>
                            <div>
                                <dt className="font-medium">Sites</dt>
                                <dd>
                                    {displayed.metadata?.sites.length
                                        ? displayed.metadata.sites.join(', ')
                                        : 'No specific Sites'}
                                </dd>
                            </div>
                            <div>
                                <dt className="font-medium">Review owner</dt>
                                <dd>
                                    {displayed.metadata?.owner ??
                                        'Owner no longer available'}
                                </dd>
                            </div>
                            <div>
                                <dt className="font-medium">Review due</dt>
                                <dd>
                                    {formatDateOnly(
                                        displayed.review_due_at,
                                        'Not set',
                                    )}
                                </dd>
                            </div>
                            <div>
                                <dt className="font-medium">Primary service</dt>
                                <dd>
                                    {displayed.metadata?.service ??
                                        'None selected'}
                                </dd>
                            </div>
                            {selected && (
                                <div>
                                    <dt className="font-medium">Recorded by</dt>
                                    <dd>
                                        {selected.recorded_by ??
                                            'User no longer available'}{' '}
                                        · {formatDateTime(selected.recorded_at)}
                                    </dd>
                                </div>
                            )}
                        </dl>
                        <KbPreview body={displayed.body ?? ''} />
                        <KnowledgeDiagrams
                            diagrams={displayed.diagrams ?? []}
                            recordKey={`${actorId}:${article.id}:history:${selected?.id ?? 'proposal'}`}
                            raster={raster}
                        />
                        {!!displayed.file_ids?.length && (
                            <div className="space-y-2">
                                <h3 className="text-section-title">
                                    Files in this revision
                                </h3>
                                {displayed.file_ids.map((id) => (
                                    <a
                                        key={id}
                                        className="block text-primary underline"
                                        href={`/it/knowledge/${article.id}/files/${id}`}
                                        target="_blank"
                                        rel="noreferrer"
                                    >
                                        Open file {id}
                                    </a>
                                ))}
                            </div>
                        )}
                        <KnowledgeRelatedRecords
                            records={displayed.related_records}
                        />
                        {KNOWLEDGE_SECTIONS.filter(
                            (field) =>
                                displayed.structured_content?.[field.key],
                        ).map((field) => (
                            <section key={field.key}>
                                <h3 className="text-section-title">
                                    {field.label}
                                </h3>
                                <KbPreview
                                    body={
                                        displayed.structured_content?.[
                                            field.key
                                        ] ?? ''
                                    }
                                />
                            </section>
                        ))}
                        {selection === 0 && current.working_copy && (
                            <details className="rounded-lg border border-border p-4">
                                <summary className="cursor-pointer text-sm font-semibold">
                                    Compare with the current publication
                                </summary>
                                <div className="mt-4 space-y-4">
                                    <h3 className="text-section-title">
                                        {publication?.title}
                                    </h3>
                                    <KbPreview body={publication?.body ?? ''} />
                                    <p className="text-subtle">
                                        Tags:{' '}
                                        {publication?.tags?.join(', ') ||
                                            'None'}
                                    </p>
                                    <KnowledgeDiagrams
                                        diagrams={publication?.diagrams ?? []}
                                        recordKey={`${actorId}:${article.id}:history-publication`}
                                        raster={raster}
                                    />
                                    {!!publication?.file_ids?.length && (
                                        <p className="text-sm">
                                            Publication files:{' '}
                                            {publication.file_ids.join(', ')}
                                        </p>
                                    )}
                                    <KnowledgeRelatedRecords
                                        records={publication?.related_records}
                                    />
                                    {KNOWLEDGE_SECTIONS.filter(
                                        (field) =>
                                            publication?.structured_content?.[
                                                field.key
                                            ],
                                    ).map((field) => (
                                        <section key={field.key}>
                                            <h4 className="text-sm font-semibold">
                                                {field.label}
                                            </h4>
                                            <KbPreview
                                                body={
                                                    publication
                                                        ?.structured_content?.[
                                                        field.key
                                                    ] ?? ''
                                                }
                                            />
                                        </section>
                                    ))}
                                </div>
                            </details>
                        )}
                        {selection === 0 &&
                            current.working_copy &&
                            article.can.author && (
                                <label className="block space-y-2 text-sm font-medium">
                                    Reason for discarding, if needed
                                    <Textarea
                                        value={reason}
                                        onChange={(event) =>
                                            setReason(event.target.value)
                                        }
                                        maxLength={2000}
                                        disabled={busy}
                                    />
                                </label>
                            )}
                        {current.revisions.length === 0 && (
                            <p className="text-sm text-muted-foreground">
                                No published revisions are available within your
                                current access.
                            </p>
                        )}
                    </div>
                )}
            </WizardShell>
            {confirmation !== null && (
                <AlertDialog
                    open
                    onOpenChange={(open) => {
                        if (!open && !busy) setConfirmation(null);
                    }}
                >
                    <AlertDialogContent>
                        <AlertDialogHeader>
                            <AlertDialogTitle>
                                {confirmation === 'publish'
                                    ? 'Publish this reviewed revision?'
                                    : confirmation === 'restore'
                                      ? 'Restore this revision as a draft?'
                                      : 'Discard the proposed revision?'}
                            </AlertDialogTitle>
                            <AlertDialogDescription>
                                {confirmation === 'publish'
                                    ? 'This content will become the publication for its selected audience.'
                                    : confirmation === 'restore'
                                      ? 'The historical record will be preserved. The restored content must be reviewed before publication.'
                                      : 'The current publication stays available. The unsaved proposal will be removed.'}
                            </AlertDialogDescription>
                        </AlertDialogHeader>
                        <AlertDialogFooter>
                            <AlertDialogCancel disabled={busy}>
                                Cancel
                            </AlertDialogCancel>
                            <AlertDialogAction
                                disabled={busy}
                                onClick={(event) => {
                                    event.preventDefault();
                                    command();
                                }}
                                className={
                                    confirmation === 'discard'
                                        ? 'bg-destructive text-destructive-foreground hover:bg-destructive/90'
                                        : undefined
                                }
                            >
                                {busy
                                    ? 'Saving…'
                                    : confirmation === 'publish'
                                      ? 'Publish revision'
                                      : confirmation === 'restore'
                                        ? 'Restore draft'
                                        : 'Discard proposal'}
                            </AlertDialogAction>
                        </AlertDialogFooter>
                    </AlertDialogContent>
                </AlertDialog>
            )}
        </>
    );
}
