import { ListCaption } from '@/components/lists';
import {
    PageHeader,
    PageHeaderFilterSelect,
    PageHeaderMeterBig,
    PageHeaderMeterBlock,
    PageHeaderMeterCaption,
    PageHeaderMeterDonut,
    PageHeaderPrimaryButton,
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
import AppLayout from '@/layouts/app-layout';
import { Head, router } from '@inertiajs/react';
import {
    AlertTriangle,
    Bell,
    BellOff,
    BellRing,
    CalendarDays,
    CheckCircle2,
    Info,
    Layers,
    Mail,
    MessageSquare,
} from 'lucide-react';
import { useCallback, useEffect, useState } from 'react';

type Notification = {
    id: number;
    title: string;
    body: string;
    type: string;
    is_read: boolean;
    created_at: string;
};

type Props = {
    notifications: {
        data: Notification[];
        links: { url: string | null; label: string; active: boolean }[];
        current_page: number;
        last_page: number;
        total: number;
    };
    filters?: {
        q?: string | null;
        type?: string | null;
        read?: string | null;
    };
    stats?: {
        total: number;
        unread: number;
    };
    types?: string[];
};

type ViewKey = 'all' | 'unread' | 'read';

const TYPE_CONFIG: Record<string, { icon: typeof Bell; color: string }> = {
    info: { icon: Info, color: 'bg-status-info-bg text-status-info' },
    alert: {
        icon: AlertTriangle,
        color: 'bg-status-warning-bg text-status-warning',
    },
    success: {
        icon: CheckCircle2,
        color: 'bg-status-success-bg text-status-success',
    },
    message: { icon: MessageSquare, color: 'bg-primary/10 text-primary' },
    reminder: { icon: CalendarDays, color: 'bg-primary/10 text-primary' },
    email: { icon: Mail, color: 'bg-status-info-bg text-status-info' },
};

function formatDate(d: string | null): string {
    if (!d) return '-';
    return new Date(d).toLocaleDateString('en-NZ', {
        day: 'numeric',
        month: 'short',
        year: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
    });
}

function typeLabel(type: string): string {
    return type.replace(/_/g, ' ').replace(/^\w/, (m) => m.toUpperCase());
}

export default function NotificationsIndex({
    notifications = {
        data: [],
        links: [],
        current_page: 1,
        last_page: 1,
        total: 0,
    },
    filters = {},
    stats,
    types = [],
}: Props) {
    const s = stats ?? { total: 0, unread: 0 };
    const readCount = Math.max(0, s.total - s.unread);

    const view: ViewKey =
        filters.read === 'unread'
            ? 'unread'
            : filters.read === 'read'
              ? 'read'
              : 'all';

    const [q, setQ] = useState(filters.q ?? '');

    const applyFilters = useCallback(
        (overrides: { view?: ViewKey; q?: string; type?: string }) => {
            const nextView = overrides.view ?? view;
            const nextQ = overrides.q ?? filters.q ?? '';
            const nextType = overrides.type ?? filters.type ?? 'all';
            const params: Record<string, string> = {};
            if (nextView !== 'all') params.read = nextView;
            if (nextQ.trim() !== '') params.q = nextQ.trim();
            if (nextType !== 'all') params.type = nextType;
            router.get('/operations/notifications', params, {
                preserveState: true,
                replace: true,
            });
        },
        [view, filters.q, filters.type],
    );

    useEffect(() => {
        if ((filters.q ?? '') === q.trim()) return;
        const t = setTimeout(() => applyFilters({ q }), 400);
        return () => clearTimeout(t);
    }, [q, filters.q, applyFilters]);

    const markRead = (id: number) => {
        router.post(
            `/operations/notifications/${id}/read`,
            {},
            { preserveState: true },
        );
    };

    const markAllRead = () => {
        router.post(
            '/operations/notifications/read-all',
            {},
            { preserveState: true },
        );
    };

    const railItems: PageHeaderRailItem<ViewKey>[] = [
        { key: 'all', label: 'All', icon: Layers, count: s.total },
        {
            key: 'unread',
            label: 'Unread',
            icon: BellRing,
            count: s.unread,
        },
        {
            key: 'read',
            label: 'Read',
            icon: CheckCircle2,
            count: readCount,
        },
    ];

    const currentViewLabel =
        (railItems.find((v) => v.key === view)?.label ?? 'All') +
        ' notifications';

    const hasNarrowing =
        (filters.q ?? '') !== '' || (filters.type ?? 'all') !== 'all';

    const typeOptions = [
        { value: 'all', label: 'All types' },
        ...types.map((t) => ({ value: t, label: typeLabel(t) })),
    ];

    const header = (
        <PageHeader
            icon={Bell}
            title="Notifications"
            titleChip={
                s.unread > 0 ? (
                    <PageHeaderStatusChip variant="info">
                        {s.unread} unread
                    </PageHeaderStatusChip>
                ) : (
                    <PageHeaderStatusChip variant="success">
                        All caught up
                    </PageHeaderStatusChip>
                )
            }
            subline={`Your operations notifications · ${s.total} total · ${s.unread} unread`}
            actions={
                <>
                    <PageHeaderSearch
                        value={q}
                        onChange={setQ}
                        placeholder="Search notifications…"
                    />
                    {s.unread > 0 ? (
                        <PageHeaderPrimaryButton
                            icon={CheckCircle2}
                            onClick={markAllRead}
                        >
                            Mark all read
                        </PageHeaderPrimaryButton>
                    ) : null}
                </>
            }
            meters={
                <>
                    <PageHeaderMeterBlock
                        label="Total"
                        ariaLabel="View all notifications"
                        onClick={() => applyFilters({ view: 'all' })}
                    >
                        <PageHeaderMeterBig>{s.total}</PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            notifications received
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                    <PageHeaderMeterBlock
                        label="Unread"
                        tone={s.unread > 0 ? 'warning' : 'success'}
                        ariaLabel="View unread notifications"
                        onClick={() => applyFilters({ view: 'unread' })}
                    >
                        <PageHeaderMeterBig>{s.unread}</PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            waiting for you
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                    {s.total > 0 ? (
                        <PageHeaderMeterBlock
                            label="Read"
                            ariaLabel="View read notifications"
                            onClick={() => applyFilters({ view: 'read' })}
                        >
                            <PageHeaderMeterDonut
                                percent={(readCount / s.total) * 100}
                                caption={
                                    <>
                                        {readCount} of {s.total}
                                        <br />
                                        read
                                    </>
                                }
                            />
                        </PageHeaderMeterBlock>
                    ) : null}
                </>
            }
            filters={
                <PageHeaderFilterSelect
                    icon={Bell}
                    label="All types"
                    value={filters.type ?? 'all'}
                    options={typeOptions}
                    onChange={(v) => applyFilters({ type: v })}
                />
            }
            rail={
                <PageHeaderRail
                    items={railItems}
                    value={view}
                    onSelect={(key) => applyFilters({ view: key })}
                    ariaLabel="Notification views"
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
                    title: 'Notifications',
                    href: '/operations/notifications',
                },
            ]}
        >
            <Head title="Notifications" />

            <PageLayout hero={header}>
                <div className="flex flex-col gap-5">
                    <ListCaption
                        title={currentViewLabel}
                        caption={`${notifications.data.length} of ${
                            view === 'unread'
                                ? s.unread
                                : view === 'read'
                                  ? readCount
                                  : s.total
                        } shown`}
                    />

                    <div className="space-y-2">
                        {notifications.data.length === 0 ? (
                            <EmptyState
                                icon={BellOff}
                                title="No notifications"
                                description={
                                    hasNarrowing || view !== 'all'
                                        ? 'Nothing matches this view or your filters.'
                                        : "You're all caught up!"
                                }
                            />
                        ) : (
                            notifications.data.map((notif) => {
                                const typeConf =
                                    TYPE_CONFIG[notif.type] ?? TYPE_CONFIG.info;
                                const Icon = typeConf.icon;
                                return (
                                    <Card
                                        key={notif.id}
                                        className={`transition-all hover:border-border hover:shadow-sm ${!notif.is_read ? 'border-l-2 border-l-primary bg-primary/5' : ''}`}
                                    >
                                        <CardContent className="flex items-center gap-4 p-4">
                                            <div
                                                className={`flex h-10 w-10 shrink-0 items-center justify-center rounded-xl ${typeConf.color}`}
                                            >
                                                <Icon className="h-5 w-5" />
                                            </div>
                                            <div className="min-w-0 flex-1">
                                                <div className="flex items-center gap-2">
                                                    <span
                                                        className={`text-sm ${!notif.is_read ? 'font-semibold' : 'font-medium'}`}
                                                    >
                                                        {notif.title}
                                                    </span>
                                                    <Badge
                                                        variant="outline"
                                                        className="h-4 px-1.5 text-[9px] capitalize"
                                                    >
                                                        {notif.type}
                                                    </Badge>
                                                    {!notif.is_read && (
                                                        <span className="h-2 w-2 rounded-full bg-primary" />
                                                    )}
                                                </div>
                                                <p className="mt-0.5 line-clamp-1 text-xs text-muted-foreground">
                                                    {notif.body}
                                                </p>
                                                <span className="mt-0.5 text-[10px] text-muted-foreground/60">
                                                    {formatDate(
                                                        notif.created_at,
                                                    )}
                                                </span>
                                            </div>
                                            <div className="flex shrink-0 gap-1">
                                                {!notif.is_read && (
                                                    <Button
                                                        size="sm"
                                                        variant="ghost"
                                                        className="h-7 px-2 text-xs"
                                                        onClick={() =>
                                                            markRead(notif.id)
                                                        }
                                                    >
                                                        <CheckCircle2 className="mr-1 h-3 w-3" />{' '}
                                                        Mark read
                                                    </Button>
                                                )}
                                            </div>
                                        </CardContent>
                                    </Card>
                                );
                            })
                        )}
                    </div>

                    {(notifications.last_page ?? 1) > 1 && (
                        <div className="flex items-center justify-center gap-1">
                            {(notifications.links ?? []).map((link, i) => (
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
