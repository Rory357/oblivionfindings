import { router } from '@inertiajs/react';
import {
    ChevronDown,
    ChevronRight,
    Network,
    Printer,
    Search,
    UserCog,
    Users,
} from 'lucide-react';
import { useMemo, useState } from 'react';

import { OrgChartBuilderDialog } from '@/components/hr/people/org-chart-builder-dialog';
import { PeoplePicker, type PersonOption } from '@/components/hr/people-picker';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';

export interface OrgNode {
    id: number;
    user_id: number;
    manager_user_id: number | null;
    name: string;
    email: string | null;
    position_title: string;
    department: string | null;
    site: string | null;
    photo_url: string | null;
    children: OrgNode[];
}

export interface OrgPerson {
    user_id: number;
    name: string;
    position_title: string | null;
    manager_user_id?: number | null;
}

function getInitials(name: string): string {
    return name
        .split(' ')
        .map((n) => n[0])
        .join('')
        .toUpperCase()
        .slice(0, 2);
}

/** Title-bar colour keyed to the department branch (stable hash → token palette). */
const BAR_COLORS = [
    'bg-status-info text-white',
    'bg-status-success text-white',
    'bg-status-warning text-white',
    'bg-primary text-primary-foreground',
    'bg-status-critical text-white',
];

function deptBar(dept: string | null): string {
    if (!dept) return 'bg-muted text-muted-foreground';
    let h = 0;
    for (let i = 0; i < dept.length; i++) h = (h * 31 + dept.charCodeAt(i)) >>> 0;
    return BAR_COLORS[h % BAR_COLORS.length];
}

export function flattenNodes(nodes: OrgNode[]): OrgNode[] {
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

function ReassignManagerDialog({
    node,
    people,
    open,
    onClose,
}: {
    node: OrgNode;
    people: OrgPerson[];
    open: boolean;
    onClose: () => void;
}) {
    const [managerId, setManagerId] = useState('');
    const [saving, setSaving] = useState(false);

    const options: PersonOption[] = people
        .filter((p) => p.user_id !== node.user_id)
        .map((p) => ({
            value: String(p.user_id),
            label: p.name,
            sub: p.position_title ?? undefined,
        }));

    const save = () => {
        setSaving(true);
        router.put(
            `/hr/orgchart/${node.id}`,
            { manager_user_id: managerId || null },
            {
                preserveScroll: true,
                onFinish: () => setSaving(false),
                onSuccess: onClose,
            },
        );
    };

    return (
        <Dialog open={open} onOpenChange={(o) => !o && onClose()}>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>Change reporting line</DialogTitle>
                    <DialogDescription>
                        Choose who{' '}
                        <span className="font-medium">{node.name}</span> reports
                        to. Leave empty to make them top-level.
                    </DialogDescription>
                </DialogHeader>
                <div className="py-2">
                    <PeoplePicker
                        value={managerId}
                        onChange={setManagerId}
                        people={options}
                        placeholder="Select a manager (or leave empty)"
                    />
                </div>
                <DialogFooter>
                    <Button variant="ghost" onClick={onClose}>
                        Cancel
                    </Button>
                    <Button onClick={save} disabled={saving}>
                        {saving ? 'Saving…' : 'Save'}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

function OrgNodeCard({
    node,
    canManage,
    people,
    searchQuery,
    matchingIds,
    depth = 0,
}: {
    node: OrgNode;
    canManage: boolean;
    people: OrgPerson[];
    searchQuery: string;
    matchingIds: Set<number>;
    depth?: number;
}) {
    const hasChildren = node.children.length > 0;
    const [reassignOpen, setReassignOpen] = useState(false);
    const isFilterActive = searchQuery.length > 0;
    const isMatch = matchingIds.has(node.id);
    const isSelfMatch = isFilterActive && matchesSearch(node, searchQuery);

    const [collapsed, setCollapsed] = useState(false);

    const isExpanded = isFilterActive ? isMatch : !collapsed;

    if (isFilterActive && !isMatch) {
        return null;
    }

    return (
        <div className="flex flex-col items-center">
            <div
                className={`relative w-56 overflow-hidden rounded-xl border bg-card shadow-sm transition-shadow hover:shadow-md ${
                    isSelfMatch ? 'ring-2 ring-primary/60' : ''
                }`}
            >
                {/* colour-coded title bar — the role, keyed to the department branch */}
                <div
                    className={`truncate px-3 py-1.5 text-[11px] font-bold tracking-wide uppercase ${deptBar(node.department)}`}
                >
                    {node.position_title || 'No position'}
                </div>

                <div className="flex items-center gap-3 p-3">
                    <span className="grid h-11 w-11 shrink-0 place-items-center overflow-hidden rounded-md bg-primary/10 text-sm font-semibold text-primary">
                        {node.photo_url ? (
                            <img
                                src={node.photo_url}
                                alt=""
                                className="h-full w-full object-cover"
                            />
                        ) : (
                            getInitials(node.name)
                        )}
                    </span>

                    <div className="min-w-0 flex-1">
                        <p className="truncate text-sm leading-tight font-semibold italic">
                            {node.name}
                        </p>
                        <p className="truncate text-xs text-muted-foreground">
                            {node.site || node.department || '—'}
                        </p>
                    </div>

                    {canManage && (
                        <Button
                            type="button"
                            variant="ghost"
                            size="icon"
                            onClick={() => setReassignOpen(true)}
                            title="Change reporting line"
                            aria-label={`Change reporting line for ${node.name}`}
                            className="h-7 w-7 shrink-0 text-muted-foreground hover:text-primary"
                        >
                            <UserCog className="h-4 w-4" />
                        </Button>
                    )}
                </div>

                {hasChildren && !isFilterActive && (
                    <Button
                        type="button"
                        variant="outline"
                        size="icon"
                        aria-label={collapsed ? 'Expand subordinates' : 'Collapse subordinates'}
                        onClick={() => setCollapsed((c) => !c)}
                        className="absolute -bottom-3 left-1/2 z-10 h-6 w-6 -translate-x-1/2 rounded-full text-muted-foreground shadow-sm"
                    >
                        {collapsed ? (
                            <ChevronRight className="h-3.5 w-3.5" />
                        ) : (
                            <ChevronDown className="h-3.5 w-3.5" />
                        )}
                    </Button>
                )}
            </div>

            {canManage && (
                <ReassignManagerDialog
                    node={node}
                    people={people}
                    open={reassignOpen}
                    onClose={() => setReassignOpen(false)}
                />
            )}

            {hasChildren && isExpanded && (
                <div className="flex flex-col items-center">
                    <div className="h-6 w-px bg-border" />

                    <div className="relative flex gap-8">
                        {node.children.filter(
                            (c) => !isFilterActive || matchingIds.has(c.id),
                        ).length > 1 && (
                            <div
                                className="absolute top-0 right-[calc(50%-var(--half-width))] left-[calc(50%-var(--half-width))] h-px bg-border"
                                style={
                                    {
                                        '--half-width': `calc(${
                                            ((node.children.filter(
                                                (c) =>
                                                    !isFilterActive ||
                                                    matchingIds.has(c.id),
                                            ).length -
                                                1) *
                                                100) /
                                            2
                                        }% + 0px)`,
                                    } as React.CSSProperties
                                }
                            />
                        )}

                        {node.children.map((child) => {
                            if (isFilterActive && !matchingIds.has(child.id))
                                return null;
                            return (
                                <div
                                    key={child.id}
                                    className="flex flex-col items-center"
                                >
                                    <div className="h-6 w-px bg-border" />
                                    <OrgNodeCard
                                        node={child}
                                        canManage={canManage}
                                        people={people}
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

/**
 * Org-chart tree pane — shared by the standalone page and the People-hub tab.
 * Owns its own search box, tree render and empty states (no PageHero/layout).
 */
export function OrgChartPane({
    hierarchy,
    people,
    canManage,
}: {
    hierarchy: OrgNode[];
    people: OrgPerson[];
    canManage: boolean;
}) {
    const [searchQuery, setSearchQuery] = useState('');
    const [builderOpen, setBuilderOpen] = useState(false);

    const matchingIds = useMemo(
        () => findMatchingIds(hierarchy, searchQuery),
        [hierarchy, searchQuery],
    );

    return (
        <div className="space-y-4">
            <div className="flex flex-wrap items-center justify-between gap-3">
                <div className="relative w-full max-w-sm">
                    <Search className="absolute top-1/2 left-3 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
                    <Input
                        placeholder="Search by name, position, department…"
                        value={searchQuery}
                        onChange={(e) => setSearchQuery(e.target.value)}
                        className="pl-9"
                    />
                </div>
                <div className="flex items-center gap-2">
                    {canManage && hierarchy.length > 0 ? (
                        <Button
                            size="sm"
                            onClick={() => setBuilderOpen(true)}
                            className="gap-1.5"
                        >
                            <Network className="h-4 w-4" />
                            Build org chart
                        </Button>
                    ) : null}
                    {hierarchy.length > 0 ? (
                        <Button
                            variant="outline"
                            size="sm"
                            onClick={() => window.print()}
                            className="gap-1.5"
                        >
                            <Printer className="h-4 w-4" />
                            Print
                        </Button>
                    ) : null}
                </div>
            </div>

            {canManage && (
                <OrgChartBuilderDialog
                    open={builderOpen}
                    onClose={() => setBuilderOpen(false)}
                    hierarchy={hierarchy}
                />
            )}

            <div className="overflow-x-auto pb-4">
                {hierarchy.length === 0 ? (
                    <Card>
                        <CardContent className="flex flex-col items-center justify-center py-16 text-center">
                            <Users className="mb-4 h-12 w-12 text-muted-foreground/40" />
                            <p className="text-lg font-medium text-muted-foreground">
                                No organisation structure found
                            </p>
                            <p className="mt-1 text-sm text-muted-foreground/70">
                                Assign managers to employees to build the org
                                chart.
                            </p>
                        </CardContent>
                    </Card>
                ) : searchQuery && matchingIds.size === 0 ? (
                    <Card>
                        <CardContent className="flex flex-col items-center justify-center py-16 text-center">
                            <Search className="mb-4 h-12 w-12 text-muted-foreground/40" />
                            <p className="text-lg font-medium text-muted-foreground">
                                No results found
                            </p>
                            <p className="mt-1 text-sm text-muted-foreground/70">
                                Try a different search term.
                            </p>
                        </CardContent>
                    </Card>
                ) : (
                    <div className="flex min-w-max justify-center gap-12 pt-4">
                        {hierarchy.map((root) => {
                            if (searchQuery && !matchingIds.has(root.id))
                                return null;
                            return (
                                <OrgNodeCard
                                    key={root.id}
                                    node={root}
                                    canManage={canManage}
                                    people={people}
                                    searchQuery={searchQuery}
                                    matchingIds={matchingIds}
                                />
                            );
                        })}
                    </div>
                )}
            </div>
        </div>
    );
}

export default OrgChartPane;
