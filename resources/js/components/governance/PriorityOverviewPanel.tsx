import { PageTabs, type PageTabItem } from '@/components/page/page-tabs';
import { Badge } from '@/components/ui/badge';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { TabsContent } from '@/components/ui/tabs';
import { Link } from '@inertiajs/react';
import {
    BookOpen,
    Calendar,
    ClipboardList,
    FileText,
    LayoutGrid,
    ShieldAlert,
} from 'lucide-react';
import { useMemo, useState } from 'react';
import { BoardPriorityCard, type WorkflowAction } from './BoardPriorityCard';

interface PriorityOverviewPanelProps {
    actions: WorkflowAction[];
    summary: {
        total: number;
        critical: number;
        overdue: number;
    };
}

type TabKey =
    | 'all'
    | 'meetings'
    | 'actions'
    | 'risks'
    | 'compliance'
    | 'policies';

const TAB_DEFS: Array<{
    key: TabKey;
    label: string;
    icon: PageTabItem['icon'];
    areas?: string[];
}> = [
    { key: 'all', label: 'All', icon: LayoutGrid },
    { key: 'meetings', label: 'Meetings', icon: Calendar, areas: ['Meetings'] },
    {
        key: 'actions',
        label: 'Actions',
        icon: ClipboardList,
        areas: ['Action Items'],
    },
    { key: 'risks', label: 'Risks', icon: ShieldAlert, areas: ['Risks'] },
    {
        key: 'compliance',
        label: 'Compliance',
        icon: FileText,
        areas: ['Compliance'],
    },
    { key: 'policies', label: 'Policies', icon: BookOpen, areas: ['Policies'] },
];

function filterFor(tab: TabKey, actions: WorkflowAction[]): WorkflowAction[] {
    if (tab === 'all') return actions;
    const def = TAB_DEFS.find((t) => t.key === tab);
    if (!def?.areas) return actions;
    return actions.filter((a) => def.areas!.includes(a.area));
}

function EmptyForTab({ tab }: { tab: TabKey }) {
    const COPY: Record<TabKey, { title: string; body: string }> = {
        all: {
            title: 'No board decisions waiting',
            body: 'Nothing requires the board’s attention right now. Check back after the next meeting.',
        },
        meetings: {
            title: 'No meeting actions outstanding',
            body: 'Agenda, pack and minutes are all on track.',
        },
        actions: {
            title: 'No open action items',
            body: 'Board and committee actions are all complete.',
        },
        risks: {
            title: 'No risks need board attention',
            body: 'All tracked risks are within appetite.',
        },
        compliance: {
            title: 'No compliance gaps',
            body: 'All upcoming obligations have evidence assigned.',
        },
        policies: {
            title: 'No outstanding policy work',
            body: 'Attestations are up to date and reviews are not yet due.',
        },
    };
    const { title, body } = COPY[tab];

    return (
        <div className="rounded-lg border border-dashed border-border p-8 text-center">
            <p className="text-sm font-medium text-foreground">{title}</p>
            <p className="mt-1 text-xs text-muted-foreground">{body}</p>
        </div>
    );
}

/**
 * Tabbed list of priority cards. Tabs use the same `PageTabs` component the
 * Sites module uses, so visual styling is identical (underlined trigger,
 * primary fill on active, dropdown overflow on narrow screens).
 */
export function PriorityOverviewPanel({
    actions,
    summary,
}: PriorityOverviewPanelProps) {
    const [tab, setTab] = useState<TabKey>('all');

    const counts = useMemo(() => {
        const c: Record<TabKey, number> = {
            all: actions.length,
            meetings: 0,
            actions: 0,
            risks: 0,
            compliance: 0,
            policies: 0,
        };
        for (const a of actions) {
            for (const def of TAB_DEFS) {
                if (def.areas?.includes(a.area)) {
                    c[def.key] += 1;
                }
            }
        }
        return c;
    }, [actions]);

    const tabs: PageTabItem[] = TAB_DEFS.map((def) => ({
        value: def.key,
        label: def.label,
        icon: def.icon,
        badge:
            counts[def.key] > 0 ? (
                <Badge variant="outline" className="ml-1 px-1.5 py-0 text-xs">
                    {counts[def.key]}
                </Badge>
            ) : undefined,
    }));

    const filtered = filterFor(tab, actions);

    return (
        <Card data-dusk="cockpit-priority-overview">
            <CardHeader className="pb-3">
                <div className="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <CardTitle className="text-lg">
                            Priority Overview
                        </CardTitle>
                        <CardDescription>
                            Board decisions, risks and obligations ranked by
                            urgency.
                        </CardDescription>
                    </div>
                    <div className="flex flex-wrap items-center gap-2">
                        <Badge variant="outline" className="border-border">
                            {summary.total} open
                        </Badge>
                        {summary.critical > 0 && (
                            <Badge className="border border-status-critical/30 bg-status-critical-bg text-status-critical">
                                {summary.critical} critical
                            </Badge>
                        )}
                        {summary.overdue > 0 && (
                            <Badge className="border border-status-warning/30 bg-status-warning-bg text-status-warning">
                                {summary.overdue} overdue
                            </Badge>
                        )}
                    </div>
                </div>
            </CardHeader>
            <CardContent>
                <PageTabs
                    value={tab}
                    onValueChange={(v) => setTab(v as TabKey)}
                    items={tabs}
                >
                    {TAB_DEFS.map((def) => (
                        <TabsContent
                            key={def.key}
                            value={def.key}
                            className="space-y-3"
                        >
                            {filtered.length === 0 ? (
                                <EmptyForTab tab={def.key} />
                            ) : (
                                filterFor(def.key, actions)
                                    .slice(0, 10)
                                    .map((action) => (
                                        <BoardPriorityCard
                                            key={action.id}
                                            action={action}
                                        />
                                    ))
                            )}
                            {filtered.length > 10 && def.key === tab ? (
                                <div className="pt-2 text-center">
                                    <Link
                                        href="/governance/actions"
                                        className="text-xs font-medium text-primary hover:underline"
                                    >
                                        View all {filtered.length} items
                                    </Link>
                                </div>
                            ) : null}
                        </TabsContent>
                    ))}
                </PageTabs>
            </CardContent>
        </Card>
    );
}

export default PriorityOverviewPanel;
