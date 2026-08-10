import { PageHero, PageLayout } from '@/components/page';
import { PageTabs, type PageTabItem } from '@/components/page/page-tabs';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { TabsContent } from '@/components/ui/tabs';
import AppLayout from '@/layouts/app-layout';
import { governanceStatusColor } from '@/lib/governance-status';
import { cn } from '@/lib/utils';
import { PageProps } from '@/types';
import { Head, router } from '@inertiajs/react';
import {
    AlertOctagon,
    BookOpen,
    Briefcase,
    CalendarDays,
    CheckCircle2,
    ClipboardList,
    DollarSign,
    FileText,
    Gauge,
    Gavel,
    MessageCircleQuestion,
    Pencil,
    Printer,
    Send,
    ShieldCheck,
    Users,
} from 'lucide-react';
import { useState } from 'react';
import { AttachmentsPanel, type Attachment } from './_attachments';
import { CeoReportDialog, type MeetingOption } from './_dialogs';

interface DecisionSought {
    title: string;
    detail: string;
    recommendation: string;
}

interface MatterArising {
    title: string;
    status: string;
    update: string;
}

interface Report {
    id: number;
    title: string;
    status: 'draft' | 'submitted' | 'presented' | string;
    period_start: string | null;
    period_end: string | null;
    period_label: string | null;
    deadline: string | null;
    is_overdue: boolean;
    days_until_deadline: number | null;
    meeting: { id: number; title: string; scheduled_at: string | null } | null;
    author: { id: number; name: string } | null;
    presented_by: { id: number; name: string } | null;
    submitted_at: string | null;
    presented_at: string | null;
    created_at: string;
    executive_summary: string | null;
    operational_summary: string | null;
    key_achievements: string | null;
    challenges_and_risks: string | null;
    staffing_update: string | null;
    compliance_status: string | null;
    financial_summary: string | null;
    recommendations: string | null;
    decisions_sought: DecisionSought[];
    matters_arising: MatterArising[];
    kpi_snapshot: Record<string, any> | null;
    attachments: Attachment[];
    sections_complete: number;
}

interface Props extends PageProps {
    report: Report;
    meetings: MeetingOption[];
}

const SECTION_LIST = [
    { key: 'executive_summary', label: 'Executive summary' },
    { key: 'operational_summary', label: 'Operational summary' },
    { key: 'key_achievements', label: 'Key achievements' },
    { key: 'financial_summary', label: 'Financial summary' },
    { key: 'challenges_and_risks', label: 'Challenges & risks' },
    { key: 'compliance_status', label: 'Compliance status' },
    { key: 'staffing_update', label: 'Workforce update' },
    { key: 'recommendations', label: 'Strategic progress' },
] as const;

function paragraphs(value: string | null): string[] {
    if (!value) return [];
    return value
        .split(/\r\n|\r|\n/)
        .map((line) => line.trim())
        .filter(Boolean);
}

function SectionView({
    title,
    value,
    fallback,
}: {
    title: string;
    value: string | null;
    fallback: string;
}) {
    const paras = paragraphs(value);
    return (
        <Card>
            <CardContent className="space-y-2 p-5 print:p-3">
                <h3 className="text-base font-semibold text-foreground print:text-sm">
                    {title}
                </h3>
                {paras.length === 0 ? (
                    <p className="text-sm text-muted-foreground italic">
                        {fallback}
                    </p>
                ) : (
                    <div className="space-y-2 text-sm leading-relaxed text-foreground">
                        {paras.map((p, i) => (
                            <p key={i}>{p}</p>
                        ))}
                    </div>
                )}
            </CardContent>
        </Card>
    );
}

function StatusStrip({ report }: { report: Report }) {
    type Tile = {
        label: string;
        value: string;
        tone: 'success' | 'info' | 'warning' | 'critical' | 'muted';
    };

    const periodValue = report.period_label ?? 'Not set';
    const deadlineValue = report.deadline
        ? new Date(report.deadline).toLocaleDateString('en-NZ')
        : 'No deadline';
    const meetingValue = report.meeting?.title ?? 'No meeting';
    const statusLabel =
        report.status === 'draft'
            ? 'Draft'
            : report.status === 'submitted'
              ? 'Submitted'
              : report.status === 'presented'
                ? 'Presented'
                : report.status;
    const submittedValue = report.submitted_at
        ? new Date(report.submitted_at).toLocaleDateString('en-NZ')
        : '—';
    const presentedValue = report.presented_at
        ? new Date(report.presented_at).toLocaleDateString('en-NZ')
        : '—';

    const tiles: Tile[] = [
        {
            label: 'Period',
            value: periodValue,
            tone: report.period_label ? 'info' : 'warning',
        },
        {
            label: 'Deadline',
            value: deadlineValue,
            tone: report.is_overdue
                ? 'critical'
                : report.deadline
                  ? 'info'
                  : 'muted',
        },
        {
            label: 'Author',
            value: report.author?.name ?? 'Unknown',
            tone: 'info',
        },
        {
            label: 'Status',
            value: statusLabel,
            tone:
                report.status === 'presented'
                    ? 'success'
                    : report.status === 'submitted'
                      ? 'info'
                      : 'warning',
        },
        {
            label: 'For meeting',
            value: meetingValue,
            tone: report.meeting ? 'info' : 'warning',
        },
        {
            label: report.status === 'presented' ? 'Presented' : 'Submitted',
            value:
                report.status === 'presented' ? presentedValue : submittedValue,
            tone:
                report.status === 'presented' || report.status === 'submitted'
                    ? 'success'
                    : 'muted',
        },
    ];

    const TONE_VALUE: Record<Tile['tone'], string> = {
        success: 'text-status-success',
        info: 'text-foreground',
        warning: 'text-status-warning',
        critical: 'text-status-critical',
        muted: 'text-muted-foreground',
    };

    return (
        <div
            className="grid gap-3 md:grid-cols-3 xl:grid-cols-6 print:grid-cols-3"
            data-dusk="ceo-status-strip"
        >
            {tiles.map((t) => (
                <Card key={t.label}>
                    <CardContent className="p-4">
                        <p className="text-[10px] font-medium tracking-wide text-muted-foreground uppercase">
                            {t.label}
                        </p>
                        <p
                            className={cn(
                                'mt-1 truncate text-sm leading-snug font-semibold',
                                TONE_VALUE[t.tone],
                            )}
                            title={t.value}
                        >
                            {t.value}
                        </p>
                    </CardContent>
                </Card>
            ))}
        </div>
    );
}

function KpiSnapshotView({
    snapshot,
}: {
    snapshot: Record<string, any> | null;
}) {
    if (!snapshot) {
        return (
            <Card>
                <CardContent className="p-6 text-center text-sm text-muted-foreground">
                    No KPI snapshot was captured for this report. Snapshots are
                    captured automatically when the report is submitted to the
                    board.
                </CardContent>
            </Card>
        );
    }

    const tiles: Array<{
        label: string;
        value: string;
        tone: 'success' | 'warning' | 'critical' | 'info' | 'muted';
    }> = [];

    const tr = snapshot.top_risks ?? {};
    tiles.push({
        label: 'Critical risks',
        value: String(tr.critical ?? 0),
        tone: (tr.critical ?? 0) > 0 ? 'critical' : 'success',
    });
    tiles.push({
        label: 'Risks above appetite',
        value: String(tr.above_appetite ?? 0),
        tone: (tr.above_appetite ?? 0) > 0 ? 'warning' : 'success',
    });

    const cc = Array.isArray(snapshot.compliance_calendar)
        ? snapshot.compliance_calendar
        : [];
    const overdueCompliance = cc.filter(
        (c: any) => (c?.days_remaining ?? 1) < 0,
    ).length;
    tiles.push({
        label: 'Overdue obligations',
        value: String(overdueCompliance),
        tone: overdueCompliance > 0 ? 'critical' : 'success',
    });

    const inc = snapshot.incidents ?? {};
    tiles.push({
        label: 'Critical incidents',
        value: String(inc?.by_severity?.critical ?? 0),
        tone: (inc?.by_severity?.critical ?? 0) > 0 ? 'critical' : 'success',
    });

    const fin = snapshot.financial ?? {};
    const variance = Number(fin?.variance ?? 0);
    tiles.push({
        label: 'Budget variance',
        value: `${variance.toFixed(1)}%`,
        tone: Math.abs(variance) >= 5 ? 'warning' : 'success',
    });

    const wf = snapshot.workforce ?? {};
    const training = wf?.training_compliance;
    tiles.push({
        label: 'Training compliance',
        value: training == null ? '—' : `${Number(training).toFixed(0)}%`,
        tone:
            training == null
                ? 'muted'
                : Number(training) >= 95
                  ? 'success'
                  : 'warning',
    });

    const sg = snapshot.safeguarding ?? {};
    tiles.push({
        label: 'Open safeguarding',
        value: String(sg.open_concerns ?? 0),
        tone: (sg.open_concerns ?? 0) > 0 ? 'warning' : 'success',
    });

    const dr = snapshot.decisions_required ?? {};
    tiles.push({
        label: 'Decisions pending',
        value: String(dr.count ?? 0),
        tone: (dr.count ?? 0) > 0 ? 'warning' : 'success',
    });

    const TONE_VALUE: Record<string, string> = {
        success: 'text-status-success',
        info: 'text-foreground',
        warning: 'text-status-warning',
        critical: 'text-status-critical',
        muted: 'text-muted-foreground',
    };

    const capturedAt = snapshot.captured_at
        ? new Date(snapshot.captured_at).toLocaleString('en-NZ')
        : null;

    return (
        <Card>
            <CardContent className="space-y-4 p-5 print:p-3">
                <div className="flex flex-wrap items-start justify-between gap-2">
                    <div>
                        <h3 className="text-base font-semibold text-foreground">
                            KPI snapshot at submission
                        </h3>
                        <p className="text-xs text-muted-foreground">
                            The numbers as they stood when this report was
                            submitted to the board.
                        </p>
                    </div>
                    {capturedAt && (
                        <Badge variant="outline" className="text-[10px]">
                            Captured {capturedAt}
                        </Badge>
                    )}
                </div>

                <div className="grid gap-3 md:grid-cols-2 lg:grid-cols-4 print:grid-cols-2">
                    {tiles.map((t) => (
                        <div
                            key={t.label}
                            className="rounded-lg border border-border bg-muted/30 p-3"
                        >
                            <p className="text-[10px] font-medium tracking-wide text-muted-foreground uppercase">
                                {t.label}
                            </p>
                            <p
                                className={cn(
                                    'mt-1 text-xl font-semibold',
                                    TONE_VALUE[t.tone],
                                )}
                            >
                                {t.value}
                            </p>
                        </div>
                    ))}
                </div>
            </CardContent>
        </Card>
    );
}

function DecisionsView({ items }: { items: DecisionSought[] }) {
    if (!items?.length) {
        return (
            <Card>
                <CardContent className="p-6 text-center text-sm text-muted-foreground italic">
                    No board decisions requested in this report.
                </CardContent>
            </Card>
        );
    }
    return (
        <div className="grid gap-3 md:grid-cols-2 print:grid-cols-1">
            {items.map((d, i) => (
                <Card key={i}>
                    <CardContent className="space-y-2 p-4">
                        <div className="flex items-start gap-2">
                            <Gavel className="mt-0.5 h-4 w-4 text-primary" />
                            <p className="text-sm font-semibold text-foreground">
                                {d.title || `Decision ${i + 1}`}
                            </p>
                        </div>
                        {d.detail && (
                            <p className="text-sm text-muted-foreground">
                                {d.detail}
                            </p>
                        )}
                        {d.recommendation && (
                            <div className="rounded-lg border border-primary/20 bg-primary/5 p-2">
                                <p className="text-[10px] font-medium tracking-wide text-primary uppercase">
                                    CEO recommendation
                                </p>
                                <p className="mt-0.5 text-sm text-foreground">
                                    {d.recommendation}
                                </p>
                            </div>
                        )}
                    </CardContent>
                </Card>
            ))}
        </div>
    );
}

function MattersView({ items }: { items: MatterArising[] }) {
    if (!items?.length) {
        return (
            <Card>
                <CardContent className="p-6 text-center text-sm text-muted-foreground italic">
                    Nothing carried forward from the previous report.
                </CardContent>
            </Card>
        );
    }
    const TONE: Record<string, string> = {
        open: 'border-status-warning/30 bg-status-warning-bg text-status-warning',
        in_progress: 'border-status-info/30 bg-status-info-bg text-status-info',
        done: 'border-status-success/30 bg-status-success-bg text-status-success',
    };
    return (
        <div className="space-y-2">
            {items.map((m, i) => (
                <Card key={i}>
                    <CardContent className="space-y-2 p-4">
                        <div className="flex items-start justify-between gap-2">
                            <p className="text-sm font-semibold text-foreground">
                                {m.title || `Matter ${i + 1}`}
                            </p>
                            <Badge
                                className={cn(
                                    'border text-[10px] uppercase',
                                    TONE[m.status] ?? TONE.open,
                                )}
                            >
                                {(m.status ?? 'open').replace('_', ' ')}
                            </Badge>
                        </div>
                        {m.update && (
                            <p className="text-sm text-muted-foreground">
                                {m.update}
                            </p>
                        )}
                    </CardContent>
                </Card>
            ))}
        </div>
    );
}

export default function CeoReportShow({ auth, report, meetings }: Props) {
    const can =
        (
            auth as {
                can?: { governance?: { 'ceo-reports'?: { manage?: boolean } } };
            }
        )?.can?.governance?.['ceo-reports']?.manage ?? false;

    const [editOpen, setEditOpen] = useState(false);
    const [activeTab, setActiveTab] = useState<string>('executive');

    const handleSubmit = () =>
        router.post(
            `/governance/ceo-reports/${report.id}/submit`,
            {},
            { preserveScroll: true },
        );
    const handlePresent = () =>
        router.post(
            `/governance/ceo-reports/${report.id}/present`,
            {},
            { preserveScroll: true },
        );
    const handlePrint = () => window.print();

    const tabs: PageTabItem[] = [
        { value: 'executive', label: 'Executive summary', icon: FileText },
        { value: 'operations', label: 'Operations', icon: Briefcase },
        { value: 'financials', label: 'Financials', icon: DollarSign },
        { value: 'risks', label: 'Risk & Compliance', icon: ShieldCheck },
        { value: 'workforce', label: 'Workforce', icon: Users },
        { value: 'strategy', label: 'Strategy', icon: BookOpen },
        {
            value: 'decisions',
            label: `Decisions (${report.decisions_sought.length})`,
            icon: Gavel,
        },
        {
            value: 'matters',
            label: `Matters arising (${report.matters_arising.length})`,
            icon: MessageCircleQuestion,
        },
        {
            value: 'kpi',
            label: 'KPI snapshot',
            icon: Gauge,
            overflowable: true,
        },
        {
            value: 'attachments',
            label: `Attachments (${report.attachments.length})`,
            icon: ClipboardList,
            overflowable: true,
        },
    ];

    return (
        <AppLayout user={auth.user}>
            <Head title={report.title} />
            <PageLayout
                hero={
                    <PageHero
                        category="governance"
                        backHref="/governance/ceo-reports"
                        icon={FileText}
                        title={
                            <span
                                className="flex flex-wrap items-center gap-3"
                                dusk="ceo-report-title"
                            >
                                {report.title}
                                <Badge
                                    className={cn(
                                        'text-xs',
                                        governanceStatusColor(report.status),
                                    )}
                                >
                                    {report.status}
                                </Badge>
                                {report.is_overdue && (
                                    <Badge className="border border-status-critical/30 bg-status-critical-bg text-status-critical">
                                        <AlertOctagon className="mr-1 h-3 w-3" />
                                        Overdue
                                    </Badge>
                                )}
                            </span>
                        }
                        description={
                            <div className="flex flex-wrap items-center gap-3 text-sm">
                                {report.period_label && (
                                    <span className="inline-flex items-center gap-1">
                                        <CalendarDays className="h-4 w-4" />
                                        {report.period_label}
                                    </span>
                                )}
                                {report.author && (
                                    <span>By {report.author.name}</span>
                                )}
                                {report.meeting && (
                                    <span>For {report.meeting.title}</span>
                                )}
                            </div>
                        }
                        stats={[
                            {
                                label: 'Period',
                                value: report.period_label ?? '—',
                            },
                            {
                                label: 'Sections',
                                value: `${report.sections_complete}/${SECTION_LIST.length}`,
                            },
                            {
                                label: 'Decisions',
                                value: report.decisions_sought.length,
                            },
                            {
                                label: 'Matters',
                                value: report.matters_arising.length,
                            },
                        ]}
                        actions={
                            <div className="flex flex-wrap items-center gap-2">
                                {can && report.status === 'draft' && (
                                    <Button
                                        variant="outline"
                                        onClick={() => setEditOpen(true)}
                                    >
                                        <Pencil className="mr-1.5 h-4 w-4" />
                                        Edit report
                                    </Button>
                                )}
                                {can && report.status === 'draft' && (
                                    <Button onClick={handleSubmit}>
                                        <Send className="mr-1.5 h-4 w-4" />
                                        Submit to board
                                    </Button>
                                )}
                                {can && report.status === 'submitted' && (
                                    <Button onClick={handlePresent}>
                                        <CheckCircle2 className="mr-1.5 h-4 w-4" />
                                        Mark as presented
                                    </Button>
                                )}
                                <Button variant="outline" onClick={handlePrint}>
                                    <Printer className="mr-1.5 h-4 w-4" />
                                    Print / PDF
                                </Button>
                            </div>
                        }
                    />
                }
            >
                {can && (
                    <CeoReportDialog
                        isOpen={editOpen}
                        onClose={() => setEditOpen(false)}
                        meetings={meetings ?? []}
                        initial={{
                            id: report.id,
                            governance_meeting_id: report.meeting?.id
                                ? String(report.meeting.id)
                                : '',
                            period_start: report.period_start ?? '',
                            period_end: report.period_end ?? '',
                            deadline: report.deadline
                                ? new Date(report.deadline)
                                      .toISOString()
                                      .slice(0, 16)
                                : '',
                            executive_summary: report.executive_summary ?? '',
                            operational_summary:
                                report.operational_summary ?? '',
                            key_achievements: report.key_achievements ?? '',
                            challenges_and_risks:
                                report.challenges_and_risks ?? '',
                            staffing_update: report.staffing_update ?? '',
                            compliance_status: report.compliance_status ?? '',
                            financial_summary: report.financial_summary ?? '',
                            recommendations: report.recommendations ?? '',
                            decisions_sought: report.decisions_sought,
                            matters_arising: report.matters_arising,
                            attachments: report.attachments,
                        }}
                    />
                )}

                <StatusStrip report={report} />

                <div className="mt-6" data-dusk="ceo-report-tabs">
                    <PageTabs
                        value={activeTab}
                        onValueChange={setActiveTab}
                        items={tabs}
                    >
                        <TabsContent value="executive">
                            <SectionView
                                title="Executive summary"
                                value={report.executive_summary}
                                fallback="The CEO did not include an executive summary for this period."
                            />
                        </TabsContent>

                        <TabsContent value="operations" className="space-y-3">
                            <SectionView
                                title="Operational summary"
                                value={report.operational_summary}
                                fallback="No operational summary was included."
                            />
                            <SectionView
                                title="Key achievements"
                                value={report.key_achievements}
                                fallback="No key achievements were highlighted."
                            />
                        </TabsContent>

                        <TabsContent value="financials">
                            <SectionView
                                title="Financial summary"
                                value={report.financial_summary}
                                fallback="No financial commentary was included."
                            />
                        </TabsContent>

                        <TabsContent value="risks" className="space-y-3">
                            <SectionView
                                title="Challenges & risks"
                                value={report.challenges_and_risks}
                                fallback="No challenges or risks were flagged."
                            />
                            <SectionView
                                title="Compliance status"
                                value={report.compliance_status}
                                fallback="No compliance update was included."
                            />
                        </TabsContent>

                        <TabsContent value="workforce">
                            <SectionView
                                title="Workforce update"
                                value={report.staffing_update}
                                fallback="No workforce update was included."
                            />
                        </TabsContent>

                        <TabsContent value="strategy">
                            <SectionView
                                title="Strategic progress"
                                value={report.recommendations}
                                fallback="No strategic progress was reported."
                            />
                        </TabsContent>

                        <TabsContent value="decisions">
                            <DecisionsView items={report.decisions_sought} />
                        </TabsContent>

                        <TabsContent value="matters">
                            <MattersView items={report.matters_arising} />
                        </TabsContent>

                        <TabsContent value="kpi">
                            <KpiSnapshotView snapshot={report.kpi_snapshot} />
                        </TabsContent>

                        <TabsContent value="attachments">
                            <Card>
                                <CardContent className="p-5">
                                    <AttachmentsPanel
                                        reportId={report.id}
                                        attachments={report.attachments}
                                        canManage={can}
                                    />
                                </CardContent>
                            </Card>
                        </TabsContent>
                    </PageTabs>
                </div>
            </PageLayout>
        </AppLayout>
    );
}
