import { ListCaption } from '@/components/lists';
import {
    PageHeader,
    PageHeaderFilterSelect,
    PageHeaderMeterAvatars,
    PageHeaderMeterBig,
    PageHeaderMeterBlock,
    PageHeaderMeterCaption,
    PageHeaderPrimaryButton,
    PageHeaderRail,
    type PageHeaderRailItem,
    PageHeaderSearch,
    PageHeaderStatusChip,
    PageLayout,
} from '@/components/page';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { EmptyState } from '@/components/ui/empty-state';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import { Head, useForm, usePage } from '@inertiajs/react';
import {
    HeartHandshake,
    Layers,
    Link2,
    Plus,
    UserRound,
    Users,
} from 'lucide-react';
import { useMemo, useState } from 'react';

type PortalUser = {
    id: number;
    name: string;
    email: string;
    relation: string;
};

type Props = {
    client: { id: number; first_name: string; last_name: string };
    portal_users: Array<PortalUser>;
    relation_options: string[];
};

type ViewKey = 'all' | 'family' | 'client_access';

function relationLabel(relation: string): string {
    return relation.replace(/_/g, ' ').replace(/^\w/, (m) => m.toUpperCase());
}

export default function ClientPortalUsers({
    client,
    portal_users,
    relation_options,
}: Props) {
    const { labels } = usePage().props as any;
    const clientSingular: string = labels?.['client.singular'] ?? 'Client';
    const form = useForm({
        email: '',
        name: '',
        relation: 'mother',
        portal_role: 'next_of_kin',
        action: 'link',
    });

    const [showForm, setShowForm] = useState(false);
    const [view, setView] = useState<ViewKey>('all');
    const [search, setSearch] = useState('');
    const [relationFilter, setRelationFilter] = useState('all');

    const submit = (e: React.FormEvent) => {
        e.preventDefault();
        form.setData('action', 'link');
        form.post(`/operations/clients/${client.id}/portal-users`, {
            preserveScroll: true,
        });
    };

    const userNotFound = (form.errors.email || '')
        .toLowerCase()
        .includes('no user found');

    const createUser = () => {
        form.setData('action', 'create_user');
        form.post(`/operations/clients/${client.id}/portal-users`, {
            preserveScroll: true,
        });
    };

    const saveContactOnly = () => {
        form.setData('action', 'contact_only');
        form.post(`/operations/clients/${client.id}/portal-users`, {
            preserveScroll: true,
        });
    };

    const name = `${client.first_name} ${client.last_name}`.trim();

    const familyCount = portal_users.filter(
        (u) => u.relation !== 'client',
    ).length;
    const clientAccessCount = portal_users.length - familyCount;

    const shown = useMemo(() => {
        const q = search.trim().toLowerCase();
        return portal_users.filter((u) => {
            if (view === 'family' && u.relation === 'client') return false;
            if (view === 'client_access' && u.relation !== 'client')
                return false;
            if (relationFilter !== 'all' && u.relation !== relationFilter)
                return false;
            if (q) {
                const hay = `${u.name} ${u.email} ${u.relation}`.toLowerCase();
                if (!hay.includes(q)) return false;
            }
            return true;
        });
    }, [portal_users, view, search, relationFilter]);

    const hasNarrowing = search.trim() !== '' || relationFilter !== 'all';

    const relationFilterOptions = useMemo(
        () => [
            { value: 'all', label: 'All relations' },
            ...Array.from(new Set(portal_users.map((u) => u.relation))).map(
                (r) => ({ value: r, label: relationLabel(r) }),
            ),
        ],
        [portal_users],
    );

    const railItems: PageHeaderRailItem<ViewKey>[] = [
        {
            key: 'all',
            label: 'All linked users',
            icon: Layers,
            count: portal_users.length,
        },
        {
            key: 'family',
            label: 'Next of kin',
            icon: HeartHandshake,
            count: familyCount,
        },
        {
            key: 'client_access',
            label: `${clientSingular} access`,
            icon: UserRound,
            count: clientAccessCount,
        },
    ];

    const currentViewLabel =
        railItems.find((v) => v.key === view)?.label ?? 'All linked users';

    const header = (
        <PageHeader
            variant="profile"
            backHref={`/operations/clients/${client.id}`}
            icon={Users}
            title={name}
            titleChip={
                portal_users.length > 0 ? (
                    <PageHeaderStatusChip variant="success">
                        {portal_users.length} linked
                    </PageHeaderStatusChip>
                ) : (
                    <PageHeaderStatusChip variant="neutral">
                        None linked
                    </PageHeaderStatusChip>
                )
            }
            subline={`Family portal access · ${portal_users.length} linked ${
                portal_users.length === 1 ? 'user' : 'users'
            } · ${familyCount} next of kin`}
            actions={
                <>
                    <PageHeaderSearch
                        value={search}
                        onChange={setSearch}
                        placeholder="Search linked users…"
                    />
                    <PageHeaderPrimaryButton
                        icon={Plus}
                        onClick={() => setShowForm((v) => !v)}
                    >
                        {showForm ? 'Cancel' : 'Link user'}
                    </PageHeaderPrimaryButton>
                </>
            }
            meters={
                <>
                    <PageHeaderMeterBlock
                        label="Portal users"
                        value={portal_users.length}
                        ariaLabel="View all linked portal users"
                        onClick={() => setView('all')}
                    >
                        {portal_users.length > 0 ? (
                            <PageHeaderMeterAvatars
                                people={portal_users.slice(0, 6).map((u) => ({
                                    id: u.id,
                                    name: u.name,
                                    detail: relationLabel(u.relation),
                                }))}
                                overflow={Math.max(0, portal_users.length - 6)}
                            />
                        ) : (
                            <PageHeaderMeterBig>0</PageHeaderMeterBig>
                        )}
                        <PageHeaderMeterCaption>
                            with portal access
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                    <PageHeaderMeterBlock
                        label="Next of kin"
                        ariaLabel="View next-of-kin portal users"
                        onClick={() => setView('family')}
                    >
                        <PageHeaderMeterBig>{familyCount}</PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            family links
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                    <PageHeaderMeterBlock
                        label={`${clientSingular} access`}
                        ariaLabel={`View the ${clientSingular.toLowerCase()}'s own portal login`}
                        onClick={() => setView('client_access')}
                    >
                        <PageHeaderMeterBig>
                            {clientAccessCount}
                        </PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            {clientAccessCount === 1
                                ? 'own login linked'
                                : 'own logins linked'}
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                </>
            }
            filters={
                <PageHeaderFilterSelect
                    icon={Link2}
                    label="All relations"
                    value={relationFilter}
                    options={relationFilterOptions}
                    onChange={setRelationFilter}
                />
            }
            rail={
                <PageHeaderRail
                    items={railItems}
                    value={view}
                    onSelect={setView}
                    ariaLabel="Portal user views"
                />
            }
        />
    );

    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Home', href: '/dashboard' },
                {
                    title: labels?.['client.plural'] ?? 'Clients',
                    href: '/operations/clients',
                },
                { title: name, href: `/operations/clients/${client.id}` },
                {
                    title: 'Portal users',
                    href: `/operations/clients/${client.id}/portal-users`,
                },
            ]}
        >
            <Head title={`Portal users · ${name}`} />

            <PageLayout hero={header}>
                <div className="flex flex-col gap-5">
                    {showForm && (
                        <Card>
                            <CardHeader>
                                <CardTitle className="text-base">
                                    Link a portal user
                                </CardTitle>
                            </CardHeader>
                            <CardContent>
                                <form
                                    onSubmit={submit}
                                    className="grid grid-cols-1 gap-3 md:grid-cols-3"
                                >
                                    <div className="md:col-span-2">
                                        <Label htmlFor="name">
                                            Name (for new users)
                                        </Label>
                                        <Input
                                            id="name"
                                            value={form.data.name}
                                            onChange={(e) =>
                                                form.setData(
                                                    'name',
                                                    e.target.value,
                                                )
                                            }
                                            placeholder="Jane Smith"
                                        />
                                        {form.errors.name && (
                                            <div className="mt-1 text-xs text-status-critical">
                                                {form.errors.name}
                                            </div>
                                        )}
                                    </div>

                                    <div className="md:col-span-2">
                                        <Label htmlFor="email">Email</Label>
                                        <Input
                                            id="email"
                                            value={form.data.email}
                                            onChange={(e) =>
                                                form.setData(
                                                    'email',
                                                    e.target.value,
                                                )
                                            }
                                            placeholder="user@example.com"
                                        />
                                        {form.errors.email && (
                                            <div className="mt-1 text-xs text-status-critical">
                                                {form.errors.email}
                                            </div>
                                        )}
                                    </div>

                                    <div>
                                        <Label htmlFor="portal_role">
                                            Portal role
                                        </Label>
                                        <select
                                            id="portal_role"
                                            className="mt-2 w-full rounded-md border bg-background px-3 py-2 text-sm"
                                            value={form.data.portal_role}
                                            onChange={(e) =>
                                                form.setData(
                                                    'portal_role',
                                                    e.target.value,
                                                )
                                            }
                                        >
                                            <option value="client">
                                                {clientSingular}
                                            </option>
                                            <option value="next_of_kin">
                                                Next of kin
                                            </option>
                                        </select>
                                    </div>

                                    <div>
                                        <Label htmlFor="relation">
                                            Relation
                                        </Label>
                                        <select
                                            id="relation"
                                            className="mt-2 w-full rounded-md border bg-background px-3 py-2 text-sm"
                                            value={form.data.relation}
                                            onChange={(e) =>
                                                form.setData(
                                                    'relation',
                                                    e.target.value,
                                                )
                                            }
                                        >
                                            {relation_options.map((r) => (
                                                <option key={r} value={r}>
                                                    {r.replace(/_/g, ' ')}
                                                </option>
                                            ))}
                                        </select>
                                    </div>

                                    <div className="md:col-span-3">
                                        <Button
                                            type="submit"
                                            disabled={
                                                form.processing ||
                                                !form.data.email.trim()
                                            }
                                        >
                                            Link user
                                        </Button>
                                    </div>

                                    {userNotFound && (
                                        <div className="rounded-md border border-status-warning/30 bg-status-warning-bg p-3 text-sm md:col-span-3">
                                            <div className="font-medium text-status-warning">
                                                User not found for this email.
                                            </div>
                                            <div className="mt-1 text-status-warning">
                                                Do you want to create a user for
                                                this person?
                                            </div>
                                            <div className="mt-3 flex flex-wrap gap-2">
                                                <Button
                                                    type="button"
                                                    onClick={createUser}
                                                    disabled={form.processing}
                                                >
                                                    Yes - create user and send
                                                    password setup email
                                                </Button>
                                                <Button
                                                    type="button"
                                                    variant="secondary"
                                                    onClick={saveContactOnly}
                                                    disabled={form.processing}
                                                >
                                                    No - save as contact/display
                                                    only
                                                </Button>
                                            </div>
                                        </div>
                                    )}
                                </form>
                            </CardContent>
                        </Card>
                    )}

                    <ListCaption
                        title={currentViewLabel}
                        caption={`${shown.length} of ${portal_users.length} ${
                            portal_users.length === 1 ? 'user' : 'users'
                        } shown`}
                    />

                    <div className="space-y-2">
                        {shown.map((u) => (
                            <div
                                key={u.id}
                                className="flex items-center justify-between gap-3 rounded-md border p-3"
                            >
                                <div>
                                    <div className="text-sm font-medium">
                                        {u.name}
                                    </div>
                                    <div className="text-xs text-muted-foreground">
                                        {u.email} · {relationLabel(u.relation)}
                                    </div>
                                </div>
                                <Button
                                    variant="destructive"
                                    onClick={() =>
                                        form.delete(
                                            `/operations/clients/${client.id}/portal-users/${u.id}`,
                                            { preserveScroll: true },
                                        )
                                    }
                                >
                                    Unlink
                                </Button>
                            </div>
                        ))}
                        {!shown.length && (
                            <EmptyState
                                icon={Users}
                                title="No portal users"
                                description={
                                    hasNarrowing || view !== 'all'
                                        ? 'No linked users match this view or your filters.'
                                        : 'No portal users linked yet — use "Link user" to add one.'
                                }
                            />
                        )}
                    </div>
                </div>
            </PageLayout>
        </AppLayout>
    );
}
