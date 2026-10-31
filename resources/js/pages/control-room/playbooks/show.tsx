import {
    plainLabel,
    severityBadge,
} from '@/components/control-room/alert-record/alert-labels';
import { ControlRoomPage } from '@/components/control-room/control-room-page';
import {
    ControlRoomRowActions,
    type ControlRoomRowAction,
} from '@/components/control-room/control-room-row-actions';
import { PlaybookWizard } from '@/components/control-room/playbook-wizard';
import {
    TurnOffProcedureConfirm,
    turnOnProcedure,
} from '@/components/control-room/procedure-turn-off-confirm';
import {
    PageHeaderGlassButton,
    PageHeaderMeterBig,
    PageHeaderMeterBlock,
    PageHeaderMeterCaption,
    PageHeaderPrimaryButton,
    PageHeaderRail,
    PageHeaderStatusChip,
} from '@/components/page';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { EmptyState } from '@/components/ui/empty-state';
import { StatusBadge, type StatusVariant } from '@/components/ui/status-badge';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import {
    formatDateTime,
    formatDurationMinutes,
    formatRelative,
} from '@/lib/datetime';
import { Link, router } from '@inertiajs/react';
import {
    BookOpen,
    Eye,
    FileText,
    FileWarning,
    History,
    Image,
    ListChecks,
    MessageSquareQuote,
    Package,
    Paperclip,
    PenLine,
    Pencil,
    Power,
    StickyNote,
    Video,
    type LucideIcon,
} from 'lucide-react';
import { useState, type ReactNode } from 'react';

// --- Types ---

interface PlaybookStep {
    id: number;
    order: number;
    title: string;
    type: string;
    instructions: string | null;
    is_required: boolean;
    is_blocking: boolean;
    time_limit_minutes: number | null;
    decision_options: string[] | null;
    notify_config: Record<string, unknown> | null;
    evidence_config: Record<string, unknown> | null;
}

interface PlaybookDetail {
    id: number;
    name: string;
    code: string | null;
    description: string | null;
    category: string;
    version: number;
    is_active: boolean;
    auto_attach: boolean;
    /** "Start on its own" is on but no alert type or severity is chosen, so it never starts on its own. */
    auto_start_missing_triggers: boolean;
    trigger_alert_types: string[];
    trigger_severities: string[];
    sla_acknowledge_minutes: number | null;
    sla_response_minutes: number | null;
    sla_resolution_minutes: number | null;
    required_evidence: string[];
    requires_approval: boolean;
    approval_roles: string[];
    escalation_after_minutes: number | null;
    escalation_targets: string[];
    created_by: { id: number; name: string } | null;
    updated_by: { id: number; name: string } | null;
    created_at: string | null;
    updated_at: string | null;
    steps: PlaybookStep[];
}

interface RunAlert {
    id: number;
    alert_type: string;
    severity: string;
    status: string;
}

interface RunEntry {
    id: number;
    alert_id: number;
    alert: RunAlert | null;
    status: string;
    current_step: number;
    completed_steps: number;
    total_steps: number;
    progress: number;
    started_at: string | null;
    completed_at: string | null;
    started_by: { id: number; name: string } | null;
    completed_by: { id: number; name: string } | null;
}

interface Props {
    playbook: PlaybookDetail;
    recentRuns: RunEntry[];
    categories: Record<string, string>;
    stepTypes: Record<string, string>;
    alertTypes: string[];
    can: {
        manage: boolean;
    };
}

type RecordTab = 'steps' | 'evidence' | 'runs';

/** ?edit=starting (from the procedures list) opens the edit wizard on "Starting and targets". */
const EDIT_STARTING = 'starting';

const TABS: ReadonlyArray<{ key: RecordTab; label: string; icon: LucideIcon }> = [
    { key: 'steps', label: 'Steps', icon: ListChecks },
    { key: 'evidence', label: 'Required evidence', icon: Package },
    { key: 'runs', label: 'Run history', icon: History },
];

const RUN_STATUS: Record<string, { label: string; tone: StatusVariant }> = {
    pending: { label: 'Not started', tone: 'neutral' },
    in_progress: { label: 'In progress', tone: 'info' },
    completed: { label: 'Done', tone: 'success' },
    failed: { label: 'Failed', tone: 'critical' },
    cancelled: { label: 'Cancelled', tone: 'neutral' },
};

const EVIDENCE_ICON: Record<string, LucideIcon> = {
    photo: Image,
    video: Video,
    document: FileText,
    signature: PenLine,
    witness_statement: MessageSquareQuote,
    incident_report: FileWarning,
    note: StickyNote,
};

// --- Helpers ---

function initialTab(): RecordTab {
    if (typeof window === 'undefined') return 'steps';
    const tab = new URLSearchParams(window.location.search).get('tab');
    return TABS.some((t) => t.key === tab) ? (tab as RecordTab) : 'steps';
}

function editStartingRequested(): boolean {
    if (typeof window === 'undefined') return false;
    return new URLSearchParams(window.location.search).get('edit') === EDIT_STARTING;
}

/** Drop ?edit= once the wizard closes, so a refresh doesn't reopen it. */
function forgetEditRequest() {
    const url = new URL(window.location.href);
    if (!url.searchParams.has('edit')) return;
    url.searchParams.delete('edit');
    window.history.replaceState(window.history.state, '', url.toString());
}

/** Keep the open tab in the address so a refresh or a shared link returns to it. */
function rememberTab(tab: RecordTab) {
    const url = new URL(window.location.href);
    if (tab === 'steps') url.searchParams.delete('tab');
    else url.searchParams.set('tab', tab);
    window.history.replaceState(window.history.state, '', url.toString());
}

function runStatus(status: string) {
    return RUN_STATUS[status] ?? { label: plainLabel(status), tone: 'neutral' as const };
}

function runMinutes(run: RunEntry): number | null {
    if (!run.started_at || !run.completed_at) return null;
    const ms = new Date(run.completed_at).getTime() - new Date(run.started_at).getTime();
    return Number.isFinite(ms) && ms >= 0 ? Math.floor(ms / 60000) : null;
}

function Panel({
    id,
    title,
    caption,
    children,
}: {
    id?: string;
    title: string;
    caption?: string;
    children: ReactNode;
}) {
    return (
        <Card id={id} tabIndex={id ? -1 : undefined} className="min-w-0 gap-4 px-5 py-5 outline-none">
            <div className="min-w-0">
                <h2 className="text-section-title">{title}</h2>
                {caption ? <p className="text-subtle mt-0.5">{caption}</p> : null}
            </div>
            {children}
        </Card>
    );
}

/** Label / value rows; rows without a value are left out. */
function Facts({ rows }: { rows: Array<[string, ReactNode | null | undefined | false]> }) {
    const shown = rows.filter(([, value]) => value !== null && value !== undefined && value !== false && value !== '');
    if (!shown.length) return null;
    return (
        <dl className="grid gap-3">
            {shown.map(([label, value]) => (
                <div key={label} className="grid gap-0.5">
                    <dt className="text-caption">{label}</dt>
                    <dd className="text-sm text-foreground">{value}</dd>
                </div>
            ))}
        </dl>
    );
}

// --- Page ---

/**
 * One response procedure (code name: playbook) — its steps, the evidence it
 * asks for and when it was last run, with when it starts and its time
 * targets alongside. Anyone who can see Control Room can read it; editors
 * edit it in the procedure wizard and turn it on or off.
 */
export default function PlaybookShow({
    playbook,
    recentRuns,
    categories,
    stepTypes,
    alertTypes,
    can,
}: Props) {
    // Which step the edit wizard opens on; null = closed.
    const [wizardAt, setWizardAt] = useState<'basics' | 'automation' | null>(() =>
        can.manage && editStartingRequested() ? 'automation' : null,
    );
    const openWizard = (at: 'basics' | 'automation' = 'basics') => setWizardAt(at);
    const closeWizard = () => {
        setWizardAt(null);
        forgetEditRequest();
    };
    const [turningOff, setTurningOff] = useState(false);
    const missingTriggers = playbook.auto_start_missing_triggers;
    const [tab, setTab] = useState<RecordTab>(initialTab);
    const selectTab = (next: RecordTab) => {
        setTab(next);
        rememberTab(next);
    };

    const category = categories[playbook.category] ?? plainLabel(playbook.category);
    const required = playbook.steps.filter((step) => step.is_required).length;
    const evidenceSteps = playbook.steps.filter((step) => step.type === 'evidence');
    const lastRun = recentRuns[0] ?? null;

    const targets: Array<[string, number]> = (
        [
            ['Acknowledge within', playbook.sla_acknowledge_minutes],
            ['Respond within', playbook.sla_response_minutes],
            ['Resolve within', playbook.sla_resolution_minutes],
        ] as Array<[string, number | null]>
    ).filter((row): row is [string, number] => Boolean(row[1]));
    const hasTargets = targets.length > 0 || Boolean(playbook.escalation_after_minutes);

    const showTargets = () => {
        if (tab === 'runs') selectTab('steps');
        window.requestAnimationFrame(() => {
            const card = document.getElementById('procedure-targets');
            card?.scrollIntoView({ behavior: 'smooth', block: 'start' });
            card?.focus({ preventScroll: true });
        });
    };

    const meters = (
        <>
            <PageHeaderMeterBlock
                label="Steps"
                onClick={() => selectTab('steps')}
                pressed={tab === 'steps'}
                ariaLabel="Show the response steps"
            >
                <PageHeaderMeterBig>{playbook.steps.length}</PageHeaderMeterBig>
                <PageHeaderMeterCaption>
                    {required === playbook.steps.length
                        ? 'all required'
                        : `${required} required`}
                </PageHeaderMeterCaption>
            </PageHeaderMeterBlock>
            <PageHeaderMeterBlock
                label="Required evidence"
                onClick={() => selectTab('evidence')}
                pressed={tab === 'evidence'}
                ariaLabel="Show the evidence this procedure asks for"
            >
                <PageHeaderMeterBig>{playbook.required_evidence.length}</PageHeaderMeterBig>
                <PageHeaderMeterCaption>
                    {playbook.required_evidence.length
                        ? playbook.required_evidence.map(plainLabel).join(', ')
                        : 'nothing to collect'}
                </PageHeaderMeterCaption>
            </PageHeaderMeterBlock>
            <PageHeaderMeterBlock
                label="Last started"
                onClick={() => selectTab('runs')}
                pressed={tab === 'runs'}
                ariaLabel="Show the run history"
            >
                <PageHeaderMeterBig>
                    {lastRun?.started_at ? formatRelative(lastRun.started_at) : 'Not yet'}
                </PageHeaderMeterBig>
                <PageHeaderMeterCaption>
                    {lastRun ? runStatus(lastRun.status).label : 'not run on an alert yet'}
                </PageHeaderMeterCaption>
            </PageHeaderMeterBlock>
            {targets.length ? (
                <PageHeaderMeterBlock
                    label={targets[0][0]}
                    onClick={showTargets}
                    ariaLabel="Show this procedure's time targets"
                >
                    <PageHeaderMeterBig>{formatDurationMinutes(targets[0][1])}</PageHeaderMeterBig>
                    <PageHeaderMeterCaption>
                        {targets.length > 1
                            ? targets
                                  .slice(1)
                                  .map(([label, minutes]) =>
                                      `${label.replace(/ within$/, '').toLowerCase()} ${formatDurationMinutes(minutes)}`,
                                  )
                                  .join(' · ')
                            : 'time target'}
                    </PageHeaderMeterCaption>
                </PageHeaderMeterBlock>
            ) : null}
        </>
    );

    const actions = can.manage ? (
        <>
            <PageHeaderGlassButton
                icon={Power}
                onClick={() =>
                    playbook.is_active ? setTurningOff(true) : turnOnProcedure(playbook)
                }
            >
                {playbook.is_active ? 'Turn off' : 'Turn on'}
            </PageHeaderGlassButton>
            <PageHeaderPrimaryButton icon={Pencil} onClick={() => openWizard()}>
                Edit procedure
            </PageHeaderPrimaryButton>
        </>
    ) : undefined;

    const side = (
        <div className="flex min-w-0 flex-col gap-5">
            <Panel title="When it starts">
                {missingTriggers ? (
                    <div className="grid justify-items-start gap-1.5">
                        <StatusBadge variant="warning" size="sm">
                            Won&apos;t start on its own
                        </StatusBadge>
                        <p className="text-subtle">
                            “Start on its own” is on, but no alert type or
                            severity is chosen, so it only starts when someone
                            starts it from the alert.
                        </p>
                        {can.manage ? (
                            <Button
                                variant="outline"
                                size="sm"
                                onClick={() => openWizard('automation')}
                            >
                                Choose which alerts start it
                            </Button>
                        ) : null}
                    </div>
                ) : null}
                <Facts
                    rows={[
                        [
                            'Starts on its own',
                            missingTriggers
                                ? null
                                : playbook.auto_attach
                                  ? 'Yes, for matching new alerts'
                                  : 'No — start it from the alert',
                        ],
                        [
                            'Alert types',
                            playbook.trigger_alert_types.length
                                ? playbook.trigger_alert_types.map(plainLabel).join(', ')
                                : null,
                        ],
                        [
                            'Severities',
                            playbook.trigger_severities.length ? (
                                <span className="flex flex-wrap gap-1">
                                    {playbook.trigger_severities.map((value) => (
                                        <StatusBadge
                                            key={value}
                                            variant={severityBadge(value).tone}
                                            size="sm"
                                        >
                                            {plainLabel(value)}
                                        </StatusBadge>
                                    ))}
                                </span>
                            ) : null,
                        ],
                        [
                            'Sign-off',
                            playbook.requires_approval
                                ? "A manager signs off before it's finished"
                                : null,
                        ],
                        [
                            'Who signs off',
                            playbook.approval_roles.length
                                ? playbook.approval_roles.map(plainLabel).join(', ')
                                : null,
                        ],
                        [
                            'Escalates to',
                            playbook.escalation_targets.length
                                ? playbook.escalation_targets.map(plainLabel).join(', ')
                                : null,
                        ],
                    ]}
                />
            </Panel>
            {hasTargets ? (
                <Panel id="procedure-targets" title="Time targets">
                    <Facts
                        rows={[
                            ...targets.map(
                                ([label, minutes]) =>
                                    [label, formatDurationMinutes(minutes)] as [string, ReactNode],
                            ),
                            [
                                'Escalate after',
                                playbook.escalation_after_minutes
                                    ? formatDurationMinutes(playbook.escalation_after_minutes)
                                    : null,
                            ],
                        ]}
                    />
                </Panel>
            ) : null}
            {tab !== 'evidence' && playbook.required_evidence.length ? (
                <Panel title="Required evidence">
                    <EvidenceList items={playbook.required_evidence} compact />
                </Panel>
            ) : null}
            <Panel title="About this procedure">
                <Facts
                    rows={[
                        ['Category', category],
                        ['Version', `Version ${playbook.version}`],
                        [
                            'Added',
                            playbook.created_at
                                ? [playbook.created_by?.name, formatDateTime(playbook.created_at)]
                                      .filter(Boolean)
                                      .join(' · ')
                                : playbook.created_by?.name,
                        ],
                        [
                            'Last changed',
                            playbook.updated_at && playbook.updated_at !== playbook.created_at
                                ? [playbook.updated_by?.name, formatDateTime(playbook.updated_at)]
                                      .filter(Boolean)
                                      .join(' · ')
                                : null,
                        ],
                    ]}
                />
            </Panel>
        </div>
    );

    return (
        <ControlRoomPage
            headTitle={playbook.name}
            recordCrumb={{ title: playbook.name }}
            header={{
                variant: 'profile',
                backHref: '/control-room/playbooks',
                icon: BookOpen,
                title: playbook.name,
                wrapTitle: true,
                titleChip: (
                    <PageHeaderStatusChip variant={playbook.is_active ? 'success' : 'neutral'}>
                        {playbook.is_active ? 'Available' : 'Off'} · Version {playbook.version}
                    </PageHeaderStatusChip>
                ),
                subline: [
                    category,
                    playbook.is_active
                        ? missingTriggers
                            ? "Won't start on its own until alerts are chosen"
                            : playbook.auto_attach
                              ? 'Starts on its own for matching alerts'
                              : 'Start it from the alert that needs a response'
                        : "Turned off — it can't be started",
                ].join(' · '),
                actions,
                meters,
            }}
            rail={
                <PageHeaderRail
                    ariaLabel="Procedure record"
                    showFind={false}
                    value={tab}
                    onSelect={selectTab}
                    items={TABS.map((t) => ({
                        key: t.key,
                        label: t.label,
                        icon: t.icon,
                        count:
                            t.key === 'steps'
                                ? playbook.steps.length
                                : t.key === 'evidence'
                                  ? playbook.required_evidence.length || undefined
                                  : recentRuns.length || undefined,
                    }))}
                />
            }
        >
            {tab === 'steps' ? (
                <div className="grid min-w-0 items-start gap-5 xl:grid-cols-[minmax(0,1fr)_340px]">
                    <Panel
                        title="Response steps"
                        caption={playbook.description ?? 'What the operator does, in order.'}
                    >
                        {playbook.steps.length === 0 ? (
                            <EmptyState
                                icon={ListChecks}
                                title="No steps yet"
                                description="Edit the procedure to add the steps an operator follows."
                                variant="compact"
                            />
                        ) : (
                            <ol className="grid gap-3">
                                {playbook.steps.map((step) => (
                                    <StepItem
                                        key={step.id}
                                        step={step}
                                        typeLabel={stepTypes[step.type] ?? plainLabel(step.type)}
                                    />
                                ))}
                            </ol>
                        )}
                    </Panel>
                    {side}
                </div>
            ) : null}

            {tab === 'evidence' ? (
                <div className="grid min-w-0 items-start gap-5 xl:grid-cols-[minmax(0,1fr)_340px]">
                    <div className="flex min-w-0 flex-col gap-5">
                        <Panel
                            title="Required evidence"
                            caption="What the operator must collect when they run this procedure."
                        >
                            {playbook.required_evidence.length ? (
                                <EvidenceList items={playbook.required_evidence} />
                            ) : (
                                <EmptyState
                                    icon={Package}
                                    title="No evidence is asked for"
                                    description="Operators can still add evidence to the alert as they go."
                                    variant="compact"
                                />
                            )}
                        </Panel>
                        {evidenceSteps.length ? (
                            <Panel
                                title="Steps that collect evidence"
                                caption="The operator is asked for it at these steps."
                            >
                                <ol className="grid gap-3">
                                    {evidenceSteps.map((step) => (
                                        <StepItem
                                            key={step.id}
                                            step={step}
                                            typeLabel={stepTypes[step.type] ?? plainLabel(step.type)}
                                        />
                                    ))}
                                </ol>
                            </Panel>
                        ) : null}
                    </div>
                    {side}
                </div>
            ) : null}

            {tab === 'runs' ? <RunHistory runs={recentRuns} /> : null}

            {turningOff ? (
                <TurnOffProcedureConfirm
                    procedure={playbook}
                    onClose={() => setTurningOff(false)}
                />
            ) : null}

            {/* Guided procedure editor — basics → steps → starting and targets → review.
                Mounted only while open so every run starts fresh. */}
            {wizardAt ? (
                <PlaybookWizard
                    open
                    onClose={closeWizard}
                    categories={categories}
                    stepTypes={stepTypes}
                    alertTypes={alertTypes}
                    openAt={wizardAt}
                    playbook={{
                        id: playbook.id,
                        name: playbook.name,
                        description: playbook.description ?? '',
                        category: playbook.category,
                        auto_attach: playbook.auto_attach,
                        requires_approval: playbook.requires_approval,
                        sla_acknowledge_minutes:
                            playbook.sla_acknowledge_minutes?.toString() ?? '',
                        sla_response_minutes:
                            playbook.sla_response_minutes?.toString() ?? '',
                        sla_resolution_minutes:
                            playbook.sla_resolution_minutes?.toString() ?? '',
                        required_evidence: playbook.required_evidence ?? [],
                        trigger_alert_types: playbook.trigger_alert_types,
                        trigger_severities: playbook.trigger_severities,
                        escalation_after_minutes: playbook.escalation_after_minutes,
                        steps: playbook.steps.map((s) => ({
                            id: s.id,
                            title: s.title,
                            type: s.type,
                            instructions: s.instructions ?? '',
                            is_required: s.is_required,
                            is_blocking: s.is_blocking,
                            time_limit_minutes:
                                s.time_limit_minutes?.toString() ?? '',
                        })),
                    }}
                />
            ) : null}
        </ControlRoomPage>
    );
}

// --- Parts ---

function StepItem({ step, typeLabel }: { step: PlaybookStep; typeLabel: string }) {
    return (
        <li className="flex items-start gap-3 rounded-[12px] border border-border p-3.5">
            <span
                aria-hidden="true"
                className="popup-lens popup-lens--sm shrink-0 text-xs font-semibold tabular-nums"
            >
                {step.order}
            </span>
            <div className="min-w-0 flex-1">
                <p className="flex flex-wrap items-center gap-x-2 gap-y-1">
                    <span className="font-semibold text-foreground">
                        <span className="sr-only">Step {step.order}: </span>
                        {step.title}
                    </span>
                    {step.is_required ? (
                        <StatusBadge variant="neutral" size="sm">
                            Required
                        </StatusBadge>
                    ) : null}
                    {step.is_blocking ? (
                        <StatusBadge variant="warning" size="sm">
                            Must finish first
                        </StatusBadge>
                    ) : null}
                </p>
                <p className="text-caption mt-0.5">
                    {[
                        typeLabel,
                        step.time_limit_minutes
                            ? `within ${formatDurationMinutes(step.time_limit_minutes)}`
                            : null,
                    ]
                        .filter(Boolean)
                        .join(' · ')}
                </p>
                {step.instructions ? (
                    <p className="text-subtle mt-1.5 whitespace-pre-line">{step.instructions}</p>
                ) : null}
                {step.decision_options?.length ? (
                    <p className="text-subtle mt-1.5">
                        Choices: {step.decision_options.join(' · ')}
                    </p>
                ) : null}
            </div>
        </li>
    );
}

function EvidenceList({ items, compact = false }: { items: string[]; compact?: boolean }) {
    return (
        <ul className={compact ? 'grid gap-2' : 'grid gap-2.5 sm:grid-cols-2'}>
            {items.map((item) => {
                const Icon = EVIDENCE_ICON[item] ?? Paperclip;
                return (
                    <li
                        key={item}
                        className={
                            compact
                                ? 'flex items-center gap-2 text-sm text-foreground'
                                : 'flex items-center gap-3 rounded-[12px] border border-border px-3.5 py-3 text-sm font-medium text-foreground'
                        }
                    >
                        <span className="popup-lens popup-lens--sm shrink-0" aria-hidden="true">
                            <Icon className="size-[15px]" />
                        </span>
                        {plainLabel(item)}
                    </li>
                );
            })}
        </ul>
    );
}

function RunHistory({ runs }: { runs: RunEntry[] }) {
    return (
        <Card className="min-w-0 gap-0 overflow-hidden py-0">
            <div className="border-b border-border px-5 py-4">
                <h2 className="text-section-title">Run history</h2>
                <p className="text-subtle mt-0.5">
                    The most recent times this procedure was run on an alert, newest first.
                </p>
            </div>
            {runs.length === 0 ? (
                <div className="px-5 py-10">
                    <EmptyState
                        icon={History}
                        title="Not run yet"
                        description="Runs appear here once the procedure is started on an alert."
                    />
                </div>
            ) : (
                <Table>
                    <TableHeader>
                        <TableRow>
                            <TableHead className="pl-5">Alert</TableHead>
                            <TableHead>Severity</TableHead>
                            <TableHead>Status</TableHead>
                            <TableHead>Progress</TableHead>
                            <TableHead>Started</TableHead>
                            <TableHead>Finished</TableHead>
                            <TableHead>Took</TableHead>
                            <TableHead>Started by</TableHead>
                            <TableHead className="w-12 pr-3">
                                <span className="sr-only">Actions</span>
                            </TableHead>
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        {runs.map((run) => (
                            <RunRow key={run.id} run={run} />
                        ))}
                    </TableBody>
                </Table>
            )}
        </Card>
    );
}

function RunRow({ run }: { run: RunEntry }) {
    const href = `/control-room/alerts/${run.alert_id}`;
    const status = runStatus(run.status);
    const minutes = runMinutes(run);
    const items: ControlRoomRowAction[] = run.alert
        ? [{ key: 'open', label: 'Open alert', icon: Eye, onSelect: () => router.visit(href) }]
        : [];

    return (
        <ControlRoomRowActions label={`Actions for alert ${run.alert_id}`} items={items}>
            {({ rowProps, overflowButton }) => (
                <TableRow
                    {...rowProps}
                    className={
                        run.alert
                            ? 'cursor-pointer outline-none focus-visible:bg-muted/50 focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-inset'
                            : undefined
                    }
                    onClick={
                        run.alert
                            ? (event) => {
                                  // The alert link and the kebab handle their own clicks.
                                  if ((event.target as HTMLElement).closest('a,button')) return;
                                  router.visit(href);
                              }
                            : undefined
                    }
                >
                    <TableCell className="pl-5">
                        {run.alert ? (
                            <>
                                <span className="block font-medium text-foreground">
                                    {plainLabel(run.alert.alert_type) || 'Alert'}
                                </span>
                                <Link
                                    href={href}
                                    className="text-caption font-medium text-primary hover:underline"
                                >
                                    Alert {run.alert_id}
                                </Link>
                            </>
                        ) : (
                            <span className="text-subtle">Alert {run.alert_id}</span>
                        )}
                    </TableCell>
                    <TableCell>
                        {run.alert ? (
                            <StatusBadge variant={severityBadge(run.alert.severity).tone} size="sm">
                                {plainLabel(run.alert.severity)}
                            </StatusBadge>
                        ) : null}
                    </TableCell>
                    <TableCell>
                        <StatusBadge variant={status.tone} size="sm">
                            {status.label}
                        </StatusBadge>
                    </TableCell>
                    <TableCell className="whitespace-nowrap tabular-nums">
                        {run.completed_steps} of {run.total_steps} steps
                    </TableCell>
                    <TableCell className="whitespace-nowrap text-muted-foreground">
                        {run.started_at ? formatDateTime(run.started_at) : null}
                    </TableCell>
                    <TableCell className="whitespace-nowrap text-muted-foreground">
                        {run.completed_at ? formatDateTime(run.completed_at) : null}
                    </TableCell>
                    <TableCell className="whitespace-nowrap tabular-nums">
                        {minutes !== null ? formatDurationMinutes(minutes) : null}
                    </TableCell>
                    <TableCell>{run.started_by?.name ?? null}</TableCell>
                    <TableCell className="pr-3 text-right">{overflowButton}</TableCell>
                </TableRow>
            )}
        </ControlRoomRowActions>
    );
}
