/* Canonical ticket profile: URL-addressable sections retain the shared thread
 * and its unsent fields. Classification, work and evidence have full-width
 * panels; the conversation has a separate context column. */
import { CsatRater, CsatStars } from '@/components/it/csat';
import { ItModuleShell } from '@/components/it/it-module-shell';
import {
    MergeTicketDialog,
    ResolveTicketDialog,
    type MergeTarget,
} from '@/components/it/it-wizards';
import { SlaEvidence, type SlaVerdict } from '@/components/it/sla-evidence';
import { TicketApprovalControls } from '@/components/it/ticket-approval-controls';
import { TicketCloseDialog } from '@/components/it/ticket-close-dialog';
import { TicketDuplicateSuggestions } from '@/components/it/ticket-duplicate-suggestions';
import {
    TICKET_IMPACT_OPTIONS,
    TICKET_URGENCY_OPTIONS,
} from '@/components/it/ticket-intake-fields';
import {
    TicketLinkedContext,
    type TicketDeviceOption,
    type TicketLinkedAlert,
    type TicketLinkedAsset,
    type TicketLinkedChange,
    type TicketLinkedDevice,
    type TicketLinkedMajorIncident,
    type TicketLinkedProblem,
} from '@/components/it/ticket-linked-context';
import { TicketMergeRecovery } from '@/components/it/ticket-merge-recovery';
import {
    TicketMergedOriginals,
    type MergedOriginals,
} from '@/components/it/ticket-merged-originals';
import { useTicketPropertyMutation } from '@/components/it/ticket-property-mutation';
import { TicketRelatedWork } from '@/components/it/ticket-related-work';
import { TicketReopenDialog } from '@/components/it/ticket-reopen-dialog';
import type { TicketResolution } from '@/components/it/ticket-resolution';
import { TicketResolutionSummary } from '@/components/it/ticket-resolution-summary';
import { TicketRoutingDialog } from '@/components/it/ticket-routing-dialog';
import {
    TicketRoutingSummary,
    type TicketRoutingDetails,
} from '@/components/it/ticket-routing-summary';
import {
    TicketThread,
    type ThreadAttachment,
    type ThreadComment,
    type ThreadEvent,
    type ThreadKbHint,
} from '@/components/it/ticket-thread';
import {
    requesterWaitingCopy,
    TicketWaitingDialog,
    waitingPartyLabel,
    waitingStatusLabel,
    type TicketWaitingDetails,
} from '@/components/it/ticket-waiting-dialog';
import { TicketWatchers } from '@/components/it/ticket-watchers';
import {
    TicketWorkTasks,
    type TicketWorkTask,
} from '@/components/it/ticket-work-tasks';
import {
    TicketWorkspaceHeader,
    ticketWorkspaceTab,
} from '@/components/it/ticket-workspace-header';
import type { MonitoringIncidentEvidence } from '@/components/monitoring/monitoring-incident-evidence-card';
import { Alert } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { EmptyState } from '@/components/ui/empty-state';
import { formatFileSize } from '@/components/ui/file-dropzone';
import { Input } from '@/components/ui/input';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { StatusBadge, type StatusVariant } from '@/components/ui/status-badge';
import type { ItApprovalWork } from '@/hooks/it-approval-work';
import type { ItDraftSnapshot } from '@/hooks/it-ticket-draft-contract';
import { summarizeItWorkTasks } from '@/hooks/it-work-task-lifecycle';
import { useItTicketDraft } from '@/hooks/use-it-ticket-draft';
import { useItTicketPageLeave } from '@/hooks/use-it-ticket-page-leave';
import { useTicketPageRefresh } from '@/hooks/use-ticket-page-refresh';
import {
    useTicketWatcherCommand,
    type TicketWatcher,
} from '@/hooks/use-ticket-watcher-command';
import AppLayout from '@/layouts/app-layout';
import { formatDateTime } from '@/lib/datetime';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router, usePage } from '@inertiajs/react';
import {
    Check,
    Clock3,
    GitMerge,
    Info,
    MessageSquare,
    Paperclip,
    RotateCcw,
    Server,
    ShieldCheck,
} from 'lucide-react';
import { useEffect, useLayoutEffect, useMemo, useRef, useState } from 'react';
import { toast } from 'sonner';
import { TicketConfirmResolutionDialog } from './_dialogs';

interface TicketPayload {
    id: number;
    lock_version: number;
    reference: string | null;
    title: string;
    description: string | null;
    work_type: string;
    service: { id: number; name: string } | null;
    category: string;
    subcategory: string | null;
    priority: string;
    impact?: string;
    urgency?: string;
    priority_decision?: {
        mode: 'automatic' | 'override' | 'legacy';
        derived_priority: string;
        reason?: string;
    } | null;
    status: string;
    workflow_state: string;
    waiting: TicketWaitingDetails | null;
    source: string;
    sla_state: string;
    sla?: SlaVerdict;
    first_response_due_at: string | null;
    resolution_due_at: string | null;
    first_responded_at: string | null;
    requester: {
        id: number | null;
        name: string;
        role: string | null;
        href: string | null;
    };
    assignee: { id: number; name: string; href: string | null } | null;
    routing?: TicketRoutingDetails;
    watchers: TicketWatcher[];
    asset: {
        id: number;
        name: string;
        tag: string | null;
        href: string | null;
    } | null;
    site: { id: number; name: string; href: string | null } | null;
    is_organisation_wide: boolean;
    provisioning_request: { id: number; item: string; status: string } | null;
    attachments: ThreadAttachment[];
    csat: {
        score: number;
        comment: string | null;
        submitted_at: string | null;
    } | null;
    created_at: string | null;
    created_human: string | null;
    updated_at: string | null;
    resolved_at: string | null;
    resolution?: TicketResolution | null;
    monitoring_recovered_at: string | null;
    closed_at: string | null;
    merged_into: { id: number; reference: string | null; title: string } | null;
    is_merged?: boolean;
    merged_originals?: MergedOriginals;
    merge_origin?: {
        id: number;
        reference: string | null;
        href: string;
    } | null;
    requires_approval: boolean;
    approval: {
        id: number;
        status: string;
        requested_by_name: string | null;
        approver_name: string | null;
        reason: string | null;
        requested_at: string | null;
        decided_at: string | null;
    } | null;
}

interface Props {
    viewer_user_id: number;
    conversation_ready?: boolean;
    ticket: TicketPayload;
    comments: ThreadComment[];
    events: ThreadEvent[];
    assignees: { id: number; name: string }[];
    approvals?: { id: number; status: string }[];
    approval_work?: ItApprovalWork | null;
    task_work?: {
        storage_ready: boolean;
        can_create: boolean;
        can_reorder: boolean;
    } | null;
    assetOptions: { id: number; name: string; tag: string | null }[];
    deviceOptions: TicketDeviceOption[];
    siteOptions: { id: number; name: string }[];
    serviceOptions: { id: number; name: string }[];
    teamOptions: { id: number; name: string }[];
    queueOptions?: { id: number; name: string }[];
    watcherOptions?: { id: number; name: string }[];
    kbSuggestions: ThreadKbHint[];
    mergeTargets: MergeTarget[];
    linked_context: {
        devices: TicketLinkedDevice[];
        assets?: TicketLinkedAsset[];
        alerts: TicketLinkedAlert[];
        incident_evidence: MonitoringIncidentEvidence[];
        changes: TicketLinkedChange[];
        problems: TicketLinkedProblem[];
        major_incidents: TicketLinkedMajorIncident[];
        related_tickets_count?: number;
        tasks: TicketWorkTask[];
    };
    can: {
        manage: boolean;
        manageWatchers?: boolean;
        linkDevices: boolean;
        assignApplicationWide: boolean;
        view: boolean;
        internal: boolean;
        comment: boolean;
        reopen: boolean;
        confirmResolution?: boolean;
        watching: boolean;
        rate: boolean;
        merge: boolean;
        requestApproval: boolean;
        decideApproval: boolean;
    };
    replyUnavailableReason: string | null;
    draftRecovery?: { enabled: boolean };
}

const statusVariant: Record<string, StatusVariant> = {
    open: 'warning',
    in_progress: 'info',
    waiting: 'warning',
    resolved: 'success',
    closed: 'neutral',
};

const priorityVariant: Record<string, StatusVariant> = {
    urgent: 'critical',
    high: 'critical',
    normal: 'info',
    low: 'neutral',
};

/** Sentinel — Radix <SelectItem value=""> crashes at runtime. */
const NONE = 'none';
const ALL_SITES = 'all_sites';

const WORKING_STATUSES = ['open', 'in_progress', 'waiting'];
const DRAFTS_DISABLED = { enabled: false };

const label = (raw: string) =>
    raw.replace(/[_-]/g, ' ').replace(/^\w/, (c) => c.toUpperCase());

export default function ItTicketShow({
    viewer_user_id,
    ticket,
    comments,
    events,
    assignees,
    approvals = [],
    approval_work = null,
    task_work,
    assetOptions,
    deviceOptions,
    siteOptions,
    serviceOptions,
    teamOptions,
    queueOptions = [],
    watcherOptions = [],
    kbSuggestions,
    mergeTargets,
    linked_context,
    can,
    replyUnavailableReason,
    draftRecovery = DRAFTS_DISABLED,
    conversation_ready = false,
}: Props) {
    const page = usePage<{ auth?: { user?: { id?: number } } }>();
    const myId = page.props.auth?.user?.id ?? null;
    const deliveryRefresh = useTicketPageRefresh(myId, ticket.id);
    const refreshAccess =
        deliveryRefresh.access ??
        (myId === null ? 'session' : viewer_user_id !== myId ? 'actor' : null);
    const concealed = refreshAccess !== null;
    const accessRef = useRef<HTMLDivElement>(null);
    const [completedConcealment, setCompletedConcealment] = useState<
        string | null
    >(null);
    const currentThread = useMemo(
        () => ({
            actorId: myId,
            ticket,
            comments,
            events,
            can,
            kbSuggestions,
            replyUnavailableReason,
            conversation_ready,
            draftRecovery,
        }),
        [
            myId,
            ticket,
            comments,
            events,
            can,
            kbSuggestions,
            replyUnavailableReason,
            conversation_ready,
            draftRecovery,
        ],
    );
    const [authorizedThread, setAuthorizedThread] = useState(currentThread);
    useEffect(() => {
        if (!concealed) setAuthorizedThread(currentThread);
    }, [concealed, currentThread]);
    const thread = concealed ? authorizedThread : currentThread;
    const watcherCommand = useTicketWatcherCommand({
        actorId: myId,
        ticketId: ticket.id,
        version: ticket.lock_version,
        canManage: can.manageWatchers === true && !concealed,
        watchers: ticket.watchers,
        options: watcherOptions,
        onCommitted: deliveryRefresh.refresh,
    });

    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Home', href: '/dashboard' },
        { title: 'IT & Support', href: '/it' },
        {
            title: concealed
                ? 'Ticket unavailable'
                : (ticket.reference ?? `Ticket ${ticket.id}`),
            href: `/it/tickets/${ticket.id}`,
        },
    ];

    const fieldScope = `${ticket.id}:${myId}`;
    const [subcategoryState, setSubcategoryState] = useState<{
        scope: string;
        value: string;
        baseVersion: number;
    } | null>(null);
    // A different record or actor never renders the previous private field,
    // while same-record refreshes retain an unsaved edit.
    const subcategory =
        subcategoryState?.scope === fieldScope
            ? subcategoryState.value
            : (ticket.subcategory ?? '');
    const subcategoryDirty = subcategory !== (ticket.subcategory ?? '');
    const setSubcategory = (value: string) =>
        setSubcategoryState(
            value === (ticket.subcategory ?? '')
                ? null
                : {
                      scope: fieldScope,
                      value,
                      baseVersion:
                          subcategoryDirty &&
                          subcategoryState?.scope === fieldScope
                              ? subcategoryState.baseVersion
                              : ticket.lock_version,
                  },
        );
    useEffect(() => {
        setSubcategoryState((current) =>
            current &&
            (current.scope !== fieldScope ||
                (ticket.lock_version > current.baseVersion &&
                    current.value.trim() === (ticket.subcategory ?? '')))
                ? null
                : current,
        );
    }, [fieldScope, ticket.lock_version, ticket.subcategory]);
    const [resolving, setResolving] = useState(false);
    const [merging, setMerging] = useState(false);
    const [closing, setClosing] = useState(false);
    const [reopening, setReopening] = useState(false);
    const [confirmingResolution, setConfirmingResolution] = useState(false);
    const [editingWaiting, setEditingWaiting] = useState(false);
    const [editingRouting, setEditingRouting] = useState(false);
    const conversationRef = useRef<HTMLDivElement>(null);
    const propertyMutation = useTicketPropertyMutation({
        actorId: myId,
        draftsEnabled: draftRecovery.enabled,
        recoveryTicket:
            can.manage &&
            refreshAccess !== 'access' &&
            refreshAccess !== 'actor'
                ? { id: ticket.id, lock_version: ticket.lock_version }
                : undefined,
    });
    const classificationContext = useMemo(
        () => ({ purpose: 'ticket_edit' as const, ticketId: ticket.id }),
        [ticket.id],
    );
    const classificationSnapshot = useMemo<ItDraftSnapshot>(
        () => ({
            fields: subcategoryDirty ? { subcategory } : {},
            step_index: 0,
            base_ticket_version:
                subcategoryState?.scope === fieldScope
                    ? subcategoryState.baseVersion
                    : ticket.lock_version,
        }),
        [
            subcategoryDirty,
            subcategory,
            subcategoryState,
            fieldScope,
            ticket.lock_version,
        ],
    );
    // Raw field input hands off to the canonical property editor. It never
    // initializes a competing persisted ticket_edit draft generation.
    const classificationMemory = useItTicketDraft({
        enabled: false,
        active:
            can.manage &&
            refreshAccess !== 'access' &&
            refreshAccess !== 'actor',
        actorId: myId ?? undefined,
        context: classificationContext,
        workingSnapshot: classificationSnapshot,
        workingDirty: subcategoryDirty,
        acceptedFields: ['subcategory'],
        onAccessLost: () => setSubcategoryState(null),
    });
    const pageLeave = useItTicketPageLeave({
        ticketId: ticket.id,
        actorId: myId,
        additionalDirty:
            can.manage && !propertyMutation.hasPending && subcategoryDirty,
    });
    const clearClassification = useRef(classificationMemory.clearBrowserWork);
    useLayoutEffect(() => {
        clearClassification.current = classificationMemory.clearBrowserWork;
    });
    useEffect(() => {
        if (!refreshAccess) {
            setCompletedConcealment(null);
            return;
        }
        accessRef.current?.focus();
        if (refreshAccess === 'session') return;
        setResolving(false);
        setMerging(false);
        setClosing(false);
        setReopening(false);
        setConfirmingResolution(false);
        setEditingWaiting(false);
        setEditingRouting(false);
        setSubcategoryState(null);
        clearClassification.current();
        // Child Thread effects have purged both audiences before this second
        // render removes the rest of the stale record from the DOM.
        setCompletedConcealment(`${ticket.id}:${refreshAccess}`);
    }, [refreshAccess, ticket.id]);
    const canViewWork = can.view || can.manage;
    const activeTab = ticketWorkspaceTab(
        new URL(page.url, 'https://local.invalid').searchParams.get('tab'),
        canViewWork,
    );
    const visitTab = (tab: string, after?: () => void) =>
        router.get(
            `/it/tickets/${ticket.id}${ticket.is_merged ? '/original' : ''}`,
            { tab: ticketWorkspaceTab(tab, canViewWork) },
            {
                preserveState: true,
                preserveScroll: true,
                onSuccess: () => {
                    if (after) requestAnimationFrame(after);
                },
            },
        );
    const focusReply = () => {
        const focus = () =>
            conversationRef.current
                ?.querySelector<HTMLTextAreaElement>('textarea')
                ?.focus();
        if (activeTab === 'messages') focus();
        else visitTab('messages', focus);
    };
    const requiredTasks = linked_context.tasks.filter(
        (task) => task.is_required,
    );
    const fileEntries = Array.from(
        new Map<
            number,
            ThreadAttachment & { source: string; internal: boolean }
        >([
            ...ticket.attachments.map(
                (file) =>
                    [
                        file.id,
                        { ...file, source: 'Original report', internal: false },
                    ] as const,
            ),
            ...comments.flatMap((comment) =>
                comment.attachments.map(
                    (file) =>
                        [
                            file.id,
                            {
                                ...file,
                                source: `${comment.author.name} · ${formatDateTime(comment.at)}`,
                                internal: comment.is_internal,
                            },
                        ] as const,
                ),
            ),
        ]).values(),
    );
    const relatedCount =
        (linked_context.related_tickets_count ?? 0) +
        linked_context.devices.filter(
            (item) => item.access.state === 'available',
        ).length +
        linked_context.alerts.filter(
            (item) => item.access.state === 'available',
        ).length +
        [
            ...linked_context.changes,
            ...linked_context.problems,
            ...linked_context.major_incidents,
        ].filter((item) => item.workspace_access.state === 'available').length +
        [
            ticket.asset,
            ticket.site,
            ticket.service,
            ticket.provisioning_request,
        ].filter(Boolean).length;

    /** PATCH a triage field and toast the outcome. */
    const patch = (
        data: Record<string, string | number | boolean | null>,
        _doneMsg = 'Ticket updated.',
        expectedVersion = ticket.lock_version,
    ) =>
        propertyMutation.submit(ticket.id, expectedVersion, data, {
            assigned_to_user_id:
                assignees.find(
                    (person) => person.id === data.assigned_to_user_id,
                )?.name ?? 'Unassigned',
            it_service_id:
                serviceOptions.find(
                    (service) => service.id === data.it_service_id,
                )?.name ?? 'No service selected',
            site_id:
                siteOptions.find((site) => site.id === data.site_id)?.name ??
                'All Sites',
            asset_id:
                assetOptions.find((asset) => asset.id === data.asset_id)
                    ?.name ?? 'No asset selected',
        });

    const copyReference = async () => {
        if (!ticket.reference) return;
        try {
            await navigator.clipboard.writeText(ticket.reference);
            toast.success(`${ticket.reference} copied.`);
        } catch {
            toast.error(
                'The reference could not be copied. Please select it from the ticket header.',
            );
        }
    };
    const copyLink = async () => {
        try {
            await navigator.clipboard.writeText(
                `${window.location.origin}/it/tickets/${ticket.id}`,
            );
            toast.success('Ticket link copied.');
        } catch {
            toast.error(
                'The link could not be copied. Please copy the address from your browser.',
            );
        }
    };

    const isWorking = WORKING_STATUSES.includes(ticket.status);
    const accessRecovery = concealed ? (
        <Alert
            ref={accessRef}
            role="alert"
            tabIndex={-1}
            className="m-5 w-auto space-y-3 rounded-xl border border-border bg-card p-5"
        >
            <h1 className="text-lg font-semibold">
                {refreshAccess === 'session'
                    ? 'Sign in to continue'
                    : refreshAccess === 'actor'
                      ? 'Your signed-in account changed'
                      : 'Ticket access unavailable'}
            </h1>
            <p>
                {refreshAccess === 'session'
                    ? 'Your conversation and entered work are hidden. Sign in with the same account, then check access again.'
                    : 'The previous ticket and entered work are hidden. Current access must be confirmed before continuing.'}
            </p>
            <div className="flex flex-wrap gap-2">
                {refreshAccess === 'session' && (
                    <Button asChild variant="outline">
                        <a href="/login" target="_blank" rel="noreferrer">
                            Sign in
                        </a>
                    </Button>
                )}
                {refreshAccess === 'actor' || deliveryRefresh.requiresReload ? (
                    <Button asChild variant="outline">
                        <a href={`/it/tickets/${ticket.id}`}>Reload page</a>
                    </Button>
                ) : (
                    <Button
                        type="button"
                        variant="outline"
                        disabled={deliveryRefresh.pending}
                        onClick={deliveryRefresh.refresh}
                    >
                        {deliveryRefresh.pending
                            ? 'Checking access…'
                            : 'Check access again'}
                    </Button>
                )}
            </div>
        </Alert>
    ) : null;
    if (
        concealed &&
        refreshAccess !== 'session' &&
        completedConcealment === `${ticket.id}:${refreshAccess}`
    )
        return (
            <AppLayout breadcrumbs={breadcrumbs}>
                <Head title="Ticket unavailable" />
                {accessRecovery}
            </AppLayout>
        );

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head
                title={
                    concealed
                        ? 'Ticket unavailable'
                        : `${ticket.reference ?? 'Ticket'} — ${ticket.title}`
                }
            />
            {accessRecovery}
            {(!concealed || refreshAccess === 'session') &&
                pageLeave.confirmation}
            <div hidden={concealed} inert={concealed || undefined}>
                {!concealed && propertyMutation.recovery}
                {!concealed &&
                editingRouting &&
                can.manage &&
                ticket.routing ? (
                    <TicketRoutingDialog
                        ticket={{
                            id: ticket.id,
                            lock_version: ticket.lock_version,
                            assignee: ticket.assignee,
                            routing: ticket.routing,
                        }}
                        queues={queueOptions}
                        agents={assignees}
                        onClose={() => setEditingRouting(false)}
                    />
                ) : null}
                {!concealed && resolving ? (
                    <ResolveTicketDialog
                        ticket={{
                            id: ticket.id,
                            lock_version: ticket.lock_version,
                            reference: ticket.reference,
                            title: ticket.title,
                        }}
                        onClose={() => setResolving(false)}
                    />
                ) : null}
                {!concealed && merging && myId !== null ? (
                    <MergeTicketDialog
                        actorId={myId}
                        ticket={{
                            id: ticket.id,
                            reference: ticket.reference,
                            title: ticket.title,
                            lock_version: ticket.lock_version,
                        }}
                        targets={mergeTargets}
                        onClose={() => setMerging(false)}
                    />
                ) : null}
                <TicketCloseDialog
                    open={!concealed && closing}
                    onOpenChange={setClosing}
                    scope="single"
                    ticketIds={[ticket.id]}
                    expectedVersions={{ [ticket.id]: ticket.lock_version }}
                    ticketReference={ticket.reference}
                />
                <TicketReopenDialog
                    open={!concealed && reopening}
                    onOpenChange={setReopening}
                    ticketId={ticket.id}
                    expectedVersion={ticket.lock_version}
                    ticketReference={ticket.reference}
                    audience={
                        ticket.requester.id === myId ? 'requester' : 'agent'
                    }
                />
                {!concealed && confirmingResolution && myId !== null && (
                    <TicketConfirmResolutionDialog
                        key={`${myId}:${ticket.id}`}
                        ticketId={ticket.id}
                        expectedVersion={ticket.lock_version}
                        actorId={myId}
                        onClose={() => setConfirmingResolution(false)}
                        onConfirmed={() => router.reload()}
                    />
                )}
                <TicketWaitingDialog
                    open={!concealed && editingWaiting}
                    onOpenChange={setEditingWaiting}
                    scope="single"
                    ticketIds={[ticket.id]}
                    expectedVersions={{ [ticket.id]: ticket.lock_version }}
                    ticketReference={ticket.reference}
                    current={ticket.waiting}
                />

                <ItModuleShell>
                    <div className="flex flex-col gap-5">
                        <TicketWorkspaceHeader
                            id={ticket.id}
                            reference={ticket.reference}
                            title={ticket.title}
                            status={
                                ticket.status === 'waiting'
                                    ? waitingStatusLabel(
                                          ticket.waiting?.party,
                                          !can.manage,
                                      )
                                    : label(ticket.status)
                            }
                            statusVariant={
                                statusVariant[ticket.status] ?? 'neutral'
                            }
                            subline={
                                <>
                                    {ticket.source === 'system' &&
                                    ticket.requester.id === null ? (
                                        'Raised automatically'
                                    ) : ticket.requester.href ? (
                                        <>
                                            Raised by{' '}
                                            <Link
                                                href={ticket.requester.href}
                                                className="rounded-sm underline underline-offset-2 focus-visible:ring-2"
                                            >
                                                {ticket.requester.name}
                                            </Link>
                                        </>
                                    ) : (
                                        <>Raised by {ticket.requester.name}</>
                                    )}
                                    {ticket.created_human
                                        ? ' · ' + ticket.created_human
                                        : ''}
                                </>
                            }
                            activeTab={activeTab}
                            onTab={visitTab}
                            original={ticket.is_merged}
                            sla={ticket.sla}
                            replies={comments.length}
                            files={fileEntries.length}
                            tasks={
                                canViewWork
                                    ? summarizeItWorkTasks(requiredTasks)
                                    : null
                            }
                            related={relatedCount}
                            primary={
                                can.confirmResolution
                                    ? {
                                          label: 'Confirm the fix',
                                          icon: Check,
                                          run: () =>
                                              setConfirmingResolution(true),
                                      }
                                    : can.comment
                                      ? {
                                            label: 'Write a reply',
                                            icon: MessageSquare,
                                            run: focusReply,
                                        }
                                      : can.reopen &&
                                          ['resolved', 'closed'].includes(
                                              ticket.status,
                                          )
                                        ? {
                                              label: 'Reopen ticket',
                                              icon: RotateCcw,
                                              run: () => setReopening(true),
                                          }
                                        : undefined
                            }
                            actions={{
                                resolve:
                                    can.manage && isWorking
                                        ? () => setResolving(true)
                                        : undefined,
                                close:
                                    can.manage && ticket.status === 'resolved'
                                        ? () => setClosing(true)
                                        : undefined,
                                merge: can.merge
                                    ? () => setMerging(true)
                                    : undefined,
                                assign:
                                    can.manage &&
                                    isWorking &&
                                    myId !== null &&
                                    ticket.assignee?.id !== myId
                                        ? () =>
                                              patch({
                                                  assigned_to_user_id: myId,
                                              })
                                        : undefined,
                                watch:
                                    watcherCommand.canManage &&
                                    myId !== null &&
                                    (watcherCommand.watchers.some(
                                        (row) => row.id === myId,
                                    ) ||
                                        watcherCommand.options.some(
                                            (row) => row.id === myId,
                                        ))
                                        ? () =>
                                              watcherCommand.begin(
                                                  myId,
                                                  !watcherCommand.watchers.some(
                                                      (row) => row.id === myId,
                                                  ),
                                              )
                                        : undefined,
                                watching: watcherCommand.watchers.some(
                                    (row) => row.id === myId,
                                ),
                                busy:
                                    watcherCommand.busy ||
                                    propertyMutation.busy,
                                copyReference,
                                copyLink,
                            }}
                        />
                        <TicketMergedOriginals
                            originals={ticket.merged_originals}
                        />
                        {can.internal && myId !== null && !merging && (
                            <>
                                <TicketMergeRecovery
                                    actorId={myId}
                                    sourceId={ticket.id}
                                />
                                {ticket.merge_origin &&
                                    ticket.merge_origin.id !== ticket.id && (
                                        <TicketMergeRecovery
                                            actorId={myId}
                                            sourceId={ticket.merge_origin.id}
                                        />
                                    )}
                            </>
                        )}
                        {ticket.merge_origin ? (
                            <div className="flex flex-wrap items-center gap-2 rounded-2xl border border-border bg-muted/40 px-4 py-3 text-[13px]">
                                <GitMerge className="h-4 w-4 flex-none text-muted-foreground" />
                                <span>
                                    You are viewing the surviving ticket.
                                </span>
                                <Link
                                    href={ticket.merge_origin.href}
                                    className="font-semibold text-primary hover:underline"
                                >
                                    View original record{' '}
                                    {ticket.merge_origin.reference ??
                                        `#${ticket.merge_origin.id}`}
                                </Link>
                            </div>
                        ) : null}
                        {ticket.merged_into ? (
                            <div className="flex flex-wrap items-center gap-2 rounded-2xl border border-border bg-muted/40 px-4 py-3 text-[13px]">
                                <GitMerge className="h-4 w-4 flex-none text-muted-foreground" />
                                <span className="font-medium">
                                    This ticket was merged into
                                </span>
                                <Link
                                    href={`/it/tickets/${ticket.merged_into.id}`}
                                    className="font-mono font-semibold text-primary hover:underline"
                                >
                                    {ticket.merged_into.reference ??
                                        `#${ticket.merged_into.id}`}
                                </Link>
                                <span className="min-w-0 truncate text-muted-foreground">
                                    — {ticket.merged_into.title}
                                </span>
                            </div>
                        ) : ticket.is_merged ? (
                            <div className="rounded-2xl border border-border bg-muted/40 px-4 py-3 text-[13px]">
                                This is the original merged record. The
                                surviving ticket is not available to your
                                current account.
                            </div>
                        ) : null}

                        {can.confirmResolution && (
                            <section
                                aria-label="Resolution follow-up"
                                className="flex flex-wrap items-center justify-between gap-4 rounded-2xl border border-border bg-card p-5"
                            >
                                <div className="min-w-0 flex-1">
                                    <h2 className="text-base font-semibold">
                                        Has this fixed the problem?
                                    </h2>
                                    <p className="mt-1 text-sm text-muted-foreground">
                                        Review the fix in the conversation. You
                                        can rate IT’s help before confirming and
                                        closing the ticket.
                                    </p>
                                </div>
                                <div className="flex flex-wrap gap-2">
                                    {can.reopen && (
                                        <Button
                                            variant="outline"
                                            onClick={() => setReopening(true)}
                                        >
                                            I still need help
                                        </Button>
                                    )}
                                    <Button
                                        onClick={() =>
                                            setConfirmingResolution(true)
                                        }
                                    >
                                        <Check className="size-4" />
                                        Confirm the fix
                                    </Button>
                                </div>
                            </section>
                        )}

                        {ticket.status === 'waiting' && ticket.waiting ? (
                            <div className="flex flex-wrap items-start gap-3 rounded-2xl border border-status-warning/30 bg-status-warning-bg px-4 py-3">
                                <span className="grid h-10 w-10 flex-none place-items-center rounded-xl bg-background/70 text-status-warning ring-1 ring-status-warning/20">
                                    <Clock3
                                        className="h-5 w-5"
                                        aria-hidden="true"
                                    />
                                </span>
                                <div className="min-w-0 flex-1">
                                    <p className="text-sm font-semibold text-foreground">
                                        {can.manage
                                            ? `Waiting on ${waitingPartyLabel(ticket.waiting.party)}`
                                            : waitingStatusLabel(
                                                  ticket.waiting.party,
                                                  true,
                                              )}
                                    </p>
                                    <p className="mt-0.5 text-sm text-muted-foreground">
                                        {can.manage
                                            ? (ticket.waiting.reason ??
                                              'No waiting reason was recorded.')
                                            : requesterWaitingCopy(
                                                  ticket.waiting.party,
                                              )}
                                    </p>
                                    {can.manage &&
                                    ticket.waiting.next_action ? (
                                        <p className="mt-1 text-sm text-foreground">
                                            <span className="font-semibold">
                                                Next action:
                                            </span>{' '}
                                            {ticket.waiting.next_action}
                                        </p>
                                    ) : null}
                                    {ticket.waiting.since ? (
                                        <p className="mt-1 text-xs text-muted-foreground">
                                            Waiting since{' '}
                                            {formatDateTime(
                                                ticket.waiting.since,
                                            )}
                                        </p>
                                    ) : null}
                                </div>
                                {can.manage && isWorking ? (
                                    <Button
                                        type="button"
                                        variant="outline"
                                        className="min-h-11 flex-none bg-background"
                                        onClick={() => setEditingWaiting(true)}
                                    >
                                        <Clock3
                                            className="h-4 w-4"
                                            aria-hidden="true"
                                        />
                                        Edit waiting details
                                    </Button>
                                ) : null}
                            </div>
                        ) : null}

                        <div
                            id="it-ticket-panel"
                            role="tabpanel"
                            aria-labelledby={'it-ticket-tab-' + activeTab}
                        >
                            <div
                                hidden={
                                    !['messages', 'history'].includes(activeTab)
                                }
                            >
                                <div className="grid items-start gap-5 xl:grid-cols-[minmax(0,1.7fr)_minmax(360px,1fr)]">
                                    <div
                                        ref={conversationRef}
                                        className="min-w-0"
                                    >
                                        {deliveryRefresh.pending && (
                                            <p
                                                role="status"
                                                className="mb-3 text-sm text-muted-foreground"
                                            >
                                                Refreshing conversation and
                                                delivery state…
                                            </p>
                                        )}
                                        {deliveryRefresh.error && (
                                            <div
                                                role="alert"
                                                className="mb-3 space-y-2 rounded-lg border border-border bg-muted/40 p-3 text-sm"
                                            >
                                                <p>{deliveryRefresh.error}</p>
                                                <Button
                                                    type="button"
                                                    variant="outline"
                                                    size="sm"
                                                    onClick={
                                                        deliveryRefresh.refresh
                                                    }
                                                >
                                                    Retry refresh
                                                </Button>
                                            </div>
                                        )}
                                        {!concealed &&
                                            can.manage &&
                                            activeTab === 'messages' &&
                                            ['resolved', 'closed'].includes(
                                                ticket.status,
                                            ) && (
                                                <TicketResolutionSummary
                                                    resolution={
                                                        ticket.resolution
                                                    }
                                                    onHistory={() =>
                                                        visitTab('history')
                                                    }
                                                />
                                            )}
                                        <TicketThread
                                            key={
                                                thread.ticket.id +
                                                ':' +
                                                thread.actorId
                                            }
                                            ticketId={thread.ticket.id}
                                            actorId={
                                                thread.actorId ?? undefined
                                            }
                                            expectedVersion={
                                                thread.ticket.lock_version
                                            }
                                            conversationReady={
                                                thread.conversation_ready
                                            }
                                            draftsEnabled={
                                                thread.draftRecovery.enabled
                                            }
                                            accessState={refreshAccess}
                                            onPosted={deliveryRefresh.refresh}
                                            refreshingDelivery={
                                                deliveryRefresh.pending
                                            }
                                            requesterName={
                                                thread.ticket.requester.name
                                            }
                                            description={
                                                thread.ticket.description
                                            }
                                            ticketAttachments={
                                                thread.ticket.attachments
                                            }
                                            comments={thread.comments}
                                            events={thread.events}
                                            canInternal={thread.can.internal}
                                            canReply={thread.can.comment}
                                            onDraftStateChange={
                                                pageLeave.onDraftStateChange
                                            }
                                            replyUnavailableReason={
                                                thread.replyUnavailableReason
                                            }
                                            kbSuggestions={thread.kbSuggestions}
                                            hideNavigation
                                            lane={
                                                activeTab === 'history'
                                                    ? 'activity'
                                                    : 'conversation'
                                            }
                                        />
                                    </div>
                                    <aside
                                        aria-label="Ticket at a glance"
                                        className="flex min-w-0 flex-col gap-5"
                                    >
                                        <section className="space-y-4 rounded-2xl border border-border bg-card p-5">
                                            <div className="flex items-center justify-between gap-3">
                                                <h2 className="text-sm font-semibold">
                                                    Ticket at a glance
                                                </h2>
                                                <StatusBadge
                                                    variant={
                                                        priorityVariant[
                                                            ticket.priority
                                                        ] ?? 'neutral'
                                                    }
                                                >
                                                    {label(ticket.priority)}
                                                </StatusBadge>
                                            </div>
                                            <p className="text-sm font-medium break-words">
                                                {ticket.title}
                                            </p>
                                            <dl className="grid grid-cols-2 gap-4 text-sm">
                                                <div>
                                                    <dt className="text-xs text-muted-foreground">
                                                        Assigned to
                                                    </dt>
                                                    <dd className="mt-1 font-medium">
                                                        {ticket.assignee
                                                            ?.href ? (
                                                            <Link
                                                                href={
                                                                    ticket
                                                                        .assignee
                                                                        .href
                                                                }
                                                                className="frontline-focus rounded-sm text-primary hover:underline"
                                                            >
                                                                {
                                                                    ticket
                                                                        .assignee
                                                                        .name
                                                                }
                                                            </Link>
                                                        ) : (
                                                            (ticket.assignee
                                                                ?.name ??
                                                            'With IT for triage')
                                                        )}
                                                    </dd>
                                                </div>
                                                <div>
                                                    <dt className="text-xs text-muted-foreground">
                                                        Ticket Site
                                                    </dt>
                                                    <dd className="mt-1 font-medium">
                                                        {ticket.is_organisation_wide ? (
                                                            'All Sites'
                                                        ) : ticket.site
                                                              ?.href ? (
                                                            <Link
                                                                href={
                                                                    ticket.site
                                                                        .href
                                                                }
                                                                className="frontline-focus rounded-sm text-primary hover:underline"
                                                            >
                                                                {
                                                                    ticket.site
                                                                        .name
                                                                }
                                                            </Link>
                                                        ) : (
                                                            (ticket.site
                                                                ?.name ??
                                                            'Site unavailable')
                                                        )}
                                                    </dd>
                                                </div>
                                                <div>
                                                    <dt className="text-xs text-muted-foreground">
                                                        Affected service
                                                    </dt>
                                                    <dd className="mt-1 font-medium">
                                                        {ticket.service?.name ??
                                                            'Not classified'}
                                                    </dd>
                                                </div>
                                                <div>
                                                    <dt className="text-xs text-muted-foreground">
                                                        Work type
                                                    </dt>
                                                    <dd className="mt-1 font-medium">
                                                        {label(
                                                            ticket.work_type,
                                                        )}
                                                    </dd>
                                                </div>
                                            </dl>
                                            <Button
                                                variant="outline"
                                                onClick={() =>
                                                    visitTab('properties')
                                                }
                                            >
                                                <Info className="size-4" />
                                                Classification & ownership
                                            </Button>
                                        </section>
                                        <section className="space-y-3 rounded-2xl border border-border bg-card p-5">
                                            <h2 className="text-sm font-semibold">
                                                Service levels
                                            </h2>
                                            <SlaEvidence sla={ticket.sla} />
                                            <Button
                                                variant="outline"
                                                onClick={() => visitTab('sla')}
                                            >
                                                View clock evidence
                                            </Button>
                                        </section>
                                        <section className="space-y-4 rounded-2xl border border-border bg-card p-5">
                                            <h2 className="text-sm font-semibold">
                                                People & feedback
                                            </h2>
                                            <TicketWatchers
                                                command={watcherCommand}
                                                actorId={myId}
                                            />

                                            {/* CSAT (§K) — the requester rates the fix; the score reads
                            back to everyone (agents see it, never edit it). */}
                                            {can.rate ? (
                                                <RailField
                                                    label={
                                                        ticket.csat
                                                            ? 'Your rating'
                                                            : 'Rate the fix'
                                                    }
                                                >
                                                    <CsatRater
                                                        ticketId={ticket.id}
                                                        expectedVersion={
                                                            ticket.lock_version
                                                        }
                                                        score={
                                                            ticket.csat
                                                                ?.score ?? null
                                                        }
                                                        comment={
                                                            ticket.csat
                                                                ?.comment ?? ''
                                                        }
                                                    />
                                                </RailField>
                                            ) : ticket.csat ? (
                                                <RailField label="Satisfaction">
                                                    <div className="flex flex-col gap-1">
                                                        <CsatStars
                                                            score={
                                                                ticket.csat
                                                                    .score
                                                            }
                                                        />
                                                        {ticket.csat.comment ? (
                                                            <p className="text-[12px] text-muted-foreground">
                                                                “
                                                                {
                                                                    ticket.csat
                                                                        .comment
                                                                }
                                                                ”
                                                            </p>
                                                        ) : null}
                                                    </div>
                                                </RailField>
                                            ) : null}
                                        </section>
                                    </aside>
                                </div>
                            </div>
                            <section
                                hidden={activeTab !== 'properties'}
                                className="rounded-2xl border border-border bg-card p-5"
                            >
                                <h2 className="text-base font-semibold">
                                    Classification & ownership
                                </h2>
                                {can.manage && isWorking && myId !== null && (
                                    <TicketDuplicateSuggestions
                                        actorId={myId}
                                        sourceId={ticket.id}
                                        sourceVersion={ticket.lock_version}
                                        title={ticket.title}
                                        siteId={ticket.site?.id ?? null}
                                    />
                                )}
                                <p className="mt-1 mb-5 text-sm text-muted-foreground">
                                    {can.manage && isWorking
                                        ? 'Classify the request and confirm who is responsible. Each change is checked before it is saved.'
                                        : 'The recorded classification and ownership for this ticket.'}
                                </p>
                                <div className="grid items-start gap-5 md:grid-cols-2 xl:grid-cols-3">
                                    {can.manage && isWorking ? (
                                        <>
                                            <RailField label="Status">
                                                <Select
                                                    disabled={
                                                        propertyMutation.busy
                                                    }
                                                    value={ticket.status}
                                                    onValueChange={(v) => {
                                                        if (v === 'waiting') {
                                                            setEditingWaiting(
                                                                true,
                                                            );
                                                            return;
                                                        }
                                                        patch(
                                                            { status: v },
                                                            `Status set to ${label(v)}.`,
                                                        );
                                                    }}
                                                >
                                                    <SelectTrigger
                                                        className="h-8"
                                                        aria-label="Status"
                                                    >
                                                        <SelectValue />
                                                    </SelectTrigger>
                                                    <SelectContent>
                                                        {WORKING_STATUSES.map(
                                                            (s) => (
                                                                <SelectItem
                                                                    key={s}
                                                                    value={s}
                                                                >
                                                                    {label(s)}
                                                                </SelectItem>
                                                            ),
                                                        )}
                                                    </SelectContent>
                                                </Select>
                                            </RailField>
                                            <RailField label="Priority">
                                                <Select
                                                    disabled={
                                                        propertyMutation.busy
                                                    }
                                                    value={ticket.priority}
                                                    onValueChange={(v) =>
                                                        patch(
                                                            { priority: v },
                                                            `Priority set to ${label(v)}.`,
                                                        )
                                                    }
                                                >
                                                    <SelectTrigger
                                                        className="h-8"
                                                        aria-label="Priority"
                                                    >
                                                        <SelectValue />
                                                    </SelectTrigger>
                                                    <SelectContent>
                                                        {[
                                                            'low',
                                                            'normal',
                                                            'high',
                                                            'urgent',
                                                        ].map((p) => (
                                                            <SelectItem
                                                                key={p}
                                                                value={p}
                                                            >
                                                                {label(p)}
                                                            </SelectItem>
                                                        ))}
                                                    </SelectContent>
                                                </Select>
                                                {ticket.priority_decision && (
                                                    <div className="mt-2 space-y-1 text-sm text-muted-foreground">
                                                        <p>
                                                            Assessed priority:{' '}
                                                            {label(
                                                                ticket
                                                                    .priority_decision
                                                                    .derived_priority,
                                                            )}
                                                            .
                                                        </p>
                                                        {ticket
                                                            .priority_decision
                                                            .mode ===
                                                            'legacy' && (
                                                            <p>
                                                                The existing
                                                                priority is
                                                                retained until
                                                                its impact and
                                                                urgency are
                                                                reviewed.
                                                            </p>
                                                        )}
                                                        {ticket
                                                            .priority_decision
                                                            .mode ===
                                                            'override' && (
                                                            <>
                                                                <p>
                                                                    Manual
                                                                    priority:{' '}
                                                                    {
                                                                        ticket
                                                                            .priority_decision
                                                                            .reason
                                                                    }
                                                                </p>
                                                                <Button
                                                                    variant="ghost"
                                                                    disabled={
                                                                        propertyMutation.busy
                                                                    }
                                                                    onClick={() =>
                                                                        patch({
                                                                            release_priority_override: true,
                                                                        })
                                                                    }
                                                                >
                                                                    Use assessed
                                                                    priority
                                                                </Button>
                                                            </>
                                                        )}
                                                    </div>
                                                )}
                                            </RailField>
                                            {(
                                                ['impact', 'urgency'] as const
                                            ).map((dimension) => (
                                                <RailField
                                                    key={dimension}
                                                    label={
                                                        dimension === 'impact'
                                                            ? 'Who is affected?'
                                                            : 'Urgency assessment'
                                                    }
                                                >
                                                    <Select
                                                        disabled={
                                                            propertyMutation.busy
                                                        }
                                                        value={
                                                            ticket[dimension] ??
                                                            (dimension ===
                                                            'impact'
                                                                ? 'individual'
                                                                : 'normal')
                                                        }
                                                        onValueChange={(
                                                            value,
                                                        ) =>
                                                            patch({
                                                                [dimension]:
                                                                    value,
                                                            })
                                                        }
                                                    >
                                                        <SelectTrigger
                                                            aria-label={
                                                                dimension ===
                                                                'impact'
                                                                    ? 'Who is affected?'
                                                                    : 'Urgency assessment'
                                                            }
                                                        >
                                                            <SelectValue />
                                                        </SelectTrigger>
                                                        <SelectContent>
                                                            {(dimension ===
                                                            'impact'
                                                                ? TICKET_IMPACT_OPTIONS
                                                                : TICKET_URGENCY_OPTIONS
                                                            ).map((option) => (
                                                                <SelectItem
                                                                    key={
                                                                        option.value
                                                                    }
                                                                    value={
                                                                        option.value
                                                                    }
                                                                >
                                                                    {
                                                                        option.label
                                                                    }
                                                                </SelectItem>
                                                            ))}
                                                        </SelectContent>
                                                    </Select>
                                                </RailField>
                                            ))}
                                            <RailField label="Work type">
                                                <Select
                                                    disabled={
                                                        propertyMutation.busy
                                                    }
                                                    value={ticket.work_type}
                                                    onValueChange={(v) =>
                                                        patch(
                                                            { work_type: v },
                                                            `Work type set to ${label(v)}.`,
                                                        )
                                                    }
                                                >
                                                    <SelectTrigger
                                                        className="h-8"
                                                        aria-label="Work type"
                                                    >
                                                        <SelectValue />
                                                    </SelectTrigger>
                                                    <SelectContent>
                                                        {[
                                                            'incident',
                                                            'service_request',
                                                            'security_request',
                                                        ].map((workType) => (
                                                            <SelectItem
                                                                key={workType}
                                                                value={workType}
                                                            >
                                                                {label(
                                                                    workType,
                                                                )}
                                                            </SelectItem>
                                                        ))}
                                                    </SelectContent>
                                                </Select>
                                            </RailField>
                                            <RailField label="Affected service">
                                                <Select
                                                    disabled={
                                                        propertyMutation.busy
                                                    }
                                                    value={
                                                        ticket.service
                                                            ? String(
                                                                  ticket.service
                                                                      .id,
                                                              )
                                                            : NONE
                                                    }
                                                    onValueChange={(v) =>
                                                        patch(
                                                            {
                                                                it_service_id:
                                                                    v === NONE
                                                                        ? null
                                                                        : Number(
                                                                              v,
                                                                          ),
                                                            },
                                                            'Affected service updated.',
                                                        )
                                                    }
                                                >
                                                    <SelectTrigger
                                                        className="h-8"
                                                        aria-label="Affected service"
                                                    >
                                                        <SelectValue />
                                                    </SelectTrigger>
                                                    <SelectContent>
                                                        <SelectItem
                                                            value={NONE}
                                                        >
                                                            No service selected
                                                        </SelectItem>
                                                        {serviceOptions.map(
                                                            (serviceOption) => (
                                                                <SelectItem
                                                                    key={
                                                                        serviceOption.id
                                                                    }
                                                                    value={String(
                                                                        serviceOption.id,
                                                                    )}
                                                                >
                                                                    {
                                                                        serviceOption.name
                                                                    }
                                                                </SelectItem>
                                                            ),
                                                        )}
                                                    </SelectContent>
                                                </Select>
                                            </RailField>
                                            <RailField label="Ticket Site">
                                                <Select
                                                    disabled={
                                                        propertyMutation.busy
                                                    }
                                                    value={
                                                        ticket.is_organisation_wide
                                                            ? ALL_SITES
                                                            : ticket.site
                                                              ? String(
                                                                    ticket.site
                                                                        .id,
                                                                )
                                                              : undefined
                                                    }
                                                    onValueChange={(v) => {
                                                        if (v === ALL_SITES) {
                                                            patch(
                                                                {
                                                                    site_id:
                                                                        null,
                                                                    is_organisation_wide: true,
                                                                },
                                                                'Ticket now applies to all Sites.',
                                                            );
                                                            return;
                                                        }

                                                        const selectedSite =
                                                            siteOptions.find(
                                                                (site) =>
                                                                    site.id ===
                                                                    Number(v),
                                                            );
                                                        patch(
                                                            {
                                                                site_id:
                                                                    Number(v),
                                                                is_organisation_wide: false,
                                                            },
                                                            selectedSite
                                                                ? `Ticket moved to ${selectedSite.name}.`
                                                                : 'Ticket Site updated.',
                                                        );
                                                    }}
                                                >
                                                    <SelectTrigger
                                                        className="h-8"
                                                        aria-label="Ticket Site"
                                                    >
                                                        <SelectValue placeholder="Choose a Site" />
                                                    </SelectTrigger>
                                                    <SelectContent>
                                                        {can.assignApplicationWide ? (
                                                            <SelectItem
                                                                value={
                                                                    ALL_SITES
                                                                }
                                                            >
                                                                All Sites
                                                            </SelectItem>
                                                        ) : null}
                                                        {siteOptions.map(
                                                            (site) => (
                                                                <SelectItem
                                                                    key={
                                                                        site.id
                                                                    }
                                                                    value={String(
                                                                        site.id,
                                                                    )}
                                                                >
                                                                    {site.name}
                                                                </SelectItem>
                                                            ),
                                                        )}
                                                    </SelectContent>
                                                </Select>
                                                {!ticket.is_organisation_wide &&
                                                ticket.site?.href ? (
                                                    <Link
                                                        href={ticket.site.href}
                                                        className="frontline-focus w-fit rounded-sm text-xs font-medium text-primary hover:underline"
                                                    >
                                                        Open {ticket.site.name}{' '}
                                                        profile
                                                    </Link>
                                                ) : null}
                                            </RailField>
                                            <RailField label="Category">
                                                <Select
                                                    disabled={
                                                        propertyMutation.busy
                                                    }
                                                    value={ticket.category}
                                                    onValueChange={(v) =>
                                                        patch(
                                                            { category: v },
                                                            `Category set to ${label(v)}.`,
                                                        )
                                                    }
                                                >
                                                    <SelectTrigger
                                                        className="h-8"
                                                        aria-label="Category"
                                                    >
                                                        <SelectValue />
                                                    </SelectTrigger>
                                                    <SelectContent>
                                                        {[
                                                            'hardware',
                                                            'account',
                                                            'network',
                                                            'other',
                                                        ].map((c) => (
                                                            <SelectItem
                                                                key={c}
                                                                value={c}
                                                            >
                                                                {label(c)}
                                                            </SelectItem>
                                                        ))}
                                                    </SelectContent>
                                                </Select>
                                            </RailField>
                                            <RailField label="Subcategory">
                                                <div className="flex gap-1.5">
                                                    <Input
                                                        aria-label="Subcategory"
                                                        value={subcategory}
                                                        disabled={
                                                            propertyMutation.busy
                                                        }
                                                        onChange={(e) =>
                                                            setSubcategory(
                                                                e.target.value,
                                                            )
                                                        }
                                                        placeholder="e.g. laptop, VPN…"
                                                        className="h-8"
                                                        maxLength={255}
                                                    />
                                                    {subcategoryDirty ? (
                                                        <Button
                                                            size="sm"
                                                            variant="outline"
                                                            aria-label="Save subcategory"
                                                            disabled={
                                                                propertyMutation.busy
                                                            }
                                                            onClick={() => {
                                                                const captured =
                                                                    patch(
                                                                        {
                                                                            subcategory:
                                                                                subcategory.trim() ||
                                                                                null,
                                                                        },
                                                                        'Subcategory saved.',
                                                                        subcategoryState?.scope ===
                                                                            fieldScope
                                                                            ? subcategoryState.baseVersion
                                                                            : ticket.lock_version,
                                                                    );
                                                                if (captured) {
                                                                    classificationMemory.clearOwnedBrowserWork();
                                                                    setSubcategoryState(
                                                                        null,
                                                                    );
                                                                }
                                                            }}
                                                        >
                                                            <Check className="h-3.5 w-3.5" />
                                                        </Button>
                                                    ) : null}
                                                </div>
                                            </RailField>
                                            {classificationMemory.memoryWarning && (
                                                <p
                                                    role="alert"
                                                    className="text-sm text-status-critical"
                                                >
                                                    {
                                                        classificationMemory.memoryWarning
                                                    }
                                                </p>
                                            )}
                                            <RailField label="Assignee">
                                                <Select
                                                    disabled={
                                                        propertyMutation.busy
                                                    }
                                                    value={
                                                        ticket.assignee
                                                            ? String(
                                                                  ticket
                                                                      .assignee
                                                                      .id,
                                                              )
                                                            : NONE
                                                    }
                                                    onValueChange={(v) =>
                                                        patch(
                                                            {
                                                                assigned_to_user_id:
                                                                    v === NONE
                                                                        ? null
                                                                        : Number(
                                                                              v,
                                                                          ),
                                                            },
                                                            'Assignee updated.',
                                                        )
                                                    }
                                                >
                                                    <SelectTrigger
                                                        className="h-8"
                                                        aria-label="Assignee"
                                                    >
                                                        <SelectValue />
                                                    </SelectTrigger>
                                                    <SelectContent>
                                                        <SelectItem
                                                            value={NONE}
                                                        >
                                                            Unassigned
                                                        </SelectItem>
                                                        {assignees.map((a) => (
                                                            <SelectItem
                                                                key={a.id}
                                                                value={String(
                                                                    a.id,
                                                                )}
                                                            >
                                                                {a.name}
                                                            </SelectItem>
                                                        ))}
                                                    </SelectContent>
                                                </Select>
                                                {ticket.assignee?.href ? (
                                                    <Link
                                                        href={
                                                            ticket.assignee.href
                                                        }
                                                        className="frontline-focus w-fit rounded-sm text-xs font-medium text-primary hover:underline"
                                                    >
                                                        Open{' '}
                                                        {ticket.assignee.name}
                                                        's profile
                                                    </Link>
                                                ) : null}
                                            </RailField>
                                            {ticket.routing ? (
                                                <RailField label="Routed ownership">
                                                    <TicketRoutingSummary
                                                        routing={ticket.routing}
                                                    />
                                                    <Button
                                                        variant="outline"
                                                        className="mt-2 w-full"
                                                        onClick={() =>
                                                            setEditingRouting(
                                                                true,
                                                            )
                                                        }
                                                    >
                                                        Change routing
                                                    </Button>
                                                </RailField>
                                            ) : null}
                                            <RailField label="Linked asset">
                                                <Select
                                                    disabled={
                                                        propertyMutation.busy
                                                    }
                                                    value={
                                                        ticket.asset
                                                            ? String(
                                                                  ticket.asset
                                                                      .id,
                                                              )
                                                            : NONE
                                                    }
                                                    onValueChange={(v) =>
                                                        patch(
                                                            {
                                                                asset_id:
                                                                    v === NONE
                                                                        ? null
                                                                        : Number(
                                                                              v,
                                                                          ),
                                                            },
                                                            'Asset link updated.',
                                                        )
                                                    }
                                                >
                                                    <SelectTrigger
                                                        className="h-8"
                                                        aria-label="Linked asset"
                                                    >
                                                        <SelectValue />
                                                    </SelectTrigger>
                                                    <SelectContent>
                                                        <SelectItem
                                                            value={NONE}
                                                        >
                                                            No linked asset
                                                        </SelectItem>
                                                        {assetOptions.map(
                                                            (a) => (
                                                                <SelectItem
                                                                    key={a.id}
                                                                    value={String(
                                                                        a.id,
                                                                    )}
                                                                >
                                                                    {a.name}
                                                                    {a.tag
                                                                        ? ` (${a.tag})`
                                                                        : ''}
                                                                </SelectItem>
                                                            ),
                                                        )}
                                                    </SelectContent>
                                                </Select>
                                                {ticket.asset?.href ? (
                                                    <Link
                                                        href={ticket.asset.href}
                                                        className="frontline-focus w-fit rounded-sm text-xs font-medium text-primary hover:underline"
                                                    >
                                                        Open {ticket.asset.name}
                                                    </Link>
                                                ) : null}
                                            </RailField>
                                        </>
                                    ) : (
                                        <>
                                            <RailRow
                                                k="Status"
                                                v={
                                                    ticket.status === 'waiting'
                                                        ? waitingStatusLabel(
                                                              ticket.waiting
                                                                  ?.party,
                                                              true,
                                                          )
                                                        : label(ticket.status)
                                                }
                                            />
                                            <RailRow
                                                k="Priority"
                                                v={label(ticket.priority)}
                                            />
                                            <RailRow
                                                k="Work type"
                                                v={label(ticket.work_type)}
                                            />
                                            <RailRow
                                                k="Affected service"
                                                v={
                                                    ticket.service?.name ??
                                                    'Not classified'
                                                }
                                            />
                                            <RailField label="Ticket Site">
                                                {ticket.is_organisation_wide ? (
                                                    <span className="text-[12.5px] font-medium">
                                                        All Sites
                                                    </span>
                                                ) : ticket.site?.href ? (
                                                    <Link
                                                        href={ticket.site.href}
                                                        className="frontline-focus w-fit rounded-sm text-[12.5px] font-medium text-primary hover:underline"
                                                    >
                                                        {ticket.site.name}
                                                    </Link>
                                                ) : ticket.site ? (
                                                    <span className="text-[12.5px] font-medium">
                                                        {ticket.site.name}
                                                    </span>
                                                ) : (
                                                    <span className="text-[12.5px] font-medium">
                                                        Site unavailable
                                                    </span>
                                                )}
                                            </RailField>
                                            <RailRow
                                                k="Category"
                                                v={`${label(ticket.category)}${ticket.subcategory ? ` · ${ticket.subcategory}` : ''}`}
                                            />
                                            <RailAssociationRow
                                                k="Assignee"
                                                v={
                                                    ticket.assignee?.name ??
                                                    'With IT for triage'
                                                }
                                                href={
                                                    ticket.assignee?.href ??
                                                    null
                                                }
                                            />
                                            {can.view && ticket.routing ? (
                                                <RailField label="Routed ownership">
                                                    <TicketRoutingSummary
                                                        routing={ticket.routing}
                                                    />
                                                </RailField>
                                            ) : null}
                                            {ticket.asset ? (
                                                <RailAssociationRow
                                                    k="Linked asset"
                                                    v={`${ticket.asset.name}${ticket.asset.tag ? ` (${ticket.asset.tag})` : ''}`}
                                                    href={ticket.asset.href}
                                                />
                                            ) : null}
                                        </>
                                    )}
                                </div>
                            </section>
                            <section
                                hidden={activeTab !== 'tasks'}
                                className="rounded-2xl border border-border bg-card p-5"
                            >
                                <h2 className="mb-4 text-base font-semibold">
                                    Tasks & evidence
                                </h2>
                                {myId !== null && (
                                    <TicketWorkTasks
                                        key={`${ticket.id}:${myId}:${can.manage}`}
                                        actorId={myId}
                                        ticketId={ticket.id}
                                        version={ticket.lock_version}
                                        canViewWork={canViewWork}
                                        tasks={linked_context.tasks}
                                        canManage={
                                            can.manage &&
                                            WORKING_STATUSES.includes(
                                                ticket.status,
                                            ) &&
                                            ticket.merged_into === null
                                        }
                                        assignees={assignees}
                                        teams={teamOptions}
                                        approvals={approvals}
                                        taskWork={task_work ?? undefined}
                                        onCommitted={deliveryRefresh.refresh}
                                        onAccessLost={deliveryRefresh.refresh}
                                        onSessionExpired={
                                            deliveryRefresh.refresh
                                        }
                                    />
                                )}
                            </section>
                            <section
                                hidden={activeTab !== 'approvals'}
                                className="rounded-2xl border border-border bg-card p-5"
                            >
                                <h2 className="mb-4 text-base font-semibold">
                                    Approvals
                                </h2>
                                {myId !== null &&
                                (ticket.requires_approval ||
                                    approval_work?.total) ? (
                                    <TicketApprovalControls
                                        key={`${ticket.id}:${myId}`}
                                        actorId={myId}
                                        ticket={ticket}
                                        work={
                                            canViewWork ? approval_work : null
                                        }
                                        onCommitted={deliveryRefresh.refresh}
                                        onAccessLost={deliveryRefresh.refresh}
                                        onSessionExpired={
                                            deliveryRefresh.refresh
                                        }
                                    />
                                ) : (
                                    <EmptyState
                                        icon={ShieldCheck}
                                        title="No approval required"
                                        description="This ticket does not currently require an approval."
                                        variant="compact"
                                    />
                                )}
                            </section>
                            <section
                                hidden={activeTab !== 'links'}
                                className="space-y-5 rounded-2xl border border-border bg-card p-5"
                            >
                                <h2 className="text-base font-semibold">
                                    Linked records
                                </h2>
                                <div className="grid gap-4 lg:grid-cols-3">
                                    {ticket.site && (
                                        <RailAssociationRow
                                            k="Site"
                                            v={ticket.site.name}
                                            href={ticket.site.href}
                                        />
                                    )}
                                    {ticket.service && (
                                        <RailRow
                                            k="Affected service"
                                            v={ticket.service.name}
                                        />
                                    )}
                                    {ticket.asset && (
                                        <RailAssociationRow
                                            k="Asset"
                                            v={ticket.asset.name}
                                            href={ticket.asset.href}
                                        />
                                    )}
                                </div>
                                <TicketLinkedContext
                                    ticketId={ticket.id}
                                    canManage={
                                        can.manage &&
                                        WORKING_STATUSES.includes(
                                            ticket.status,
                                        ) &&
                                        [
                                            'incident',
                                            'service_request',
                                            'security_request',
                                        ].includes(ticket.work_type)
                                    }
                                    canLinkDevices={can.linkDevices}
                                    deviceOptions={deviceOptions}
                                    recoveredAt={ticket.monitoring_recovered_at}
                                    devices={linked_context.devices}
                                    assets={linked_context.assets}
                                    alerts={linked_context.alerts}
                                    incidentEvidence={
                                        linked_context.incident_evidence
                                    }
                                    changes={linked_context.changes}
                                    problems={linked_context.problems}
                                    majorIncidents={
                                        linked_context.major_incidents
                                    }
                                />

                                {can.internal && myId !== null && (
                                    <TicketRelatedWork
                                        actorId={myId}
                                        ticketId={ticket.id}
                                        active={activeTab === 'links'}
                                        onChanged={deliveryRefresh.refresh}
                                    />
                                )}

                                {ticket.provisioning_request ? (
                                    <RailField label="Provisioning request">
                                        <div className="flex items-center gap-2 rounded-lg border border-border/60 bg-muted/40 px-2.5 py-1.5 text-[12.5px]">
                                            <Server className="h-3.5 w-3.5 flex-none text-muted-foreground" />
                                            <span className="min-w-0 truncate">
                                                {
                                                    ticket.provisioning_request
                                                        .item
                                                }
                                            </span>
                                            <StatusBadge
                                                variant="neutral"
                                                size="sm"
                                            >
                                                {label(
                                                    ticket.provisioning_request
                                                        .status,
                                                )}
                                            </StatusBadge>
                                        </div>
                                    </RailField>
                                ) : null}
                            </section>
                            <section
                                hidden={activeTab !== 'sla'}
                                className="rounded-2xl border border-border bg-card p-5"
                            >
                                <h2 className="mb-5 text-base font-semibold">
                                    Service levels & record history
                                </h2>
                                <div className="grid items-start gap-5 lg:grid-cols-2">
                                    <SlaEvidence sla={ticket.sla} />
                                    <div>
                                        {' '}
                                        {/* People + stamps */}
                                        <div className="mt-1 border-t border-border/60 pt-3">
                                            <RailAssociationRow
                                                k="Requester"
                                                v={`${ticket.requester.name}${ticket.requester.role ? ` · ${ticket.requester.role}` : ''}`}
                                                href={ticket.requester.href}
                                            />
                                            <RailRow
                                                k="Source"
                                                v={label(ticket.source)}
                                            />
                                            <RailRow
                                                k="Raised"
                                                v={formatDateTime(
                                                    ticket.created_at,
                                                )}
                                            />
                                            <RailRow
                                                k="Updated"
                                                v={formatDateTime(
                                                    ticket.updated_at,
                                                )}
                                            />
                                            {ticket.resolved_at ? (
                                                <RailRow
                                                    k="Resolved"
                                                    v={formatDateTime(
                                                        ticket.resolved_at,
                                                    )}
                                                />
                                            ) : null}
                                            {ticket.closed_at ? (
                                                <RailRow
                                                    k="Closed"
                                                    v={formatDateTime(
                                                        ticket.closed_at,
                                                    )}
                                                />
                                            ) : null}
                                        </div>
                                    </div>
                                </div>
                            </section>
                            <section
                                hidden={activeTab !== 'files'}
                                className="rounded-2xl border border-border bg-card p-5"
                            >
                                <h2 className="text-base font-semibold">
                                    Files
                                </h2>
                                <p className="mt-1 mb-5 text-sm text-muted-foreground">
                                    Attachments from the original report and
                                    replies you can access.
                                </p>
                                {fileEntries.length ? (
                                    <ul className="divide-y divide-border">
                                        {fileEntries.map((file) => (
                                            <li
                                                key={file.id}
                                                className="flex items-center gap-4 py-4"
                                            >
                                                <Paperclip
                                                    className="size-5 shrink-0 text-primary"
                                                    aria-hidden="true"
                                                />
                                                <div className="min-w-0 flex-1">
                                                    <a
                                                        href={file.url}
                                                        target="_blank"
                                                        rel="noreferrer"
                                                        className="frontline-focus rounded-sm text-sm font-semibold break-words text-primary hover:underline"
                                                    >
                                                        {file.name}
                                                        <span className="sr-only">
                                                            {' '}
                                                            (opens in a new tab)
                                                        </span>
                                                    </a>
                                                    <p className="mt-1 text-xs text-muted-foreground">
                                                        {file.source} ·{' '}
                                                        {formatFileSize(
                                                            file.size,
                                                        )}
                                                    </p>
                                                </div>
                                                {file.internal && (
                                                    <StatusBadge variant="warning">
                                                        Internal
                                                    </StatusBadge>
                                                )}
                                            </li>
                                        ))}
                                    </ul>
                                ) : (
                                    <EmptyState
                                        icon={Paperclip}
                                        title="No files attached"
                                        description={
                                            can.comment
                                                ? 'Add evidence using the attachment control when you write a reply.'
                                                : 'This ticket has no attachments you can access.'
                                        }
                                        action={
                                            can.comment ? (
                                                <Button
                                                    variant="outline"
                                                    onClick={focusReply}
                                                >
                                                    Write a reply
                                                </Button>
                                            ) : undefined
                                        }
                                    />
                                )}
                            </section>
                        </div>
                    </div>
                </ItModuleShell>
            </div>
        </AppLayout>
    );
}

function RailField({
    label: l,
    children,
}: {
    label: string;
    children: React.ReactNode;
}) {
    return (
        <div className="flex flex-col gap-1">
            <span className="text-[11px] font-bold tracking-wide text-muted-foreground uppercase">
                {l}
            </span>
            {children}
        </div>
    );
}

function RailRow({ k, v }: { k: string; v: string }) {
    return (
        <div className="flex items-baseline justify-between gap-2 py-1">
            <span className="text-[11px] font-bold tracking-wide text-muted-foreground uppercase">
                {k}
            </span>
            <span className="min-w-0 truncate text-right text-[12.5px] font-medium">
                {v}
            </span>
        </div>
    );
}

function RailAssociationRow({
    k,
    v,
    href,
}: {
    k: string;
    v: string;
    href: string | null;
}) {
    return (
        <div className="flex items-baseline justify-between gap-2 py-1">
            <span className="text-[11px] font-bold tracking-wide text-muted-foreground uppercase">
                {k}
            </span>
            {href ? (
                <Link
                    href={href}
                    className="frontline-focus min-w-0 truncate rounded-sm text-right text-[12.5px] font-medium text-primary hover:underline"
                >
                    {v}
                </Link>
            ) : (
                <span className="min-w-0 truncate text-right text-[12.5px] font-medium">
                    {v}
                </span>
            )}
        </div>
    );
}
