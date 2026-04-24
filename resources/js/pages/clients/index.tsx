import PageHeader from '@/components/page-header';
import PageShell from '@/components/page-shell';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { useInitials } from '@/hooks/use-initials';
import AppLayout from '@/layouts/app-layout';
import { Head, Link, usePage } from '@inertiajs/react';
import { useMemo, useState } from 'react';

export default function ClientsIndex({ clients }) {
    const { auth } = usePage().props as any;
    const { labels } = usePage().props as any;
    const role = auth?.user?.role;
    const siteSingular = labels?.['site.singular'] ?? 'Site';
    const can = auth?.can;

    const canCreate = !!can?.clients?.create;
    const canUpdate = !!can?.clients?.update;
    const canManage = canCreate || canUpdate;

    const getInitials = useInitials();

    const [query, setQuery] = useState('');
    const [onlyIncomplete, setOnlyIncomplete] = useState(false);
    const [respiteFilter, setRespiteFilter] = useState<'all' | 'yes' | 'no'>(
        'all',
    );

    const filteredClients = useMemo(() => {
        const q = query.trim().toLowerCase();
        let rows = clients as any[];
        if (onlyIncomplete) {
            rows = rows.filter((c) => c.onboarding?.status !== 'complete');
        }
        if (respiteFilter === 'yes') {
            rows = rows.filter((c) => c.has_respite);
        }
        if (respiteFilter === 'no') {
            rows = rows.filter((c) => !c.has_respite);
        }
        if (!q) return rows;
        return rows.filter((c) => {
            const name = `${c.first_name ?? ''} ${c.last_name ?? ''}`
                .trim()
                .toLowerCase();
            const site = (c.site?.name ?? '').toLowerCase();
            const status = (c.status ?? '').toLowerCase();
            return name.includes(q) || site.includes(q) || status.includes(q);
        });
    }, [clients, query, onlyIncomplete, respiteFilter]);

    const breadcrumbs = useMemo(
        () => [
            { title: labels?.['client.plural'] ?? 'Clients', href: '/clients' },
        ],
        [labels],
    );

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={labels?.['client.plural'] ?? 'Clients'} />

            <PageShell>
                <PageHeader
                    title={labels?.['client.plural'] ?? 'Clients'}
                    description={
                        role === 'support_worker'
                            ? 'Only clients assigned to you are shown.'
                            : 'View and manage client profiles, documents, medical info, and assignments.'
                    }
                    actions={
                        canManage ? (
                            <Button asChild>
                                <Link href="/system/users/create?type=client">
                                    Add{' '}
                                    {labels?.['client.singular'] ?? 'Client'}
                                </Link>
                            </Button>
                        ) : null
                    }
                />

                <div className="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                    <div className="max-w-md">
                        <Input
                            placeholder="Search clients…"
                            value={query}
                            onChange={(e) => setQuery(e.target.value)}
                        />
                    </div>
                    <div className="flex items-center gap-3">
                        <div className="w-40">
                            <Select
                                value={respiteFilter}
                                onValueChange={(v) =>
                                    setRespiteFilter(v as 'all' | 'yes' | 'no')
                                }
                            >
                                <SelectTrigger>
                                    <SelectValue placeholder="Respite" />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="all">
                                        Respite: All
                                    </SelectItem>
                                    <SelectItem value="yes">
                                        Respite: Yes
                                    </SelectItem>
                                    <SelectItem value="no">
                                        Respite: No
                                    </SelectItem>
                                </SelectContent>
                            </Select>
                        </div>
                        <label className="flex cursor-pointer items-center gap-2 text-xs text-muted-foreground">
                            <Checkbox
                                checked={onlyIncomplete}
                                onCheckedChange={(v) => setOnlyIncomplete(!!v)}
                            />
                            Onboarding incomplete only
                        </label>
                        <div className="text-sm text-muted-foreground">
                            Showing {filteredClients.length} of {clients.length}
                        </div>
                    </div>
                </div>

                {/* List */}
                <div className="grid grid-cols-3 gap-1 space-y-2">
                    {filteredClients.map((client) => (
                        <div
                            key={client.id}
                            className="flex flex-col gap-3 rounded-md border p-4 sm:flex-row sm:items-center sm:justify-between"
                        >
                            <div className="flex items-start gap-3">
                                <Avatar className="h-25 w-25">
                                    <AvatarImage
                                        src={
                                            client.avatar ??
                                            client.profile_photo_url
                                        }
                                        alt={`${client.first_name} ${client.last_name}`}
                                    />
                                    <AvatarFallback>
                                        {getInitials(
                                            `${client.first_name} ${client.last_name}`,
                                        )}
                                    </AvatarFallback>
                                </Avatar>
                                <div>
                                    <div className="text-sm font-medium">
                                        {client.first_name} {client.last_name}
                                    </div>
                                    {client.nhi_number ? (
                                        <div className="mt-0.5 text-xs font-mono text-muted-foreground">
                                            NHI: {client.nhi_number}
                                        </div>
                                    ) : null}
                                    <div className="mt-1 flex flex-wrap items-center gap-2">
                                        <div className="text-xs text-muted-foreground">
                                            Status: {client.status}
                                        </div>
                                        {client.onboarding ? (
                                            <div
                                                className={`rounded-full px-2 py-0.5 text-xs ${
                                                    client.onboarding.status ===
                                                    'complete'
                                                        ? 'bg-status-success-bg text-status-success'
                                                        : 'bg-status-warning-bg text-status-warning'
                                                }`}
                                            >
                                                Onboarding:{' '}
                                                {client.onboarding.status ===
                                                'complete'
                                                    ? 'Complete'
                                                    : 'Incomplete'}
                                                {typeof client.onboarding
                                                    .percent === 'number'
                                                    ? ` • ${client.onboarding.percent}%`
                                                    : ''}
                                            </div>
                                        ) : null}
                                    </div>
                                    <div className="mt-1 text-xs text-muted-foreground">
                                        {siteSingular}:{' '}
                                        {client.site ? (
                                            can?.sites?.update ? (
                                                <Link
                                                    href={`/sites/${client.site.id}/edit`}
                                                    className="text-primary/70 hover:text-primary/70"
                                                >
                                                    {client.site.name}
                                                </Link>
                                            ) : (
                                                <span className="text-muted-foreground">
                                                    {client.site.name}
                                                </span>
                                            )
                                        ) : (
                                            <span className="text-muted-foreground">
                                                —
                                            </span>
                                        )}
                                    </div>
                                </div>
                            </div>

                            <div className="flex items-center gap-2">
                                <Button variant="outline" size="sm" asChild>
                                    <Link href={`/clients/${client.id}`}>
                                        View
                                    </Link>
                                </Button>

                                {canManage && (
                                    <>
                                        <Link
                                            href={`/clients/${client.id}/edit`}
                                            className="rounded-md border px-3 py-2 text-xs hover:bg-muted"
                                        >
                                            Edit
                                        </Link>
                                    </>
                                )}
                            </div>
                        </div>
                    ))}

                    {clients.length === 0 && (
                        <div className="rounded-md border p-4 text-sm text-muted-foreground">
                            No{' '}
                            {labels?.['client.plural']?.toLowerCase() ??
                                'clients'}{' '}
                            found.
                        </div>
                    )}

                    {clients.length > 0 && filteredClients.length === 0 && (
                        <div className="rounded-md border p-4 text-sm text-muted-foreground">
                            No clients match your search.
                        </div>
                    )}
                </div>

                {canManage ? (
                    <div className="rounded-md border bg-muted/30 p-4 text-sm text-muted-foreground">
                        Tip: open a client profile to access tabs for medical,
                        support plan, documents, portal users, and timeline.
                    </div>
                ) : null}
            </PageShell>
        </AppLayout>
    );
}
