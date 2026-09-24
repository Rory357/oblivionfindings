import PageShell from '@/components/page-shell';
import { TierTwoTabs } from '@/components/page/grouped-profile-nav';
import '@/../css/maintenance-work-record.css';
import { PageHeader, PageHeaderMeterBig, PageHeaderMeterBlock, PageHeaderMeterCaption,
    PageHeaderPrimaryButton, PageHeaderRail, PageHeaderSearch, PageHeaderStatusChip,
    type PageHeaderRailItem } from '@/components/page/page-header';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { StatusBadge } from '@/components/ui/status-badge';
import { FileDropzone, StagedFileCard } from '@/components/ui/file-dropzone';
import { MaintenanceDateRange } from '@/components/fleet-assets/maintenance/date-range';
import { WorkOrderCreateWizard } from './create-wizard';
import { TimePicker } from '@/components/fleet-assets/maintenance/time-picker';
import { DateTimeField, localDateTimeLabel, validLocalDateTime } from '@/components/fleet-assets/maintenance/date-time-field';
import { ConfiguredQuestions, applicableAnswers, type ConfiguredAnswer, type ConfiguredItem, type ConfiguredQuestion } from '@/components/fleet-assets/maintenance/configured-questions';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { ReviewCard, ReviewRow, WizardShell, WizardStepPane, type WizardStep } from '@/components/wizard/shell';
import AppLayout from '@/layouts/app-layout';
import { formatDateTime } from '@/lib/fleet-utils';
import { formatDateOnly } from '@/lib/datetime';
import { Head, Link, router } from '@inertiajs/react';
import { CalendarDays, ClipboardCheck, Clock, Eye, FileText, History, MessageSquare, ShieldCheck, Truck, UserRound, Wrench } from 'lucide-react';
import { useRef, useState } from 'react';

type WorkOrder = {
    id: number; reference_number: string | null; title: string; description: string | null;
    status: string; priority: string; version: number; waiting_reason: string | null;
    next_action: string | null; due_at: string | null; created_at: string;
    asset: { id: number; name: string; asset_tag: string | null; category: string | null; site_id: number; registration_number: string | null };
    reported_by: { id: number; name: string } | null; assigned_to: { id: number; name: string } | null;
};
type Action = { id: number; type: string; actor_id: number; actor_name: string; policy_version_id: number | null; target_id: number | null;
    target_name: string | null; payload: Record<string, unknown>; occurred_at: string; version: number };
type Check = { id: number; kind: string; outcome: string; submitted_at: string; corrects_run_id: number | null;
    presented_template: { name: string; items: Array<{ id: string; label: string }> } | null;
    /** Maintenance's "No issue found — released for use" decision; the outcome stays as submitted. */
    assessment?: { decision: string; label: string; reason: string; assessed_by: string | null; assessed_at: string } | null };
type Attachment = { id: number; action_id: number | null; original_name: string; byte_size: number };
type Rule = { id: number; rules: { questions?: ConfiguredQuestion[]; requires_custody?: boolean } & Record<string, unknown> } | null;
type Props = {
    work_order: WorkOrder; actions: Action[]; checks: Check[];
    reports: Array<{ id: number; title: string; description: string | null; reporter_name: string; submitted_at: string;
        estimated_start_date: string | null; estimated_end_date: string | null; corrects_report_id: number | null }>;
    asset_active_restriction_ids: number[];
    restrictions: Array<{ id: number; restriction_kind: string; state: string }>;
    attachments: Attachment[]; finance: Array<{ id?: number; status: string; reference?: string;
        total_amount?: string; approved_at?: string | null; journal_id?: number | null }>;
    release_policy: Rule; repair_policy: Rule; retest_policy: Rule; check_policy: Rule; hold_policy: Rule;
    release_readiness: { repair_attested: boolean; repair_evidence_saved: boolean; repair_rule_current: boolean; retest_outcome: string | null; retest_coverage_current: boolean };
    task_link: string | null; task_scope_message: string | null;
    retest_template: { id: number; name: string; items: ConfiguredItem[] } | null;
    check_template: { id: number; name: string; items: ConfiguredItem[] } | null;
    booking_impacts: Array<{ id: number; booking_id: number; reference_number: string | null; booking_status: string;
        starts_at: string; ends_at: string; followup_state: string; source_released_at: string | null;
        owner_name: string; owner_user_id: number }>;
    current_user_id: number; can: { manage: boolean; review: boolean; custody: boolean; details: boolean; calendar: boolean; finance_view: boolean; finance_link: boolean };
};
type WorkTab = 'detail' | 'checks' | 'release' | 'bookings' | 'finance' | 'history';
const WORK_TABS: PageHeaderRailItem<WorkTab>[] = [
    { key: 'detail', label: 'Work detail', icon: Wrench },
    { key: 'checks', label: 'Checks & evidence', icon: ClipboardCheck },
    { key: 'release', label: 'Release review', icon: ShieldCheck },
    { key: 'bookings', label: 'Booking impact', icon: Truck },
    { key: 'finance', label: 'Finance', icon: FileText },
    { key: 'history', label: 'History', icon: CalendarDays },
];
const RELEASE_STEPS: readonly WizardStep[] = [
    { key: 'repair', label: 'Repair', blurb: 'Attestation and evidence', icon: Wrench },
    { key: 'retest', label: 'Retest', blurb: 'New source check', icon: ClipboardCheck },
    { key: 'custody', label: 'Custody', blurb: 'Receiving person', icon: Truck },
    { key: 'review', label: 'Review', blurb: 'Independent release', icon: ShieldCheck },
];
const PROVIDER_STEPS: readonly WizardStep[] = [
    { key: 'details', label: 'Appointment', blurb: 'Provider and local times', icon: CalendarDays },
    { key: 'review', label: 'Review', blurb: 'Record the source', icon: ClipboardCheck },
];
const TRIAGE_STEPS: readonly WizardStep[] = [
    { key: 'action', label: 'Next action', blurb: 'Owner and target', icon: ClipboardCheck },
    { key: 'review', label: 'Review', blurb: 'Keep the work moving', icon: ShieldCheck },
];

function label(value: string) { return value.replaceAll('_', ' ').replace(/\b\w/g, (letter) => letter.toUpperCase()); }
function aucklandLocal(utc: string | null): string {
    if (!utc) return '';
    const parts = new Intl.DateTimeFormat('en-NZ', { timeZone: 'Pacific/Auckland', year: 'numeric', month: '2-digit', day: '2-digit',
        hour: '2-digit', minute: '2-digit', hourCycle: 'h23' }).formatToParts(new Date(utc));
    const get = (part: string) => parts.find((value) => value.type === part)?.value ?? '';
    return `${get('year')}-${get('month')}-${get('day')}T${get('hour')}:${get('minute')}`;
}

/** A link can open one tab (?tab=release) and its review (&open=release), e.g. from the vehicle calendar. */
function linkedTab(): WorkTab {
    if (typeof window === 'undefined') return 'detail';
    const tab = new URLSearchParams(window.location.search).get('tab');
    return WORK_TABS.find((item) => item.key === tab)?.key ?? 'detail';
}
function linkedRelease(): boolean {
    return typeof window !== 'undefined' && new URLSearchParams(window.location.search).get('open') === 'release';
}

/** A vehicle profile view to go back to (?return=), when the work was opened from one. */
function vehicleReturn(): string | null {
    if (typeof window === 'undefined') return null;
    const target = new URLSearchParams(window.location.search).get('return');
    return target && /^\/fleet-assets\/vehicles\/\d+(\?[A-Za-z0-9_=&%.-]*)?$/.test(target) ? target : null;
}

export default function WorkOrderShow({ work_order: work, actions, checks, reports, restrictions, asset_active_restriction_ids, booking_impacts, attachments,
    finance, release_policy, repair_policy, release_readiness, retest_policy, retest_template, check_policy, check_template, hold_policy,
    task_link, task_scope_message, current_user_id, can }: Props) {
    const [reportOpen, setReportOpen] = useState(false);
    const [correctingReport, setCorrectingReport] = useState<number | null>(null);
    const [busy, setBusy] = useState(false);
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [pending, setPending] = useState<{ identity: string; key: string } | null>(null);
    const [note, setNote] = useState('');
    const [nextAction, setNextAction] = useState(work.next_action ?? '');
    const [dueLocal, setDueLocal] = useState(() => aucklandLocal(work.due_at));
    const [dueOffset, setDueOffset] = useState('');
    const [triageOpen, setTriageOpen] = useState(false);
    const [triageStep, setTriageStep] = useState(0);
    const [holdKind, setHoldKind] = useState('');
    const [holdSourceRunId, setHoldSourceRunId] = useState('');
    const [activeTab, setActiveTab] = useState<WorkTab>(linkedTab);
    const [detailView, setDetailView] = useState<'overview' | 'activity'>('overview');
    const [workSearch, setWorkSearch] = useState('');
    const [targetSearch, setTargetSearch] = useState('');
    const [targetOptions, setTargetOptions] = useState<Array<{ id: number; name: string }>>([]);
    const [handoverOpen, setHandoverOpen] = useState(false);
    const [handoverStep, setHandoverStep] = useState(0);
    const [handoverTarget, setHandoverTarget] = useState<{ id: number; name: string } | null>(null);
    // Opened for release review: the same wizard and first step as the release button.
    const [releaseOpen, setReleaseOpen] = useState(() => linkedRelease() && (can.manage || can.review || can.custody));
    const [releaseStep, setReleaseStep] = useState(() => (linkedRelease() && can.custody && !can.manage && !can.review ? 2 : 0));
    const [repairSummary, setRepairSummary] = useState('');
    const [evidenceFile, setEvidenceFile] = useState<File | null>(null);
    const [evidenceKey, setEvidenceKey] = useState(crypto.randomUUID());
    const [notesOpen, setNotesOpen] = useState(false);
    const [notesSearch, setNotesSearch] = useState('');
    const [progressValue, setProgressValue] = useState('');
    const [providerSeeded, setProviderSeeded] = useState(false);
    const [peopleSearchError, setPeopleSearchError] = useState('');
    const [peopleSearching, setPeopleSearching] = useState(false);
    const peopleRequest = useRef<AbortController | null>(null);
    const [providerOpen, setProviderOpen] = useState(false);
    const [providerReturnToRelease, setProviderReturnToRelease] = useState(false);
    const [providerMode, setProviderMode] = useState<'plan' | 'confirm' | 'cancel' | 'complete'>('plan');
    const [providerStep, setProviderStep] = useState(0);
    const [providerName, setProviderName] = useState('');
    const [providerStart, setProviderStart] = useState<string | null>(null);
    const [providerEnd, setProviderEnd] = useState<string | null>(null);
    const [providerStartTime, setProviderStartTime] = useState('');
    const [providerEndTime, setProviderEndTime] = useState('');
    const [providerStartOffset, setProviderStartOffset] = useState('');
    const [providerEndOffset, setProviderEndOffset] = useState('');
    const [providerResponseMethod, setProviderResponseMethod] = useState('');
    const [providerReference, setProviderReference] = useState('');
    const [providerReason, setProviderReason] = useState('');
    const [retestAnswers, setRetestAnswers] = useState<Record<string, ConfiguredAnswer>>({});
    const [retestKey, setRetestKey] = useState(crypto.randomUUID());
    const [checkAnswers, setCheckAnswers] = useState<Record<string, ConfiguredAnswer>>({});
    const [checkKey, setCheckKey] = useState(crypto.randomUUID());
    const [impactNotes, setImpactNotes] = useState<Record<number, string>>({});
    const [custodySearch, setCustodySearch] = useState('');
    const [custodyOptions, setCustodyOptions] = useState<Array<{ id: number; name: string }>>([]);
    const [billSearch, setBillSearch] = useState('');
    const [billOptions, setBillOptions] = useState<Array<{ id: number; bill_number: string; status: string }>>([]);
    const activeHold = restrictions.some((restriction) => restriction.state === 'active');
    const assetHeld = asset_active_restriction_ids.length > 0;
    const otherHoldCount = asset_active_restriction_ids.filter((id) => !restrictions.some((restriction) => restriction.id === id)).length;
    const repair = [...actions].reverse().find((action) => action.type === 'attest_repair');
    const repairFile = release_readiness.repair_evidence_saved;
    const repairPolicyStale = work.status === 'completed' && activeHold && Boolean(repair_policy && repair
        && repair.policy_version_id !== repair_policy.id);
    const canAttest = can.manage && (['open', 'in_progress', 'on_hold'].includes(work.status) || repairPolicyStale);
    const canUploadRepair = can.manage && Boolean(repair) && !repairFile
        && (['open', 'in_progress', 'on_hold'].includes(work.status) || (work.status === 'completed' && activeHold));
    const retest = [...checks].reverse().find((check) => check.kind === 'retest');
    const custodyLatest = [...actions].reverse().find((action) => ['propose_custody', 'acknowledge_custody'].includes(action.type));
    const custodyOffer = custodyLatest?.type === 'propose_custody' ? custodyLatest : undefined;
    const custodyReceipt = custodyLatest?.type === 'acknowledge_custody' ? custodyLatest : undefined;
    const handover = [...actions].reverse().find((action) => ['propose_handover', 'accept_handover'].includes(action.type));
    const notes = [...actions].reverse().filter((action) => action.type === 'note');
    const providerLast = [...actions].reverse().find((action) => ['plan_provider', 'record_provider_confirmation', 'record_provider_cancellation', 'record_provider_completion'].includes(action.type));
    const providerPlan = [...actions].reverse().find((action) => action.type === 'plan_provider');
    const needsCustody = release_policy?.rules?.requires_custody === true;
    const holdKinds = Array.isArray(hold_policy?.rules?.allowed_kinds) ? hold_policy.rules.allowed_kinds as string[] : [];
    const providerValid = providerMode === 'plan' ? Boolean(providerName.trim() && providerStart && providerEnd && providerStartTime && providerEndTime)
        : providerMode === 'confirm' ? Boolean(providerResponseMethod.trim() && providerReference.trim()) : Boolean(providerReason.trim());
    const assetHref = work.asset.category === 'vehicle' ? `/fleet-assets/vehicles/${work.asset.id}` : `/fleet-assets/assets/${work.asset.id}`;
    const evidenceCount = reports.length + checks.length + attachments.length;
    const financeLabel = !can.finance_view ? 'Access limited' : finance.length ? label(finance[0].status) : 'No linked bill';
    const activity = [
        ...reports.map((report) => ({ key: `report-${report.id}`, at: report.submitted_at,
            title: report.corrects_report_id ? `Report correction #${report.id}` : `Problem report #${report.id}`,
            detail: `${report.reporter_name} · ${report.title}` })),
        ...checks.map((check) => ({ key: `check-${check.id}`, at: check.submitted_at,
            title: `${label(check.kind)} · ${label(check.outcome)}`, detail: check.presented_template?.name ?? 'Original check source' })),
        ...checks.flatMap((check) => check.assessment ? [{ key: `check-assessment-${check.id}`, at: check.assessment.assessed_at,
            title: `CHK-${check.id} · ${check.assessment.label}`,
            detail: `${check.assessment.assessed_by ?? 'Maintenance manager'} · ${check.assessment.reason}` }] : []),
        ...actions.map((action) => ({ key: `action-${action.id}`, at: action.occurred_at,
            title: label(action.type), detail: `${action.actor_name}${action.target_name ? ` → ${action.target_name}` : ''}` })),
    ].sort((left, right) => right.at.localeCompare(left.at));

    const send = (operation: string, payload: Record<string, unknown> = {}, onSaved?: () => void) => {
        const identity = JSON.stringify([operation, payload]);
        const key = pending?.identity === identity ? pending.key : crypto.randomUUID();
        setPending({ identity, key });
        setBusy(true); setErrors({});
        router.put(`/fleet-assets/maintenance/work-orders/${work.id}`, {
            operation, version: work.version, request_key: key, ...payload,
        }, { preserveScroll: true, onSuccess: () => { setPending(null); setBusy(false); setProgressValue(''); onSaved?.(); },
            onError: (validation) => { setErrors(validation); setBusy(false); setProgressValue(''); },
            onFinish: () => setBusy(false) });
    };
    const openProvider = (mode: 'plan' | 'confirm' | 'cancel' | 'complete', fromRelease = false) => {
        setProviderReturnToRelease(fromRelease);
        if (fromRelease) setReleaseOpen(false);
        setProviderMode(mode); setProviderStep(0); { setErrors({}); setProviderOpen(true); }
        if (mode === 'plan' && !providerSeeded) {
            setProviderSeeded(true);
            if (!providerPlan) return;
            setProviderName(String(providerPlan.payload.provider_name ?? ''));
            setProviderStart(String(providerPlan.payload.starts_local ?? '').split('T')[0] || null);
            setProviderEnd(String(providerPlan.payload.ends_local ?? '').split('T')[0] || null);
            setProviderStartTime(String(providerPlan.payload.starts_local ?? '').split('T')[1] || '');
            setProviderEndTime(String(providerPlan.payload.ends_local ?? '').split('T')[1] || '');
        }
    };
    const closeProvider = () => {
        setProviderOpen(false);
        if (providerReturnToRelease) { setErrors({}); setReleaseOpen(true); }
    };
    const saveProvider = () => {
        const payload = providerMode === 'plan'
            ? { provider_name: providerName, starts_local: `${providerStart}T${providerStartTime}`, ends_local: `${providerEnd}T${providerEndTime}`,
                starts_offset: providerStartOffset || null, ends_offset: providerEndOffset || null }
            : providerMode === 'confirm' ? { response_method: providerResponseMethod, provider_reference: providerReference }
                : providerMode === 'cancel' ? { reason: providerReason }
                    : { service_summary: providerReason };
        const operation = providerMode === 'plan' ? 'plan_provider' : providerMode === 'confirm' ? 'record_provider_confirmation'
            : providerMode === 'cancel' ? 'record_provider_cancellation' : 'record_provider_completion';
        send(operation, payload, () => { setProviderSeeded(false); if (providerReturnToRelease) setReleaseStep(0); closeProvider(); });
    };
    const searchStaff = (query: string, setter: (users: Array<{ id: number; name: string }>) => void) => {
        peopleRequest.current?.abort();
        setPeopleSearchError('');
        if (query.trim().length < 2) { setter([]); setPeopleSearching(false); return; }
        const controller = new AbortController(); peopleRequest.current = controller;
        setPeopleSearching(true);
        fetch(`/fleet-assets/maintenance/work-orders/options/search?type=users&q=${encodeURIComponent(query)}`,
            { credentials: 'same-origin', signal: controller.signal })
            .then((response) => response.ok ? response.json() : Promise.reject(response))
            .then((body: { results: Array<{ id: number; name: string }> }) => setter(body.results))
            .catch(() => { if (!controller.signal.aborted) setPeopleSearchError('People could not be loaded. Your selected person and draft are kept. Edit the search to try again.'); })
            .finally(() => { if (!controller.signal.aborted) setPeopleSearching(false); });
    };
    const actionErrors = Object.keys(errors).length > 0
        ? <p role="alert" className="mb-4 rounded-lg border border-destructive/30 bg-destructive/5 p-3 text-sm text-destructive">{Object.values(errors).join(' ')} Your entries are kept; correct the details and retry.</p> : null;
    const peopleFeedback = peopleSearchError ? <p role="alert" className="mt-2 text-sm text-destructive">{peopleSearchError}</p>
        : peopleSearching ? <p role="status" className="mt-2 text-sm text-muted-foreground">Searching current staff…</p> : null;
    const searchBills = (query: string) => {
        if (query.trim().length < 2) { setBillOptions([]); return; }
        fetch(`/fleet-assets/maintenance/work-orders/options/search?type=finance_bills&asset_id=${work.asset.id}&q=${encodeURIComponent(query)}`,
            { credentials: 'same-origin' })
            .then((response) => response.ok ? response.json() : Promise.reject(response))
            .then((body: { results: Array<{ id: number; bill_number: string; status: string }> }) => setBillOptions(body.results))
            .catch(() => setBillOptions([]));
    };
    const linkBill = (billId: number) => {
        setBusy(true); setErrors({});
        router.post(`/fleet-assets/maintenance/work-orders/${work.id}/finance-bills`, { fin_bill_id: billId },
            { preserveScroll: true, onSuccess: () => { setBillSearch(''); setBillOptions([]); },
                onError: (validation) => setErrors(validation), onFinish: () => setBusy(false) });
    };
    const uploadEvidence = () => {
        if (!repair || !evidenceFile) return;
        const body = new FormData();
        body.append('parent_type', 'action'); body.append('parent_id', String(repair.id));
        body.append('request_key', evidenceKey); body.append('file', evidenceFile);
        body.append('category', 'Service record');
        setBusy(true); setErrors({});
        router.post(`/fleet-assets/maintenance/work-orders/${work.id}/attachments`, body,
            { preserveScroll: true, onSuccess: () => { setEvidenceFile(null); setEvidenceKey(crypto.randomUUID()); },
                onError: (validation) => setErrors(validation), onFinish: () => setBusy(false) });
    };
    const submitRetest = () => {
        if (!retest_policy || !retest_template) return;
        setBusy(true); setErrors({});
        router.post(`/fleet-assets/maintenance/work-orders/${work.id}/retests`, {
            template_id: retest_template.id, rule_version_id: retest_policy.id,
            answers: applicableAnswers(retestAnswers, retest_policy.rules.questions ?? []),
            covered_restriction_ids: asset_active_restriction_ids,
            corrects_run_id: retest?.id ?? null,
            request_key: retestKey,
        }, { preserveScroll: true, onSuccess: () => { setRetestKey(crypto.randomUUID()); setRetestAnswers({}); },
            onError: (validation) => setErrors(validation), onFinish: () => setBusy(false) });
    };
    const submitCheck = () => {
        if (!check_policy || !check_template) return;
        setBusy(true); setErrors({});
        router.post(`/fleet-assets/maintenance/work-orders/${work.id}/checks`, {
            template_id: check_template.id, rule_version_id: check_policy.id,
            answers: applicableAnswers(checkAnswers, check_policy.rules.questions ?? []),
            corrects_run_id: [...checks].reverse().find((entry) => entry.kind === 'check')?.id ?? null,
            request_key: checkKey,
        }, { preserveScroll: true, onSuccess: () => { setCheckKey(crypto.randomUUID()); setCheckAnswers({}); },
            onError: (validation) => setErrors(validation), onFinish: () => setBusy(false) });
    };
    const uploadQuestionEvidence = (kind: 'check' | 'retest', questionId: string, file: File) => {
        const body = new FormData();
        body.append('parent_type', 'work'); body.append('parent_id', '0');
        body.append('request_key', crypto.randomUUID()); body.append('file', file);
        body.append('category', `${kind} question ${questionId}`);
        setBusy(true); setErrors({});
        router.post(`/fleet-assets/maintenance/work-orders/${work.id}/attachments`, body, {
            preserveScroll: true,
            onSuccess: (page) => {
                const id = Number((page.props.flash as { maintenance_attachment_id?: number } | undefined)?.maintenance_attachment_id);
                if (id > 0) {
                    if (kind === 'check') setCheckAnswers((current) => ({ ...current,
                        [questionId]: { ...(current[questionId] ?? { result: '' }), evidence_attachment_id: id } }));
                    else setRetestAnswers((current) => ({ ...current,
                        [questionId]: { ...(current[questionId] ?? { result: '' }), evidence_attachment_id: id } }));
                }
            },
            onError: (validation) => setErrors(validation), onFinish: () => setBusy(false),
        });
    };

    return <AppLayout breadcrumbs={[{ title: 'Home', href: '/dashboard' }, { title: 'Fleet & Assets', href: '/fleet-assets' },
        { title: 'Maintenance', href: '/fleet-assets/maintenance/work-orders' },
        { title: work.reference_number ?? `Work ${work.id}`, href: '#' }]}>
        <Head title={`${work.reference_number ?? 'Work order'} · ${work.title}`} />
        <PageShell>
            <PageHeader variant="profile" icon={Wrench} backHref={vehicleReturn() ?? '/fleet-assets/maintenance/work-orders'} wrapTitle className="overflow-clip!"
                title={work.reference_number ?? `WO-${work.id}`}
                titleChip={<PageHeaderStatusChip variant={assetHeld ? 'critical' : work.status === 'completed' ? 'success' : 'warning'}>
                    {assetHeld ? 'Restricted' : label(work.status)}</PageHeaderStatusChip>}
                subline={`${work.asset.name} · ${work.asset.asset_tag ?? work.asset.registration_number ?? 'Asset'} · ${label(work.priority)} priority`}
                actions={<>
                    <PageHeaderSearch value={workSearch} onChange={setWorkSearch} placeholder="Search this work…" />
                    <Link href={assetHref} aria-label={`View ${work.asset.name}`} title={`View ${work.asset.name}`} className="rounded-[10px] border border-primary-foreground/20 bg-primary-foreground/10 px-3.5 py-2 text-[13px] font-semibold text-primary-foreground hover:bg-primary-foreground/20">{work.asset.asset_tag ?? work.asset.registration_number ?? 'Resource'}</Link>
                    {task_link ? <Link href={task_link} aria-label={`${work.reference_number ?? `WO-${work.id}`} in All Tasks`}
                        className="rounded-[10px] border border-primary-foreground/20 bg-primary-foreground/10 px-3.5 py-2 text-[13px] font-semibold text-primary-foreground hover:bg-primary-foreground/20">All Tasks</Link>
                        : <span title={task_scope_message ?? undefined} className="rounded-[10px] border border-primary-foreground/20 bg-primary-foreground/10 px-3.5 py-2 text-[13px] font-semibold text-primary-foreground/80">All Tasks: outside scope</span>}
                    {can.manage && <PageHeaderPrimaryButton icon={Wrench} onClick={() => { setCorrectingReport(null); setReportOpen(true); }}>Report a problem</PageHeaderPrimaryButton>}
                </>}
                meters={<>
                    <PageHeaderMeterBlock label="Restriction" tone={assetHeld ? 'critical' : 'success'} onClick={() => setActiveTab('release')}>
                        <PageHeaderMeterBig>{assetHeld ? 'Hold active' : 'No active hold'}</PageHeaderMeterBig>
                        <PageHeaderMeterCaption>{assetHeld ? `${asset_active_restriction_ids.length} active on this resource` : 'Current restriction state'}</PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                    <PageHeaderMeterBlock label="Evidence" onClick={() => setActiveTab('checks')}>
                        <PageHeaderMeterBig>{can.details ? `${evidenceCount} sources` : 'Access limited'}</PageHeaderMeterBig>
                        <PageHeaderMeterCaption>{can.details ? 'Reports, checks and private files retained' : 'Readiness shown in release review'}</PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                    <PageHeaderMeterBlock label="Affected bookings" onClick={() => setActiveTab('bookings')}>
                        <PageHeaderMeterBig>{can.manage || can.review ? booking_impacts.length : 'Access limited'}</PageHeaderMeterBig>
                        <PageHeaderMeterCaption>{can.manage || can.review ? 'Bookings stay in place' : 'Follow-up details restricted'}</PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                    <PageHeaderMeterBlock label="Finance" onClick={() => setActiveTab('finance')}>
                        <PageHeaderMeterBig>{financeLabel}</PageHeaderMeterBig>
                        <PageHeaderMeterCaption>Independent of repair and release</PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                </>}
                rail={<PageHeaderRail<WorkTab> items={WORK_TABS} value={activeTab} onSelect={setActiveTab}
                    ariaLabel="Work detail sections" />} />

            {Object.keys(errors).length > 0 && <div role="alert" className="mt-5 rounded-lg border border-status-critical/30 bg-status-critical-bg p-3 text-sm text-status-critical">
                {Object.values(errors).join(' ')} <Button type="button" variant="ghost" onClick={() => setErrors({})}>Dismiss</Button>
            </div>}

            {activeTab === 'detail' && <div className="mt-5"><TierTwoTabs
                tabs={[{ key: 'overview', label: 'Overview', icon: Eye }, { key: 'activity', label: 'Activity', icon: History }]}
                activeTab={detailView} onTab={(key) => setDetailView(key as 'overview' | 'activity')}
                testIdPrefix="maintenance" ariaLabel="Work detail views" panelId="work-detail-panel"
                renderLink={(tab, className, inner, accessibility) => <Button key={tab.key} variant="ghost" className={className}
                    {...accessibility} onClick={() => setDetailView(tab.key as 'overview' | 'activity')}>{inner}</Button>} /></div>}
            <div id="work-detail-panel" className="maintenance-work-record" role={activeTab === 'detail' ? 'tabpanel' : undefined}
                aria-labelledby={activeTab === 'detail' ? `maintenance-tab-${detailView}` : undefined}>
            {activeTab === 'detail' && <div className="mt-6">
                <p className="text-[11px] font-semibold tracking-wider text-muted-foreground uppercase">{work.reference_number ?? `WO-${work.id}`} / Maintenance</p>
                <h2 className="mt-1 text-2xl font-semibold">{detailView === 'overview' ? 'Work overview' : 'Work activity'}</h2>
                <p className="mt-1 text-sm text-muted-foreground">{detailView === 'overview'
                    ? 'The problem, the next action and what must happen before release.'
                    : 'Reports, checks and decisions retained on this work record.'}</p>
                {task_scope_message && <p className="mt-2 text-xs text-muted-foreground">{task_scope_message}</p>}
            </div>}
            {activeTab === 'detail' && detailView === 'activity' && <Card className="mt-5"><CardHeader><CardTitle><span className="work-section-icon"><History className="size-4" /></span>Recent activity</CardTitle></CardHeader><CardContent className="space-y-3">
                {activity.filter((entry) => `${entry.title} ${entry.detail}`.toLowerCase().includes(workSearch.toLowerCase()))
                    .map((entry) => <div key={entry.key} className="border-l-2 border-primary/30 py-1 pl-3 text-sm">
                        <strong>{entry.title}</strong><p className="text-muted-foreground">{entry.detail} · {formatDateTime(entry.at)}</p>
                    </div>)}
                {activity.length === 0 && <p className="text-sm text-muted-foreground">No work activity has been recorded.</p>}
            </CardContent></Card>}
            {activeTab === 'detail' && detailView === 'overview' && <div className={`mt-5 rounded-xl border p-4 ${assetHeld ? 'border-status-critical/30 bg-status-critical-bg' : 'border-primary/20 bg-primary/5'}`} role="status">
                <strong>{assetHeld ? 'Do not use — maintenance hold active' : 'No active maintenance hold on this resource'}</strong>
                <p className="mt-1 text-sm">{assetHeld ? `${otherHoldCount ? `${otherHoldCount} hold(s) belong to other work. ` : ''}Every hold needs its own authorised release. Completing this repair does not clear another job’s restriction.` : 'Each booking still needs its normal readiness checks.'}</p>
            </div>}
            {(activeTab !== 'detail' || detailView === 'overview') && <div className={`mt-6 grid gap-5 ${activeTab === 'detail' ? 'lg:grid-cols-[minmax(0,1.7fr)_minmax(300px,1fr)]' : 'grid-cols-1'}`}>
                <div className={activeTab === 'detail' ? 'space-y-5' : 'contents'}>
                    <Card className={activeTab === 'detail' ? '' : 'hidden'}><CardHeader><CardTitle><span className="work-section-icon"><FileText className="size-4" /></span>Work summary</CardTitle></CardHeader><CardContent className="space-y-4">
                        <div><h2 className="text-lg font-semibold">{work.title}</h2><p className="mt-1 whitespace-pre-wrap text-sm text-muted-foreground">{work.description || 'No original description supplied.'}</p></div>
                        <div className="grid gap-3 border-t pt-4 text-sm sm:grid-cols-3">
                            <div><span className="block text-xs text-muted-foreground">Resource</span><Link href={assetHref} className="font-semibold text-primary hover:underline">{work.asset.name} · {work.asset.asset_tag ?? work.asset.registration_number}</Link></div>
                            <div><span className="block text-xs text-muted-foreground">Reported by</span><strong>{work.reported_by?.name ?? 'Unknown'}</strong><span className="block text-xs text-muted-foreground">{formatDateTime(work.created_at)}</span></div>
                            <div><span className="block text-xs text-muted-foreground">Report source</span>
                                {can.details && reports[0] ? <Button type="button" variant="link" className="h-auto p-0 font-semibold"
                                    onClick={() => setActiveTab('checks')}>Report #{reports[0].id}</Button>
                                    : <strong>{can.details ? 'Work record' : 'Details restricted'}</strong>}
                                <span className="block text-xs text-muted-foreground">{can.details
                                    ? reports[0]?.title ?? 'Original work description retained above'
                                    : 'Release readiness is shown separately'}</span></div>
                        </div>
                    </CardContent></Card>

                    <Card className={activeTab === 'detail' ? '' : 'hidden'}><CardHeader className="flex flex-row items-center justify-between gap-2"><CardTitle><span className="work-section-icon"><MessageSquare className="size-4" /></span>Notes & updates</CardTitle>
                        <div className="flex items-center gap-1"><Badge variant="outline">{can.details ? `${notes.length} notes` : 'Details restricted'}</Badge>
                            {can.manage && <Button variant="outline" size="sm" onClick={() => setNotesOpen(true)}>{note ? 'Continue note' : 'Add note'}</Button>}
                            {can.details && <Button variant="ghost" size="sm" onClick={() => setNotesOpen((value) => !value)} aria-expanded={notesOpen}>{notesOpen ? 'Collapse' : 'Expand'}</Button>}</div>
                    </CardHeader><CardContent className="space-y-3">
                        {!notesOpen && (notes[0] ? <div className="text-sm"><strong>{notes[0].actor_name}</strong><span className="ml-2 text-muted-foreground">{formatDateTime(notes[0].occurred_at)}</span>
                            <p className="mt-1 line-clamp-2 whitespace-pre-wrap">{String(notes[0].payload.note ?? '')}</p></div>
                            : <p className="text-sm text-muted-foreground">{can.details
                                ? 'No notes yet. Add an update to keep the next person informed.'
                                : 'Work notes are restricted for this role.'}</p>)}
                        {notesOpen && <><p className="text-xs text-muted-foreground">Internal work record · visible to authorised staff · no provider message sent</p>
                            {can.manage && <><Textarea value={note} onChange={(event) => setNote(event.target.value)} rows={3} maxLength={3000}
                                placeholder="What changed? What was checked or arranged?" />
                                <div className="flex items-center justify-between"><span className="text-xs text-muted-foreground">{note.length}/3,000 · draft kept in this tab</span>
                                    <Button size="sm" disabled={busy || !note.trim()} onClick={() => send('note', { note }, () => setNote(''))}>Post note</Button></div></>}
                            <Input aria-label="Search maintenance notes" value={notesSearch} onChange={(event) => setNotesSearch(event.target.value)} placeholder="Search notes or authors…" />
                            {notes.filter((item) => `${item.actor_name} ${String(item.payload.note ?? '')}`.toLowerCase().includes(notesSearch.toLowerCase()))
                                .map((item) => <article key={item.id} className="rounded-lg border p-3 text-sm"><strong>{item.actor_name}</strong><span className="ml-2 text-xs text-muted-foreground">{formatDateTime(item.occurred_at)}</span>
                                    <p className="mt-1 whitespace-pre-wrap">{String(item.payload.note ?? '')}</p></article>)}
                        </>}
                    </CardContent></Card>

                    <Card className={activeTab === 'detail' ? '' : 'hidden'}><CardHeader><CardTitle><span className="work-section-icon"><Wrench className="size-4" /></span>Repair & provider</CardTitle></CardHeader><CardContent className="space-y-4 text-sm">
                        {reports.filter((report) => Boolean(report.estimated_start_date)).map((report) =>
                            <div key={report.id} className="rounded-lg border p-3"><strong>Estimated maintenance window</strong>
                                <p>{formatDateOnly(report.estimated_start_date!)} – {formatDateOnly(report.estimated_end_date ?? report.estimated_start_date!)} · report {report.id}</p>
                                <small className="block text-muted-foreground">Proposed dates from the report. Provider confirmation is separate.</small>
                                {can.calendar && <Link href={`/sites/${work.asset.site_id}/calendar`} className="mt-2 inline-block font-medium text-primary hover:underline">View Site Calendar for this resource's site</Link>}</div>)}
                        <div className="rounded-lg border p-3"><div className="flex items-center justify-between gap-2"><strong>Appointment</strong><Badge variant="outline">{!can.details ? 'Details restricted' : providerLast?.type === 'record_provider_confirmation' ? 'Provider confirmed' : providerLast?.type === 'record_provider_cancellation' ? 'Provider cancelled' : providerLast?.type === 'record_provider_completion' ? 'Service completed' : providerLast?.type === 'plan_provider' ? 'Confirmation needed' : 'Not planned'}</Badge></div>
                            <p className="mt-1 text-muted-foreground">{!can.details ? 'Provider appointment details are restricted for this role.' : providerPlan ? `${String(providerPlan.payload.provider_name ?? 'Provider')} · ${localDateTimeLabel(String(providerPlan.payload.starts_local ?? ''))} – ${localDateTimeLabel(String(providerPlan.payload.ends_local ?? ''))}` : 'No internal appointment plan recorded.'}</p>
                            {providerPlan && <small className="text-muted-foreground">An internal plan does not confirm the provider or clear a hold.</small>}
                            {can.manage && !['completed', 'cancelled'].includes(work.status) && <div className="mt-3 flex flex-wrap gap-2"><Button size="sm" variant="outline" onClick={() => openProvider('plan')}>Plan appointment</Button>
                                {providerLast?.type === 'plan_provider' && <Button size="sm" variant="outline" onClick={() => openProvider('confirm')}>Record provider response</Button>}
                                {['plan_provider', 'record_provider_confirmation'].includes(providerLast?.type ?? '') && <Button size="sm" variant="outline" onClick={() => openProvider('cancel')}>Record cancellation</Button>}
                                {providerLast?.type === 'record_provider_confirmation' && <Button size="sm" variant="outline" onClick={() => openProvider('complete')}>Record provider completion</Button>}
                            </div>}
                        </div>
                        <div className="rounded-lg border p-3"><strong>Repair evidence</strong><p className="mt-1 text-muted-foreground">{repairFile
                            ? can.details ? 'Service record attached to the current repair attestation.' : 'Service evidence saved; private file details are restricted.'
                            : can.details ? 'A service record or assessment is needed before completion.' : 'Evidence readiness is shown in release review; private file details are restricted.'}</p>
                            {canUploadRepair && <Button size="sm" variant="outline" className="mt-2" onClick={() => { setReleaseStep(0); { setErrors({}); setReleaseOpen(true); } }}>Add repair evidence</Button>}</div>
                        <div className="rounded-lg border p-3"><strong>Repair completion</strong><p className="mt-1 text-muted-foreground">{repairPolicyStale
                            ? 'A newly approved repair rule needs a fresh attestation, evidence and retest before release.'
                            : release_readiness.repair_attested ? 'Repair attestation recorded. Review separate release requirements.'
                                : 'Record the work done and its service evidence.'}</p>
                            {canAttest && (!repair || repairPolicyStale) && <Button size="sm" className="mt-2" onClick={() => { setReleaseStep(0); { setErrors({}); setReleaseOpen(true); } }}>
                                {repairPolicyStale ? 'Update attestation for current rule' : 'Record repair completion'}</Button>}</div>
                    </CardContent></Card>

                    <Card className={activeTab === 'checks' ? '' : 'hidden'}><CardHeader><CardTitle><span className="work-section-icon"><ClipboardCheck className="size-4" /></span>Reports and checks</CardTitle></CardHeader><CardContent className="space-y-3">
                        {can.manage && check_policy && check_template && <div className="space-y-3 rounded-lg border p-3">
                            <strong>Record a check or correction · {check_template.name}</strong>
                            <p className="text-xs text-muted-foreground">A new dated run keeps the earlier result and original wording.</p>
                            <ConfiguredQuestions items={check_template.items} questions={check_policy.rules.questions ?? []}
                                answers={checkAnswers} attachments={attachments} onChange={setCheckAnswers}
                                onUpload={(id, file) => uploadQuestionEvidence('check', id, file)} busy={busy} />
                            <Button disabled={busy || !check_policy.rules.questions?.length} onClick={submitCheck}>Save new check</Button>
                        </div>}
                        {reports.filter((report) => `${report.title} ${report.description ?? ''} ${report.reporter_name}`.toLowerCase().includes(workSearch.toLowerCase())).map((report) => <div key={report.id} className="rounded-lg border p-3"><div className="flex justify-between gap-2"><strong>{report.title}</strong><span className="text-xs text-muted-foreground">{formatDateTime(report.submitted_at)}</span></div>
                            <p className="mt-1 text-sm text-muted-foreground">{report.reporter_name} · {report.description || 'No additional details'}</p>
                            {report.corrects_report_id && <p className="mt-1 text-xs">Correction of report #{report.corrects_report_id}; earlier source retained.</p>}
                            {can.manage && <Button variant="link" size="sm" className="mt-2 h-auto p-0"
                                onClick={() => { setCorrectingReport(report.id); setReportOpen(true); }}>Correct this source with a new report</Button>}
                        </div>)}
                        {checks.filter((check) => `${check.kind} ${check.outcome} ${check.presented_template?.name ?? ''} ${check.presented_template?.items?.map((item) => item.label).join(' ') ?? ''}`.toLowerCase().includes(workSearch.toLowerCase())).map((check) => <Link key={check.id} href={`/fleet-assets/inspections/${check.id}`} className="block rounded-lg border p-3 hover:bg-accent">
                            <div className="flex items-center justify-between"><span>{check.presented_template?.name ?? 'Legacy check'} · {label(check.kind)}</span><Badge variant="outline">{label(check.outcome)}</Badge></div>
                            <p className="text-xs text-muted-foreground">{check.presented_template?.items?.map((item) => item.label).join(', ') ?? 'Original question wording unavailable'} · {formatDateTime(check.submitted_at)}</p>
                            {check.assessment && <div className="mt-2 grid gap-1">
                                <div className="flex flex-wrap items-center gap-2"><StatusBadge variant="success">No issue found</StatusBadge>
                                    <span className="text-xs text-muted-foreground">Released for use by {check.assessment.assessed_by ?? 'a maintenance manager'} · {formatDateTime(check.assessment.assessed_at)}</span></div>
                                <p className="text-sm">{check.assessment.reason}</p>
                            </div>}
                        </Link>)}
                        <div className="border-t pt-3"><strong>Private evidence</strong>
                            {attachments.filter((file) => file.original_name.toLowerCase().includes(workSearch.toLowerCase())).map((file) =>
                                <Link key={file.id} href={`/fleet-assets/maintenance/work-orders/${work.id}/attachments/${file.id}`}
                                    className="mt-2 flex items-center gap-2 text-primary hover:underline"><FileText className="size-4" />{file.original_name}</Link>)}
                            {attachments.length === 0 && <p className="mt-1 text-sm text-muted-foreground">{can.details
                                ? 'No saved files visible to you.' : 'Private evidence details are restricted for this role.'}</p>}
                        </div>
                    </CardContent></Card>

                    <Card className={activeTab === 'history' ? '' : 'hidden'}><CardHeader><CardTitle><span className="work-section-icon"><History className="size-4" /></span>Activity history</CardTitle></CardHeader><CardContent className="space-y-3">
                        {actions.filter((action) => `${action.type} ${action.actor_name} ${JSON.stringify(action.payload)}`.toLowerCase().includes(workSearch.toLowerCase())).map((action) => <div key={action.id} className="border-l-2 pl-3 text-sm"><strong>{label(action.type)}</strong> · {action.actor_name}
                            {action.target_name && ` → ${action.target_name}`} <span className="text-muted-foreground">· {formatDateTime(action.occurred_at)}</span>
                            {typeof action.payload.note === 'string' && <p className="mt-1 whitespace-pre-wrap">{action.payload.note}</p>}</div>)}
                        {actions.length === 0 && <p className="text-sm text-muted-foreground">No updates yet.</p>}
                    </CardContent></Card>
                </div>

                <div className={activeTab === 'detail' ? 'space-y-5' : 'contents'}>
                    <Card className={activeTab === 'detail' ? 'work-next-action border-t-[3px] border-t-primary' : 'hidden'}><CardHeader><CardTitle><Clock className="size-4" />Next action</CardTitle></CardHeader><CardContent className="space-y-3">
                        <p className="text-base font-semibold">{work.next_action || 'Assessment and a next action are needed.'}</p>
                        <p className="text-sm text-muted-foreground">{work.assigned_to?.name ?? 'Site Coordinator'} · {work.due_at ? formatDateTime(work.due_at) : 'Target not set'} · Pacific/Auckland</p>
                        {can.manage && !['completed', 'cancelled'].includes(work.status) && <Button variant="outline" className="w-full" onClick={() => { setTriageStep(0); { setErrors({}); setTriageOpen(true); } }}>
                            Review triage & next action</Button>}
                        <div className="border-t pt-3"><Label htmlFor="work-progress">Progress</Label>
                            {can.manage && !['completed', 'cancelled'].includes(work.status) ? <select id="work-progress" className="mt-2 h-10 w-full rounded-md border bg-background px-3 text-sm"
                                value={progressValue || (work.status === 'on_hold' ? `waiting:${work.waiting_reason || 'other'}` : work.status)}
                                onChange={(event) => { const value = event.target.value; setProgressValue(value);
                                    if (value === 'in_progress') send(work.status === 'on_hold' ? 'resume' : 'start');
                                    else if (value.startsWith('waiting:')) send('hold', { waiting_reason: value.slice(8) });
                                    else if (value === 'completed') { setReleaseStep(0); { setErrors({}); setReleaseOpen(true); } }
                                    else if (value === 'cancelled') send('cancel'); }}>
                                <option value="open" disabled={work.status !== 'open'}>Open</option><option value="in_progress">In progress</option>
                                <option value="waiting:internal_feedback">Awaiting internal feedback</option>
                                <option value="waiting:approval">Awaiting approval</option>
                                <option value="waiting:vendor">Awaiting third party / vendor</option>
                                <option value="waiting:parts">Awaiting parts</option>
                                <option value="waiting:scheduling">Awaiting scheduling</option>
                                <option value="waiting:retest">Awaiting retest</option>
                                <option value="waiting:release">Awaiting release</option>
                                <option value="waiting:other">On hold</option>
                                <option value="completed">Completed / closed</option><option value="cancelled" disabled={activeHold}>Cancelled</option>
                            </select> : <p className="mt-2 text-sm font-semibold">{label(work.status)}</p>}
                            <p className="mt-2 text-xs text-muted-foreground">Work progress, vehicle availability and Finance approval are separate decisions.</p>
                            {can.manage && !['completed', 'cancelled'].includes(work.status) && <Button className="mt-3 w-full" disabled={busy} onClick={() => { setReleaseStep(0); { setErrors({}); setReleaseOpen(true); } }}>Complete / resolve work</Button>}
                        </div>
                    </CardContent></Card>
                    <Card className={activeTab === 'detail' ? '' : 'hidden'}><CardHeader><CardTitle><span className="work-section-icon"><UserRound className="size-4" /></span>Responsibility</CardTitle></CardHeader><CardContent className="space-y-3 text-sm">
                        <p><strong>{work.assigned_to?.name ?? 'Not assigned'}</strong> remains accountable for this work and its next action.</p>
                        {handover?.type === 'propose_handover' && <p>Proposed to {handover.target_name}. The current owner remains accountable until acceptance.</p>}
                        {handover?.type === 'propose_handover' && handover.target_id === current_user_id && <Button disabled={busy} onClick={() => send('accept_handover')}>Accept handover</Button>}
                        {can.manage && !['completed', 'cancelled'].includes(work.status) && <Button variant="outline" onClick={() => { setHandoverStep(0); { setErrors({}); setHandoverOpen(true); } }}>Hand over work</Button>}
                    </CardContent></Card>
                    <Card className={activeTab === 'release' || activeTab === 'detail' ? '' : 'hidden'}><CardHeader><CardTitle><span className="work-section-icon"><ShieldCheck className="size-4" /></span>Release requirements</CardTitle></CardHeader><CardContent className="space-y-3 text-sm">
                        <div className="flex justify-between gap-2"><strong>Repair evidence</strong><span>{repairFile ? 'Saved' : can.details ? 'Needed' : 'Details restricted'}</span></div>
                        <div className="flex justify-between gap-2"><strong>Current repair rule</strong><span>{release_readiness.repair_rule_current
                            ? 'Attestation current' : repair_policy ? 'New attestation needed' : can.details ? 'Approved rule needed' : 'Review pending'}</span></div>
                        <div className="flex justify-between gap-2"><strong>Retest</strong><span>{retest ? release_readiness.retest_coverage_current ? label(retest.outcome) : 'New retest needed' : 'Result needed'}</span></div>
                        <div className="flex justify-between gap-2"><strong>Keys & custody</strong><span>{needsCustody ? custodyReceipt ? 'Receipt acknowledged' : 'Receipt needed' : release_policy ? 'Not required by policy' : 'Policy decision needed'}</span></div>
                        <div className="flex justify-between gap-2"><strong>Release authority</strong><span>{can.review ? 'Granted for this site and category' : 'Independent reviewer needed'}</span></div>
                        <Button className="w-full" disabled={!can.manage && !can.review && !can.custody} onClick={() => { setReleaseStep(can.custody && !can.manage && !can.review ? 2 : 0); { setErrors({}); setReleaseOpen(true); } }}>
                            {activeHold ? 'Release vehicle / asset' : 'Review release record'}</Button>
                    </CardContent></Card>
                    <Card className={activeTab === 'bookings' ? '' : 'hidden'}><CardHeader><CardTitle><span className="work-section-icon"><Truck className="size-4" /></span>Affected bookings</CardTitle></CardHeader><CardContent className="space-y-3 text-sm">
                        <p className="text-muted-foreground">Existing bookings stay in place. Each affected booking has an accountable follow-up.</p>
                        {booking_impacts.map((impact) => <div key={impact.id} className="rounded-lg border p-3">
                            <div className="flex justify-between gap-2"><Link href={`/fleet-assets/bookings/${impact.booking_id}`} className="font-semibold text-primary hover:underline">
                                {impact.reference_number ?? `Booking ${impact.booking_id}`}</Link><Badge variant="outline">{label(impact.followup_state)}</Badge></div>
                            <p className="mt-1 text-muted-foreground">{label(impact.booking_status)} · {formatDateTime(impact.starts_at)} to {formatDateTime(impact.ends_at)}</p>
                            <p className="mt-1">Follow-up owner: {impact.owner_name}{impact.source_released_at ? ' · restriction released; review still recorded separately' : ''}</p>
                            {impact.followup_state === 'needs_review' && can.manage &&
                                <div className="mt-2 flex flex-col gap-2"><Input aria-label={`Follow-up note for booking ${impact.booking_id}`}
                                    value={impactNotes[impact.id] ?? ''} onChange={(event) => setImpactNotes((current) => ({ ...current, [impact.id]: event.target.value }))}
                                    placeholder="Record the booking follow-up completed" />
                                    <Button size="sm" disabled={busy || !(impactNotes[impact.id] ?? '').trim()}
                                        onClick={() => send('review_booking_impact', { booking_impact_id: impact.id, review_note: impactNotes[impact.id] })}>Mark reviewed</Button></div>}
                        </div>)}
                        {booking_impacts.length === 0 && <p>{can.manage || can.review
                            ? 'No existing bookings were flagged for this work.' : 'Booking follow-up details are restricted for this role.'}</p>}
                    </CardContent></Card>
                    <Card className={activeTab === 'finance' ? '' : 'hidden'}><CardHeader><CardTitle><span className="work-section-icon"><FileText className="size-4" /></span>Evidence and Finance</CardTitle></CardHeader><CardContent className="space-y-3 text-sm">
                        {attachments.map((attachment) => <Link key={attachment.id} href={`/fleet-assets/maintenance/work-orders/${work.id}/attachments/${attachment.id}`} className="flex items-center gap-2 text-primary hover:underline"><FileText className="size-4" />{attachment.original_name}</Link>)}
                        {attachments.length === 0 && <p className="text-muted-foreground">{can.details ? 'No saved files visible to you.' : 'Private file details are restricted for this role.'}</p>}
                        <div className="border-t pt-3"><strong>Finance</strong>
                            {finance.length ? finance.map((bill, index) => <div key={index} className="mt-2 rounded-md border p-2">
                                {bill.id ? <Link href={`/finance/bills/${bill.id}`} className="font-medium text-primary hover:underline">{bill.reference ?? 'Linked bill'}</Link>
                                    : <strong>Linked bill</strong>} · {label(bill.status)}
                                {bill.id && <p className="text-xs text-muted-foreground">{bill.approved_at ? 'Approved in Finance' : 'Awaiting Finance approval'} · {bill.journal_id ? 'Journal recorded' : 'No journal recorded'}
                                    {bill.total_amount ? ` · ${bill.total_amount}` : ''}</p>}</div>)
                                : <p className="text-muted-foreground">{can.finance_view
                                    ? 'No Finance bill linked. Estimates and completion do not post costs.'
                                    : 'Finance details are restricted for this role.'}</p>}
                            {can.finance_link && <div className="mt-3 space-y-2"><Label htmlFor="finance-bill-search">Link existing Finance bill</Label>
                                <Input id="finance-bill-search" value={billSearch} onChange={(event) => { setBillSearch(event.target.value); searchBills(event.target.value); }} placeholder="Search bill number for this resource and site" />
                                {billOptions.map((bill) => <Button key={bill.id} variant="outline" size="sm" className="w-full justify-start" disabled={busy}
                                    onClick={() => linkBill(bill.id)}>{bill.bill_number} · {label(bill.status)}</Button>)}
                                <p className="text-xs text-muted-foreground">Linking shows Finance's current status. It does not approve, post or copy a cost.</p>
                            </div>}
                        </div>
                    </CardContent></Card>
                </div>
            </div>}

            </div>

            <WorkOrderCreateWizard key={correctingReport ?? 'new-problem'} open={reportOpen} onClose={() => setReportOpen(false)}
                assets={[work.asset]} checklistRuns={checks.map((check) => ({ id: check.id, asset_id: work.asset.id, asset_name: work.asset.name,
                    template_name: check.presented_template?.name ?? `Check #${check.id}`, run_at: check.submitted_at }))}
                prefillAssetId={String(work.asset.id)} prefillExistingWorkOrderId={correctingReport ? String(work.id) : null}
                prefillCorrectsReportId={correctingReport ? String(correctingReport) : null} />
            <WizardShell open={triageOpen} onClose={() => setTriageOpen(false)}
                title="Review triage & next action" description="Keep the accountable owner and exact target with this work."
                railIcon={ClipboardCheck} railTitle="Next action" railSub={work.reference_number ?? 'Maintenance'}
                steps={TRIAGE_STEPS} stepIndex={triageStep} onStepClick={setTriageStep}
                footerStart={<Button variant="ghost" onClick={() => triageStep ? setTriageStep(0) : setTriageOpen(false)}>{triageStep ? 'Back' : 'Keep draft and close'}</Button>}
                footerEnd={<Button disabled={busy || !nextAction.trim() || Boolean(dueLocal && !validLocalDateTime(dueLocal))}
                    onClick={() => triageStep ? send('update_next_action', {
                        next_action: nextAction,
                        ...(dueLocal && dueLocal !== aucklandLocal(work.due_at)
                            ? { due_local: dueLocal, due_offset: dueOffset || null } : {}),
                    }, () => setTriageOpen(false)) : setTriageStep(1)}>{triageStep ? 'Save next action' : 'Review'}</Button>}>
                {triageStep === 0 ? <WizardStepPane>{actionErrors}<div className="space-y-4">
                    <div><Label htmlFor="next-action">Next action</Label><Input id="next-action" value={nextAction}
                        onChange={(event) => setNextAction(event.target.value)} placeholder="Describe the next action" />
                        {errors.next_action && <p role="alert" className="text-sm text-destructive">{errors.next_action}</p>}</div>
                    <DateTimeField id="work-target" label="Target deadline" value={dueLocal} onChange={setDueLocal}
                        error={errors.due_local} hint="Optional. Leaving this blank keeps the current deadline." />
                    <div className="rounded-lg border p-3"><Label htmlFor="hold-kind">Asset restriction · separate from work progress</Label>
                        <select id="hold-kind" className="mt-2 h-10 w-full rounded-md border bg-background px-3" value={holdKind} onChange={(event) => setHoldKind(event.target.value)}>
                            <option value="">Do not place a restriction</option>
                            {holdKinds.map((kind) => <option key={kind} value={kind}>{label(kind)}</option>)}
                        </select>
                        {holdKinds.length === 0 && <p className="mt-1 text-xs text-muted-foreground">An approved hold rule is required before a restriction can be placed.</p>}
                        {holdKind && <><Label htmlFor="hold-source">Source check, if this hold follows a result</Label>
                            <select id="hold-source" className="mt-2 h-10 w-full rounded-md border bg-background px-3" value={holdSourceRunId} onChange={(event) => setHoldSourceRunId(event.target.value)}>
                                <option value="">Report / work assessment</option>
                                {checks.filter((check) => check.outcome !== 'passed').map((check) =>
                                    <option key={check.id} value={check.id}>{label(check.kind)} #{check.id} · {label(check.outcome)}</option>)}
                            </select>
                            <Button className="mt-2" variant="destructive" disabled={busy}
                                onClick={() => send('place_restriction', { restriction_kind: holdKind,
                                    ...(holdSourceRunId ? { source_run_id: Number(holdSourceRunId) } : {}) })}>
                                {activeHold ? 'Place another approved restriction' : 'Place approved restriction'}</Button>
                            {errors.restriction_kind && <p role="alert" className="text-sm text-destructive">{errors.restriction_kind}</p>}
                        </>}
                    </div>
                    {dueLocal && <div><Label htmlFor="work-due-offset">Clock occurrence, if the hour repeats</Label>
                        <select id="work-due-offset" className="mt-1 h-10 w-full rounded-md border bg-background px-2 text-sm"
                            value={dueOffset} onChange={(event) => setDueOffset(event.target.value)}>
                            <option value="">Ordinary Auckland time</option><option value="+13:00">First occurrence · UTC+13</option>
                            <option value="+12:00">Second occurrence · UTC+12</option></select></div>}
                </div></WizardStepPane> : <WizardStepPane>{actionErrors}<ReviewCard title="Triage record" icon={ClipboardCheck}>
                    <ReviewRow label="Next action" value={nextAction} />
                    <ReviewRow label="Owner" value={work.assigned_to?.name ?? 'Site Coordinator'} />
                    <ReviewRow label="Target" value={dueLocal ? localDateTimeLabel(dueLocal) : work.due_at ? formatDateTime(work.due_at) : 'No target set'} />
                    <ReviewRow label="Availability" value="A work target does not release a maintenance hold" />
                    <ReviewRow label="Proposed restriction" value={holdKind ? `${label(holdKind)} · save separately` : 'None'} />
                </ReviewCard></WizardStepPane>}
            </WizardShell>

            <WizardShell open={handoverOpen} onClose={() => setHandoverOpen(false)}
                title="Propose owner handover" description="The current owner remains accountable until the selected colleague accepts."
                railIcon={Truck} railTitle="Owner handover" railSub={work.reference_number ?? 'Maintenance'}
                steps={[{ key: 'person', label: 'Person', blurb: 'Current site staff', icon: Truck },
                    { key: 'review', label: 'Review', blurb: 'Confirm proposal', icon: ShieldCheck }]}
                stepIndex={handoverStep} onStepClick={setHandoverStep}
                footerStart={<Button variant="ghost" onClick={() => handoverStep ? setHandoverStep(0) : setHandoverOpen(false)}>{handoverStep ? 'Back' : 'Keep draft and close'}</Button>}
                footerEnd={<Button disabled={busy || !handoverTarget} onClick={() => handoverStep
                    ? send('propose_handover', { target_user_id: handoverTarget!.id }, () => setHandoverOpen(false))
                    : setHandoverStep(1)}>{handoverStep ? 'Propose handover' : 'Review'}</Button>}>
                {handoverStep === 0 ? <WizardStepPane>{actionErrors}<Label htmlFor="handover-search">Receiving owner</Label>
                    <Input id="handover-search" value={targetSearch} onChange={(event) => { setTargetSearch(event.target.value); searchStaff(event.target.value, setTargetOptions); }} placeholder="Search current staff at this site" />{peopleFeedback}
                    {targetOptions.map((person) => <Button key={person.id} type="button" variant={handoverTarget?.id === person.id ? 'default' : 'outline'}
                        className="mt-2 w-full justify-start" onClick={() => setHandoverTarget(person)}>{person.name}</Button>)}
                    {errors.target_user_id && <p role="alert" className="text-sm text-destructive">{errors.target_user_id}</p>}
                </WizardStepPane> : <WizardStepPane>{actionErrors}<ReviewCard title="Handover proposal" icon={Truck}>
                    <ReviewRow label="Work" value={work.reference_number ?? `WO-${work.id}`} />
                    <ReviewRow label="Current owner" value={work.assigned_to?.name ?? 'Site Coordinator'} />
                    <ReviewRow label="Proposed owner" value={handoverTarget?.name ?? 'Choose a person'} />
                    <ReviewRow label="Accountability" value="Current owner until acceptance" />
                </ReviewCard></WizardStepPane>}
            </WizardShell>

            <WizardShell open={providerOpen} onClose={closeProvider}
                title={providerMode === 'plan' ? 'Plan appointment' : providerMode === 'confirm' ? 'Record provider response' : providerMode === 'cancel' ? 'Record provider cancellation' : 'Record provider completion'}
                description="Record the internal plan and the provider's response as separate sources. An appointment does not clear a maintenance hold."
                railIcon={CalendarDays} railTitle="Provider appointment" railSub={work.reference_number ?? 'Maintenance'}
                steps={PROVIDER_STEPS} stepIndex={providerStep} onStepClick={setProviderStep}
                footerStart={<Button variant="ghost" onClick={() => providerStep ? setProviderStep(0) : closeProvider()}>{providerStep ? 'Back' : 'Keep draft and close'}</Button>}
                footerEnd={<Button disabled={busy || !providerValid} onClick={() => providerStep ? saveProvider() : setProviderStep(1)}>{providerStep ? 'Record source' : 'Review'}</Button>}>
                {providerStep === 0 && <WizardStepPane>{actionErrors}<div className="space-y-4">

                    {providerMode === 'plan' ? <><div><Label htmlFor="provider-name">Proposed provider</Label><Input id="provider-name" value={providerName} onChange={(event) => setProviderName(event.target.value)} placeholder="Workshop or service provider" /></div>
                        <MaintenanceDateRange title="Proposed appointment dates" hint="Pacific/Auckland · internal plan until the provider confirms" start={providerStart} end={providerEnd} onChange={(start, end) => { setProviderStart(start); setProviderEnd(end); }} />
                        <div className="grid gap-3 sm:grid-cols-2"><div><Label>Start time</Label><TimePicker id="provider-start-time" label="Start time" value={providerStartTime} onChange={setProviderStartTime} /></div>
                            <div><Label>End time</Label><TimePicker id="provider-end-time" label="End time" value={providerEndTime} onChange={setProviderEndTime} /></div></div>
                        <div className="grid gap-3 sm:grid-cols-2"><div><Label htmlFor="provider-start-offset">Start offset if the clock repeats</Label><select id="provider-start-offset" className="mt-1 h-10 w-full rounded-md border bg-background px-2" value={providerStartOffset} onChange={(event) => setProviderStartOffset(event.target.value)}><option value="">Ordinary Auckland time</option><option value="+13:00">First occurrence · UTC+13</option><option value="+12:00">Second occurrence · UTC+12</option></select></div>
                            <div><Label htmlFor="provider-end-offset">End offset if the clock repeats</Label><select id="provider-end-offset" className="mt-1 h-10 w-full rounded-md border bg-background px-2" value={providerEndOffset} onChange={(event) => setProviderEndOffset(event.target.value)}><option value="">Ordinary Auckland time</option><option value="+13:00">First occurrence · UTC+13</option><option value="+12:00">Second occurrence · UTC+12</option></select></div></div>
                        <p className="text-xs text-muted-foreground">Both date and time parts are required. Times in a daylight saving gap are rejected; repeated minutes need a stated offset.</p></>
                        : providerMode === 'confirm' ? <><div><Label htmlFor="provider-method">How did the provider respond?</Label><Input id="provider-method" value={providerResponseMethod} onChange={(event) => setProviderResponseMethod(event.target.value)} placeholder="Phone, email or booking portal" /></div>
                            <div><Label htmlFor="provider-reference">Provider reference</Label><Input id="provider-reference" value={providerReference} onChange={(event) => setProviderReference(event.target.value)} placeholder="Confirmation number or named contact" /></div></>
                            : <div><Label htmlFor="provider-reason">{providerMode === 'cancel' ? 'Cancellation reason' : 'Service completion summary'}</Label><Textarea id="provider-reason" value={providerReason} onChange={(event) => setProviderReason(event.target.value)} rows={4} /></div>}
                </div></WizardStepPane>}
                {providerStep === 1 && <WizardStepPane>{actionErrors}<ReviewCard title="Provider source review" icon={CalendarDays}>
                    <ReviewRow label="Action" value={providerMode === 'plan' ? 'Internal appointment plan' : providerMode === 'confirm' ? 'Provider confirmation' : providerMode === 'cancel' ? 'Provider cancellation' : 'Provider completion'} />
                    {providerMode === 'plan' ? <><ReviewRow label="Provider" value={providerName} /><ReviewRow label="Start" value={`${providerStart} · ${providerStartTime} · Pacific/Auckland`} /><ReviewRow label="End" value={`${providerEnd} · ${providerEndTime} · Pacific/Auckland`} /></>
                        : providerMode === 'confirm' ? <><ReviewRow label="Method" value={providerResponseMethod} /><ReviewRow label="Reference" value={providerReference} /></>
                            : <ReviewRow label={providerMode === 'cancel' ? 'Reason' : 'Summary'} value={providerReason} />}
                    <p className="mt-3 text-sm text-muted-foreground">The action is saved as a separate dated work source. The maintenance hold remains independently controlled.</p>
                </ReviewCard></WizardStepPane>}
            </WizardShell>

            <WizardShell open={releaseOpen} onClose={() => setReleaseOpen(false)} title={activeHold ? 'Release vehicle / asset' : 'Complete maintenance work'}
                description={activeHold ? 'Save each source separately. Final release requires approved rules and an independent authorised reviewer.'
                    : 'Record repair completion and its evidence. There is no hold on this work to release.'}
                railIcon={ShieldCheck} railTitle={activeHold ? 'Guarded release' : 'Repair completion'} railSub={work.reference_number ?? 'Maintenance'}
                steps={RELEASE_STEPS} stepIndex={releaseStep} onStepClick={setReleaseStep}
                footerStart={<Button variant="ghost" onClick={() => releaseStep ? setReleaseStep(releaseStep - 1) : setReleaseOpen(false)}>{releaseStep ? 'Back' : 'Keep draft and close'}</Button>}
                footerEnd={<Button onClick={() => releaseStep < 3 ? setReleaseStep(releaseStep + 1)
                    : activeHold ? send('release', {}, () => setReleaseOpen(false)) : setReleaseOpen(false)}
                    disabled={busy || (releaseStep === 3 && (activeHold
                        ? !can.review || repair?.actor_id === current_user_id || !release_policy || !repairFile
                            || !release_readiness.repair_rule_current || retest?.outcome !== 'passed'
                            || (needsCustody && !custodyReceipt) || work.status !== 'completed'
                        : work.status !== 'completed'))}>
                    {releaseStep < 3 ? 'Continue' : activeHold ? 'Release asset' : 'Close record'}</Button>}>
                {releaseStep === 0 && <WizardStepPane>{actionErrors}<div className="space-y-4">

                    {['plan_provider', 'record_provider_confirmation'].includes(providerLast?.type ?? '') && can.manage && <div className="rounded-lg border border-status-warning/40 bg-status-warning-bg p-3">
                        <strong>Provider appointment still needs a source outcome</strong>
                        <p className="mt-1 text-sm">{providerLast?.type === 'plan_provider'
                            ? 'Record the provider response, then service completion or cancellation before repair completion.'
                            : 'Record service completion or cancellation before repair completion.'} This keeps the appointment history.</p>
                        <div className="mt-2 flex flex-wrap gap-2"><Button size="sm" variant="outline" onClick={() => openProvider('cancel', true)}>Record cancellation</Button>
                            {providerLast?.type === 'plan_provider' && <Button size="sm" variant="outline" onClick={() => openProvider('confirm', true)}>Record provider response</Button>}
                            {providerLast?.type === 'record_provider_confirmation' && <Button size="sm" variant="outline" onClick={() => openProvider('complete', true)}>Record completion</Button>}</div>
                    </div>}
                    <p className="text-sm">Repair attestation: {release_readiness.repair_attested
                        ? can.details && repair ? `${repair.actor_name} · saved` : 'Saved; source details restricted'
                        : 'Not saved'}. Evidence: {repairFile ? 'Saved' : can.details ? 'Required before completion or release' : 'Details restricted'}.</p>
                    {repairPolicyStale && <p role="status" className="rounded-lg border border-status-warning/40 bg-status-warning-bg p-3 text-sm">
                        The repair rule changed after completion. Save a new attestation under the current rule, attach new service evidence, then record a fresh retest and custody receipt before release.
                    </p>}
                    {canAttest && <><Label htmlFor="repair-summary">{repair ? 'Updated repair summary' : 'Repair summary'}</Label><Textarea id="repair-summary" value={repairSummary} onChange={(event) => setRepairSummary(event.target.value)} />
                        <Button disabled={busy || !repairSummary.trim()} onClick={() => send('attest_repair', { summary: repairSummary })}>{repairPolicyStale ? 'Save attestation under current rule' : repair ? 'Save updated attestation' : 'Save attestation'}</Button></>}
                    {canUploadRepair && <><Label>Service evidence</Label><FileDropzone id="repair-file" accept="image/jpeg,image/png,application/pdf" multiple={false}
                        title="Drop service evidence here" hint="PDF, JPG or PNG · up to 10 MB" onFiles={(files) => setEvidenceFile(files[0] ?? null)} />
                        {evidenceFile && <StagedFileCard file={evidenceFile} onRemove={() => setEvidenceFile(null)} />}
                        <Button disabled={busy || !evidenceFile} onClick={uploadEvidence}>Save private evidence</Button></>}
                    {repairFile && <p className="text-sm text-status-success">Saved repair evidence is linked to its attestation.</p>}
                    {can.manage && repairFile && !['completed', 'cancelled'].includes(work.status) && <Button disabled={busy} onClick={() => send('complete')}>
                        Mark repair work complete</Button>}
                    {work.status === 'completed' && <p className="text-sm text-status-success">Repair work is complete. The hold remains until independent release.</p>}
                </div></WizardStepPane>}
                {releaseStep === 1 && <WizardStepPane>{actionErrors}<div className="space-y-4">

                    <p className="text-sm">Latest retest: <strong>{retest ? label(retest.outcome) : 'Not saved'}</strong>. A failed or unassessed result blocks release.</p>
                    {can.manage && retest_policy && retest_template ? <><p className="font-medium">{retest_template.name}</p>
                        <ConfiguredQuestions items={retest_template.items} questions={retest_policy.rules.questions ?? []}
                            answers={retestAnswers} attachments={attachments} onChange={setRetestAnswers}
                            onUpload={(id, file) => uploadQuestionEvidence('retest', id, file)} busy={busy} />
                        <Button disabled={busy || !repairFile || !retest_policy.rules.questions?.length} onClick={submitRetest}>Save new retest</Button></>
                        : <p className="text-sm text-status-warning">{retest_policy && retest_template
                            ? 'Only an authorised maintenance manager can record a retest. The approved rule and template are present.'
                            : 'No approved exact retest rule and template are configured for this asset.'}</p>}
                </div></WizardStepPane>}
                {releaseStep === 2 && <WizardStepPane>{actionErrors}<div className="space-y-4">

                    <p className="text-sm">{needsCustody ? 'An identified receiving person must acknowledge custody after the retest.' : release_policy ? 'Approved policy does not require a custody receipt.' : 'Approved custody applicability is missing.'}</p>
                    {custodyReceipt && <p className="text-sm text-status-success">Receipt saved by {custodyReceipt.actor_name}.</p>}
                    {needsCustody && can.manage && <><Label htmlFor="custody-search">Receiving person</Label><Input id="custody-search" value={custodySearch} onChange={(event) => { setCustodySearch(event.target.value); searchStaff(event.target.value, setCustodyOptions); }} placeholder="Search current staff" />
                        {custodyOptions.map((person) => <Button key={person.id} variant="outline" className="w-full justify-start" disabled={busy} onClick={() => send('propose_custody', { target_user_id: person.id })}>{person.name}</Button>)}</>}
                    {needsCustody && custodyOffer && custodyOffer.target_id === current_user_id && !custodyReceipt && <Button disabled={busy} onClick={() => send('acknowledge_custody', { received: true })}>Acknowledge receipt</Button>}
                </div></WizardStepPane>}
                {releaseStep === 3 && <WizardStepPane>{actionErrors}<ReviewCard title={activeHold ? 'Final release review' : 'Repair completion review'} icon={ShieldCheck}>
                    {otherHoldCount > 0 && <p className="mb-4 rounded-lg border border-status-warning/30 bg-status-warning-bg p-3 text-sm">This decision clears only this work’s hold. The resource stays unavailable while {otherHoldCount} other hold(s) remain.</p>}
                    <ReviewRow label="Active restriction" value={activeHold ? 'Yes' : 'None'} />
                    <ReviewRow label="Repair evidence" value={repairFile ? 'Saved' : 'Missing'} />
                    <ReviewRow label="Repair rule" value={release_readiness.repair_rule_current ? 'Current attestation' : 'New approved-rule attestation needed'} />
                    <ReviewRow label="Repair completion" value={work.status === 'completed' ? 'Saved' : 'Needed'} />
                    <ReviewRow label="Current retest" value={retest?.outcome ? release_readiness.retest_coverage_current ? label(retest.outcome) : 'New retest needed for current holds / rule' : 'Missing'} />
                    <ReviewRow label="Custody" value={needsCustody ? custodyReceipt ? `Acknowledged by ${custodyReceipt.actor_name}` : 'Missing' : release_policy ? 'Not required by policy' : 'Policy missing'} />
                    <ReviewRow label="Reviewer" value={can.review ? repair?.actor_id === current_user_id ? 'A different authorised reviewer must release' : 'Granted for this site and category' : 'Not authorised'} />
                    <p className="mt-4 text-sm text-muted-foreground">{activeHold
                        ? 'The final decision rechecks current sources and grants while locking the asset. Completion alone leaves the restriction active.'
                        : 'Repair completion is a work status. Future bookings still use their own readiness checks.'}</p>
                </ReviewCard></WizardStepPane>}
            </WizardShell>
        </PageShell>
    </AppLayout>;
}
