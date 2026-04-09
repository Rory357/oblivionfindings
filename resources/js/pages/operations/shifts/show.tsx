import ShiftFormsCard from '@/components/operations/shift-forms-card';
import ShiftMedicationCard from '@/components/operations/shift-medication-card';
import FleetHero from '@/components/fleet-hero';
import { ShiftStatusBadge } from '@/components/shift-status-badge';
import { TimesheetStatusBadge } from '@/components/timesheet-status-badge';
import { EligibilityStatusBadge, deriveEligibilityStatus } from '@/components/eligibility/eligibility-status-badge';
import { EligibilityAlertBanner } from '@/components/eligibility/eligibility-alert-banner';
import { OverrideConfirmationDialog } from '@/components/eligibility/override-confirmation-dialog';
import type { OverrideableWarning } from '@/components/eligibility/override-confirmation-dialog';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Dialog,
    DialogContent,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Textarea } from '@/components/ui/textarea';
import AppLayout from '@/layouts/app-layout';
import PageShell from '@/components/page-shell';
import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import { useMemo, useState } from 'react';
import { CalendarDays, Clock, MapPin, User, AlertTriangle, CheckCircle2, ArrowRight, FileText, Handshake, ClipboardCheck } from 'lucide-react';

type Task = {
    id: number;
    label: string;
    is_completed: boolean;
};

type Note = {
    id: number;
    type: string;
    occurred_at?: string | null;
    subject?: string | null;
    body?: string | null;
    meta?: any;
    actor?: { id: number; name: string } | null;
};

type Props = {
    shift: {
        id: number;
        client_id: number;
        service_context_id?: number | null;
        user_id: number | null;
        shift_series_id?: number | null;
        starts_at: string;
        ends_at: string;
        actual_starts_at?: string | null;
        actual_ends_at?: string | null;
        status: string;
        shift_type?: string | null;
        is_sleepover?: boolean;
        is_on_call?: boolean;
        expected_break_minutes?: number | null;
        location?: string | null;
        notes?: string | null;
        client: { id: number; first_name: string; last_name: string };
        staff: { id: number; name: string; email?: string } | null;
        service_context?: {
            id: number;
            name: string;
            type: string;
            is_active: boolean;
        } | null;
        tasks: Task[];
    };
    handover: Note[];
    notes: Note[];
    incidents: any[];
    incidentTemplates: any[];
    forms: {
        available: Array<{
            id: number;
            name: string;
            description?: string | null;
            form_type: string;
            schema: Array<{
                key?: string | null;
                label: string;
                type: string;
                required?: boolean;
                options?: string[];
            }>;
        }>;
        submissions: Array<{
            id: number;
            status: string;
            submitted_at?: string | null;
            data: Record<string, unknown>;
            submitter?: { id: number; name: string } | null;
            form?: { id: number; name: string; form_type: string } | null;
        }>;
    };
    medications: {
        stats?: {
            scheduled?: {
                completed?: number;
                due?: number;
                late?: number;
                missed?: number;
            };
        } | null;
        allergies?: Array<{
            id: number;
            allergen: string;
            reaction?: string | null;
            is_severe?: boolean;
        }>;
        due: any[];
        prn: any[];
        recent_history: Array<{
            id: number;
            medication_name: string;
            status: string;
            administered_at?: string | null;
            is_controlled?: boolean;
            is_prn?: boolean;
        }>;
    } | null;
    medicationWitnesses: Array<{ id: number; name: string }>;
    replacementRequest?: {
        id: number;
        status: string;
        reason: string;
        notes?: string | null;
        required_skills: string[];
        requested_at?: string | null;
        claimed_at?: string | null;
        approved_at?: string | null;
        cancelled_at?: string | null;
        requested_by?: { id: number; name: string } | null;
        current_staff?: { id: number; name: string } | null;
        replacement_staff?: { id: number; name: string } | null;
        approved_by?: { id: number; name: string } | null;
        cancelled_by?: { id: number; name: string } | null;
        is_active: boolean;
        open_position?: {
            id: number;
            status: string;
            expires_at?: string | null;
            claimed_by?: { id: number; name: string } | null;
            approved_by?: { id: number; name: string } | null;
        } | null;
    } | null;
    linkedTimesheet?: {
        id: number;
        status: string;
        work_date: string;
        starts_at: string;
        ends_at: string;
        exported_to_payroll_at?: string | null;
        payroll_reference?: string | null;
        reconciliation_status?: string | null;
    } | null;
    handoverSummary?: {
        id: number;
        status: string;
        incoming_staff_name?: string | null;
    } | null;
    transports: Array<{
        id: number;
        status: string;
        transport_type: string;
        resident_name?: string | null;
        pickup_location?: string | null;
        dropoff_location?: string | null;
        departed_at?: string | null;
        arrived_at?: string | null;
        asset?: { id: number; name: string; asset_tag?: string | null } | null;
        driver?: { id: number; name: string } | null;
    }>;
    assignmentCandidates?: Array<{
        id: number;
        name: string;
        email?: string | null;
        weekly_hours: number;
        site_familiarity?: number;
        client_consistency?: number;
        coverage_priority?: number;
        role_gap_priority?: number;
        coverage_fit_bonus?: number;
        role_coverage_bonus?: number;
        resolves_missing_staff?: boolean;
        resolves_role_gap?: boolean;
        recommended_score?: number;
        is_eligible: boolean;
        blocked_reasons: string[];
        warning_reasons: string[];
        required_roles?: Array<{ key: string; label: string; minimum: number }>;
        matched_roles?: Array<{ key: string; label: string; minimum: number }>;
        missing_roles?: string[];
        has_time_off: boolean;
        has_staff_conflict: boolean;
        has_compliance_block: boolean;
        has_tight_turnaround?: boolean;
    }>;
    coverage?: {
        site_id: number;
        site_name: string;
        rule_id?: number | null;
        coverage_state: string;
        planned_coverage_state?: string;
        gap_kind?: string | null;
        recommended_fill_action?: string | null;
        has_role_gap?: boolean;
        has_planned_role_gap?: boolean;
        has_actionable_gap?: boolean;
        contradictions?: string[];
        starts_at?: string | null;
        ends_at?: string | null;
        missing_staff: number;
        unfilled_after_open_shifts?: number;
        required_staff: number;
        assigned_staff: number;
        planned_staff?: number;
        open_shifts: number;
        preferred_client_id?: number | null;
        preferred_client_name?: string | null;
        role_shortages?: Array<{
            key: string;
            label: string;
            required: number;
            missing: number;
        }>;
        planned_role_shortages?: Array<{
            key: string;
            label: string;
            required: number;
            missing: number;
        }>;
        open_shift_ids?: number[];
        contributing_shifts?: Array<{
            id: number;
            client_id?: number | null;
            client_name: string;
            staff_name?: string | null;
            status: string;
            location?: string | null;
            starts_at?: string | null;
            ends_at?: string | null;
            shift_series_id?: number | null;
            is_open: boolean;
            coverage_roles?: string[];
        }>;
        matching_series?: Array<{
            id: number;
            client_id?: number | null;
            client_name?: string | null;
            staff_name?: string | null;
            service_context_name?: string | null;
            shift_type?: string | null;
            weekdays: string[];
            starts_time?: string | null;
            ends_time?: string | null;
            location?: string | null;
            next_starts_at?: string | null;
            active_occurrences_count?: number;
            open_occurrences_count?: number;
            coverage_roles?: string[];
        }>;
        recommended_fill_mode?: string;
        window_label: string;
        matching_rules: Array<{
            rule_name: string;
            required_staff: number;
            assigned_staff: number;
            open_shifts: number;
            missing_staff: number;
            planned_staff?: number;
            unfilled_after_open_shifts?: number;
            coverage_state: string;
            planned_coverage_state?: string;
            gap_kind?: string | null;
            role_shortages?: Array<{
                key: string;
                label: string;
                required: number;
                missing: number;
            }>;
            planned_role_shortages?: Array<{
                key: string;
                label: string;
                required: number;
                missing: number;
            }>;
            window_label: string;
        }>;
    } | null;
    can: {
        add_note: boolean;
        create_incident: boolean;
        view_forms: boolean;
        submit_form: boolean;
        view_medication: boolean;
        record_medication: boolean;
        request_replacement: boolean;
        cancel_replacement: boolean;
        assign_shift?: boolean;
        override_eligibility?: boolean;
        view_transport?: boolean;
    };
};

const templates = [
    { key: 'shift_note', label: 'Shift note', body: '' },
    {
        key: 'progress_note',
        label: 'Progress note',
        body: 'Goal/outcome:\n\nWhat happened:\n\nNext steps:',
    },
    {
        key: 'handover',
        label: 'Handover',
        body: 'Key points for next shift:\n-\n-\n\nRisks/alerts:\n-\n\nActions needed:\n-',
    },
];

function coverageRolesForAction(
    coverage:
        | NonNullable<Props['coverage']>
        | NonNullable<Props['coverage']>['matching_rules'][number],
) {
    return (
        (coverage.planned_role_shortages?.length
            ? coverage.planned_role_shortages
            : coverage.role_shortages) ?? []
    );
}

function gapKindLabel(kind?: string | null) {
    switch (kind) {
        case 'headcount_open':
            return 'Open shift gap';
        case 'headcount_unplanned':
            return 'Unplanned headcount gap';
        case 'role_open':
            return 'Open role gap';
        case 'role_unplanned':
            return 'Unplanned role gap';
        case 'mixed_open':
            return 'Open shift + role gap';
        case 'mixed_unplanned':
            return 'Headcount + role gap';
        case 'overfill_not_allowed':
            return 'Overfill not allowed';
        case 'overfilled_wrong_role_mix':
            return 'Overfilled role imbalance';
        case 'overfill_and_role_imbalance':
            return 'Overfill + role imbalance';
        default:
            return 'Coverage';
    }
}

function fillActionLabel(action?: string | null) {
    switch (action) {
        case 'fill_existing_open_shift':
            return 'Fill existing open shift';
        case 'retag_or_replace_open_shift':
            return 'Retag or replace open shift';
        case 'create_role_specific_shift':
            return 'Create role-specific cover';
        case 'create_recurring_cover':
            return 'Create recurring cover';
        case 'review_existing_supply':
            return 'Review existing supply';
        case 'rebalance_existing_supply':
            return 'Rebalance existing supply';
        default:
            return 'Create cover shift';
    }
}

function shouldOfferCreation(action?: string | null) {
    return !['review_existing_supply', 'rebalance_existing_supply'].includes(
        action ?? '',
    );
}

export default function ShiftShow({
    shift,
    handover,
    notes,
    incidents,
    incidentTemplates,
    forms,
    medications,
    medicationWitnesses,
    linkedTimesheet,
    handoverSummary,
    transports,
    replacementRequest,
    assignmentCandidates = [],
    coverage = null,
    can,
}: Props) {
    const { auth } = usePage().props as any;
    const canMarkTasks =
        auth?.can?.shifts?.update ||
        auth?.can?.shifts?.tasksUpdateSelf ||
        auth?.can?.shifts?.manageAny;
    const canActShift =
        auth?.can?.shifts?.update || auth?.can?.shifts?.manageAny;
    const canStartShift = canActShift && shift.status === 'scheduled';
    const canCompleteShift = canActShift && shift.status === 'in_progress';
    const [tasks, setTasks] = useState<Task[]>(shift.tasks ?? []);
    const [completeOpen, setCompleteOpen] = useState(() => {
        try {
            return (
                new URLSearchParams(window.location.search).get('complete') ===
                '1'
            );
        } catch {
            return false;
        }
    });
    // Override dialog state
    const [overrideOpen, setOverrideOpen] = useState(false);
    const [overrideCandidate, setOverrideCandidate] = useState<{
        id: number;
        name: string;
        warnings: OverrideableWarning[];
    } | null>(null);
    const [overrideProcessing, setOverrideProcessing] = useState(false);

    // Session-flashed eligibility data (persisted after failed assignment attempt)
    const pageProps = usePage().props as any;
    const flashedEligibility = pageProps.flash?.eligibility_result ?? null;
    const flashedWarnings: string[] = pageProps.flash?.assignment_warnings ?? [];

    const [incidentOpen, setIncidentOpen] = useState(false);
    const incidentForm = useForm({
        template_id: '',
        type: 'injury',
        severity: 'low',
        occurred_at: '',
        description: '',
        requires_followup: false,
        immediate_action_taken: '',
        witnesses: '',
    });

    const applyIncidentTemplate = (id: string) => {
        incidentForm.setData('template_id', id);
        const t = (incidentTemplates || []).find(
            (x: any) => String(x.id) === String(id),
        );
        if (!t) return;
        if (t.type) incidentForm.setData('type', t.type);
        if (t.severity) incidentForm.setData('severity', t.severity);
        if (t.default_description && !incidentForm.data.description)
            incidentForm.setData('description', t.default_description);
    };

    const name = `${shift.client.first_name} ${shift.client.last_name}`.trim();

    const incompleteCount = useMemo(
        () => tasks.filter((t) => !t.is_completed).length,
        [tasks],
    );
    const hasProgressOrShiftNotes = useMemo(
        () =>
            (notes ?? []).some(
                (n) => n.type === 'progress_note' || n.type === 'shift_note',
            ),
        [notes],
    );
    const outstandingMedicationCount = useMemo(
        () =>
            Number(medications?.stats?.scheduled?.due ?? 0) +
            Number(medications?.stats?.scheduled?.late ?? 0) +
            Number(medications?.stats?.scheduled?.missed ?? 0),
        [medications],
    );
    const availableFormCount = forms?.available?.length ?? 0;
    const submittedFormCount = forms?.submissions?.length ?? 0;
    const coverageReturnTo = `/operations/shifts/${shift.id}`;

    const completeForm = useForm<{
        final_note_subject: string;
        final_note_body: string;
        allow_incomplete_tasks: boolean;
        incomplete_tasks_reason: string;
        handover_waiver_reason: string;
    }>({
        final_note_subject: 'Shift summary',
        final_note_body: '',
        allow_incomplete_tasks: false,
        incomplete_tasks_reason: '',
        handover_waiver_reason: '',
    });

    const noteForm = useForm<{
        type: string;
        subject: string;
        goal: string;
        body: string;
        visibility: string;
        pin: boolean;
        shift_id: number;
    }>({
        type: 'shift_note',
        subject: '',
        goal: '',
        body: '',
        visibility: 'internal',
        pin: false,
        shift_id: shift.id,
    });
    const replacementForm = useForm<{
        reason: string;
        notes: string;
        required_skills_text: string;
        publish_to_job_board: boolean;
        expires_at: string;
    }>({
        reason: '',
        notes: '',
        required_skills_text: '',
        publish_to_job_board: true,
        expires_at: '',
    });

    const activeTemplate = useMemo(
        () => templates.find((t) => t.key === noteForm.data.type),
        [noteForm.data.type],
    );

    function getXsrfTokenFromCookie(): string | null {
        // Laravel sets an URL-encoded XSRF-TOKEN cookie. Using it avoids stale meta-tag CSRF issues in SPA navigations.
        const pair = document.cookie
            .split('; ')
            .find((row) => row.startsWith('XSRF-TOKEN='));
        if (!pair) return null;
        const value = pair.split('=')[1];
        if (!value) return null;
        try {
            return decodeURIComponent(value);
        } catch {
            return value;
        }
    }

    async function toggleTask(task: Task, next: boolean) {
        // optimistic
        setTasks((prev) =>
            prev.map((t) =>
                t.id === task.id ? { ...t, is_completed: next } : t,
            ),
        );
        try {
            const res = await fetch(
                `/operations/shifts/${shift.id}/tasks/${task.id}`,
                {
                    method: 'PATCH',
                    credentials: 'same-origin',
                    headers: {
                        'Content-Type': 'application/json',
                        Accept: 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                        // Prefer cookie-based XSRF token (kept in sync by Laravel) but fall back to meta tag.
                        ...(getXsrfTokenFromCookie()
                            ? {
                                  'X-XSRF-TOKEN':
                                      getXsrfTokenFromCookie() as string,
                              }
                            : {
                                  'X-CSRF-TOKEN': (
                                      document.querySelector(
                                          'meta[name="csrf-token"]',
                                      ) as HTMLMetaElement
                                  )?.content,
                              }),
                    },
                    body: JSON.stringify({ is_completed: next }),
                },
            );
            if (!res.ok) throw new Error('Request failed');
        } catch (e) {
            // revert
            setTasks((prev) =>
                prev.map((t) =>
                    t.id === task.id ? { ...t, is_completed: !next } : t,
                ),
            );
        }
    }

    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Shifts', href: '/shifts' },
                {
                    title: `${name} (${new Date(shift.starts_at).toLocaleDateString()})`,
                    href: `/operations/shifts/${shift.id}`,
                },
            ]}
        >
            <Head title={`Shift — ${name}`} />

            <PageShell>
                {/* Hero header */}
                <FleetHero
                    title={name}
                    description={new Date(shift.starts_at).toLocaleDateString([], { weekday: 'long', year: 'numeric', month: 'long', day: 'numeric' })}
                    icon={<CalendarDays className="h-7 w-7 text-white" />}
                    backHref="/shifts"
                    backLabel="All shifts"
                    stats={[
                        { label: 'Duration', value: (() => { const s = new Date(shift.starts_at).getTime(); const e = new Date(shift.ends_at).getTime(); return (Number.isNaN(s) || Number.isNaN(e) || e <= s) ? '—' : `${((e - s) / 3600000).toFixed(1)}h`; })() },
                        { label: 'Tasks', value: `${tasks.filter(t => t.is_completed).length}/${tasks.length}` },
                        { label: 'Notes', value: notes.length },
                    ]}
                    actions={
                        <ShiftStatusBadge status={shift.status} showIcon className="border-white/30 bg-white/10 text-white" />
                    }
                >
                    <div className="mt-4 flex flex-wrap items-center gap-x-4 gap-y-1 text-sm text-white/70">
                        <span className="inline-flex items-center gap-1">
                            <Clock className="h-3.5 w-3.5" />
                            {new Date(shift.starts_at).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })}
                            {' – '}
                            {new Date(shift.ends_at).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })}
                        </span>
                        {shift.location ? <span className="inline-flex items-center gap-1"><MapPin className="h-3.5 w-3.5" />{shift.location}</span> : null}
                        <span className="inline-flex items-center gap-1"><User className="h-3.5 w-3.5" />{shift.staff?.name ?? 'Unassigned'}</span>
                        {shift.service_context ? <Badge variant="outline" className="border-white/20 bg-white/10 text-white text-[10px]">{shift.service_context.name}</Badge> : null}
                        {shift.is_sleepover ? <Badge variant="outline" className="border-white/20 bg-white/10 text-white text-[10px]">Sleepover</Badge> : null}
                        {shift.is_on_call ? <Badge variant="outline" className="border-white/20 bg-white/10 text-white text-[10px]">On-call</Badge> : null}
                        {shift.shift_type ? <Badge variant="outline" className="border-white/20 bg-white/10 text-white text-[10px]">{shift.shift_type}</Badge> : null}
                        {shift.actual_starts_at ? (
                            <span className="text-white/50">
                                Actual: {new Date(shift.actual_starts_at).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })}
                                {shift.actual_ends_at ? `–${new Date(shift.actual_ends_at).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })}` : ''}
                            </span>
                        ) : null}
                    </div>
                </FleetHero>

                {/* Workflow guidance */}
                {shift.status === 'in_progress' ? (
                    <div className="flex items-center gap-3 rounded-xl border border-amber-500/30 bg-amber-500/10 p-4">
                        <AlertTriangle className="h-4 w-4 text-amber-500 shrink-0" />
                        <span className="text-sm text-amber-800 dark:text-amber-300">Shift is in progress. Complete the shift when finished — a timesheet will be created automatically.</span>
                    </div>
                ) : shift.status === 'scheduled' ? (
                    <div className="flex items-center gap-3 rounded-xl border border-blue-500/30 bg-blue-500/10 p-4">
                        <ArrowRight className="h-4 w-4 text-blue-500 shrink-0" />
                        <span className="text-sm text-blue-800 dark:text-blue-300">Shift is scheduled. Staff can clock in or start the shift when it begins.</span>
                    </div>
                ) : shift.status === 'cancelled' ? (
                    <div className="flex items-center gap-3 rounded-xl border border-red-500/30 bg-red-500/10 p-4">
                        <AlertTriangle className="h-4 w-4 text-red-500 shrink-0" />
                        <span className="text-sm text-red-800 dark:text-red-300">This shift has been cancelled. Downstream records may have been affected.</span>
                    </div>
                ) : null}

                {/* Action bar */}
                <div className="flex flex-wrap items-center gap-2">
                    {canStartShift ? (
                        <Button onClick={() => router.patch(`/operations/shifts/${shift.id}/start`, {}, { preserveScroll: true })}>
                            Start shift
                        </Button>
                    ) : null}
                    {canCompleteShift ? (
                        <Button variant={canStartShift ? 'outline' : 'default'} onClick={() => setCompleteOpen(true)}>
                            Complete shift
                        </Button>
                    ) : null}
                    {can.create_incident ? (
                        <Button variant="outline" onClick={() => setIncidentOpen(true)}>
                            Report incident
                        </Button>
                    ) : null}
                    {(auth?.can?.timesheets?.create || auth?.can?.timesheets?.manageAny) ? (
                        <Button variant="outline" asChild>
                            <Link href={`/operations/timesheets/create?shift_id=${shift.id}`}>
                                Create timesheet
                            </Link>
                        </Button>
                    ) : null}
                    {auth?.can?.shifts?.update ? (
                        <Button variant="ghost" asChild>
                            <Link href={`/operations/shifts/${shift.id}/edit`}>Edit</Link>
                        </Button>
                    ) : null}
                    {auth?.can?.shifts?.manageAny && shift.status !== 'completed' && shift.status !== 'cancelled' ? (
                        <Button variant="outline" onClick={() => router.patch(`/operations/shifts/${shift.id}/cancel`, {}, { preserveScroll: true })}>
                            Cancel occurrence
                        </Button>
                    ) : null}
                    {auth?.can?.shifts?.manageAny && shift.status === 'cancelled' ? (
                        <Button variant="outline" onClick={() => router.patch(`/operations/shifts/${shift.id}/reopen`, {}, { preserveScroll: true })}>
                            Reopen occurrence
                        </Button>
                    ) : null}
                    {shift.shift_series_id ? (
                        <Button variant="ghost" asChild>
                            <Link href={`/operations/shifts/series/${shift.shift_series_id}`}>Recurring series</Link>
                        </Button>
                    ) : null}
                </div>

                {/* Integration cards */}
                <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                    <Card className="transition-shadow hover:shadow-md">
                        <CardHeader className="pb-2">
                            <CardTitle className="flex items-center gap-2 text-[10px] font-medium uppercase tracking-wider text-muted-foreground">
                                <FileText className="h-3.5 w-3.5" />
                                Linked Timesheet
                            </CardTitle>
                        </CardHeader>
                        <CardContent>
                            {linkedTimesheet ? (
                                <div className="space-y-1">
                                    <div className="flex items-center gap-2">
                                        <Link href={`/operations/timesheets/${linkedTimesheet.id}/edit`} className="font-medium underline text-sm">
                                            Timesheet #{linkedTimesheet.id}
                                        </Link>
                                        <TimesheetStatusBadge status={linkedTimesheet.status} />
                                    </div>
                                    <p className="text-xs text-muted-foreground">
                                        {new Date(linkedTimesheet.starts_at).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })}
                                        {' – '}
                                        {new Date(linkedTimesheet.ends_at).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })}
                                    </p>
                                    {linkedTimesheet.exported_to_payroll_at ? (
                                        <Badge variant="outline" className="border-emerald-500/30 text-emerald-400 bg-emerald-500/10 text-[10px]">Exported to payroll</Badge>
                                    ) : null}
                                </div>
                            ) : (
                                <p className="text-sm text-muted-foreground">No timesheet linked yet.</p>
                            )}
                        </CardContent>
                    </Card>

                    <Card className="transition-shadow hover:shadow-md">
                        <CardHeader className="pb-2">
                            <CardTitle className="flex items-center gap-2 text-[10px] font-medium uppercase tracking-wider text-muted-foreground">
                                <Handshake className="h-3.5 w-3.5" />
                                Handover
                            </CardTitle>
                        </CardHeader>
                        <CardContent>
                            {handoverSummary ? (
                                <div className="space-y-1">
                                    <Badge variant="outline" className={
                                        handoverSummary.status === 'acknowledged' ? 'border-emerald-500/30 text-emerald-400 bg-emerald-500/10' :
                                        handoverSummary.status === 'submitted' ? 'border-yellow-500/30 text-yellow-400 bg-yellow-500/10' :
                                        'border-slate-500/30 text-slate-400 bg-slate-500/10'
                                    }>
                                        {handoverSummary.status.charAt(0).toUpperCase() + handoverSummary.status.slice(1)}
                                    </Badge>
                                    {handoverSummary.incoming_staff_name ? (
                                        <p className="text-xs text-muted-foreground">Incoming: {handoverSummary.incoming_staff_name}</p>
                                    ) : null}
                                </div>
                            ) : (
                                <p className="text-sm text-muted-foreground">No handover required.</p>
                            )}
                        </CardContent>
                    </Card>

                    <Card className="transition-shadow hover:shadow-md">
                        <CardHeader className="pb-2">
                            <CardTitle className="flex items-center gap-2 text-[10px] font-medium uppercase tracking-wider text-muted-foreground">
                                <ClipboardCheck className="h-3.5 w-3.5" />
                                Task Progress
                            </CardTitle>
                        </CardHeader>
                        <CardContent>
                            {tasks.length > 0 ? (
                                <div className="space-y-2">
                                    <div className="flex items-baseline gap-2">
                                        <span className="text-2xl font-bold tabular-nums">{tasks.filter(t => t.is_completed).length}</span>
                                        <span className="text-sm text-muted-foreground">/ {tasks.length} completed</span>
                                    </div>
                                    <div className="h-1.5 w-full rounded-full bg-muted">
                                        <div className="h-full rounded-full bg-primary transition-all duration-300" style={{ width: `${(tasks.filter(t => t.is_completed).length / tasks.length) * 100}%` }} />
                                    </div>
                                </div>
                            ) : (
                                <p className="text-sm text-muted-foreground">No tasks assigned.</p>
                            )}
                        </CardContent>
                    </Card>
                </div>

                <Card className="border-primary/10">
                    <CardHeader>
                        <CardTitle className="text-base">
                            Operational Summary
                        </CardTitle>
                    </CardHeader>
                    <CardContent className="grid gap-3 md:grid-cols-4">
                        <div className="rounded-lg border p-3">
                            <div className="text-[10px] font-medium text-muted-foreground uppercase tracking-wider">
                                Shift type
                            </div>
                            <div className="mt-1 text-sm font-semibold">
                                {(shift.shift_type ?? 'standard')
                                    .split('_')
                                    .join(' ')}
                            </div>
                        </div>
                        <div className="rounded-md border p-3">
                            <div className="text-xs text-muted-foreground uppercase">
                                Break
                            </div>
                            <div className="mt-1 text-sm font-medium">
                                {shift.expected_break_minutes != null
                                    ? `${shift.expected_break_minutes} min`
                                    : 'Not set'}
                            </div>
                        </div>
                        <div className="rounded-md border p-3">
                            <div className="text-xs text-muted-foreground uppercase">
                                Pay flags
                            </div>
                            <div className="mt-1 text-sm font-medium">
                                {shift.is_sleepover || shift.is_on_call
                                    ? [
                                          shift.is_sleepover
                                              ? 'Sleepover'
                                              : null,
                                          shift.is_on_call ? 'On-call' : null,
                                      ]
                                          .filter(Boolean)
                                          .join(', ')
                                    : 'Standard'}
                            </div>
                        </div>
                        <div className="rounded-md border p-3">
                            <div className="text-xs text-muted-foreground uppercase">
                                Workflow readiness
                            </div>
                            <div className="mt-1 text-sm font-medium">
                                {outstandingMedicationCount > 0
                                    ? `${outstandingMedicationCount} medication item(s) pending`
                                    : 'No medication alerts'}
                            </div>
                            <div className="mt-1 text-xs text-muted-foreground">
                                {availableFormCount > 0
                                    ? `${submittedFormCount}/${availableFormCount} shift form(s) submitted`
                                    : 'No active shift forms'}
                            </div>
                        </div>
                    </CardContent>
                </Card>

                {coverage ? (
                    <Card>
                        <CardHeader>
                            <CardTitle className="text-base">
                                Site coverage
                            </CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-3">
                            <div className="flex flex-wrap items-center justify-between gap-3">
                                <div>
                                    <div className="text-sm font-medium">
                                        {coverage.site_name}
                                    </div>
                                    <div className="text-xs text-muted-foreground">
                                        {coverage.window_label}
                                    </div>
                                </div>
                                <Badge
                                    variant={
                                        coverage.has_actionable_gap
                                            ? 'destructive'
                                            : coverage.coverage_state === 'over'
                                              ? 'outline'
                                              : 'secondary'
                                    }
                                >
                                    {coverage.has_actionable_gap
                                        ? gapKindLabel(coverage.gap_kind)
                                        : coverage.coverage_state === 'over'
                                          ? 'Over-covered'
                                          : 'Exact coverage'}
                                </Badge>
                            </div>

                            <div className="grid gap-3 sm:grid-cols-3">
                                <div className="rounded-md border p-3">
                                    <div className="text-xs text-muted-foreground uppercase">
                                        Required
                                    </div>
                                    <div className="mt-1 text-sm font-medium">
                                        {coverage.required_staff}
                                    </div>
                                </div>
                                <div className="rounded-md border p-3">
                                    <div className="text-xs text-muted-foreground uppercase">
                                        Assigned
                                    </div>
                                    <div className="mt-1 text-sm font-medium">
                                        {coverage.assigned_staff}
                                    </div>
                                </div>
                                <div className="rounded-md border p-3">
                                    <div className="text-xs text-muted-foreground uppercase">
                                        Open shifts
                                    </div>
                                    <div className="mt-1 text-sm font-medium">
                                        {coverage.open_shifts}
                                    </div>
                                </div>
                            </div>

                            <div className="rounded-md border p-3">
                                <div className="text-xs text-muted-foreground uppercase">
                                    Planned supply after open shifts
                                </div>
                                <div className="mt-1 text-sm font-medium">
                                    {coverage.planned_staff ??
                                        coverage.assigned_staff}{' '}
                                    planned
                                </div>
                                <div className="mt-1 text-xs text-muted-foreground">
                                    {coverage.recommended_fill_action ===
                                    'fill_existing_open_shift'
                                        ? 'Demand is already represented by open shifts. Fill one of those shifts rather than creating another.'
                                        : coverage.recommended_fill_action ===
                                            'retag_or_replace_open_shift'
                                          ? 'An open shift already exists, but it is not carrying the right role demand. Retag it or create a role-specific cover shift.'
                                          : coverage.unfilled_after_open_shifts &&
                                              coverage.unfilled_after_open_shifts >
                                                  0
                                            ? `${coverage.unfilled_after_open_shifts} more shift slot(s) still need to be created or reopened.`
                                            : coverage.has_planned_role_gap
                                              ? 'Planned supply exists, but the required role mix is still not covered.'
                                              : coverage.open_shifts > 0
                                                ? 'Open shifts already exist for the remaining demand in this window.'
                                                : 'Current planned shifts cover this demand window.'}
                                </div>
                            </div>

                            {coverageRolesForAction(coverage).length > 0 ? (
                                <div className="flex flex-wrap gap-2">
                                    {coverageRolesForAction(coverage).map(
                                        (role) => (
                                            <Badge
                                                key={`coverage-role-${role.key}`}
                                                variant="outline"
                                            >
                                                {role.label} still needed x
                                                {role.missing}
                                            </Badge>
                                        ),
                                    )}
                                </div>
                            ) : null}

                            {coverage.contradictions &&
                            coverage.contradictions.length > 0 ? (
                                <div className="flex flex-wrap gap-2">
                                    {coverage.contradictions.map((issue) => (
                                        <Badge
                                            key={`coverage-issue-${issue}`}
                                            variant="outline"
                                        >
                                            {issue ===
                                            'headcount_exact_but_role_gap'
                                                ? 'Headcount looks full but role demand is still short'
                                                : issue ===
                                                    'partial_window_undercoverage'
                                                  ? 'Coverage drops away inside the window and needs partial backfill'
                                                  : issue ===
                                                      'planned_supply_exact_but_role_gap'
                                                    ? 'Planned supply still misses the required role mix'
                                                    : issue ===
                                                        'preferred_client_drift'
                                                      ? 'Preferred client context has drifted'
                                                      : issue ===
                                                          'overfill_not_allowed'
                                                        ? 'This window is overstaffed beyond the allowed limit'
                                                        : issue ===
                                                            'overfilled_but_wrong_role_mix'
                                                          ? 'This window is overfilled but still has the wrong role mix'
                                                          : issue}
                                        </Badge>
                                    ))}
                                </div>
                            ) : null}

                            <div className="space-y-2">
                                {coverage.matching_rules.map((rule) => (
                                    <div
                                        key={`${rule.rule_name}-${rule.window_label}`}
                                        className="rounded-md border p-3"
                                    >
                                        <div className="flex items-center justify-between gap-3">
                                            <div className="text-sm font-medium">
                                                {rule.rule_name}
                                            </div>
                                            <Badge
                                                variant={
                                                    rule.coverage_state ===
                                                    'under'
                                                        ? 'destructive'
                                                        : 'outline'
                                                }
                                            >
                                                Need {rule.required_staff}
                                            </Badge>
                                        </div>
                                        <div className="mt-1 text-xs text-muted-foreground">
                                            Assigned {rule.assigned_staff}
                                            {rule.open_shifts > 0
                                                ? ` · ${rule.open_shifts} open shift(s)`
                                                : ''}
                                            {rule.unfilled_after_open_shifts &&
                                            rule.unfilled_after_open_shifts > 0
                                                ? ` · ${rule.unfilled_after_open_shifts} still unplanned`
                                                : ''}
                                        </div>
                                    </div>
                                ))}
                            </div>

                            {coverage.contributing_shifts &&
                            coverage.contributing_shifts.length > 0 ? (
                                <div className="space-y-2">
                                    <div className="text-sm font-medium">
                                        Existing supply in this window
                                    </div>
                                    {coverage.contributing_shifts.map(
                                        (existingShift) => (
                                            <div
                                                key={existingShift.id}
                                                className="rounded-md border p-3"
                                            >
                                                <div className="flex flex-wrap items-start justify-between gap-2">
                                                    <div>
                                                        <div className="text-sm font-medium">
                                                            {
                                                                existingShift.client_name
                                                            }
                                                        </div>
                                                        <div className="mt-1 text-xs text-muted-foreground">
                                                            {existingShift.starts_at &&
                                                            existingShift.ends_at
                                                                ? `${new Date(
                                                                      existingShift.starts_at,
                                                                  ).toLocaleTimeString(
                                                                      [],
                                                                      {
                                                                          hour: '2-digit',
                                                                          minute: '2-digit',
                                                                      },
                                                                  )}-${new Date(
                                                                      existingShift.ends_at,
                                                                  ).toLocaleTimeString(
                                                                      [],
                                                                      {
                                                                          hour: '2-digit',
                                                                          minute: '2-digit',
                                                                      },
                                                                  )}`
                                                                : 'Time not set'}
                                                            {existingShift.location
                                                                ? ` · ${existingShift.location}`
                                                                : ''}
                                                            {existingShift.staff_name
                                                                ? ` · ${existingShift.staff_name}`
                                                                : ' · Unassigned'}
                                                        </div>
                                                    </div>
                                                    <div className="flex flex-wrap gap-2">
                                                        <Badge
                                                            variant={
                                                                existingShift.is_open
                                                                    ? 'outline'
                                                                    : 'secondary'
                                                            }
                                                        >
                                                            {existingShift.is_open
                                                                ? 'Open shift'
                                                                : existingShift.status}
                                                        </Badge>
                                                        {existingShift.coverage_roles &&
                                                        existingShift
                                                            .coverage_roles
                                                            .length > 0 ? (
                                                            <Badge variant="outline">
                                                                {existingShift.coverage_roles
                                                                    .map(
                                                                        (
                                                                            role,
                                                                        ) =>
                                                                            role.replace(
                                                                                /_/g,
                                                                                ' ',
                                                                            ),
                                                                    )
                                                                    .join(', ')}
                                                            </Badge>
                                                        ) : null}
                                                        <Button
                                                            size="sm"
                                                            variant="outline"
                                                            asChild
                                                        >
                                                            <Link
                                                                href={`/operations/shifts/${existingShift.id}`}
                                                            >
                                                                Open shift
                                                            </Link>
                                                        </Button>
                                                    </div>
                                                </div>
                                            </div>
                                        ),
                                    )}
                                </div>
                            ) : null}

                            {coverage.matching_series &&
                            coverage.matching_series.length > 0 ? (
                                <div className="space-y-2">
                                    <div className="text-sm font-medium">
                                        Recurring supply linked to this demand
                                    </div>
                                    {coverage.matching_series.map((series) => (
                                        <div
                                            key={series.id}
                                            className="rounded-md border p-3"
                                        >
                                            <div className="flex flex-wrap items-start justify-between gap-2">
                                                <div>
                                                    <div className="text-sm font-medium">
                                                        {series.client_name ??
                                                            'Recurring series'}
                                                    </div>
                                                    <div className="mt-1 text-xs text-muted-foreground">
                                                        {series.weekdays.join(
                                                            ', ',
                                                        )}{' '}
                                                        · {series.starts_time}-
                                                        {series.ends_time}
                                                        {series.location
                                                            ? ` · ${series.location}`
                                                            : ''}
                                                    </div>
                                                </div>
                                                <Button
                                                    size="sm"
                                                    variant="outline"
                                                    asChild
                                                >
                                                    <Link
                                                        href={`/operations/shifts/series/${series.id}`}
                                                    >
                                                        Open series
                                                    </Link>
                                                </Button>
                                            </div>
                                        </div>
                                    ))}
                                </div>
                            ) : null}

                            <div className="flex flex-wrap gap-2">
                                {coverage.open_shift_ids &&
                                coverage.open_shift_ids.length > 0 ? (
                                    <Button size="sm" variant="outline" asChild>
                                        <Link
                                            href={`/operations/shifts/${coverage.open_shift_ids[0]}`}
                                        >
                                            Open existing cover shift
                                        </Link>
                                    </Button>
                                ) : null}
                                {coverage.has_actionable_gap &&
                                shouldOfferCreation(
                                    coverage.recommended_fill_action,
                                ) &&
                                coverage.starts_at &&
                                coverage.ends_at ? (
                                    <>
                                        <Button size="sm" asChild>
                                            <Link
                                                href={`/operations/shifts/create?site_id=${coverage.site_id}&coverage_rule_id=${encodeURIComponent(String(coverage.rule_id ?? ''))}&client_id=${encodeURIComponent(String(coverage.preferred_client_id ?? ''))}&starts_at=${encodeURIComponent(coverage.starts_at)}&ends_at=${encodeURIComponent(coverage.ends_at)}&coverage_rule_name=${encodeURIComponent(coverage.window_label)}&coverage_required_staff=${coverage.required_staff}&coverage_missing_staff=${coverage.missing_staff}&coverage_role_shortages=${encodeURIComponent(JSON.stringify(coverageRolesForAction(coverage)))}&return_to=${encodeURIComponent(coverageReturnTo)}`}
                                            >
                                                {fillActionLabel(
                                                    coverage.recommended_fill_action,
                                                )}
                                            </Link>
                                        </Button>
                                        <Button
                                            size="sm"
                                            variant="outline"
                                            asChild
                                        >
                                            <Link
                                                href={`/operations/shifts/create?site_id=${coverage.site_id}&coverage_rule_id=${encodeURIComponent(String(coverage.rule_id ?? ''))}&client_id=${encodeURIComponent(String(coverage.preferred_client_id ?? ''))}&starts_at=${encodeURIComponent(coverage.starts_at)}&ends_at=${encodeURIComponent(coverage.ends_at)}&open_shift=1&coverage_rule_name=${encodeURIComponent(coverage.window_label)}&coverage_required_staff=${coverage.required_staff}&coverage_missing_staff=${coverage.missing_staff}&coverage_role_shortages=${encodeURIComponent(JSON.stringify(coverageRolesForAction(coverage)))}&return_to=${encodeURIComponent(coverageReturnTo)}`}
                                            >
                                                Create open shift
                                            </Link>
                                        </Button>
                                        <Button
                                            size="sm"
                                            variant="outline"
                                            asChild
                                        >
                                            <Link
                                                href={`/operations/shifts/create?site_id=${coverage.site_id}&coverage_rule_id=${encodeURIComponent(String(coverage.rule_id ?? ''))}&client_id=${encodeURIComponent(String(coverage.preferred_client_id ?? ''))}&starts_at=${encodeURIComponent(coverage.starts_at)}&ends_at=${encodeURIComponent(coverage.ends_at)}&repeat_weekly=1&repeat_end_date=${encodeURIComponent(new Date(new Date(coverage.starts_at).getTime() + 1000 * 60 * 60 * 24 * 28).toISOString().slice(0, 10))}&open_shift=1&coverage_rule_name=${encodeURIComponent(coverage.window_label)}&coverage_required_staff=${coverage.required_staff}&coverage_missing_staff=${coverage.missing_staff}&coverage_role_shortages=${encodeURIComponent(JSON.stringify(coverageRolesForAction(coverage)))}&return_to=${encodeURIComponent(coverageReturnTo)}`}
                                            >
                                                Create recurring cover
                                            </Link>
                                        </Button>
                                    </>
                                ) : null}
                            </div>
                        </CardContent>
                    </Card>
                ) : null}

                {can.assign_shift ? (
                    <Card>
                        <CardHeader>
                            <CardTitle className="text-base">
                                Assignment coverage
                            </CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-3">
                            <div className="text-sm text-muted-foreground">
                                Recommended staff are ranked by availability,
                                current weekly hours, compliance state, and the
                                urgency of any uncovered site demand linked to
                                this shift.
                            </div>

                            {/* Persistent alert banner for session-flashed eligibility failures */}
                            {flashedEligibility && !flashedEligibility.is_eligible ? (
                                <EligibilityAlertBanner
                                    type="blocked"
                                    reasons={flashedEligibility.blocked_reasons ?? []}
                                />
                            ) : null}
                            {flashedWarnings.length > 0 && (!flashedEligibility || flashedEligibility.is_eligible) ? (
                                <EligibilityAlertBanner
                                    type="warnings"
                                    reasons={flashedWarnings}
                                    title="Assignment warnings"
                                />
                            ) : null}

                            {assignmentCandidates.length === 0 ? (
                                <div className="rounded-md border border-dashed p-3 text-sm text-muted-foreground">
                                    No assignment recommendations available for
                                    this shift.
                                </div>
                            ) : (
                                assignmentCandidates.map((candidate) => {
                                    const { status: eligStatus, warningCount } = deriveEligibilityStatus({
                                        is_eligible: candidate.is_eligible,
                                        blocked_reasons: candidate.blocked_reasons,
                                        warning_reasons: candidate.warning_reasons,
                                    });
                                    const isAlreadyAssigned = shift.user_id === candidate.id;
                                    const hasOverrideableWarnings = candidate.is_eligible
                                        && candidate.warning_reasons.length > 0
                                        && can.override_eligibility;

                                    return (
                                    <div
                                        key={candidate.id}
                                        className="rounded-md border p-3"
                                    >
                                        <div className="flex flex-wrap items-start justify-between gap-3">
                                            <div>
                                                <div className="text-sm font-medium">
                                                    {candidate.name}
                                                </div>
                                                <div className="text-xs text-muted-foreground">
                                                    {candidate.email ??
                                                        'No email'}
                                                    {' · '}
                                                    {candidate.weekly_hours.toFixed(
                                                        1,
                                                    )}{' '}
                                                    hrs this week
                                                </div>
                                                <div className="mt-1 text-xs text-muted-foreground">
                                                    {candidate.site_familiarity ??
                                                        0}{' '}
                                                    recent site shift(s)
                                                    {' · '}
                                                    {candidate.client_consistency ??
                                                        0}{' '}
                                                    recent client shift(s)
                                                </div>
                                            </div>

                                            <div className="flex flex-wrap gap-2">
                                                <EligibilityStatusBadge
                                                    status={eligStatus}
                                                    warningCount={warningCount}
                                                />
                                                {candidate.has_tight_turnaround ? (
                                                    <Badge variant="outline">
                                                        Tight turnaround
                                                    </Badge>
                                                ) : null}
                                                {candidate.recommended_score !=
                                                null ? (
                                                    <Badge variant="outline">
                                                        Score{' '}
                                                        {
                                                            candidate.recommended_score
                                                        }
                                                    </Badge>
                                                ) : null}
                                                {candidate.required_roles &&
                                                candidate.required_roles
                                                    .length > 0 ? (
                                                    <Badge variant="outline">
                                                        {candidate.matched_roles
                                                            ?.length ?? 0}
                                                        /
                                                        {
                                                            candidate
                                                                .required_roles
                                                                .length
                                                        }{' '}
                                                        role matches
                                                    </Badge>
                                                ) : null}
                                                {candidate.resolves_missing_staff ? (
                                                    <Badge variant="outline">
                                                        Closes coverage gap
                                                    </Badge>
                                                ) : null}
                                                {candidate.resolves_role_gap ? (
                                                    <Badge variant="outline">
                                                        Closes role gap
                                                    </Badge>
                                                ) : null}
                                            </div>
                                        </div>

                                        {candidate.blocked_reasons.length >
                                        0 ? (
                                            <div className="mt-2 space-y-1 text-xs text-red-700 dark:text-red-400">
                                                {candidate.blocked_reasons.map(
                                                    (reason) => (
                                                        <div key={reason}>
                                                            {reason}
                                                        </div>
                                                    ),
                                                )}
                                            </div>
                                        ) : null}

                                        {candidate.warning_reasons.length >
                                        0 ? (
                                            <div className="mt-2 space-y-1 text-xs text-amber-700 dark:text-amber-400">
                                                {candidate.warning_reasons.map(
                                                    (reason) => (
                                                        <div key={reason}>
                                                            {reason}
                                                        </div>
                                                    ),
                                                )}
                                            </div>
                                        ) : null}

                                        {candidate.required_roles &&
                                        candidate.required_roles.length > 0 ? (
                                            <div className="mt-2 text-xs text-muted-foreground">
                                                Required roles:{' '}
                                                {candidate.required_roles
                                                    .map((role) => role.label)
                                                    .join(', ')}
                                            </div>
                                        ) : null}

                                        {candidate.coverage_fit_bonus &&
                                        candidate.coverage_fit_bonus > 0 ? (
                                            <div className="mt-2 text-xs text-emerald-700">
                                                Familiarity bonus applied
                                                because this shift sits in an
                                                under-covered house window.
                                            </div>
                                        ) : null}

                                        <div className="mt-3 flex flex-wrap gap-2">
                                            {/* Clean pass or already assigned: direct assign */}
                                            {!hasOverrideableWarnings ? (
                                                <Button
                                                    size="sm"
                                                    variant={isAlreadyAssigned ? 'outline' : 'default'}
                                                    disabled={!candidate.is_eligible || isAlreadyAssigned}
                                                    onClick={() =>
                                                        router.post(
                                                            `/operations/shifts/${shift.id}/assign`,
                                                            {
                                                                user_id: candidate.id,
                                                                return_to: `/operations/shifts/${shift.id}`,
                                                            },
                                                            { preserveScroll: true },
                                                        )
                                                    }
                                                >
                                                    {isAlreadyAssigned ? 'Assigned' : 'Assign'}
                                                </Button>
                                            ) : (
                                                /* Has overrideable warnings: open dialog first */
                                                <Button
                                                    size="sm"
                                                    disabled={isAlreadyAssigned}
                                                    variant={isAlreadyAssigned ? 'outline' : 'default'}
                                                    className={!isAlreadyAssigned ? 'bg-yellow-600 hover:bg-yellow-700 dark:bg-yellow-700 dark:hover:bg-yellow-600' : ''}
                                                    onClick={() => {
                                                        setOverrideCandidate({
                                                            id: candidate.id,
                                                            name: candidate.name,
                                                            warnings: candidate.warning_reasons.map((msg) => ({
                                                                rule: 'unknown',
                                                                message: msg,
                                                                overrideable: true,
                                                            })),
                                                        });
                                                        setOverrideOpen(true);
                                                    }}
                                                >
                                                    {isAlreadyAssigned ? 'Assigned' : 'Assign with override'}
                                                </Button>
                                            )}
                                        </div>
                                    </div>
                                    );
                                })
                            )}
                        </CardContent>
                    </Card>
                ) : null}

                {/* Override confirmation dialog */}
                <OverrideConfirmationDialog
                    open={overrideOpen}
                    onOpenChange={(next) => {
                        setOverrideOpen(next);
                        if (!next) setOverrideCandidate(null);
                    }}
                    warnings={overrideCandidate?.warnings ?? []}
                    staffName={overrideCandidate?.name}
                    processing={overrideProcessing}
                    onConfirm={(reason) => {
                        if (!overrideCandidate) return;
                        setOverrideProcessing(true);
                        router.post(
                            `/operations/shifts/${shift.id}/assign`,
                            {
                                user_id: overrideCandidate.id,
                                override_acknowledged: true,
                                override_reason: reason,
                                return_to: `/operations/shifts/${shift.id}`,
                            },
                            {
                                preserveScroll: true,
                                onFinish: () => {
                                    setOverrideProcessing(false);
                                    setOverrideOpen(false);
                                    setOverrideCandidate(null);
                                },
                            },
                        );
                    }}
                />

                {transports.length > 0 || can.view_transport ? (
                    <Card>
                        <CardHeader className="flex flex-row items-center justify-between gap-3">
                            <CardTitle className="text-base">
                                Transport activity
                            </CardTitle>
                            {can.view_transport ? (
                                <Button variant="outline" size="sm" asChild>
                                    <Link
                                        href={`/fleet-assets/transports/create?shift_id=${shift.id}`}
                                    >
                                        Log transport
                                    </Link>
                                </Button>
                            ) : null}
                        </CardHeader>
                        <CardContent className="space-y-3">
                            <div className="text-sm text-muted-foreground">
                                Linked resident transport, vehicle, and driver
                                activity for this shift.
                            </div>
                            {transports.length === 0 ? (
                                <div className="rounded-md border border-dashed p-3 text-sm text-muted-foreground">
                                    No transport has been linked to this shift
                                    yet.
                                </div>
                            ) : (
                                transports.map((transport) => (
                                    <div
                                        key={transport.id}
                                        className="rounded-md border p-3"
                                    >
                                        <div className="flex flex-wrap items-start justify-between gap-3">
                                            <div>
                                                <div className="text-sm font-medium capitalize">
                                                    {transport.transport_type.replace(
                                                        /_/g,
                                                        ' ',
                                                    )}
                                                </div>
                                                <div className="mt-1 text-xs text-muted-foreground">
                                                    {transport.asset?.name ??
                                                        'Vehicle not set'}
                                                    {' · '}
                                                    {transport.driver?.name ??
                                                        'Driver not set'}
                                                </div>
                                            </div>
                                            <div className="flex flex-wrap gap-2">
                                                <Badge
                                                    variant="outline"
                                                    className="capitalize"
                                                >
                                                    {transport.status.replace(
                                                        /_/g,
                                                        ' ',
                                                    )}
                                                </Badge>
                                                <Button
                                                    size="sm"
                                                    variant="outline"
                                                    asChild
                                                >
                                                    <Link
                                                        href={`/fleet-assets/transports/${transport.id}`}
                                                    >
                                                        Open
                                                    </Link>
                                                </Button>
                                            </div>
                                        </div>
                                        <div className="mt-2 grid gap-2 text-xs text-muted-foreground sm:grid-cols-2">
                                            <div>
                                                Pickup:{' '}
                                                {transport.pickup_location ??
                                                    'Not set'}
                                            </div>
                                            <div>
                                                Dropoff:{' '}
                                                {transport.dropoff_location ??
                                                    'Not set'}
                                            </div>
                                            <div>
                                                Departed:{' '}
                                                {transport.departed_at
                                                    ? new Date(
                                                          transport.departed_at,
                                                      ).toLocaleString()
                                                    : 'Not started'}
                                            </div>
                                            <div>
                                                Arrived:{' '}
                                                {transport.arrived_at
                                                    ? new Date(
                                                          transport.arrived_at,
                                                      ).toLocaleString()
                                                    : 'In progress'}
                                            </div>
                                        </div>
                                    </div>
                                ))
                            )}
                        </CardContent>
                    </Card>
                ) : null}

                {replacementRequest ||
                (can.request_replacement && shift.user_id) ? (
                    <Card>
                        <CardHeader>
                            <CardTitle className="text-base">
                                Replacement workflow
                            </CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-4">
                            {replacementRequest ? (
                                <div className="space-y-3 rounded-md border p-3">
                                    <div className="flex flex-wrap items-center gap-2">
                                        <Badge
                                            variant={
                                                replacementRequest.is_active
                                                    ? 'secondary'
                                                    : 'outline'
                                            }
                                            className="capitalize"
                                        >
                                            {replacementRequest.status.replace(
                                                /_/g,
                                                ' ',
                                            )}
                                        </Badge>
                                        {replacementRequest.open_position ? (
                                            <Badge
                                                variant="outline"
                                                className="capitalize"
                                            >
                                                Job board:{' '}
                                                {
                                                    replacementRequest
                                                        .open_position.status
                                                }
                                            </Badge>
                                        ) : null}
                                    </div>

                                    <div className="space-y-1 text-sm">
                                        <div className="font-medium">
                                            Reason: {replacementRequest.reason}
                                        </div>
                                        {replacementRequest.notes ? (
                                            <div className="whitespace-pre-wrap text-muted-foreground">
                                                {replacementRequest.notes}
                                            </div>
                                        ) : null}
                                    </div>

                                    <div className="grid gap-2 text-xs text-muted-foreground sm:grid-cols-2">
                                        <div>
                                            Requested by:{' '}
                                            {replacementRequest.requested_by
                                                ?.name ?? 'Unknown'}
                                        </div>
                                        <div>
                                            Requested at:{' '}
                                            {replacementRequest.requested_at
                                                ? new Date(
                                                      replacementRequest.requested_at,
                                                  ).toLocaleString()
                                                : '-'}
                                        </div>
                                        <div>
                                            Current staff:{' '}
                                            {replacementRequest.current_staff
                                                ?.name ?? 'Unassigned'}
                                        </div>
                                        <div>
                                            Replacement:{' '}
                                            {replacementRequest
                                                .replacement_staff?.name ??
                                                replacementRequest.open_position
                                                    ?.claimed_by?.name ??
                                                'Pending'}
                                        </div>
                                    </div>

                                    {replacementRequest.required_skills
                                        ?.length ? (
                                        <div className="flex flex-wrap gap-1">
                                            {replacementRequest.required_skills.map(
                                                (skill) => (
                                                    <Badge
                                                        key={skill}
                                                        variant="outline"
                                                        className="text-[10px]"
                                                    >
                                                        {skill}
                                                    </Badge>
                                                ),
                                            )}
                                        </div>
                                    ) : null}

                                    {replacementRequest.open_position ? (
                                        <div className="rounded-md bg-muted/40 p-3 text-xs text-muted-foreground">
                                            <div>
                                                Published to the job board.
                                                {replacementRequest
                                                    .open_position.expires_at
                                                    ? ` Expires ${new Date(replacementRequest.open_position.expires_at).toLocaleString()}.`
                                                    : ''}
                                            </div>
                                            {replacementRequest.open_position
                                                .claimed_by ? (
                                                <div className="mt-1">
                                                    Claimed by{' '}
                                                    {
                                                        replacementRequest
                                                            .open_position
                                                            .claimed_by.name
                                                    }
                                                    .
                                                </div>
                                            ) : null}
                                            <div className="mt-2">
                                                <Link
                                                    className="underline"
                                                    href="/operations/job-board"
                                                >
                                                    View on job board
                                                </Link>
                                            </div>
                                        </div>
                                    ) : null}

                                    {replacementRequest.is_active &&
                                    can.cancel_replacement ? (
                                        <div className="flex justify-end">
                                            <Button
                                                type="button"
                                                variant="outline"
                                                onClick={() =>
                                                    router.patch(
                                                        `/operations/shifts/${shift.id}/replacement-request/cancel`,
                                                        {},
                                                        {
                                                            preserveScroll: true,
                                                        },
                                                    )
                                                }
                                            >
                                                Cancel request
                                            </Button>
                                        </div>
                                    ) : null}
                                </div>
                            ) : null}

                            {!replacementRequest?.is_active &&
                            can.request_replacement &&
                            shift.user_id ? (
                                <div className="space-y-3 rounded-md border p-3">
                                    <div className="text-sm font-medium">
                                        Request a replacement
                                    </div>
                                    <div className="grid gap-3 sm:grid-cols-2">
                                        <div className="space-y-1 sm:col-span-2">
                                            <Label>Reason</Label>
                                            <Input
                                                value={
                                                    replacementForm.data.reason
                                                }
                                                onChange={(e) =>
                                                    replacementForm.setData(
                                                        'reason',
                                                        e.target.value,
                                                    )
                                                }
                                                placeholder="Why does this shift need a replacement?"
                                            />
                                            {replacementForm.errors.reason ? (
                                                <div className="text-xs text-red-600">
                                                    {
                                                        replacementForm.errors
                                                            .reason
                                                    }
                                                </div>
                                            ) : null}
                                        </div>
                                        <div className="space-y-1 sm:col-span-2">
                                            <Label>Notes</Label>
                                            <Textarea
                                                value={
                                                    replacementForm.data.notes
                                                }
                                                onChange={(e) =>
                                                    replacementForm.setData(
                                                        'notes',
                                                        e.target.value,
                                                    )
                                                }
                                                placeholder="Anything the scheduler or replacement worker needs to know."
                                            />
                                        </div>
                                        <div className="space-y-1">
                                            <Label>
                                                Required skills
                                                (comma-separated)
                                            </Label>
                                            <Input
                                                value={
                                                    replacementForm.data
                                                        .required_skills_text
                                                }
                                                onChange={(e) =>
                                                    replacementForm.setData(
                                                        'required_skills_text',
                                                        e.target.value,
                                                    )
                                                }
                                                placeholder="Medication, Manual handling"
                                            />
                                        </div>
                                        <div className="space-y-1">
                                            <Label>Job board expiry</Label>
                                            <Input
                                                type="datetime-local"
                                                value={
                                                    replacementForm.data
                                                        .expires_at
                                                }
                                                onChange={(e) =>
                                                    replacementForm.setData(
                                                        'expires_at',
                                                        e.target.value,
                                                    )
                                                }
                                            />
                                        </div>
                                    </div>
                                    <label className="flex items-center gap-2 text-sm">
                                        <input
                                            type="checkbox"
                                            checked={
                                                replacementForm.data
                                                    .publish_to_job_board
                                            }
                                            onChange={(e) =>
                                                replacementForm.setData(
                                                    'publish_to_job_board',
                                                    e.target.checked,
                                                )
                                            }
                                        />
                                        Publish this replacement to the job
                                        board
                                    </label>
                                    <div className="flex justify-end">
                                        <Button
                                            type="button"
                                            disabled={
                                                replacementForm.processing
                                            }
                                            onClick={() => {
                                                replacementForm.transform(
                                                    (data) => ({
                                                        reason: data.reason,
                                                        notes:
                                                            data.notes || null,
                                                        publish_to_job_board:
                                                            data.publish_to_job_board,
                                                        expires_at:
                                                            data.expires_at ||
                                                            null,
                                                        required_skills:
                                                            data.required_skills_text
                                                                .split(',')
                                                                .map((skill) =>
                                                                    skill.trim(),
                                                                )
                                                                .filter(
                                                                    Boolean,
                                                                ),
                                                    }),
                                                );
                                                replacementForm.post(
                                                    `/operations/shifts/${shift.id}/replacement-request`,
                                                    {
                                                        preserveScroll: true,
                                                        onSuccess: () =>
                                                            replacementForm.reset(),
                                                    },
                                                );
                                            }}
                                        >
                                            Create replacement request
                                        </Button>
                                    </div>
                                </div>
                            ) : null}
                        </CardContent>
                    </Card>
                ) : null}

                <Dialog open={completeOpen} onOpenChange={setCompleteOpen}>
                    <DialogContent className="sm:max-w-2xl">
                        <DialogHeader>
                            <DialogTitle>Complete shift</DialogTitle>
                        </DialogHeader>

                        <div className="space-y-4">
                            <div className="rounded-lg border p-3">
                                <div className="text-sm font-medium">
                                    Checklist
                                </div>
                                <div className="mt-2 text-sm text-muted-foreground">
                                    {incompleteCount === 0 ? (
                                        <>All shift tasks are completed.</>
                                    ) : (
                                        <>
                                            {incompleteCount} task
                                            {incompleteCount === 1
                                                ? ''
                                                : 's'}{' '}
                                            still incomplete.
                                        </>
                                    )}
                                </div>

                                {incompleteCount > 0 ? (
                                    <div className="mt-3 space-y-3">
                                        <div className="flex items-center gap-2">
                                            <Checkbox
                                                checked={
                                                    completeForm.data
                                                        .allow_incomplete_tasks
                                                }
                                                onCheckedChange={(v) =>
                                                    completeForm.setData(
                                                        'allow_incomplete_tasks',
                                                        Boolean(v),
                                                    )
                                                }
                                            />
                                            <div className="text-sm">
                                                Allow completion with incomplete
                                                tasks
                                            </div>
                                        </div>

                                        {completeForm.data
                                            .allow_incomplete_tasks ? (
                                            <div>
                                                <Label>Reason (required)</Label>
                                                <Textarea
                                                    className="mt-1"
                                                    value={
                                                        completeForm.data
                                                            .incomplete_tasks_reason
                                                    }
                                                    onChange={(e) =>
                                                        completeForm.setData(
                                                            'incomplete_tasks_reason',
                                                            e.target.value,
                                                        )
                                                    }
                                                    placeholder="Why are tasks incomplete?"
                                                />
                                                {completeForm.errors
                                                    .incomplete_tasks_reason ? (
                                                    <div className="mt-1 text-xs text-red-600">
                                                        {
                                                            completeForm.errors
                                                                .incomplete_tasks_reason
                                                        }
                                                    </div>
                                                ) : null}
                                            </div>
                                        ) : null}

                                        {completeForm.errors
                                            .allow_incomplete_tasks ? (
                                            <div className="text-xs text-red-600">
                                                {
                                                    completeForm.errors
                                                        .allow_incomplete_tasks
                                                }
                                            </div>
                                        ) : null}
                                    </div>
                                ) : null}
                            </div>

                            <div className="rounded-lg border p-3">
                                <div className="text-sm font-medium">
                                    Shift summary note
                                </div>
                                <div className="mt-2 grid gap-3 sm:grid-cols-2">
                                    <div>
                                        <Label>Subject</Label>
                                        <Input
                                            className="mt-1"
                                            value={
                                                completeForm.data
                                                    .final_note_subject
                                            }
                                            onChange={(e) =>
                                                completeForm.setData(
                                                    'final_note_subject',
                                                    e.target.value,
                                                )
                                            }
                                        />
                                        {completeForm.errors
                                            .final_note_subject ? (
                                            <div className="mt-1 text-xs text-red-600">
                                                {
                                                    completeForm.errors
                                                        .final_note_subject
                                                }
                                            </div>
                                        ) : null}
                                    </div>
                                </div>

                                <div className="mt-3">
                                    <Label>
                                        Note{' '}
                                        {hasProgressOrShiftNotes
                                            ? '(optional if notes already added)'
                                            : '(required)'}
                                    </Label>
                                    {hasProgressOrShiftNotes ? (
                                        <div className="mt-1 text-xs text-muted-foreground">
                                            You already have notes recorded for
                                            this shift. You can leave this blank
                                            to auto-generate a short completion
                                            summary.
                                        </div>
                                    ) : (
                                        <div className="mt-1 text-xs text-muted-foreground">
                                            Provide a short summary to complete
                                            the shift, or add a progress note
                                            first.
                                        </div>
                                    )}
                                    <Textarea
                                        className="mt-1"
                                        value={
                                            completeForm.data.final_note_body
                                        }
                                        onChange={(e) =>
                                            completeForm.setData(
                                                'final_note_body',
                                                e.target.value,
                                            )
                                        }
                                        placeholder="Summarise what happened during the shift, outcomes, any concerns, and handover items."
                                    />
                                    {completeForm.errors.final_note_body ? (
                                        <div className="mt-1 text-xs text-red-600">
                                            {
                                                completeForm.errors
                                                    .final_note_body
                                            }
                                        </div>
                                    ) : null}
                                </div>
                            </div>

                            <div className="rounded-lg border p-3">
                                <div className="text-sm font-medium">
                                    Operational checks
                                </div>
                                <div className="mt-2 space-y-2 text-sm text-muted-foreground">
                                    <div>
                                        {outstandingMedicationCount > 0
                                            ? `${outstandingMedicationCount} medication item(s) still show as due, late, or missed for this client today.`
                                            : 'No due or late medication items are showing for today.'}
                                    </div>
                                    <div>
                                        {availableFormCount > 0
                                            ? `${submittedFormCount} of ${availableFormCount} active shift form(s) have been submitted.`
                                            : 'No active shift forms are configured for this workflow.'}
                                    </div>
                                </div>

                                <div className="mt-3">
                                    <Label>No handover reason (if needed)</Label>
                                    <div className="mt-1 text-xs text-muted-foreground">
                                        If a relevant incoming shift exists, you
                                        must either submit a handover first or
                                        record why completion is proceeding
                                        without one.
                                    </div>
                                    <Textarea
                                        className="mt-1"
                                        value={
                                            completeForm.data
                                                .handover_waiver_reason
                                        }
                                        onChange={(e) =>
                                            completeForm.setData(
                                                'handover_waiver_reason',
                                                e.target.value,
                                            )
                                        }
                                        placeholder="Explain why no handover was completed for the next shift."
                                    />
                                    {completeForm.errors
                                        .handover_waiver_reason ? (
                                        <div className="mt-1 text-xs text-red-600">
                                            {
                                                completeForm.errors
                                                    .handover_waiver_reason
                                            }
                                        </div>
                                    ) : null}
                                </div>
                            </div>

                            <div className="rounded-lg border border-dashed p-3">
                                <div className="text-xs text-muted-foreground">
                                    A draft timesheet will be created automatically when this shift is completed.
                                </div>
                            </div>
                        </div>

                        <DialogFooter>
                            <Button
                                type="button"
                                variant="outline"
                                onClick={() => setCompleteOpen(false)}
                            >
                                Cancel
                            </Button>
                            <Button
                                type="button"
                                disabled={completeForm.processing}
                                onClick={() =>
                                    completeForm.patch(
                                        `/operations/shifts/${shift.id}/complete`,
                                        {
                                            preserveScroll: true,
                                            onSuccess: () => {
                                                setCompleteOpen(false);
                                                // clean query param if present
                                                try {
                                                    const url = new URL(
                                                        window.location.href,
                                                    );
                                                    url.searchParams.delete(
                                                        'complete',
                                                    );
                                                    window.history.replaceState(
                                                        {},
                                                        '',
                                                        url.toString(),
                                                    );
                                                } catch {}
                                            },
                                        },
                                    )
                                }
                            >
                                Complete shift
                            </Button>
                        </DialogFooter>
                    </DialogContent>
                </Dialog>

                <Dialog open={incidentOpen} onOpenChange={setIncidentOpen}>
                    <DialogContent className="sm:max-w-2xl">
                        <DialogHeader>
                            <DialogTitle>Report incident</DialogTitle>
                        </DialogHeader>

                        <div className="space-y-3">
                            <div className="grid grid-cols-1 gap-3 sm:grid-cols-3">
                                <div className="space-y-1">
                                    <Label>Template (optional)</Label>
                                    <Select
                                        value={
                                            incidentForm.data.template_id ||
                                            '__none__'
                                        }
                                        onValueChange={(v) =>
                                            applyIncidentTemplate(
                                                v === '__none__' ? '' : v,
                                            )
                                        }
                                    >
                                        <SelectTrigger>
                                            <SelectValue placeholder="Pick a template" />
                                        </SelectTrigger>
                                        <SelectContent>
                                            <SelectItem value="__none__">
                                                None
                                            </SelectItem>
                                            {(incidentTemplates || []).map(
                                                (t: any) => (
                                                    <SelectItem
                                                        key={t.id}
                                                        value={String(t.id)}
                                                    >
                                                        {t.name}
                                                    </SelectItem>
                                                ),
                                            )}
                                        </SelectContent>
                                    </Select>
                                </div>

                                <div className="space-y-1">
                                    <Label>Type</Label>
                                    <Input
                                        value={incidentForm.data.type}
                                        onChange={(e) =>
                                            incidentForm.setData(
                                                'type',
                                                e.target.value,
                                            )
                                        }
                                    />
                                </div>

                                <div className="space-y-1">
                                    <Label>Severity</Label>
                                    <Select
                                        value={incidentForm.data.severity}
                                        onValueChange={(v) =>
                                            incidentForm.setData('severity', v)
                                        }
                                    >
                                        <SelectTrigger>
                                            <SelectValue />
                                        </SelectTrigger>
                                        <SelectContent>
                                            {['low', 'medium', 'high'].map(
                                                (s) => (
                                                    <SelectItem
                                                        key={s}
                                                        value={s}
                                                    >
                                                        {s}
                                                    </SelectItem>
                                                ),
                                            )}
                                        </SelectContent>
                                    </Select>
                                </div>
                            </div>

                            <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
                                <div className="space-y-1">
                                    <Label>Occurred at</Label>
                                    <Input
                                        type="datetime-local"
                                        value={incidentForm.data.occurred_at}
                                        onChange={(e) =>
                                            incidentForm.setData(
                                                'occurred_at',
                                                e.target.value,
                                            )
                                        }
                                    />
                                </div>

                                <div className="flex items-center gap-2 pt-6">
                                    <Checkbox
                                        checked={
                                            !!incidentForm.data
                                                .requires_followup
                                        }
                                        onCheckedChange={(v) =>
                                            incidentForm.setData(
                                                'requires_followup',
                                                !!v,
                                            )
                                        }
                                    />
                                    <Label>Requires follow-up</Label>
                                </div>
                            </div>

                            <div className="space-y-1">
                                <Label>Description</Label>
                                <Textarea
                                    value={incidentForm.data.description}
                                    onChange={(e) =>
                                        incidentForm.setData(
                                            'description',
                                            e.target.value,
                                        )
                                    }
                                />
                            </div>

                            <div className="space-y-1">
                                <Label>Immediate action taken</Label>
                                <Textarea
                                    value={
                                        incidentForm.data.immediate_action_taken
                                    }
                                    onChange={(e) =>
                                        incidentForm.setData(
                                            'immediate_action_taken',
                                            e.target.value,
                                        )
                                    }
                                />
                            </div>

                            <div className="space-y-1">
                                <Label>Witnesses</Label>
                                <Textarea
                                    value={incidentForm.data.witnesses}
                                    onChange={(e) =>
                                        incidentForm.setData(
                                            'witnesses',
                                            e.target.value,
                                        )
                                    }
                                />
                            </div>
                        </div>

                        <DialogFooter>
                            <Button
                                disabled={incidentForm.processing}
                                onClick={() =>
                                    incidentForm.post(
                                        `/operations/shifts/${shift.id}/incidents`,
                                        {
                                            onSuccess: () => {
                                                incidentForm.reset();
                                                setIncidentOpen(false);
                                            },
                                        },
                                    )
                                }
                            >
                                Submit (shift-linked)
                            </Button>
                        </DialogFooter>
                    </DialogContent>
                </Dialog>

                {handover.length ? (
                    <Card>
                        <CardHeader>
                            <CardTitle className="text-base">
                                Recent handover
                            </CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-2">
                            {handover.map((h) => (
                                <div
                                    key={h.id}
                                    className="rounded-md border p-3"
                                >
                                    <div className="flex items-center justify-between gap-2">
                                        <div className="text-sm font-medium">
                                            {h.subject || 'Handover'}
                                        </div>
                                        <div className="text-xs text-muted-foreground">
                                            {h.occurred_at
                                                ? new Date(
                                                      h.occurred_at,
                                                  ).toLocaleString()
                                                : ''}
                                        </div>
                                    </div>
                                    {h.body ? (
                                        <div className="mt-2 text-sm whitespace-pre-wrap">
                                            {h.body}
                                        </div>
                                    ) : null}
                                    <div className="mt-2 text-xs text-muted-foreground">
                                        {h.actor?.name
                                            ? `By ${h.actor.name}`
                                            : ''}
                                    </div>
                                </div>
                            ))}
                        </CardContent>
                    </Card>
                ) : null}

                <Card>
                    <CardHeader>
                        <CardTitle className="text-base">Tasks</CardTitle>
                    </CardHeader>
                    <CardContent className="space-y-2">
                        {tasks.map((t) => (
                            <div
                                key={t.id}
                                className="flex items-center gap-3 rounded-md border p-3"
                            >
                                <Checkbox
                                    checked={t.is_completed}
                                    disabled={!canMarkTasks}
                                    onCheckedChange={(v) =>
                                        toggleTask(t, Boolean(v))
                                    }
                                />
                                <div
                                    className={`text-sm ${t.is_completed ? 'text-muted-foreground line-through' : ''}`}
                                >
                                    {t.label}
                                </div>
                            </div>
                        ))}
                        {!tasks.length ? (
                            <div className="text-sm text-muted-foreground">
                                No tasks added for this shift.
                            </div>
                        ) : null}
                    </CardContent>
                </Card>

                {can.view_medication ? (
                    <ShiftMedicationCard
                        clientId={shift.client.id}
                        shiftId={shift.id}
                        shiftStatus={shift.status}
                        canRecord={can.record_medication}
                        summary={medications}
                        witnesses={medicationWitnesses}
                    />
                ) : null}

                {can.view_forms ? (
                    <ShiftFormsCard
                        shiftId={shift.id}
                        canSubmit={can.submit_form}
                        forms={forms.available}
                        submissions={forms.submissions}
                    />
                ) : null}

                <Card>
                    <CardHeader>
                        <CardTitle className="text-base">Shift notes</CardTitle>
                    </CardHeader>
                    <CardContent className="space-y-3">
                        {can.add_note ? (
                            <div className="rounded-md border p-3">
                                <div className="text-sm font-medium">
                                    Add note
                                </div>
                                <div className="mt-3 grid grid-cols-1 gap-3 sm:grid-cols-2">
                                    <div>
                                        <Label>Type</Label>
                                        <Select
                                            value={noteForm.data.type}
                                            onValueChange={(v) => {
                                                noteForm.setData('type', v);
                                                const tpl = templates.find(
                                                    (t) => t.key === v,
                                                );
                                                if (
                                                    tpl &&
                                                    noteForm.data.body.trim() ===
                                                        ''
                                                ) {
                                                    noteForm.setData(
                                                        'body',
                                                        tpl.body,
                                                    );
                                                }
                                                // pin default for handover
                                                noteForm.setData(
                                                    'pin',
                                                    v === 'handover',
                                                );
                                            }}
                                        >
                                            <SelectTrigger>
                                                <SelectValue />
                                            </SelectTrigger>
                                            <SelectContent>
                                                {templates.map((t) => (
                                                    <SelectItem
                                                        key={t.key}
                                                        value={t.key}
                                                    >
                                                        {t.label}
                                                    </SelectItem>
                                                ))}
                                            </SelectContent>
                                        </Select>
                                    </div>
                                    {noteForm.data.type !== 'handover' ? (
                                        <div>
                                            <Label>Subject (optional)</Label>
                                            <Input
                                                value={noteForm.data.subject}
                                                onChange={(e) =>
                                                    noteForm.setData(
                                                        'subject',
                                                        e.target.value,
                                                    )
                                                }
                                            />
                                        </div>
                                    ) : null}
                                </div>

                                {noteForm.data.type === 'progress_note' ? (
                                    <div className="mt-3">
                                        <Label>Goal/outcome (optional)</Label>
                                        <Input
                                            value={noteForm.data.goal}
                                            onChange={(e) =>
                                                noteForm.setData(
                                                    'goal',
                                                    e.target.value,
                                                )
                                            }
                                        />
                                    </div>
                                ) : null}

                                <div className="mt-3">
                                    <Label>Note</Label>
                                    <Textarea
                                        rows={5}
                                        value={noteForm.data.body}
                                        onChange={(e) =>
                                            noteForm.setData(
                                                'body',
                                                e.target.value,
                                            )
                                        }
                                    />
                                </div>

                                <div className="mt-3 flex flex-wrap items-center gap-3">
                                    {noteForm.data.type === 'handover' ? (
                                        <div className="text-xs text-muted-foreground">
                                            Handovers are stored as structured
                                            internal records.
                                        </div>
                                    ) : (
                                        <div className="flex items-center gap-2 text-xs">
                                            <Checkbox
                                                checked={
                                                    noteForm.data.visibility ===
                                                    'portal'
                                                }
                                                onCheckedChange={(v) =>
                                                    noteForm.setData(
                                                        'visibility',
                                                        v
                                                            ? 'portal'
                                                            : 'internal',
                                                    )
                                                }
                                            />
                                            <span>Share in portal</span>
                                        </div>
                                    )}

                                    <Button
                                        onClick={() => {
                                            const onSuccess = () =>
                                                noteForm.reset();

                                            if (
                                                noteForm.data.type ===
                                                'handover'
                                            ) {
                                                noteForm.transform((data) => ({
                                                    handover_notes: data.body,
                                                    client_id: shift.client.id,
                                                }));
                                                noteForm.post(
                                                    `/operations/shifts/${shift.id}/handover`,
                                                    {
                                                        preserveScroll: true,
                                                        onSuccess,
                                                    },
                                                );

                                                return;
                                            }

                                            noteForm.transform((data) => data);
                                            noteForm.post(
                                                `/operations/clients/${shift.client.id}/notes`,
                                                {
                                                    preserveScroll: true,
                                                    onSuccess,
                                                },
                                            );
                                        }}
                                        disabled={
                                            noteForm.processing ||
                                            !noteForm.data.body
                                        }
                                    >
                                        {noteForm.data.type === 'handover'
                                            ? 'Submit handover'
                                            : 'Add'}
                                    </Button>
                                </div>
                                {activeTemplate?.body &&
                                noteForm.data.body.trim() === '' ? (
                                    <div className="mt-2 text-xs text-muted-foreground">
                                        Tip: selecting a type will insert a
                                        quick template.
                                    </div>
                                ) : null}
                            </div>
                        ) : null}

                        {notes.map((n) => (
                            <div key={n.id} className="rounded-md border p-3">
                                <div className="flex items-center justify-between gap-2">
                                    <div className="text-sm font-medium">
                                        {n.subject || n.type}
                                    </div>
                                    <div className="text-xs text-muted-foreground">
                                        {n.occurred_at
                                            ? new Date(
                                                  n.occurred_at,
                                              ).toLocaleString()
                                            : ''}
                                    </div>
                                </div>
                                {n.meta?.goal ? (
                                    <div className="mt-1 text-xs text-muted-foreground">
                                        Goal: {n.meta.goal}
                                    </div>
                                ) : null}
                                {n.body ? (
                                    <div className="mt-2 text-sm whitespace-pre-wrap">
                                        {n.body}
                                    </div>
                                ) : null}
                                <div className="mt-2 text-xs text-muted-foreground">
                                    {n.actor?.name ? `By ${n.actor.name}` : ''}
                                </div>
                            </div>
                        ))}
                        {!notes.length ? (
                            <div className="text-sm text-muted-foreground">
                                No notes for this shift yet.
                            </div>
                        ) : null}
                    </CardContent>
                </Card>
                <Card>
                    <CardHeader>
                        <CardTitle className="text-base">
                            Shift incidents
                        </CardTitle>
                    </CardHeader>
                    <CardContent className="space-y-2">
                        {(incidents || []).map((i: any) => (
                            <div
                                key={i.id}
                                className="flex items-center justify-between rounded-md border p-3"
                            >
                                <div>
                                    <div className="text-sm font-medium">
                                        {i.type} • {i.severity}
                                    </div>
                                    <div className="mt-1 text-xs text-muted-foreground">
                                        {i.status} • {i.occurred_at}
                                    </div>
                                </div>
                                <Link
                                    href={`/incidents/${i.id}`}
                                    className="rounded-md border px-3 py-2 text-xs hover:bg-muted"
                                >
                                    Open
                                </Link>
                            </div>
                        ))}
                        {!(incidents || []).length && (
                            <div className="text-sm text-muted-foreground">
                                No incidents for this shift.
                            </div>
                        )}
                    </CardContent>
                </Card>
            </PageShell>
        </AppLayout>
    );
}
