import ClientSafetyRibbon, {
    type ClientSafety,
} from '@/components/client-safety-ribbon';
import { cn } from '@/lib/utils';
import {
    Activity,
    AlertTriangle,
    Car,
    ClipboardList,
    FileText,
    History,
    Info,
    ListChecks,
    Pill,
    Repeat,
    ShieldAlert,
    UserPlus,
    type LucideIcon,
} from 'lucide-react';

export type ShiftShowTabKey =
    | 'tasks'
    | 'notes'
    | 'medications'
    | 'coverage'
    | 'assignment'
    | 'incidents'
    | 'observations'
    | 'forms'
    | 'transport'
    | 'replacement'
    | 'audit';

type ShiftShowTabTone =
    | 'primary'
    | 'warning'
    | 'success'
    | 'info'
    | 'critical'
    | 'neutral';

export type ShiftShowTab = {
    key: ShiftShowTabKey;
    label: string;
    icon: LucideIcon;
    tone: ShiftShowTabTone;
    badge?: string | number;
};

type BuildShiftShowTabsInput = {
    tasksDone: number;
    tasksTotal: number;
    notesCount: number;
    handoverCount: number;
    auditCount: number;
    incidentCount: number;
    medicationOutstandingCount: number;
    showCoverage: boolean;
    showAssignment: boolean;
    showMedications: boolean;
    showObservations: boolean;
    showForms: boolean;
    showTransport: boolean;
    showReplacement: boolean;
};

type ShiftPermissionInput = {
    mark_tasks?: boolean;
};

type SharedAuthInput = {
    can?: {
        shifts?: {
            update?: boolean;
            tasksUpdateSelf?: boolean;
            manageAny?: boolean;
        };
    };
} | null;

export function canMarkShiftTasks(
    can: ShiftPermissionInput | null | undefined,
    auth: SharedAuthInput,
) {
    return Boolean(
        can?.mark_tasks ||
        auth?.can?.shifts?.update ||
        auth?.can?.shifts?.tasksUpdateSelf ||
        auth?.can?.shifts?.manageAny,
    );
}

const tabToneClasses: Record<ShiftShowTabTone, string> = {
    primary:
        'bg-primary/10 text-primary [&_.shift-tab-chip]:bg-primary [&_.shift-tab-chip]:text-primary-foreground [&_.shift-tab-bar]:bg-primary',
    warning:
        'bg-status-warning-bg text-status-warning [&_.shift-tab-chip]:bg-status-warning [&_.shift-tab-chip]:text-white [&_.shift-tab-bar]:bg-status-warning',
    success:
        'bg-status-success-bg text-status-success [&_.shift-tab-chip]:bg-status-success [&_.shift-tab-chip]:text-white [&_.shift-tab-bar]:bg-status-success',
    info: 'bg-status-info-bg text-status-info [&_.shift-tab-chip]:bg-status-info [&_.shift-tab-chip]:text-white [&_.shift-tab-bar]:bg-status-info',
    critical:
        'bg-status-critical-bg text-status-critical [&_.shift-tab-chip]:bg-status-critical [&_.shift-tab-chip]:text-white [&_.shift-tab-bar]:bg-status-critical',
    neutral:
        'bg-muted text-foreground [&_.shift-tab-chip]:bg-muted-foreground [&_.shift-tab-chip]:text-card [&_.shift-tab-bar]:bg-muted-foreground',
};

export function buildShiftShowTabs({
    tasksDone,
    tasksTotal,
    notesCount,
    handoverCount,
    auditCount,
    incidentCount,
    medicationOutstandingCount,
    showCoverage,
    showAssignment,
    showMedications,
    showObservations,
    showForms,
    showTransport,
    showReplacement,
}: BuildShiftShowTabsInput): ShiftShowTab[] {
    const tabs: ShiftShowTab[] = [
        {
            key: 'tasks',
            label: 'Tasks',
            icon: ListChecks,
            tone: 'primary',
            badge: tasksTotal ? `${tasksDone}/${tasksTotal}` : undefined,
        },
        {
            key: 'notes',
            label: 'Notes',
            icon: FileText,
            tone: 'info',
            badge:
                notesCount + handoverCount > 0
                    ? notesCount + handoverCount
                    : undefined,
        },
    ];

    if (showMedications) {
        tabs.push({
            key: 'medications',
            label: 'Medications',
            icon: Pill,
            tone: medicationOutstandingCount > 0 ? 'warning' : 'success',
            badge:
                medicationOutstandingCount > 0
                    ? medicationOutstandingCount
                    : undefined,
        });
    }

    if (showCoverage) {
        tabs.push({
            key: 'coverage',
            label: 'Coverage',
            icon: ShieldAlert,
            tone: 'warning',
        });
    }

    if (showAssignment) {
        tabs.push({
            key: 'assignment',
            label: 'Assignment',
            icon: UserPlus,
            tone: 'neutral',
        });
    }

    tabs.push({
        key: 'incidents',
        label: 'Incidents',
        icon: AlertTriangle,
        tone: incidentCount > 0 ? 'critical' : 'neutral',
        badge: incidentCount > 0 ? incidentCount : undefined,
    });

    if (showObservations) {
        tabs.push({
            key: 'observations',
            label: 'Observations',
            icon: Activity,
            tone: 'success',
        });
    }

    if (showForms) {
        tabs.push({
            key: 'forms',
            label: 'Forms',
            icon: ClipboardList,
            tone: 'neutral',
        });
    }

    if (showTransport) {
        tabs.push({
            key: 'transport',
            label: 'Transport',
            icon: Car,
            tone: 'neutral',
        });
    }

    if (showReplacement) {
        tabs.push({
            key: 'replacement',
            label: 'Replacement',
            icon: Repeat,
            tone: 'warning',
        });
    }

    tabs.push({
        key: 'audit',
        label: 'Audit',
        icon: History,
        tone: 'neutral',
        badge: auditCount > 0 ? auditCount : undefined,
    });

    return tabs;
}

export function ShiftTabStrip({
    tabs,
    activeTab,
    onChange,
}: {
    tabs: ShiftShowTab[];
    activeTab: string;
    onChange: (key: ShiftShowTabKey) => void;
}) {
    return (
        <div
            role="tablist"
            aria-label="Shift sections"
            className="flex flex-wrap items-center gap-1 rounded-[14px] border border-border bg-card p-1.5 shadow-sm"
        >
            {tabs.map((tab) => {
                const active = activeTab === tab.key;
                const Icon = tab.icon;

                return (
                    <button
                        key={tab.key}
                        type="button"
                        role="tab"
                        aria-selected={active}
                        aria-label={
                            tab.badge !== undefined
                                ? `${tab.label} ${tab.badge}`
                                : tab.label
                        }
                        onClick={() => onChange(tab.key)}
                        className={cn(
                            'relative inline-flex min-h-10 items-center gap-2 rounded-[9px] px-3 py-2 text-[13px] font-semibold transition-colors focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring',
                            active
                                ? tabToneClasses[tab.tone]
                                : 'text-muted-foreground hover:bg-accent hover:text-foreground',
                        )}
                    >
                        <span
                            className={cn(
                                'shift-tab-chip inline-flex h-[22px] w-[22px] items-center justify-center rounded-md',
                                !active && 'bg-muted text-muted-foreground',
                            )}
                        >
                            <Icon className="h-3.5 w-3.5" />
                        </span>
                        <span>{tab.label}</span>
                        {tab.badge !== undefined ? (
                            <span className="ml-0.5 inline-flex items-center rounded-full bg-background/70 px-1.5 py-0.5 text-[10px] font-bold tabular-nums">
                                {tab.badge}
                            </span>
                        ) : null}
                        {active ? (
                            <span
                                className="shift-tab-bar absolute inset-x-3.5 -bottom-px h-0.5 rounded"
                                aria-hidden="true"
                            />
                        ) : null}
                    </button>
                );
            })}
        </div>
    );
}

type CoverageSignalInput = {
    has_actionable_gap?: boolean;
    gap_kind?: string | null;
    window_label?: string | null;
    required_staff?: number;
    assigned_staff?: number;
    open_shifts?: number;
    role_shortages?: Array<{
        key: string;
        label: string;
        required: number;
        missing: number;
    }>;
} | null;

type HandoverSignalInput =
    | {
          id: number;
          status?: string | null;
          incoming_staff_name?: string | null;
          observations_summary?: unknown[] | null;
      }
    | null
    | undefined;

export type ShiftShowSignal = {
    id: string;
    tone: 'critical' | 'warning' | 'info' | 'success';
    title: string;
    body: string;
    cta: string;
    tabKey: ShiftShowTabKey;
};

type BuildShiftShowSignalsInput = {
    coverage: CoverageSignalInput;
    medicationOutstandingCount: number;
    incompleteTaskCount: number;
    handoverSummary: HandoverSignalInput;
};

function plural(value: number, singular: string, pluralLabel = `${singular}s`) {
    return `${value} ${value === 1 ? singular : pluralLabel}`;
}

function coverageSignalBody(coverage: NonNullable<CoverageSignalInput>) {
    const roleShortage = coverage.role_shortages?.[0];
    if (roleShortage) {
        return `${coverage.window_label ?? 'Coverage window'} needs ${plural(roleShortage.missing, roleShortage.label)}.`;
    }

    return `${coverage.window_label ?? 'Coverage window'} needs ${coverage.required_staff ?? 0}, ${coverage.assigned_staff ?? 0} assigned, ${coverage.open_shifts ?? 0} open.`;
}

export function buildShiftShowSignals({
    coverage,
    medicationOutstandingCount,
    incompleteTaskCount,
    handoverSummary,
}: BuildShiftShowSignalsInput): ShiftShowSignal[] {
    const signals: ShiftShowSignal[] = [];

    if (coverage?.has_actionable_gap) {
        signals.push({
            id: 'coverage-gap',
            tone: 'critical',
            title: 'Coverage gap',
            body: coverageSignalBody(coverage),
            cta:
                coverage.gap_kind === 'role_open'
                    ? 'Review role cover'
                    : 'Review coverage',
            tabKey: 'coverage',
        });
    }

    if (medicationOutstandingCount > 0) {
        signals.push({
            id: 'medication-due',
            tone: 'warning',
            title: 'Medication due',
            body: `${plural(medicationOutstandingCount, 'medication item')} due, late, or missed.`,
            cta: 'View medications',
            tabKey: 'medications',
        });
    }

    if (incompleteTaskCount > 0) {
        signals.push({
            id: 'tasks-outstanding',
            tone: 'warning',
            title: `${plural(incompleteTaskCount, 'task')} outstanding`,
            body: 'Finish or document the remaining support work before closing the shift.',
            cta: 'View tasks',
            tabKey: 'tasks',
        });
    }

    if (
        handoverSummary &&
        (handoverSummary.status ?? 'submitted') !== 'acknowledged'
    ) {
        signals.push({
            id: 'handover-awaiting',
            tone: 'info',
            title: 'Handover needs acknowledgement',
            body: handoverSummary.incoming_staff_name
                ? `Submitted to ${handoverSummary.incoming_staff_name}.`
                : 'Submitted handover is waiting for the incoming worker.',
            cta: 'Review handover',
            tabKey: 'notes',
        });
    }

    return signals;
}

const signalBorder: Record<ShiftShowSignal['tone'], string> = {
    critical: 'border-l-status-critical',
    warning: 'border-l-status-warning',
    info: 'border-l-status-info',
    success: 'border-l-status-success',
};

const signalChip: Record<ShiftShowSignal['tone'], string> = {
    critical: 'bg-status-critical-bg text-status-critical',
    warning: 'bg-status-warning-bg text-status-warning',
    info: 'bg-status-info-bg text-status-info',
    success: 'bg-status-success-bg text-status-success',
};

const signalIcon: Record<ShiftShowSignal['tone'], LucideIcon> = {
    critical: AlertTriangle,
    warning: AlertTriangle,
    info: Info,
    success: Activity,
};

export function ShiftSignalRail({
    signals,
    safety,
    onSelectTab,
}: {
    signals: ShiftShowSignal[];
    safety: ClientSafety | null | undefined;
    onSelectTab: (key: ShiftShowTabKey) => void;
}) {
    return (
        <aside className="flex flex-col gap-4 rounded-[14px] border border-border bg-card p-4 shadow-sm">
            <section>
                <header className="mb-3 flex items-center justify-between gap-3">
                    <h2 className="text-sm font-bold tracking-tight text-foreground">
                        Needs you
                    </h2>
                    <span className="rounded-full bg-muted px-2 py-0.5 text-[11px] font-semibold text-muted-foreground tabular-nums">
                        {signals.length}
                    </span>
                </header>

                {signals.length === 0 ? (
                    <p className="rounded-md border border-dashed border-border p-3 text-xs text-muted-foreground">
                        All clear. No shift alerts need action right now.
                    </p>
                ) : (
                    <ul className="space-y-2">
                        {signals.map((signal) => {
                            const Icon = signalIcon[signal.tone];

                            return (
                                <li key={signal.id}>
                                    <button
                                        type="button"
                                        onClick={() =>
                                            onSelectTab(signal.tabKey)
                                        }
                                        className="block w-full text-left"
                                    >
                                        <span
                                            className={cn(
                                                'flex w-full gap-2.5 rounded-md border-l-[3px] bg-background/60 p-2.5 transition-colors hover:bg-accent focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring',
                                                signalBorder[signal.tone],
                                            )}
                                        >
                                            <span
                                                className={cn(
                                                    'flex h-[22px] w-[22px] shrink-0 items-center justify-center rounded-md',
                                                    signalChip[signal.tone],
                                                )}
                                            >
                                                <Icon className="h-3 w-3" />
                                            </span>
                                            <span className="min-w-0 flex-1">
                                                <span className="block text-xs leading-tight font-semibold text-foreground">
                                                    {signal.title}
                                                </span>
                                                <span className="mt-0.5 block text-[11px] leading-snug text-muted-foreground">
                                                    {signal.body}
                                                </span>
                                                <span className="mt-1 inline-flex items-center gap-1 text-[11px] font-semibold text-primary">
                                                    {signal.cta}
                                                    <span aria-hidden="true">
                                                        -&gt;
                                                    </span>
                                                </span>
                                            </span>
                                        </span>
                                    </button>
                                </li>
                            );
                        })}
                    </ul>
                )}
            </section>

            {safety?.has_any ? (
                <ClientSafetyRibbon
                    safety={safety}
                    className="border shadow-none"
                />
            ) : null}
        </aside>
    );
}
