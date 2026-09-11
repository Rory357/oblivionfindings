import { ConfirmDialog } from '@/components/confirm-dialog';
import { TicketDraftRecovery } from '@/components/it/ticket-draft-recovery';
import { TicketDraftFiles } from '@/components/it/ticket-intake-draft';
import type {
    ThreadDraftState,
    ThreadKbHint,
} from '@/components/it/ticket-thread';
import { TicketVersionConflict } from '@/components/it/ticket-version-conflict';
import { Button } from '@/components/ui/button';
import { StagedFileCard } from '@/components/ui/file-dropzone';
import { Textarea } from '@/components/ui/textarea';
import {
    itCommentCommitMessage,
    type ItCommentCommitted,
} from '@/hooks/it-ticket-comment-contract';
import {
    draftSnapshotKey,
    resumedDraftBaseVersion,
    type ItDraftSnapshot,
} from '@/hooks/it-ticket-draft-contract';
import { useItTicketCommentCommand } from '@/hooks/use-it-ticket-comment-command';
import {
    useItTicketDraft,
    type ItDraftBrowserRestored,
} from '@/hooks/use-it-ticket-draft';
import {
    IT_ATTACHMENT_ACCEPT,
    isAllowedItAttachmentName,
} from '@/lib/it-attachments';
import { BookOpen, Lock, MessageSquare, Paperclip, Send } from 'lucide-react';
import {
    useCallback,
    useEffect,
    useId,
    useMemo,
    useRef,
    useState,
} from 'react';
import { toast } from 'sonner';

interface Props {
    actorId: number;
    ticketId: number;
    expectedVersion: number;
    draftsEnabled: boolean;
    canInternal: boolean;
    accessState?: 'session' | 'access' | 'actor' | null;
    compact?: boolean;
    kbSuggestions?: ThreadKbHint[];
    onPosted?: () => void;
    onDraftStateChange?: (state: ThreadDraftState) => void;
}

/** Each audience owns its own fields, files, draft revision and command identity. */
export function TicketReplyComposer(props: Props) {
    const [audience, setAudience] = useState<'public' | 'internal'>('public');
    const [revokedEpoch, setRevokedEpoch] = useState(0);
    const revokeAccess = useCallback(
        () => setRevokedEpoch((current) => current + 1),
        [],
    );
    const [work, setWork] = useState<
        Record<'public' | 'internal', ThreadDraftState>
    >({
        public: { dirty: false, busy: false },
        internal: { dirty: false, busy: false },
    });
    const reportPublic = useCallback((next: ThreadDraftState) => {
        setWork((current) =>
            current.public.dirty === next.dirty &&
            current.public.busy === next.busy
                ? current
                : { ...current, public: next },
        );
    }, []);
    const reportInternal = useCallback((next: ThreadDraftState) => {
        setWork((current) =>
            current.internal.dirty === next.dirty &&
            current.internal.busy === next.busy
                ? current
                : { ...current, internal: next },
        );
    }, []);
    const dirty =
        work.public.dirty || (props.canInternal && work.internal.dirty);
    const busy = work.public.busy || (props.canInternal && work.internal.busy);
    const active = props.canInternal ? audience : 'public';
    const report = props.onDraftStateChange;
    useEffect(() => {
        report?.({ dirty, busy });
    }, [dirty, busy, report]);
    return (
        <section
            aria-label="Write a ticket message"
            hidden={!!props.accessState}
            className="space-y-4 border-t border-border p-5"
        >
            {props.canInternal && (
                <div
                    className="flex flex-wrap gap-2"
                    aria-label="Message audience"
                >
                    <Button
                        type="button"
                        size="sm"
                        variant={active === 'public' ? 'default' : 'outline'}
                        aria-pressed={active === 'public'}
                        onClick={() => setAudience('public')}
                    >
                        <MessageSquare className="size-4" /> Reply
                        {work.public.dirty ? ' · draft' : ''}
                    </Button>
                    <Button
                        type="button"
                        size="sm"
                        variant={active === 'internal' ? 'default' : 'outline'}
                        aria-pressed={active === 'internal'}
                        onClick={() => setAudience('internal')}
                    >
                        <Lock className="size-4" /> Internal note
                        {work.internal.dirty ? ' · draft' : ''}
                    </Button>
                </div>
            )}
            <div hidden={active !== 'public'}>
                <AudienceComposer
                    key={`${props.actorId}:${props.ticketId}:public`}
                    {...props}
                    revokedEpoch={revokedEpoch}
                    onAccessRevoked={revokeAccess}
                    isInternal={false}
                    visible={active === 'public'}
                    onDraftStateChange={reportPublic}
                />
            </div>
            {props.canInternal && (
                <div hidden={active !== 'internal'}>
                    <AudienceComposer
                        key={`${props.actorId}:${props.ticketId}:internal`}
                        {...props}
                        revokedEpoch={revokedEpoch}
                        onAccessRevoked={revokeAccess}
                        isInternal
                        visible={active === 'internal'}
                        onDraftStateChange={reportInternal}
                    />
                </div>
            )}
        </section>
    );
}

const acceptedFields = ['body'] as const;

function AudienceComposer({
    actorId,
    ticketId,
    expectedVersion,
    draftsEnabled,
    compact,
    kbSuggestions = [],
    onPosted,
    onDraftStateChange,
    isInternal,
    visible,
    revokedEpoch,
    onAccessRevoked,
    accessState,
}: Props & {
    isInternal: boolean;
    visible: boolean;
    revokedEpoch: number;
    onAccessRevoked: () => void;
}) {
    const inputId = useId();
    const [body, setBody] = useState('');
    const [files, setFiles] = useState<File[]>([]);
    const [baseVersion, setBaseVersion] = useState(expectedVersion);
    const [confirmedVersion, setConfirmedVersion] = useState(expectedVersion);
    const currentVersion = Math.max(expectedVersion, confirmedVersion);
    const [accessHidden, setAccessHidden] = useState(false);
    const [authorizedEpoch, setAuthorizedEpoch] = useState(revokedEpoch);
    const [preparing, setPreparing] = useState(false);
    const [startingNext, setStartingNext] = useState(false);
    const [nextDraft, setNextDraft] =
        useState<ItCommentCommitted['draft']>(undefined);
    const nextDraftRef = useRef<ItCommentCommitted['draft']>(undefined);
    const nextWait = useRef(false);
    const focusNext = useRef(false);
    const [message, setMessage] = useState<string | null>(null);
    const [discardOpen, setDiscardOpen] = useState(false);
    const [cancelUuid, setCancelUuid] = useState<string | null>(null);
    const [pendingRestore, setPendingRestore] =
        useState<ItDraftBrowserRestored | null>(null);
    const [lastCommit, setLastCommit] = useState<ItCommentCommitted | null>(
        null,
    );
    const submitted = useRef<ItDraftSnapshot | null>(null);
    const submittedFiles = useRef<readonly File[] | null>(null);
    const input = useRef<HTMLInputElement>(null);
    const error = useRef<HTMLDivElement>(null);
    const preparation = useRef(false);
    const mounted = useRef(true);
    const completed = useRef<(result: ItCommentCommitted) => void>(
        () => undefined,
    );
    const accessLost = useRef(() => undefined);
    const purgePrivateWork = useRef(() => undefined);
    const command = useItTicketCommentCommand({
        actorId,
        ticketId,
        isInternal,
        onCommitted: (result) => completed.current(result),
        onAccessLost: () => accessLost.current(),
    });
    const snapshot = useMemo<ItDraftSnapshot>(
        () => ({
            fields: { body },
            step_index: 0,
            base_ticket_version: baseVersion,
        }),
        [body, baseVersion],
    );
    const draft = useItTicketDraft({
        enabled: draftsEnabled,
        actorId,
        context: {
            purpose: isInternal ? 'internal_note' : 'public_reply',
            ticketId,
        },
        workingSnapshot: snapshot,
        workingDirty: body.length > 0 || files.length > 0,
        workingFiles: files,
        acceptSelectedFiles: true,
        acceptedFields,
        workingOutcomeUnknown:
            command.frozenIntent !== null &&
            !['editing', 'committed', 'rejected'].includes(command.stage),
        workingSettledOperationToken: command.settledOperationToken,
        workingPendingComment: command.frozenIntent,
        onAccessLost: () => accessLost.current(),
    });
    purgePrivateWork.current = () => {
        nextDraftRef.current = undefined;
        setNextDraft(undefined);
        command.denyCurrentAccess();
        setAccessHidden(true);
        setBody('');
        setFiles([]);
        setPendingRestore(null);
        submitted.current = null;
        submittedFiles.current = null;
        draft.clearBrowserWork();
    };
    accessLost.current = () => {
        purgePrivateWork.current();
        onAccessRevoked();
    };
    useEffect(() => {
        if (revokedEpoch > 0) purgePrivateWork.current();
    }, [revokedEpoch]);
    useEffect(() => {
        if (accessState === 'access' || accessState === 'actor') {
            purgePrivateWork.current();
        }
    }, [accessState]);
    const revealAuthorizedWork = () => {
        setAccessHidden(false);
        setAuthorizedEpoch(revokedEpoch);
    };
    const concealed =
        !!accessState ||
        authorizedEpoch !== revokedEpoch ||
        accessHidden ||
        command.concealed ||
        ['session_expired', 'access_denied'].includes(draft.state);
    const dirty =
        body.length > 0 ||
        files.length > 0 ||
        draft.attachments.length > 0 ||
        command.references.length > 0;
    const busy = command.busy || draft.busy || preparing || startingNext;
    const commandReady =
        command.stage === 'editing' && command.references.length === 0;
    const editable =
        commandReady &&
        !command.accessBlocker &&
        !concealed &&
        !preparing &&
        !startingNext &&
        !draft.memoryBlocked &&
        ![
            'available',
            'checking',
            'reviewing',
            'conflict',
            'outcome_unknown',
            'terminal',
        ].includes(draft.state);
    const stale = dirty && currentVersion > baseVersion;
    const latest = useRef({
        draft,
        snapshot,
        command,
        editable,
        files,
        concealed,
    });
    latest.current = { draft, snapshot, command, editable, files, concealed };
    useEffect(() => {
        onDraftStateChange?.({ dirty, busy });
    }, [dirty, busy, onDraftStateChange]);
    useEffect(() => {
        if (!dirty && commandReady) setBaseVersion(currentVersion);
    }, [dirty, commandReady, currentVersion]);
    useEffect(() => {
        mounted.current = true;
        return () => {
            mounted.current = false;
        };
    }, []);
    useEffect(() => {
        if (
            visible &&
            (message || command.message || Object.keys(command.errors).length)
        )
            error.current?.focus();
    }, [visible, message, command.message, command.errors]);

    const resetLocal = () => {
        nextDraftRef.current = undefined;
        setNextDraft(undefined);
        draft.clearOwnedBrowserWork();
        setBody('');
        setFiles([]);
        setMessage(null);
        submitted.current = null;
        submittedFiles.current = null;
        setPendingRestore(null);
        setBaseVersion(currentVersion);
    };
    completed.current = (result) => {
        nextDraftRef.current = undefined;
        setNextDraft(undefined);
        setConfirmedVersion((current) =>
            Math.max(current, result.lock_version),
        );
        const sent = submitted.current;
        const current = latest.current;
        const sameWork =
            sent !== null &&
            draftSnapshotKey(sent) === draftSnapshotKey(current.snapshot) &&
            submittedFiles.current !== null &&
            submittedFiles.current.length === current.files.length &&
            submittedFiles.current.every(
                (file, index) => file === current.files[index],
            );
        // A recovered receipt can belong to earlier work. Never erase a newer unsent message.
        if (
            sameWork &&
            result.draft &&
            !current.draft.acknowledgeConsumed(result.draft, sent)
        ) {
            setMessage(
                'The reply was added, but its saved draft could not be matched here. Review the saved draft before starting another reply.',
            );
            setLastCommit(result);
            try {
                onPosted?.();
            } catch {
                setMessage(
                    'The reply was added. Refresh the conversation and review the unmatched saved draft before starting another reply.',
                );
            }
            return;
        }
        if (sameWork) {
            current.draft.clearOwnedBrowserWork();
            setBody('');
            setFiles([]);
            setBaseVersion(result.lock_version);
            submitted.current = null;
            submittedFiles.current = null;
            if (
                draftsEnabled &&
                result.draft &&
                result.canonical_ticket_id === ticketId
            ) {
                nextDraftRef.current = result.draft;
                setNextDraft(result.draft);
            }
        }
        if (!sameWork) {
            if (sent && result.draft)
                current.draft.acknowledgeConsumed(
                    result.draft,
                    current.snapshot,
                    { preserveLocal: true },
                );
            submitted.current = null;
            submittedFiles.current = null;
        }
        setLastCommit(result);
        setMessage(null);
        toast.success(itCommentCommitMessage(result));
        try {
            onPosted?.();
        } catch {
            setMessage(
                'The reply was added. Refresh the conversation to load its latest messages.',
            );
        }
    };
    // prepareNext is separate from acknowledgement, after the host has cleared only the matching work.
    useEffect(() => {
        if (
            command.stage === 'committed' &&
            lastCommit &&
            submitted.current === null
        )
            command.prepareNext();
    }, [command, lastCommit]);
    useEffect(() => {
        if (command.cancelled) {
            submitted.current = null;
            submittedFiles.current = null;
            setPendingRestore(null);
        }
    }, [command.cancelled]);

    const canContinueMatchedDraft = () => {
        const current = latest.current;
        return (
            mounted.current &&
            !current.concealed &&
            current.command.stage === 'editing' &&
            current.command.references.length === 0 &&
            current.command.frozenIntent === null &&
            !current.command.accessBlocker &&
            submitted.current === null &&
            current.snapshot.fields.body === '' &&
            current.files.length === 0 &&
            current.draft.attachments.length === 0 &&
            !current.draft.memoryBlocked &&
            !current.draft.memoryWarning
        );
    };
    const startNextMessage = async () => {
        const receipt = nextDraftRef.current;
        if (!receipt || nextWait.current || busy || !canContinueMatchedDraft())
            return;
        nextWait.current = true;
        setStartingNext(true);
        try {
            const checked = await latest.current.draft.check();
            if (
                !checked ||
                nextDraftRef.current !== receipt ||
                !canContinueMatchedDraft()
            )
                return;
            if (
                checked.draft_uuid !== receipt.draft_uuid ||
                checked.revision !== receipt.revision ||
                checked.state !== 'consumed' ||
                checked.purpose !==
                    (isInternal ? 'internal_note' : 'public_reply') ||
                checked.audience !== (isInternal ? 'internal' : 'public') ||
                checked.ticket_id !== ticketId ||
                checked.context_key !== `ticket:${ticketId}` ||
                checked.has_content ||
                checked.blocker ||
                checked.capabilities.start_new !== true
            ) {
                nextDraftRef.current = undefined;
                setNextDraft(undefined);
                setMessage(
                    'The saved draft changed. Use its recovery controls before continuing; your current work is kept.',
                );
                return;
            }
            if (!(await latest.current.draft.startNew())) return;
            if (nextDraftRef.current !== receipt || !canContinueMatchedDraft())
                return;
            nextDraftRef.current = undefined;
            setNextDraft(undefined);
            setMessage(null);
            focusNext.current = true;
        } finally {
            nextWait.current = false;
            if (mounted.current) setStartingNext(false);
        }
    };
    useEffect(() => {
        if (focusNext.current && editable && visible) {
            focusNext.current = false;
            document.getElementById(inputId)?.focus();
        }
    }, [editable, visible, inputId]);

    const snapshotKey = draftSnapshotKey(snapshot);
    const failedSave = useRef<string | null>(null);
    useEffect(() => {
        if (
            !draftsEnabled ||
            !editable ||
            !dirty ||
            draft.busy ||
            draft.state !== 'ready' ||
            draft.isSaved(snapshot) ||
            failedSave.current === snapshotKey ||
            Object.keys(draft.errors).length
        )
            return;
        const timer = window.setTimeout(async () => {
            const current = latest.current;
            if (!current.editable || current.draft.busy || current.concealed)
                return;
            if (!(await current.draft.save(current.snapshot)))
                failedSave.current = snapshotKey;
        }, 750);
        return () => window.clearTimeout(timer);
    }, [draftsEnabled, editable, dirty, draft, snapshot, snapshotKey]);

    const resumeMemory = async (restored: ItDraftBrowserRestored) => {
        setPendingRestore(restored);
        if (restored.pendingComment) {
            if (
                !(await command.adoptAuthorizedIntent(restored.pendingComment))
            ) {
                setMessage(
                    'The earlier reply is retained, but its access and original submission could not be confirmed. Check its result before retrying.',
                );
                return;
            }
            const originalSnapshot: ItDraftSnapshot = {
                fields: { body: restored.pendingComment.body },
                step_index: 0,
                base_ticket_version: restored.pendingComment.expectedVersion,
            };
            if (
                restored.pendingComment.draft &&
                !draft.registerRecoveredSubmission(
                    originalSnapshot,
                    restored.pendingComment.draft,
                )
            ) {
                setMessage(
                    'The earlier submission is retained. Check its outcome; the saved draft could not yet be matched for clearing.',
                );
            }
            submitted.current = originalSnapshot;
            submittedFiles.current = restored.pendingComment.files;
        }
        setBody(
            typeof restored.snapshot.fields.body === 'string'
                ? restored.snapshot.fields.body
                : '',
        );
        setFiles(restored.files);
        setBaseVersion(restored.snapshot.base_ticket_version ?? baseVersion);
        revealAuthorizedWork();
        setPendingRestore(null);
    };
    const send = async () => {
        const current = latest.current;
        if (
            !current.editable ||
            preparation.current ||
            !body.trim() ||
            stale ||
            current.draft.busy
        )
            return;
        preparation.current = true;
        setPreparing(true);
        setMessage(null);
        const frozenSnapshot = snapshot;
        const frozenFiles = [...files];
        try {
            let reference;
            if (draftsEnabled) {
                if (
                    !current.draft.isSaved(frozenSnapshot) &&
                    !(await current.draft.save(frozenSnapshot))
                )
                    return;
                if (!mounted.current) return;
                reference = current.draft.submissionReference(frozenSnapshot);
                if (!reference) {
                    setMessage(
                        'Save or review the draft and its files before submitting this message.',
                    );
                    return;
                }
            }
            if (!mounted.current || latest.current.concealed) return;
            submitted.current = frozenSnapshot;
            submittedFiles.current = frozenFiles;
            if (
                !current.command.submit({
                    body,
                    expectedVersion: baseVersion,
                    files: frozenFiles,
                    ...(reference ? { draft: reference } : {}),
                })
            ) {
                submitted.current = null;
                submittedFiles.current = null;
            }
        } finally {
            preparation.current = false;
            if (mounted.current) setPreparing(false);
        }
    };
    const chooseFiles = (selected: FileList | null) => {
        if (!selected || !editable) return;
        const next = Array.from(selected);
        if (input.current) input.current.value = '';
        if (files.length + draft.attachments.length + next.length > 5) {
            setMessage(
                'Choose up to 5 files in total. Your existing files have been kept.',
            );
            return;
        }
        if (next.some((file) => file.size > 10 * 1024 * 1024)) {
            setMessage(
                'Each file must be 10 MB or smaller. Choose a smaller file and try again.',
            );
            return;
        }
        if (next.some((file) => !isAllowedItAttachmentName(file.name))) {
            setMessage(
                'Choose an image, PDF, text, CSV, Word or Excel file. No files from this selection were added.',
            );
            return;
        }
        setMessage(null);
        setFiles([...files, ...next]);
    };
    const reviewError =
        command.stage === 'committed' && submitted.current !== null
            ? 'This reply is already added. Review the current ticket before keeping the entered text as a new unsent message.'
            : command.stage === 'rejected'
              ? (command.message ??
                'Review this ticket before editing the rejected message.')
              : command.references.length > 0 || command.frozenIntent
                ? undefined
                : concealed
                  ? 'Check your current ticket access before continuing the retained message.'
                  : stale
                    ? 'The ticket changed while you were writing. Review its current state before submitting.'
                    : draft.browserBlocker || draft.browserOutcomeUnknown
                      ? 'Review the current ticket before continuing this recovered message.'
                      : undefined;
    const acceptVersion = async (version: number) => {
        if (
            command.stage === 'committed' &&
            lastCommit &&
            submitted.current !== null
        ) {
            if (version < lastCommit.lock_version) {
                setMessage(
                    'The reviewed ticket does not yet include this reply. Review again before continuing.',
                );
                return;
            }
            if (draftsEnabled) {
                const checked = await draft.check();
                if (
                    !checked ||
                    (!checked.capabilities.read &&
                        !(
                            ['consumed', 'discarded', 'expired'].includes(
                                checked.state,
                            ) && checked.capabilities.start_new
                        ))
                ) {
                    setMessage(
                        'The reply is already added. Its saved draft state could not be checked; retry that check before continuing.',
                    );
                    return;
                }
            }
            if (
                !mounted.current ||
                latest.current.concealed ||
                latest.current.command.stage !== 'committed'
            )
                return;
            submitted.current = null;
            submittedFiles.current = null;
            draft.clearOwnedBrowserWork();
            setBaseVersion(version);
            setConfirmedVersion((current) => Math.max(current, version));
            setMessage(
                'The earlier reply is confirmed. Your entered text and selected files are kept as an unsent message.',
            );
            return;
        }
        if (command.stage === 'rejected') {
            if (
                !(await command.checkCurrentAccess()) ||
                !latest.current.command.releaseKnownRejection(version)
            )
                return;
        } else if (command.references.length || command.frozenIntent) return;
        else if (command.concealed && !(await command.checkCurrentAccess()))
            return;
        if (draft.browserBlocker || draft.browserOutcomeUnknown) {
            if (!draft.acknowledgeReviewedBrowserWork(version)) return;
        }
        setBaseVersion(version);
        setConfirmedVersion((current) => Math.max(current, version));
        revealAuthorizedWork();
        setMessage(null);
    };
    const tokens = body.toLowerCase().match(/[a-z0-9]{3,}/g) ?? [];
    const hints = kbSuggestions
        .map((article) => ({
            article,
            score: tokens.filter((token) =>
                article.title.toLowerCase().includes(token),
            ).length,
        }))
        .filter(({ score }) => score > 0)
        .sort((a, b) => b.score - a.score)
        .slice(0, 3);

    if (accessState) return null;

    return (
        <div className="space-y-4">
            <p className="text-sm text-muted-foreground">
                {isInternal
                    ? 'Only people with permission to work on this ticket can read this note.'
                    : 'This reply is visible to the people who can access this ticket. Email delivery is tracked separately.'}
            </p>
            {(message ||
                command.message ||
                Object.keys(command.errors).length > 0) && (
                <div
                    ref={error}
                    role="alert"
                    tabIndex={-1}
                    className="space-y-2 rounded-lg border border-border bg-muted/30 p-3 text-sm outline-none focus-visible:ring-2 focus-visible:ring-ring"
                >
                    {message && <p>{message}</p>}
                    {command.message && <p>{command.message}</p>}
                    {!concealed &&
                        Object.entries(command.errors).map(
                            ([field, detail]) => <p key={field}>{detail}</p>,
                        )}
                </div>
            )}
            {lastCommit && (
                <p role="status" className="text-sm">
                    {itCommentCommitMessage(lastCommit)}
                    {lastCommit.canonical_ticket_id !== ticketId && (
                        <a
                            className="ml-2 text-primary underline"
                            href={`/it/tickets/${lastCommit.canonical_ticket_id}`}
                        >
                            Open the current ticket
                        </a>
                    )}
                </p>
            )}
            {command.accessBlocker && (
                <p role="alert" className="text-sm">
                    {command.accessBlocker.message}
                </p>
            )}
            {pendingRestore && !command.busy && (
                <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    onClick={() => void resumeMemory(pendingRestore)}
                >
                    Retry browser work restoration
                </Button>
            )}
            {draftsEnabled &&
                ['session_expired', 'access_denied'].includes(draft.state) && (
                    <div
                        className="space-y-3 rounded-lg border border-border bg-muted/30 p-3 text-sm"
                        aria-label="Saved draft access recovery"
                    >
                        <p>
                            Sign in with the original account, then check the
                            saved draft before continuing.
                        </p>
                        <div className="flex flex-wrap gap-2">
                            <Button asChild variant="outline" size="sm">
                                <a
                                    href="/login"
                                    target="_blank"
                                    rel="noopener noreferrer"
                                >
                                    Sign in to recover draft
                                </a>
                            </Button>
                            <Button
                                variant="outline"
                                size="sm"
                                disabled={draft.busy}
                                onClick={async () => {
                                    const checked = await draft.check();
                                    if (checked?.capabilities.read)
                                        revealAuthorizedWork();
                                }}
                            >
                                Check saved draft access
                            </Button>
                        </div>
                    </div>
                )}
            {(command.references.length > 0 ||
                command.busy ||
                command.concealed) && (
                <div
                    className="space-y-3 rounded-lg border border-border bg-muted/30 p-3 text-sm"
                    aria-label="Reply outcome recovery"
                >
                    <p>
                        A pending submission keeps its original identity until
                        its outcome is confirmed.
                    </p>
                    <div className="flex flex-wrap gap-2">
                        {command.busy ? (
                            <Button
                                variant="outline"
                                size="sm"
                                onClick={command.stopWaiting}
                            >
                                Stop waiting
                            </Button>
                        ) : (
                            <>
                                {command.references.map((id, index) => (
                                    <div
                                        key={id}
                                        className="flex flex-wrap gap-2"
                                    >
                                        <Button
                                            variant="outline"
                                            size="sm"
                                            onClick={() =>
                                                command.recoverReference(id)
                                            }
                                        >
                                            Check reply {index + 1}
                                        </Button>
                                        <Button
                                            type="button"
                                            variant="outline"
                                            size="sm"
                                            onClick={() => setCancelUuid(id)}
                                        >
                                            Cancel pending reply {index + 1}
                                        </Button>
                                    </div>
                                ))}
                                {command.canRetryExact &&
                                    !concealed &&
                                    !command.accessBlocker && (
                                        <Button
                                            variant="outline"
                                            size="sm"
                                            onClick={command.retryExact}
                                        >
                                            Retry original reply
                                        </Button>
                                    )}
                                {command.requestUuid && (
                                    <Button
                                        variant="outline"
                                        size="sm"
                                        onClick={async () => {
                                            if (
                                                await command.checkCurrentAccess()
                                            )
                                                revealAuthorizedWork();
                                        }}
                                    >
                                        Check current access
                                    </Button>
                                )}
                                {command.stage === 'session' && (
                                    <Button asChild variant="outline" size="sm">
                                        <a
                                            href="/login"
                                            target="_blank"
                                            rel="noopener noreferrer"
                                        >
                                            Sign in again
                                        </a>
                                    </Button>
                                )}
                            </>
                        )}
                    </div>
                </div>
            )}
            {concealed && !command.requestUuid && (
                <Button asChild variant="outline" size="sm">
                    <a href="/login" target="_blank" rel="noopener noreferrer">
                        Sign in again
                    </a>
                </Button>
            )}
            <TicketVersionConflict
                error={reviewError}
                actorId={actorId}
                ticketId={ticketId}
                requiredCapability="comment"
                requireInternal={isInternal}
                onAccessLost={() => accessLost.current()}
                onSessionLost={() => setAccessHidden(true)}
                onReviewed={(version) => void acceptVersion(version)}
            />
            {concealed ? (
                <p role="status" className="text-sm text-muted-foreground">
                    The message and files are hidden until your current access
                    is verified.
                </p>
            ) : (
                <>
                    <fieldset
                        disabled={
                            command.busy ||
                            command.frozenIntent !== null ||
                            preparing
                        }
                    >
                        <TicketDraftRecovery
                            draft={draft}
                            snapshot={snapshot}
                            hasLocalChanges={dirty}
                            nextDraftAction={
                                nextDraft && canContinueMatchedDraft()
                                    ? {
                                          label: isInternal
                                              ? 'Add another internal note'
                                              : 'Write another reply',
                                          onClick: () =>
                                              void startNextMessage(),
                                          disabled: startingNext,
                                      }
                                    : undefined
                            }
                            onResume={(saved) => {
                                setBody(saved.payload.fields.body ?? '');
                                setFiles([]);
                                setBaseVersion(
                                    resumedDraftBaseVersion(saved) ??
                                        baseVersion,
                                );
                            }}
                            onResumeMemory={(restored) =>
                                void resumeMemory(restored)
                            }
                            onDiscarded={() => {
                                draft.clearOwnedBrowserWork();
                                setMessage(
                                    'The saved draft was discarded. Any text still entered here is kept until you discard it separately.',
                                );
                            }}
                            onStartNew={() => {
                                draft.clearOwnedBrowserWork();
                                setMessage(null);
                            }}
                            renderReview={(saved) => (
                                <div className="space-y-2">
                                    <p className="font-medium">
                                        Saved{' '}
                                        {isInternal
                                            ? 'internal note'
                                            : 'public reply'}
                                    </p>
                                    <p className="break-words whitespace-pre-wrap">
                                        {saved.payload.fields.body ||
                                            'No text saved.'}
                                    </p>
                                    <p>
                                        {saved.attachments.length} saved files
                                    </p>
                                </div>
                            )}
                        />
                    </fieldset>
                    <div className="space-y-2">
                        <label
                            htmlFor={inputId}
                            className="text-sm font-medium"
                        >
                            {isInternal ? 'Internal note' : 'Your reply'}
                        </label>
                        <Textarea
                            id={inputId}
                            value={body}
                            disabled={!editable}
                            rows={compact ? 3 : 5}
                            aria-invalid={Boolean(command.errors.body)}
                            onChange={(event) => {
                                setBody(event.target.value);
                                setLastCommit(null);
                            }}
                            onKeyDown={(event) => {
                                if (
                                    event.key === 'Enter' &&
                                    (event.ctrlKey || event.metaKey)
                                ) {
                                    event.preventDefault();
                                    void send();
                                }
                            }}
                            placeholder={
                                isInternal
                                    ? 'Record context or work for the IT team…'
                                    : 'Write an update or ask a question…'
                            }
                        />
                    </div>
                    {hints.length > 0 && (
                        <div
                            className="flex flex-wrap items-center gap-2"
                            aria-label="Related knowledge guides"
                        >
                            <span className="text-xs text-muted-foreground">
                                Related guides
                            </span>
                            {hints.map(({ article }) => (
                                <Button
                                    key={article.id}
                                    size="sm"
                                    variant="outline"
                                    disabled={!editable}
                                    onClick={() => {
                                        const reference = `Related guide: "${article.title}" — search it in the Knowledge tab.`;
                                        if (!body.includes(reference))
                                            setBody(
                                                body.trimEnd()
                                                    ? `${body.trimEnd()}\n\n${reference}`
                                                    : reference,
                                            );
                                    }}
                                >
                                    <BookOpen className="size-4" />
                                    {article.title}
                                </Button>
                            ))}
                        </div>
                    )}
                    {draftsEnabled && (
                        <TicketDraftFiles draft={draft} disabled={!editable} />
                    )}
                    {files.map((file, index) => (
                        <fieldset
                            key={`${file.name}-${index}`}
                            disabled={!editable}
                        >
                            <StagedFileCard
                                file={file}
                                onRemove={() => {
                                    if (editable)
                                        setFiles(
                                            files.filter((_, i) => i !== index),
                                        );
                                }}
                            />
                        </fieldset>
                    ))}
                    <div className="flex flex-wrap items-center justify-between gap-3">
                        <div className="flex flex-wrap items-center gap-2">
                            {!draftsEnabled && (
                                <>
                                    <input
                                        ref={input}
                                        type="file"
                                        multiple
                                        accept={IT_ATTACHMENT_ACCEPT}
                                        className="hidden"
                                        onChange={(event) =>
                                            chooseFiles(event.target.files)
                                        }
                                    />
                                    <Button
                                        variant="outline"
                                        size="sm"
                                        disabled={
                                            !editable || files.length >= 5
                                        }
                                        onClick={() => input.current?.click()}
                                    >
                                        <Paperclip className="size-4" />
                                        Attach
                                    </Button>
                                </>
                            )}
                            {dirty && (
                                <Button
                                    variant="ghost"
                                    size="sm"
                                    disabled={!editable || busy}
                                    onClick={() => setDiscardOpen(true)}
                                >
                                    Discard entered message
                                </Button>
                            )}
                            <span className="text-xs text-muted-foreground">
                                Ctrl+Enter to{' '}
                                {isInternal ? 'add note' : 'reply'}
                            </span>
                        </div>
                        <Button
                            onClick={() => void send()}
                            disabled={
                                !editable || busy || stale || !body.trim()
                            }
                        >
                            <Send className="size-4" />
                            {preparing
                                ? 'Preparing reply…'
                                : isInternal
                                  ? 'Add note'
                                  : 'Add reply'}
                        </Button>
                    </div>
                </>
            )}
            <ConfirmDialog
                open={discardOpen}
                onClose={() => setDiscardOpen(false)}
                title="Discard this entered message?"
                description="This removes the unsent text and selected files from this composer. A saved draft remains available until you explicitly discard it."
                confirmText="Discard entered message"
                onConfirm={() => {
                    if (editable && !busy) resetLocal();
                    setDiscardOpen(false);
                }}
            />
            <ConfirmDialog
                open={cancelUuid !== null}
                onClose={() => setCancelUuid(null)}
                title="Cancel this pending reply?"
                description="The server will prevent this submission from being added if it has not already finished. If it was already added, its saved result will be shown. Your saved draft is kept."
                confirmText="Check and cancel pending reply"
                onConfirm={async () => {
                    const id = cancelUuid;
                    setCancelUuid(null);
                    if (id) await command.cancelReference(id);
                }}
            />
        </div>
    );
}
