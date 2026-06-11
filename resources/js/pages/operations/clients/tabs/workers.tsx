import { Avatar, AvatarFallback } from '@/components/ui/avatar';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { useInitials } from '@/hooks/use-initials';
import { router } from '@inertiajs/react';
import {
    CheckCircle2,
    Save,
    Search,
    Star,
    UserPlus,
    Users,
    X,
} from 'lucide-react';
import type { ReactNode } from 'react';
import { useEffect, useMemo, useState } from 'react';

export type WorkerOption = {
    id: number;
    name: string;
    email?: string | null;
};

type WorkersTabClient = {
    id: number;
    first_name: string;
    preferred_name?: string | null;
    key_worker?: { id: number; name: string } | null;
    support_workers?: WorkerOption[];
};

type WorkersTabProps = {
    client: WorkersTabClient;
    assignableWorkers?: WorkerOption[];
    canAssign: boolean;
};

const NONE = '__none';

function matches(worker: WorkerOption, search: string): boolean {
    if (!search.trim()) return true;
    const q = search.toLowerCase();
    return (
        worker.name.toLowerCase().includes(q) ||
        (worker.email ?? '').toLowerCase().includes(q)
    );
}

export function WorkersTab({
    client,
    assignableWorkers = [],
    canAssign,
}: WorkersTabProps) {
    const getInitials = useInitials();
    const assignedSource = useMemo(
        () => client.support_workers ?? [],
        [client.support_workers],
    );
    const workerPool = useMemo(
        () => (assignableWorkers.length ? assignableWorkers : assignedSource),
        [assignableWorkers, assignedSource],
    );
    const [selected, setSelected] = useState<number[]>(
        assignedSource.map((worker) => worker.id),
    );
    const [keyWorkerId, setKeyWorkerId] = useState<string>(
        client.key_worker?.id ? String(client.key_worker.id) : NONE,
    );
    const [search, setSearch] = useState('');
    const [status, setStatus] = useState<'idle' | 'saving' | 'saved' | 'error'>(
        'idle',
    );

    useEffect(() => {
        setSelected(assignedSource.map((worker) => worker.id));
        setKeyWorkerId(
            client.key_worker?.id ? String(client.key_worker.id) : NONE,
        );
    }, [assignedSource, client.key_worker?.id]);

    const selectedSet = useMemo(() => new Set(selected), [selected]);
    const assignedWorkers = useMemo(
        () => workerPool.filter((worker) => selectedSet.has(worker.id)),
        [workerPool, selectedSet],
    );
    const availableWorkers = useMemo(
        () =>
            workerPool.filter(
                (worker) =>
                    !selectedSet.has(worker.id) && matches(worker, search),
            ),
        [workerPool, selectedSet, search],
    );

    const toggle = (workerId: number) => {
        if (!canAssign) return;
        setSelected((current) =>
            current.includes(workerId)
                ? current.filter((id) => id !== workerId)
                : [...current, workerId],
        );
    };

    const saveAssignments = () => {
        setStatus('saving');
        router.put(
            `/operations/clients/${client.id}/assignments`,
            { user_ids: selected, _modal: 1 },
            {
                preserveScroll: true,
                preserveState: false,
                onSuccess: () => {
                    setStatus('saved');
                    window.setTimeout(() => setStatus('idle'), 1800);
                },
                onError: () => setStatus('error'),
            },
        );
    };

    const saveKeyWorker = (value: string) => {
        setKeyWorkerId(value);
        router.patch(
            `/operations/clients/${client.id}/quick-update`,
            { key_worker_id: value === NONE ? null : Number(value) },
            {
                preserveScroll: true,
                preserveState: false,
            },
        );
    };

    return (
        <div className="space-y-4">
            <div className="flex flex-wrap items-center justify-between gap-3">
                <div className="flex items-center gap-3">
                    <span className="flex h-10 w-10 items-center justify-center rounded-xl bg-accent text-primary">
                        <Users className="h-[19px] w-[19px]" />
                    </span>
                    <div>
                        <h2 className="text-lg leading-tight font-semibold">
                            Workers
                        </h2>
                        <p className="text-sm text-muted-foreground">
                            Support team assigned to{' '}
                            {client.preferred_name || client.first_name}
                        </p>
                    </div>
                </div>
                {canAssign ? (
                    <Button
                        onClick={saveAssignments}
                        disabled={status === 'saving'}
                    >
                        <Save className="mr-1.5 h-4 w-4" />
                        {status === 'saving'
                            ? 'Saving...'
                            : status === 'saved'
                              ? 'Saved'
                              : 'Save workers'}
                    </Button>
                ) : null}
            </div>

            {status === 'error' ? (
                <div className="rounded-lg border border-status-critical/30 bg-status-critical-bg p-3 text-sm text-status-critical">
                    Worker assignments could not be saved. Please try again.
                </div>
            ) : null}

            <div className="grid grid-cols-2 gap-3 sm:grid-cols-3">
                {/* eslint-disable-next-line no-restricted-syntax -- MiniStat tile per the profile pattern language. */}
                <div className="rounded-xl border bg-card px-4 py-3">
                    <div className="text-xl font-bold text-status-info">
                        {assignedWorkers.length}
                    </div>
                    <div className="text-xs text-muted-foreground">
                        Assigned workers
                    </div>
                </div>
                {/* eslint-disable-next-line no-restricted-syntax -- MiniStat tile per the profile pattern language. */}
                <div className="rounded-xl border bg-card px-4 py-3">
                    <div className="truncate text-xl font-bold text-primary">
                        {client.key_worker?.name ?? '—'}
                    </div>
                    <div className="text-xs text-muted-foreground">
                        Key worker
                    </div>
                </div>
                {/* eslint-disable-next-line no-restricted-syntax -- MiniStat tile per the profile pattern language. */}
                <div className="col-span-2 rounded-xl border bg-card px-4 py-3 sm:col-span-1">
                    <div className="text-sm font-medium text-foreground/80">
                        Assigned workers see this profile, get rostered, and
                        receive handovers.
                    </div>
                </div>
            </div>

            {canAssign ? (
                <Card>
                    <CardHeader>
                        <CardTitle className="text-base">Key worker</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <Select
                            value={keyWorkerId}
                            onValueChange={saveKeyWorker}
                        >
                            <SelectTrigger className="min-h-11 max-w-md">
                                <SelectValue placeholder="Select key worker" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value={NONE}>
                                    No key worker
                                </SelectItem>
                                {assignedWorkers.map((worker) => (
                                    <SelectItem
                                        key={worker.id}
                                        value={String(worker.id)}
                                    >
                                        {worker.name}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                    </CardContent>
                </Card>
            ) : null}

            <div className="grid gap-4 lg:grid-cols-2">
                <WorkerList
                    title="Assigned"
                    icon={Users}
                    count={assignedWorkers.length}
                    workers={assignedWorkers}
                    emptyIcon={Users}
                    emptyTitle="No workers assigned yet"
                    emptyDescription="Add workers from the available list."
                    renderAction={(worker) =>
                        canAssign ? (
                            <Button
                                type="button"
                                variant="ghost"
                                size="sm"
                                className="h-8 w-8 p-0 text-status-critical hover:bg-status-critical-bg hover:text-status-critical"
                                onClick={() => toggle(worker.id)}
                                title="Remove"
                            >
                                <X className="h-4 w-4" />
                            </Button>
                        ) : null
                    }
                    getInitials={getInitials}
                    keyWorkerId={client.key_worker?.id ?? null}
                />

                <Card className="gap-0 py-0">
                    <CardHeader className="px-5 pt-4 pb-0">
                        <CardTitle className="flex items-center gap-2 text-[15px]">
                            <UserPlus className="h-4 w-4 text-status-success" />
                            Available
                            <Badge
                                variant="secondary"
                                className="ml-auto text-[10px]"
                            >
                                {availableWorkers.length}
                            </Badge>
                        </CardTitle>
                        <div className="relative mt-3">
                            <Search className="absolute top-2.5 left-2.5 h-3.5 w-3.5 text-muted-foreground" />
                            <Input
                                value={search}
                                onChange={(event) =>
                                    setSearch(event.target.value)
                                }
                                placeholder="Search workers"
                                className="h-9 pl-8 text-sm"
                            />
                        </div>
                    </CardHeader>
                    <CardContent className="space-y-1.5 px-5 pt-3 pb-5">
                        {availableWorkers.length > 0 ? (
                            availableWorkers.map((worker) => (
                                // eslint-disable-next-line no-restricted-syntax -- Available worker row is a full-width selectable layout card.
                                <button
                                    key={worker.id}
                                    type="button"
                                    disabled={!canAssign}
                                    onClick={() => toggle(worker.id)}
                                    className="flex w-full items-center gap-2.5 rounded-lg border px-2.5 py-2 text-left transition-colors hover:bg-status-success-bg disabled:cursor-not-allowed disabled:opacity-60"
                                >
                                    <Avatar className="h-[34px] w-[34px]">
                                        <AvatarFallback className="text-xs">
                                            {getInitials(worker.name)}
                                        </AvatarFallback>
                                    </Avatar>
                                    <span className="min-w-0 flex-1">
                                        <span className="block truncate text-sm font-medium">
                                            {worker.name}
                                        </span>
                                        {worker.email ? (
                                            <span className="block truncate text-xs text-muted-foreground">
                                                {worker.email}
                                            </span>
                                        ) : null}
                                    </span>
                                    <span className="flex h-7 w-7 items-center justify-center rounded-full border-2 border-dashed border-status-success/30 text-status-success">
                                        <UserPlus className="h-3.5 w-3.5" />
                                    </span>
                                </button>
                            ))
                        ) : (
                            <div className="flex flex-col items-center gap-2 rounded-xl border border-dashed py-10 text-center">
                                <CheckCircle2 className="h-6 w-6 text-status-success" />
                                <p className="text-sm font-medium">
                                    {search
                                        ? 'No workers match your search'
                                        : 'All workers are assigned'}
                                </p>
                            </div>
                        )}
                    </CardContent>
                </Card>
            </div>
        </div>
    );
}

function WorkerList({
    title,
    icon: Icon,
    count,
    workers,
    emptyIcon: EmptyIcon,
    emptyTitle,
    emptyDescription,
    renderAction,
    getInitials,
    keyWorkerId,
}: {
    title: string;
    icon: typeof Users;
    count: number;
    workers: WorkerOption[];
    emptyIcon: typeof Users;
    emptyTitle: string;
    emptyDescription: string;
    renderAction: (worker: WorkerOption) => ReactNode;
    getInitials: (name: string) => string;
    keyWorkerId: number | null;
}) {
    return (
        <Card className="gap-0 py-0">
            <CardHeader className="px-5 pt-4 pb-0">
                <CardTitle className="flex items-center gap-2 text-[15px]">
                    <Icon className="h-4 w-4 text-primary" />
                    {title}
                    <Badge variant="secondary" className="ml-auto text-[10px]">
                        {count}
                    </Badge>
                </CardTitle>
            </CardHeader>
            <CardContent className="space-y-1.5 px-5 pt-3 pb-5">
                {workers.length > 0 ? (
                    workers.map((worker) => {
                        const isKey = keyWorkerId === worker.id;
                        return (
                            <div
                                key={worker.id}
                                className={`flex items-center gap-2.5 rounded-lg border px-2.5 py-2 ${
                                    isKey
                                        ? 'border-primary/30 bg-accent'
                                        : 'border-border'
                                }`}
                            >
                                <Avatar className="h-[34px] w-[34px]">
                                    <AvatarFallback className="text-xs">
                                        {getInitials(worker.name)}
                                    </AvatarFallback>
                                </Avatar>
                                <div className="min-w-0 flex-1">
                                    <div className="flex flex-wrap items-center gap-1.5">
                                        <span className="truncate text-sm font-medium">
                                            {worker.name}
                                        </span>
                                        {isKey ? (
                                            <span className="inline-flex items-center gap-0.5 rounded-full bg-status-warning-bg px-1.5 py-0.5 text-[10px] font-medium text-status-warning">
                                                <Star className="h-2.5 w-2.5" />
                                                Key worker
                                            </span>
                                        ) : null}
                                    </div>
                                    {worker.email ? (
                                        <p className="truncate text-xs text-muted-foreground">
                                            {worker.email}
                                        </p>
                                    ) : null}
                                </div>
                                {renderAction(worker)}
                            </div>
                        );
                    })
                ) : (
                    <div className="flex flex-col items-center gap-2 rounded-xl border border-dashed py-10 text-center">
                        <EmptyIcon className="h-6 w-6 text-muted-foreground" />
                        <p className="text-sm font-medium">{emptyTitle}</p>
                        <p className="text-xs text-muted-foreground">
                            {emptyDescription}
                        </p>
                    </div>
                )}
            </CardContent>
        </Card>
    );
}
