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
        action_items_overdue?: number;
        by_tab?: Record<TabKey, number>;
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
    areaKeys?: string[];
}> = [
    { key: 'all', label: 'All', icon: LayoutGrid },
    { key: 'meetings', label: 'Meetings', icon: Calendar, areas: ['Meetings'], areaKeys: ['meetings'] },
    {
        key: 'actions',
        label: 'Actions',
        icon: ClipboardList,
        areas: ['Action Items'],
        areaKeys: ['action_items', 'actions'],
    },
    {
        key: 'risks',
        label: 'Risks',
        icon: ShieldAlert,
        areas: ['Risks', 'Risk Register'],
        areaKeys: ['risks', 'risk_register'],
    },
    {
        key: 'compliance',
        label: 'Compliance',
        icon: FileText,
        areas: ['Compliance'],
        areaKeys: ['compliance'],
    },
    { key: 'policies', label: 'Policies', icon: BookOpen, areas: ['Policies'], areaKeys: ['policies'] },
];

function filterFor(tab: TabKey, actions: WorkflowAction[]): WorkflowAction[] {
    if (tab === 'all') return actions;
    const def = TAB_DEFS.find((t) => t.key === tab);
    if (!def) return actions;
    return actions.filter((a) => {
        if (def.areaKeys && a.area_key && def.areaKeys.includes(a.area_key)) return true;
        if (def.areas && a.area && def.areas.includes(a.area)) return true;
        return false;
    });
}

function EmptyForTab({ tab, totalInTab = 0 }: { tab: TabKey; totalInTab?: number }) {
    if (totalInTab > 0) {
        return (
            <div className="rounded-lg border border-dashed border-border p-8 text-center">
                <p className="text-sm font-medium text-foreground">No items in top ranked sample</p>
                <p className="mt-1 text-xs text-muted-foreground">
                    There are {totalInTab} tracked items in this category that are not ranked in the immediate top sample.
                </p>
            </div>
        );
    }

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

function scopedViewUrl(tab: TabKey): string {
    switch (tab) {
        case 'meetings':
            return '/governance/meetings';
        case 'actions':
            return '/governance/actions';
        case 'risks':
            return '/governance/risks';
        case 'compliance':
            return '/governance/compliance';
        case 'policies':
            return '/governance/policies';
        case 'all':
        default:
            return '/governance/records';
    }
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
    const [isExpanded, setIsExpanded] = useState(false);

    const counts = useMemo(() => {
        if (summary.by_tab) {
            return summary.by_tab;
        }
        const c: Record<TabKey, number> = {
            all: summary.total ?? actions.length,
            meetings: 0,
            actions: 0,
            risks: 0,
            compliance: 0,
            policies: 0,
        };
        for (const a of actions) {
            for (const def of TAB_DEFS) {
                if ((def.areaKeys && a.area_key && def.areaKeys.includes(a.area_key)) || (def.areas && a.area && def.areas.includes(a.area))) {
                    c[def.key] += 1;
                }
            }
        }
        return c;
    }, [actions, summary]);

    const tabs: PageTabItem[] = TAB_DEFS.map((def) => {
        const count = counts[def.key];
        return {
            value: def.key,
            key: def.key,
            label: def.label,
            icon: def.icon,
            count: count,
            tone: count > 0 ? 'brand' : 'neutral',
        };
    });

    const filtered = filterFor(tab, actions);

    return (
        <Card data-dusk="cockpit-priority-overview">
            <CardHeader className="pb-3">
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <CardTitle className="text-lg">
                            Priorities Requiring Board Attention
                        </CardTitle>
                        <CardDescription>
                            Ranked by urgency across meetings, risks,
                            compliance, and assigned actions
                        </CardDescription>
                    </div>
                    <div className="flex items-center gap-2">
                        <Badge
                            variant="outline"
                            className="text-xs font-normal"
                        >
                            {summary.total} total
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
                    onValueChange={(v) => {
                        setTab(v as TabKey);
                        setIsExpanded(false);
                    }}
                    items={tabs}
                >
                    {TAB_DEFS.map((def) => {
                        const tabActions = filterFor(def.key, actions);
                        const displayActions = isExpanded ? tabActions : tabActions.slice(0, 8);
                        const totalInTab = counts[def.key];

                        return (
                            <TabsContent
                                key={def.key}
                                value={def.key}
                                className="space-y-3"
                            >
                                {tabActions.length === 0 ? (
                                    <EmptyForTab tab={def.key} totalInTab={totalInTab} />
                                ) : (
                                    displayActions.map((action) => (
                                        <BoardPriorityCard
                                            key={action.id}
                                            action={action}
                                        />
                                    ))
                                )}
                                {totalInTab > 8 && def.key === tab ? (
                                    <div className="pt-2 flex flex-wrap items-center justify-center gap-3">
                                        <button
                                            type="button"
                                            onClick={() => setIsExpanded(!isExpanded)}
                                            className="text-xs font-medium text-primary hover:underline"
                                        >
                                            {isExpanded
                                                ? 'Show top 8 only'
                                                : `Show all ${totalInTab} ${def.key === 'all' ? 'priorities' : def.label.toLowerCase()}`}
                                        </button>
                                        {def.key !== 'all' ? (
                                            <Link
                                                href={scopedViewUrl(def.key)}
                                                className="text-xs text-muted-foreground hover:underline"
                                            >
                                                Open {def.label} register &rarr;
                                            </Link>
                                        ) : (
                                            <Link
                                                href="/governance/records"
                                                className="text-xs text-muted-foreground hover:underline"
                                            >
                                                Explore Records & Archives &rarr;
                                            </Link>
                                        )}
                                    </div>
                                ) : null}
                            </TabsContent>
                        );
                    })}
                </PageTabs>
            </CardContent>
        </Card>
    );
}

export default PriorityOverviewPanel;
