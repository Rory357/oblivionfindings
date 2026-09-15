import { Link } from '@inertiajs/react';
import axios from 'axios';
import {
    BookOpen,
    Calendar,
    CheckCircle2,
    ClipboardList,
    FileText,
    LayoutGrid,
    ShieldAlert,
} from 'lucide-react';
import { useEffect, useMemo, useState } from 'react';

import { PageTabs, type PageTabItem } from '@/components/page/page-tabs';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { EmptyState } from '@/components/ui/empty-state';
import { TabsContent } from '@/components/ui/tabs';
import { data as dashboardData } from '@/routes/governance/dashboard';

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
    /** How many priorities show before "See all" (members see the top 3). */
    collapsedCount?: number;
    /** Area tabs (meeting managers). Members get the single ranked list. */
    showTabs?: boolean;
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

const TAB_DEFS: Array<{
    key: TabKey;
    label: string;
    noun: string;
    icon: PageTabItem['icon'];
    areas?: string[];
    areaKeys?: string[];
    register: { href: string; label: string };
    empty: { title: string; body: string };
}> = [
    {
        key: 'all',
        label: 'All',
        noun: 'priorities',
        icon: LayoutGrid,
        register: { href: '/governance/records', label: 'Search records' },
        empty: {
            title: 'Nothing needs the board right now',
            body: 'Nothing in the areas you can see needs attention.',
        },
    },
    {
        key: 'meetings',
        label: 'Meetings',
        noun: 'meeting tasks',
        icon: Calendar,
        areas: ['Meetings'],
        areaKeys: ['meetings'],
        register: { href: '/governance/meetings', label: 'Open meetings' },
        empty: {
            title: 'No meeting work waiting',
            body: 'No agenda, board pack or minutes work is waiting.',
        },
    },
    {
        key: 'actions',
        label: 'Actions',
        noun: 'actions',
        icon: ClipboardList,
        areas: ['Action Items'],
        areaKeys: ['action_items', 'actions'],
        register: { href: '/governance/actions', label: 'Open actions' },
        empty: {
            title: 'No open actions',
            body: 'No board or committee actions are open.',
        },
    },
    {
        key: 'risks',
        label: 'Risks',
        noun: 'risks',
        icon: ShieldAlert,
        areas: ['Risks', 'Risk Register'],
        areaKeys: ['risks', 'risk_register'],
        register: { href: '/governance/risks', label: 'Open risk register' },
        empty: {
            title: 'No risks need board attention right now',
            body: "No open risks are above the board's limit.",
        },
    },
    {
        key: 'compliance',
        label: 'Compliance',
        noun: 'requirements',
        icon: FileText,
        areas: ['Compliance'],
        areaKeys: ['compliance'],
        register: { href: '/governance/compliance', label: 'Open compliance' },
        empty: {
            title: 'No requirements due',
            body: 'No requirements are overdue or due in the next 30 days.',
        },
    },
    {
        key: 'policies',
        label: 'Policies',
        noun: 'policy reviews',
        icon: BookOpen,
        areas: ['Policies'],
        areaKeys: ['policies'],
        register: { href: '/governance/policies', label: 'Open policies' },
        empty: {
            title: 'No policy reviews due',
            body: 'No policies are due for review in the next 30 days.',
        },
    },
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

/**
 * The ranked board priorities. The section heading lives with the caller
 * (Home says "Board priorities" once); this panel is the list itself.
 *
 * Completeness contract: tab counts describe the viewer's full population.
 * "See all" loads the tab's ranked list from the server page by page (same
 * viewer, same tab definition), and the footer always states how many of the
 * counted items are shown — the panel never claims more than the user can
 * reach.
 */
export function PriorityOverviewPanel({
    actions,
    summary,
    pagination,
    collapsedCount = 8,
    showTabs = true,
}: PriorityOverviewPanelProps) {
    const [tab, setTab] = useState<TabKey>('all');
    const [isExpanded, setIsExpanded] = useState(false);
    const [fetched, setFetched] = useState<Partial<Record<TabKey, TabList>>>({});
    const [loadingTab, setLoadingTab] = useState<TabKey | null>(null);
    const [loadError, setLoadError] = useState<string | null>(null);

    // A refreshed dashboard payload (Refresh) supersedes any pages fetched
    // against the previous ranking.
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
                if (def.key === 'all') continue;
                if (
                    (def.areaKeys && a.area_key && def.areaKeys.includes(a.area_key)) ||
                    (def.areas && a.area && def.areas.includes(a.area))
                ) {
                    c[def.key] += 1;
                }
            }
        }
        return c;
    }, [actions, summary]);

    /**
     * The list a tab currently holds: a server-fetched ranked list when one
     * exists, the initial page for "all", otherwise the tab's share of the
     * initial page (a sample that "See all" completes from the server).
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
            setLoadError("More priorities couldn't be loaded. Try again.");
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

    const renderTab = (def: (typeof TAB_DEFS)[number]) => {
        const { items, complete, list } = listFor(def.key);
        const totalInTab = counts[def.key];
        const displayActions = isExpanded ? items : items.slice(0, collapsedCount);
        const isLoading = loadingTab === def.key;
        const nextPage = list ? list.pagination.page + 1 : 1;
        const hasMoreThanShown = totalInTab > collapsedCount || !complete;

        return (
            <div className="flex flex-col gap-3">
                {totalInTab === 0 && items.length === 0 ? (
                    <EmptyState
                        variant="compact"
                        icon={CheckCircle2}
                        title={def.empty.title}
                        description={def.empty.body}
                    />
                ) : items.length === 0 ? (
                    <EmptyState
                        variant="compact"
                        icon={LayoutGrid}
                        title={
                            isLoading
                                ? `Loading ${totalInTab} ${def.noun}…`
                                : `${totalInTab} ${def.noun} are further down the list`
                        }
                        description={
                            isLoading ? undefined : 'Choose See all to load them.'
                        }
                    />
                ) : (
                    displayActions.map((action) => (
                        <BoardPriorityCard key={action.id} action={action} />
                    ))
                )}
                {hasMoreThanShown ? (
                    <div
                        className="flex flex-wrap items-center justify-center gap-3 pt-2"
                        data-test="priority-overview-footer"
                    >
                        {isExpanded ? (
                            <>
                                <span
                                    className="text-caption"
                                    aria-live="polite"
                                >
                                    Showing {items.length} of {totalInTab} {def.noun}
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
                                {items.length > collapsedCount ? (
                                    <Button
                                        type="button"
                                        variant="link"
                                        size="xs"
                                        onClick={() => setIsExpanded(false)}
                                    >
                                        Show fewer
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
                                See all {totalInTab} {def.noun}
                            </Button>
                        )}
                        {showTabs ? (
                            <Link
                                href={def.register.href}
                                className="text-caption hover:underline focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                            >
                                {def.register.label}
                            </Link>
                        ) : null}
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
            </div>
        );
    };

    if (!showTabs) {
        return (
            <Card data-dusk="cockpit-priority-overview">
                <CardContent>
                    {renderTab(TAB_DEFS[0])}
                </CardContent>
            </Card>
        );
    }

    const tabs: PageTabItem[] = TAB_DEFS.map((def) => {
        const count = counts[def.key];
        return {
            value: def.key,
            key: def.key,
            label: def.label,
            icon: def.icon,
            count,
            tone: count > 0 ? 'brand' : 'neutral',
        };
    });

    return (
        <Card data-dusk="cockpit-priority-overview">
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
                    {TAB_DEFS.map((def) => (
                        <TabsContent key={def.key} value={def.key}>
                            {def.key === tab ? renderTab(def) : null}
                        </TabsContent>
                    ))}
                </PageTabs>
            </CardContent>
        </Card>
    );
}

export default PriorityOverviewPanel;
