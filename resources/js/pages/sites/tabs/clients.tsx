import {
    AddClientDialog,
    type AddClientDialogProps,
} from '@/components/clients/add-client-dialog';
import { ConfirmDialog } from '@/components/confirm-dialog';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Link, router } from '@inertiajs/react';
import {
    ExternalLink,
    Link2,
    Plus,
    ShieldAlert,
    UserRound,
} from 'lucide-react';
import { useState } from 'react';
import {
    LinkClientDialog,
    type ClientPlacementOptions,
    type PlacementClient,
} from '../clients/link-client-dialog';
import {
    SiteProfileEmptyState,
    SiteProfileLockedState,
} from './site-profile-states';

type ClientItem = {
    id: number;
    first_name: string;
    last_name: string;
    name: string;
    preferred_name?: string | null;
    status: string;
    profile_photo_url?: string | null;
    risk_level?: string | null;
    safeguarding_flag: boolean;
    service_start_date?: string | null;
    room?: { id: number; name: string } | null;
    key_worker?: { id: number; name: string } | null;
    service_context?: { id: number; name: string; type?: string | null } | null;
    href: string;
};

type CreateOptions = {
    sites: AddClientDialogProps['sites'];
    serviceContexts: AddClientDialogProps['serviceContexts'];
    keyWorkers: AddClientDialogProps['keyWorkers'];
    geofences: AddClientDialogProps['geofences'];
    defaultServiceContextId?: number | null;
};

export type SiteClientsData = {
    locked: boolean;
    items: ClientItem[];
    summary?: {
        total: number;
        active: number;
        onboarding: number;
        high_risk: number;
        safeguarding: number;
    } | null;
    available: PlacementClient[];
    can_create: boolean;
    can_place_existing: boolean;
    create_options?: CreateOptions | null;
    placement_options?: ClientPlacementOptions | null;
};

export function SiteProfileClients({
    siteId,
    data,
}: {
    siteId: number;
    data: SiteClientsData;
}) {
    const [createOpen, setCreateOpen] = useState(false);
    const [placementOpen, setPlacementOpen] = useState(false);
    const [unlinkClient, setUnlinkClient] = useState<ClientItem | null>(null);

    if (data.locked) return <SiteProfileLockedState label="Clients" />;

    const createOptions = data.create_options;
    const placementOptions = data.placement_options;
    const refreshPeople = () =>
        router.reload({
            only: ['clientsData'],
            preserveState: true,
            preserveScroll: true,
        });

    return (
        <div className="space-y-5">
            <div className="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <h2 className="text-xl font-semibold">Clients</h2>
                    <p className="text-sm text-muted-foreground">
                        People currently placed at this Site.
                    </p>
                </div>
                <div className="flex flex-wrap gap-2">
                    {data.can_place_existing && placementOptions ? (
                        <Button
                            type="button"
                            variant="outline"
                            className="min-h-11"
                            onClick={() => setPlacementOpen(true)}
                        >
                            <Link2 className="mr-2 h-4 w-4" />
                            Link existing client
                        </Button>
                    ) : null}
                    {data.can_create && createOptions ? (
                        <Button
                            type="button"
                            className="min-h-11"
                            onClick={() => setCreateOpen(true)}
                        >
                            <Plus className="mr-2 h-4 w-4" />
                            Create client
                        </Button>
                    ) : null}
                </div>
            </div>

            {data.summary ? (
                <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-5">
                    {Object.entries(data.summary).map(([label, value]) => (
                        <Card key={label}>
                            <CardContent className="p-4">
                                <p className="text-xs font-medium tracking-wide text-muted-foreground uppercase">
                                    {label.replaceAll('_', ' ')}
                                </p>
                                <p className="mt-1 text-2xl font-bold tabular-nums">
                                    {value}
                                </p>
                            </CardContent>
                        </Card>
                    ))}
                </div>
            ) : null}

            {data.items.length ? (
                <Card>
                    <CardContent className="divide-y p-0">
                        {data.items.map((client) => (
                            <div
                                key={client.id}
                                className="flex min-h-16 flex-wrap items-center gap-3 px-4 py-3"
                            >
                                <Avatar className="h-10 w-10">
                                    {client.profile_photo_url ? (
                                        <AvatarImage
                                            src={client.profile_photo_url}
                                            alt=""
                                        />
                                    ) : null}
                                    <AvatarFallback>
                                        {initials(client.name)}
                                    </AvatarFallback>
                                </Avatar>
                                <div className="min-w-0 flex-1">
                                    <div className="flex flex-wrap items-center gap-2">
                                        <p className="truncate text-sm font-semibold">
                                            {client.preferred_name ||
                                                client.name}
                                        </p>
                                        <Badge variant="outline">
                                            {client.status}
                                        </Badge>
                                        {client.safeguarding_flag ? (
                                            <span className="inline-flex items-center gap-1 text-xs font-medium text-status-critical">
                                                <ShieldAlert className="h-3.5 w-3.5" />
                                                Safeguarding
                                            </span>
                                        ) : null}
                                    </div>
                                    <p className="mt-0.5 truncate text-xs text-muted-foreground">
                                        {[
                                            client.room?.name,
                                            client.service_context?.name,
                                            client.key_worker?.name
                                                ? `Key worker: ${client.key_worker.name}`
                                                : null,
                                        ]
                                            .filter(Boolean)
                                            .join(' · ') ||
                                            'Placement details not recorded'}
                                    </p>
                                </div>
                                <Button
                                    variant="ghost"
                                    size="sm"
                                    className="min-h-11"
                                    asChild
                                >
                                    <Link href={client.href}>
                                        Open profile
                                        <ExternalLink className="ml-2 h-4 w-4" />
                                    </Link>
                                </Button>
                                {data.can_place_existing ? (
                                    <Button
                                        type="button"
                                        variant="ghost"
                                        size="sm"
                                        className="min-h-11 text-status-critical"
                                        onClick={() => setUnlinkClient(client)}
                                    >
                                        Unlink
                                    </Button>
                                ) : null}
                            </div>
                        ))}
                    </CardContent>
                </Card>
            ) : (
                <SiteProfileEmptyState
                    icon={UserRound}
                    title="No clients placed here"
                    description="Create a full client profile or place an existing unassigned client at this Site."
                    action={
                        data.can_create && createOptions
                            ? {
                                  label: 'Create client',
                                  onClick: () => setCreateOpen(true),
                              }
                            : undefined
                    }
                />
            )}

            {createOptions ? (
                <AddClientDialog
                    isOpen={createOpen}
                    onClose={() => setCreateOpen(false)}
                    sites={createOptions.sites}
                    serviceContexts={createOptions.serviceContexts}
                    keyWorkers={createOptions.keyWorkers}
                    geofences={createOptions.geofences}
                    defaultServiceContextId={
                        createOptions.defaultServiceContextId
                    }
                    initialValues={{ site_id: String(siteId) }}
                    onSaved={refreshPeople}
                />
            ) : null}
            {placementOptions ? (
                <LinkClientDialog
                    siteId={siteId}
                    availableClients={data.available}
                    options={placementOptions}
                    isOpen={placementOpen}
                    onClose={() => setPlacementOpen(false)}
                    onPlaced={refreshPeople}
                />
            ) : null}
            <ConfirmDialog
                open={unlinkClient !== null}
                onClose={() => setUnlinkClient(null)}
                onConfirm={() => {
                    if (!unlinkClient) return;
                    router.post(
                        `/sites/${siteId}/clients/${unlinkClient.id}/unlink`,
                        {},
                        {
                            preserveScroll: true,
                            onSuccess: refreshPeople,
                        },
                    );
                }}
                title="Unlink client from this Site?"
                description={`${unlinkClient?.name ?? 'This client'} will lose their Site and room placement. Their profile, history, service context, and key worker remain intact.`}
                confirmText="Unlink client"
            />
        </div>
    );
}

function initials(name: string): string {
    return name
        .split(/\s+/)
        .filter(Boolean)
        .slice(0, 2)
        .map((part) => part[0]?.toUpperCase())
        .join('');
}
