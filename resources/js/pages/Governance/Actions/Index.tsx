import { PageHero, PageLayout } from '@/components/page';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { Textarea } from '@/components/ui/textarea';
import AppLayout from '@/layouts/app-layout';
import { governanceStatusColor } from '@/lib/governance-status';
import { cn } from '@/lib/utils';
import { show as showAction } from '@/routes/governance/actions';
import { PageProps } from '@/types';
import { Head, Link, router, useForm } from '@inertiajs/react';
import {
    AlertCircle,
    CheckSquare,
    Plus,
    Search,
    User as UserIcon,
} from 'lucide-react';
import React, { useState } from 'react';

interface UserRef {
    id: number;
    name: string;
    email?: string | null;
}

interface ActionItem {
    id: number;
    action_reference: string;
    title?: string | null;
    description: string;
    due_date: string;
    status: string;
    priority: string;
    assigned_to: UserRef;
    source_type?: string | null;
    source_id?: number | null;
    progress_pct?: number;
    blocked_at?: string | null;
    blocked_reason?: string | null;
    evidence_required?: boolean;
}

interface Props extends PageProps {
    items: {
        data: ActionItem[];
        total: number;
        current_page: number;
        last_page: number;
        links: Array<{
            url: string | null;
            label: string;
            active: boolean;
        }>;
    };
    summary: {
        total_open: number;
        overdue: number;
        my_open: number;
        high_priority: number;
    };
    filters: {
        status?: string;
        priority?: string;
        source_type?: string;
        assigned_to_me?: boolean;
        search?: string;
    };
    assignees: UserRef[];
}

export default function ActionsIndex({ auth, items, summary, filters, assignees }: Props) {
    const [search, setSearch] = useState(filters.search || '');
    const [statusFilter, setStatusFilter] = useState(filters.status || 'all');
    const [priorityFilter, setPriorityFilter] = useState(filters.priority || 'all');
    const [isCreateOpen, setIsCreateOpen] = useState(false);

    const { data: createData, setData: setCreateData, post: postCreate, processing: createProcessing, reset: resetCreate, errors: createErrors } = useForm({
        title: '',
        description: '',
        assigned_to: assignees[0]?.id ? String(assignees[0].id) : '',
        due_date: '',
        priority: 'medium',
        evidence_required: false,
    });

    const applyFilter = (key: string, value: string) => {
        const query: Record<string, any> = {
            ...filters,
            search: search,
            [key]: value,
        };
        if (value === 'all' || !value) {
            delete query[key];
        }
        router.get('/governance/actions', query, { preserveState: true, replace: true });
    };

    const handleSearchSubmit = (e: React.FormEvent) => {
        e.preventDefault();
        applyFilter('search', search);
    };

    const getStatusColor = (status: string) => governanceStatusColor(status);

    const getPriorityColor = (priority: string) => {
        return (
            {
                low: 'bg-muted text-foreground',
                medium: 'bg-status-info-bg text-status-info',
                high: 'bg-status-warning-bg text-status-warning',
                critical: 'bg-status-critical-bg text-status-critical',
            }[priority] || 'bg-muted text-foreground'
        );
    };

    const formatDate = (dateString: string) => {
        const date = new Date(dateString);
        const days = Math.ceil(
            (date.getTime() - new Date().getTime()) / (1000 * 60 * 60 * 24),
        );

        if (days < 0)
            return {
                text: `${Math.abs(days)} days overdue`,
                color: 'text-status-critical font-medium',
            };
        if (days === 0)
            return { text: 'Due today', color: 'text-status-warning font-medium' };
        return {
            text: `${days} days left`,
            color: days <= 3 ? 'text-status-warning' : 'text-muted-foreground',
        };
    };

    const handleCreateSubmit = (e: React.FormEvent) => {
        e.preventDefault();
        postCreate('/governance/actions', {
            onSuccess: () => {
                setIsCreateOpen(false);
                resetCreate();
            },
        });
    };

    return (
        <AppLayout
            user={auth.user}
            breadcrumbs={[
                { title: 'Home', href: '/dashboard' },
                { title: 'Governance', href: '/governance/dashboard' },
                { title: 'Actions', href: '/governance/actions' },
            ]}
        >
            <Head title="Action Items" />

            <PageLayout
                hero={
                    <PageHero
                        icon={CheckSquare}
                        title="Actions"
                        description="Track board decisions and follow-ups through to completion."
                        stats={[
                            { label: 'Open', value: summary.total_open },
                            { label: 'Overdue', value: summary.overdue },
                            { label: 'My open', value: summary.my_open },
                            {
                                label: 'High priority',
                                value: summary.high_priority,
                            },
                        ]}
                    />
                }
            >
                {/* Control bar: search, filters & create button */}
                <div className="mb-6 flex flex-col gap-4 md:flex-row md:items-center md:justify-between">
                    <form onSubmit={handleSearchSubmit} className="flex flex-1 items-center gap-2 max-w-md">
                        <div className="relative w-full">
                            <Search className="absolute left-3 top-2.5 h-4 w-4 text-muted-foreground" />
                            <Input
                                placeholder="Search by reference or description..."
                                className="pl-9"
                                value={search}
                                onChange={(e) => setSearch(e.target.value)}
                            />
                        </div>
                        <Button type="submit" variant="secondary" size="sm">
                            Search
                        </Button>
                    </form>

                    <div className="flex flex-wrap items-center gap-2">
                        {/* Status Filter */}
                        <select
                            aria-label="Filter by Status"
                            className="h-9 rounded-md border border-input bg-background px-3 py-1 text-sm shadow-sm focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-ring"
                            value={statusFilter}
                            onChange={(e) => {
                                setStatusFilter(e.target.value);
                                applyFilter('status', e.target.value);
                            }}
                        >
                            <option value="all">All Statuses</option>
                            <option value="open">Open</option>
                            <option value="in_progress">In Progress</option>
                            <option value="blocked">Blocked</option>
                            <option value="complete">Completed</option>
                        </select>

                        {/* Priority Filter */}
                        <select
                            aria-label="Filter by Priority"
                            className="h-9 rounded-md border border-input bg-background px-3 py-1 text-sm shadow-sm focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-ring"
                            value={priorityFilter}
                            onChange={(e) => {
                                setPriorityFilter(e.target.value);
                                applyFilter('priority', e.target.value);
                            }}
                        >
                            <option value="all">All Priorities</option>
                            <option value="critical">Critical</option>
                            <option value="high">High</option>
                            <option value="medium">Medium</option>
                            <option value="low">Low</option>
                        </select>

                        {/* Assigned to Me Button */}
                        <Button
                            variant={filters.assigned_to_me ? 'default' : 'outline'}
                            size="sm"
                            onClick={() => {
                                const newVal = filters.assigned_to_me ? '' : '1';
                                applyFilter('assigned_to_me', newVal);
                            }}
                        >
                            <UserIcon className="mr-1.5 h-3.5 w-3.5" />
                            My Actions
                        </Button>

                        <Button size="sm" onClick={() => setIsCreateOpen(true)}>
                            <Plus className="mr-1.5 h-4 w-4" />
                            New Action
                        </Button>
                    </div>
                </div>

                {/* Table */}
                <Card dusk="actions-list-card">
                    <CardHeader className="pb-3">
                        <CardTitle className="text-lg font-semibold flex items-center justify-between">
                            <span>Action Register ({items.total ?? items.data.length})</span>
                        </CardTitle>
                    </CardHeader>
                    <CardContent className="p-0">
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead className="w-[120px]">Reference</TableHead>
                                    <TableHead>Description</TableHead>
                                    <TableHead className="w-[150px]">Assignee</TableHead>
                                    <TableHead className="w-[140px]">Due Date</TableHead>
                                    <TableHead className="w-[100px]">Priority</TableHead>
                                    <TableHead className="w-[120px]">Progress</TableHead>
                                    <TableHead className="w-[120px]">Status</TableHead>
                                    <TableHead className="w-[80px] text-right">Action</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {items.data.length === 0 ? (
                                    <TableRow>
                                        <TableCell colSpan={8} className="py-8 text-center text-muted-foreground">
                                            No action items found matching criteria.
                                        </TableCell>
                                    </TableRow>
                                ) : (
                                    items.data.map((item) => {
                                        const dateInfo = formatDate(item.due_date);
                                        const isBlocked = item.status === 'blocked';
                                        return (
                                            <TableRow key={item.id} className={cn(isBlocked && 'bg-status-warning-bg/10')}>
                                                <TableCell className="font-mono text-xs font-semibold text-muted-foreground">
                                                    {item.action_reference}
                                                </TableCell>
                                                <TableCell>
                                                    <div className="space-y-1">
                                                        <Link
                                                            href={showAction.url({ action: item.id })}
                                                            className="font-medium hover:underline text-foreground"
                                                        >
                                                            {item.title || item.description}
                                                        </Link>
                                                        {item.title && item.title !== item.description && (
                                                            <p className="text-xs text-muted-foreground line-clamp-1">
                                                                {item.description}
                                                            </p>
                                                        )}
                                                        {item.source_type && (
                                                            <span className="inline-block text-[11px] font-mono text-muted-foreground uppercase">
                                                                From {item.source_type.replace(/.*\\/, '')} #{item.source_id}
                                                            </span>
                                                        )}
                                                    </div>
                                                </TableCell>
                                                <TableCell>
                                                    <div className="flex items-center gap-1.5 text-sm">
                                                        <UserIcon className="h-3.5 w-3.5 text-muted-foreground shrink-0" />
                                                        <span className="truncate">{item.assigned_to?.name ?? 'Unassigned'}</span>
                                                    </div>
                                                </TableCell>
                                                <TableCell>
                                                    <div className="text-xs">
                                                        <span className={dateInfo.color}>{dateInfo.text}</span>
                                                        <div className="text-muted-foreground">{item.due_date}</div>
                                                    </div>
                                                </TableCell>
                                                <TableCell>
                                                    <Badge className={cn('capitalize text-[11px]', getPriorityColor(item.priority))}>
                                                        {item.priority}
                                                    </Badge>
                                                </TableCell>
                                                <TableCell>
                                                    <div className="space-y-1">
                                                        <div className="flex items-center justify-between text-xs">
                                                            <span className="text-muted-foreground">{item.progress_pct ?? 0}%</span>
                                                        </div>
                                                        <div className="h-1.5 w-full overflow-hidden rounded-full bg-muted">
                                                            <div
                                                                className={cn(
                                                                    'h-full transition-all',
                                                                    (item.progress_pct ?? 0) >= 100
                                                                        ? 'bg-status-success'
                                                                        : 'bg-primary',
                                                                )}
                                                                style={{ width: `${item.progress_pct ?? 0}%` }}
                                                            />
                                                        </div>
                                                    </div>
                                                </TableCell>
                                                <TableCell>
                                                    <div className="space-y-0.5">
                                                        <Badge className={cn('capitalize text-[11px]', getStatusColor(item.status))}>
                                                            {item.status === 'complete' ? 'Completed' : item.status.replace('_', ' ')}
                                                        </Badge>
                                                        {isBlocked && (
                                                            <div className="flex items-center gap-1 text-[11px] text-status-warning font-medium">
                                                                <AlertCircle className="h-3 w-3" />
                                                                Blocked
                                                            </div>
                                                        )}
                                                    </div>
                                                </TableCell>
                                                <TableCell className="text-right">
                                                    <Button variant="ghost" size="sm" asChild>
                                                        <Link href={showAction.url({ action: item.id })}>
                                                            View &rarr;
                                                        </Link>
                                                    </Button>
                                                </TableCell>
                                            </TableRow>
                                        );
                                    })
                                )}
                            </TableBody>
                        </Table>

                        {/* Pagination */}
                        {items.links && items.links.length > 3 && (
                            <div className="flex items-center justify-between border-t px-4 py-3 text-sm">
                                <span className="text-muted-foreground">
                                    Page {items.current_page} of {items.last_page}
                                </span>
                                <div className="flex items-center gap-1">
                                    {items.links.map((link, i) => (
                                        <Button
                                            key={i}
                                            variant={link.active ? 'default' : 'outline'}
                                            size="sm"
                                            disabled={!link.url}
                                            asChild={!!link.url}
                                        >
                                            {link.url ? (
                                                <Link href={link.url} dangerouslySetInnerHTML={{ __html: link.label }} />
                                            ) : (
                                                <span dangerouslySetInnerHTML={{ __html: link.label }} />
                                            )}
                                        </Button>
                                    ))}
                                </div>
                            </div>
                        )}
                    </CardContent>
                </Card>
            </PageLayout>

            {/* Create Action Item Dialog */}
            <Dialog open={isCreateOpen} onOpenChange={setIsCreateOpen}>
                <DialogContent className="sm:max-w-[500px]">
                    <DialogHeader>
                        <DialogTitle>New Governance Action Item</DialogTitle>
                        <DialogDescription>
                            Create a standalone action item or follow-up task.
                        </DialogDescription>
                    </DialogHeader>

                    <form onSubmit={handleCreateSubmit} className="space-y-4 py-2">
                        <div className="space-y-1.5">
                            <Label htmlFor="create-title">Title / Summary</Label>
                            <Input
                                id="create-title"
                                placeholder="E.g. Conduct IT compliance audit"
                                value={createData.title}
                                onChange={(e) => setCreateData('title', e.target.value)}
                            />
                        </div>

                        <div className="space-y-1.5">
                            <Label htmlFor="create-description">Detailed Description <span className="text-status-critical">*</span></Label>
                            <Textarea
                                id="create-description"
                                placeholder="State the required deliverables, scope, and expected outcome..."
                                rows={3}
                                value={createData.description}
                                onChange={(e) => setCreateData('description', e.target.value)}
                                required
                            />
                            {createErrors.description && (
                                <p className="text-xs text-status-critical">{createErrors.description}</p>
                            )}
                        </div>

                        <div className="grid grid-cols-2 gap-3">
                            <div className="space-y-1.5">
                                <Label htmlFor="create-assignee">Assignee <span className="text-status-critical">*</span></Label>
                                <select
                                    id="create-assignee"
                                    className="w-full h-9 rounded-md border border-input bg-background px-3 py-1 text-sm shadow-sm"
                                    value={createData.assigned_to}
                                    onChange={(e) => setCreateData('assigned_to', e.target.value)}
                                    required
                                >
                                    {assignees.map((u) => (
                                        <option key={u.id} value={u.id}>
                                            {u.name}
                                        </option>
                                    ))}
                                </select>
                            </div>

                            <div className="space-y-1.5">
                                <Label htmlFor="create-due">Due Date <span className="text-status-critical">*</span></Label>
                                <Input
                                    id="create-due"
                                    type="date"
                                    value={createData.due_date}
                                    onChange={(e) => setCreateData('due_date', e.target.value)}
                                    required
                                />
                            </div>
                        </div>

                        <div className="grid grid-cols-2 gap-3">
                            <div className="space-y-1.5">
                                <Label htmlFor="create-priority">Priority</Label>
                                <select
                                    id="create-priority"
                                    className="w-full h-9 rounded-md border border-input bg-background px-3 py-1 text-sm shadow-sm"
                                    value={createData.priority}
                                    onChange={(e) => setCreateData('priority', e.target.value)}
                                >
                                    <option value="low">Low</option>
                                    <option value="medium">Medium</option>
                                    <option value="high">High</option>
                                    <option value="critical">Critical</option>
                                </select>
                            </div>

                            <div className="flex items-center space-x-2 pt-6">
                                <input
                                    type="checkbox"
                                    id="create-evidence"
                                    className="rounded border-input text-primary"
                                    checked={createData.evidence_required}
                                    onChange={(e) => setCreateData('evidence_required', e.target.checked)}
                                />
                                <Label htmlFor="create-evidence" className="text-xs cursor-pointer">
                                    Evidence documentation required
                                </Label>
                            </div>
                        </div>

                        <DialogFooter className="pt-2">
                            <Button type="button" variant="outline" onClick={() => setIsCreateOpen(false)}>
                                Cancel
                            </Button>
                            <Button type="submit" disabled={createProcessing || !createData.description}>
                                Create Action Item
                            </Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>
        </AppLayout>
    );
}
