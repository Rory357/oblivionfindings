import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { useSettingsLeaveConfirmation } from '@/hooks/use-settings-leave-confirmation';
import { formatDateLong, formatDateTime } from '@/lib/datetime';
import { shiftInputInstant } from '@/lib/workforce-time-input';
import {
    ArrowLeft,
    ArrowUpRight,
    Check,
    Eye,
    EyeOff,
    FileText,
    History,
    MessageSquare,
    Pencil,
    RotateCcw,
    Sparkles,
} from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { HubRequestError, hubRequest } from './_api';
import { HubDateTimeField } from './_date-fields';
import { blockLibrary, blockSpan } from './_layout';
import { HubFormattedText } from './_rich-text';
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
    restoreBlocked = false,
    actorId,
    hasSavedDraft = false,
    onChanged,
    onClose,
}: {
    pageId: number;
    version: number;
    revisions: RevisionSummary[];
    cursor?: number | null;
    disabled: boolean;
    restoreBlocked?: boolean;
    actorId?: number | null;
    hasSavedDraft?: boolean;
    onChanged: () => void;
    onClose?: () => void;
}) {
    const [items, setItems] = useState(revisions);
    const [next, setNext] = useState(cursor);
    const [selected, setSelected] = useState<number | 'draft' | null>(null);
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState('');
    const generation = useRef(0);
    const heading = useRef<HTMLHeadingElement>(null);
    const opener = useRef<HTMLButtonElement | null>(null);
    useEffect(() => {
        if (selected === null) heading.current?.focus({ preventScroll: true });
    }, [selected]);
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
            <div hidden={selected !== null}>
                {onClose && (
                    <Button variant="ghost" className="mb-4" onClick={onClose}>
                        <ArrowLeft className="size-4" /> Back to draft
                    </Button>
                )}
                <div className="mb-4 flex items-start gap-3">
                    <History className="mt-1 size-5 text-primary" />
                    <div>
                        <h2
                            ref={heading}
                            tabIndex={-1}
                            className="text-lg font-semibold outline-none"
                        >
                            Version history & review
                        </h2>
                        <p className="mt-1 text-sm text-muted-foreground">
                            Open an exact saved version before reviewing or
                            restoring it, or inspect the current saved draft.
                        </p>
                    </div>
                </div>
                {hasSavedDraft && actorId && (
                    <article className="hub-version-row border-primary/25 bg-primary/5">
                        <div className="hub-version-number">
                            <Pencil className="size-5" />
                        </div>
                        <div className="min-w-0 flex-1">
                            <h3 className="font-medium">Current saved draft</h3>
                            <p className="mt-1 text-xs text-muted-foreground">
                                Version {version} · The saved draft, before any
                                unsaved edits
                            </p>
                        </div>
                        <Button
                            variant="outline"
                            size="sm"
                            onClick={(event) => {
                                opener.current = event.currentTarget;
                                setSelected('draft');
                            }}
                        >
                            <Eye className="size-4" />
                            View saved draft
                        </Button>
                    </article>
                )}
                {!items.length && (
                    <p className="hub-empty-note">
                        Your first submitted or published version will appear
                        here.
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
                            onClick={(event) => {
                                opener.current = event.currentTarget;
                                setSelected(revision.id);
                            }}
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
            </div>
            {selected === 'draft' && actorId && (
                <SavedDraftPreview
                    key={`${actorId}:${pageId}:${version}`}
                    actorId={actorId}
                    pageId={pageId}
                    version={version}
                    canEdit={!restoreBlocked}
                    canReload={!disabled}
                    onChanged={onChanged}
                    onContinue={onClose}
                    onBack={() => {
                        setSelected(null);
                        requestAnimationFrame(() =>
                            opener.current?.focus({ preventScroll: true }),
                        );
                    }}
                />
            )}
            {typeof selected === 'number' && (
                <RevisionWorkspace
                    key={selected}
                    pageId={pageId}
                    revisionId={selected}
                    version={version}
                    disabled={disabled}
                    restoreBlocked={restoreBlocked}
                    close={() => {
                        setSelected(null);
                        requestAnimationFrame(
                            () =>
                                opener.current?.isConnected &&
                                opener.current.focus({ preventScroll: true }),
                        );
                    }}
                    onChanged={onChanged}
                />
            )}
        </section>
    );
}

function SavedDraftPreview({
    actorId,
    pageId,
    version,
    canEdit,
    canReload,
    onBack,
    onContinue,
    onChanged,
}: {
    actorId: number;
    pageId: number;
    version: number;
    canEdit: boolean;
    canReload: boolean;
    onBack: () => void;
    onContinue?: () => void;
    onChanged: () => void;
}) {
    type Snapshot = {
        source: 'draft';
        actor_id: number;
        page_id: number;
        version: number;
        saved_at: string | null;
        content: HubContent;
    };
    const [snapshot, setSnapshot] = useState<Snapshot | null>(null);
    const [freshEditable, setFreshEditable] = useState(false);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState('');
    const [retry, setRetry] = useState(0);
    const heading = useRef<HTMLHeadingElement>(null);
    useEffect(() => {
        heading.current?.focus({ preventScroll: true });
        let active = true;
        setLoading(true);
        setError('');
        setSnapshot(null);
        setFreshEditable(false);
        void hubRequest<{
            current_draft: Snapshot | null;
            blocked_reason: string | null;
        }>(
            `/company-hub/manage/${pageId}/draft?include_snapshot=1&actor_id=${actorId}&version=${version}`,
            'GET',
        )
            .then((result) => {
                if (!active) return;
                const saved = result.current_draft;
                if (saved === null) {
                    setError(
                        'There is no unpublished saved draft for this page. Reload the editor to see its current state.',
                    );
                    return;
                }
                if (
                    !saved ||
                    saved.source !== 'draft' ||
                    saved.actor_id !== actorId ||
                    saved.page_id !== pageId ||
                    saved.version !== version ||
                    !Array.isArray(saved.content?.sections)
                )
                    throw new Error(
                        'This saved draft could not be confirmed. Reload the editor to see the current saved version.',
                    );
                setSnapshot(saved);
                setFreshEditable(
                    result.blocked_reason === null ||
                        ['layout', 'published_audience'].includes(
                            result.blocked_reason,
                        ),
                );
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
    }, [actorId, pageId, version, retry]);
    return (
        <section aria-label="Current saved draft preview" className="space-y-6">
            <header className="space-y-3 border-b pb-5">
                <Button variant="ghost" className="-ml-2" onClick={onBack}>
                    <ArrowLeft className="size-4" />
                    All saved versions
                </Button>
                <h2
                    ref={heading}
                    tabIndex={-1}
                    className="text-xl font-semibold outline-none"
                >
                    Current saved draft · v{version}
                </h2>
                <p className="text-sm text-muted-foreground">
                    This is the last confirmed saved content. Unsaved changes in
                    the editor are not included.
                </p>
            </header>
            {loading && (
                <p role="status" className="hub-empty-note">
                    Loading the saved draft…
                </p>
            )}
            {error && (
                <div
                    role="alert"
                    className="space-y-3 rounded-xl border border-destructive/30 p-4 text-sm"
                >
                    <p>{error}</p>
                    <div className="flex gap-2">
                        <Button
                            variant="outline"
                            disabled={loading}
                            onClick={() => setRetry((n) => n + 1)}
                        >
                            Try again
                        </Button>
                        <Button
                            variant="outline"
                            disabled={!canReload}
                            onClick={onChanged}
                        >
                            Reload editor
                        </Button>
                    </div>
                    {!canReload && (
                        <p>
                            Return to the editor and save or discard your
                            changes before reloading.
                        </p>
                    )}
                </div>
            )}
            {snapshot && (
                <div className="hub-review-layout">
                    <RevisionSnapshot content={snapshot.content} />
                    <aside className="hub-review-actions space-y-4">
                        <span className="hub-version-state">
                            Unpublished draft
                        </span>
                        {snapshot.saved_at && (
                            <p className="text-xs text-muted-foreground">
                                <strong className="mb-1 block text-foreground">
                                    Saved version updated
                                </strong>
                                {formatDateTime(snapshot.saved_at)}
                            </p>
                        )}
                        <p className="text-sm text-muted-foreground">
                            Continue editing to make changes. Review and
                            publication actions stay in the page editor.
                        </p>
                        {onContinue && (
                            <Button
                                disabled={!canEdit || !freshEditable}
                                onClick={onContinue}
                            >
                                <Pencil className="size-4" />
                                Continue editing
                            </Button>
                        )}
                        {(!canEdit || !freshEditable) && (
                            <p className="text-xs text-muted-foreground">
                                This draft is currently locked for editing.
                            </p>
                        )}
                    </aside>
                </div>
            )}
        </section>
    );
}

function SnapshotBlock({ block }: { block: HubBlock }) {
    if (block.type === 'notice')
        return (
            <div className="hub-custom-callout">
                <Sparkles
                    className="size-5 shrink-0 text-primary"
                    aria-hidden
                />
                <p className="text-sm leading-relaxed whitespace-pre-wrap">
                    {block.body || 'No callout text in this saved version.'}
                </p>
            </div>
        );
    if (block.type === 'button')
        return (
            <div className="space-y-3">
                {block.body && (
                    <p className="text-sm leading-relaxed whitespace-pre-wrap text-muted-foreground">
                        {block.body}
                    </p>
                )}
                <span className="inline-flex items-center gap-2 rounded-lg bg-primary-fill px-4 py-2 text-sm font-medium text-primary-fill-foreground">
                    {block.action_label || 'Explore'}
                    <ArrowUpRight className="size-4" aria-hidden />
                </span>
                <p className="text-xs break-all text-muted-foreground">
                    Destination: {block.url || 'No destination saved'}
                </p>
            </div>
        );
    if (block.type === 'text')
        return (
            <HubFormattedText
                value={block.rich_text}
                body={block.body || 'No text in this saved version.'}
            />
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
            <div className="space-y-3">
                {block.body && (
                    <p className="text-sm whitespace-pre-wrap text-muted-foreground">
                        {block.body}
                    </p>
                )}
                <p className="text-sm break-all text-muted-foreground">
                    Video link: {block.url || 'No link saved'}
                </p>
            </div>
        );
    if (block.type === 'file')
        return (
            <div className="space-y-3">
                {block.body && (
                    <p className="text-sm whitespace-pre-wrap text-muted-foreground">
                        {block.body}
                    </p>
                )}
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
                    {section.hidden && (
                        <p className="mb-4 inline-flex items-center gap-1.5 text-xs font-medium text-muted-foreground">
                            <EyeOff className="size-3.5" aria-hidden />
                            Section hidden from staff in this version
                        </p>
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
                                {block.hidden && (
                                    <p className="mb-3 inline-flex items-center gap-1.5 rounded-full bg-muted px-2.5 py-1 text-xs font-medium text-muted-foreground">
                                        <EyeOff
                                            className="size-3.5"
                                            aria-hidden
                                        />{' '}
                                        Hidden from staff in this version
                                    </p>
                                )}
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
                    <div className="mb-5 flex items-start justify-between gap-4">
                        <div>
                            <h4 className="font-semibold">Campaign plan</h4>
                            <p className="mt-1 text-xs text-muted-foreground">
                                The plan saved with this version. Personal
                                progress is not included.
                            </p>
                        </div>
                        {content.campaign.ends_on && (
                            <p className="shrink-0 rounded-lg bg-muted px-3 py-2 text-xs">
                                Target date:{' '}
                                {formatDateLong(content.campaign.ends_on)}
                            </p>
                        )}
                    </div>
                    {!!content.campaign.milestones?.length && (
                        <div className="mb-6">
                            <h5 className="mb-3 text-sm font-semibold">
                                Shared milestones
                            </h5>
                            <ol className="space-y-2">
                                {content.campaign.milestones.map(
                                    (milestone) => (
                                        <li
                                            key={milestone.id}
                                            className="flex items-center justify-between gap-3 rounded-lg border p-3 text-sm"
                                        >
                                            <span>{milestone.title}</span>
                                            <span className="inline-flex shrink-0 items-center gap-1.5 rounded-full bg-muted px-2.5 py-1 text-xs">
                                                {milestone.status ===
                                                    'done' && (
                                                    <Check
                                                        className="size-3.5"
                                                        aria-hidden
                                                    />
                                                )}
                                                {milestone.status === 'done'
                                                    ? 'Complete'
                                                    : milestone.status ===
                                                        'active'
                                                      ? 'In progress'
                                                      : 'Up next'}
                                            </span>
                                        </li>
                                    ),
                                )}
                            </ol>
                        </div>
                    )}
                    <h5 className="mb-3 text-sm font-semibold">
                        Personal activities
                    </h5>
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
                    <div
                        className="mt-5 flex flex-wrap gap-2 text-xs text-muted-foreground"
                        aria-label="Saved campaign selections"
                    >
                        {[
                            [
                                'News stories',
                                content.campaign.story_ids?.length ?? 0,
                            ],
                            [
                                'Resources',
                                content.campaign.resource_ids?.length ?? 0,
                            ],
                            [
                                'Events',
                                content.campaign.event_keys?.length ?? 0,
                            ],
                        ].map(([label, count]) => (
                            <span
                                key={label}
                                className="rounded-lg border px-3 py-2"
                            >
                                {label}: {count} selected
                            </span>
                        ))}
                    </div>
                </section>
            )}
            {!!content.checklist?.length && (
                <section className="hub-revision-section">
                    <h4 className="mb-3 font-semibold">Guide checklist</h4>
                    <ol className="list-inside list-decimal space-y-2 text-sm">
                        {content.checklist.map((step) => (
                            <li key={step.id}>{step.title}</li>
                        ))}
                    </ol>
                </section>
            )}
        </div>
    );
}

function RevisionWorkspace({
    pageId,
    revisionId,
    version,
    disabled,
    restoreBlocked,
    close,
    onChanged,
}: {
    pageId: number;
    revisionId: number;
    version: number;
    disabled: boolean;
    restoreBlocked: boolean;
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
    const [uncertain, setUncertain] = useState(false);
    const pending = useRef(false);
    const alive = useRef(false);
    const heading = useRef<HTMLHeadingElement>(null);
    const navigation = useSettingsLeaveConfirmation(
        !!note.trim() || !!publishAt || !!expiresAt || busy || uncertain,
        'Leave this saved-version review?',
        'Your unsent feedback and timing choices will be discarded. An action already sent may still finish; check the saved versions before trying again.',
    );
    useEffect(() => {
        alive.current = true;
        heading.current?.focus({ preventScroll: true });
        return () => {
            alive.current = false;
        };
    }, []);
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
                if (!active) return;
                if (
                    result.id !== revisionId ||
                    !Number.isSafeInteger(result.page_version)
                )
                    throw new Error(
                        'This saved version could not be confirmed. Reload it before continuing.',
                    );
                setDetail(result);
                setUncertain(false);
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
    const blocked = disabled || busy || loading || uncertain || Boolean(stale);
    const attemptClose = () => {
        if (pending.current) return;
        if (note.trim() || publishAt || expiresAt) setDiscard(true);
        else close();
    };
    const action = async (type: string) => {
        if (
            !detail ||
            pending.current ||
            blocked ||
            (type === 'restore' && restoreBlocked)
        )
            return;
        pending.current = true;
        setBusy(true);
        setError('');
        try {
            const base = `/company-hub/manage/${pageId}/revisions/${revisionId}`;
            let result: { saved?: boolean };
            if (type === 'approve')
                result = await hubRequest(`${base}/approve`, 'POST', {
                    version: detail.page_version,
                    publish_at: publishAt ? shiftInputInstant(publishAt) : null,
                    expires_at: expiresAt ? shiftInputInstant(expiresAt) : null,
                });
            else if (type === 'restore')
                result = await hubRequest(`${base}/restore`, 'POST', {
                    version: detail.page_version,
                });
            else
                result = await hubRequest(`${base}/review`, 'POST', {
                    version: detail.page_version,
                    action: type,
                    note: type === 'request_changes' ? note.trim() : null,
                });
            if (!alive.current) return;
            if (result.saved !== true) {
                setUncertain(true);
                setConfirm(null);
                setError(
                    'We couldn’t confirm the saved action. Check the current version before trying again.',
                );
                return;
            }
            navigation.discardAndRun(() => {
                close();
                onChanged();
            });
        } catch (err) {
            if (!alive.current) return;
            if (
                !(err instanceof HubRequestError) ||
                ![403, 404, 409, 422, 429].includes(err.status)
            )
                setUncertain(true);
            setError((err as Error).message);
            setConfirm(null);
        } finally {
            if (alive.current) {
                pending.current = false;
                setBusy(false);
            }
        }
    };
    return (
        <>
            <section aria-label="Saved version review" className="space-y-6">
                <header className="space-y-3 border-b pb-5">
                    <Button
                        variant="ghost"
                        className="-ml-2"
                        disabled={busy}
                        onClick={attemptClose}
                    >
                        <ArrowLeft className="size-4" /> All saved versions
                    </Button>
                    <h2
                        ref={heading}
                        tabIndex={-1}
                        className="text-2xl font-semibold tracking-tight outline-none"
                    >
                        {detail
                            ? `Version ${detail.draft_version} · ${revisionLabel(detail)}`
                            : 'Open saved version'}
                    </h2>
                    <p className="text-sm text-muted-foreground">
                        Review the saved content below. Reading a version does
                        not change the draft or the live page.
                    </p>
                </header>
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
                                        onClick={() =>
                                            navigation.request(onChanged)
                                        }
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
                                        <HubDateTimeField
                                            id="review-publish"
                                            label="Publish at"
                                            value={publishAt}
                                            disabled={blocked}
                                            onChange={(value) =>
                                                setPublishAt(value)
                                            }
                                        />
                                    </div>
                                    <div className="space-y-1">
                                        <Label htmlFor="review-expiry">
                                            Expires at (optional)
                                        </Label>
                                        <HubDateTimeField
                                            id="review-expiry"
                                            label="Expires at"
                                            value={expiresAt}
                                            disabled={blocked}
                                            onChange={(value) =>
                                                setExpiresAt(value)
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
                                disabled={blocked || restoreBlocked}
                                onClick={() => setConfirm('restore')}
                            >
                                <RotateCcw className="size-4" />
                                Restore to draft
                            </Button>
                            <p className="text-xs text-muted-foreground">
                                {restoreBlocked
                                    ? 'Withdraw the review or cancel the scheduled publication before restoring a draft.'
                                    : 'Your current saved draft stays in version history. Restoring replaces the editing draft; staff keep seeing the current published page.'}
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
                {uncertain && (
                    <Button
                        variant="outline"
                        disabled={busy || loading}
                        onClick={() => setReload((value) => value + 1)}
                    >
                        Check current version
                    </Button>
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
            </section>
            {navigation.confirmation}
        </>
    );
}
