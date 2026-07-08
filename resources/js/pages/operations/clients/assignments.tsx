import PageShell from '@/components/page-shell';
import { Avatar, AvatarFallback } from '@/components/ui/avatar';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { PageHero } from '@/components/page';
import { useInitials } from '@/hooks/use-initials';
import AppLayout from '@/layouts/app-layout';
import { Head, router, usePage } from '@inertiajs/react';
import {
    CheckCircle2,
    Search,
    Star,
    UserPlus,
    Users,
    UsersRound,
    X,
} from 'lucide-react';
import { useEffect, useMemo, useState } from 'react';

export default function ClientAssignments({
    client,
    workers,
    assignedIds,
}: {
    client: any;
    workers: any[];
    assignedIds: number[];
}) {
    const { labels } = usePage().props as any;
    const name = `${client.first_name} ${client.last_name}`;
    const getInitials = useInitials();

    const [selected, setSelected] = useState<number[]>(assignedIds ?? []);
    const [search, setSearch] = useState('');
    const [status, setStatus] = useState<'idle' | 'saving' | 'saved' | 'error'>(
        'idle',
    );

    useEffect(() => {
        setSelected(assignedIds ?? []);
    }, [assignedIds]);

    const isSelected = useMemo(() => new Set(selected), [selected]);

    const filteredWorkers = useMemo(() => {
        if (!search) return workers ?? [];
        const q = search.toLowerCase();
        return (workers ?? []).filter(
            (w) =>
                w.name?.toLowerCase().includes(q) ||
                w.email?.toLowerCase().includes(q),
        );
    }, [workers, search]);

    const assignedWorkers = useMemo(
        () => (workers ?? []).filter((w) => isSelected.has(w.id)),
        [workers, isSelected],
    );
    const unassignedWorkers = useMemo(
        () => filteredWorkers.filter((w) => !isSelected.has(w.id)),
        [filteredWorkers, isSelected],
    );

    function toggle(id: number) {
        setSelected((prev) =>
            prev.includes(id) ? prev.filter((x) => x !== id) : [...prev, id],
        );
    }

    function save() {
        setStatus('saving');
        router.put(
            `/operations/clients/${client.id}/assignments`,
            { user_ids: selected },
            {
                preserveScroll: true,
                onSuccess: () => {
                    setStatus('saved');
                    setTimeout(() => setStatus('idle'), 2000);
                },
                onError: () => setStatus('error'),
            },
        );
    }

    return (
        <AppLayout
            breadcrumbs={[
                {
                    title: labels?.['client.plural'] ?? 'Clients',
                    href: '/operations/clients',
                },
                { title: name, href: `/operations/clients/${client.id}` },
                { title: 'Assign Workers' },
            ]}
        >
            <Head title={`Assign Workers - ${name}`} />
            <PageHero
                icon={UsersRound}
                title="Assign Workers"
                description={`Manage which support workers are assigned to ${client.first_name}.`}
                backHref={`/operations/clients/${client.id}`}
                backLabel={`Back to ${client.first_name}`}
                stats={[
                    { label: 'Assigned', value: assignedWorkers.length },
                    { label: 'Available', value: unassignedWorkers.length },
                    { label: 'Total workers', value: workers?.length ?? 0 },
                ]}
                actions={
                    <Button
                        className="gap-1.5 bg-primary hover:bg-primary"
                        onClick={save}
                        disabled={status === 'saving'}
                    >
                        {status === 'saving'
                            ? 'Saving...'
                            : status === 'saved'
                              ? 'Saved!'
                              : 'Save Changes'}
                    </Button>
                }
            />
            <PageShell>
                {status === 'error' && (
                    <div className="rounded-xl border border-status-critical/30 bg-status-critical-bg p-3 text-sm text-status-critical">
                        Something went wrong saving assignments. Please try
                        again.
                    </div>
                )}

                <div className="grid gap-6 lg:grid-cols-2">
                    {/* Left: Currently Assigned */}
                    <div>
                        <div className="mb-3 flex items-center gap-2">
                            <div className="flex h-7 w-7 items-center justify-center rounded-lg bg-primary/10 text-primary">
                                <Users className="h-4 w-4" />
                            </div>
                            <h2 className="text-sm font-semibold">
                                Assigned Workers
                            </h2>
                            <Badge variant="secondary" className="text-[10px]">
                                {assignedWorkers.length}
                            </Badge>
                        </div>

                        {assignedWorkers.length === 0 ? (
                            <Card className="border-dashed">
                                <CardContent className="flex flex-col items-center justify-center py-10">
                                    <Users className="mb-2 h-8 w-8 text-muted-foreground" />
                                    <p className="text-sm text-muted-foreground">
                                        No workers assigned yet
                                    </p>
                                    <p className="text-xs text-muted-foreground">
                                        Select workers from the list on the
                                        right
                                    </p>
                                </CardContent>
                            </Card>
                        ) : (
                            <div className="space-y-2">
                                {assignedWorkers.map((w) => (
                                    <Card
                                        key={w.id}
                                        className="bg-primary/10 border-primary transition-all hover:shadow-sm"
                                    >
                                        <CardContent className="flex items-center justify-between p-3">
                                            <div className="flex items-center gap-3">
                                                <Avatar className="h-9 w-9 border-2 border-primary">
                                                    <AvatarFallback className="bg-primary/20 text-xs font-bold text-primary">
                                                        {getInitials(w.name)}
                                                    </AvatarFallback>
                                                </Avatar>
                                                <div>
                                                    <div className="flex items-center gap-1.5">
                                                        <span className="text-sm font-medium">
                                                            {w.name}
                                                        </span>
                                                        {client.key_worker_id ===
                                                            w.id && (
                                                            <span className="flex items-center gap-0.5 rounded-full bg-status-warning-bg px-1.5 py-0.5 text-[10px] font-medium text-status-warning">
                                                                <Star className="h-2.5 w-2.5" />{' '}
                                                                Key Worker
                                                            </span>
                                                        )}
                                                    </div>
                                                    <p className="text-xs text-muted-foreground">
                                                        {w.email}
                                                    </p>
                                                </div>
                                            </div>
                                            <Button
                                                variant="ghost"
                                                size="sm"
                                                className="h-7 w-7 p-0 text-status-critical hover:bg-status-critical-bg hover:text-status-critical"
                                                onClick={() => toggle(w.id)}
                                                title="Remove"
                                            >
                                                <X className="h-4 w-4" />
                                            </Button>
                                        </CardContent>
                                    </Card>
                                ))}
                            </div>
                        )}
                    </div>

                    {/* Right: Available Workers */}
                    <div>
                        <div className="mb-3 flex items-center gap-2">
                            <div className="flex h-7 w-7 items-center justify-center rounded-lg bg-status-success-bg text-status-success">
                                <UserPlus className="h-4 w-4" />
                            </div>
                            <h2 className="text-sm font-semibold">
                                Available Workers
                            </h2>
                            <Badge variant="secondary" className="text-[10px]">
                                {unassignedWorkers.length}
                            </Badge>
                        </div>

                        {/* Search */}
                        <div className="relative mb-3">
                            <Search className="absolute top-2.5 left-2.5 h-3.5 w-3.5 text-muted-foreground" />
                            <Input
                                placeholder="Search workers..."
                                className="h-9 pl-8 text-sm"
                                value={search}
                                onChange={(e) => setSearch(e.target.value)}
                            />
                        </div>

                        {unassignedWorkers.length === 0 ? (
                            <Card className="border-dashed">
                                <CardContent className="flex flex-col items-center justify-center py-10">
                                    <CheckCircle2 className="mb-2 h-8 w-8 text-status-success" />
                                    <p className="text-sm text-muted-foreground">
                                        {search
                                            ? 'No workers match your search'
                                            : 'All workers are assigned!'}
                                    </p>
                                </CardContent>
                            </Card>
                        ) : (
                            <Card className="bg-card">
                                <CardContent className="space-y-1.5 p-2">
                                    {unassignedWorkers.map((w) => (
                                        <Button
                                            key={w.id}
                                            type="button"
                                            variant="ghost"
                                            onClick={() => toggle(w.id)}
                                            className="h-auto w-full justify-start gap-3 rounded-lg p-2.5 text-left hover:bg-status-success-bg"
                                        >
                                            <Avatar className="h-8 w-8">
                                                <AvatarFallback className="bg-muted text-xs">
                                                    {getInitials(w.name)}
                                                </AvatarFallback>
                                            </Avatar>
                                            <div className="flex-1">
                                                <span className="text-sm font-medium">
                                                    {w.name}
                                                </span>
                                                <p className="text-xs text-muted-foreground">
                                                    {w.email}
                                                </p>
                                            </div>
                                            <div className="flex h-7 w-7 items-center justify-center rounded-full border-2 border-dashed border-status-success/30 text-status-success">
                                                <UserPlus className="h-3.5 w-3.5" />
                                            </div>
                                        </Button>
                                    ))}
                                </CardContent>
                            </Card>
                        )}
                    </div>
                </div>
            </PageShell>
        </AppLayout>
    );
}
