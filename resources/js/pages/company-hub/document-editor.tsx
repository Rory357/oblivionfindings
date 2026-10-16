import { ConfirmDialog } from '@/components/confirm-dialog';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { FileDropzone } from '@/components/ui/file-dropzone';
import { useSettingsLeaveConfirmation } from '@/hooks/use-settings-leave-confirmation';
import AppLayout from '@/layouts/app-layout';
import { formatDateTime } from '@/lib/datetime';
import { Head, Link, router, usePage } from '@inertiajs/react';
import {
    ArrowLeft,
    Download,
    FileText,
    History,
    Loader2,
    RefreshCw,
    RotateCcw,
    Upload,
} from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { HubRequestError, hubRequest } from './_api';
import {
    DocumentDetails,
    documentMetadataChanges,
    sameDocumentValue,
    validDocumentMetadata,
    type DocumentMetadata,
    type GovernanceWarning,
} from './_document-details';
import { HubSiteHeader } from './_hero';
import { HubNavigationRail } from './_navigation';
import type { HubReadiness } from './_publication-readiness';
import type { HubProps } from './_types';
import {
    documentFileError,
    fileSize,
    hubDocumentAccept,
    hubDocumentHint,
    uploadHubFile,
} from './_upload';
import './designer.css';
import type { HubDocument } from './document';
import './hub.css';

export type DocumentContext = {
    ready: boolean;
    actor_id: number;
    page_id: number;
    page_version: number;
    document_id: number;
    document_version: number | null;
    draft: DocumentEdition | null;
    published: DocumentEdition | null;
    blocked_reason: string | null;
    can_replace: boolean;
    edit_url: string;
    metadata?: DocumentMetadata;
    owners?: { id: number; name: string }[];
    can_edit_details?: boolean;
    can_change_owner?: boolean;
    can_submit?: boolean;
    can_publish?: boolean;
    update_url?: string;
    publication_url?: string;
    details_url?: string;
    reader_url?: string;
    governance_warnings?: GovernanceWarning[];
    readiness?: HubReadiness;
    history: {
        id: number;
        version: number;
        media_id: number;
        name: string;
        mime: string;
        size: number;
        action: string;
        created_at: string;
        is_draft: boolean;
        is_published: boolean;
        can_restore: boolean;
        download_url: string | null;
        preview_url: string | null;
    }[];
};
type DocumentEdition = Pick<
    HubDocument,
    | 'id'
    | 'media_id'
    | 'title'
    | 'name'
    | 'mime'
    | 'size'
    | 'version'
    | 'description'
    | 'updated_at'
    | 'edition'
    | 'download_url'
    | 'preview_url'
>;
const positive = (value: unknown): value is number =>
    typeof value === 'number' && Number.isInteger(value) && value > 0;
const record = (value: unknown): value is Record<string, unknown> =>
    !!value && typeof value === 'object';
export function validDocumentContext(
    value: unknown,
    actor: number | undefined,
    page: number,
    document: number,
): value is DocumentContext {
    if (
        !record(value) ||
        value.actor_id !== actor ||
        !positive(actor) ||
        value.page_id !== page ||
        value.document_id !== document ||
        !positive(value.page_version) ||
        typeof value.ready !== 'boolean' ||
        typeof value.can_replace !== 'boolean' ||
        !(
            value.document_version === null || positive(value.document_version)
        ) ||
        (value.ready && !positive(value.document_version)) ||
        !Array.isArray(value.history) ||
        value.edit_url !== `/company-hub/manage/${page}/edit`
    )
        return false;
    if (
        value.governance_warnings !== undefined &&
        (!Array.isArray(value.governance_warnings) ||
            !value.governance_warnings.every(
                (warning) =>
                    record(warning) &&
                    typeof warning.field === 'string' &&
                    typeof warning.code === 'string' &&
                    typeof warning.message === 'string',
            ))
    )
        return false;
    if (
        value.metadata !== undefined &&
        (!validDocumentMetadata(value.metadata) ||
            ![
                'can_edit_details',
                'can_change_owner',
                'can_submit',
                'can_publish',
            ].every((key) => typeof value[key] === 'boolean') ||
            value.update_url !==
                `/company-hub/manage/${page}/documents/${document}/details` ||
            value.publication_url !==
                `/company-hub/manage/${page}/documents/${document}/publication` ||
            !Array.isArray(value.owners) ||
            !value.owners.every(
                (owner) =>
                    record(owner) &&
                    positive(owner.id) &&
                    typeof owner.name === 'string',
            ) ||
            !record(value.readiness) ||
            value.readiness.actor_id !== actor ||
            value.readiness.page_id !== page ||
            value.readiness.version !== value.page_version ||
            typeof value.readiness.content_ready !== 'boolean' ||
            !Array.isArray(value.readiness.issues) ||
            !value.readiness.issues.every(
                (issue) => record(issue) && typeof issue.message === 'string',
            ))
    )
        return false;
    const edition = (item: unknown, kind: string) =>
        item === null ||
        (record(item) &&
            item.id === document &&
            positive(item.media_id) &&
            positive(item.version) &&
            item.edition === kind &&
            typeof item.title === 'string' &&
            typeof item.name === 'string' &&
            typeof item.mime === 'string' &&
            typeof item.size === 'number' &&
            item.size >= 0 &&
            item.download_url ===
                `/company-hub/media/${item.media_id}?download=1` &&
            (item.preview_url === null ||
                item.preview_url ===
                    `/company-hub/media/${item.media_id}/preview`));
    return (
        edition(value.draft, 'draft') &&
        edition(value.published, 'published') &&
        !!(value.draft || value.published) &&
        (!value.can_replace ||
            (value.ready && !!value.draft && value.blocked_reason === null)) &&
        value.history.every(
            (item) =>
                record(item) &&
                positive(item.id) &&
                positive(item.version) &&
                positive(item.media_id) &&
                typeof item.name === 'string' &&
                typeof item.created_at === 'string' &&
                typeof item.size === 'number' &&
                typeof item.can_restore === 'boolean' &&
                typeof item.is_draft === 'boolean' &&
                typeof item.is_published === 'boolean' &&
                (item.download_url === null ||
                    item.download_url ===
                        `/company-hub/media/${item.media_id}?download=1`) &&
                (item.preview_url === null ||
                    item.preview_url ===
                        `/company-hub/media/${item.media_id}/preview`),
        )
    );
}
export default function DocumentEditor(
    props: HubProps & { documentContext: DocumentContext },
) {
    const actor = (
        usePage().props.auth as { user?: { id: number } } | undefined
    )?.user?.id;
    return (
        <DocumentEditorBody
            key={`${actor}:${props.documentContext.page_id}:${props.documentContext.document_id}`}
            {...props}
            actor={actor}
        />
    );
}
function DocumentEditorBody(
    props: HubProps & { documentContext: DocumentContext; actor?: number },
) {
    const [context, setContext] = useState(props.documentContext),
        [file, setFile] = useState<File | null>(null),
        [busy, setBusy] = useState(false),
        [uploading, setUploading] = useState(false),
        [error, setError] = useState(''),
        [message, setMessage] = useState(''),
        [uncertain, setUncertain] = useState(false),
        [progress, setProgress] = useState(0),
        [restore, setRestore] = useState<number | null>(null),
        [received, setReceived] = useState(props.documentContext),
        [stale, setStale] = useState(false),
        [search, setSearch] = useState(''),
        [details, setDetails] = useState(
            props.documentContext.metadata ?? null,
        ),
        [operation, setOperation] = useState<
            'details' | 'submit' | 'publish' | null
        >(null),
        [confirmPublish, setConfirmPublish] = useState(false);
    const detailsDirty =
        !!details &&
        !!context.metadata &&
        Object.keys(documentMetadataChanges(context.metadata, details)).length >
            0;
    const incoming = useRef(props.documentContext);
    incoming.current = props.documentContext;
    if (received !== props.documentContext) {
        setReceived(props.documentContext);
        setConfirmPublish(false);
        const next = props.documentContext;
        if (
            next.actor_id === context.actor_id &&
            (next.page_version < context.page_version ||
                (next.document_version ?? 0) < (context.document_version ?? 0))
        ) {
            setContext({
                ...context,
                ready: context.ready && next.ready,
                can_replace: context.can_replace && next.can_replace,
                can_edit_details:
                    context.can_edit_details && next.can_edit_details,
                can_submit: context.can_submit && next.can_submit,
                can_publish: context.can_publish && next.can_publish,
                can_change_owner:
                    context.can_change_owner && next.can_change_owner,
                blocked_reason: next.blocked_reason,
                draft: next.draft ? context.draft : null,
                published: next.published ? context.published : null,
                history: context.history.filter((item) =>
                    next.history.some(
                        (allowed) =>
                            allowed.id === item.id &&
                            allowed.media_id === item.media_id,
                    ),
                ),
            });
            setUncertain(true);
            setError(
                'Earlier page data arrived after the document changed. Refresh history to confirm the current version.',
            );
        } else {
            setContext(next);
            if (!detailsDirty && !busy) setDetails(next.metadata ?? null);
        }
        if (file || restore !== null || busy || detailsDirty) setStale(true);
    }
    const alive = useRef(true),
        pending = useRef(false),
        controller = useRef<AbortController | null>(null);
    useEffect(() => {
        alive.current = true;
        return () => {
            alive.current = false;
            controller.current?.abort();
        };
    }, []);
    const leave = useSettingsLeaveConfirmation(
        !!file || restore !== null || busy || detailsDirty,
        'Leave document changes?',
        'Your unsaved document details and selected replacement will be cleared. A change already sent may still finish; refresh this document before retrying.',
        () => controller.current?.abort(),
        busy,
    );
    const base = `/company-hub/manage/${context.page_id}/documents/${context.document_id}`;
    const valid = (value: unknown): value is DocumentContext =>
        validDocumentContext(
            value,
            props.actor,
            props.documentContext.page_id,
            props.documentContext.document_id,
        );
    const locked =
        busy ||
        stale ||
        uncertain ||
        !valid(context) ||
        !context.can_replace ||
        detailsDirty ||
        props.actor !== context.actor_id;
    const current = context.draft ?? context.published;
    async function refresh() {
        if (pending.current) return;
        const opening = incoming.current;
        pending.current = true;
        setBusy(true);
        setError('');
        try {
            const value = await hubRequest<DocumentContext>(
                `${base}?actor_id=${props.actor}`,
                'GET',
            );
            if (
                !valid(value) ||
                incoming.current !== opening ||
                value.page_version < context.page_version ||
                (value.document_version ?? 0) < (context.document_version ?? 0)
            )
                throw new Error(
                    'The current document could not be confirmed for this account.',
                );
            if (alive.current) {
                setContext(value);
                setDetails(value.metadata ?? null);
                setUncertain(false);
                setStale(false);
                setFile(null);
                setRestore(null);
                setMessage(
                    'Document history refreshed. Check the current version before making another change.',
                );
            }
        } catch (failure) {
            if (alive.current) {
                setUncertain(true);
                setError(
                    failure instanceof Error
                        ? failure.message
                        : 'Could not refresh document history.',
                );
            }
        } finally {
            pending.current = false;
            if (alive.current) setBusy(false);
        }
    }
    async function save() {
        if (locked || pending.current || (!file && restore === null)) return;
        pending.current = true;
        setBusy(true);
        setUploading(restore === null && !!file);
        setError('');
        setMessage('');
        setProgress(0);
        const opening = incoming.current;
        try {
            let result: DocumentContext & { saved: boolean };
            const guard = {
                actor_id: props.actor,
                page_version: context.page_version,
                document_version: context.document_version,
            };
            if (restore !== null)
                result = await hubRequest(`${base}/restore`, 'POST', {
                    ...guard,
                    history_id: restore,
                });
            else {
                const form = new FormData();
                Object.entries(guard).forEach(([key, value]) =>
                    form.append(key, String(value)),
                );
                form.append('file', file!);
                controller.current = new AbortController();
                result = await uploadHubFile(
                    `${base}/replace`,
                    form,
                    controller.current.signal,
                    (value) => {
                        if (alive.current) setProgress(value);
                    },
                );
            }
            if (
                !valid(result) ||
                result.saved !== true ||
                incoming.current !== opening ||
                result.page_version !== context.page_version + 1 ||
                !result.draft ||
                result.document_version !==
                    (context.document_version ?? 0) + 1 ||
                result.draft.version !== result.document_version
            )
                throw new Error(
                    'The replacement could not be confirmed. Refresh history before trying again.',
                );
            if (alive.current) {
                setContext(result);
                setDetails(result.metadata ?? null);
                setFile(null);
                setRestore(null);
                setMessage(
                    'Document updated in the private draft. Review and publish the guide to share it with staff.',
                );
            }
        } catch (failure) {
            if (alive.current) {
                setUncertain(true);
                setError(
                    failure instanceof Error
                        ? failure.message
                        : 'The document could not be updated. Refresh history before trying again.',
                );
            }
        } finally {
            pending.current = false;
            controller.current = null;
            if (alive.current) {
                setBusy(false);
                setUploading(false);
            }
        }
    }
    async function saveDetails(action: 'details' | 'submit' | 'publish') {
        if (
            pending.current ||
            busy ||
            stale ||
            uncertain ||
            !valid(context) ||
            props.actor !== context.actor_id ||
            !context.metadata ||
            !details ||
            file ||
            restore !== null
        )
            return;
        if (
            action === 'details'
                ? !context.can_edit_details ||
                  !detailsDirty ||
                  !details.title.trim()
                : detailsDirty ||
                  !(action === 'publish'
                      ? context.can_publish
                      : context.can_submit)
        )
            return;
        const opening = incoming.current;
        const changes = documentMetadataChanges(context.metadata, details);
        const version = context.page_version;
        const documentVersion = context.document_version;
        pending.current = true;
        setBusy(true);
        setOperation(action);
        setError('');
        setMessage('');
        try {
            const result = await hubRequest<
                DocumentContext & {
                    saved: boolean;
                    revision_id?: number;
                    publication_state?: string;
                }
            >(
                `${base}/${action === 'details' ? 'details' : 'publication'}`,
                action === 'details' ? 'PATCH' : 'POST',
                {
                    actor_id: props.actor,
                    page_version: version,
                    document_version: documentVersion,
                    ...(action === 'details' ? changes : { action }),
                },
            );
            const expectedVersion =
                version +
                (action === 'details'
                    ? 1 + ('owner_id' in changes ? 1 : 0)
                    : 0);
            if (
                !valid(result) ||
                result.saved !== true ||
                incoming.current !== opening ||
                result.page_version !== expectedVersion ||
                result.document_version !== documentVersion ||
                !result.metadata ||
                (action === 'details' &&
                    Object.entries(changes).some(
                        ([key, value]) =>
                            !sameDocumentValue(
                                result.metadata![key as keyof DocumentMetadata],
                                value,
                            ),
                    )) ||
                (action !== 'details' &&
                    (!positive(result.revision_id) ||
                        result.publication_state !==
                            (action === 'submit' ? 'review' : 'published')))
            )
                throw new Error(
                    'This change could not be confirmed. Refresh the document before trying again.',
                );
            if (alive.current) {
                setContext(result);
                setDetails(result.metadata);
                setMessage(
                    action === 'details'
                        ? 'Document details saved to the private draft.'
                        : action === 'submit'
                          ? 'Document submitted for review. The published edition stays available while it is reviewed.'
                          : 'Document published. Staff can use the same document link.',
                );
            }
        } catch (failure) {
            if (alive.current) {
                if (
                    !(
                        failure instanceof HubRequestError &&
                        failure.status === 422
                    )
                )
                    setUncertain(true);
                setError(
                    failure instanceof Error
                        ? failure.message
                        : 'Could not save this document. Refresh before trying again.',
                );
            }
        } finally {
            pending.current = false;
            if (alive.current) {
                setBusy(false);
                setOperation(null);
                setConfirmPublish(false);
            }
        }
    }
    if (!valid(context))
        return (
            <AppLayout>
                <main className="hub-page-canvas">
                    <h1 className="text-page-title">Document unavailable</h1>
                    <p>
                        Reopen Resources to check access for your current
                        account.
                    </p>
                    <Link href="/company-hub?view=resources">
                        Return to Resources
                    </Link>
                </main>
            </AppLayout>
        );
    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Company Hub', href: '/company-hub' },
                { title: 'Document details', href: `${base}/edit` },
            ]}
        >
            <Head title="Document details · Company Hub" />
            <div className="hub-portal">
                <HubSiteHeader
                    content={props.home}
                    search={search}
                    onSearch={setSearch}
                    onSubmit={(event) => {
                        event.preventDefault();
                        leave.request(() =>
                            router.get('/company-hub', { search }),
                        );
                    }}
                >
                    <HubNavigationRail
                        updates={props.updates}
                        items={props.sharedNavigation ?? []}
                        current="view:resources"
                        onNavigate={(href) =>
                            leave.request(() => router.visit(href))
                        }
                    />
                </HubSiteHeader>
                <main className="hub-page-canvas hub-document-workspace">
                    <Button
                        variant="ghost"
                        size="sm"
                        className="self-start"
                        onClick={() =>
                            leave.request(() =>
                                router.visit('/company-hub?view=resources'),
                            )
                        }
                    >
                        <ArrowLeft className="size-4" /> Back to the library
                    </Button>
                    <div className="hub-discovery-heading">
                        <div>
                            <p className="text-caption text-primary">
                                DOCUMENT WORKSPACE
                            </p>
                            <h1 className="text-page-title">
                                {current?.title ?? 'Manage document'}
                            </h1>
                            <p>
                                Keep its details, sharing and file versions in
                                one place.
                            </p>
                        </div>
                        <Button
                            variant="outline"
                            disabled={busy}
                            onClick={() => leave.request(() => void refresh())}
                        >
                            <RefreshCw className="size-4" /> Refresh history
                        </Button>
                    </div>
                    {error && (
                        <p
                            role="alert"
                            className="rounded-xl border border-destructive/30 bg-destructive/5 p-4 text-sm text-destructive"
                        >
                            {error}
                        </p>
                    )}
                    {message && (
                        <p
                            role="status"
                            className="rounded-xl border bg-card p-4 text-sm"
                        >
                            {message}
                        </p>
                    )}
                    {stale && (
                        <Card
                            unstyled
                            role="alert"
                            className="space-y-3 rounded-xl border bg-card p-4"
                        >
                            <p>
                                The document changed while you were preparing
                                this update.{' '}
                                {file
                                    ? 'Your selected file is retained.'
                                    : 'Your document details are retained.'}
                                Check the current history before continuing.
                            </p>
                            <Button
                                variant="outline"
                                disabled={
                                    busy || uncertain || !context.can_replace
                                }
                                onClick={() => {
                                    setStale(false);
                                    setRestore(null);
                                }}
                            >
                                Use current version
                            </Button>
                        </Card>
                    )}
                    <div className="hub-document-layout">
                        <div className="space-y-5">
                            {details && context.metadata && (
                                <DocumentDetails
                                    value={details}
                                    onChange={setDetails}
                                    actor={context.actor_id}
                                    owners={context.owners ?? []}
                                    canChangeOwner={
                                        context.can_change_owner === true
                                    }
                                    disabled={
                                        busy ||
                                        stale ||
                                        uncertain ||
                                        !context.can_edit_details ||
                                        confirmPublish ||
                                        !!file ||
                                        restore !== null
                                    }
                                    dirty={detailsDirty}
                                    saving={operation === 'details'}
                                    canSubmit={context.can_submit === true}
                                    canPublish={context.can_publish === true}
                                    warnings={context.governance_warnings ?? []}
                                    issues={context.readiness?.issues}
                                    onSave={() => void saveDetails('details')}
                                    onSubmit={() => void saveDetails('submit')}
                                    onPublish={() => setConfirmPublish(true)}
                                />
                            )}
                            <section className="hub-upload-file space-y-4">
                                <h2 className="text-section-title">
                                    Replace the file
                                </h2>
                                {context.can_replace ? (
                                    <p className="text-sm text-muted-foreground">
                                        The replacement is saved to this guide’s
                                        private draft. Existing published copies
                                        stay available until you publish the
                                        guide.
                                    </p>
                                ) : (
                                    <p className="text-sm text-muted-foreground">
                                        {!context.ready
                                            ? 'Document versioning is waiting for a local application update.'
                                            : context.blocked_reason ===
                                                'publication'
                                              ? 'This guide is awaiting review or scheduled. Finish that publication step before replacing its document.'
                                              : 'Return to the guide editor to prepare an editable draft before replacing this file.'}
                                    </p>
                                )}
                                <FileDropzone
                                    multiple={false}
                                    accept={hubDocumentAccept}
                                    hint={hubDocumentHint}
                                    disabled={locked}
                                    onFiles={(files) => {
                                        const next = files[0];
                                        if (!next) return;
                                        const problem = documentFileError(next);
                                        setError(problem ?? '');
                                        if (!problem) {
                                            setFile(next);
                                            setRestore(null);
                                        }
                                    }}
                                />
                                {file && (
                                    <div className="flex items-center justify-between gap-3 rounded-lg bg-muted/50 p-4">
                                        <span className="text-sm">
                                            <FileText className="mr-2 inline size-4" />
                                            {file.name} · {fileSize(file.size)}
                                        </span>
                                        <Button
                                            variant="ghost"
                                            size="sm"
                                            disabled={busy}
                                            onClick={() => setFile(null)}
                                        >
                                            Remove
                                        </Button>
                                    </div>
                                )}
                                {uploading && file && (
                                    <div className="space-y-2">
                                        <progress
                                            value={progress}
                                            max={100}
                                            className="h-2 w-full accent-primary"
                                            aria-label="Replacement upload progress"
                                        />
                                        <p
                                            role="status"
                                            className="text-xs text-muted-foreground"
                                        >
                                            {progress >= 100
                                                ? 'Checking and saving…'
                                                : `Uploading ${Math.round(progress)}%`}
                                        </p>
                                    </div>
                                )}
                                {file && (
                                    <Button
                                        disabled={locked}
                                        onClick={() => void save()}
                                    >
                                        {uploading ? (
                                            <Loader2 className="size-4 animate-spin" />
                                        ) : (
                                            <Upload className="size-4" />
                                        )}{' '}
                                        {uploading
                                            ? 'Saving replacement…'
                                            : 'Save replacement to draft'}
                                    </Button>
                                )}
                            </section>
                            <section className="hub-upload-file space-y-4">
                                <h2 className="text-section-title flex items-center gap-2">
                                    <History className="size-5 text-primary" />{' '}
                                    Version history
                                </h2>
                                {!context.history.length && (
                                    <p className="text-sm text-muted-foreground">
                                        This file has no recorded replacements.
                                        Its original version will be retained
                                        when you replace it.
                                    </p>
                                )}
                                <ol className="divide-y">
                                    {context.history.map((version) => (
                                        <li
                                            key={version.id}
                                            className="space-y-3 py-4"
                                        >
                                            <div className="flex items-start justify-between gap-4">
                                                <div>
                                                    <p className="text-sm font-semibold">
                                                        Version{' '}
                                                        {version.version}
                                                        {version.is_published &&
                                                            ' · Published'}
                                                        {version.is_draft &&
                                                            ' · Current draft'}
                                                    </p>
                                                    <p className="mt-1 text-sm">
                                                        {version.name}
                                                    </p>
                                                    <p className="mt-1 text-xs text-muted-foreground">
                                                        {fileSize(version.size)}{' '}
                                                        ·{' '}
                                                        {formatDateTime(
                                                            version.created_at,
                                                        )}
                                                    </p>
                                                </div>
                                                <div className="flex gap-2">
                                                    {version.preview_url && (
                                                        <Button
                                                            size="sm"
                                                            variant="ghost"
                                                            asChild
                                                        >
                                                            <a
                                                                href={
                                                                    version.preview_url
                                                                }
                                                                target="_blank"
                                                                rel="noreferrer"
                                                                aria-label={`Preview version ${version.version}`}
                                                            >
                                                                Preview
                                                            </a>
                                                        </Button>
                                                    )}
                                                    {version.download_url && (
                                                        <Button
                                                            size="sm"
                                                            variant="ghost"
                                                            asChild
                                                        >
                                                            <a
                                                                href={
                                                                    version.download_url
                                                                }
                                                            >
                                                                <Download className="size-4" />{' '}
                                                                Download
                                                            </a>
                                                        </Button>
                                                    )}
                                                    {version.can_restore && (
                                                        <Button
                                                            size="sm"
                                                            variant="outline"
                                                            disabled={locked}
                                                            onClick={() => {
                                                                setRestore(
                                                                    version.id,
                                                                );
                                                                setFile(null);
                                                            }}
                                                        >
                                                            <RotateCcw className="size-4" />
                                                            Restore
                                                        </Button>
                                                    )}
                                                </div>
                                            </div>
                                            {restore === version.id && (
                                                <div className="rounded-lg border border-primary/20 bg-primary/5 p-4 text-sm">
                                                    <p>
                                                        Restore version{' '}
                                                        {version.version} as a
                                                        new private draft
                                                        version? Published
                                                        content stays as it is
                                                        until you publish.
                                                    </p>
                                                    <div className="mt-3 flex gap-2">
                                                        <Button
                                                            size="sm"
                                                            disabled={locked}
                                                            onClick={() =>
                                                                void save()
                                                            }
                                                        >
                                                            Restore to draft
                                                        </Button>
                                                        <Button
                                                            size="sm"
                                                            variant="ghost"
                                                            disabled={busy}
                                                            onClick={() =>
                                                                setRestore(null)
                                                            }
                                                        >
                                                            Cancel
                                                        </Button>
                                                    </div>
                                                </div>
                                            )}
                                        </li>
                                    ))}
                                </ol>
                            </section>
                        </div>
                        <aside className="hub-document-aside">
                            <h2>What staff can see</h2>
                            {context.published ? (
                                <>
                                    <p>
                                        Published version{' '}
                                        {context.published.version}
                                    </p>
                                    <p>{context.published.name}</p>
                                    <Button variant="outline" asChild>
                                        <Link
                                            href={`/company-hub/documents/${context.document_id}`}
                                        >
                                            Open published document
                                        </Link>
                                    </Button>
                                </>
                            ) : (
                                <p>This document has not been published yet.</p>
                            )}
                            <div className="space-y-3 border-t pt-4">
                                <h3>Your draft</h3>
                                <p>
                                    {context.draft
                                        ? `Version ${context.draft.version} · ${context.draft.name}`
                                        : 'No saved draft edition'}
                                </p>
                                {context.draft && (
                                    <Button
                                        variant="outline"
                                        disabled={busy}
                                        onClick={() =>
                                            leave.request(() =>
                                                router.visit(
                                                    `/company-hub/documents/${context.document_id}?edition=draft&actor_id=${context.actor_id}&page_version=${context.page_version}`,
                                                ),
                                            )
                                        }
                                    >
                                        Preview draft document
                                    </Button>
                                )}
                                <Button
                                    onClick={() =>
                                        leave.request(() =>
                                            router.visit(context.edit_url),
                                        )
                                    }
                                >
                                    Open page design & versions
                                </Button>
                            </div>
                        </aside>
                    </div>
                </main>
            </div>
            <ConfirmDialog
                open={confirmPublish}
                title="Publish this document?"
                description={`Staff ${details?.audience.type === 'all' ? 'across the company' : details?.audience.type === 'sites' ? 'at the selected sites' : 'in the selected departments'} will see this saved document at its existing link. It replaces the current published edition.`}
                confirmText="Publish document"
                variant="default"
                processing={operation === 'publish'}
                onClose={() => {
                    if (!busy) setConfirmPublish(false);
                }}
                onConfirm={() => void saveDetails('publish')}
            />
            {leave.confirmation}
        </AppLayout>
    );
}
