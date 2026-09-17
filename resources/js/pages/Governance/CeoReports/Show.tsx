import { ConfirmDialog } from '@/components/confirm-dialog';
import { useDialogDeepLink } from '@/components/governance/governance-dialog-deep-link';
import { GovernanceTermHint } from '@/components/governance/GovernanceTermHint';
import {
    PageHeader,
    PageHeaderGlassButton,
    PageHeaderMeterBig,
    PageHeaderMeterBlock,
    PageHeaderMeterCaption,
    PageHeaderPrimaryButton,
    PageHeaderStatusChip,
    PageLayout,
} from '@/components/page';
import { TierTwoTabs } from '@/components/page/grouped-profile-nav';
import { Card, CardContent } from '@/components/ui/card';
import { EmptyState } from '@/components/ui/empty-state';
import { StatusBadge } from '@/components/ui/status-badge';
import AppLayout from '@/layouts/app-layout';
import {
    formatDateLong,
    formatDateTimeLong,
    toDatetimeLocal,
} from '@/lib/datetime';
import { cn } from '@/lib/utils';
import { PageProps } from '@/types';
import { Head, Link, router } from '@inertiajs/react';
import {
    CheckCircle2,
    FileText,
    Gauge,
    Gavel,
    MessageCircleQuestion,
    Paperclip,
    Pencil,
    Printer,
    Send,
} from 'lucide-react';
import { useState, type ReactNode } from 'react';
import { AttachmentsPanel, type Attachment } from './_attachments';
import {
    CEO_REPORT_SECTIONS,
    CeoReportWizardDialog,
    ceoReportChip,
    matterStatus,
    type MeetingOption,
} from './_dialogs';

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
    kpi_snapshot: Record<string, unknown> | null;
    attachments: Attachment[];
    sections_complete: number;
}

interface Props extends PageProps {
    report: Report;
    meetings: MeetingOption[];
}

type TabKey = 'report' | 'decisions' | 'matters' | 'figures' | 'attachments';

/** The order a board member reads the report in. */
const READING_ORDER = [
    'executive_summary',
    'operational_summary',
    'key_achievements',
    'staffing_update',
    'financial_summary',
    'challenges_and_risks',
    'compliance_status',
    'recommendations',
] as const;

function paragraphs(value: string | null): string[] {
    if (!value) return [];
    return value
        .split(/\r\n|\r|\n/)
        .map((line) => line.trim())
        .filter(Boolean);
}

/* ── Key figures ───────────────────────────────────────────────────────── */

export interface KeyFigure {
    label: string;
    /** "Not available" when the source failed or had no data — never a 0. */
    value: string;
    tone: 'critical' | 'warning' | 'success' | 'neutral';
    hint?: ReactNode;
}

type Snapshot = Record<string, unknown> | null;

function objectAt(snapshot: Snapshot, key: string): Record<string, unknown> | null {
    const value = snapshot?.[key];
    return value && typeof value === 'object' && !Array.isArray(value)
        ? (value as Record<string, unknown>)
        : null;
}

function numberAt(source: Record<string, unknown> | null, key: string): number | null {
    const value = source?.[key];
    return typeof value === 'number' && Number.isFinite(value) ? value : null;
}

const NOT_AVAILABLE = 'Not available';

/** Figures saved when the report was submitted. Missing sources read "Not available". */
export function keyFigures(snapshot: Snapshot): KeyFigure[] {
    const risks = objectAt(snapshot, 'top_risks');
    const critical = numberAt(risks, 'critical');
    const aboveLimit = numberAt(risks, 'above_appetite');

    const calendar = snapshot?.compliance_calendar;
    const overdueRequirements = Array.isArray(calendar)
        ? calendar.filter(
              (item) =>
                  typeof (item as { days_remaining?: unknown })?.days_remaining ===
                      'number' &&
                  ((item as { days_remaining: number }).days_remaining < 0),
          ).length
        : null;

    const incidents = objectAt(snapshot, 'incidents');
    const bySeverity =
        incidents && typeof incidents.by_severity === 'object'
            ? (incidents.by_severity as Record<string, unknown>)
            : null;
    const criticalIncidents = bySeverity
        ? (numberAt(bySeverity, 'critical') ?? 0)
        : null;

    const finance = objectAt(snapshot, 'financial');
    const variance = numberAt(finance, 'variance');

    const workforce = objectAt(snapshot, 'workforce');
    const training = numberAt(workforce, 'training_compliance');

    const safeguarding = objectAt(snapshot, 'safeguarding');
    const openSafeguarding = numberAt(safeguarding, 'open_concerns');

    const decisions = objectAt(snapshot, 'decisions_required');
    const decisionsPending = numberAt(decisions, 'count');

    const count = (
        label: string,
        value: number | null,
        badTone: 'critical' | 'warning',
        hint?: ReactNode,
    ): KeyFigure =>
        value === null
            ? { label, value: NOT_AVAILABLE, tone: 'neutral', hint }
            : {
                  label,
                  value: String(value),
                  tone: value > 0 ? badTone : 'success',
                  hint,
              };

    return [
        count('Critical risks', critical, 'critical'),
        count(
            "Risks above the board's limit",
            aboveLimit,
            'warning',
            <GovernanceTermHint term="board_limit" />,
        ),
        count('Overdue requirements', overdueRequirements, 'critical'),
        count('Critical incidents', criticalIncidents, 'critical'),
        variance === null
            ? { label: 'Budget position', value: NOT_AVAILABLE, tone: 'neutral' }
            : {
                  label: 'Budget position',
                  value:
                      variance === 0
                          ? 'On budget'
                          : `${variance > 0 ? 'Over' : 'Under'} budget by ${Math.abs(variance).toFixed(1)}%`,
                  tone: Math.abs(variance) >= 5 ? 'warning' : 'success',
              },
        training === null
            ? { label: 'Staff up to date with training', value: NOT_AVAILABLE, tone: 'neutral' }
            : {
                  label: 'Staff up to date with training',
                  value: `${training.toFixed(0)}%`,
                  tone: training >= 95 ? 'success' : 'warning',
              },
        count('Open safeguarding concerns', openSafeguarding, 'warning'),
        count('Decisions waiting for the board', decisionsPending, 'warning'),
    ];
}

const FIGURE_TONE: Record<KeyFigure['tone'], string> = {
    critical: 'text-status-critical',
    warning: 'text-status-warning',
    success: 'text-status-success',
    neutral: 'text-muted-foreground',
};

function KeyFiguresView({ snapshot }: { snapshot: Snapshot }) {
    if (!snapshot) {
        return (
            <EmptyState
                icon={Gauge}
                title="No key figures yet"
                description="The figures are saved automatically when the report is submitted to the board."
            />
        );
    }

    const capturedAt =
        typeof snapshot.captured_at === 'string' ? snapshot.captured_at : null;

    return (
        <Card>
            <CardContent className="flex flex-col gap-4 p-5 print:p-3">
                <div>
                    <h2 className="text-section-title">
                        Key figures when the report was submitted
                    </h2>
                    <p className="text-subtle">
                        {capturedAt
                            ? `As they stood on ${formatDateTimeLong(capturedAt)}. "Not available" means the figure couldn't be worked out at the time.`
                            : `"Not available" means the figure couldn't be worked out at the time.`}
                    </p>
                </div>
                <dl className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4 print:grid-cols-2">
                    {keyFigures(snapshot).map((figure) => (
                        <div
                            key={figure.label}
                            className="rounded-lg border border-border p-3"
                        >
                            <dt className="text-caption flex items-center gap-1">
                                {figure.label}
                                {figure.hint}
                            </dt>
                            <dd
                                className={cn(
                                    'mt-1 text-sm font-semibold tabular-nums',
                                    FIGURE_TONE[figure.tone],
                                )}
                            >
                                {figure.value}
                            </dd>
                        </div>
                    ))}
                </dl>
            </CardContent>
        </Card>
    );
}

/* ── Sections ──────────────────────────────────────────────────────────── */

const FALLBACKS: Record<string, string> = {
    executive_summary: 'No executive summary was included.',
    operational_summary: 'No operational summary was included.',
    key_achievements: 'No key achievements were highlighted.',
    staffing_update: 'No workforce update was included.',
    financial_summary: 'No financial summary was included.',
    challenges_and_risks: 'No challenges or risks were raised.',
    compliance_status: 'No compliance update was included.',
    recommendations: 'No strategic progress was reported.',
};

function ReportDocument({ report }: { report: Report }) {
    const sections = READING_ORDER.map((key) => ({
        key,
        label:
            CEO_REPORT_SECTIONS.find((section) => section.key === key)?.label ??
            key,
        value: report[key],
    }));

    return (
        <Card>
            <CardContent className="flex flex-col gap-6 p-5 print:p-3">
                <nav aria-label="Contents" className="print:hidden">
                    <h2 className="text-section-title">Contents</h2>
                    <ol className="mt-2 grid gap-1 sm:grid-cols-2">
                        {sections.map((section, index) => (
                            <li key={section.key}>
                                <a
                                    href={`#ceo-section-${section.key}`}
                                    className="text-sm text-primary underline-offset-4 hover:underline"
                                >
                                    {index + 1}. {section.label}
                                </a>
                            </li>
                        ))}
                    </ol>
                </nav>
                {sections.map((section) => {
                    const paras = paragraphs(section.value);
                    return (
                        <section
                            key={section.key}
                            id={`ceo-section-${section.key}`}
                            className="scroll-mt-5 border-t border-border pt-4"
                        >
                            <h3 className="text-section-title">
                                {section.label}
                            </h3>
                            {paras.length === 0 ? (
                                <p className="text-subtle mt-1">
                                    {FALLBACKS[section.key]}
                                </p>
                            ) : (
                                <div className="mt-2 flex flex-col gap-2 text-sm leading-relaxed">
                                    {paras.map((p, i) => (
                                        <p key={i}>{p}</p>
                                    ))}
                                </div>
                            )}
                        </section>
                    );
                })}
            </CardContent>
        </Card>
    );
}

function DecisionsView({ items }: { items: DecisionSought[] }) {
    if (!items?.length) {
        return (
            <EmptyState
                icon={Gavel}
                title="No decisions sought"
                description="The CEO didn't ask the board to decide anything in this report."
            />
        );
    }
    return (
        <div className="grid gap-5 md:grid-cols-2 print:grid-cols-1">
            {items.map((d, i) => (
                <Card key={i}>
                    <CardContent className="flex flex-col gap-2 p-4">
                        <p className="flex items-start gap-2 text-sm font-semibold">
                            <Gavel className="mt-0.5 h-4 w-4 shrink-0 text-primary" />
                            {d.title || `Decision ${i + 1}`}
                        </p>
                        {d.detail ? (
                            <p className="text-subtle">{d.detail}</p>
                        ) : null}
                        {d.recommendation ? (
                            <div className="rounded-lg border border-border p-2">
                                <p className="text-caption">
                                    The CEO recommends
                                </p>
                                <p className="mt-0.5 text-sm">
                                    {d.recommendation}
                                </p>
                            </div>
                        ) : null}
                    </CardContent>
                </Card>
            ))}
        </div>
    );
}

function MattersView({ items }: { items: MatterArising[] }) {
    if (!items?.length) {
        return (
            <EmptyState
                icon={MessageCircleQuestion}
                title="No matters arising"
                description="Nothing was carried forward from the last meeting."
            />
        );
    }
    return (
        <div className="flex flex-col gap-5">
            {items.map((m, i) => {
                const status = matterStatus(m.status);
                return (
                    <Card key={i}>
                        <CardContent className="flex flex-col gap-2 p-4">
                            <div className="flex items-start justify-between gap-2">
                                <p className="text-sm font-semibold">
                                    {m.title || `Matter ${i + 1}`}
                                </p>
                                <StatusBadge size="sm" variant={status.variant}>
                                    {status.label}
                                </StatusBadge>
                            </div>
                            {m.update ? (
                                <p className="text-subtle">{m.update}</p>
                            ) : null}
                        </CardContent>
                    </Card>
                );
            })}
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

    const isDraft = report.status === 'draft';
    const canEdit = can && isDraft;
    const [editOpen, setEditOpen] = useDialogDeepLink('edit', canEdit);
    const [tab, setTab] = useState<TabKey>('report');
    const [confirm, setConfirm] = useState<'submit' | 'present' | null>(null);

    const chip = ceoReportChip(report.status, report.is_overdue);

    const submit = () =>
        router.post(
            `/governance/ceo-reports/${report.id}/submit`,
            {},
            { preserveScroll: true },
        );
    const present = () =>
        router.post(
            `/governance/ceo-reports/${report.id}/present`,
            {},
            { preserveScroll: true },
        );

    const tabs: { key: TabKey; label: string; icon: typeof FileText; count?: number }[] = [
        { key: 'report', label: 'Report', icon: FileText },
        {
            key: 'decisions',
            label: 'Decisions sought',
            icon: Gavel,
            count: report.decisions_sought.length,
        },
        {
            key: 'matters',
            label: 'Matters arising',
            icon: MessageCircleQuestion,
            count: report.matters_arising.length,
        },
        { key: 'figures', label: 'Key figures', icon: Gauge },
        {
            key: 'attachments',
            label: 'Attachments',
            icon: Paperclip,
            count: report.attachments.length,
        },
    ];

    // Every section renders so "Print or save as PDF" includes the whole
    // report; only the chosen tab shows on screen.
    const panel = (key: TabKey, children: ReactNode) => (
        <div key={key} className={tab === key ? 'block' : 'hidden print:block'}>
            {children}
        </div>
    );

    const deadlineCaption = report.deadline
        ? report.is_overdue
            ? 'Past the deadline'
            : isDraft && report.days_until_deadline !== null
              ? report.days_until_deadline <= 0
                  ? 'Due today'
                  : `Due in ${report.days_until_deadline} day${report.days_until_deadline === 1 ? '' : 's'}`
              : 'Deadline'
        : 'No deadline set';

    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Home', href: '/dashboard' },
                { title: 'Governance', href: '/governance/dashboard' },
                { title: 'CEO reports', href: '/governance/ceo-reports' },
                {
                    title: report.title,
                    href: `/governance/ceo-reports/${report.id}`,
                },
            ]}
        >
            <Head title={report.title} />
            <PageLayout
                hero={
                    <PageHeader
                        variant="profile"
                        backHref="/governance/ceo-reports"
                        icon={FileText}
                        title={report.title}
                        titleDusk="ceo-report-title"
                        wrapTitle
                        titleChip={
                            <PageHeaderStatusChip variant={chip.variant}>
                                {chip.label}
                            </PageHeaderStatusChip>
                        }
                        subline={[
                            report.period_label
                                ? `Covers ${report.period_label}`
                                : null,
                            report.author ? `By ${report.author.name}` : null,
                            report.presented_at
                                ? `Presented ${formatDateLong(report.presented_at)}`
                                : report.submitted_at
                                  ? `Submitted ${formatDateLong(report.submitted_at)}`
                                  : null,
                        ]
                            .filter(Boolean)
                            .join(' · ')}
                        meters={
                            <>
                                <PageHeaderMeterBlock
                                    label="Sections written"
                                    onClick={() => setTab('report')}
                                    ariaLabel="Read the report"
                                >
                                    <PageHeaderMeterBig>
                                        {report.sections_complete} of{' '}
                                        {CEO_REPORT_SECTIONS.length}
                                    </PageHeaderMeterBig>
                                    <PageHeaderMeterCaption>
                                        Report sections
                                    </PageHeaderMeterCaption>
                                </PageHeaderMeterBlock>
                                <PageHeaderMeterBlock
                                    label="Decisions sought"
                                    onClick={() => setTab('decisions')}
                                    ariaLabel="View the decisions the CEO is asking for"
                                >
                                    <PageHeaderMeterBig>
                                        {report.decisions_sought.length}
                                    </PageHeaderMeterBig>
                                    <PageHeaderMeterCaption>
                                        For the board to decide
                                    </PageHeaderMeterCaption>
                                </PageHeaderMeterBlock>
                                <PageHeaderMeterBlock
                                    label="Deadline"
                                    tone={report.is_overdue ? 'critical' : 'brand'}
                                    href={
                                        report.is_overdue
                                            ? '/governance/ceo-reports?status=overdue'
                                            : '/governance/ceo-reports'
                                    }
                                    ariaLabel="View CEO reports by deadline"
                                >
                                    <PageHeaderMeterBig>
                                        {report.deadline
                                            ? formatDateLong(report.deadline)
                                            : 'Not set'}
                                    </PageHeaderMeterBig>
                                    <PageHeaderMeterCaption>
                                        {deadlineCaption}
                                    </PageHeaderMeterCaption>
                                </PageHeaderMeterBlock>
                                {report.meeting ? (
                                    <PageHeaderMeterBlock
                                        label="Meeting"
                                        href={`/governance/meetings/${report.meeting.id}`}
                                        ariaLabel="Go to the meeting"
                                    >
                                        <PageHeaderMeterBig>
                                            {report.meeting.scheduled_at
                                                ? formatDateLong(
                                                      report.meeting.scheduled_at,
                                                  )
                                                : 'Date not set'}
                                        </PageHeaderMeterBig>
                                        <PageHeaderMeterCaption>
                                            {report.meeting.title}
                                        </PageHeaderMeterCaption>
                                    </PageHeaderMeterBlock>
                                ) : null}
                            </>
                        }
                        actions={
                            <>
                                <PageHeaderGlassButton
                                    icon={Printer}
                                    onClick={() => window.print()}
                                >
                                    Print or save as PDF
                                </PageHeaderGlassButton>
                                {canEdit ? (
                                    <PageHeaderGlassButton
                                        icon={Pencil}
                                        onClick={() => setEditOpen(true)}
                                    >
                                        Edit report
                                    </PageHeaderGlassButton>
                                ) : null}
                                {can && isDraft ? (
                                    <PageHeaderPrimaryButton
                                        icon={Send}
                                        onClick={() => setConfirm('submit')}
                                    >
                                        Submit to board
                                    </PageHeaderPrimaryButton>
                                ) : null}
                                {can && report.status === 'submitted' ? (
                                    <PageHeaderPrimaryButton
                                        icon={CheckCircle2}
                                        onClick={() => setConfirm('present')}
                                    >
                                        Mark as presented
                                    </PageHeaderPrimaryButton>
                                ) : null}
                            </>
                        }
                    />
                }
                tabs={
                    <div data-dusk="ceo-report-tabs" className="print:hidden">
                        <TierTwoTabs
                            tabs={tabs}
                            activeTab={tab}
                            onTab={(key) => setTab(key as TabKey)}
                            testIdPrefix="ceo-report"
                            ariaLabel="Report sections"
                            panelId="ceo-report-panel"
                            renderLink={() => null}
                        />
                    </div>
                }
            >
                {canEdit ? (
                    <CeoReportWizardDialog
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
                            // NZ wall time — slicing the ISO string would show UTC.
                            deadline: toDatetimeLocal(report.deadline),
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
                ) : null}

                <div
                    id="ceo-report-panel"
                    role="tabpanel"
                    aria-labelledby={`ceo-report-tab-${tab}`}
                    className="flex flex-col gap-5"
                >
                    {!isDraft && can ? (
                        <p className="text-subtle print:hidden">
                            This report has been submitted, so it can&apos;t be
                            edited.
                            {report.meeting ? (
                                <>
                                    {' '}
                                    <Link
                                        href={`/governance/meetings/${report.meeting.id}`}
                                        className="text-primary underline-offset-4 hover:underline"
                                    >
                                        Go to the meeting
                                    </Link>
                                </>
                            ) : null}
                        </p>
                    ) : null}

                    {panel('report', <ReportDocument report={report} />)}
                    {panel(
                        'decisions',
                        <DecisionsView items={report.decisions_sought} />,
                    )}
                    {panel(
                        'matters',
                        <MattersView items={report.matters_arising} />,
                    )}
                    {panel(
                        'figures',
                        <KeyFiguresView snapshot={report.kpi_snapshot} />,
                    )}
                    {panel(
                        'attachments',
                        <Card>
                            <CardContent className="p-5">
                                <AttachmentsPanel
                                    reportId={report.id}
                                    attachments={report.attachments}
                                    canManage={canEdit}
                                />
                            </CardContent>
                        </Card>,
                    )}
                </div>
            </PageLayout>

            <ConfirmDialog
                open={confirm === 'submit'}
                onClose={() => setConfirm(null)}
                onConfirm={submit}
                title="Submit your report to the board?"
                description="Board members will be able to read it. You won't be able to edit it afterwards, and the key figures are saved as they stand right now."
                confirmText="Submit to board"
                variant="default"
            />
            <ConfirmDialog
                open={confirm === 'present'}
                onClose={() => setConfirm(null)}
                onConfirm={present}
                title="Mark this report as presented?"
                description={`This records that the report was presented to the board${report.meeting ? ` at ${report.meeting.title}` : ''}. It can't be undone.`}
                confirmText="Mark as presented"
                variant="default"
            />
        </AppLayout>
    );
}
