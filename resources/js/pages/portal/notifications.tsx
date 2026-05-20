import { PageHero, PageLayout } from '@/components/page';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import AppLayout from '@/layouts/app-layout';
import { Head, router } from '@inertiajs/react';
import { Bell, CheckCheck } from 'lucide-react';

type Notification = {
    id: string;
    type: string;
    data: Record<string, any>;
    read_at?: string | null;
    created_at: string;
};

type Props = {
    notifications: {
        data: Notification[];
        links: any;
    };
    filter: string;
    unreadCount: number;
};

function timeAgo(iso: string): string {
    const seconds = Math.floor((Date.now() - new Date(iso).getTime()) / 1000);
    if (seconds < 60) return 'just now';
    const minutes = Math.floor(seconds / 60);
    if (minutes < 60) return `${minutes}m ago`;
    const hours = Math.floor(minutes / 60);
    if (hours < 24) return `${hours}h ago`;
    const days = Math.floor(hours / 24);
    if (days < 7) return `${days}d ago`;
    return new Date(iso).toLocaleDateString([], { day: 'numeric', month: 'short' });
}

function notificationTitle(notification: Notification): string {
    return notification.data.title || notification.type.replace(/_/g, ' ').replace(/\b\w/g, (c) => c.toUpperCase());
}

function notificationBody(notification: Notification): string | null {
    return notification.data.body || notification.data.message || null;
}

export default function Notifications({ notifications, filter, unreadCount }: Props) {
    const activeFilter = filter || 'all';

    const setFilter = (filter: string) => {
        router.get('/portal/notifications', { filter }, { preserveState: true });
    };

    const markAllRead = () => {
        router.post('/portal/notifications/read-all', {}, { preserveState: false });
    };

    const markAsRead = (notification: Notification) => {
        if (!notification.read_at) {
            router.post(`/portal/notifications/${notification.id}/read`, {}, { preserveState: true });
        }
    };

    const loadMore = () => {
        if (notifications.links?.next) {
            router.get(notifications.links.next, {}, { preserveState: true, preserveScroll: true });
        }
    };

    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Portal', href: '/portal' },
                { title: 'Notifications', href: '/portal/notifications' },
            ]}
        >
            <Head title="Notifications" />

            <PageLayout
                hero={
                    <PageHero
                        icon={Bell}
                        title="Notifications"
                        description="Latest updates from the care team."
                        stats={[
                            { label: 'Total', value: notifications.data.length },
                            { label: 'Unread', value: unreadCount },
                        ]}
                        actions={
                            unreadCount > 0 ? (
                                <Button
                                    variant="outline"
                                    size="sm"
                                    onClick={markAllRead}
                                    className="border-primary-foreground/30 bg-primary-foreground/10 text-primary-foreground backdrop-blur-sm hover:bg-primary-foreground/20 hover:text-primary-foreground"
                                >
                                    <CheckCheck className="mr-2 h-4 w-4" />
                                    Mark all read
                                </Button>
                            ) : undefined
                        }
                    />
                }
            >
                {/* Filter tabs */}
                <div className="flex gap-2">
                    <Button
                        variant={activeFilter === 'all' ? 'default' : 'outline'}
                        size="sm"
                        onClick={() => setFilter('all')}
                    >
                        All
                    </Button>
                    <Button
                        variant={activeFilter === 'unread' ? 'default' : 'outline'}
                        size="sm"
                        onClick={() => setFilter('unread')}
                        className="gap-2"
                    >
                        Unread
                        {unreadCount > 0 && (
                            <Badge variant="secondary" className="ml-1 px-1.5 py-0 text-xs">
                                {unreadCount}
                            </Badge>
                        )}
                    </Button>
                </div>

                {notifications.data.length === 0 ? (
                    <Card>
                        <CardContent className="flex flex-col items-center justify-center py-16">
                            <Bell className="mb-4 h-12 w-12 text-muted-foreground" />
                            <p className="text-lg font-medium text-muted-foreground">You're all caught up!</p>
                        </CardContent>
                    </Card>
                ) : (
                    <div className="space-y-2">
                        {notifications.data.map((notification) => {
                            const isUnread = !notification.read_at;
                            const title = notificationTitle(notification);
                            const body = notificationBody(notification);

                            return (
                                <Card
                                    key={notification.id}
                                    className={`cursor-pointer border-l-4 transition-shadow hover:shadow-md ${
                                        isUnread ? 'border-l-blue-500' : 'border-l-transparent'
                                    }`}
                                    onClick={() => markAsRead(notification)}
                                >
                                    <CardContent className="flex items-start gap-3 p-4">
                                        <div
                                            className={`mt-0.5 flex h-9 w-9 shrink-0 items-center justify-center rounded-full ${
                                                isUnread ? 'bg-status-info-bg' : 'bg-muted'
                                            }`}
                                        >
                                            <Bell
                                                className={`h-4 w-4 ${
                                                    isUnread ? 'text-status-info dark:text-status-info' : 'text-muted-foreground'
                                                }`}
                                            />
                                        </div>
                                        <div className="min-w-0 flex-1">
                                            <div className="flex items-start justify-between gap-2">
                                                <h3
                                                    className={`text-sm ${
                                                        isUnread ? 'font-semibold' : 'font-medium'
                                                    }`}
                                                >
                                                    {title}
                                                </h3>
                                                <span className="shrink-0 text-xs text-muted-foreground">
                                                    {timeAgo(notification.created_at)}
                                                </span>
                                            </div>
                                            {body && (
                                                <p className="mt-0.5 text-sm text-muted-foreground">{body}</p>
                                            )}
                                        </div>
                                    </CardContent>
                                </Card>
                            );
                        })}
                    </div>
                )}

                {notifications.links?.next && (
                    <div className="flex justify-center">
                        <Button variant="outline" onClick={loadMore}>
                            Load more
                        </Button>
                    </div>
                )}
            </PageLayout>
        </AppLayout>
    );
}
