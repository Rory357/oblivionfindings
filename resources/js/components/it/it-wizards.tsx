import { ResolveTicketDialog } from '@/components/it/resolve-ticket-dialog';
/* The IT & Support dialogs — Log ticket (3-step), Fulfil request and
 * Assign owner (single-step). All built on the shared HR wizard kit
 * (WizardShell + primitives) so they are visually identical to the
 * Add-Client / Asset lifecycle modals. Zero confirm(): every action is a
 * reviewed modal ending in a success pane. */
import { router, useForm, usePage } from '@inertiajs/react';
import {
    BookOpen,
    CalendarClock,
    CheckCircle2,
    ClipboardCheck,
    FileText,
    Flag,
    GitMerge,
    KeyRound,
    Laptop,
    Mail,
    RotateCcw,
    Search,
    Server,
    Ticket,
    Timer,
    User,
    UserCheck,
    Users,
    Wifi,
    X,
} from 'lucide-react';
import { useEffect, useId, useMemo, useRef, useState } from 'react';
import { toast } from 'sonner';

import {
    Field,
    FieldErr,
    InfoCard,
    ReviewCard,
    ReviewRow,
    SelectInput,
    StepHead,
    TilePicker,
    useWizard,
    WizardShell,
    WizardStepPane,
    WizardSuccessPane,
    type WizardStep,
} from '@/components/hr/wizard';
import { TicketCommandWizard } from '@/components/it/ticket-command-wizard';
import { TicketDuplicateSuggestions } from '@/components/it/ticket-duplicate-suggestions';
import {
    TicketDraftFiles,
    TicketIntakeDraftPanel,
} from '@/components/it/ticket-intake-draft';
import {
    TICKET_IMPACT_OPTIONS,
    TICKET_URGENCY_OPTIONS,
    TicketImpactUrgencyFields,
    TicketSiteField,
    type TicketImpact,
    type TicketIntakePolicy,
    type TicketPriority,
    type TicketUrgency,
} from '@/components/it/ticket-intake-fields';
import type { TicketRoutingDetails } from '@/components/it/ticket-routing-summary';
import { TicketVersionConflict } from '@/components/it/ticket-version-conflict';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { FileDropzone, StagedFileCard } from '@/components/ui/file-dropzone';
import { Input } from '@/components/ui/input';
import { StatusBadge } from '@/components/ui/status-badge';
import { Textarea } from '@/components/ui/textarea';
import { preferredIntakeDraft } from '@/hooks/it-intake-draft-locator';
import type { ItDraftResumed } from '@/hooks/it-ticket-draft-contract';
import {
    intakeDraftSnapshot,
    restoreIntakeDraft,
    useItIntakeDraft,
} from '@/hooks/use-it-intake-draft';
import { useItTicketCommand } from '@/hooks/use-it-ticket-command';
import type { ItDraftBrowserRestored } from '@/hooks/use-it-ticket-draft';
import { itIntakeDraftMemoryRequestIds } from '@/hooks/use-it-ticket-draft-memory';
import {
    IT_ATTACHMENT_ACCEPT,
    itAttachmentSelectionError,
} from '@/lib/it-attachments';
import type { SharedData } from '@/types';

/* ------------------------------------------------------------------ */
/*  Shared types                                                       */
/* ------------------------------------------------------------------ */

export interface AssigneeOption {
    id: number;
    name: string;
    /** Present on canonical assignment options; other identity projections may omit it. */
    site_ids?: number[];
    organisation_wide?: boolean;
}

/** An entry from the canonical assets register, for the ticket asset-link picker. */
export interface AssetOption {
    id: number;
    name: string;
    tag: string | null;
}

/** An active approved Site for explicit ticket scope. */
export interface SiteOption {
    id: number;
    name: string;
}

/** A permission-safe canonical Security & Devices record for ticket context. */
export interface DeviceOption {
    id: number;
    name: string;
    uid: string;
    site_id: number | null;
}

/** An active service from the canonical IT service catalogue. */
export interface ServiceOption {
    id: number;
    name: string;
}

export interface RequestRow {
    id: number;
    employee: { name: string; role: string | null };
    item: string;
    type: string;
    status: string;
    priority: string;
    due_date: string | null;
    assignee: AssigneeOption | null;
    task_key: string | null;
    action: string | null;
    category: string | null;
    responsible_team: AssigneeOption | null;
    stage: number | null;
    dependency_request_ids: number[];
    approval_required: boolean;
    approval_status: string | null;
    approver: AssigneeOption | null;
    evidence_required: boolean;
    evidence_summary: string | null;
    failure_reason: string | null;
    fulfiller_context: Record<string, unknown>;
    workflow: {
        id: number;
        lifecycle_type: string;
        status: string;
        source_type: string;
        effective_at: string | null;
    } | null;
    external_ref: string | null;
    notes: string | null;
    from_onboarding: boolean;
    sign_off_required: boolean;
    created: string | null;
    fulfilled: string | null;
    /** Most recent ticket raised off this request (§H convert/link). */
    linked_ticket: { id: number; reference: string | null } | null;
    linked_ticket_count: number;
}

export interface TicketRow {
    conversation?: import('./ticket-conversation-summary').TicketConversation;
    sla?: import('@/components/it/sla-evidence').SlaVerdict;
    id: number;
    can: { manage: boolean };
    lock_version: number;
    reference: string | null;
    title: string;
    description: string | null;
    work_type: string;
    service: ServiceOption | null;
    category: string;
    priority: string;
    status: string;
    waiting_party:
        | 'requester'
        | 'vendor'
        | 'approver'
        | 'team'
        | 'change'
        | 'other'
        | null;
    waiting_reason?: string | null;
    next_action?: string | null;
    waiting_since?: string | null;
    sla_state: string;
    first_response_due_at: string | null;
    resolution_due_at: string | null;
    first_responded_at: string | null;
    requester: string;
    assignee: AssigneeOption | null;
    routing?: TicketRoutingDetails;
    age: string | null;
    updated: string | null;
    resolved: string | null;
}

/** One priority's effective SLA targets, as served by the policy editor payload. */
export interface SlaPolicyRow {
    first_response_minutes: number;
    resolution_minutes: number;
    is_custom: boolean;
}

export type SlaPolicyGrid = Record<string, SlaPolicyRow>;

/** The application's business-hours calendar, flattened to the editor's single-window view. */
export interface SlaCalendar {
    enabled: boolean;
    open_time: string;
    close_time: string;
    working_days: string[];
    holiday_dates: string[];
}

/** An access-approved employee profile for the manual provisioning-request picker. */
export interface EmployeeOption {
    id: number;
    name: string;
    /** HR profile identity stays id; ticket intake requires the approved user. */
    requester?: { user_id: number; site_ids: number[] } | null;
}

/** A knowledge-base article row for the agent Knowledge tab (§I). */
export interface KbRow {
    id: number;
    can: { manage: boolean; author: boolean; review: boolean };
    title: string;
    slug: string;
    category: string;
    status: string;
    audience: string;
    site_scope: number[];
    body: string | null;
    views: number;
    helpful_yes: number;
    helpful_no: number;
    helpful_percent: number | null;
    deflections: number;
    author: string | null;
    owner_user_id: number | null;
    owner: string | null;
    related_service_id: number | null;
    related_service: string | null;
    review_due_at: string | null;
    review_started_at: string | null;
    published_at: string | null;
    retired_at: string | null;
    updated: string | null;
}

export interface KbOptions {
    owners: Array<{ id: number; name: string }>;
    sites: Array<{ id: number; name: string }>;
    services: Array<{ id: number; name: string }>;
}

/** A published-article suggestion for raise-time deflection (§I). */
export interface KbSuggestion {
    id: number;
    title: string;
    category: string;
}

export type ItModal =
    | { type: 'ticket'; provisioning?: { id: number; item: string } }
    | { type: 'raise' }
    | {
          type: 'resolve';
          ticket: {
              id: number;
              lock_version: number;
              reference: string | null;
              title: string;
          };
      }
    | { type: 'fulfil'; request: RequestRow }
    | { type: 'fail-request'; request: RequestRow }
    | { type: 'assign-request'; request: RequestRow }
    | { type: 'assign-ticket'; ticket: TicketRow }
    | { type: 'new-request' }
    | { type: 'kb'; article?: KbRow; draft?: KbDraft }
    | { type: 'sla' };

/** Pre-fill for a NEW KB article (e.g. drafted from a resolution note). */
export interface KbDraft {
    title?: string;
    body?: string;
    category?: string;
}

/** Flash error carried by an Inertia redirect (validation / logic-guard). Read
 *  from the page passed to onSuccess — `back()->with('error')` fires onSuccess,
 *  not onError (see reference_inertia_flash_error). */
function pageFlashError(page: {
    props: Record<string, unknown>;
}): string | null {
    const flash = page.props.flash as { error?: string } | undefined;
    return flash?.error ?? null;
}

/** Sentinel — Radix <SelectItem value=""> crashes at runtime. */
const UNASSIGNED = 'unassigned';

/* ================================================================== */
/*  Dispatcher                                                        */
/* ================================================================== */

export function ItWizard({
    modal,
    assignees,
    employeeOptions = [],
    assetOptions = [],
    siteOptions = [],
    deviceOptions = [],
    serviceOptions = [],
    intakePolicy,
    slaPolicies,
    slaCalendar,
    kbSuggestions = [],
    kbOptions = { owners: [], sites: [], services: [] },
    onOpenArticle,
    onDraftKb,
    onClose,
}: {
    modal: ItModal | null;
    assignees: AssigneeOption[];
    employeeOptions?: EmployeeOption[];
    assetOptions?: AssetOption[];
    siteOptions?: SiteOption[];
    deviceOptions?: DeviceOption[];
    serviceOptions?: ServiceOption[];
    intakePolicy?: TicketIntakePolicy;
    slaPolicies?: SlaPolicyGrid | null;
    slaCalendar?: SlaCalendar | null;
    kbSuggestions?: KbSuggestion[];
    kbOptions?: KbOptions;
    onOpenArticle?: (id: number) => void;
    onDraftKb?: (draft: KbDraft) => void;
    onClose: () => void;
}) {
    const page = usePage<
        SharedData & { draftRecovery?: { enabled: boolean } }
    >();
    const actorId = page.props.auth.user.id;
    const draftRecoveryEnabled = page.props.draftRecovery?.enabled === true;
    if (!modal) return null;
    switch (modal.type) {
        case 'ticket':
            return (
                <CreateTicketWizard
                    key={actorId}
                    actorId={actorId}
                    draftRecoveryEnabled={draftRecoveryEnabled}
                    assignees={assignees}
                    employeeOptions={employeeOptions}
                    assetOptions={assetOptions}
                    siteOptions={siteOptions}
                    deviceOptions={deviceOptions}
                    serviceOptions={serviceOptions}
                    intakePolicy={intakePolicy}
                    slaPolicies={slaPolicies}
                    slaCalendar={slaCalendar}
                    provisioning={modal.provisioning}
                    onClose={onClose}
                />
            );
        case 'new-request':
            return (
                <NewProvisioningRequestDialog
                    employeeOptions={employeeOptions}
                    assignees={assignees}
                    onClose={onClose}
                />
            );
        case 'kb':
            return (
                <KbArticleDialog
                    article={modal.article}
                    draft={modal.draft}
                    options={kbOptions}
                    onClose={onClose}
                />
            );
        case 'raise':
            return (
                <RaiseTicketDialog
                    key={actorId}
                    actorId={actorId}
                    draftRecoveryEnabled={draftRecoveryEnabled}
                    siteOptions={siteOptions}
                    kbSuggestions={kbSuggestions}
                    onOpenArticle={onOpenArticle}
                    onClose={onClose}
                />
            );
        case 'sla':
            return slaPolicies ? (
                <SlaPolicyDialog
                    policies={slaPolicies}
                    calendar={slaCalendar}
                    onClose={onClose}
                />
            ) : null;
        case 'resolve':
            return (
                <ResolveTicketDialog
                    ticket={modal.ticket}
                    onDraftKb={onDraftKb}
                    onClose={onClose}
                />
            );
        case 'fulfil':
            return (
                <FulfilRequestDialog
                    request={modal.request}
                    onClose={onClose}
                />
            );
        case 'fail-request':
            return (
                <FailRequestDialog request={modal.request} onClose={onClose} />
            );
        case 'assign-request':
            return (
                <AssignDialog
                    heading="Assign request"
                    subject={modal.request.item}
                    currentId={modal.request.assignee?.id ?? null}
                    endpoint={{
                        method: 'post',
                        url: `/it/provisioning/${modal.request.id}/assign`,
                        field: 'assigned_to_user_id',
                    }}
                    assignees={assignees}
                    onClose={onClose}
                />
            );
        case 'assign-ticket':
            return (
                <AssignDialog
                    heading="Assign ticket"
                    subject={modal.ticket.title}
                    currentId={modal.ticket.assignee?.id ?? null}
                    endpoint={{
                        method: 'patch',
                        url: `/it/tickets/${modal.ticket.id}`,
                        field: 'assigned_to_user_id',
                        ticketId: modal.ticket.id,
                        expectedVersion: modal.ticket.lock_version,
                    }}
                    assignees={assignees}
                    onClose={onClose}
                />
            );
    }
}

/* ================================================================== */
/*  Log ticket (3 steps)                                              */
/* ================================================================== */

const TICKET_STEPS: readonly WizardStep[] = [
    { key: 'details', label: 'Details', blurb: 'What & where', icon: FileText },
    { key: 'triage', label: 'Triage', blurb: 'Priority & owner', icon: Flag },
    {
        key: 'review',
        label: 'Review',
        blurb: 'Confirm & log',
        icon: CheckCircle2,
    },
];

const CATEGORY_OPTIONS = [
    {
        key: 'hardware',
        label: 'Hardware',
        description: 'Laptops, phones, printers & devices',
        icon: Laptop,
    },
    {
        key: 'account',
        label: 'Account',
        description: 'Logins, email & software access',
        icon: Mail,
    },
    {
        key: 'network',
        label: 'Network',
        description: 'Wi-Fi, VPN & connectivity',
        icon: Wifi,
    },
    {
        key: 'other',
        label: 'Other',
        description: 'Anything else IT should look at',
        icon: Server,
    },
] as const;

const PRIORITY_OPTIONS = [
    { key: 'low', label: 'Low', description: 'When time allows' },
    { key: 'normal', label: 'Normal', description: 'Business as usual' },
    { key: 'high', label: 'High', description: 'Blocking someone’s work' },
    {
        key: 'urgent',
        label: 'Urgent',
        description: 'Site-wide / safety impact',
    },
] as const;

const INTAKE_WORK_TYPE_OPTIONS = [
    {
        key: 'incident',
        label: 'Incident',
        description: 'Something is broken or degraded',
        icon: Ticket,
    },
    {
        key: 'service_request',
        label: 'Service request',
        description: 'Access, equipment or standard help',
        icon: ClipboardCheck,
    },
    {
        key: 'security_request',
        label: 'Security request',
        description: 'A security concern or sensitive access',
        icon: KeyRound,
    },
] as const;

function CreateTicketWizard({
    actorId,
    draftRecoveryEnabled,
    assignees,
    employeeOptions,
    assetOptions,
    siteOptions,
    deviceOptions,
    serviceOptions,
    intakePolicy,
    slaPolicies,
    slaCalendar,
    provisioning,
    onClose,
}: {
    actorId: number;
    draftRecoveryEnabled: boolean;
    assignees: AssigneeOption[];
    employeeOptions: EmployeeOption[];
    assetOptions: AssetOption[];
    siteOptions: SiteOption[];
    deviceOptions: DeviceOption[];
    serviceOptions: ServiceOption[];
    intakePolicy?: TicketIntakePolicy;
    slaPolicies?: SlaPolicyGrid | null;
    slaCalendar?: SlaCalendar | null;
    provisioning?: { id: number; item: string };
    onClose: () => void;
}) {
    const wizard = useWizard(TICKET_STEPS.length);
    const attachmentId = useId();
    const command = useItTicketCommand({
        actorId,
        draftRequestId:
            itIntakeDraftMemoryRequestIds(actorId, 'technician_intake')[0] ??
            preferredIntakeDraft(
                draftRecoveryEnabled,
                actorId,
                'technician_intake',
            ),
    });
    const created = command.result;
    const done = created !== null;
    const finishClose = () => {
        onClose();
        if (created) router.reload();
    };

    const form = useForm<{
        title: string;
        description: string;
        category: string;
        subcategory: string;
        priority: TicketPriority | 'automatic';
        impact: TicketImpact;
        urgency: TicketUrgency;
        priority_reason: string;
        routing_reason: string;
        work_type: string;
        it_service_id: string;
        requester_user_id: string;
        assigned_to_user_id: string;
        asset_id: string;
        site_id: string;
        device_id: string;
        watchers: number[];
        provisioning_request_id: number | null;
        attachments: File[];
    }>({
        title: provisioning ? `Issue with ${provisioning.item}` : '',
        description: '',
        category: 'hardware',
        subcategory: '',
        priority: 'automatic',
        impact: 'individual',
        urgency: 'normal',
        priority_reason: '',
        routing_reason: '',
        work_type: 'incident',
        it_service_id: UNASSIGNED,
        requester_user_id: UNASSIGNED,
        assigned_to_user_id: UNASSIGNED,
        asset_id: UNASSIGNED,
        site_id:
            siteOptions.length === 1 ? String(siteOptions[0].id) : UNASSIGNED,
        device_id: UNASSIGNED,
        watchers: [],
        provisioning_request_id: provisioning?.id ?? null,
        attachments: [],
    });

    const initialValues = useRef(form.data);
    const savedSnapshot = intakeDraftSnapshot(
        'technician_intake',
        form.data,
        wizard.index,
    );
    const intake = useItIntakeDraft({
        enabled: draftRecoveryEnabled,
        actorId,
        purpose: 'technician_intake',
        command,
        snapshot: savedSnapshot,
        dirty: form.isDirty,
        files: form.data.attachments,
    });
    const resumeBrowserWork = (restored: ItDraftBrowserRestored) => {
        form.setData({
            ...restoreIntakeDraft(initialValues.current, restored.snapshot),
            attachments: restored.files,
        });
        form.clearErrors();
        wizard.goTo(restored.snapshot.step_index);
    };
    const resumeDraft = (saved: ItDraftResumed) => {
        form.setData({
            ...restoreIntakeDraft(initialValues.current, saved.payload),
            attachments: [],
        });
        form.clearErrors();
        wizard.goTo(saved.payload.step_index);
    };
    const resetDraftFields = () => {
        form.reset();
        form.clearErrors('attachments');
        wizard.goTo(0);
    };
    const { setData, setDefaults, clearErrors } = form;
    useEffect(() => {
        if (
            command.state !== 'access_denied' &&
            intake.draft.state !== 'access_denied'
        )
            return;
        const cleared = {
            title: '',
            description: '',
            category: 'hardware',
            subcategory: '',
            priority: 'automatic' as const,
            impact: 'individual' as const,
            urgency: 'normal' as const,
            priority_reason: '',
            routing_reason: '',
            work_type: 'incident',
            it_service_id: UNASSIGNED,
            requester_user_id: UNASSIGNED,
            assigned_to_user_id: UNASSIGNED,
            asset_id: UNASSIGNED,
            site_id: UNASSIGNED,
            device_id: UNASSIGNED,
            watchers: [],
            provisioning_request_id: null,
            attachments: [],
        };
        setData(cleared);
        setDefaults(cleared);
        clearErrors();
    }, [command.state, intake.draft.state, setData, setDefaults, clearErrors]);
    const eligibleAssignees = assignees.filter((candidate) =>
        candidate.site_ids?.includes(Number(form.data.site_id)),
    );
    const eligibleRequesters = employeeOptions.filter((candidate) =>
        candidate.requester?.site_ids.includes(Number(form.data.site_id)),
    );
    const requester = eligibleRequesters.find(
        (candidate) =>
            String(candidate.requester?.user_id) ===
            form.data.requester_user_id,
    );
    const requesterValid =
        form.data.requester_user_id === UNASSIGNED || requester !== undefined;
    const assignee =
        eligibleAssignees.find(
            (a) => String(a.id) === form.data.assigned_to_user_id,
        ) ?? null;
    const asset =
        assetOptions.find((a) => String(a.id) === form.data.asset_id) ?? null;
    const site =
        siteOptions.find((s) => String(s.id) === form.data.site_id) ?? null;
    const availableDevices = deviceOptions.filter(
        (candidate) =>
            form.data.site_id !== UNASSIGNED &&
            candidate.site_id === Number(form.data.site_id),
    );
    const device =
        availableDevices.find(
            (candidate) => String(candidate.id) === form.data.device_id,
        ) ?? null;
    const service =
        serviceOptions.find((s) => String(s.id) === form.data.it_service_id) ??
        null;
    const requesterName =
        form.data.requester_user_id === UNASSIGNED
            ? 'Me (myself)'
            : requester?.name;
    const detailsValid =
        form.data.title.trim().length > 0 &&
        requesterValid &&
        siteOptions.some((site) => String(site.id) === form.data.site_id);
    const attachmentCount = draftRecoveryEnabled
        ? intake.draft.attachments.length
        : form.data.attachments.length;
    const hasPriorityOverride = form.data.priority !== 'automatic';
    const triageValid =
        (!hasPriorityOverride || form.data.priority_reason.trim().length > 0) &&
        (form.data.assigned_to_user_id === UNASSIGNED ||
            (assignee !== null && form.data.routing_reason.trim().length > 0));
    const assessedPriority =
        intakePolicy?.priority_matrix[form.data.impact]?.[form.data.urgency];
    const effectivePriority =
        form.data.priority === 'automatic'
            ? assessedPriority
            : form.data.priority;

    // Targets describe the configured policy; the server records the calendar
    // and calculates actual deadlines when the ticket is saved.
    const slaTarget = effectivePriority
        ? (slaPolicies?.[effectivePriority] ?? null)
        : null;
    const targetLabel = (mins: number) =>
        `${mins.toLocaleString('en-NZ')} ${slaCalendar?.enabled ? 'working minutes' : 'minutes'}`;

    const submit = () => {
        const data = form.data;
        void intake.submit({
            ...data,
            priority: data.priority === 'automatic' ? null : data.priority,
            priority_reason: hasPriorityOverride ? data.priority_reason : null,
            routing_reason:
                data.assigned_to_user_id === UNASSIGNED
                    ? null
                    : data.routing_reason,
            requester_user_id:
                data.requester_user_id === UNASSIGNED
                    ? null
                    : Number(data.requester_user_id),
            assigned_to_user_id:
                data.assigned_to_user_id === UNASSIGNED
                    ? null
                    : Number(data.assigned_to_user_id),
            asset_id:
                data.asset_id === UNASSIGNED ? null : Number(data.asset_id),
            site_id: Number(data.site_id),
            device_id:
                data.device_id === UNASSIGNED ? null : Number(data.device_id),
            it_service_id:
                data.it_service_id === UNASSIGNED
                    ? null
                    : Number(data.it_service_id),
            subcategory:
                data.subcategory.trim() === '' ? null : data.subcategory,
        });
    };

    const logAnother = () => {
        form.reset();
        form.clearErrors('attachments');
        command.reset('new');
        wizard.goTo(0);
    };

    const addWatcher = (v: string) => {
        if (v === UNASSIGNED) return;
        const id = Number(v);
        if (!form.data.watchers.includes(id)) {
            form.setData('watchers', [...form.data.watchers, id]);
        }
    };

    return (
        <TicketCommandWizard
            command={command}
            dirty={intake.dirty}
            onDiscardBrowserWork={() => {
                intake.draft.clearOwnedBrowserWork();
                resetDraftFields();
            }}
            draftExit={
                draftRecoveryEnabled
                    ? {
                          busy: intake.draft.busy,
                          canSave:
                              intake.draft.state === 'ready' &&
                              intake.draft.draft?.capabilities.save === true,
                          canDiscard:
                              ['ready', 'available'].includes(
                                  intake.draft.state,
                              ) &&
                              intake.draft.draft?.capabilities.discard === true,
                          save: intake.saveForClose,
                          discard: intake.discardForClose,
                          keepReference: intake.draft.draft
                              ? intake.keepReference
                              : undefined,
                      }
                    : undefined
            }
            open
            onClose={finishClose}
            title="Log & triage ticket"
            description="Log a helpdesk ticket on behalf of a colleague and triage it in one pass."
            railIcon={Ticket}
            railTitle="Log & triage"
            railSub="IT helpdesk"
            steps={TICKET_STEPS}
            stepIndex={wizard.index}
            onStepClick={wizard.goTo}
            pct={Math.round(
                ([
                    form.data.title.trim().length > 0,
                    form.data.site_id !== UNASSIGNED,
                    !!form.data.category,
                    !!form.data.impact && !!form.data.urgency,
                    triageValid,
                ].filter(Boolean).length /
                    5) *
                    100,
            )}
            success={
                done ? (
                    <WizardSuccessPane
                        title={
                            created?.reference
                                ? `Logged — ${created.reference}`
                                : 'Ticket logged'
                        }
                        blurb={
                            command.restoredFromReference ? (
                                <>
                                    Your saved request was found. Open it to
                                    review its details and current owner.
                                </>
                            ) : (
                                <>
                                    “{form.data.title}” is now in the helpdesk
                                    queue
                                    {assignee ? (
                                        <> with {assignee.name}</>
                                    ) : null}
                                    .
                                </>
                            )
                        }
                        actions={
                            <>
                                {created ? (
                                    <Button
                                        onClick={() =>
                                            router.visit(
                                                `/it/tickets/${created.id}`,
                                            )
                                        }
                                    >
                                        Open {created.reference ?? 'ticket'}
                                    </Button>
                                ) : null}
                                <Button variant="outline" onClick={logAnother}>
                                    Log another
                                </Button>
                                <Button variant="ghost" onClick={finishClose}>
                                    Done
                                </Button>
                            </>
                        }
                    />
                ) : undefined
            }
            footerStart={
                wizard.isFirst ? null : (
                    <Button variant="outline" onClick={wizard.back}>
                        Back
                    </Button>
                )
            }
            footerEnd={
                <>
                    {wizard.isLast ? (
                        <Button
                            onClick={submit}
                            disabled={
                                !intake.canSubmit ||
                                !detailsValid ||
                                !triageValid
                            }
                        >
                            {command.isBusy || intake.preparing
                                ? 'Logging…'
                                : 'Log ticket'}
                        </Button>
                    ) : (
                        <Button
                            onClick={wizard.next}
                            disabled={
                                !command.canEdit ||
                                (wizard.index === 0 && !detailsValid) ||
                                (wizard.index === 1 && !triageValid)
                            }
                        >
                            Continue
                        </Button>
                    )}
                </>
            }
        >
            <TicketIntakeDraftPanel
                intake={intake}
                command={command}
                snapshot={savedSnapshot}
                dirty={form.isDirty}
                onResume={resumeDraft}
                onResumeMemory={resumeBrowserWork}
                onDiscarded={resetDraftFields}
                onStartNew={resetDraftFields}
                optionLabels={{
                    site_id: Object.fromEntries(
                        siteOptions.map((row) => [row.id, row.name]),
                    ),
                    assigned_to_user_id: Object.fromEntries(
                        assignees.map((row) => [row.id, row.name]),
                    ),
                    requester_user_id: Object.fromEntries(
                        eligibleRequesters.map((row) => [
                            row.requester!.user_id,
                            row.name,
                        ]),
                    ),
                    it_service_id: Object.fromEntries(
                        serviceOptions.map((row) => [row.id, row.name]),
                    ),
                    asset_id: Object.fromEntries(
                        assetOptions.map((row) => [row.id, row.name]),
                    ),
                }}
            />
            {wizard.index === 0 && (
                <WizardStepPane>
                    <StepHead
                        icon={FileText}
                        title="What’s the issue?"
                        blurb="Log it for the person who hit it, with any detail IT needs to act."
                    />
                    {form.data.provisioning_request_id !== null &&
                    command.state !== 'access_denied' ? (
                        <InfoCard icon={Server}>
                            {provisioning?.id ===
                            form.data.provisioning_request_id
                                ? `Linked to provisioning request — “${provisioning.item}”.`
                                : `Linked to provisioning request #${form.data.provisioning_request_id}.`}{' '}
                            The ticket will show on that request too.
                        </InfoCard>
                    ) : null}
                    <div className="grid grid-cols-1 gap-3.5">
                        {employeeOptions.length > 0 ||
                        form.data.requester_user_id !== UNASSIGNED ? (
                            <Field
                                label="Requester"
                                hint="who hit the problem"
                                error={
                                    command.fieldErrors.requester_user_id ||
                                    (!requesterValid
                                        ? 'The selected requester is unavailable for this Site. Choose an eligible employee or log for yourself.'
                                        : undefined)
                                }
                            >
                                <SelectInput
                                    value={form.data.requester_user_id}
                                    onChange={(v) =>
                                        form.setData('requester_user_id', v)
                                    }
                                    placeholder="Me (myself)"
                                    ariaLabel="Requester"
                                    options={[
                                        {
                                            value: UNASSIGNED,
                                            label: 'Me — logging for myself',
                                        },
                                        ...(!requesterValid
                                            ? [
                                                  {
                                                      value: form.data
                                                          .requester_user_id,
                                                      label: 'Selected requester unavailable for this Site',
                                                  },
                                              ]
                                            : []),
                                        ...eligibleRequesters.map((a) => ({
                                            value: String(a.requester!.user_id),
                                            label: a.name,
                                        })),
                                    ]}
                                />
                            </Field>
                        ) : null}
                        <TicketSiteField
                            sites={siteOptions}
                            error={command.fieldErrors.site_id}
                            value={form.data.site_id}
                            onChange={(v) => {
                                const selectedDevice = deviceOptions.find(
                                    (candidate) =>
                                        String(candidate.id) ===
                                        form.data.device_id,
                                );
                                const keepsAssignee = assignees
                                    .find(
                                        (candidate) =>
                                            String(candidate.id) ===
                                            form.data.assigned_to_user_id,
                                    )
                                    ?.site_ids?.includes(Number(v));
                                form.setData({
                                    ...form.data,
                                    site_id: v,
                                    assigned_to_user_id: keepsAssignee
                                        ? form.data.assigned_to_user_id
                                        : UNASSIGNED,
                                    routing_reason: keepsAssignee
                                        ? form.data.routing_reason
                                        : '',
                                    device_id:
                                        selectedDevice?.site_id === Number(v)
                                            ? form.data.device_id
                                            : UNASSIGNED,
                                });
                            }}
                        />
                        <Field
                            label="Title"
                            required
                            error={command.fieldErrors.title}
                        >
                            <Input
                                value={form.data.title}
                                onChange={(e) =>
                                    form.setData('title', e.target.value)
                                }
                                placeholder="e.g. Printer offline — Sunnyside Lodge"
                                maxLength={255}
                            />
                        </Field>
                        <Field
                            label="Detail"
                            hint="optional"
                            error={command.fieldErrors.description}
                        >
                            <Textarea
                                value={form.data.description}
                                onChange={(e) =>
                                    form.setData('description', e.target.value)
                                }
                                placeholder="Anything that helps IT reproduce or locate the issue…"
                                rows={4}
                            />
                        </Field>
                        <Field
                            label="Category"
                            error={command.fieldErrors.category}
                        >
                            <TilePicker
                                value={form.data.category}
                                onChange={(v) => form.setData('category', v)}
                                options={[...CATEGORY_OPTIONS]}
                            />
                        </Field>
                        <Field
                            label="Subcategory"
                            hint="optional"
                            error={command.fieldErrors.subcategory}
                        >
                            <Input
                                value={form.data.subcategory}
                                onChange={(e) =>
                                    form.setData('subcategory', e.target.value)
                                }
                                placeholder="e.g. Label printer, VPN, mailbox…"
                                maxLength={255}
                            />
                        </Field>
                        <Field
                            label="Photos or files"
                            hint="optional"
                            htmlFor={
                                !draftRecoveryEnabled
                                    ? `${attachmentId}-input`
                                    : undefined
                            }
                            labelId={
                                !draftRecoveryEnabled
                                    ? `${attachmentId}-label`
                                    : undefined
                            }
                            errorId={
                                !draftRecoveryEnabled
                                    ? `${attachmentId}-error`
                                    : undefined
                            }
                            error={
                                form.errors.attachments ??
                                command.fieldErrors.attachments
                            }
                        >
                            {draftRecoveryEnabled ? (
                                <TicketDraftFiles
                                    draft={intake.draft}
                                    disabled={!command.canEdit}
                                />
                            ) : (
                                <>
                                    <FileDropzone
                                        id={attachmentId}
                                        aria-labelledby={`${attachmentId}-label ${attachmentId}-title`}
                                        aria-describedby={
                                            (form.errors.attachments ??
                                            command.fieldErrors.attachments)
                                                ? `${attachmentId}-error`
                                                : undefined
                                        }
                                        aria-invalid={Boolean(
                                            form.errors.attachments ??
                                            command.fieldErrors.attachments,
                                        )}
                                        disabled={!command.canEdit}
                                        onFiles={(files) => {
                                            if (!command.canEdit) return;
                                            const error =
                                                itAttachmentSelectionError(
                                                    files,
                                                    form.data.attachments
                                                        .length,
                                                );
                                            if (error) {
                                                form.setError(
                                                    'attachments',
                                                    error,
                                                );
                                                return;
                                            }
                                            form.clearErrors('attachments');
                                            form.setData('attachments', [
                                                ...form.data.attachments,
                                                ...files,
                                            ]);
                                        }}
                                        accept={IT_ATTACHMENT_ACCEPT}
                                        title="Drop a photo or file"
                                        hint="Images, PDF or documents — up to 5 files"
                                    />
                                    {form.data.attachments.map((file, i) => (
                                        <StagedFileCard
                                            key={`${file.name}-${i}`}
                                            file={file}
                                            onRemove={() => {
                                                form.clearErrors('attachments');
                                                form.setData(
                                                    'attachments',
                                                    form.data.attachments.filter(
                                                        (_, j) => j !== i,
                                                    ),
                                                );
                                            }}
                                        />
                                    ))}
                                </>
                            )}
                        </Field>
                    </div>
                </WizardStepPane>
            )}

            {wizard.index === 1 && (
                <WizardStepPane>
                    <StepHead
                        icon={Flag}
                        title="Priority & owner"
                        blurb="How urgent is it, who picks it up, and what’s it about?"
                    />
                    <div className="grid grid-cols-1 gap-3.5">
                        <Field
                            label="Work type"
                            hint="required — controls the support workflow"
                            error={command.fieldErrors.work_type}
                        >
                            <TilePicker
                                value={form.data.work_type}
                                onChange={(v) => form.setData('work_type', v)}
                                options={[...INTAKE_WORK_TYPE_OPTIONS]}
                            />
                        </Field>
                        <Field
                            label="Affected service"
                            hint="optional — helps route the ticket to the right queue"
                            error={command.fieldErrors.it_service_id}
                        >
                            <SelectInput
                                value={form.data.it_service_id}
                                onChange={(v) =>
                                    form.setData('it_service_id', v)
                                }
                                placeholder="No service selected"
                                options={[
                                    {
                                        value: UNASSIGNED,
                                        label: 'No service selected',
                                    },
                                    ...serviceOptions.map((serviceOption) => ({
                                        value: String(serviceOption.id),
                                        label: serviceOption.name,
                                    })),
                                ]}
                            />
                        </Field>
                        <TicketImpactUrgencyFields
                            impact={form.data.impact}
                            urgency={form.data.urgency}
                            onImpactChange={(value) =>
                                form.setData('impact', value)
                            }
                            onUrgencyChange={(value) =>
                                form.setData('urgency', value)
                            }
                            errors={command.fieldErrors}
                        />
                        <InfoCard icon={Flag}>
                            {assessedPriority ? (
                                <>
                                    Assessed priority:{' '}
                                    <strong>
                                        {
                                            PRIORITY_OPTIONS.find(
                                                (option) =>
                                                    option.key ===
                                                    assessedPriority,
                                            )?.label
                                        }
                                    </strong>
                                    . This follows the current impact and
                                    urgency policy.
                                </>
                            ) : (
                                'The server will assess priority from impact and urgency when this ticket is saved. A preview is unavailable.'
                            )}
                        </InfoCard>
                        <Field
                            label="Priority decision"
                            error={command.fieldErrors.priority}
                        >
                            <SelectInput
                                value={form.data.priority}
                                ariaLabel="Priority decision"
                                onChange={(value) =>
                                    form.setData({
                                        ...form.data,
                                        priority: value as
                                            | TicketPriority
                                            | 'automatic',
                                        priority_reason:
                                            value === 'automatic'
                                                ? ''
                                                : form.data.priority_reason,
                                    })
                                }
                                placeholder="Use assessed priority"
                                options={[
                                    {
                                        value: 'automatic',
                                        label: 'Use assessed priority',
                                    },
                                    ...PRIORITY_OPTIONS.map((option) => ({
                                        value: option.key,
                                        label: `Set ${option.label.toLowerCase()} priority`,
                                    })),
                                ]}
                            />
                        </Field>
                        {hasPriorityOverride && (
                            <Field
                                label="Why change the assessed priority?"
                                required
                                error={command.fieldErrors.priority_reason}
                            >
                                <Textarea
                                    value={form.data.priority_reason}
                                    onChange={(event) =>
                                        form.setData(
                                            'priority_reason',
                                            event.target.value,
                                        )
                                    }
                                    maxLength={1000}
                                    rows={3}
                                    placeholder="Explain the operational reason for this priority."
                                />
                            </Field>
                        )}
                        {slaTarget ? (
                            <InfoCard icon={Timer}>
                                First response target:{' '}
                                <strong>
                                    {targetLabel(
                                        slaTarget.first_response_minutes,
                                    )}
                                </strong>
                                . Resolution target:{' '}
                                <strong>
                                    {targetLabel(slaTarget.resolution_minutes)}
                                </strong>{' '}
                                at this priority. Deadlines are calculated when
                                saved.
                            </InfoCard>
                        ) : null}
                        {/* Triage is agent work — self-service requesters get no
                            assignee list from the server, so these fields hide. */}
                        {assignees.length > 0 ? (
                            <Field
                                label="Assign to"
                                hint="optional — automatic routing applies when saved"
                                error={
                                    command.fieldErrors.assigned_to_user_id ??
                                    (form.data.assigned_to_user_id !==
                                        UNASSIGNED && !assignee
                                        ? 'Choose a currently eligible technician for this Site.'
                                        : undefined)
                                }
                            >
                                <SelectInput
                                    value={form.data.assigned_to_user_id}
                                    onChange={(v) =>
                                        form.setData({
                                            ...form.data,
                                            assigned_to_user_id: v,
                                            routing_reason:
                                                v === UNASSIGNED
                                                    ? ''
                                                    : form.data.routing_reason,
                                        })
                                    }
                                    placeholder="Unassigned"
                                    options={[
                                        {
                                            value: UNASSIGNED,
                                            label: 'Unassigned',
                                        },
                                        ...eligibleAssignees.map((a) => ({
                                            value: String(a.id),
                                            label: a.name,
                                        })),
                                    ]}
                                />
                            </Field>
                        ) : null}
                        {form.data.assigned_to_user_id !== UNASSIGNED && (
                            <Field
                                label="Why this technician?"
                                required
                                error={command.fieldErrors.routing_reason}
                            >
                                <Textarea
                                    value={form.data.routing_reason}
                                    onChange={(event) =>
                                        form.setData(
                                            'routing_reason',
                                            event.target.value,
                                        )
                                    }
                                    maxLength={1000}
                                    rows={3}
                                    placeholder="Explain why this technician should take the work."
                                />
                            </Field>
                        )}
                        <InfoCard icon={Users}>
                            {form.data.assigned_to_user_id !== UNASSIGNED
                                ? 'This manual assignment and its reason are recorded. Later classification changes preserve it until an authorized technician releases the override; the selected technician must remain eligible.'
                                : 'Routing is applied when the ticket is saved, using its Site, service and classification. The saved ticket shows the actual queue, team and accountable owner, or any setup gap.'}
                        </InfoCard>
                        {assetOptions.length > 0 ? (
                            <Field
                                label="Linked asset"
                                hint="optional — from the assets register"
                                error={command.fieldErrors.asset_id}
                            >
                                <SelectInput
                                    value={form.data.asset_id}
                                    onChange={(v) =>
                                        form.setData('asset_id', v)
                                    }
                                    placeholder="No asset"
                                    options={[
                                        {
                                            value: UNASSIGNED,
                                            label: 'No asset',
                                        },
                                        ...assetOptions.map((a) => ({
                                            value: String(a.id),
                                            label: a.tag
                                                ? `${a.name} · ${a.tag}`
                                                : a.name,
                                        })),
                                    ]}
                                />
                            </Field>
                        ) : null}
                        {availableDevices.length > 0 ? (
                            <Field
                                label="Affected Device"
                                hint="optional — canonical Security & Devices record"
                                error={command.fieldErrors.device_id}
                            >
                                <SelectInput
                                    value={form.data.device_id}
                                    onChange={(v) =>
                                        form.setData('device_id', v)
                                    }
                                    placeholder="No Device"
                                    options={[
                                        {
                                            value: UNASSIGNED,
                                            label: 'No Device',
                                        },
                                        ...availableDevices.map((device) => ({
                                            value: String(device.id),
                                            label: `${device.name} · ${device.uid}`,
                                        })),
                                    ]}
                                />
                            </Field>
                        ) : null}
                        {assignees.length > 0 ? (
                            <Field
                                label="Watchers"
                                hint="optional — kept in the loop on updates"
                            >
                                <SelectInput
                                    value={UNASSIGNED}
                                    onChange={addWatcher}
                                    placeholder="Add a watcher"
                                    options={[
                                        {
                                            value: UNASSIGNED,
                                            label: 'Add a watcher…',
                                        },
                                        ...assignees
                                            .filter(
                                                (a) =>
                                                    !form.data.watchers.includes(
                                                        a.id,
                                                    ),
                                            )
                                            .map((a) => ({
                                                value: String(a.id),
                                                label: a.name,
                                            })),
                                    ]}
                                />
                                {form.data.watchers.length > 0 ? (
                                    <div className="mt-2 flex flex-wrap gap-1.5">
                                        {form.data.watchers.map((id) => {
                                            const w = assignees.find(
                                                (a) => a.id === id,
                                            );
                                            return (
                                                <span
                                                    key={id}
                                                    className="inline-flex items-center gap-1 rounded-full bg-accent px-2 py-0.5 text-[12px] font-medium text-primary"
                                                >
                                                    {w?.name ?? `#${id}`}
                                                    {/* eslint-disable-next-line no-restricted-syntax -- inline chip remove, not button chrome */}
                                                    <button
                                                        type="button"
                                                        onClick={() =>
                                                            form.setData(
                                                                'watchers',
                                                                form.data.watchers.filter(
                                                                    (x) =>
                                                                        x !==
                                                                        id,
                                                                ),
                                                            )
                                                        }
                                                        aria-label={`Remove ${w?.name ?? 'watcher'}`}
                                                        className="text-primary/70 hover:text-primary"
                                                    >
                                                        <X className="h-3 w-3" />
                                                    </button>
                                                </span>
                                            );
                                        })}
                                    </div>
                                ) : null}
                            </Field>
                        ) : null}
                    </div>
                </WizardStepPane>
            )}

            {wizard.index === 2 && (
                <WizardStepPane>
                    <StepHead
                        icon={ClipboardCheck}
                        title="Review & log"
                        blurb="Check the ticket before it lands in the queue."
                    />
                    <TicketDuplicateSuggestions
                        actorId={actorId}
                        title={form.data.title}
                        siteId={
                            form.data.site_id === UNASSIGNED
                                ? null
                                : Number(form.data.site_id)
                        }
                        workType={form.data.work_type}
                        serviceId={
                            form.data.it_service_id === UNASSIGNED
                                ? null
                                : Number(form.data.it_service_id)
                        }
                    />
                    <div className="grid gap-3 sm:grid-cols-2">
                        <ReviewCard
                            icon={FileText}
                            title="Details"
                            onEdit={() => wizard.goTo(0)}
                        >
                            <ReviewRow
                                label="Requester"
                                value={requesterName}
                            />
                            <ReviewRow label="Site" value={site?.name} />
                            <ReviewRow label="Title" value={form.data.title} />
                            <ReviewRow
                                label="Category"
                                value={
                                    CATEGORY_OPTIONS.find(
                                        (c) => c.key === form.data.category,
                                    )?.label
                                }
                            />
                            <ReviewRow
                                label="Subcategory"
                                value={form.data.subcategory || undefined}
                            />
                            <ReviewRow
                                label="Detail"
                                value={form.data.description || undefined}
                            />
                            <ReviewRow
                                label="Files"
                                value={
                                    attachmentCount > 0
                                        ? `${attachmentCount} attached`
                                        : undefined
                                }
                            />
                        </ReviewCard>
                        <ReviewCard
                            icon={Flag}
                            title="Triage"
                            onEdit={() => wizard.goTo(1)}
                        >
                            <ReviewRow
                                label="Work type"
                                value={
                                    INTAKE_WORK_TYPE_OPTIONS.find(
                                        (option) =>
                                            option.key === form.data.work_type,
                                    )?.label
                                }
                            />
                            <ReviewRow
                                label="Service"
                                value={service?.name ?? 'Not selected'}
                            />
                            <ReviewRow
                                label="Impact"
                                value={
                                    TICKET_IMPACT_OPTIONS.find(
                                        (option) =>
                                            option.value === form.data.impact,
                                    )?.label
                                }
                            />
                            <ReviewRow
                                label="Urgency"
                                value={
                                    TICKET_URGENCY_OPTIONS.find(
                                        (option) =>
                                            option.value === form.data.urgency,
                                    )?.label
                                }
                            />
                            <ReviewRow
                                label="Priority"
                                value={
                                    PRIORITY_OPTIONS.find(
                                        (p) => p.key === effectivePriority,
                                    )?.label ?? 'Assessed when saved'
                                }
                            />
                            {hasPriorityOverride && (
                                <ReviewRow
                                    label="Priority reason"
                                    value={form.data.priority_reason}
                                />
                            )}
                            {slaTarget ? (
                                <ReviewRow
                                    label="Resolution target"
                                    value={`${targetLabel(slaTarget.resolution_minutes)} · Deadline calculated when saved`}
                                />
                            ) : null}
                            <ReviewRow
                                label="Assign to"
                                value={
                                    assignee?.name ??
                                    'Automatic routing when saved'
                                }
                            />
                            {assignee && (
                                <ReviewRow
                                    label="Assignment reason"
                                    value={form.data.routing_reason}
                                />
                            )}
                            <ReviewRow
                                label="Asset"
                                value={
                                    asset
                                        ? asset.tag
                                            ? `${asset.name} · ${asset.tag}`
                                            : asset.name
                                        : undefined
                                }
                            />
                            <ReviewRow
                                label="Device"
                                value={
                                    device
                                        ? `${device.name} · ${device.uid}`
                                        : undefined
                                }
                            />
                            <ReviewRow
                                label="Watchers"
                                value={
                                    form.data.watchers.length > 0
                                        ? `${form.data.watchers.length}`
                                        : undefined
                                }
                            />
                        </ReviewCard>
                    </div>
                </WizardStepPane>
            )}
        </TicketCommandWizard>
    );
}

/* ================================================================== */
/*  New provisioning request (agent, 2 steps)                         */
/* ================================================================== */

const PROVISIONING_STEPS: readonly WizardStep[] = [
    { key: 'what', label: 'Request', blurb: 'Who & what', icon: FileText },
    { key: 'assign', label: 'Assign', blurb: 'Owner & due', icon: Flag },
];

const REQUEST_TYPE_OPTIONS = [
    {
        key: 'account',
        label: 'Account',
        description: 'Email, logins & software',
        icon: Mail,
    },
    {
        key: 'access',
        label: 'Access',
        description: 'Systems & permissions',
        icon: KeyRound,
    },
    {
        key: 'equipment',
        label: 'Equipment',
        description: 'Laptop, phone & devices',
        icon: Laptop,
    },
    {
        key: 'other',
        label: 'Other',
        description: 'Anything else to provision',
        icon: Server,
    },
] as const;

function NewProvisioningRequestDialog({
    employeeOptions,
    assignees,
    onClose,
}: {
    employeeOptions: EmployeeOption[];
    assignees: AssigneeOption[];
    onClose: () => void;
}) {
    const wizard = useWizard(PROVISIONING_STEPS.length);
    const [done, setDone] = useState(false);

    const form = useForm({
        employee_profile_id: '',
        type: 'account',
        item: '',
        assigned_to_user_id: UNASSIGNED,
        priority: 'normal',
        due_date: '',
        notes: '',
    });

    const employee =
        employeeOptions.find(
            (e) => String(e.id) === form.data.employee_profile_id,
        ) ?? null;
    const detailsValid =
        form.data.employee_profile_id !== '' &&
        form.data.item.trim().length > 0;

    const submit = () => {
        form.transform((data) => ({
            ...data,
            employee_profile_id: Number(data.employee_profile_id),
            assigned_to_user_id:
                data.assigned_to_user_id === UNASSIGNED
                    ? null
                    : Number(data.assigned_to_user_id),
            due_date: data.due_date === '' ? null : data.due_date,
            notes: data.notes.trim() === '' ? null : data.notes,
        }));
        form.post('/it/provisioning', {
            preserveScroll: true,
            onSuccess: (page) => {
                const err = pageFlashError(page);
                if (err) {
                    toast.error(err);
                    return;
                }
                setDone(true);
            },
        });
    };

    return (
        <WizardShell
            open
            onClose={onClose}
            title="New provisioning request"
            description="Raise an ad-hoc account, access or equipment request."
            railIcon={Server}
            railTitle="New request"
            railSub="Provisioning"
            steps={PROVISIONING_STEPS}
            stepIndex={wizard.index}
            onStepClick={wizard.goTo}
            pct={wizard.progress}
            success={
                done ? (
                    <WizardSuccessPane
                        title="Request raised"
                        blurb={
                            <>
                                “{form.data.item}” is on the provisioning queue
                                {employee ? <> for {employee.name}</> : null}.
                            </>
                        }
                        actions={<Button onClick={onClose}>Done</Button>}
                    />
                ) : undefined
            }
            footerStart={
                wizard.isFirst ? null : (
                    <Button variant="outline" onClick={wizard.back}>
                        Back
                    </Button>
                )
            }
            footerEnd={
                <>
                    <Button variant="ghost" onClick={onClose}>
                        Cancel
                    </Button>
                    {wizard.isLast ? (
                        <Button
                            onClick={submit}
                            disabled={form.processing || !detailsValid}
                        >
                            {form.processing ? 'Raising…' : 'Raise request'}
                        </Button>
                    ) : (
                        <Button onClick={wizard.next} disabled={!detailsValid}>
                            Continue
                        </Button>
                    )}
                </>
            }
        >
            {wizard.index === 0 && (
                <WizardStepPane>
                    <StepHead
                        icon={FileText}
                        title="Who & what"
                        blurb="Who’s this for, and what needs provisioning?"
                    />
                    <div className="grid gap-3.5">
                        <Field
                            label="Employee"
                            required
                            error={form.errors.employee_profile_id}
                        >
                            <SelectInput
                                value={form.data.employee_profile_id}
                                onChange={(v) =>
                                    form.setData('employee_profile_id', v)
                                }
                                placeholder="Choose an employee"
                                options={employeeOptions.map((e) => ({
                                    value: String(e.id),
                                    label: e.name,
                                }))}
                            />
                        </Field>
                        <Field label="Type" error={form.errors.type}>
                            <TilePicker
                                value={form.data.type}
                                onChange={(v) => form.setData('type', v)}
                                options={[...REQUEST_TYPE_OPTIONS]}
                            />
                        </Field>
                        <Field label="Item" required error={form.errors.item}>
                            <Input
                                value={form.data.item}
                                onChange={(e) =>
                                    form.setData('item', e.target.value)
                                }
                                placeholder="e.g. Replacement laptop"
                                maxLength={255}
                            />
                        </Field>
                    </div>
                </WizardStepPane>
            )}
            {wizard.index === 1 && (
                <WizardStepPane>
                    <StepHead
                        icon={Flag}
                        title="Assign & schedule"
                        blurb="Owner, priority and a due date if there is one."
                    />
                    <div className="grid gap-3.5">
                        <Field label="Priority" error={form.errors.priority}>
                            <TilePicker
                                value={form.data.priority}
                                onChange={(v) => form.setData('priority', v)}
                                options={[...PRIORITY_OPTIONS]}
                            />
                        </Field>
                        {assignees.length > 0 ? (
                            <Field
                                label="Assign to"
                                hint="optional"
                                error={form.errors.assigned_to_user_id}
                            >
                                <SelectInput
                                    value={form.data.assigned_to_user_id}
                                    onChange={(v) =>
                                        form.setData('assigned_to_user_id', v)
                                    }
                                    placeholder="Unassigned"
                                    options={[
                                        {
                                            value: UNASSIGNED,
                                            label: 'Unassigned',
                                        },
                                        ...assignees.map((a) => ({
                                            value: String(a.id),
                                            label: a.name,
                                        })),
                                    ]}
                                />
                            </Field>
                        ) : null}
                        <Field
                            label="Due date"
                            hint="optional"
                            error={form.errors.due_date}
                        >
                            <Input
                                type="date"
                                value={form.data.due_date}
                                onChange={(e) =>
                                    form.setData('due_date', e.target.value)
                                }
                            />
                        </Field>
                        <Field
                            label="Notes"
                            hint="optional"
                            error={form.errors.notes}
                        >
                            <Textarea
                                value={form.data.notes}
                                onChange={(e) =>
                                    form.setData('notes', e.target.value)
                                }
                                rows={3}
                                placeholder="Anything the fulfiller should know…"
                            />
                        </Field>
                    </div>
                </WizardStepPane>
            )}
        </WizardShell>
    );
}

/* ================================================================== */
/*  Raise a ticket (self-service, single step — speed IS the spec)    */
/* ================================================================== */

const RAISE_STEPS: readonly WizardStep[] = [
    {
        key: 'raise',
        label: 'Raise a ticket',
        blurb: 'Tell IT what you need',
        icon: Ticket,
    },
];

/** Plain-language categories for people mid-shift — no IT jargon. */
const RAISE_CATEGORY_OPTIONS = [
    {
        key: 'hardware',
        label: 'Device or hardware',
        description: 'Phone, laptop, printer, charger…',
        icon: Laptop,
    },
    {
        key: 'account',
        label: 'Account or sign-in',
        description: 'Locked out, passwords, email',
        icon: User,
    },
    {
        key: 'network',
        label: 'Wi-Fi or network',
        description: 'No internet, VPN trouble',
        icon: Wifi,
    },
    {
        key: 'other',
        label: 'Something else',
        description: 'Anything IT should look at',
        icon: Server,
    },
] as const;

function RaiseTicketDialog({
    actorId,
    draftRecoveryEnabled,
    siteOptions,
    kbSuggestions = [],
    onOpenArticle,
    onClose,
}: {
    actorId: number;
    draftRecoveryEnabled: boolean;
    siteOptions: SiteOption[];
    kbSuggestions?: KbSuggestion[];
    onOpenArticle?: (id: number) => void;
    onClose: () => void;
}) {
    const wizard = useWizard(RAISE_STEPS.length);
    const attachmentId = useId();
    const command = useItTicketCommand({
        actorId,
        draftRequestId:
            itIntakeDraftMemoryRequestIds(actorId, 'requester_intake')[0] ??
            preferredIntakeDraft(
                draftRecoveryEnabled,
                actorId,
                'requester_intake',
            ),
    });
    const done = command.result !== null;
    const [moreDetails, setMoreDetails] = useState(false);
    const reference = command.result?.reference;
    const finishClose = () => {
        onClose();
        if (command.result) router.reload();
    };

    const form = useForm<{
        title: string;
        description: string;
        category: string;
        impact: TicketImpact;
        urgency: TicketUrgency;
        site_id: string;
        attachments: File[];
    }>({
        title: '',
        description: '',
        category: 'hardware',
        impact: 'individual',
        urgency: 'normal',
        site_id:
            siteOptions.length === 1 ? String(siteOptions[0].id) : UNASSIGNED,
        attachments: [],
    });

    const initialValues = useRef(form.data);
    const savedSnapshot = intakeDraftSnapshot(
        'requester_intake',
        form.data,
        wizard.index,
    );
    const intake = useItIntakeDraft({
        enabled: draftRecoveryEnabled,
        actorId,
        purpose: 'requester_intake',
        command,
        snapshot: savedSnapshot,
        dirty: form.isDirty,
        files: form.data.attachments,
    });
    const resumeBrowserWork = (restored: ItDraftBrowserRestored) => {
        form.setData({
            ...restoreIntakeDraft(initialValues.current, restored.snapshot),
            attachments: restored.files,
        });
        form.clearErrors();
        wizard.goTo(restored.snapshot.step_index);
        setMoreDetails(
            !!restored.snapshot.fields.description || restored.files.length > 0,
        );
    };
    const resumeDraft = (saved: ItDraftResumed) => {
        form.setData({
            ...restoreIntakeDraft(initialValues.current, saved.payload),
            attachments: [],
        });
        form.clearErrors();
        wizard.goTo(saved.payload.step_index);
        setMoreDetails(
            !!saved.payload.fields.description || saved.attachments.length > 0,
        );
    };
    const resetDraftFields = () => {
        form.reset();
        form.clearErrors('attachments');
        wizard.goTo(0);
    };
    const { setData, setDefaults, clearErrors } = form;
    useEffect(() => {
        if (
            command.state !== 'access_denied' &&
            intake.draft.state !== 'access_denied'
        )
            return;
        const cleared = {
            title: '',
            description: '',
            category: 'hardware',
            impact: 'individual' as const,
            urgency: 'normal' as const,
            site_id: UNASSIGNED,
            attachments: [],
        };
        setData(cleared);
        setDefaults(cleared);
        clearErrors();
    }, [command.state, intake.draft.state, setData, setDefaults, clearErrors]);
    const valid =
        form.data.title.trim().length > 0 &&
        siteOptions.some((site) => String(site.id) === form.data.site_id);

    // Deflection (§I): live-match published article titles as the requester types.
    const query = form.data.title.trim().toLowerCase();
    const kbMatches =
        onOpenArticle && query.length >= 3
            ? kbSuggestions
                  .filter((s) => s.title.toLowerCase().includes(query))
                  .slice(0, 3)
            : [];

    const submit = () => {
        void intake.submit({
            ...form.data,
            site_id: Number(form.data.site_id),
        });
    };

    return (
        <TicketCommandWizard
            command={command}
            dirty={intake.dirty}
            draftExit={
                draftRecoveryEnabled
                    ? {
                          busy: intake.draft.busy,
                          canSave:
                              intake.draft.state === 'ready' &&
                              intake.draft.draft?.capabilities.save === true,
                          canDiscard:
                              ['ready', 'available'].includes(
                                  intake.draft.state,
                              ) &&
                              intake.draft.draft?.capabilities.discard === true,
                          save: intake.saveForClose,
                          discard: intake.discardForClose,
                          keepReference: intake.draft.draft
                              ? intake.keepReference
                              : undefined,
                      }
                    : undefined
            }
            open
            onClose={finishClose}
            title="Raise a ticket"
            onDiscardBrowserWork={() => {
                intake.draft.clearOwnedBrowserWork();
                resetDraftFields();
            }}
            description="Tell IT what you need help with."
            railIcon={Ticket}
            railTitle="Raise a ticket"
            railSub="IT helpdesk"
            steps={RAISE_STEPS}
            stepIndex={wizard.index}
            onStepClick={wizard.goTo}
            pct={Math.round(
                ([
                    form.data.title.trim().length > 0,
                    siteOptions.some(
                        (site) => String(site.id) === form.data.site_id,
                    ),
                    !!form.data.category,
                    !!form.data.impact,
                    !!form.data.urgency,
                ].filter(Boolean).length /
                    5) *
                    100,
            )}
            success={
                done ? (
                    <WizardSuccessPane
                        title={
                            reference
                                ? `Raised — ${reference}`
                                : 'Ticket raised'
                        }
                        blurb={
                            <>
                                Your request is saved. Open it now or return to{' '}
                                <strong>My tickets</strong> to follow its
                                progress.
                            </>
                        }
                        actions={
                            <>
                                <Button
                                    onClick={() =>
                                        command.result &&
                                        router.visit(command.result.url)
                                    }
                                >
                                    Open {reference}
                                </Button>
                                <Button variant="ghost" onClick={finishClose}>
                                    Done
                                </Button>
                            </>
                        }
                    />
                ) : undefined
            }
            footerStart={null}
            footerEnd={
                <>
                    <Button
                        onClick={submit}
                        disabled={!intake.canSubmit || !valid}
                    >
                        {command.isBusy || intake.preparing
                            ? 'Raising…'
                            : 'Raise ticket'}
                    </Button>
                </>
            }
        >
            <TicketIntakeDraftPanel
                intake={intake}
                command={command}
                snapshot={savedSnapshot}
                dirty={form.isDirty}
                onResume={resumeDraft}
                onDiscarded={resetDraftFields}
                onStartNew={resetDraftFields}
                optionLabels={{
                    site_id: Object.fromEntries(
                        siteOptions.map((row) => [row.id, row.name]),
                    ),
                }}
                onResumeMemory={resumeBrowserWork}
            />
            <WizardStepPane>
                <StepHead
                    icon={Ticket}
                    title="What’s the problem?"
                    blurb="Choose the affected Site and tell us what happened. You can add more detail if it helps."
                />
                <div className="grid grid-cols-1 gap-3.5">
                    <TicketSiteField
                        sites={siteOptions}
                        value={form.data.site_id}
                        onChange={(value) => form.setData('site_id', value)}
                        error={command.fieldErrors.site_id}
                    />
                    <Field
                        label="What's broken?"
                        required
                        error={command.fieldErrors.title}
                    >
                        <Input
                            value={form.data.title}
                            onChange={(e) =>
                                form.setData('title', e.target.value)
                            }
                            placeholder="e.g. My work phone won’t charge"
                            maxLength={255}
                            autoFocus
                        />
                    </Field>
                    <TicketDuplicateSuggestions
                        actorId={actorId}
                        title={form.data.title}
                        siteId={
                            form.data.site_id === UNASSIGNED
                                ? null
                                : Number(form.data.site_id)
                        }
                    />
                    <Field
                        label="What kind of thing is it?"
                        error={command.fieldErrors.category}
                    >
                        <TilePicker
                            value={form.data.category}
                            onChange={(v) => form.setData('category', v)}
                            options={[...RAISE_CATEGORY_OPTIONS]}
                        />
                    </Field>
                    <TicketImpactUrgencyFields
                        impact={form.data.impact}
                        urgency={form.data.urgency}
                        onImpactChange={(value) =>
                            form.setData('impact', value)
                        }
                        onUrgencyChange={(value) =>
                            form.setData('urgency', value)
                        }
                        errors={command.fieldErrors}
                    />
                    {kbMatches.length > 0 ? (
                        <div className="min-w-0 rounded-xl border border-primary/30 bg-primary/5 p-3">
                            <div className="flex items-center gap-1.5 text-[12px] font-semibold text-primary">
                                <BookOpen className="h-3.5 w-3.5" /> These might
                                fix it now
                            </div>
                            <div className="mt-1.5 flex flex-col gap-1">
                                {kbMatches.map((s) => (
                                    // eslint-disable-next-line no-restricted-syntax -- KB suggestion link, not button chrome
                                    <button
                                        key={s.id}
                                        type="button"
                                        onClick={() => onOpenArticle?.(s.id)}
                                        className="flex items-center justify-between gap-2 rounded-lg px-2 py-1.5 text-left text-[13px] transition-colors hover:bg-primary/10"
                                    >
                                        <span className="truncate font-medium">
                                            {s.title}
                                        </span>
                                        <span className="flex-none text-[11px] text-muted-foreground">
                                            Read →
                                        </span>
                                    </button>
                                ))}
                            </div>
                        </div>
                    ) : null}
                    {moreDetails ? (
                        <>
                            <Field
                                label="More details"
                                hint="optional"
                                error={command.fieldErrors.description}
                            >
                                <Textarea
                                    value={form.data.description}
                                    onChange={(e) =>
                                        form.setData(
                                            'description',
                                            e.target.value,
                                        )
                                    }
                                    placeholder="Anything that helps IT find or fix it — where you are, what you tried…"
                                    rows={4}
                                />
                            </Field>
                            <Field
                                label="Photos or files"
                                hint="optional — a photo says a lot"
                                htmlFor={
                                    !draftRecoveryEnabled
                                        ? `${attachmentId}-input`
                                        : undefined
                                }
                                labelId={
                                    !draftRecoveryEnabled
                                        ? `${attachmentId}-label`
                                        : undefined
                                }
                                errorId={
                                    !draftRecoveryEnabled
                                        ? `${attachmentId}-error`
                                        : undefined
                                }
                                error={
                                    form.errors.attachments ??
                                    command.fieldErrors.attachments
                                }
                            >
                                {draftRecoveryEnabled ? (
                                    <TicketDraftFiles
                                        draft={intake.draft}
                                        disabled={!command.canEdit}
                                    />
                                ) : (
                                    <>
                                        <FileDropzone
                                            id={attachmentId}
                                            aria-labelledby={`${attachmentId}-label ${attachmentId}-title`}
                                            aria-describedby={
                                                (form.errors.attachments ??
                                                command.fieldErrors.attachments)
                                                    ? `${attachmentId}-error`
                                                    : undefined
                                            }
                                            aria-invalid={Boolean(
                                                form.errors.attachments ??
                                                command.fieldErrors.attachments,
                                            )}
                                            disabled={!command.canEdit}
                                            onFiles={(files) => {
                                                if (!command.canEdit) return;
                                                const error =
                                                    itAttachmentSelectionError(
                                                        files,
                                                        form.data.attachments
                                                            .length,
                                                    );
                                                if (error) {
                                                    form.setError(
                                                        'attachments',
                                                        error,
                                                    );
                                                    return;
                                                }
                                                form.clearErrors('attachments');
                                                form.setData('attachments', [
                                                    ...form.data.attachments,
                                                    ...files,
                                                ]);
                                            }}
                                            accept={IT_ATTACHMENT_ACCEPT}
                                            title="Drop a photo of the problem"
                                            hint="Images, PDF or documents — up to 5 files"
                                        />
                                        {form.data.attachments.map(
                                            (file, i) => (
                                                <StagedFileCard
                                                    key={`${file.name}-${i}`}
                                                    file={file}
                                                    onRemove={() => {
                                                        form.clearErrors(
                                                            'attachments',
                                                        );
                                                        form.setData(
                                                            'attachments',
                                                            form.data.attachments.filter(
                                                                (_, j) =>
                                                                    j !== i,
                                                            ),
                                                        );
                                                    }}
                                                />
                                            ),
                                        )}
                                    </>
                                )}
                            </Field>
                        </>
                    ) : (
                        // eslint-disable-next-line no-restricted-syntax -- text-link disclosure, not a button chrome
                        <button
                            type="button"
                            onClick={() => setMoreDetails(true)}
                            className="justify-self-start text-[12.5px] font-semibold text-primary hover:underline"
                        >
                            + Add more details
                        </button>
                    )}
                </div>
            </WizardStepPane>
        </TicketCommandWizard>
    );
}

/* ================================================================== */
/*  Resolve ticket (single step — the note IS the record)             */
/* ================================================================== */

export { ResolveTicketDialog };

/* ================================================================== */
/*  SLA targets (single step, admin-only — §N7)                       */
/* ================================================================== */

const SLA_STEPS: readonly WizardStep[] = [
    {
        key: 'targets',
        label: 'SLA targets',
        blurb: 'Minutes per priority',
        icon: Timer,
    },
    {
        key: 'hours',
        label: 'Business hours',
        blurb: 'When the clock runs',
        icon: CalendarClock,
    },
];

/** §G defaults — what "Reset to defaults" restores. */
const SLA_DEFAULTS: Record<
    string,
    { first_response_minutes: string; resolution_minutes: string }
> = {
    urgent: { first_response_minutes: '60', resolution_minutes: '240' },
    high: { first_response_minutes: '240', resolution_minutes: '1440' },
    normal: { first_response_minutes: '1440', resolution_minutes: '4320' },
    low: { first_response_minutes: '4320', resolution_minutes: '10080' },
};

const SLA_PRIORITY_HINTS: Record<string, string> = {
    urgent: 'Stops someone supporting a person right now',
    high: 'Blocking their work',
    normal: 'Annoying, but they can work',
    low: 'Whenever there’s a moment',
};

const SLA_WORKING_DAYS: readonly { key: string; short: string }[] = [
    { key: 'mon', short: 'Mon' },
    { key: 'tue', short: 'Tue' },
    { key: 'wed', short: 'Wed' },
    { key: 'thu', short: 'Thu' },
    { key: 'fri', short: 'Fri' },
    { key: 'sat', short: 'Sat' },
    { key: 'sun', short: 'Sun' },
];

/** "90 min" / "4 h" / "3 d" — so minutes never need mental arithmetic. */
function minutesHuman(raw: string): string {
    const m = Number(raw);
    if (!Number.isFinite(m) || m <= 0) return '—';
    if (m < 60) return `${m} min`;
    if (m < 24 * 60) {
        const h = m / 60;
        return `${Number.isInteger(h) ? h : h.toFixed(1)} h`;
    }
    const d = m / (24 * 60);
    return `${Number.isInteger(d) ? d : d.toFixed(1)} d`;
}

type SlaFormData = Record<
    string,
    { first_response_minutes: string; resolution_minutes: string }
>;

export function SlaPolicyDialog({
    policies,
    calendar,
    onClose,
}: {
    policies: SlaPolicyGrid;
    calendar?: SlaCalendar | null;
    onClose: () => void;
}) {
    const wizard = useWizard(SLA_STEPS.length);
    const [done, setDone] = useState(false);
    const [cal, setCal] = useState({
        enabled: calendar?.enabled ?? false,
        open_time: calendar?.open_time ?? '08:00',
        close_time: calendar?.close_time ?? '17:00',
        working_days: calendar?.working_days ?? [
            'mon',
            'tue',
            'wed',
            'thu',
            'fri',
        ],
        holiday_dates: calendar?.holiday_dates ?? [],
    });
    const [holidayDraft, setHolidayDraft] = useState('');

    const form = useForm<SlaFormData>(
        Object.fromEntries(
            Object.keys(SLA_DEFAULTS).map((priority) => [
                priority,
                {
                    first_response_minutes: String(
                        policies[priority]?.first_response_minutes ??
                            SLA_DEFAULTS[priority].first_response_minutes,
                    ),
                    resolution_minutes: String(
                        policies[priority]?.resolution_minutes ??
                            SLA_DEFAULTS[priority].resolution_minutes,
                    ),
                },
            ]),
        ),
    );

    // Nested error keys ("urgent.resolution_minutes") aren't in the typed map.
    const errors = form.errors as Record<string, string | undefined>;

    const set = (
        priority: string,
        field: 'first_response_minutes' | 'resolution_minutes',
        value: string,
    ) => form.setData(priority, { ...form.data[priority], [field]: value });

    const toggleDay = (key: string) =>
        setCal((c) => ({
            ...c,
            working_days: c.working_days.includes(key)
                ? c.working_days.filter((d) => d !== key)
                : [...c.working_days, key],
        }));
    const addHoliday = () => {
        if (holidayDraft && !cal.holiday_dates.includes(holidayDraft)) {
            setCal((c) => ({
                ...c,
                holiday_dates: [...c.holiday_dates, holidayDraft].sort(),
            }));
        }
        setHolidayDraft('');
    };
    const removeHoliday = (d: string) =>
        setCal((c) => ({
            ...c,
            holiday_dates: c.holiday_dates.filter((x) => x !== d),
        }));

    const submit = () => {
        form.transform((data) => ({
            ...data,
            business_hours_enabled: cal.enabled,
            open_time: cal.open_time,
            close_time: cal.close_time,
            working_days: cal.working_days,
            holiday_dates: cal.holiday_dates,
        }));
        form.put('/it/sla-policies', {
            preserveScroll: true,
            onSuccess: (page) => {
                const err = pageFlashError(page);
                if (err) {
                    toast.error(err);
                    return;
                }
                setDone(true);
                toast.success('SLA targets updated.');
            },
        });
    };

    return (
        <WizardShell
            open
            onClose={onClose}
            title="SLA targets"
            description="The clock every ticket is stamped with, per priority."
            railIcon={Timer}
            railTitle="SLA targets"
            railSub="IT helpdesk"
            steps={SLA_STEPS}
            stepIndex={wizard.index}
            onStepClick={wizard.goTo}
            pct={wizard.progress}
            success={
                done ? (
                    <WizardSuccessPane
                        title="Targets saved"
                        blurb="New tickets and re-triaged priorities use these immediately. Existing tickets keep the targets they were promised."
                        actions={<Button onClick={onClose}>Done</Button>}
                    />
                ) : undefined
            }
            footerStart={
                wizard.isFirst ? (
                    <Button
                        variant="ghost"
                        onClick={() =>
                            form.setData(structuredClone(SLA_DEFAULTS))
                        }
                        disabled={form.processing}
                    >
                        <RotateCcw className="h-3.5 w-3.5" /> Reset to defaults
                    </Button>
                ) : (
                    <Button variant="outline" onClick={wizard.back}>
                        Back
                    </Button>
                )
            }
            footerEnd={
                <>
                    <Button variant="ghost" onClick={onClose}>
                        Cancel
                    </Button>
                    {wizard.isLast ? (
                        <Button onClick={submit} disabled={form.processing}>
                            {form.processing ? 'Saving…' : 'Save targets'}
                        </Button>
                    ) : (
                        <Button onClick={wizard.next}>Next</Button>
                    )}
                </>
            }
        >
            {wizard.index === 0 && (
                <WizardStepPane>
                    <StepHead
                        icon={Timer}
                        title="Response & resolution targets"
                        blurb="Targets count from creation. First response stops at the first public agent reply. Resolution pauses during recorded requester, vendor, approver, team, change or other waits."
                    />
                    <div className="grid gap-3">
                        {Object.keys(SLA_DEFAULTS).map((priority) => (
                            <div
                                key={priority}
                                className="rounded-xl border border-border bg-muted/30 p-3.5"
                            >
                                <div className="flex flex-wrap items-center justify-between gap-2">
                                    <div>
                                        <div className="text-[13px] font-bold capitalize">
                                            {priority}
                                        </div>
                                        <div className="text-[11.5px] text-muted-foreground">
                                            {SLA_PRIORITY_HINTS[priority]}
                                        </div>
                                    </div>
                                    {policies[priority]?.is_custom ? (
                                        <StatusBadge variant="info" size="sm">
                                            Custom
                                        </StatusBadge>
                                    ) : (
                                        <StatusBadge
                                            variant="neutral"
                                            size="sm"
                                        >
                                            Default
                                        </StatusBadge>
                                    )}
                                </div>
                                <div className="mt-2.5 grid gap-3 sm:grid-cols-2">
                                    <Field
                                        label="First response (minutes)"
                                        error={
                                            errors[
                                                `${priority}.first_response_minutes`
                                            ]
                                        }
                                    >
                                        <div className="flex items-center gap-2">
                                            <Input
                                                type="number"
                                                min={5}
                                                value={
                                                    form.data[priority]
                                                        .first_response_minutes
                                                }
                                                onChange={(e) =>
                                                    set(
                                                        priority,
                                                        'first_response_minutes',
                                                        e.target.value,
                                                    )
                                                }
                                                aria-label={`${priority} first response minutes`}
                                            />
                                            <span className="text-[11.5px] whitespace-nowrap text-muted-foreground">
                                                ={' '}
                                                {minutesHuman(
                                                    form.data[priority]
                                                        .first_response_minutes,
                                                )}
                                            </span>
                                        </div>
                                    </Field>
                                    <Field
                                        label="Resolution (minutes)"
                                        error={
                                            errors[
                                                `${priority}.resolution_minutes`
                                            ]
                                        }
                                    >
                                        <div className="flex items-center gap-2">
                                            <Input
                                                type="number"
                                                min={5}
                                                value={
                                                    form.data[priority]
                                                        .resolution_minutes
                                                }
                                                onChange={(e) =>
                                                    set(
                                                        priority,
                                                        'resolution_minutes',
                                                        e.target.value,
                                                    )
                                                }
                                                aria-label={`${priority} resolution minutes`}
                                            />
                                            <span className="text-[11.5px] whitespace-nowrap text-muted-foreground">
                                                ={' '}
                                                {minutesHuman(
                                                    form.data[priority]
                                                        .resolution_minutes,
                                                )}
                                            </span>
                                        </div>
                                    </Field>
                                </div>
                            </div>
                        ))}
                        <InfoCard icon={Timer}>
                            {cal.enabled
                                ? 'Clocks run on the business-hours calendar set in the next step.'
                                : 'Clocks run 24/7 — set a business-hours calendar in the next step to pause them overnight and at weekends.'}{' '}
                            Changing targets never rewrites tickets already on
                            the queue.
                        </InfoCard>
                    </div>
                </WizardStepPane>
            )}

            {wizard.index === 1 && (
                <WizardStepPane>
                    <StepHead
                        icon={CalendarClock}
                        title="Business hours"
                        blurb="When enabled, SLA clocks only tick during these hours — a Friday-evening ticket is due the next working morning, not over the weekend."
                    />
                    <div className="grid gap-3.5">
                        <label className="flex items-center gap-2 text-[13px] font-medium">
                            <Checkbox
                                checked={cal.enabled}
                                onCheckedChange={(v) =>
                                    setCal((c) => ({
                                        ...c,
                                        enabled: v === true,
                                    }))
                                }
                            />
                            Run SLA clocks on a business-hours calendar
                        </label>

                        {cal.enabled ? (
                            <>
                                <div className="grid gap-3 sm:grid-cols-2">
                                    <Field
                                        label="Opens"
                                        error={errors.open_time}
                                    >
                                        <Input
                                            type="time"
                                            value={cal.open_time}
                                            onChange={(e) =>
                                                setCal((c) => ({
                                                    ...c,
                                                    open_time: e.target.value,
                                                }))
                                            }
                                            aria-label="Business hours open time"
                                        />
                                    </Field>
                                    <Field
                                        label="Closes"
                                        error={errors.close_time}
                                    >
                                        <Input
                                            type="time"
                                            value={cal.close_time}
                                            onChange={(e) =>
                                                setCal((c) => ({
                                                    ...c,
                                                    close_time: e.target.value,
                                                }))
                                            }
                                            aria-label="Business hours close time"
                                        />
                                    </Field>
                                </div>

                                <Field
                                    label="Working days"
                                    error={errors.working_days}
                                >
                                    <div className="flex flex-wrap gap-1.5">
                                        {SLA_WORKING_DAYS.map((d) => {
                                            const on =
                                                cal.working_days.includes(
                                                    d.key,
                                                );
                                            return (
                                                // eslint-disable-next-line no-restricted-syntax -- selector chip: custom selected fill + aria-pressed, not a <Button>
                                                <button
                                                    key={d.key}
                                                    type="button"
                                                    aria-pressed={on}
                                                    onClick={() =>
                                                        toggleDay(d.key)
                                                    }
                                                    className={`rounded-lg border px-2.5 py-1 text-[12px] font-semibold transition focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none ${
                                                        on
                                                            ? 'border-primary bg-primary text-primary-foreground'
                                                            : 'border-border bg-transparent text-muted-foreground hover:bg-muted'
                                                    }`}
                                                >
                                                    {d.short}
                                                </button>
                                            );
                                        })}
                                    </div>
                                </Field>

                                <Field label="Public holidays (optional)">
                                    <div className="grid gap-2">
                                        {cal.holiday_dates.length > 0 && (
                                            <div className="flex flex-wrap gap-1.5">
                                                {cal.holiday_dates.map((d) => (
                                                    <span
                                                        key={d}
                                                        className="inline-flex items-center gap-1 rounded-lg border border-border bg-muted/40 px-2 py-1 text-[12px]"
                                                    >
                                                        {d}
                                                        {/* eslint-disable-next-line no-restricted-syntax -- tiny inline remove-chip control */}
                                                        <button
                                                            type="button"
                                                            onClick={() =>
                                                                removeHoliday(d)
                                                            }
                                                            aria-label={`Remove holiday ${d}`}
                                                            className="text-muted-foreground hover:text-foreground"
                                                        >
                                                            <X className="h-3 w-3" />
                                                        </button>
                                                    </span>
                                                ))}
                                            </div>
                                        )}
                                        <div className="flex items-center gap-2">
                                            <Input
                                                type="date"
                                                value={holidayDraft}
                                                onChange={(e) =>
                                                    setHolidayDraft(
                                                        e.target.value,
                                                    )
                                                }
                                                aria-label="Add a public holiday"
                                            />
                                            <Button
                                                variant="outline"
                                                onClick={addHoliday}
                                                disabled={!holidayDraft}
                                            >
                                                Add
                                            </Button>
                                        </div>
                                    </div>
                                </Field>

                                <InfoCard icon={CalendarClock}>
                                    These hours apply to every priority.
                                    Weekends and listed holidays don’t count
                                    against any SLA clock.
                                </InfoCard>
                            </>
                        ) : (
                            <InfoCard icon={Timer}>
                                Clocks run 24/7 — every minute counts, including
                                nights and weekends. Enable a calendar above to
                                pause them outside working hours.
                            </InfoCard>
                        )}
                    </div>
                </WizardStepPane>
            )}
        </WizardShell>
    );
}

/* ================================================================== */
/*  Merge ticket (agent — fold a duplicate into a survivor, §P-S2)     */
/* ================================================================== */

export { MergeTicketDialog, type MergeTarget } from './merge-ticket-dialog';

/* ================================================================== */
/*  KB article (agent, 4 steps: Basics → Audience → Content → Review) */
/* ================================================================== */

const KB_STEPS: readonly WizardStep[] = [
    {
        key: 'basics',
        label: 'Basics',
        blurb: 'Title & category',
        icon: FileText,
    },
    {
        key: 'audience',
        label: 'Ownership',
        blurb: 'Audience & review',
        icon: Users,
    },
    {
        key: 'content',
        label: 'Content',
        blurb: 'Write & preview',
        icon: BookOpen,
    },
    {
        key: 'review',
        label: 'Review',
        blurb: 'Confirm & save',
        icon: ClipboardCheck,
    },
];

/** A safe, block-level markdown preview: `#`/`##`/`###` headings, `-`/`*`
 *  bullets, blank-line paragraphs. No inline HTML / no dangerouslySetInnerHTML. */
export function KbPreview({ body }: { body: string }) {
    return (
        <div className="space-y-1.5 text-[13px] leading-relaxed">
            {body.split('\n').map((raw, i) => {
                const line = raw.trim();
                if (line === '')
                    return <div key={i} className="h-1.5" aria-hidden />;
                if (line.startsWith('### '))
                    return (
                        <div key={i} className="text-[13px] font-bold">
                            {line.slice(4)}
                        </div>
                    );
                if (line.startsWith('## '))
                    return (
                        <div key={i} className="text-[14px] font-bold">
                            {line.slice(3)}
                        </div>
                    );
                if (line.startsWith('# '))
                    return (
                        <div key={i} className="text-[15px] font-bold">
                            {line.slice(2)}
                        </div>
                    );
                if (/^[-*]\s/.test(line)) {
                    return (
                        <div key={i} className="flex gap-2">
                            <span aria-hidden className="text-muted-foreground">
                                •
                            </span>
                            <span>{line.replace(/^[-*]\s/, '')}</span>
                        </div>
                    );
                }
                return <p key={i}>{line}</p>;
            })}
        </div>
    );
}

function KbArticleDialog({
    article,
    draft,
    options,
    onClose,
}: {
    article?: KbRow;
    draft?: KbDraft;
    options: KbOptions;
    onClose: () => void;
}) {
    const editing = Boolean(article);
    const wizard = useWizard(KB_STEPS.length);
    const [done, setDone] = useState(false);

    // A `draft` (e.g. from a resolution note) pre-fills a NEW article — no
    // article means we still create, we just start with content in place.
    const form = useForm({
        title: article?.title ?? draft?.title ?? '',
        category: article?.category ?? draft?.category ?? 'hardware',
        audience: article?.audience ?? 'all_staff',
        site_scope: article?.site_scope ?? ([] as number[]),
        owner_user_id: String(article?.owner_user_id ?? ''),
        related_service_id: String(article?.related_service_id ?? ''),
        review_due_at: article?.review_due_at ?? '',
        body: article?.body ?? draft?.body ?? '',
    });

    const basicsValid = form.data.title.trim().length > 0;
    const contentValid = form.data.body.trim().length > 0;
    const savedState = editing
        ? {
              title: 'Article updated',
              blurb: ' with its lifecycle state unchanged',
              action: 'Save changes',
          }
        : {
              title: 'Draft saved',
              blurb: ' as a draft, ready to send for review',
              action: 'Save draft',
          };

    const afterSave = (addAnother: boolean) => {
        toast.success(editing ? 'Article updated.' : `${savedState.title}.`);
        if (addAnother && !editing) {
            form.reset();
            wizard.goTo(0);
        } else {
            setDone(true);
        }
    };

    const submit = (addAnother = false) => {
        if (editing) {
            form.patch(`/it/kb/${article!.id}`, {
                preserveScroll: true,
                onSuccess: (page) => {
                    const err = pageFlashError(page);
                    if (err) {
                        toast.error(err);
                        return;
                    }
                    afterSave(addAnother);
                },
            });
        } else {
            form.post('/it/kb', {
                preserveScroll: true,
                onSuccess: (page) => {
                    const err = pageFlashError(page);
                    if (err) {
                        toast.error(err);
                        return;
                    }
                    afterSave(addAnother);
                },
            });
        }
    };

    return (
        <WizardShell
            open
            onClose={onClose}
            title={editing ? 'Edit article' : 'New KB article'}
            description="Write it once, deflect the ticket every time after."
            railIcon={BookOpen}
            railTitle="Knowledge"
            railSub="IT helpdesk"
            steps={KB_STEPS}
            stepIndex={wizard.index}
            onStepClick={wizard.goTo}
            pct={wizard.progress}
            success={
                done ? (
                    <WizardSuccessPane
                        title={editing ? 'Article saved' : savedState.title}
                        blurb={
                            <>
                                “{form.data.title}” is in the knowledge base
                                {savedState.blurb}.
                            </>
                        }
                        actions={<Button onClick={onClose}>Done</Button>}
                    />
                ) : undefined
            }
            footerStart={
                wizard.isFirst ? null : (
                    <Button variant="outline" onClick={wizard.back}>
                        Back
                    </Button>
                )
            }
            footerEnd={
                <>
                    <Button variant="ghost" onClick={onClose}>
                        Cancel
                    </Button>
                    {wizard.isLast ? (
                        <>
                            {!editing ? (
                                <Button
                                    variant="outline"
                                    onClick={() => submit(true)}
                                    disabled={
                                        form.processing ||
                                        !basicsValid ||
                                        !contentValid
                                    }
                                >
                                    Save & add another
                                </Button>
                            ) : null}
                            <Button
                                onClick={() => submit(false)}
                                disabled={
                                    form.processing ||
                                    !basicsValid ||
                                    !contentValid
                                }
                            >
                                {form.processing
                                    ? 'Saving…'
                                    : savedState.action}
                            </Button>
                        </>
                    ) : (
                        <Button
                            onClick={wizard.next}
                            disabled={
                                (wizard.index === 0 && !basicsValid) ||
                                (wizard.index === 1 &&
                                    form.data.audience === 'specific_sites' &&
                                    form.data.site_scope.length === 0) ||
                                (wizard.index === 2 && !contentValid)
                            }
                        >
                            Continue
                        </Button>
                    )}
                </>
            }
        >
            {wizard.index === 0 && (
                <WizardStepPane>
                    <StepHead
                        icon={FileText}
                        title="Basics"
                        blurb="What’s it about? New articles start as drafts."
                    />
                    <div className="grid gap-3.5">
                        <Field label="Title" required error={form.errors.title}>
                            <Input
                                value={form.data.title}
                                onChange={(e) =>
                                    form.setData('title', e.target.value)
                                }
                                placeholder="e.g. Reset your work password"
                                maxLength={255}
                            />
                        </Field>
                        <Field label="Category" error={form.errors.category}>
                            <TilePicker
                                value={form.data.category}
                                onChange={(v) => form.setData('category', v)}
                                options={[...CATEGORY_OPTIONS]}
                            />
                        </Field>
                    </div>
                </WizardStepPane>
            )}

            {wizard.index === 1 && (
                <WizardStepPane>
                    <StepHead
                        icon={Users}
                        title="Ownership & audience"
                        blurb="Keep the article accountable and show it only where it applies."
                    />
                    <div className="grid gap-4 lg:grid-cols-2">
                        <Field label="Audience" error={form.errors.audience}>
                            <SelectInput
                                value={form.data.audience}
                                onChange={(value) =>
                                    form.setData('audience', value)
                                }
                                placeholder="Choose who can find it"
                                options={[
                                    { value: 'all_staff', label: 'All staff' },
                                    {
                                        value: 'specific_sites',
                                        label: 'Only selected sites',
                                    },
                                    {
                                        value: 'it_agents',
                                        label: 'IT agents only',
                                    },
                                ]}
                            />
                        </Field>
                        <Field
                            label="Article owner"
                            error={form.errors.owner_user_id}
                        >
                            <SelectInput
                                value={form.data.owner_user_id || UNASSIGNED}
                                onChange={(value) =>
                                    form.setData(
                                        'owner_user_id',
                                        value === UNASSIGNED ? '' : value,
                                    )
                                }
                                placeholder="Choose an owner"
                                options={[
                                    {
                                        value: UNASSIGNED,
                                        label: 'Use me as owner',
                                    },
                                    ...options.owners.map((owner) => ({
                                        value: String(owner.id),
                                        label: owner.name,
                                    })),
                                ]}
                            />
                        </Field>
                        <Field
                            label="Related service"
                            error={form.errors.related_service_id}
                        >
                            <SelectInput
                                value={
                                    form.data.related_service_id || UNASSIGNED
                                }
                                onChange={(value) =>
                                    form.setData(
                                        'related_service_id',
                                        value === UNASSIGNED ? '' : value,
                                    )
                                }
                                placeholder="Choose a service"
                                options={[
                                    {
                                        value: UNASSIGNED,
                                        label: 'No related service',
                                    },
                                    ...options.services.map((service) => ({
                                        value: String(service.id),
                                        label: service.name,
                                    })),
                                ]}
                            />
                        </Field>
                        <Field
                            label="Review due"
                            error={form.errors.review_due_at}
                        >
                            <Input
                                type="date"
                                value={form.data.review_due_at}
                                onChange={(event) =>
                                    form.setData(
                                        'review_due_at',
                                        event.target.value,
                                    )
                                }
                            />
                        </Field>
                        {form.data.audience === 'specific_sites' ? (
                            <fieldset className="rounded-xl border border-border p-3 lg:col-span-2">
                                <legend className="px-1 text-sm font-medium">
                                    Sites that can find this article
                                </legend>
                                <div className="mt-2 grid gap-1 sm:grid-cols-2">
                                    {options.sites.map((site) => (
                                        <label
                                            key={site.id}
                                            className="flex min-h-11 items-center gap-2 rounded-lg px-2 text-sm hover:bg-muted/50"
                                        >
                                            <input
                                                type="checkbox"
                                                checked={form.data.site_scope.includes(
                                                    site.id,
                                                )}
                                                onChange={(event) =>
                                                    form.setData(
                                                        'site_scope',
                                                        event.target.checked
                                                            ? [
                                                                  ...form.data
                                                                      .site_scope,
                                                                  site.id,
                                                              ]
                                                            : form.data.site_scope.filter(
                                                                  (id) =>
                                                                      id !==
                                                                      site.id,
                                                              ),
                                                    )
                                                }
                                            />
                                            {site.name}
                                        </label>
                                    ))}
                                </div>
                                {form.errors.site_scope ? (
                                    <p className="mt-2 text-xs text-status-critical">
                                        {form.errors.site_scope}
                                    </p>
                                ) : null}
                            </fieldset>
                        ) : null}
                    </div>
                </WizardStepPane>
            )}

            {wizard.index === 2 && (
                <WizardStepPane>
                    <StepHead
                        icon={BookOpen}
                        title="Content"
                        blurb="Markdown on the left, live preview on the right."
                    />
                    <div className="grid gap-3 lg:grid-cols-2">
                        <Field
                            label="Article (markdown)"
                            required
                            error={form.errors.body}
                        >
                            <Textarea
                                value={form.data.body}
                                onChange={(e) =>
                                    form.setData('body', e.target.value)
                                }
                                placeholder={
                                    '# Steps\n1. Open the portal\n2. Click Forgot password\n\n- Check spam for the reset email'
                                }
                                rows={14}
                                className="font-mono text-[12.5px]"
                            />
                        </Field>
                        <div>
                            <div className="mb-1.5 text-[11.5px] font-semibold tracking-wide text-muted-foreground uppercase">
                                Preview
                            </div>
                            <div className="min-h-[20rem] rounded-xl border border-border bg-muted/30 p-3.5">
                                {form.data.body.trim() ? (
                                    <KbPreview body={form.data.body} />
                                ) : (
                                    <p className="text-[13px] text-muted-foreground">
                                        Nothing to preview yet.
                                    </p>
                                )}
                            </div>
                        </div>
                    </div>
                </WizardStepPane>
            )}

            {wizard.index === 3 && (
                <WizardStepPane>
                    <StepHead
                        icon={ClipboardCheck}
                        title="Review & save"
                        blurb="A quick check before it lands in the knowledge base."
                    />
                    <div className="grid gap-3 sm:grid-cols-2">
                        <ReviewCard
                            icon={FileText}
                            title="Basics"
                            onEdit={() => wizard.goTo(0)}
                        >
                            <ReviewRow label="Title" value={form.data.title} />
                            <ReviewRow
                                label="Category"
                                value={
                                    CATEGORY_OPTIONS.find(
                                        (c) => c.key === form.data.category,
                                    )?.label
                                }
                            />
                            <ReviewRow label="Lifecycle status" value="Draft" />
                        </ReviewCard>
                        <ReviewCard
                            icon={Users}
                            title="Ownership"
                            onEdit={() => wizard.goTo(1)}
                        >
                            <ReviewRow
                                label="Audience"
                                value={form.data.audience.replace(/_/g, ' ')}
                            />
                            <ReviewRow
                                label="Sites"
                                value={
                                    form.data.audience === 'specific_sites'
                                        ? `${form.data.site_scope.length} selected`
                                        : 'Not restricted by site'
                                }
                            />
                            <ReviewRow
                                label="Review due"
                                value={form.data.review_due_at || 'Not set'}
                            />
                        </ReviewCard>
                        <ReviewCard
                            icon={BookOpen}
                            title="Content"
                            onEdit={() => wizard.goTo(2)}
                        >
                            <ReviewRow
                                label="Length"
                                value={`${form.data.body.trim().length} characters`}
                            />
                        </ReviewCard>
                    </div>
                </WizardStepPane>
            )}
        </WizardShell>
    );
}

/* ================================================================== */
/*  Fulfil request (single step)                                      */
/* ================================================================== */

const FULFIL_STEPS: readonly WizardStep[] = [
    {
        key: 'fulfil',
        label: 'Fulfil request',
        blurb: 'Confirm it’s done',
        icon: CheckCircle2,
    },
];

function FulfilRequestDialog({
    request,
    onClose,
}: {
    request: RequestRow;
    onClose: () => void;
}) {
    const [done, setDone] = useState(false);

    const form = useForm({
        external_ref: request.external_ref ?? '',
        notes: request.notes ?? '',
        evidence_summary: request.evidence_summary ?? '',
    });

    const submit = () => {
        form.post(`/it/provisioning/${request.id}/fulfil`, {
            preserveScroll: true,
            onSuccess: (page) => {
                const err = pageFlashError(page);
                if (err) {
                    toast.error(err);
                    return;
                }
                setDone(true);
            },
        });
    };

    return (
        <WizardShell
            open
            onClose={onClose}
            title="Fulfil provisioning request"
            description={`Confirm “${request.item}” has been provisioned.`}
            railIcon={KeyRound}
            railTitle="Fulfil request"
            railSub="IT provisioning"
            steps={FULFIL_STEPS}
            stepIndex={0}
            onStepClick={() => undefined}
            maxHeight="min(78vh, 560px)"
            success={
                done ? (
                    <WizardSuccessPane
                        title="Request fulfilled"
                        blurb={
                            <>
                                “{request.item}” for {request.employee.name} is
                                done
                                {request.from_onboarding ? (
                                    <>
                                        {' '}
                                        — the linked onboarding task has been
                                        completed too
                                    </>
                                ) : null}
                                .
                            </>
                        }
                        actions={<Button onClick={onClose}>Done</Button>}
                    />
                ) : undefined
            }
            footerEnd={
                <>
                    <Button variant="ghost" onClick={onClose}>
                        Cancel
                    </Button>
                    <Button onClick={submit} disabled={form.processing}>
                        {form.processing ? 'Fulfilling…' : 'Mark fulfilled'}
                    </Button>
                </>
            }
        >
            <WizardStepPane>
                <StepHead
                    icon={CheckCircle2}
                    title={`Fulfil “${request.item}”`}
                    blurb={`Record how ${request.employee.name}’s request was provisioned.`}
                />
                <div className="grid gap-3.5">
                    {request.workflow ? (
                        <InfoCard icon={GitMerge}>
                            Stage {request.stage ?? 1} of the{' '}
                            {request.workflow.lifecycle_type.replace('_', ' ')}{' '}
                            workflow
                            {request.responsible_team
                                ? ` · owned by ${request.responsible_team.name}`
                                : ''}
                            .
                        </InfoCard>
                    ) : null}
                    {request.approval_required ? (
                        <InfoCard icon={UserCheck}>
                            Approval is{' '}
                            {request.approval_status === 'approved'
                                ? `recorded${request.approver ? ` by ${request.approver.name}` : ''}`
                                : 'required before fulfilment'}
                            .
                        </InfoCard>
                    ) : null}
                    {request.dependency_request_ids.length > 0 ? (
                        <InfoCard icon={GitMerge}>
                            Earlier workflow steps must be completed before this
                            step can be fulfilled.
                        </InfoCard>
                    ) : null}
                    {request.from_onboarding ? (
                        <InfoCard icon={ClipboardCheck}>
                            Fulfilling this request also completes the linked
                            onboarding task
                            {request.sign_off_required
                                ? ' and records you as the sign-off'
                                : ''}
                            .
                        </InfoCard>
                    ) : null}
                    <Field
                        label="External reference"
                        hint="optional — ticket id / account id"
                        error={form.errors.external_ref}
                    >
                        <Input
                            value={form.data.external_ref}
                            onChange={(e) =>
                                form.setData('external_ref', e.target.value)
                            }
                            placeholder="e.g. M365 user id, helpdesk #4821"
                            maxLength={255}
                        />
                    </Field>
                    <Field
                        label="Notes"
                        hint="optional"
                        error={form.errors.notes}
                    >
                        <Textarea
                            value={form.data.notes}
                            onChange={(e) =>
                                form.setData('notes', e.target.value)
                            }
                            placeholder="Anything worth recording about how this was set up…"
                            rows={4}
                        />
                    </Field>
                    {request.evidence_required ? (
                        <Field
                            label="Completion evidence"
                            hint="required — record what was checked or returned"
                            error={form.errors.evidence_summary}
                        >
                            <Textarea
                                value={form.data.evidence_summary}
                                onChange={(e) =>
                                    form.setData(
                                        'evidence_summary',
                                        e.target.value,
                                    )
                                }
                                placeholder="e.g. Account sign-in verified; laptop OF-104 returned and inspected"
                                rows={3}
                            />
                        </Field>
                    ) : null}
                    {Object.keys(request.fulfiller_context).length > 0 ? (
                        <div className="rounded-xl border border-border bg-muted/35 p-3">
                            <p className="text-xs font-semibold text-foreground">
                                Minimum details for fulfilment
                            </p>
                            <dl className="mt-2 grid gap-1.5 sm:grid-cols-2">
                                {Object.entries(request.fulfiller_context).map(
                                    ([key, value]) => (
                                        <div key={key}>
                                            <dt className="text-[10.5px] font-semibold tracking-wide text-muted-foreground uppercase">
                                                {key.replaceAll('_', ' ')}
                                            </dt>
                                            <dd className="text-xs text-foreground">
                                                {formatContextValue(value)}
                                            </dd>
                                        </div>
                                    ),
                                )}
                            </dl>
                        </div>
                    ) : null}
                </div>
            </WizardStepPane>
        </WizardShell>
    );
}

function formatContextValue(value: unknown): string {
    if (value == null || value === '') return '—';
    if (Array.isArray(value)) return value.map(formatContextValue).join(', ');
    if (typeof value !== 'object') return String(value);

    const record = value as Record<string, unknown>;
    if (typeof record.name === 'string') return record.name;
    if ('from' in record || 'to' in record) {
        return `${formatContextValue(record.from)} → ${formatContextValue(record.to)}`;
    }

    return Object.entries(record)
        .map(
            ([key, nested]) =>
                `${key.replaceAll('_', ' ')}: ${formatContextValue(nested)}`,
        )
        .join(' · ');
}

/* ================================================================== */
/*  Record request failure                                             */
/* ================================================================== */

const FAIL_STEPS: readonly WizardStep[] = [
    {
        key: 'failure',
        label: 'Record failure',
        blurb: 'Explain the blocker',
        icon: X,
    },
];

function FailRequestDialog({
    request,
    onClose,
}: {
    request: RequestRow;
    onClose: () => void;
}) {
    const [done, setDone] = useState(false);
    const form = useForm({ failure_reason: request.failure_reason ?? '' });

    const submit = () => {
        form.post(`/it/provisioning/${request.id}/fail`, {
            preserveScroll: true,
            onSuccess: (page) => {
                const err = pageFlashError(page);
                if (err) {
                    toast.error(err);
                    return;
                }
                setDone(true);
            },
        });
    };

    return (
        <WizardShell
            open
            onClose={onClose}
            title="Record provisioning failure"
            description={`Explain why “${request.item}” could not be completed.`}
            railIcon={X}
            railTitle="Failure"
            railSub="Provisioning workflow"
            steps={FAIL_STEPS}
            stepIndex={0}
            onStepClick={() => undefined}
            maxHeight="min(76vh, 520px)"
            success={
                done ? (
                    <WizardSuccessPane
                        title="Failure recorded"
                        blurb="The workflow is marked partially failed so the IT team can recover it without losing completed work."
                        actions={<Button onClick={onClose}>Done</Button>}
                    />
                ) : undefined
            }
            footerEnd={
                <>
                    <Button variant="ghost" onClick={onClose}>
                        Cancel
                    </Button>
                    <Button
                        variant="destructive"
                        onClick={submit}
                        disabled={
                            form.processing ||
                            form.data.failure_reason.trim().length < 3
                        }
                    >
                        {form.processing ? 'Recording…' : 'Record failure'}
                    </Button>
                </>
            }
        >
            <WizardStepPane>
                <StepHead
                    icon={X}
                    title="What prevented completion?"
                    blurb="Use a specific, operational reason. The employee’s private HR data is not included here."
                />
                <Field
                    label="Failure reason"
                    error={form.errors.failure_reason}
                >
                    <Textarea
                        value={form.data.failure_reason}
                        onChange={(event) =>
                            form.setData('failure_reason', event.target.value)
                        }
                        placeholder="e.g. Supplier has no stock; delivery is now expected on 24 July"
                        rows={5}
                        maxLength={2000}
                    />
                </Field>
            </WizardStepPane>
        </WizardShell>
    );
}

/* ================================================================== */
/*  Assign owner (single step, shared by requests & tickets)          */
/* ================================================================== */

const ASSIGN_STEPS: readonly WizardStep[] = [
    {
        key: 'assign',
        label: 'Pick an owner',
        blurb: 'Who works this?',
        icon: User,
    },
];

function initials(name: string): string {
    return name
        .split(/\s+/)
        .filter(Boolean)
        .slice(0, 2)
        .map((p) => p[0]?.toUpperCase() ?? '')
        .join('');
}

function AssignDialog({
    heading,
    subject,
    currentId,
    endpoint,
    assignees,
    onClose,
}: {
    heading: string;
    subject: string;
    currentId: number | null;
    endpoint: {
        method: 'post' | 'patch';
        url: string;
        field: string;
        ticketId?: number;
        expectedVersion?: number;
    };
    assignees: AssigneeOption[];
    onClose: () => void;
}) {
    const [done, setDone] = useState(false);
    const [search, setSearch] = useState('');
    const [expectedVersion, setExpectedVersion] = useState(
        endpoint.expectedVersion,
    );

    const form = useForm({
        [endpoint.field]: currentId != null ? String(currentId) : '',
        routing_reason: '',
    } as Record<string, string>);

    const pickedId = form.data[endpoint.field];
    const picked = assignees.find((a) => String(a.id) === pickedId) ?? null;

    const filtered = useMemo(() => {
        const q = search.trim().toLowerCase();
        if (!q) return assignees;
        return assignees.filter((a) => a.name.toLowerCase().includes(q));
    }, [search, assignees]);

    const submit = () => {
        form.transform((data) => ({
            [endpoint.field]: Number(data[endpoint.field]),
            ...(endpoint.ticketId !== undefined
                ? {
                      expected_version: expectedVersion,
                      routing_reason: data.routing_reason,
                  }
                : {}),
        }));
        const opts = {
            preserveScroll: true,
            onSuccess: (page: { props: Record<string, unknown> }) => {
                const err = pageFlashError(page);
                if (err) {
                    toast.error(err);
                    return;
                }
                setDone(true);
            },
        };
        if (endpoint.method === 'patch') {
            form.patch(endpoint.url, opts);
        } else {
            form.post(endpoint.url, opts);
        }
    };

    return (
        <WizardShell
            open
            onClose={onClose}
            title={heading}
            description={`Pick who owns “${subject}”.`}
            railIcon={UserCheck}
            railTitle={heading}
            railSub="IT & Support"
            steps={ASSIGN_STEPS}
            stepIndex={0}
            onStepClick={() => undefined}
            maxHeight="min(78vh, 600px)"
            success={
                done ? (
                    <WizardSuccessPane
                        title="Owner assigned"
                        blurb={
                            <>
                                “{subject}” is now with{' '}
                                {picked?.name ?? 'the new owner'}.
                            </>
                        }
                        actions={<Button onClick={onClose}>Done</Button>}
                    />
                ) : undefined
            }
            footerEnd={
                <>
                    <Button variant="ghost" onClick={onClose}>
                        Cancel
                    </Button>
                    <Button
                        onClick={submit}
                        disabled={
                            form.processing ||
                            !picked ||
                            (endpoint.ticketId !== undefined &&
                                !form.data.routing_reason.trim()) ||
                            !!form.errors.expected_version
                        }
                    >
                        {form.processing ? 'Assigning…' : 'Assign'}
                    </Button>
                </>
            }
        >
            <WizardStepPane>
                <StepHead
                    icon={User}
                    title="Who works this?"
                    blurb={`Pick the person taking ownership of “${subject}”.`}
                />
                <TicketVersionConflict
                    error={form.errors.expected_version}
                    ticketId={endpoint.ticketId ?? null}
                    onReviewed={(version) => {
                        setExpectedVersion(version);
                        form.clearErrors('expected_version');
                    }}
                />
                {endpoint.ticketId !== undefined && (
                    <Field
                        label="Reason for assignment"
                        required
                        error={form.errors.routing_reason}
                    >
                        <Textarea
                            value={form.data.routing_reason}
                            onChange={(event) =>
                                form.setData(
                                    'routing_reason',
                                    event.target.value,
                                )
                            }
                            rows={3}
                            maxLength={1000}
                            placeholder="Explain why this technician should take the work."
                        />
                    </Field>
                )}
                <FieldErr>{form.errors[endpoint.field]}</FieldErr>
                <div className="relative mb-3">
                    <Search className="pointer-events-none absolute top-1/2 left-2.5 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
                    <Input
                        value={search}
                        onChange={(e) => setSearch(e.target.value)}
                        placeholder="Search staff by name…"
                        className="pl-8"
                    />
                </div>
                <div className="flex max-h-72 flex-col gap-1.5 overflow-y-auto">
                    {filtered.map((a) => {
                        const active = String(a.id) === pickedId;
                        return (
                            // eslint-disable-next-line no-restricted-syntax -- selector card (avatar + name + check), not a Button case
                            <button
                                key={a.id}
                                type="button"
                                onClick={() =>
                                    form.setData(endpoint.field, String(a.id))
                                }
                                className={`flex items-center gap-3 rounded-xl border bg-card px-3 py-2.5 text-left transition-colors ${active ? 'border-primary bg-primary/5' : 'border-border hover:border-primary/50'}`}
                            >
                                <span className="grid h-9 w-9 flex-none place-items-center rounded-full bg-primary/10 text-[12.5px] font-bold text-primary">
                                    {initials(a.name)}
                                </span>
                                <span className="min-w-0 flex-1 truncate text-[13.5px] font-bold">
                                    {a.name}
                                </span>
                                {active ? (
                                    <CheckCircle2 className="h-5 w-5 shrink-0 text-primary" />
                                ) : null}
                            </button>
                        );
                    })}
                    {filtered.length === 0 ? (
                        <div className="py-6 text-center text-[13px] text-muted-foreground">
                            No staff match “{search}”.
                        </div>
                    ) : null}
                </div>
            </WizardStepPane>
        </WizardShell>
    );
}
