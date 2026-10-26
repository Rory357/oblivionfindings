import {
    PageHeader,
    PageHeaderMeterBig,
    PageHeaderMeterBlock,
    PageHeaderMeterCaption,
    PageHeaderRail,
    PageHeaderStatusChip,
    PageLayout,
} from '@/components/page';
import { TierTwoTabs } from '@/components/page/grouped-profile-nav';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { InfoCard } from '@/components/wizard/primitives';
import {
    SafetyFormDraftContext,
    useSafetyWorkspaceDrafts,
} from '@/hooks/use-safety-workspace-drafts';
import {
    hazardHref,
    hazardLocation,
    hazardPaneAllowed,
    type HazardPane,
} from '@/lib/health-safety/hazard-navigation';
import { Link, router, usePage } from '@inertiajs/react';
import {
    AlertTriangle,
    Camera,
    CheckCircle2,
    ClipboardCheck,
    FileText,
    Gauge,
    History,
    ListChecks,
    Play,
    ShieldAlert,
    ShieldCheck,
    UserPlus,
} from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { HazardConnectionsSection } from './hazard-connections';
import {
    HazardActionsSection,
    HazardEvidenceSection,
    HazardHistorySection,
    HazardOverviewSection,
    HazardPaneCancelContext,
    HazardPaneRenderer,
    HazardRiskSection,
} from './hazard-detail-dialog';
import {
    fmtDay,
    fmtWhen,
    hazardLabelOf,
    RISK,
    STATUS,
    type HazardDetail,
    type HazardSectionKey,
} from './hazard-kit';

const SECTIONS = [
    { key: 'overview', label: 'Overview', icon: FileText, group: 'hazard' },
    { key: 'risk', label: 'Risk & controls', icon: Gauge, group: 'hazard' },
    {
        key: 'actions',
        label: 'Corrective actions',
        icon: ListChecks,
        group: 'work',
    },
    { key: 'evidence', label: 'Evidence', icon: Camera, group: 'record' },
    { key: 'history', label: 'History', icon: History, group: 'record' },
    {
        key: 'connections',
        label: 'Connected work',
        icon: ListChecks,
        group: 'record',
    },
] as const;
const GROUPS = [
    { key: 'hazard', label: 'Hazard', icon: ShieldAlert },
    { key: 'work', label: 'Actions', icon: ListChecks },
    { key: 'record', label: 'Record & connections', icon: History },
];
const COMMANDS = [
    { kind: 'assign', label: 'Change owner or due date', icon: UserPlus },
    { kind: 'start', label: 'Start progress', icon: Play },
    {
        kind: 'mitigate',
        label: 'Record controls & residual risk',
        icon: ShieldCheck,
    },
    { kind: 'add_action', label: 'Add corrective action', icon: ListChecks },
    { kind: 'review', label: 'Record a review', icon: ClipboardCheck },
    { kind: 'close', label: 'Close hazard', icon: CheckCircle2 },
] as const;

/** Permanent record with the existing source-owned commands and full detail. */
export function HazardWorkspace({ detail: d }: { detail: HazardDetail }) {
    const url = usePage().url;
    const location = hazardLocation(url);
    const [section, setSection] = useState(location.section);
    const [pane, setPane] = useState<HazardPane | null>(location.pane);
    const drafts = useSafetyWorkspaceDrafts();
    const panelRef = useRef<HTMLDivElement>(null);
    const hasNavigated = useRef(false);
    useEffect(() => {
        const current = hazardLocation(url);
        setSection(current.section);
        setPane(current.pane);
    }, [url]);
    useEffect(() => {
        if (hasNavigated.current) panelRef.current?.focus();
        hasNavigated.current = true;
    }, [section, pane?.kind]);
    const href = (next: HazardSectionKey, nextPane?: HazardPane | null) =>
        hazardHref(d.id, {
            section: next,
            pane: nextPane,
            returnTo: location.returnTo,
        });
    const selectSection = (next: HazardSectionKey) =>
        drafts.request(() => {
            setPane(null);
            setSection(next);
            router.visit(href(next), {
                preserveState: true,
                preserveScroll: true,
            });
        });
    const changePane = (next: HazardPane | null) => {
        router.replace({
            url: href(section, next),
            preserveState: true,
            preserveScroll: true,
            onSuccess: () => setPane(next),
        });
    };
    const openPane = (next: HazardPane) =>
        drafts.request(() => changePane(next));
    const canAct = d.can.manage && d.status !== 'closed';
    const stage = STATUS[d.status];
    const risk = RISK[d.risk_rating];
    const openActions = d.actions.filter(
        (a) => a.status !== 'completed',
    ).length;
    const evidenceCount =
        d.photo_paths.length +
        d.document_paths.length +
        d.resolution_evidence.length;
    const active = SECTIONS.find((s) => s.key === section)!;
    const allowedPane = pane && hazardPaneAllowed(pane, d);
    const commands = COMMANDS.filter((command) =>
        hazardPaneAllowed({ kind: command.kind }, d),
    );
    const nextKind = !d.assigned_to
        ? 'assign'
        : d.status === 'open'
          ? 'start'
          : d.status === 'in_progress'
            ? 'mitigate'
            : 'review';

    return (
        <SafetyFormDraftContext.Provider value={drafts.register}>
            <PageLayout
                hero={
                    <PageHeader
                        variant="profile"
                        frontline
                        wrapTitle
                        backHref={location.returnTo}
                        icon={ShieldAlert}
                        title={d.reference_number}
                        titleChip={
                            <PageHeaderStatusChip
                                variant={stage?.tone ?? 'neutral'}
                            >
                                {stage?.label ?? 'Status unknown'}
                            </PageHeaderStatusChip>
                        }
                        subline={`${hazardLabelOf(d)} · ${d.site?.name ?? 'Site not recorded'} · Logged ${fmtWhen(d.created_at).main}`}
                        mobileSummary={`${d.assigned_to?.name ?? 'Needs an owner'} · ${openActions} actions open`}
                        meters={
                            <>
                                <PageHeaderMeterBlock
                                    label="Owner"
                                    onClick={() => selectSection('overview')}
                                    tone={d.assigned_to ? 'brand' : 'warning'}
                                >
                                    <PageHeaderMeterBig>
                                        {d.assigned_to?.name ?? 'Unassigned'}
                                    </PageHeaderMeterBig>
                                    <PageHeaderMeterCaption>
                                        Accountable for this hazard
                                    </PageHeaderMeterCaption>
                                </PageHeaderMeterBlock>
                                <PageHeaderMeterBlock
                                    label="Due date"
                                    onClick={() => selectSection('overview')}
                                >
                                    <PageHeaderMeterBig>
                                        {d.due_date
                                            ? fmtDay(d.due_date)
                                            : 'Not set'}
                                    </PageHeaderMeterBig>
                                    <PageHeaderMeterCaption>
                                        NZ calendar date
                                    </PageHeaderMeterCaption>
                                </PageHeaderMeterBlock>
                                <PageHeaderMeterBlock
                                    label="Risk rating"
                                    onClick={() => selectSection('risk')}
                                    tone={
                                        risk?.tone === 'neutral'
                                            ? 'brand'
                                            : (risk?.tone ?? 'brand')
                                    }
                                >
                                    <PageHeaderMeterBig>
                                        {risk?.label ?? 'Not assessed'}
                                    </PageHeaderMeterBig>
                                    <PageHeaderMeterCaption>
                                        {d.residual_risk_rating
                                            ? `Residual: ${RISK[d.residual_risk_rating]?.label ?? 'Not assessed'}`
                                            : 'Residual risk not assessed'}
                                    </PageHeaderMeterCaption>
                                </PageHeaderMeterBlock>
                                <PageHeaderMeterBlock
                                    label="Actions still open"
                                    onClick={() => selectSection('actions')}
                                    tone={openActions ? 'warning' : 'brand'}
                                >
                                    <PageHeaderMeterBig>
                                        {openActions}
                                    </PageHeaderMeterBig>
                                    <PageHeaderMeterCaption>
                                        {d.actions.length} recorded on this
                                        hazard
                                    </PageHeaderMeterCaption>
                                </PageHeaderMeterBlock>
                                <PageHeaderMeterBlock
                                    label="Evidence"
                                    onClick={() => selectSection('evidence')}
                                >
                                    <PageHeaderMeterBig>
                                        {evidenceCount}
                                    </PageHeaderMeterBig>
                                    <PageHeaderMeterCaption>
                                        Photos, documents & closure
                                    </PageHeaderMeterCaption>
                                </PageHeaderMeterBlock>
                            </>
                        }
                        rail={
                            <PageHeaderRail
                                items={GROUPS}
                                value={active.group}
                                ariaLabel="Hazard workspace groups"
                                onSelect={(key) =>
                                    selectSection(
                                        SECTIONS.find((s) => s.group === key)!
                                            .key,
                                    )
                                }
                            />
                        }
                    />
                }
                tabs={
                    <TierTwoTabs
                        tabs={SECTIONS.filter(
                            (s) => s.group === active.group,
                        ).map((s) => ({ ...s, href: href(s.key) }))}
                        activeTab={section}
                        onTab={(key) => selectSection(key as HazardSectionKey)}
                        testIdPrefix="hazard"
                        panelId="hazard-panel"
                        ariaLabel="Hazard sections"
                        renderLink={(tab, className, content, props) => (
                            <Link
                                href={tab.href!}
                                className={className}
                                {...props}
                            >
                                {content}
                            </Link>
                        )}
                    />
                }
            >
                <div className="grid min-w-0 items-start gap-5 xl:grid-cols-[minmax(0,1fr)_minmax(18rem,22rem)]">
                    <Card className="min-w-0">
                        <CardContent className="p-5">
                            <div
                                ref={panelRef}
                                id="hazard-panel"
                                role="tabpanel"
                                tabIndex={-1}
                                aria-labelledby={`hazard-tab-${section}`}
                                className="min-w-0 space-y-5 focus:outline-none"
                            >
                                {pane ? (
                                    allowedPane ? (
                                        <HazardPaneCancelContext.Provider
                                            value={() =>
                                                drafts.request(() =>
                                                    changePane(null),
                                                )
                                            }
                                        >
                                            <HazardPaneRenderer
                                                key={`${pane.kind}:${'actionId' in pane ? pane.actionId : ''}`}
                                                pane={pane}
                                                d={d}
                                                onDone={() => changePane(null)}
                                            />
                                        </HazardPaneCancelContext.Provider>
                                    ) : (
                                        <>
                                            <InfoCard
                                                icon={AlertTriangle}
                                                tone="warn"
                                            >
                                                This action is no longer
                                                available for this hazard or
                                                your permissions. Your record
                                                has not been changed.
                                            </InfoCard>
                                            <Button
                                                variant="outline"
                                                className="frontline-tap"
                                                onClick={() => changePane(null)}
                                            >
                                                Return to{' '}
                                                {active.label.toLowerCase()}
                                            </Button>
                                        </>
                                    )
                                ) : (
                                    <>
                                        {section === 'overview' && (
                                            <HazardOverviewSection d={d} />
                                        )}
                                        {section === 'risk' && (
                                            <HazardRiskSection d={d} />
                                        )}
                                        {section === 'actions' && (
                                            <HazardActionsSection
                                                d={d}
                                                canAct={canAct}
                                                onPane={openPane}
                                            />
                                        )}
                                        {section === 'evidence' && (
                                            <HazardEvidenceSection
                                                d={d}
                                                canAct={canAct}
                                            />
                                        )}
                                        {section === 'history' && (
                                            <HazardHistorySection d={d} />
                                        )}
                                        {section === 'connections' && (
                                            <HazardConnectionsSection
                                                connections={d.connections}
                                                returnTo={href('connections')}
                                            />
                                        )}
                                    </>
                                )}
                            </div>
                        </CardContent>
                    </Card>
                    <aside
                        className={`min-w-0 space-y-5 ${commands.length && !pane ? 'order-first xl:order-last' : ''}`}
                        aria-label="Hazard next steps"
                    >
                        <Card>
                            <CardHeader>
                                <CardTitle className="text-section-title">
                                    {commands.length
                                        ? 'Next steps'
                                        : 'Current position'}
                                </CardTitle>
                            </CardHeader>
                            <CardContent className="space-y-4">
                                <p className="text-sm">
                                    {d.status === 'closed'
                                        ? 'This hazard is closed. Its controls, evidence and history remain available.'
                                        : openActions
                                          ? `Complete the ${openActions} outstanding action${openActions === 1 ? '' : 's'} before closing this hazard.`
                                          : 'Check the controls and record a review before deciding whether the hazard can close.'}
                                </p>
                                <div className="flex flex-col gap-2">
                                    {commands.map((command) => (
                                        <Button
                                            key={command.kind}
                                            variant={
                                                command.kind === nextKind
                                                    ? 'default'
                                                    : 'outline'
                                            }
                                            className="frontline-tap h-auto justify-start py-2 text-left whitespace-normal"
                                            onClick={() =>
                                                openPane({ kind: command.kind })
                                            }
                                        >
                                            <command.icon className="mr-2 h-4 w-4 shrink-0" />
                                            {command.kind === 'assign' &&
                                            !d.assigned_to
                                                ? 'Assign an owner'
                                                : command.label}
                                        </Button>
                                    ))}
                                </div>
                                {!commands.length && d.status !== 'closed' && (
                                    <p className="text-sm text-muted-foreground">
                                        You can read this record. Changes need
                                        the relevant hazard permission.
                                    </p>
                                )}
                            </CardContent>
                        </Card>
                        <Card>
                            <CardHeader>
                                <CardTitle className="text-section-title">
                                    Closure requirements
                                </CardTitle>
                            </CardHeader>
                            <CardContent className="space-y-3 text-sm">
                                <p>
                                    {d.close_gate.actions_ok
                                        ? d.actions.length
                                            ? 'All hazard control actions are complete.'
                                            : 'No hazard control actions are recorded.'
                                        : d.close_gate.blockers.join(' ')}
                                </p>
                                <p>
                                    A resolution summary is required. Closing
                                    the hazard does not close a linked safety
                                    review, review action or Control Room
                                    response.
                                </p>
                            </CardContent>
                        </Card>
                    </aside>
                </div>
            </PageLayout>
            {drafts.confirmation}
        </SafetyFormDraftContext.Provider>
    );
}
