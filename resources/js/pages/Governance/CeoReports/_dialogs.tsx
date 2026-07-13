import { useEffect, useMemo, useState } from 'react';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { PageTabs, type PageTabItem } from '@/components/page/page-tabs';
import { TabsContent } from '@/components/ui/tabs';
import { router, useForm } from '@inertiajs/react';
import { cn } from '@/lib/utils';
import {
    AlertOctagon,
    BookOpen,
    Briefcase,
    Calendar,
    DollarSign,
    FileText,
    Gavel,
    Layers,
    Loader2,
    MessageCircleQuestion,
    Paperclip,
    Plus,
    ShieldCheck,
    Trash2,
    Users,
    type LucideIcon,
} from 'lucide-react';
import { AttachmentsPanel, type Attachment } from './_attachments';
import { Card as GuardrailCard } from '@/components/ui/card';

// ── Report type tile picker (Send-Kudos style) ────────────────────────────

type ReportTypeKey = 'monthly' | 'quarterly' | 'ad_hoc';

interface ReportTypeDef {
    key: ReportTypeKey;
    label: string;
    description: string;
    icon: LucideIcon;
    accent: string;
}

export const CEO_REPORT_TYPES: ReportTypeDef[] = [
    {
        key: 'monthly',
        label: 'Monthly update',
        description: 'Standard monthly board update.',
        icon: Calendar,
        accent: 'text-status-info',
    },
    {
        key: 'quarterly',
        label: 'Quarterly summary',
        description: 'Three-month performance review.',
        icon: Layers,
        accent: 'text-primary',
    },
    {
        key: 'ad_hoc',
        label: 'Ad-hoc / urgent',
        description: 'Out-of-cycle issue or escalation.',
        icon: AlertOctagon,
        accent: 'text-status-warning',
    },
];

// ── Form shape ────────────────────────────────────────────────────────────

export interface MeetingOption {
    id: number;
    title: string;
    scheduled_at: string | null;
}

export interface DecisionSoughtRow {
    title: string;
    detail: string;
    recommendation: string;
}

export interface MatterArisingRow {
    title: string;
    status: 'open' | 'in_progress' | 'done' | string;
    update: string;
}

export type CeoReportFormValues = {
    report_type: ReportTypeKey | string;
    governance_meeting_id: string;
    period_start: string;
    period_end: string;
    deadline: string;
    executive_summary: string;
    operational_summary: string;
    key_achievements: string;
    challenges_and_risks: string;
    staffing_update: string;
    compliance_status: string;
    financial_summary: string;
    recommendations: string;
    decisions_sought: DecisionSoughtRow[];
    matters_arising: MatterArisingRow[];
    submit_immediately: boolean;
};

export interface CeoReportInitialValues extends Partial<CeoReportFormValues> {
    id?: number;
    attachments?: Attachment[];
}

// ── Field error ───────────────────────────────────────────────────────────

function FieldError({ message }: { message?: string }) {
    if (!message) return null;
    return <p className="mt-1 text-xs text-status-critical">{message}</p>;
}

// ── Type picker ───────────────────────────────────────────────────────────

function ReportTypePicker({
    value,
    onChange,
}: {
    value: string;
    onChange: (v: ReportTypeKey) => void;
}) {
    return (
        <div className="grid grid-cols-1 gap-2 sm:grid-cols-3">
            {CEO_REPORT_TYPES.map((t) => {
                const Icon = t.icon;
                const active = value === t.key;
                return (
                    <Button unstyled
                        key={t.key}
                        type="button"
                        onClick={() => onChange(t.key)}
                        className={cn(
                            'group flex items-start gap-2 rounded-xl border bg-card/40 p-3 text-left transition-all',
                            'hover:border-primary/50 hover:bg-card focus:outline-none focus-visible:ring-2 focus-visible:ring-primary',
                            active
                                ? 'border-primary bg-primary/10 ring-1 ring-primary/40'
                                : 'border-border',
                        )}
                        aria-pressed={active}
                    >
                        <span className="mt-0.5 shrink-0 rounded-lg bg-background/60 p-1.5">
                            <Icon className={cn('h-4 w-4', t.accent)} />
                        </span>
                        <span className="min-w-0">
                            <span className="block truncate text-sm font-medium">{t.label}</span>
                            <span className="block text-xs text-muted-foreground">{t.description}</span>
                        </span>
                    </Button>
                );
            })}
        </div>
    );
}

// ── Repeatable rows ───────────────────────────────────────────────────────

function DecisionsSoughtEditor({
    rows,
    onChange,
}: {
    rows: DecisionSoughtRow[];
    onChange: (rows: DecisionSoughtRow[]) => void;
}) {
    const update = (i: number, patch: Partial<DecisionSoughtRow>) => {
        onChange(rows.map((r, idx) => (idx === i ? { ...r, ...patch } : r)));
    };
    const add = () =>
        onChange([...rows, { title: '', detail: '', recommendation: '' }]);
    const remove = (i: number) => onChange(rows.filter((_, idx) => idx !== i));

    return (
        <div className="space-y-3">
            {rows.length === 0 && (
                <div className="rounded-lg border border-dashed border-border p-4 text-center text-sm text-muted-foreground">
                    No decisions for the board this period. Add one if you need a vote.
                </div>
            )}
            {rows.map((row, i) => (
                <GuardrailCard unstyled key={i} className="space-y-2 rounded-lg border border-border bg-card/40 p-3">
                    <div className="flex items-start justify-between gap-2">
                        <Input
                            placeholder="Decision title (e.g. Approve FY27 budget)"
                            value={row.title}
                            onChange={(e) => update(i, { title: e.target.value })}
                            className="font-medium"
                        />
                        <Button
                            type="button"
                            variant="ghost"
                            size="icon"
                            aria-label="Remove decision"
                            onClick={() => remove(i)}
                        >
                            <Trash2 className="h-4 w-4 text-status-critical" />
                        </Button>
                    </div>
                    <Textarea
                        rows={2}
                        placeholder="Why is this decision needed? Background and context."
                        value={row.detail}
                        onChange={(e) => update(i, { detail: e.target.value })}
                    />
                    <Textarea
                        rows={2}
                        placeholder="CEO recommendation to the board."
                        value={row.recommendation}
                        onChange={(e) => update(i, { recommendation: e.target.value })}
                    />
                </GuardrailCard>
            ))}
            <Button type="button" variant="outline" size="sm" onClick={add}>
                <Plus className="mr-1.5 h-4 w-4" />
                Add decision sought
            </Button>
        </div>
    );
}

function MattersArisingEditor({
    rows,
    onChange,
}: {
    rows: MatterArisingRow[];
    onChange: (rows: MatterArisingRow[]) => void;
}) {
    const update = (i: number, patch: Partial<MatterArisingRow>) => {
        onChange(rows.map((r, idx) => (idx === i ? { ...r, ...patch } : r)));
    };
    const add = () =>
        onChange([...rows, { title: '', status: 'open', update: '' }]);
    const remove = (i: number) => onChange(rows.filter((_, idx) => idx !== i));

    return (
        <div className="space-y-3">
            {rows.length === 0 && (
                <div className="rounded-lg border border-dashed border-border p-4 text-center text-sm text-muted-foreground">
                    Nothing carried over from the previous report.
                </div>
            )}
            {rows.map((row, i) => (
                <GuardrailCard unstyled key={i} className="space-y-2 rounded-lg border border-border bg-card/40 p-3">
                    <div className="flex items-start gap-2">
                        <Input
                            placeholder="Matter title (from previous report)"
                            value={row.title}
                            onChange={(e) => update(i, { title: e.target.value })}
                            className="font-medium"
                        />
                        <Select value={row.status} onValueChange={(v) => update(i, { status: v })}>
                            <SelectTrigger className="w-36 shrink-0">
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="open">Open</SelectItem>
                                <SelectItem value="in_progress">In progress</SelectItem>
                                <SelectItem value="done">Done</SelectItem>
                            </SelectContent>
                        </Select>
                        <Button
                            type="button"
                            variant="ghost"
                            size="icon"
                            aria-label="Remove matter"
                            onClick={() => remove(i)}
                        >
                            <Trash2 className="h-4 w-4 text-status-critical" />
                        </Button>
                    </div>
                    <Textarea
                        rows={2}
                        placeholder="What's happened since the previous board meeting?"
                        value={row.update}
                        onChange={(e) => update(i, { update: e.target.value })}
                    />
                </GuardrailCard>
            ))}
            <Button type="button" variant="outline" size="sm" onClick={add}>
                <Plus className="mr-1.5 h-4 w-4" />
                Add matter arising
            </Button>
        </div>
    );
}

// ── Initial defaults from a meeting ───────────────────────────────────────

function defaultsFromMeeting(meeting: MeetingOption | null): { period_start: string; period_end: string; deadline: string } {
    if (!meeting?.scheduled_at) {
        return { period_start: '', period_end: '', deadline: '' };
    }
    const dt = new Date(meeting.scheduled_at);
    const end = new Date(dt);
    end.setDate(end.getDate() - 1);
    const start = new Date(end);
    start.setMonth(start.getMonth() - 1);
    start.setDate(start.getDate() + 1);
    const deadline = new Date(dt);
    deadline.setDate(deadline.getDate() - 3);
    const fmtDate = (d: Date) => d.toISOString().slice(0, 10);
    const fmtDateTime = (d: Date) => d.toISOString().slice(0, 16);
    return {
        period_start: fmtDate(start),
        period_end: fmtDate(end),
        deadline: fmtDateTime(deadline),
    };
}

// ── Dialog shell ──────────────────────────────────────────────────────────

export interface CeoReportDialogProps {
    isOpen: boolean;
    onClose: () => void;
    meetings: MeetingOption[];
    /** When set, lock the form to this meeting (used from Meeting Show). */
    meetingId?: number | string | null;
    lockMeeting?: boolean;
    /** When passed, dialog enters edit mode and PUTs to this report id. */
    initial?: CeoReportInitialValues;
    /** Called after a successful save/submit. */
    onSaved?: () => void;
}

export function CeoReportDialog({
    isOpen,
    onClose,
    meetings,
    meetingId,
    lockMeeting = false,
    initial,
    onSaved,
}: CeoReportDialogProps) {
    return (
        <Dialog open={isOpen} onOpenChange={(open) => !open && onClose()}>
            <DialogContent
                className="max-h-[90vh] overflow-y-auto"
                style={{ maxWidth: 'min(92vw, 1100px)', width: 'min(92vw, 1100px)' }}
            >
                {isOpen && (
                    <CeoReportBody
                        onClose={onClose}
                        meetings={meetings}
                        meetingId={meetingId ?? null}
                        lockMeeting={lockMeeting}
                        initial={initial}
                        onSaved={onSaved}
                    />
                )}
            </DialogContent>
        </Dialog>
    );
}

function CeoReportBody({
    onClose,
    meetings,
    meetingId,
    lockMeeting,
    initial,
    onSaved,
}: {
    onClose: () => void;
    meetings: MeetingOption[];
    meetingId: number | string | null;
    lockMeeting: boolean;
    initial?: CeoReportInitialValues;
    onSaved?: () => void;
}) {
    const isEdit = Boolean(initial?.id);

    const meetingMap = useMemo(
        () => new Map(meetings.map((m) => [String(m.id), m])),
        [meetings],
    );
    const initialMeetingId = String(initial?.governance_meeting_id ?? meetingId ?? '');
    const initialMeeting = meetingMap.get(initialMeetingId) ?? null;
    const meetingDefaults = defaultsFromMeeting(initialMeeting);

    const form = useForm<CeoReportFormValues>({
        report_type: (initial?.report_type as ReportTypeKey | undefined) ?? 'monthly',
        governance_meeting_id: initialMeetingId,
        period_start: initial?.period_start ?? meetingDefaults.period_start,
        period_end: initial?.period_end ?? meetingDefaults.period_end,
        deadline: initial?.deadline ?? meetingDefaults.deadline,
        executive_summary: initial?.executive_summary ?? '',
        operational_summary: initial?.operational_summary ?? '',
        key_achievements: initial?.key_achievements ?? '',
        challenges_and_risks: initial?.challenges_and_risks ?? '',
        staffing_update: initial?.staffing_update ?? '',
        compliance_status: initial?.compliance_status ?? '',
        financial_summary: initial?.financial_summary ?? '',
        recommendations: initial?.recommendations ?? '',
        decisions_sought: initial?.decisions_sought ?? [],
        matters_arising: initial?.matters_arising ?? [],
        submit_immediately: false,
    });

    const [activeTab, setActiveTab] = useState<string>('overview');

    // Sync period defaults when a different meeting is picked (only in create mode).
    useEffect(() => {
        if (isEdit) return;
        const selected = meetingMap.get(form.data.governance_meeting_id) ?? null;
        if (!selected) return;
        const d = defaultsFromMeeting(selected);
        if (!form.data.period_start) form.setData('period_start', d.period_start);
        if (!form.data.period_end) form.setData('period_end', d.period_end);
        if (!form.data.deadline) form.setData('deadline', d.deadline);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [form.data.governance_meeting_id]);

    const lockedMeeting = lockMeeting ? meetingMap.get(String(meetingId ?? '')) ?? null : null;

    const handleSave = (submitToBoard: boolean) => {
        form.setData('submit_immediately', submitToBoard);

        const onSuccess = () => {
            onSaved?.();
            onClose();
        };

        if (isEdit && initial?.id) {
            form.put(`/governance/ceo-reports/${initial.id}`, {
                preserveScroll: true,
                preserveState: true,
                onSuccess: () => {
                    if (submitToBoard) {
                        router.post(`/governance/ceo-reports/${initial.id}/submit`, undefined, {
                            preserveScroll: true,
                            onSuccess,
                        });
                    } else {
                        onSuccess();
                    }
                },
            });
        } else {
            form.post('/governance/ceo-reports', {
                preserveScroll: true,
                preserveState: true,
                onSuccess,
            });
        }
    };

    const tabs: PageTabItem[] = [
        { value: 'overview', label: 'Overview', icon: FileText },
        { value: 'operations', label: 'Operations', icon: Briefcase },
        { value: 'financials', label: 'Financials', icon: DollarSign },
        { value: 'risks', label: 'Risk & Compliance', icon: ShieldCheck },
        { value: 'workforce', label: 'Workforce', icon: Users },
        { value: 'strategy', label: 'Strategy', icon: BookOpen },
        { value: 'decisions', label: 'Decisions', icon: Gavel },
        { value: 'matters', label: 'Matters arising', icon: MessageCircleQuestion },
        { value: 'attachments', label: `Attachments (${initial?.attachments?.length ?? 0})`, icon: Paperclip, overflowable: true },
    ];

    return (
        <form onSubmit={(e) => { e.preventDefault(); handleSave(false); }}>
            <DialogHeader>
                <DialogTitle className="flex items-center gap-2">
                    <FileText className="h-4 w-4 text-primary" />
                    {isEdit ? 'Edit CEO Report' : 'New CEO Report'}
                </DialogTitle>
                <DialogDescription>
                    {isEdit
                        ? 'Update the report sections. Save as draft or submit to the board.'
                        : 'Pick the report type and meeting, then fill in each section.'}
                </DialogDescription>
            </DialogHeader>

            <div className="mt-3 space-y-4">
                {!isEdit && (
                    <div>
                        <Label className="mb-2 block">
                            Report type <span className="text-status-critical">*</span>
                        </Label>
                        <ReportTypePicker
                            value={form.data.report_type}
                            onChange={(v) => form.setData('report_type', v)}
                        />
                    </div>
                )}

                {/* Meeting + period row */}
                <div className="grid gap-3 sm:grid-cols-2">
                    <div>
                        <Label htmlFor="ceo-meeting">
                            Board meeting <span className="text-status-critical">*</span>
                        </Label>
                        {lockedMeeting ? (
                            <div className="flex items-start gap-3 rounded-xl border border-primary/40 bg-primary/10 p-3">
                                <span className="mt-0.5 shrink-0 rounded-lg bg-background/60 p-1.5">
                                    <Calendar className="h-4 w-4 text-primary" />
                                </span>
                                <div className="min-w-0 flex-1">
                                    <div className="flex flex-wrap items-center gap-2">
                                        <span className="truncate text-sm font-medium">
                                            {lockedMeeting.title}
                                        </span>
                                        <Badge variant="outline" className="text-[10px]">
                                            From meeting
                                        </Badge>
                                    </div>
                                    <p className="mt-0.5 text-xs text-muted-foreground">
                                        Locked from the meeting you opened.
                                    </p>
                                </div>
                            </div>
                        ) : (
                            <Select
                                value={form.data.governance_meeting_id || undefined}
                                onValueChange={(v) => form.setData('governance_meeting_id', v)}
                                disabled={isEdit}
                            >
                                <SelectTrigger id="ceo-meeting">
                                    <SelectValue placeholder="Select meeting…" />
                                </SelectTrigger>
                                <SelectContent>
                                    {meetings.map((m) => (
                                        <SelectItem key={m.id} value={String(m.id)}>
                                            {m.title}
                                            {m.scheduled_at && (
                                                <span className="ml-2 text-xs text-muted-foreground">
                                                    ({new Date(m.scheduled_at).toLocaleDateString('en-NZ')})
                                                </span>
                                            )}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        )}
                        <FieldError message={form.errors.governance_meeting_id} />
                    </div>

                    <div>
                        <Label htmlFor="ceo-deadline">Deadline</Label>
                        <Input
                            id="ceo-deadline"
                            type="datetime-local"
                            value={form.data.deadline}
                            onChange={(e) => form.setData('deadline', e.target.value)}
                        />
                        <FieldError message={form.errors.deadline} />
                    </div>

                    <div>
                        <Label htmlFor="ceo-period-start">Period start</Label>
                        <Input
                            id="ceo-period-start"
                            type="date"
                            value={form.data.period_start}
                            onChange={(e) => form.setData('period_start', e.target.value)}
                        />
                        <FieldError message={form.errors.period_start} />
                    </div>

                    <div>
                        <Label htmlFor="ceo-period-end">Period end</Label>
                        <Input
                            id="ceo-period-end"
                            type="date"
                            value={form.data.period_end}
                            onChange={(e) => form.setData('period_end', e.target.value)}
                        />
                        <FieldError message={form.errors.period_end} />
                    </div>
                </div>

                {/* Section tabs */}
                <PageTabs value={activeTab} onValueChange={setActiveTab} items={tabs}>
                    <TabsContent value="overview" className="space-y-3">
                        <SectionTextarea
                            label="Executive summary"
                            hint="3-paragraph top-line for the board. Headline outcomes only."
                            value={form.data.executive_summary}
                            error={form.errors.executive_summary}
                            onChange={(v) => form.setData('executive_summary', v)}
                            rows={6}
                        />
                    </TabsContent>

                    <TabsContent value="operations" className="space-y-3">
                        <SectionTextarea
                            label="Operational summary"
                            hint="Service utilisation, occupancy, throughput. Use plain numbers."
                            value={form.data.operational_summary}
                            error={form.errors.operational_summary}
                            onChange={(v) => form.setData('operational_summary', v)}
                            rows={5}
                        />
                        <SectionTextarea
                            label="Key achievements"
                            hint="Wins for the period the board should know about."
                            value={form.data.key_achievements}
                            error={form.errors.key_achievements}
                            onChange={(v) => form.setData('key_achievements', v)}
                            rows={4}
                        />
                    </TabsContent>

                    <TabsContent value="financials" className="space-y-3">
                        <SectionTextarea
                            label="Financial summary"
                            hint="Budget vs actual, key revenue / expense items, any variance > 5%."
                            value={form.data.financial_summary}
                            error={form.errors.financial_summary}
                            onChange={(v) => form.setData('financial_summary', v)}
                            rows={6}
                        />
                    </TabsContent>

                    <TabsContent value="risks" className="space-y-3">
                        <SectionTextarea
                            label="Challenges & risks"
                            hint="Risks above appetite, notifiable incidents, regulator engagement."
                            value={form.data.challenges_and_risks}
                            error={form.errors.challenges_and_risks}
                            onChange={(v) => form.setData('challenges_and_risks', v)}
                            rows={5}
                        />
                        <SectionTextarea
                            label="Compliance status"
                            hint="Audits closed, evidence gaps, regulatory deadlines."
                            value={form.data.compliance_status}
                            error={form.errors.compliance_status}
                            onChange={(v) => form.setData('compliance_status', v)}
                            rows={4}
                        />
                    </TabsContent>

                    <TabsContent value="workforce" className="space-y-3">
                        <SectionTextarea
                            label="Workforce update"
                            hint="Hires, exits, training compliance %, turnover."
                            value={form.data.staffing_update}
                            error={form.errors.staffing_update}
                            onChange={(v) => form.setData('staffing_update', v)}
                            rows={5}
                        />
                    </TabsContent>

                    <TabsContent value="strategy" className="space-y-3">
                        <SectionTextarea
                            label="Strategic progress"
                            hint="Movement against the current strategic plan and roadmap."
                            value={form.data.recommendations}
                            error={form.errors.recommendations}
                            onChange={(v) => form.setData('recommendations', v)}
                            rows={5}
                        />
                    </TabsContent>

                    <TabsContent value="decisions" className="space-y-3">
                        <p className="text-xs text-muted-foreground">
                            List anything that needs the board to vote or sign off this period.
                        </p>
                        <DecisionsSoughtEditor
                            rows={form.data.decisions_sought}
                            onChange={(rows) => form.setData('decisions_sought', rows)}
                        />
                    </TabsContent>

                    <TabsContent value="matters" className="space-y-3">
                        <p className="text-xs text-muted-foreground">
                            Update the board on items carried forward from the previous meeting.
                        </p>
                        <MattersArisingEditor
                            rows={form.data.matters_arising}
                            onChange={(rows) => form.setData('matters_arising', rows)}
                        />
                    </TabsContent>

                    <TabsContent value="attachments">
                        <AttachmentsPanel
                            reportId={isEdit ? initial?.id ?? null : null}
                            attachments={initial?.attachments ?? []}
                            canManage
                        />
                    </TabsContent>
                </PageTabs>
            </div>

            <DialogFooter className="mt-4 gap-2">
                <Button type="button" variant="outline" onClick={onClose}>
                    Cancel
                </Button>
                <Button
                    type="submit"
                    variant="outline"
                    disabled={form.processing}
                >
                    {form.processing && !form.data.submit_immediately && (
                        <Loader2 className="mr-2 h-4 w-4 animate-spin" />
                    )}
                    {isEdit ? 'Save changes' : 'Save as draft'}
                </Button>
                <Button
                    type="button"
                    onClick={() => handleSave(true)}
                    disabled={form.processing}
                >
                    {form.processing && form.data.submit_immediately && (
                        <Loader2 className="mr-2 h-4 w-4 animate-spin" />
                    )}
                    Submit to board
                </Button>
            </DialogFooter>
        </form>
    );
}

function SectionTextarea({
    label,
    hint,
    value,
    error,
    onChange,
    rows = 5,
}: {
    label: string;
    hint?: string;
    value: string;
    error?: string;
    onChange: (v: string) => void;
    rows?: number;
}) {
    return (
        <div>
            <Label>{label}</Label>
            {hint && <p className="mb-1 text-[11px] text-muted-foreground">{hint}</p>}
            <Textarea
                rows={rows}
                value={value}
                onChange={(e) => onChange(e.target.value)}
                placeholder="Write each paragraph on its own line. Blank lines split sections."
            />
            <FieldError message={error} />
        </div>
    );
}
