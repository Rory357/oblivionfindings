import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { formatDateTime } from '@/lib/datetime';
import { shiftInputInstant } from '@/lib/workforce-time-input';
import { Eye, FileText, History, MessageSquare, RotateCcw } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { hubRequest } from './_api';
import { blockLibrary, blockSpan } from './_layout';
import type { HubBlock, HubContent } from './_types';

export type RevisionSummary = {
    id: number;
    state: string;
    draft_version: number;
    created_at: string;
    publish_at: string | null;
    expires_at?: string | null;
    title?: string;
    author?: string;
    approved_by_name?: string | null;
    reviewed_by_name?: string | null;
    reviewed_at?: string | null;
    review_note?: string | null;
    is_current_draft?: boolean;
    is_live?: boolean;
    can_approve?: boolean;
    can_return?: boolean;
    can_withdraw?: boolean;
    can_cancel_schedule?: boolean;
};
type RevisionDetail = RevisionSummary & {
    content: HubContent;
    page_version: number;
};
type HistoryPage = { items: RevisionSummary[]; next_cursor: number | null };

export function revisionLabel(revision: RevisionSummary) {
    if (revision.state === 'approved') {
        if (revision.is_live) return 'Live version';
        if (revision.publish_at && new Date(revision.publish_at) > new Date())
            return 'Scheduled';
        if (revision.expires_at && new Date(revision.expires_at) < new Date())
            return 'Expired';
        return 'Previously published';
    }
    return (
        (
            {
                submitted: 'Awaiting review',
                changes_requested: 'Changes requested',
                withdrawn: 'Review withdrawn',
                cancelled: 'Schedule cancelled',
                superseded: 'Superseded',
            } as Record<string, string>
        )[revision.state] ?? 'Saved version'
    );
}

export function RevisionHistory({
    pageId,
    version,
    revisions,
    cursor = null,
    disabled,
    onChanged,
}: {
    pageId: number;
    version: number;
    revisions: RevisionSummary[];
    cursor?: number | null;
    disabled: boolean;
    onChanged: () => void;
}) {
    const [items, setItems] = useState(revisions);
    const [next, setNext] = useState(cursor);
    const [selected, setSelected] = useState<number | null>(null);
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState('');
    const generation = useRef(0);
    useEffect(() => {
        generation.current += 1;
        setItems(revisions);
        setNext(cursor);
        setBusy(false);
        setError('');
        return () => {
            generation.current += 1;
        };
    }, [pageId, revisions, cursor]);
    return (
        <section className="hub-version-history">
            <div className="mb-4 flex items-start gap-3">
                <History className="mt-1 size-5 text-primary" />
                <div>
                    <h2 className="text-lg font-semibold">
                        Version history & review
                    </h2>
                    <p className="mt-1 text-sm text-muted-foreground">
                        Open an exact saved version before reviewing or
                        restoring it. The current draft is separate.
                    </p>
                </div>
            </div>
            {!items.length && (
                <p className="hub-empty-note">
                    Your first submitted or published version will appear here.
                </p>
            )}
            {items.map((revision) => (
                <article className="hub-version-row" key={revision.id}>
                    <div className="hub-version-number">
                        v{revision.draft_version}
                    </div>
                    <div className="min-w-0 flex-1">
                        <div className="flex flex-wrap items-center gap-2">
                            <h3 className="font-medium">
                                {revision.title ??
                                    `Version ${revision.draft_version}`}
                            </h3>
                            <span
                                className="hub-version-state"
                                data-state={revision.state}
                            >
                                {revisionLabel(revision)}
                            </span>
                        </div>
                        <p className="mt-1 text-xs text-muted-foreground">
                            {revision.author ? `${revision.author} · ` : ''}
                            {formatDateTime(revision.created_at)}
                            {revision.is_current_draft
                                ? ' · Matches current draft'
                                : ''}
                        </p>
                        {revision.review_note && (
                            <p className="mt-2 line-clamp-2 text-sm text-muted-foreground">
                                {revision.reviewed_by_name
                                    ? `${revision.reviewed_by_name}: `
                                    : ''}
                                {revision.review_note}
                            </p>
                        )}
                    </div>
                    <Button
                        variant="outline"
                        size="sm"
                        onClick={() => setSelected(revision.id)}
                    >
                        <Eye className="size-4" />
                        {revision.state === 'submitted'
                            ? 'Review version'
                            : 'View version'}
                    </Button>
                </article>
            ))}
            {error && (
                <p role="alert" className="mt-3 text-sm text-destructive">
                    {error}
                </p>
            )}
            {next && (
                <Button
                    className="mt-4"
                    variant="ghost"
                    disabled={busy}
                    onClick={async () => {
                        const requestGeneration = generation.current;
                        setBusy(true);
                        setError('');
                        try {
                            const page = await hubRequest<HistoryPage>(
                                `/company-hub/manage/${pageId}/revisions?before=${next}`,
                                'GET',
                            );
                            if (generation.current !== requestGeneration)
                                return;
                            setItems((current) => [
                                ...current,
                                ...page.items.filter(
                                    (item) =>
                                        !current.some(
                                            (old) => old.id === item.id,
                                        ),
                                ),
                            ]);
                            setNext(page.next_cursor);
                        } catch (err) {
                            if (generation.current === requestGeneration)
                                setError((err as Error).message);
                        } finally {
                            if (generation.current === requestGeneration)
                                setBusy(false);
                        }
                    }}
                >
                    {busy ? 'Loading…' : 'Load older versions'}
                </Button>
            )}
            {selected !== null && (
                <RevisionDialog
                    key={selected}
                    pageId={pageId}
                    revisionId={selected}
                    version={version}
                    disabled={disabled}
                    close={() => setSelected(null)}
                    onChanged={onChanged}
                />
            )}
        </section>
    );
}

function SnapshotBlock({ block }: { block: HubBlock }) {
    if (block.type === 'text')
        return (
            <p className="text-sm leading-relaxed whitespace-pre-wrap">
                {block.body || 'No text in this saved version.'}
            </p>
        );
    if (block.type === 'image' && block.media_id)
        return (
            <figure>
                <img
                    className="w-full rounded-lg"
                    src={`/company-hub/media/${block.media_id}`}
                    alt={block.alt ?? ''}
                />
                {block.body && (
                    <figcaption className="mt-2 text-xs text-muted-foreground">
                        {block.body}
                    </figcaption>
                )}
            </figure>
        );
    if (block.type === 'links')
        return (
            <ul className="space-y-3">
                {block.links?.map((link) => (
                    <li key={link.id}>
                        <strong className="text-sm">{link.title}</strong>
                        {link.description && (
                            <p className="text-sm text-muted-foreground">
                                {link.description}
                            </p>
                        )}
                        <p className="text-xs break-all text-muted-foreground">
                            {link.url}
                        </p>
                    </li>
                ))}
            </ul>
        );
    if (block.type === 'poll')
        return (
            <div>
                <p className="text-sm font-medium">{block.question}</p>
                <ul className="mt-2 space-y-1">
                    {block.options?.map((option, i) => (
                        <li
                            className="rounded-md border px-3 py-2 text-sm"
                            key={i}
                        >
                            {option}
                        </li>
                    ))}
                </ul>
                <p className="mt-2 text-xs text-muted-foreground">
                    Saved question and options. Voting is unavailable in a
                    version preview.
                </p>
            </div>
        );
    if (block.type === 'video')
        return (
            <p className="text-sm break-all text-muted-foreground">
                Video link: {block.url || 'No link saved'}
            </p>
        );
    if (block.type === 'file')
        return (
            <div className="flex items-center gap-2 text-sm">
                <FileText className="size-4" />
                {block.media_id ? (
                    <a
                        className="text-primary underline"
                        href={`/company-hub/media/${block.media_id}`}
                        target="_blank"
                        rel="noopener noreferrer"
                    >
                        Open saved attachment
                    </a>
                ) : (
                    'No attachment saved'
                )}
            </div>
        );
    return (
        <div className="rounded-lg border border-dashed p-4 text-sm text-muted-foreground">
            <p>
                {blockLibrary.find(([key]) => key === block.type)?.[1] ??
                    block.type}
            </p>
            <p className="mt-2 text-xs">
                This block displays current company information when the page is
                read. A saved version preserves its settings, not a copy of
                staff activity.
            </p>
            {block.limit && (
                <p className="mt-2 text-xs">Items shown: {block.limit}</p>
            )}
            {block.category && (
                <p className="text-xs">Category: {block.category}</p>
            )}
        </div>
    );
}

export function RevisionSnapshot({ content }: { content: HubContent }) {
    return (
        <div className="hub-revision-snapshot">
            <header>
                <p className="text-xs font-semibold tracking-wide text-primary uppercase">
                    {content.category}
                </p>
                <h3 className="mt-2 text-2xl font-semibold tracking-tight">
                    {content.title}
                </h3>
                {content.summary && (
                    <p className="mt-3 text-sm leading-relaxed text-muted-foreground">
                        {content.summary}
                    </p>
                )}
                {content.cover_id && (
                    <img
                        className="mt-4 max-h-64 w-full rounded-lg object-cover"
                        style={{
                            objectPosition: `${content.hero?.focal_x ?? 50}% ${content.hero?.focal_y ?? 50}%`,
                        }}
                        src={`/company-hub/media/${content.cover_id}`}
                        alt="Saved page cover"
                    />
                )}
                {content.hero?.action_label && (
                    <p className="mt-3 text-xs text-muted-foreground">
                        Hero action: {content.hero.action_label} ·{' '}
                        {content.hero.action_url}
                    </p>
                )}
            </header>
            {content.sections.map((section) => (
                <section
                    className="hub-revision-section"
                    data-tone={section.tone ?? 'plain'}
                    key={section.id}
                >
                    {section.title && (
                        <h4 className="mb-4 font-semibold">{section.title}</h4>
                    )}
                    <div className="grid grid-cols-12 gap-5">
                        {section.blocks.map((block, index) => (
                            <article
                                className="min-w-0"
                                key={block.id}
                                style={{
                                    gridColumn: `span ${blockSpan(section, block, index)}`,
                                }}
                            >
                                {block.title && (
                                    <h5 className="mb-2 text-sm font-semibold">
                                        {block.title}
                                    </h5>
                                )}
                                <SnapshotBlock block={block} />
                            </article>
                        ))}
                    </div>
                </section>
            ))}
            {content.campaign && (
                <section className="hub-revision-section">
                    <h4 className="mb-3 font-semibold">Campaign activities</h4>
                    <ul className="space-y-2">
                        {content.campaign.steps.map((step) => (
                            <li className="text-sm" key={step.id}>
                                {step.title}
                                {step.url && (
                                    <p className="text-xs break-all text-muted-foreground">
                                        {step.url}
                                    </p>
                                )}
                            </li>
                        ))}
                    </ul>
                </section>
            )}
        </div>
    );
}

function RevisionDialog({
    pageId,
    revisionId,
    version,
    disabled,
    close,
    onChanged,
}: {
    pageId: number;
    revisionId: number;
    version: number;
    disabled: boolean;
    close: () => void;
    onChanged: () => void;
}) {
    const [detail, setDetail] = useState<RevisionDetail | null>(null);
    const [loading, setLoading] = useState(true);
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState('');
    const [reload, setReload] = useState(0);
    const [note, setNote] = useState('');
    const [publishAt, setPublishAt] = useState('');
    const [expiresAt, setExpiresAt] = useState('');
    const [confirm, setConfirm] = useState<string | null>(null);
    const [discard, setDiscard] = useState(false);
    useEffect(() => {
        let active = true;
        setLoading(true);
        setError('');
        setDetail(null);
        hubRequest<RevisionDetail>(
            `/company-hub/manage/${pageId}/revisions/${revisionId}`,
            'GET',
        )
            .then((result) => {
                if (active) setDetail(result);
            })
            .catch((err: Error) => {
                if (active) setError(err.message);
            })
            .finally(() => {
                if (active) setLoading(false);
            });
        return () => {
            active = false;
        };
    }, [pageId, revisionId, reload]);
    const stale = detail && detail.page_version !== version;
    const blocked = disabled || busy || loading || Boolean(stale);
    const attemptClose = () => {
        if (busy) return;
        if (note.trim() || publishAt || expiresAt) setDiscard(true);
        else close();
    };
    const action = async (type: string) => {
        if (!detail || blocked) return;
        setBusy(true);
        setError('');
        try {
            const base = `/company-hub/manage/${pageId}/revisions/${revisionId}`;
            if (type === 'approve')
                await hubRequest(`${base}/approve`, 'POST', {
                    version: detail.page_version,
                    publish_at: publishAt ? shiftInputInstant(publishAt) : null,
                    expires_at: expiresAt ? shiftInputInstant(expiresAt) : null,
                });
            else if (type === 'restore')
                await hubRequest(`${base}/restore`, 'POST', {
                    version: detail.page_version,
                });
            else
                await hubRequest(`${base}/review`, 'POST', {
                    version: detail.page_version,
                    action: type,
                    note: type === 'request_changes' ? note.trim() : null,
                });
            close();
            onChanged();
        } catch (err) {
            setError((err as Error).message);
            setConfirm(null);
        } finally {
            setBusy(false);
        }
    };
    return (
        <Dialog
            open
            onOpenChange={(open) => {
                if (!open) attemptClose();
            }}
        >
            <DialogContent className="max-h-[90vh] overflow-x-hidden overflow-y-auto sm:max-w-5xl">
                <DialogHeader>
                    <DialogTitle>
                        {detail
                            ? `Version ${detail.draft_version} · ${revisionLabel(detail)}`
                            : 'Open saved version'}
                    </DialogTitle>
                    <DialogDescription>
                        Review the saved content below. Reading a version does
                        not change the draft or the live page.
                    </DialogDescription>
                </DialogHeader>
                {loading && (
                    <p role="status" className="hub-empty-note">
                        Loading the saved version…
                    </p>
                )}
                {!loading && !detail && (
                    <Button
                        variant="outline"
                        onClick={() => setReload((n) => n + 1)}
                    >
                        Try again
                    </Button>
                )}
                {detail && (
                    <div className="hub-review-layout">
                        <RevisionSnapshot content={detail.content} />
                        <aside className="hub-review-actions">
                            <div className="space-y-3 text-xs text-muted-foreground">
                                <p>
                                    <strong className="block text-foreground">
                                        Saved by
                                    </strong>
                                    {detail.author} ·{' '}
                                    {formatDateTime(detail.created_at)}
                                </p>
                                <p>
                                    <strong className="block text-foreground">
                                        Audience
                                    </strong>
                                    {detail.content.audience.type === 'all'
                                        ? 'All staff'
                                        : `${detail.content.audience.ids.length} selected ${detail.content.audience.type}`}
                                </p>
                                {detail.publish_at && (
                                    <p>
                                        <strong className="block text-foreground">
                                            Publication
                                        </strong>
                                        {formatDateTime(detail.publish_at)}
                                    </p>
                                )}
                                {detail.expires_at && (
                                    <p>
                                        <strong className="block text-foreground">
                                            Expiry
                                        </strong>
                                        {formatDateTime(detail.expires_at)}
                                    </p>
                                )}
                                {detail.approved_by_name && (
                                    <p>
                                        <strong className="block text-foreground">
                                            Approved by
                                        </strong>
                                        {detail.approved_by_name}
                                    </p>
                                )}
                            </div>
                            {detail.review_note && (
                                <div className="hub-review-feedback">
                                    <p className="text-xs font-semibold">
                                        {detail.reviewed_by_name ??
                                            'Review note'}
                                    </p>
                                    <p className="mt-2 text-sm whitespace-pre-wrap">
                                        {detail.review_note}
                                    </p>
                                    {detail.reviewed_at && (
                                        <p className="mt-2 text-xs text-muted-foreground">
                                            {formatDateTime(detail.reviewed_at)}
                                        </p>
                                    )}
                                </div>
                            )}
                            {disabled && (
                                <p className="text-xs text-muted-foreground">
                                    Finish saving your draft before taking an
                                    action.
                                </p>
                            )}
                            {stale && (
                                <div className="rounded-lg border p-3 text-xs">
                                    <p>
                                        The draft changed while this version was
                                        open.
                                    </p>
                                    <Button
                                        className="mt-2"
                                        size="sm"
                                        variant="outline"
                                        disabled={disabled || busy}
                                        onClick={onChanged}
                                    >
                                        Reload editor
                                    </Button>
                                </div>
                            )}
                            {detail.can_return && (
                                <div className="space-y-2">
                                    <Label htmlFor="review-feedback">
                                        Feedback for the author
                                    </Label>
                                    <Textarea
                                        id="review-feedback"
                                        rows={4}
                                        maxLength={2000}
                                        value={note}
                                        disabled={blocked}
                                        onChange={(e) =>
                                            setNote(e.target.value)
                                        }
                                        placeholder="Explain what would make this ready to publish."
                                    />
                                    <Button
                                        variant="outline"
                                        className="w-full"
                                        disabled={blocked || !note.trim()}
                                        onClick={() =>
                                            void action('request_changes')
                                        }
                                    >
                                        <MessageSquare className="size-4" />
                                        Request changes
                                    </Button>
                                </div>
                            )}
                            {detail.can_approve && (
                                <div className="space-y-3 rounded-lg border p-3">
                                    <p className="text-sm font-medium">
                                        Approve publication
                                    </p>
                                    <div className="space-y-1">
                                        <Label htmlFor="review-publish">
                                            Publish at (optional)
                                        </Label>
                                        <Input
                                            id="review-publish"
                                            type="datetime-local"
                                            value={publishAt}
                                            disabled={blocked}
                                            onChange={(e) =>
                                                setPublishAt(e.target.value)
                                            }
                                        />
                                    </div>
                                    <div className="space-y-1">
                                        <Label htmlFor="review-expiry">
                                            Expires at (optional)
                                        </Label>
                                        <Input
                                            id="review-expiry"
                                            type="datetime-local"
                                            value={expiresAt}
                                            disabled={blocked}
                                            onChange={(e) =>
                                                setExpiresAt(e.target.value)
                                            }
                                        />
                                    </div>
                                    <Button
                                        className="w-full"
                                        disabled={blocked}
                                        onClick={() => setConfirm('approve')}
                                    >
                                        {publishAt
                                            ? 'Approve schedule'
                                            : 'Approve & publish'}
                                    </Button>
                                </div>
                            )}
                            {detail.can_withdraw && (
                                <Button
                                    className="w-full"
                                    variant="outline"
                                    disabled={blocked}
                                    onClick={() => setConfirm('withdraw')}
                                >
                                    Withdraw review
                                </Button>
                            )}
                            {detail.can_cancel_schedule && (
                                <Button
                                    className="w-full"
                                    variant="outline"
                                    disabled={blocked}
                                    onClick={() =>
                                        setConfirm('cancel_schedule')
                                    }
                                >
                                    Cancel schedule
                                </Button>
                            )}
                            <Button
                                className="w-full"
                                variant="outline"
                                disabled={blocked}
                                onClick={() => setConfirm('restore')}
                            >
                                <RotateCcw className="size-4" />
                                Restore to draft
                            </Button>
                            <p className="text-xs text-muted-foreground">
                                Restoring replaces your draft with this version.
                                Staff keep seeing the current published page.
                            </p>
                            {confirm && (
                                <div className="space-y-3 rounded-lg border border-primary/40 bg-primary/5 p-3">
                                    <p className="text-sm">
                                        {confirm === 'restore'
                                            ? `Replace the current draft with version ${detail.draft_version}?`
                                            : confirm === 'withdraw'
                                              ? 'Withdraw this submission and keep the saved draft?'
                                              : confirm === 'cancel_schedule'
                                                ? 'Cancel this scheduled release? The current live page stays unchanged.'
                                                : publishAt
                                                  ? 'Approve this saved version for the selected publication time?'
                                                  : 'Publish this saved version to its selected audience now?'}
                                    </p>
                                    <div className="flex gap-2">
                                        <Button
                                            size="sm"
                                            disabled={blocked}
                                            onClick={() => void action(confirm)}
                                        >
                                            Confirm
                                        </Button>
                                        <Button
                                            size="sm"
                                            variant="ghost"
                                            disabled={busy}
                                            onClick={() => setConfirm(null)}
                                        >
                                            Go back
                                        </Button>
                                    </div>
                                </div>
                            )}
                        </aside>
                    </div>
                )}
                {error && (
                    <p
                        role="alert"
                        className="text-sm whitespace-pre-wrap text-destructive"
                    >
                        {error}
                    </p>
                )}
                {discard && (
                    <div className="flex items-center gap-3 rounded-lg border p-3">
                        <p className="flex-1 text-sm">
                            Discard your unsent feedback and publication
                            settings?
                        </p>
                        <Button
                            variant="outline"
                            onClick={() => setDiscard(false)}
                        >
                            Keep reviewing
                        </Button>
                        <Button variant="destructive" onClick={close}>
                            Discard
                        </Button>
                    </div>
                )}
                <div className="flex justify-end">
                    <Button
                        variant="ghost"
                        disabled={busy}
                        onClick={attemptClose}
                    >
                        Close review
                    </Button>
                </div>
            </DialogContent>
        </Dialog>
    );
}
