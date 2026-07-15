import { CommandCentrePage } from '@/components/command-centre/command-centre-page';
import { BroadcastWizard } from '@/components/control-room/broadcast-wizard';
import PageShell from '@/components/page-shell';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import AppLayout from '@/layouts/app-layout';
import { formatDateTime } from '@/lib/datetime';
import { Head, router } from '@inertiajs/react';
import { CheckCircle, Megaphone, Users, XCircle } from 'lucide-react';
import { useState } from 'react';

// --- TypeScript Interfaces ---

interface BroadcastInitiator {
    id: number;
    name: string;
}

interface BroadcastGroup {
    broadcast_group_id: string;
    content: string;
    channels: string[];
    sent_at: string | null;
    template_used: string | null;
    total_recipients: number;
    delivered_count: number;
    failed_count: number;
    initiated_by: BroadcastInitiator | null;
}

interface PaginationLink {
    url: string | null;
    label: string;
    active: boolean;
}

interface PaginatedBroadcasts {
    data: BroadcastGroup[];
    links: PaginationLink[];
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
}

interface Props {
    broadcasts: PaginatedBroadcasts;
    roles: string[];
    roleCounts: Record<string, number>;
    totalStaff: number;
    can: {
        manage: boolean;
    };
}

const CHANNEL_LABELS: Record<string, string> = {
    in_app: 'In-App',
    push: 'Push Notification',
    sms: 'SMS',
    email: 'Email',
};

function channelBadgeVariant(
    channel: string,
): 'default' | 'secondary' | 'outline' | 'destructive' {
    switch (channel) {
        case 'sms':
            return 'default';
        case 'email':
            return 'secondary';
        case 'push':
            return 'outline';
        default:
            return 'outline';
    }
}

function statusBadge(total: number, delivered: number, failed: number) {
    if (failed > 0 && delivered === 0) {
        return <Badge variant="destructive">Failed</Badge>;
    }
    if (failed > 0) {
        return <Badge variant="destructive">Partial Failure</Badge>;
    }
    if (delivered === total) {
        return (
            <Badge className="bg-status-success-bg text-status-success-foreground hover:bg-status-success/90">
                Delivered
            </Badge>
        );
    }
    return <Badge variant="secondary">Sending</Badge>;
}

export default function ControlRoomBroadcast({
    broadcasts,
    roles,
    roleCounts,
    totalStaff,
    can,
}: Props) {
    // ?new=1 deep-links straight into the wizard (house pattern).
    const [composerOpen, setComposerOpen] = useState<boolean>(
        () =>
            typeof window !== 'undefined' &&
            new URLSearchParams(window.location.search).get('new') === '1',
    );

    const handlePageChange = (url: string | null) => {
        if (url) {
            router.get(url, {}, { preserveState: true });
        }
    };

    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Control Room', href: '/control-room' },
                { title: 'Broadcast Messages', href: '#' },
            ]}
        >
            <Head title="Broadcast Messages" />
            <PageShell>
                <CommandCentrePage
                    variant="compact"
                    current="/control-room/broadcast"
                    icon={Megaphone}
                    title="Broadcasts"
                    description="Send accountable urgent messages to the right staff across approved channels."
                    status="Broadcast workspace"
                    freshness={`${broadcasts.total} sent`}
                    actions={
                        can.manage ? (
                            <Button
                                variant="outline"
                                size="sm"
                                onClick={() => setComposerOpen(true)}
                                className="border-primary-foreground/30 bg-primary-foreground/10 text-primary-foreground backdrop-blur-sm hover:bg-primary-foreground/20 hover:text-primary-foreground"
                            >
                                <Megaphone className="mr-2 h-4 w-4" />
                                New broadcast
                            </Button>
                        ) : undefined
                    }
                >
                    {/* Broadcast History */}
                    <Card>
                        <CardHeader>
                            <CardTitle>Broadcast History</CardTitle>
                            <CardDescription>
                                Previously sent broadcast messages and their
                                delivery status.
                            </CardDescription>
                        </CardHeader>
                        <CardContent>
                            {broadcasts.data.length === 0 ? (
                                <div className="flex flex-col items-center justify-center py-12 text-center text-muted-foreground">
                                    <Megaphone className="mb-3 h-10 w-10 opacity-40" />
                                    <p className="text-sm">
                                        No broadcasts sent yet.
                                    </p>
                                    {can.manage ? (
                                        <Button
                                            size="sm"
                                            className="mt-4"
                                            onClick={() =>
                                                setComposerOpen(true)
                                            }
                                        >
                                            <Megaphone className="mr-2 h-4 w-4" />
                                            Send your first broadcast
                                        </Button>
                                    ) : null}
                                </div>
                            ) : (
                                <>
                                    <div className="overflow-x-auto">
                                        <table className="w-full text-sm">
                                            <thead>
                                                <tr className="border-b text-left">
                                                    <th className="pr-4 pb-3 font-medium">
                                                        Message
                                                    </th>
                                                    <th className="pr-4 pb-3 font-medium">
                                                        Channels
                                                    </th>
                                                    <th className="pr-4 pb-3 font-medium">
                                                        Sent At
                                                    </th>
                                                    <th className="pr-4 pb-3 text-right font-medium">
                                                        Recipients
                                                    </th>
                                                    <th className="pr-4 pb-3 text-right font-medium">
                                                        Delivered
                                                    </th>
                                                    <th className="pr-4 pb-3 text-right font-medium">
                                                        Failed
                                                    </th>
                                                    <th className="pb-3 font-medium">
                                                        Status
                                                    </th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                {broadcasts.data.map(
                                                    (broadcast) => (
                                                        <tr
                                                            key={
                                                                broadcast.broadcast_group_id
                                                            }
                                                            className="cursor-pointer border-b last:border-0 hover:bg-muted/50"
                                                            onClick={() =>
                                                                router.get(
                                                                    `/control-room/broadcast/${broadcast.broadcast_group_id}`,
                                                                )
                                                            }
                                                        >
                                                            <td className="max-w-xs truncate py-3 pr-4">
                                                                <div className="truncate font-medium">
                                                                    {broadcast.content?.slice(
                                                                        0,
                                                                        80,
                                                                    )}
                                                                    {(broadcast
                                                                        .content
                                                                        ?.length ??
                                                                        0) > 80
                                                                        ? '...'
                                                                        : ''}
                                                                </div>
                                                                {broadcast.initiated_by && (
                                                                    <div className="text-xs text-muted-foreground">
                                                                        by{' '}
                                                                        {
                                                                            broadcast
                                                                                .initiated_by
                                                                                .name
                                                                        }
                                                                    </div>
                                                                )}
                                                            </td>
                                                            <td className="py-3 pr-4">
                                                                <div className="flex flex-wrap gap-1">
                                                                    {broadcast.channels.map(
                                                                        (
                                                                            ch,
                                                                        ) => (
                                                                            <Badge
                                                                                key={
                                                                                    ch
                                                                                }
                                                                                variant={channelBadgeVariant(
                                                                                    ch,
                                                                                )}
                                                                                className="text-xs"
                                                                            >
                                                                                {CHANNEL_LABELS[
                                                                                    ch
                                                                                ] ??
                                                                                    ch}
                                                                            </Badge>
                                                                        ),
                                                                    )}
                                                                </div>
                                                            </td>
                                                            <td className="py-3 pr-4 whitespace-nowrap text-muted-foreground">
                                                                {formatDateTime(
                                                                    broadcast.sent_at,
                                                                )}
                                                            </td>
                                                            <td className="py-3 pr-4 text-right">
                                                                <span className="flex items-center justify-end gap-1">
                                                                    <Users className="h-3.5 w-3.5 text-muted-foreground" />
                                                                    {
                                                                        broadcast.total_recipients
                                                                    }
                                                                </span>
                                                            </td>
                                                            <td className="py-3 pr-4 text-right">
                                                                <span className="flex items-center justify-end gap-1 text-status-success">
                                                                    <CheckCircle className="h-3.5 w-3.5" />
                                                                    {
                                                                        broadcast.delivered_count
                                                                    }
                                                                </span>
                                                            </td>
                                                            <td className="py-3 pr-4 text-right">
                                                                <span
                                                                    className={`flex items-center justify-end gap-1 ${broadcast.failed_count > 0 ? 'text-status-critical' : 'text-muted-foreground'}`}
                                                                >
                                                                    <XCircle className="h-3.5 w-3.5" />
                                                                    {
                                                                        broadcast.failed_count
                                                                    }
                                                                </span>
                                                            </td>
                                                            <td className="py-3">
                                                                {statusBadge(
                                                                    broadcast.total_recipients,
                                                                    broadcast.delivered_count,
                                                                    broadcast.failed_count,
                                                                )}
                                                            </td>
                                                        </tr>
                                                    ),
                                                )}
                                            </tbody>
                                        </table>
                                    </div>

                                    {/* Pagination */}
                                    {broadcasts.last_page > 1 && (
                                        <div className="mt-4 flex items-center justify-between border-t pt-4">
                                            <p className="text-sm text-muted-foreground">
                                                Page {broadcasts.current_page}{' '}
                                                of {broadcasts.last_page} (
                                                {broadcasts.total} total)
                                            </p>
                                            <div className="flex gap-1">
                                                {broadcasts.links.map(
                                                    (link, idx) => (
                                                        <Button
                                                            key={idx}
                                                            variant={
                                                                link.active
                                                                    ? 'default'
                                                                    : 'outline'
                                                            }
                                                            size="sm"
                                                            disabled={!link.url}
                                                            onClick={() =>
                                                                handlePageChange(
                                                                    link.url,
                                                                )
                                                            }
                                                            dangerouslySetInnerHTML={{
                                                                __html: link.label,
                                                            }}
                                                        />
                                                    ),
                                                )}
                                            </div>
                                        </div>
                                    )}
                                </>
                            )}
                        </CardContent>
                    </Card>
                </CommandCentrePage>
            </PageShell>

            {/* Guided composer — message → audience → channels → review & send.
                Mounted only while open so every run starts fresh. */}
            {can.manage && composerOpen ? (
                <BroadcastWizard
                    open
                    onClose={() => setComposerOpen(false)}
                    roles={roles}
                    roleCounts={roleCounts}
                    totalStaff={totalStaff}
                />
            ) : null}
        </AppLayout>
    );
}
