import { Badge } from '@/components/ui/badge';
import { Card, CardContent } from '@/components/ui/card';
import { formatDateTime, formatRelative } from '@/lib/datetime';
import { Link } from '@inertiajs/react';
import {
    Activity,
    CircleHelp,
    MonitorCheck,
    MonitorOff,
    RadioTower,
    TriangleAlert,
    type LucideIcon,
} from 'lucide-react';
import type { ReactNode } from 'react';

export type WorkspaceTabState = 'available' | 'not_configured';

export type SecurityDevicesWorkspaceTab = {
    key: string;
    label: string;
    description: string;
    state: WorkspaceTabState;
    stateLabel: string;
};

export type SecurityDevicesWorkspace = {
    slug: string;
    title: string;
    description: string;
    canonicalHref: string;
    activeTab: string;
    activeTabState: WorkspaceTabState;
    tabs: SecurityDevicesWorkspaceTab[];
    summary: {
        devices: number;
        attention: number;
        monitored: number;
        unmonitored: number;
    };
    freshness: {
        state: 'current' | 'stale' | 'unknown';
        label: string;
        observedAt: string | null;
        lastChangedAt?: string | null;
    };
};

type ShellProps = {
    workspace: SecurityDevicesWorkspace;
    filters: Record<string, string | undefined>;
    children: ReactNode;
};

type SummaryItem = {
    label: string;
    value: number;
    icon: LucideIcon;
    tone?: string;
};

function tabHref(
    workspace: SecurityDevicesWorkspace,
    filters: Record<string, string | undefined>,
    tab: string,
): string {
    const params = new URLSearchParams();

    for (const [key, value] of Object.entries(filters)) {
        if (value && key !== 'page' && key !== 'tab') {
            params.set(key, value);
        }
    }

    params.set('tab', tab);

    return `${workspace.canonicalHref}?${params.toString()}`;
}

export function WorkspaceSummary({
    summary,
}: {
    summary: SecurityDevicesWorkspace['summary'];
}) {
    const items: SummaryItem[] = [
        { label: 'Devices', value: summary.devices, icon: Activity },
        {
            label: 'Need attention',
            value: summary.attention,
            icon: TriangleAlert,
            tone:
                summary.attention > 0
                    ? 'text-amber-700 dark:text-amber-300'
                    : undefined,
        },
        { label: 'Monitored', value: summary.monitored, icon: MonitorCheck },
        {
            label: 'Unmonitored',
            value: summary.unmonitored,
            icon: MonitorOff,
            tone:
                summary.unmonitored > 0
                    ? 'text-amber-700 dark:text-amber-300'
                    : undefined,
        },
    ];

    return (
        <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
            {items.map((item) => {
                const Icon = item.icon;

                return (
                    <Card key={item.label}>
                        <CardContent className="flex items-center justify-between gap-3 p-4">
                            <div>
                                <p className="text-xs font-semibold tracking-wide text-muted-foreground uppercase">
                                    {item.label}
                                </p>
                                <p
                                    className={`mt-1 text-2xl font-bold ${item.tone ?? ''}`}
                                >
                                    {item.value}
                                </p>
                            </div>
                            <Icon
                                className={`h-5 w-5 ${item.tone ?? 'text-primary'}`}
                                aria-hidden="true"
                            />
                        </CardContent>
                    </Card>
                );
            })}
        </div>
    );
}

export function WorkspaceFreshness({
    freshness,
}: {
    freshness: SecurityDevicesWorkspace['freshness'];
}) {
    const state = {
        current: {
            icon: RadioTower,
            label: 'Current',
            className: 'text-emerald-700 dark:text-emerald-300',
        },
        stale: {
            icon: TriangleAlert,
            label: 'Stale',
            className: 'text-amber-700 dark:text-amber-300',
        },
        unknown: {
            icon: CircleHelp,
            label: 'Unknown',
            className: 'text-muted-foreground',
        },
    }[freshness.state];
    const Icon = state.icon;

    return (
        <div className="flex flex-col gap-2 rounded-xl border border-border bg-muted/30 px-4 py-3 text-sm sm:flex-row sm:items-center sm:justify-between">
            <div className="flex items-center gap-2">
                <Icon
                    className={`h-4 w-4 ${state.className}`}
                    aria-hidden="true"
                />
                <span className="font-semibold">{freshness.label}</span>
                <Badge variant="outline" className={state.className}>
                    {state.label}
                </Badge>
            </div>
            <span className="text-muted-foreground">
                {freshness.observedAt ? (
                    <span title={formatDateTime(freshness.observedAt)}>
                        {formatRelative(freshness.observedAt)}
                    </span>
                ) : (
                    'No observation recorded'
                )}
            </span>
        </div>
    );
}

export function WorkspaceFilterBar({ children }: { children: ReactNode }) {
    return (
        <section
            aria-label="Workspace filters"
            className="space-y-3 rounded-xl border border-border bg-card p-4"
        >
            {children}
        </section>
    );
}

export function WorkspaceDeviceList({ children }: { children: ReactNode }) {
    return <section aria-label="Workspace devices">{children}</section>;
}

export function SecurityDevicesWorkspaceShell({
    workspace,
    filters,
    children,
}: ShellProps) {
    const activeTab =
        workspace.tabs.find((tab) => tab.key === workspace.activeTab) ??
        workspace.tabs[0];
    const unavailableMessage = `${activeTab.description.replace(/\.$/, '')} is not configured for this workspace yet.`;

    return (
        <div className="space-y-4">
            <nav aria-label={`${workspace.title} workspace tabs`}>
                <ul className="flex flex-wrap gap-2">
                    {workspace.tabs.map((tab) => {
                        const active = tab.key === workspace.activeTab;

                        return (
                            <li key={tab.key}>
                                <Link
                                    href={tabHref(workspace, filters, tab.key)}
                                    aria-current={active ? 'page' : undefined}
                                    title={tab.description}
                                    className={`frontline-focus flex min-h-11 items-center gap-2 rounded-xl border px-3 py-2 text-sm font-semibold transition-colors ${
                                        active
                                            ? 'border-primary bg-primary text-primary-foreground'
                                            : 'border-border bg-card text-muted-foreground hover:border-primary/40 hover:text-foreground'
                                    }`}
                                >
                                    <span>{tab.label}</span>
                                    {tab.state === 'not_configured' && (
                                        <Badge
                                            variant="outline"
                                            className={
                                                active
                                                    ? 'border-primary-foreground/40 text-primary-foreground'
                                                    : 'text-muted-foreground'
                                            }
                                        >
                                            Not configured
                                        </Badge>
                                    )}
                                </Link>
                            </li>
                        );
                    })}
                </ul>
            </nav>

            <WorkspaceSummary summary={workspace.summary} />
            <WorkspaceFreshness freshness={workspace.freshness} />

            {workspace.activeTabState === 'available' ? (
                children
            ) : (
                <Card>
                    <CardContent className="flex min-h-40 flex-col items-start justify-center gap-2 p-6">
                        <Badge variant="outline">
                            Capability not configured
                        </Badge>
                        <h2 className="text-lg font-semibold">
                            {activeTab.label}
                        </h2>
                        <p className="max-w-2xl text-sm text-muted-foreground">
                            {unavailableMessage}
                        </p>
                        <p className="text-xs text-muted-foreground">
                            No metrics or controls are shown until a canonical
                            data source is available.
                        </p>
                    </CardContent>
                </Card>
            )}
        </div>
    );
}
