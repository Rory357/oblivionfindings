import { ListCaption } from '@/components/lists';
import {
    PageHeader,
    PageHeaderFilterSelect,
    PageHeaderMeterBig,
    PageHeaderMeterBlock,
    PageHeaderMeterCaption,
    PageHeaderMeterDonut,
    PageHeaderRail,
    type PageHeaderRailItem,
    PageHeaderSearch,
    PageHeaderStatusChip,
    PageLayout,
} from '@/components/page';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { EmptyState } from '@/components/ui/empty-state';
import { StatusBadge } from '@/components/ui/status-badge';
import AppLayout from '@/layouts/app-layout';
import { Head, Link, router, usePage } from '@inertiajs/react';
import {
    BedDouble,
    Bell,
    CalendarDays,
    Eye,
    HeartHandshake,
    Home,
    Layers,
    MessageSquare,
    Pencil,
    Share2,
    Users,
    UserX,
} from 'lucide-react';
import { useCallback, useEffect, useState } from 'react';

type PortalClient = {
    id: number;
    first_name: string;
    last_name: string;
    portal_enabled: boolean;
    notifications: {
        shift_updates: boolean;
        respite: boolean;
        care_notes: boolean;
        incident_alerts: boolean;
    };
    family_contacts_count: number;
};

type Props = {
    clients: {
        data: PortalClient[];
        links: { url: string | null; label: string; active: boolean }[];
        current_page: number;
        last_page: number;
        total: number;
    };
    filters?: {
        q?: string | null;
        portal?: string | null;
        sharing?: string | null;
    };
    stats?: {
        total: number;
        enabled: number;
        family_contacts: number;
    };
};

type ViewKey = 'all' | 'active' | 'inactive';

const NOTIFICATION_LABELS: Record<
    string,
    { label: string; icon: typeof Bell }
> = {
    shift_updates: { label: 'Shift schedule', icon: CalendarDays },
    respite: { label: 'Respite', icon: BedDouble },
    care_notes: { label: 'Care notes', icon: MessageSquare },
    incident_alerts: { label: 'Incident alerts', icon: Bell },
};

const SHARING_OPTIONS = [
    { value: 'all', label: 'Sharing anything' },
    { value: 'shift_schedule', label: 'Shift schedule' },
    { value: 'respite', label: 'Respite' },
    { value: 'care_notes', label: 'Care notes' },
    { value: 'incidents', label: 'Incident alerts' },
];

export default function FamilyPortalIndex({
    clients = { data: [], links: [], current_page: 1, last_page: 1, total: 0 },
    filters = {},
    stats,
}: Props) {
    const { labels } = usePage().props as any;
    const clientPlural: string = labels?.['client.plural'] ?? 'Clients';

    const s = stats ?? { total: 0, enabled: 0, family_contacts: 0 };

    const view: ViewKey =
        filters.portal === 'active'
            ? 'active'
            : filters.portal === 'inactive'
              ? 'inactive'
              : 'all';

    const [q, setQ] = useState(filters.q ?? '');

    const applyFilters = useCallback(
        (overrides: { view?: ViewKey; q?: string; sharing?: string }) => {
            const nextView = overrides.view ?? view;
            const nextQ = overrides.q ?? filters.q ?? '';
            const nextSharing = overrides.sharing ?? filters.sharing ?? 'all';
            const params: Record<string, string> = {};
            if (nextView !== 'all') params.portal = nextView;
            if (nextQ.trim() !== '') params.q = nextQ.trim();
            if (nextSharing !== 'all') params.sharing = nextSharing;
            router.get('/operations/family-portal', params, {
                preserveState: true,
                replace: true,
            });
        },
        [view, filters.q, filters.sharing],
    );

    useEffect(() => {
        if ((filters.q ?? '') === q.trim()) return;
        const t = setTimeout(() => applyFilters({ q }), 400);
        return () => clearTimeout(t);
    }, [q, filters.q, applyFilters]);

    const inactiveCount = Math.max(0, s.total - s.enabled);

    const railItems: PageHeaderRailItem<ViewKey>[] = [
        {
            key: 'all',
            label: `All ${clientPlural.toLowerCase()}`,
            icon: Layers,
            count: s.total,
        },
        {
            key: 'active',
            label: 'Portal active',
            icon: Home,
            count: s.enabled,
        },
        {
            key: 'inactive',
            label: 'Portal inactive',
            icon: UserX,
            count: inactiveCount,
        },
    ];

    const currentViewLabel =
        railItems.find((v) => v.key === view)?.label ??
        `All ${clientPlural.toLowerCase()}`;

    const hasNarrowing =
        (filters.q ?? '') !== '' || (filters.sharing ?? 'all') !== 'all';

    const header = (
        <PageHeader
            icon={Home}
            title="Family portal"
            titleChip={
                s.enabled > 0 ? (
                    <PageHeaderStatusChip variant="success">
                        {s.enabled} active
                    </PageHeaderStatusChip>
                ) : (
                    <PageHeaderStatusChip variant="neutral">
                        None active
                    </PageHeaderStatusChip>
                )
            }
            subline={`Portal access and sharing settings · ${s.total} ${clientPlural.toLowerCase()} · ${
                s.family_contacts
            } family ${s.family_contacts === 1 ? 'contact' : 'contacts'}`}
            actions={
                <PageHeaderSearch
                    value={q}
                    onChange={setQ}
                    placeholder={`Search ${clientPlural.toLowerCase()}…`}
                />
            }
            meters={
                <>
                    <PageHeaderMeterBlock
                        label={clientPlural}
                        ariaLabel={`View all ${clientPlural.toLowerCase()}`}
                        onClick={() => applyFilters({ view: 'all' })}
                    >
                        <PageHeaderMeterBig>{s.total}</PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            with portal settings to manage
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                    {s.total > 0 ? (
                        <PageHeaderMeterBlock
                            label="Portal active"
                            ariaLabel="View clients with an active portal"
                            onClick={() => applyFilters({ view: 'active' })}
                        >
                            <PageHeaderMeterDonut
                                percent={(s.enabled / s.total) * 100}
                                caption={
                                    <>
                                        {s.enabled} of {s.total}
                                        <br />
                                        enabled
                                    </>
                                }
                            />
                        </PageHeaderMeterBlock>
                    ) : null}
                    <PageHeaderMeterBlock
                        label="Portal inactive"
                        ariaLabel="View clients without a portal"
                        onClick={() => applyFilters({ view: 'inactive' })}
                    >
                        <PageHeaderMeterBig>{inactiveCount}</PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            not yet set up
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                    <PageHeaderMeterBlock
                        label="Family contacts"
                        ariaLabel="View family contacts by client"
                        onClick={() => applyFilters({ view: 'all' })}
                    >
                        <PageHeaderMeterBig>
                            {s.family_contacts}
                        </PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            next-of-kin logins linked
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                </>
            }
            filters={
                <PageHeaderFilterSelect
                    icon={Share2}
                    label="Sharing anything"
                    value={filters.sharing ?? 'all'}
                    options={SHARING_OPTIONS}
                    onChange={(v) => applyFilters({ sharing: v })}
                />
            }
            rail={
                <PageHeaderRail
                    items={railItems}
                    value={view}
                    onSelect={(key) => applyFilters({ view: key })}
                    ariaLabel="Portal views"
                />
            }
        />
    );

    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Home', href: '/dashboard' },
                { title: 'Operations', href: '/operations' },
                {
                    title: 'Family portal',
                    href: '/operations/family-portal',
                },
            ]}
        >
            <Head title="Family portal" />

            <PageLayout hero={header}>
                <div className="flex flex-col gap-5">
                    <ListCaption
                        title={currentViewLabel}
                        caption={`${clients.data.length} of ${
                            view === 'active'
                                ? s.enabled
                                : view === 'inactive'
                                  ? inactiveCount
                                  : s.total
                        } shown`}
                    />

                    <div className="space-y-2">
                        {clients.data.length === 0 ? (
                            <EmptyState
                                icon={Users}
                                title={`No ${clientPlural.toLowerCase()} found`}
                                description={
                                    hasNarrowing || view !== 'all'
                                        ? 'Try a different view or clear your filters.'
                                        : `${clientPlural} portal settings will appear here.`
                                }
                            />
                        ) : (
                            clients.data.map((client) => (
                                <Card
                                    key={client.id}
                                    className="transition-all hover:border-border hover:shadow-sm"
                                >
                                    <CardContent className="p-4">
                                        <div className="flex items-start gap-4">
                                            <div className="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-primary/10 text-primary">
                                                <HeartHandshake className="h-5 w-5" />
                                            </div>
                                            <div className="min-w-0 flex-1">
                                                <div className="flex flex-wrap items-center gap-2">
                                                    <Link
                                                        href={`/operations/family-portal/${client.id}`}
                                                        className="text-sm font-semibold hover:underline"
                                                    >
                                                        {client.first_name}{' '}
                                                        {client.last_name}
                                                    </Link>
                                                    <StatusBadge
                                                        variant={
                                                            client.portal_enabled
                                                                ? 'success'
                                                                : 'neutral'
                                                        }
                                                    >
                                                        {client.portal_enabled
                                                            ? 'Portal active'
                                                            : 'Portal inactive'}
                                                    </StatusBadge>
                                                    <span className="text-xs text-muted-foreground">
                                                        {
                                                            client.family_contacts_count
                                                        }{' '}
                                                        family contact
                                                        {client.family_contacts_count !==
                                                        1
                                                            ? 's'
                                                            : ''}
                                                    </span>
                                                </div>
                                                <div className="mt-2 flex flex-wrap gap-1.5">
                                                    {Object.entries(
                                                        NOTIFICATION_LABELS,
                                                    ).map(
                                                        ([
                                                            key,
                                                            {
                                                                label,
                                                                icon: Icon,
                                                            },
                                                        ]) => {
                                                            const enabled =
                                                                client
                                                                    .notifications[
                                                                    key as keyof typeof client.notifications
                                                                ];
                                                            return (
                                                                <Badge
                                                                    key={key}
                                                                    variant={
                                                                        enabled
                                                                            ? 'default'
                                                                            : 'outline'
                                                                    }
                                                                    className={`h-5 gap-1 px-2 text-[9px] ${!enabled ? 'opacity-40' : ''}`}
                                                                >
                                                                    <Icon className="h-2.5 w-2.5" />
                                                                    {label}
                                                                </Badge>
                                                            );
                                                        },
                                                    )}
                                                </div>
                                            </div>
                                            <div className="flex shrink-0 gap-1">
                                                <Button
                                                    asChild
                                                    size="sm"
                                                    variant="ghost"
                                                    className="h-7 w-7 p-0"
                                                >
                                                    <Link
                                                        href={`/operations/family-portal/${client.id}`}
                                                        aria-label={`View ${client.first_name} ${client.last_name}`}
                                                    >
                                                        <Eye className="h-3.5 w-3.5" />
                                                    </Link>
                                                </Button>
                                                <Button
                                                    asChild
                                                    size="sm"
                                                    variant="ghost"
                                                    className="h-7 w-7 p-0"
                                                >
                                                    <Link
                                                        href={`/operations/family-portal/${client.id}/edit`}
                                                        aria-label={`Edit ${client.first_name} ${client.last_name}`}
                                                    >
                                                        <Pencil className="h-3.5 w-3.5" />
                                                    </Link>
                                                </Button>
                                            </div>
                                        </div>
                                    </CardContent>
                                </Card>
                            ))
                        )}
                    </div>

                    {(clients.last_page ?? 1) > 1 && (
                        <div className="flex items-center justify-center gap-1">
                            {(clients.links ?? []).map((link, i) => (
                                <Button
                                    key={i}
                                    size="sm"
                                    variant={
                                        link.active ? 'default' : 'outline'
                                    }
                                    className="h-7 min-w-[28px] px-2 text-xs"
                                    disabled={!link.url}
                                    onClick={() =>
                                        link.url &&
                                        router.get(
                                            link.url,
                                            {},
                                            { preserveState: true },
                                        )
                                    }
                                    dangerouslySetInnerHTML={{
                                        __html: link.label,
                                    }}
                                />
                            ))}
                        </div>
                    )}
                </div>
            </PageLayout>
        </AppLayout>
    );
}
