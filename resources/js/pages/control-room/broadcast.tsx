import {
    BROADCAST_TEMPLATE_LABELS,
    BroadcastWizard,
    broadcastDeliveryState,
    channelList,
} from '@/components/control-room/broadcast-wizard';
import { ControlRoomPage } from '@/components/control-room/control-room-page';
import {
    ControlRoomRowActions,
    type ControlRoomRowAction,
} from '@/components/control-room/control-room-row-actions';
import { PageHeaderPrimaryButton } from '@/components/page';
import { Card } from '@/components/ui/card';
import { EmptyState } from '@/components/ui/empty-state';
import { LaravelPagination } from '@/components/ui/laravel-pagination';
import { StatusBadge } from '@/components/ui/status-badge';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { formatDateTime, formatRelative } from '@/lib/datetime';
import { Link, router } from '@inertiajs/react';
import { ArrowUpRight, Copy, Megaphone, Plus } from 'lucide-react';
import { useState } from 'react';

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
    from?: number | null;
    to?: number | null;
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

const BASE_PATH = '/control-room/broadcast';

/**
 * Communications › Broadcasts — urgent messages sent to staff, newest first,
 * with what each channel confirmed. "New broadcast" opens the four-step
 * wizard with its review step.
 */
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

    return (
        <ControlRoomPage
            headTitle="Broadcasts"
            header={{
                subline:
                    'Urgent messages to staff, with what was delivered on each channel',
                actions: can.manage ? (
                    <PageHeaderPrimaryButton
                        icon={Plus}
                        onClick={() => setComposerOpen(true)}
                    >
                        New broadcast
                    </PageHeaderPrimaryButton>
                ) : undefined,
            }}
        >
            <Card className="min-w-0 gap-0 overflow-hidden py-0">
                <div className="flex flex-wrap items-start justify-between gap-3 border-b border-border px-5 py-4">
                    <div className="min-w-0">
                        <h2 className="text-section-title">Broadcasts</h2>
                        <p className="text-subtle mt-0.5">
                            Delivered means the channel confirmed it. It doesn&apos;t
                            mean the person has read it.
                        </p>
                    </div>
                    <StatusBadge variant="neutral" className="tabular-nums">
                        {broadcasts.total}{' '}
                        {broadcasts.total === 1 ? 'broadcast' : 'broadcasts'}
                    </StatusBadge>
                </div>

                {broadcasts.data.length === 0 ? (
                    <div className="px-5 py-10">
                        <EmptyState
                            icon={Megaphone}
                            title="No broadcasts yet"
                            description="Broadcasts sent to staff show here with what was delivered."
                        />
                    </div>
                ) : (
                    // Table owns its overflow-x-auto container.
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead className="pl-5">Message</TableHead>
                                <TableHead>Audience</TableHead>
                                <TableHead>Sent</TableHead>
                                <TableHead className="text-right">Delivered</TableHead>
                                <TableHead className="text-right">Not delivered</TableHead>
                                <TableHead>Delivery</TableHead>
                                <TableHead className="w-12 pr-5">
                                    <span className="sr-only">Actions</span>
                                </TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {broadcasts.data.map((broadcast) => (
                                <BroadcastRow
                                    key={broadcast.broadcast_group_id}
                                    broadcast={broadcast}
                                />
                            ))}
                        </TableBody>
                    </Table>
                )}

                {broadcasts.last_page > 1 ? (
                    <div className="flex flex-wrap items-center justify-between gap-3 border-t border-border px-5 py-3">
                        <p className="text-caption">
                            Page {broadcasts.current_page} of {broadcasts.last_page} ·{' '}
                            {broadcasts.total} broadcasts
                        </p>
                        <div className="max-w-full min-w-0 overflow-x-auto scrollbar-pretty">
                            <div className="w-max">
                                <LaravelPagination
                                    links={broadcasts.links}
                                    lastPage={broadcasts.last_page}
                                />
                            </div>
                        </div>
                    </div>
                ) : null}
            </Card>

            {/* Mounted only while open so every run starts fresh. */}
            {can.manage && composerOpen ? (
                <BroadcastWizard
                    open
                    onClose={() => setComposerOpen(false)}
                    roles={roles}
                    roleCounts={roleCounts}
                    totalStaff={totalStaff}
                />
            ) : null}
        </ControlRoomPage>
    );
}

function BroadcastRow({ broadcast }: { broadcast: BroadcastGroup }) {
    const href = `${BASE_PATH}/${broadcast.broadcast_group_id}`;
    const total = broadcast.total_recipients;
    const channelCount = Math.max(1, broadcast.channels.length);
    // One record per person per channel, so people = sends ÷ channels.
    const people = Math.round(total / channelCount);
    const state = broadcastDeliveryState(
        total,
        broadcast.delivered_count,
        broadcast.failed_count,
    );
    const template =
        broadcast.template_used && broadcast.template_used !== 'custom'
            ? BROADCAST_TEMPLATE_LABELS[broadcast.template_used] ?? null
            : null;
    const byline = [
        broadcast.initiated_by ? `By ${broadcast.initiated_by.name}` : null,
        template ? `${template} template` : null,
    ]
        .filter(Boolean)
        .join(' · ');
    const items: ControlRoomRowAction[] = [
        {
            key: 'open',
            label: 'Open broadcast',
            icon: ArrowUpRight,
            onSelect: () => router.visit(href),
        },
        {
            key: 'copy',
            label: 'Copy message',
            icon: Copy,
            onSelect: () => void navigator.clipboard?.writeText(broadcast.content),
        },
    ];

    return (
        <ControlRoomRowActions label="Actions for this broadcast" items={items}>
            {({ rowProps, overflowButton }) => (
                <TableRow
                    onContextMenu={rowProps.onContextMenu}
                    onClick={(event) => {
                        if ((event.target as HTMLElement).closest('a,button')) return;
                        router.visit(href);
                    }}
                    className="cursor-pointer"
                >
                    <TableCell className="max-w-[34rem] min-w-[16rem] pl-5 whitespace-normal">
                        <Link
                            href={href}
                            title={broadcast.content}
                            className="line-clamp-2 font-semibold text-foreground underline-offset-2 hover:underline focus-visible:rounded-sm focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                        >
                            {broadcast.content}
                        </Link>
                        {byline ? <span className="text-caption block">{byline}</span> : null}
                    </TableCell>
                    <TableCell className="whitespace-normal">
                        <span className="block">
                            {people} {people === 1 ? 'person' : 'people'}
                        </span>
                        {broadcast.channels.length ? (
                            <span className="text-caption block">
                                {channelList(broadcast.channels)}
                            </span>
                        ) : null}
                    </TableCell>
                    <TableCell>
                        {broadcast.sent_at ? (
                            <>
                                <span className="block">{formatRelative(broadcast.sent_at)}</span>
                                <span className="text-caption block tabular-nums">
                                    {formatDateTime(broadcast.sent_at)}
                                </span>
                            </>
                        ) : null}
                    </TableCell>
                    <TableCell className="text-right tabular-nums">
                        {broadcast.delivered_count} of {total}
                    </TableCell>
                    <TableCell
                        className={
                            broadcast.failed_count > 0
                                ? 'text-right font-semibold text-status-critical tabular-nums'
                                : 'text-right text-muted-foreground tabular-nums'
                        }
                    >
                        {broadcast.failed_count}
                    </TableCell>
                    <TableCell>
                        <StatusBadge variant={state.tone} size="sm">
                            {state.label}
                        </StatusBadge>
                    </TableCell>
                    <TableCell className="pr-5 text-right">{overflowButton}</TableCell>
                </TableRow>
            )}
        </ControlRoomRowActions>
    );
}
