import PageHeader from '@/components/page-header';
import PageShell from '@/components/page-shell';
import { ClientEditDialog } from '@/components/client-edit-dialog';
import { ClientSafetyBadges } from '@/components/client-safety-ribbon';
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
import { MapPin, Pencil } from 'lucide-react';
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
    const [editingClientId, setEditingClientId] = useState<number | null>(null);

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
    }, [clients, query, onlyIncomplete]);

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
                                <Link href="/operations/clients/create">
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
                <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-3">
                    {filteredClients.map((client) => {
                        const isActive = client.status === 'active';
                        return (
                            <div
                                key={client.id}
                                className="group relative flex flex-col rounded-xl border bg-card p-5 shadow-sm transition-all duration-200 hover:-translate-y-0.5 hover:border-primary/40 hover:shadow-md"
                            >
                                {/* Top: Avatar + identity */}
                                <div className="flex min-w-0 items-start gap-4">
                                    <Avatar className="h-14 w-14 shrink-0 ring-2 ring-background shadow-sm">
                                        <AvatarImage
                                            src={
                                                client.avatar ??
                                                client.profile_photo_url
                                            }
                                            alt={`${client.first_name} ${client.last_name}`}
                                        />
                                        <AvatarFallback className="bg-primary/10 text-sm font-semibold text-primary">
                                            {getInitials(
                                                `${client.first_name} ${client.last_name}`,
                                            )}
                                        </AvatarFallback>
                                    </Avatar>
                                    <div className="min-w-0 flex-1 space-y-1">
                                        <div className="truncate text-base font-semibold leading-tight">
                                            {client.first_name}{' '}
                                            {client.last_name}
                                        </div>
                                        <div className="flex items-center gap-1.5 text-xs">
                                            <span
                                                className={`inline-block h-1.5 w-1.5 rounded-full ${
                                                    isActive
                                                        ? 'bg-status-success ring-2 ring-status-success/20'
                                                        : 'bg-muted ring-2 ring-slate-400/20'
                                                }`}
                                            />
                                            <span className="capitalize text-muted-foreground">
                                                {client.status}
                                            </span>
                                        </div>
                                        <div className="flex items-center gap-1 text-xs text-muted-foreground">
                                            <MapPin className="h-3 w-3 shrink-0" />
                                            <span className="truncate">
                                                {client.site ? (
                                                    can?.sites?.update ? (
                                                        <Link
                                                            href={`/sites/${client.site.id}/edit`}
                                                            className="hover:text-primary hover:underline"
                                                        >
                                                            {client.site.name}
                                                        </Link>
                                                    ) : (
                                                        client.site.name
                                                    )
                                                ) : (
                                                    `No ${siteSingular.toLowerCase()} assigned`
                                                )}
                                            </span>
                                        </div>
                                    </div>
                                </div>

                                {/* Safety badges */}
                                {client.safety?.has_any && (
                                    <div className="mt-3">
                                        <ClientSafetyBadges
                                            summary={client.safety}
                                        />
                                    </div>
                                )}

                                {/* Footer: actions */}
                                <div className="mt-auto flex items-center gap-1.5 pt-4">
                                    {role === 'support_worker' ? (
                                        <Button
                                            variant="default"
                                            size="sm"
                                            className="flex-1"
                                            asChild
                                        >
                                            <Link
                                                href={`/operations/clients/${client.id}/care`}
                                            >
                                                Open care view
                                            </Link>
                                        </Button>
                                    ) : (
                                        <>
                                            <Button
                                                variant="outline"
                                                size="sm"
                                                className="flex-1"
                                                asChild
                                            >
                                                <Link
                                                    href={`/operations/clients/${client.id}`}
                                                >
                                                    View
                                                </Link>
                                            </Button>
                                            <Button
                                                variant="default"
                                                size="sm"
                                                className="flex-1"
                                                asChild
                                            >
                                                <Link
                                                    href={`/operations/clients/${client.id}/care`}
                                                >
                                                    Care view
                                                </Link>
                                            </Button>
                                        </>
                                    )}

                                    {canManage && (
                                        <Button
                                            type="button"
                                            variant="ghost"
                                            size="sm"
                                            className="h-8 w-8 p-0"
                                            title="Edit"
                                            onClick={() =>
                                                setEditingClientId(client.id)
                                            }
                                        >
                                            <Pencil className="h-3.5 w-3.5" />
                                            <span className="sr-only">
                                                Edit
                                            </span>
                                        </Button>
                                    )}
                                </div>
                            </div>
                        );
                    })}

                    {clients.length === 0 && (
                        <div className="col-span-full rounded-xl border border-dashed p-8 text-center text-sm text-muted-foreground">
                            No{' '}
                            {labels?.['client.plural']?.toLowerCase() ??
                                'clients'}{' '}
                            found.
                        </div>
                    )}

                    {clients.length > 0 && filteredClients.length === 0 && (
                        <div className="col-span-full rounded-xl border border-dashed p-8 text-center text-sm text-muted-foreground">
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

            <ClientEditDialog
                clientId={editingClientId}
                open={editingClientId !== null}
                onOpenChange={(isOpen) => {
                    if (!isOpen) setEditingClientId(null);
                }}
                siteSingular={siteSingular}
            />
        </AppLayout>
    );
}
