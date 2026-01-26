import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
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
import { useMemo, useState } from 'react';

type InboxPayload = {
    notifications: {
        unread_count: number;
        items: Array<{
            id: string;
            type: string;
            data: any;
            read_at: string | null;
            acknowledged_at: string | null;
            escalation_count: number | null;
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

function notificationContext(n: { data: any }): Array<{ label: string; value: string }> {
    const ctx = n?.data?.context;
    if (!ctx || typeof ctx !== 'object') return [];
    return Object.entries(ctx)
        .filter(([, v]) => v !== null && v !== undefined && String(v).trim() !== '')
        .map(([k, v]) => ({ label: k, value: String(v) }));
}

export default function InboxMenus() {
    const inbox = (usePage().props as any).inbox as InboxPayload | null;
    if (!inbox) return null;

    const [openNotifId, setOpenNotifId] = useState<string | null>(null);
    const [openAnnouncementId, setOpenAnnouncementId] = useState<number | null>(null);

    const openNotification = useMemo(
        () => inbox.notifications.items.find((n) => n.id === openNotifId) ?? null,
        [inbox.notifications.items, openNotifId],
    );
    const openAnnouncement = useMemo(
        () => inbox.announcements.items.find((a) => a.id === openAnnouncementId) ?? null,
        [inbox.announcements.items, openAnnouncementId],
    );

    const mustAckBeforeClose = !!openNotification?.data?.must_ack_before_close && !!openNotification?.data?.ack_required && !openNotification?.acknowledged_at;

    const reloadInbox = () => {
        // Refresh just the inbox payload so counts / read state update immediately.
        router.reload({ only: ['inbox'], preserveScroll: true });
    };

    const markNotificationRead = (id: string) => {
        router.post(`/inbox/notifications/${id}/read`, {}, { preserveScroll: true, onSuccess: reloadInbox });
    };

    const acknowledgeNotification = (id: string) => {
        router.post(`/inbox/notifications/${id}/acknowledge`, {}, { preserveScroll: true, onSuccess: reloadInbox });
    };

    const markAnnouncementRead = (id: number) => {
        router.post(`/inbox/announcements/${id}/read`, {}, { preserveScroll: true, onSuccess: reloadInbox });
    };

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
                                router.post(
                                    '/inbox/notifications/read-all',
                                    {},
                                    { preserveScroll: true, onSuccess: reloadInbox },
                                )
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

                                <div className="mt-1 flex w-full justify-end">
                                    <Button
                                        type="button"
                                        variant="ghost"
                                        size="sm"
                                        className="h-7 px-2 text-xs"
                                        onClick={() => {
                                            setOpenAnnouncementId(null);
                                            setOpenNotifId(n.id);
                                            if (isUnread) markNotificationRead(n.id);
                                        }}
                                    >
                                        View
                                    </Button>
                                </div>
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
                                router.post(
                                    '/inbox/announcements/read-all',
                                    {},
                                    { preserveScroll: true, onSuccess: reloadInbox },
                                )
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

                                <div className="mt-1 flex w-full justify-end">
                                    <Button
                                        type="button"
                                        variant="ghost"
                                        size="sm"
                                        className="h-7 px-2 text-xs"
                                        onClick={() => {
                                            setOpenNotifId(null);
                                            setOpenAnnouncementId(a.id);
                                            if (isUnread) markAnnouncementRead(a.id);
                                        }}
                                    >
                                        View
                                    </Button>
                                </div>
                            </DropdownMenuItem>
                        );
                    })}
                </DropdownMenuContent>
            </DropdownMenu>

            {/* Notification modal */}
            <Dialog open={!!openNotification} onOpenChange={(v) => {
                if (!v) {
                    if (mustAckBeforeClose) return;
                    setOpenNotifId(null);
                }
            }}>
                <DialogContent
                    className="sm:max-w-lg"
                    onEscapeKeyDown={(e) => {
                        if (mustAckBeforeClose) e.preventDefault();
                    }}
                    onPointerDownOutside={(e) => {
                        if (mustAckBeforeClose) e.preventDefault();
                    }}
                >
                    <DialogHeader>
                        <DialogTitle>
                            {openNotification ? notificationTitle(openNotification) : 'Notification'}
                        </DialogTitle>
                    </DialogHeader>

                    {openNotification && (
                        <div className="space-y-3">
                            {openNotification.created_at && (
                                <div className="text-xs text-muted-foreground">
                                    {new Date(openNotification.created_at).toLocaleString()}
                                </div>
                            )}
                            {notificationBody(openNotification) ? (
                                <div className="whitespace-pre-wrap text-sm">
                                    {notificationBody(openNotification)}
                                </div>
                            ) : (
                                <div className="text-sm text-muted-foreground">
                                    No message content.
                                </div>
                            )}

                            {notificationContext(openNotification).length > 0 && (
                                <div className="rounded-md border bg-muted/20 p-3">
                                    <div className="mb-2 text-xs font-semibold text-muted-foreground">
                                        Details
                                    </div>
                                    <div className="space-y-1">
                                        {notificationContext(openNotification).map((row) => (
                                            <div
                                                key={row.label}
                                                className="grid grid-cols-3 gap-2 text-sm"
                                            >
                                                <div className="text-xs font-medium text-muted-foreground">
                                                    {row.label}
                                                </div>
                                                <div className="col-span-2 text-xs">
                                                    {row.value}
                                                </div>
                                            </div>
                                        ))}
                                    </div>
                                </div>
                            )}
                        </div>
                    )}

                    <DialogFooter>
                        {openNotification?.data?.ack_required && !openNotification?.acknowledged_at && (
                            <Button
                                type="button"
                                variant="default"
                                onClick={() => {
                                    if (openNotification?.id) {
                                        acknowledgeNotification(openNotification.id);
                                    }
                                }}
                            >
                                Acknowledge
                            </Button>
                        )}
                        {openNotification?.data?.url && (
                            <Button
                                type="button"
                                onClick={() => {
                                    const url = openNotification?.data?.url;
                                    if (typeof url === 'string' && url) {
                                        window.location.href = url;
                                    }
                                }}
                            >
                                Open
                            </Button>
                        )}
                        <Button type="button" variant="outline" disabled={mustAckBeforeClose} onClick={() => {
                                if (mustAckBeforeClose) return;
                                setOpenNotifId(null);
                            }}>
                            Close
                        </Button>
                        {mustAckBeforeClose && (
                            <div className="w-full text-xs text-muted-foreground">
                                This notification requires acknowledgement before you can close it.
                            </div>
                        )}
                    </DialogFooter>
                </DialogContent>
            </Dialog>

            {/* Announcement modal */}
            <Dialog open={!!openAnnouncement} onOpenChange={(v) => !v && setOpenAnnouncementId(null)}>
                <DialogContent className="sm:max-w-lg">
                    <DialogHeader>
                        <DialogTitle>{openAnnouncement ? openAnnouncement.title : 'Announcement'}</DialogTitle>
                    </DialogHeader>

                    {openAnnouncement && (
                        <div className="space-y-3">
                            <div className="flex items-center justify-between gap-2">
                                {openAnnouncement.author ? (
                                    <div className="text-xs text-muted-foreground">
                                        From {openAnnouncement.author.name}
                                    </div>
                                ) : (
                                    <div />
                                )}
                                {openAnnouncement.created_at && (
                                    <div className="text-xs text-muted-foreground">
                                        {new Date(openAnnouncement.created_at).toLocaleString()}
                                    </div>
                                )}
                            </div>

                            {openAnnouncement.body ? (
                                <div className="whitespace-pre-wrap text-sm">{openAnnouncement.body}</div>
                            ) : (
                                <div className="text-sm text-muted-foreground">No content.</div>
                            )}
                        </div>
                    )}

                    <DialogFooter>
                        <Button type="button" variant="outline" onClick={() => setOpenAnnouncementId(null)}>
                            Close
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </div>
    );
}
