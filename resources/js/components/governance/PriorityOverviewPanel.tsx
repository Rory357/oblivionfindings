import { PageTabs, type PageTabItem } from '@/components/page/page-tabs';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { TabsContent } from '@/components/ui/tabs';
import { data as dashboardData } from '@/routes/governance/dashboard';
import { Link } from '@inertiajs/react';
import axios from 'axios';
import {
    BookOpen,
    Calendar,
    ClipboardList,
    FileText,
    LayoutGrid,
    ShieldAlert,
} from 'lucide-react';
import { useEffect, useMemo, useState } from 'react';
import { BoardPriorityCard, type WorkflowAction } from './BoardPriorityCard';

/**
 * Server pagination contract for the ranked priorities
 * (`GovernanceWorkflowService::dashboardWorkflow`). `total` is the size of
 * the tab's population for this viewer; further pages come from
 * `/governance/dashboard/data?section=priorities`.
 */
export interface PriorityPagination {
    tab: TabKey;
    page: number;
    per_page: number;
    total: number;
    last_page: number;
    from: number;
    to: number;
    has_more: boolean;
}

interface PriorityOverviewPanelProps {
    actions: WorkflowAction[];
    summary: {
        total: number;
        critical: number;
        overdue: number;
        action_items_overdue?: number;
        by_tab?: Record<TabKey, number>;
    };
    /** Pagination for the initial `actions` page (the "all" tab). */
    pagination?: PriorityPagination | null;
}

export type TabKey =
    | 'all'
    | 'meetings'
    | 'actions'
    | 'risks'
    | 'compliance'
    | 'policies';

interface TabList {
    actions: WorkflowAction[];
    pagination: PriorityPagination;
}

const COLLAPSED_COUNT = 8;

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
 *
 * Completeness contract: the header total and tab counts describe the
 * viewer's full population. "Show all" fetches the tab's ranked list from the
 * server page by page (same viewer, same tab definition), and the footer
 * always states how many of the counted items are loaded — the panel never
 * claims more than the user can reach.
 */
export function PriorityOverviewPanel({
    actions,
    summary,
    pagination,
}: PriorityOverviewPanelProps) {
    const [tab, setTab] = useState<TabKey>('all');
    const [isExpanded, setIsExpanded] = useState(false);
    const [fetched, setFetched] = useState<Partial<Record<TabKey, TabList>>>({});
    const [loadingTab, setLoadingTab] = useState<TabKey | null>(null);
    const [loadError, setLoadError] = useState<string | null>(null);

    // A refreshed dashboard payload (new period, Refresh) supersedes any
    // pages fetched against the previous ranking.
    useEffect(() => {
        setFetched({});
        setIsExpanded(false);
        setLoadError(null);
    }, [actions]);

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

    /**
     * The list a tab currently holds: a server-fetched ranked list when one
     * exists, the initial page for "all", otherwise the tab's share of the
     * initial page (a sample that "Show all" completes from the server).
     */
    const listFor = (key: TabKey): { items: WorkflowAction[]; complete: boolean; list: TabList | null } => {
        const serverList = fetched[key] ?? (key === 'all' && pagination ? { actions, pagination } : null);
        if (serverList) {
            return {
                items: serverList.actions,
                complete: !serverList.pagination.has_more,
                list: serverList,
            };
        }
        const sample = filterFor(key, actions);
        return { items: sample, complete: sample.length >= counts[key], list: null };
    };

    const loadPage = async (key: TabKey, page: number) => {
        setLoadingTab(key);
        setLoadError(null);
        try {
            const response = await axios.get(dashboardData.url(), {
                params: { section: 'priorities', tab: key, page },
            });
            const workflow = response.data?.workflow as
                | { actions: WorkflowAction[]; pagination: PriorityPagination }
                | undefined;
            if (!workflow?.pagination) {
                throw new Error('Malformed priorities page');
            }
            setFetched((prev) => {
                const existing = page > 1 ? (prev[key] ?? (key === 'all' && pagination ? { actions, pagination } : null)) : null;
                const seen = new Set((existing?.actions ?? []).map((a) => a.id));
                return {
                    ...prev,
                    [key]: {
                        actions: [
                            ...(existing?.actions ?? []),
                            ...workflow.actions.filter((a) => !seen.has(a.id)),
                        ],
                        pagination: workflow.pagination,
                    },
                };
            });
        } catch {
            setLoadError('More priorities could not be loaded. Try again.');
        } finally {
            setLoadingTab(null);
        }
    };

    const showAll = (key: TabKey) => {
        setIsExpanded(true);
        const { complete, list } = listFor(key);
        if (!complete && !list) {
            void loadPage(key, 1);
        }
    };

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
                        setLoadError(null);
                    }}
                    items={tabs}
                >
                    {TAB_DEFS.map((def) => {
                        const { items, complete, list } = listFor(def.key);
                        const totalInTab = counts[def.key];
                        const displayActions = isExpanded ? items : items.slice(0, COLLAPSED_COUNT);
                        const isActiveTab = def.key === tab;
                        const isLoading = loadingTab === def.key;
                        const noun = def.key === 'all' ? 'priorities' : def.label.toLowerCase();
                        const nextPage = list ? list.pagination.page + 1 : 1;

                        return (
                            <TabsContent
                                key={def.key}
                                value={def.key}
                                className="space-y-3"
                            >
                                {totalInTab === 0 && items.length === 0 ? (
                                    <EmptyForTab tab={def.key} />
                                ) : items.length === 0 ? (
                                    <div className="rounded-lg border border-dashed border-border p-8 text-center">
                                        <p className="text-sm font-medium text-foreground">
                                            {isLoading ? `Loading ${totalInTab} ${noun}…` : `${totalInTab} ${noun} rank below the first page`}
                                        </p>
                                        {!isLoading ? (
                                            <p className="mt-1 text-xs text-muted-foreground">
                                                Use Show all to load every one of them.
                                            </p>
                                        ) : null}
                                    </div>
                                ) : (
                                    displayActions.map((action) => (
                                        <BoardPriorityCard
                                            key={action.id}
                                            action={action}
                                        />
                                    ))
                                )}
                                {isActiveTab && (totalInTab > COLLAPSED_COUNT || !complete) ? (
                                    <div
                                        className="flex flex-wrap items-center justify-center gap-3 pt-2"
                                        data-test="priority-overview-footer"
                                    >
                                        {isExpanded ? (
                                            <>
                                                <span
                                                    className="text-xs text-muted-foreground"
                                                    aria-live="polite"
                                                >
                                                    Showing {items.length} of {totalInTab} {noun}
                                                </span>
                                                {!complete ? (
                                                    <Button
                                                        type="button"
                                                        variant="outline"
                                                        size="xs"
                                                        disabled={isLoading}
                                                        aria-busy={isLoading}
                                                        onClick={() => void loadPage(def.key, nextPage)}
                                                    >
                                                        {isLoading
                                                            ? 'Loading…'
                                                            : `Load ${Math.min(
                                                                  list?.pagination.per_page ?? totalInTab,
                                                                  Math.max(totalInTab - items.length, 0),
                                                              )} more`}
                                                    </Button>
                                                ) : null}
                                                {items.length > COLLAPSED_COUNT ? (
                                                    <Button
                                                        type="button"
                                                        variant="link"
                                                        size="xs"
                                                        onClick={() => setIsExpanded(false)}
                                                    >
                                                        Show top {COLLAPSED_COUNT} only
                                                    </Button>
                                                ) : null}
                                            </>
                                        ) : (
                                            <Button
                                                type="button"
                                                variant="link"
                                                size="xs"
                                                onClick={() => showAll(def.key)}
                                            >
                                                Show all {totalInTab} {noun}
                                            </Button>
                                        )}
                                        <Link
                                            href={scopedViewUrl(def.key)}
                                            className="text-xs text-muted-foreground hover:underline focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                                        >
                                            {def.key !== 'all'
                                                ? `Open ${def.label} register →`
                                                : 'Explore Records & Archives →'}
                                        </Link>
                                        {loadError ? (
                                            <p
                                                role="alert"
                                                className="w-full text-center text-xs text-status-critical"
                                            >
                                                {loadError}
                                            </p>
                                        ) : null}
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
