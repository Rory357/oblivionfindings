import AppLayout from '@/layouts/app-layout';
import PageShell from '@/components/page-shell';
import PageHeader from '@/components/page-header';
import { Head, router } from '@inertiajs/react';
import { Card, CardContent } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Users, Search, ChevronDown, ChevronRight } from 'lucide-react';
import { type BreadcrumbItem } from '@/types';
import { useState, useMemo, useCallback } from 'react';

interface OrgNode {
    id: number;
    user_id: number;
    name: string;
    email: string | null;
    position_title: string;
    department: string | null;
    profile_photo_path: string | null;
    children: OrgNode[];
}

interface Props {
    hierarchy: OrgNode[];
    can: { manage: boolean };
}

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'HR', href: '/hr/people' },
    { title: 'Organisation Chart', href: '/hr/orgchart' },
];

function getInitials(name: string): string {
    return name
        .split(' ')
        .map((n) => n[0])
        .join('')
        .toUpperCase()
        .slice(0, 2);
}

function flattenNodes(nodes: OrgNode[]): OrgNode[] {
    const result: OrgNode[] = [];
    for (const node of nodes) {
        result.push(node);
        if (node.children.length > 0) {
            result.push(...flattenNodes(node.children));
        }
    }
    return result;
}

function matchesSearch(node: OrgNode, query: string): boolean {
    const q = query.toLowerCase();
    return (
        node.name.toLowerCase().includes(q) ||
        (node.email?.toLowerCase().includes(q) ?? false) ||
        (node.position_title?.toLowerCase().includes(q) ?? false) ||
        (node.department?.toLowerCase().includes(q) ?? false)
    );
}

function findMatchingIds(nodes: OrgNode[], query: string): Set<number> {
    const ids = new Set<number>();
    if (!query) return ids;

    function walk(node: OrgNode): boolean {
        const selfMatch = matchesSearch(node, query);
        let childMatch = false;
        for (const child of node.children) {
            if (walk(child)) childMatch = true;
        }
        if (selfMatch || childMatch) {
            ids.add(node.id);
            return true;
        }
        return false;
    }

    for (const root of nodes) {
        walk(root);
    }
    return ids;
}

function OrgNodeCard({
    node,
    canManage,
    searchQuery,
    matchingIds,
    depth = 0,
}: {
    node: OrgNode;
    canManage: boolean;
    searchQuery: string;
    matchingIds: Set<number>;
    depth?: number;
}) {
    const hasChildren = node.children.length > 0;
    const isFilterActive = searchQuery.length > 0;
    const isMatch = matchingIds.has(node.id);
    const isSelfMatch = isFilterActive && matchesSearch(node, searchQuery);

    const [collapsed, setCollapsed] = useState(false);

    // When searching, always show matched branches
    const isExpanded = isFilterActive ? isMatch : !collapsed;

    if (isFilterActive && !isMatch) {
        return null;
    }

    return (
        <div className="flex flex-col items-center">
            {/* Card */}
            <div
                className={`relative flex w-56 items-center gap-3 rounded-lg border bg-card p-3 shadow-sm transition-shadow hover:shadow-md ${
                    isSelfMatch ? 'ring-2 ring-primary/50' : ''
                }`}
            >
                {/* Avatar */}
                <div className="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-primary/10 text-sm font-semibold text-primary">
                    {node.profile_photo_path ? (
                        <img
                            src={node.profile_photo_path}
                            alt={node.name}
                            className="h-10 w-10 rounded-full object-cover"
                        />
                    ) : (
                        getInitials(node.name)
                    )}
                </div>

                {/* Info */}
                <div className="min-w-0 flex-1">
                    <p className="truncate text-sm font-medium leading-tight">{node.name}</p>
                    <p className="truncate text-xs text-muted-foreground">{node.position_title || 'No position'}</p>
                    {node.department && (
                        <p className="truncate text-xs text-muted-foreground/70">{node.department}</p>
                    )}
                </div>

                {/* Expand/collapse toggle */}
                {hasChildren && !isFilterActive && (
                    <button
                        type="button"
                        onClick={() => setCollapsed((c) => !c)}
                        className="absolute -bottom-3 left-1/2 z-10 flex h-6 w-6 -translate-x-1/2 items-center justify-center rounded-full border bg-background text-muted-foreground shadow-sm hover:bg-muted"
                    >
                        {collapsed ? (
                            <ChevronRight className="h-3.5 w-3.5" />
                        ) : (
                            <ChevronDown className="h-3.5 w-3.5" />
                        )}
                    </button>
                )}
            </div>

            {/* Children */}
            {hasChildren && isExpanded && (
                <div className="flex flex-col items-center">
                    {/* Vertical connector from parent to children row */}
                    <div className="h-6 w-px bg-border" />

                    {/* Horizontal connector + children */}
                    <div className="relative flex gap-8">
                        {/* Horizontal line spanning children */}
                        {node.children.filter((c) => !isFilterActive || matchingIds.has(c.id)).length > 1 && (
                            <div className="absolute left-[calc(50%-var(--half-width))] right-[calc(50%-var(--half-width))] top-0 h-px bg-border"
                                style={{
                                    '--half-width': `calc(${
                                        ((node.children.filter((c) => !isFilterActive || matchingIds.has(c.id)).length - 1) * 100) / 2
                                    }% + 0px)`,
                                } as React.CSSProperties}
                            />
                        )}

                        {node.children.map((child) => {
                            if (isFilterActive && !matchingIds.has(child.id)) return null;
                            return (
                                <div key={child.id} className="flex flex-col items-center">
                                    {/* Vertical connector to child */}
                                    <div className="h-6 w-px bg-border" />
                                    <OrgNodeCard
                                        node={child}
                                        canManage={canManage}
                                        searchQuery={searchQuery}
                                        matchingIds={matchingIds}
                                        depth={depth + 1}
                                    />
                                </div>
                            );
                        })}
                    </div>
                </div>
            )}
        </div>
    );
}

export default function OrgChartIndex({ hierarchy, can }: Props) {
    const [searchQuery, setSearchQuery] = useState('');

    const allNodes = useMemo(() => flattenNodes(hierarchy), [hierarchy]);
    const totalCount = allNodes.length;

    const matchingIds = useMemo(
        () => findMatchingIds(hierarchy, searchQuery),
        [hierarchy, searchQuery],
    );

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Organisation Chart" />
            <PageShell>
                <div className="px-6 pt-6">
                    <PageHeader
                        title={
                            <span className="flex items-center gap-2">
                                <Users className="h-6 w-6" />
                                Organisation Chart
                            </span>
                        }
                        description={`Showing ${totalCount} active employee${totalCount !== 1 ? 's' : ''} across the organisation.`}
                    />
                </div>

                <div className="px-6">
                    <div className="relative w-full max-w-sm">
                        <Search className="absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
                        <Input
                            placeholder="Search by name, position, department..."
                            value={searchQuery}
                            onChange={(e) => setSearchQuery(e.target.value)}
                            className="pl-9"
                        />
                    </div>
                </div>

                <div className="overflow-x-auto px-6 pb-8">
                    {hierarchy.length === 0 ? (
                        <Card>
                            <CardContent className="flex flex-col items-center justify-center py-16 text-center">
                                <Users className="mb-4 h-12 w-12 text-muted-foreground/40" />
                                <p className="text-lg font-medium text-muted-foreground">No organisation structure found</p>
                                <p className="mt-1 text-sm text-muted-foreground/70">
                                    Assign managers to employees to build the org chart.
                                </p>
                            </CardContent>
                        </Card>
                    ) : searchQuery && matchingIds.size === 0 ? (
                        <Card>
                            <CardContent className="flex flex-col items-center justify-center py-16 text-center">
                                <Search className="mb-4 h-12 w-12 text-muted-foreground/40" />
                                <p className="text-lg font-medium text-muted-foreground">No results found</p>
                                <p className="mt-1 text-sm text-muted-foreground/70">
                                    Try a different search term.
                                </p>
                            </CardContent>
                        </Card>
                    ) : (
                        <div className="flex min-w-max justify-center gap-12 pt-4">
                            {hierarchy.map((root) => {
                                if (searchQuery && !matchingIds.has(root.id)) return null;
                                return (
                                    <OrgNodeCard
                                        key={root.id}
                                        node={root}
                                        canManage={can.manage}
                                        searchQuery={searchQuery}
                                        matchingIds={matchingIds}
                                    />
                                );
                            })}
                        </div>
                    )}
                </div>
            </PageShell>
        </AppLayout>
    );
}
