import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { usePage, router } from '@inertiajs/react';
import { Bell, Megaphone } from 'lucide-react';

type InboxPayload = {
    notifications: {
        unread_count: number;
        items: Array<{
            id: string;
            type: string;
            data: any;
            read_at: string | null;
            created_at: string | null;
        }>;
    };
    announcements: {
        unread_count: number;
        items: Array<{
            id: number;
            title: string;
            body: string | null;
            author: { id: number; name: string } | null;
            read_at: string | null;
            created_at: string | null;
        }>;
    };
};

function Badge({ count }: { count: number }) {
    if (!count) return null;
    return (
        <span className="absolute -right-1 -top-1 inline-flex min-w-4 items-center justify-center rounded-full bg-red-600 px-1 text-[10px] font-semibold leading-4 text-white">
            {count > 99 ? '99+' : count}
        </span>
    );
}

function notificationTitle(n: { type: string; data: any }) {
    // Prefer explicit titles
    const t = n?.data?.title || n?.data?.subject;
    if (typeof t === 'string' && t.trim() !== '') return t;

    // Fall back to the notification class name
    const parts = String(n.type ?? '').split('\\');
    return parts[parts.length - 1] || 'Notification';
}

function notificationBody(n: { data: any }) {
    const msg = n?.data?.message || n?.data?.body || n?.data?.text;
    return typeof msg === 'string' ? msg : null;
}

export default function InboxMenus() {
    const inbox = (usePage().props as any).inbox as InboxPayload | null;
    if (!inbox) return null;

    const unreadNotifications = inbox.notifications?.unread_count ?? 0;
    const unreadAnnouncements = inbox.announcements?.unread_count ?? 0;

    return (
        <div className="flex items-center gap-1">
            {/* Notifications */}
            <DropdownMenu>
                <DropdownMenuTrigger asChild>
                    <Button
                        variant="ghost"
                        size="icon"
                        className="relative h-9 w-9"
                        aria-label="Notifications"
                    >
                        <Bell className="h-5 w-5" />
                        <Badge count={unreadNotifications} />
                    </Button>
                </DropdownMenuTrigger>
                <DropdownMenuContent align="end" className="w-96">
                    <div className="flex items-center justify-between">
                        <DropdownMenuLabel>Notifications</DropdownMenuLabel>
                        <Button
                            variant="ghost"
                            size="sm"
                            className="h-8 px-2 text-xs"
                            disabled={!unreadNotifications}
                            onClick={() =>
                                router.post('/inbox/notifications/read-all', {}, { preserveScroll: true })
                            }
                        >
                            Mark all read
                        </Button>
                    </div>
                    <DropdownMenuSeparator />

                    {inbox.notifications.items.length === 0 && (
                        <div className="px-3 py-6 text-center text-sm text-muted-foreground">
                            No notifications yet.
                        </div>
                    )}

                    {inbox.notifications.items.map((n) => {
                        const title = notificationTitle(n);
                        const body = notificationBody(n);
                        const isUnread = !n.read_at;
                        return (
                            <DropdownMenuItem
                                key={n.id}
                                className="flex cursor-pointer flex-col items-start gap-1 whitespace-normal"
                                onSelect={(e) => {
                                    e.preventDefault();
                                    if (!isUnread) return;
                                    router.post(
                                        `/inbox/notifications/${n.id}/read`,
                                        {},
                                        { preserveScroll: true },
                                    );
                                }}
                            >
                                <div className="flex w-full items-center justify-between gap-2">
                                    <span className={isUnread ? 'font-semibold' : 'font-medium'}>
                                        {title}
                                    </span>
                                    {isUnread && (
                                        <span className="rounded bg-muted px-1.5 py-0.5 text-[10px] text-muted-foreground">
                                            New
                                        </span>
                                    )}
                                </div>
                                {body && (
                                    <span className="line-clamp-2 text-xs text-muted-foreground">
                                        {body}
                                    </span>
                                )}
                            </DropdownMenuItem>
                        );
                    })}
                </DropdownMenuContent>
            </DropdownMenu>

            {/* Announcements */}
            <DropdownMenu>
                <DropdownMenuTrigger asChild>
                    <Button
                        variant="ghost"
                        size="icon"
                        className="relative h-9 w-9"
                        aria-label="Announcements"
                    >
                        <Megaphone className="h-5 w-5" />
                        <Badge count={unreadAnnouncements} />
                    </Button>
                </DropdownMenuTrigger>
                <DropdownMenuContent align="end" className="w-96">
                    <div className="flex items-center justify-between">
                        <DropdownMenuLabel>Announcements</DropdownMenuLabel>
                        <Button
                            variant="ghost"
                            size="sm"
                            className="h-8 px-2 text-xs"
                            disabled={!unreadAnnouncements}
                            onClick={() =>
                                router.post('/inbox/announcements/read-all', {}, { preserveScroll: true })
                            }
                        >
                            Mark all read
                        </Button>
                    </div>
                    <DropdownMenuSeparator />

                    {inbox.announcements.items.length === 0 && (
                        <div className="px-3 py-6 text-center text-sm text-muted-foreground">
                            No announcements yet.
                        </div>
                    )}

                    {inbox.announcements.items.map((a) => {
                        const isUnread = !a.read_at;
                        return (
                            <DropdownMenuItem
                                key={a.id}
                                className="flex cursor-pointer flex-col items-start gap-1 whitespace-normal"
                                onSelect={(e) => {
                                    e.preventDefault();
                                    if (!isUnread) return;
                                    router.post(
                                        `/inbox/announcements/${a.id}/read`,
                                        {},
                                        { preserveScroll: true },
                                    );
                                }}
                            >
                                <div className="flex w-full items-center justify-between gap-2">
                                    <span className={isUnread ? 'font-semibold' : 'font-medium'}>
                                        {a.title}
                                    </span>
                                    {isUnread && (
                                        <span className="rounded bg-muted px-1.5 py-0.5 text-[10px] text-muted-foreground">
                                            New
                                        </span>
                                    )}
                                </div>
                                {a.body && (
                                    <span className="line-clamp-2 text-xs text-muted-foreground">
                                        {a.body}
                                    </span>
                                )}
                                {a.author && (
                                    <span className="text-[11px] text-muted-foreground">
                                        From {a.author.name}
                                    </span>
                                )}
                            </DropdownMenuItem>
                        );
                    })}
                </DropdownMenuContent>
            </DropdownMenu>
        </div>
    );
}
