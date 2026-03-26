import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { TabsContent, TabsList, TabsRoot, TabsTrigger } from '@/components/ui/tabs';
import AppLayout from '@/layouts/app-layout';
import { Head, Link, router } from '@inertiajs/react';
import {
    Bell,
    BellOff,
    Building2,
    CheckCircle2,
    ClipboardList,
    ExternalLink,
    Megaphone,
    ShieldAlert,
    TriangleAlert,
    Users,
    Wrench,
} from 'lucide-react';
import { useState } from 'react';

/* ------------------------------------------------------------------ */
/*  Types                                                              */
/* ------------------------------------------------------------------ */

interface NotificationItem {
    id: string;
    type: string;
    data: Record<string, any>;
    read_at: string | null;
    acknowledged_at: string | null;
    created_at: string;
}

interface PaginationLink {
    url: string | null;
    label: string;
    active: boolean;
}

interface PaginatedNotifications {
    data: NotificationItem[];
    links: PaginationLink[];
    current_page: number;
    last_page: number;
    total: number;
}

interface Announcement {
    id: number;
    title: string;
    body: string | null;
    author_name: string | null;
    starts_at: string | null;
    ends_at: string | null;
    roles: string[] | null;
    is_active: boolean;
    created_at: string;
}

interface Props {
    notifications?: PaginatedNotifications | null;
    unread_count?: number | null;
    filters?: { filter?: string; type?: string } | null;
    announcements?: Announcement[] | null;
}

/* ------------------------------------------------------------------ */
/*  Helpers                                                            */
/* ------------------------------------------------------------------ */

const MODULE_COLOURS: Record<string, { border: string; bg: string; text: string; icon: typeof Bell }> = {
    operations: { border: 'border-l-violet-500', bg: 'bg-violet-100 dark:bg-violet-900/30', text: 'text-violet-700 dark:text-violet-300', icon: ClipboardList },
    hr: { border: 'border-l-blue-500', bg: 'bg-blue-100 dark:bg-blue-900/30', text: 'text-blue-700 dark:text-blue-300', icon: Users },
    governance: { border: 'border-l-emerald-500', bg: 'bg-emerald-100 dark:bg-emerald-900/30', text: 'text-emerald-700 dark:text-emerald-300', icon: ShieldAlert },
    sites: { border: 'border-l-amber-500', bg: 'bg-amber-100 dark:bg-amber-900/30', text: 'text-amber-700 dark:text-amber-300', icon: Building2 },
    incidents: { border: 'border-l-red-500', bg: 'bg-red-100 dark:bg-red-900/30', text: 'text-red-700 dark:text-red-300', icon: TriangleAlert },
    system: { border: 'border-l-slate-500', bg: 'bg-slate-100 dark:bg-slate-900/30', text: 'text-slate-700 dark:text-slate-300', icon: Wrench },
};

function getModuleStyle(module?: string) {
    const key = (module ?? 'system').toLowerCase();
    return MODULE_COLOURS[key] ?? MODULE_COLOURS.system;
}

function relativeTime(dateStr: string | null): string {
    if (!dateStr) return '';
    const now = Date.now();
    const then = new Date(dateStr).getTime();
    const diff = now - then;
    const seconds = Math.floor(diff / 1000);
    if (seconds < 60) return 'Just now';
    const minutes = Math.floor(seconds / 60);
    if (minutes < 60) return `${minutes}m ago`;
    const hours = Math.floor(minutes / 60);
    if (hours < 24) return `${hours}h ago`;
    const days = Math.floor(hours / 24);
    if (days < 7) return `${days}d ago`;
    return new Date(dateStr).toLocaleDateString();
}

function notificationTitle(n: NotificationItem): string {
    const t = n.data?.title || n.data?.subject;
    if (typeof t === 'string' && t.trim()) return t;
    return n.type || 'Notification';
}

function notificationBody(n: NotificationItem): string | null {
    const msg = n.data?.body || n.data?.description || n.data?.message;
    return typeof msg === 'string' ? msg : null;
}

/* ------------------------------------------------------------------ */
/*  Component                                                          */
/* ------------------------------------------------------------------ */

const breadcrumbs = [{ title: 'Notification Centre', href: '/notifications' }];

export default function NotificationsIndex({
    notifications = null,
    unread_count = 0,
    filters = null,
    announcements = null,
}: Props) {
    const notifData = notifications?.data ?? [];
    const notifLinks = notifications?.links ?? [];
    const lastPage = notifications?.last_page ?? 1;
    const totalNotifs = notifications?.total ?? 0;
    const currentFilter = filters?.filter ?? 'all';
    const currentType = filters?.type ?? 'all';
    const announcementList = announcements ?? [];
    const unread = unread_count ?? 0;

    const [expandedAnnouncement, setExpandedAnnouncement] = useState<number | null>(null);

    const markRead = (id: string) => {
        router.post(`/inbox/notifications/${id}/read`, {}, { preserveScroll: true });
    };

    const markAllRead = () => {
        router.post('/inbox/notifications/read-all', {}, { preserveScroll: true });
    };

    const acknowledge = (id: string) => {
        router.post(`/inbox/notifications/${id}/acknowledge`, {}, { preserveScroll: true });
    };

    const applyFilter = (key: string, value: string) => {
        const params: Record<string, string> = {
            filter: currentFilter,
            type: currentType,
            [key]: value,
        };
        // Remove default values
        if (params.filter === 'all') delete params.filter;
        if (params.type === 'all') delete params.type;
        router.get('/notifications', params, { preserveState: true, preserveScroll: true });
    };

    const clearFilters = () => {
        router.get('/notifications', {}, { preserveState: true, preserveScroll: true });
    };

    const hasActiveFilters = currentFilter !== 'all' || currentType !== 'all';

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Notification Centre" />

            <div className="mx-auto w-full max-w-4xl px-4 py-6 sm:px-6 lg:px-8">
                {/* Header */}
                <div className="mb-6 flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                    <div className="flex items-center gap-3">
                        <h1 className="text-2xl font-bold tracking-tight">Notification Centre</h1>
                        {unread > 0 && (
                            <Badge variant="secondary" className="bg-violet-100 text-violet-700 dark:bg-violet-900/30 dark:text-violet-300">
                                {unread} unread
                            </Badge>
                        )}
                    </div>
                    <Button
                        variant="default"
                        size="sm"
                        disabled={!unread}
                        className="bg-violet-600 hover:bg-violet-700"
                        onClick={markAllRead}
                    >
                        <CheckCircle2 className="mr-1.5 h-4 w-4" />
                        Mark All Read
                    </Button>
                </div>

                <TabsRoot defaultValue="notifications">
                    <TabsList>
                        <TabsTrigger value="notifications">
                            <Bell className="mr-1.5 h-4 w-4" />
                            Notifications
                            {totalNotifs > 0 && (
                                <Badge variant="secondary" className="ml-2 text-xs">
                                    {totalNotifs}
                                </Badge>
                            )}
                        </TabsTrigger>
                        <TabsTrigger value="announcements">
                            <Megaphone className="mr-1.5 h-4 w-4" />
                            Announcements
                            {announcementList.length > 0 && (
                                <Badge variant="secondary" className="ml-2 text-xs">
                                    {announcementList.length}
                                </Badge>
                            )}
                        </TabsTrigger>
                    </TabsList>

                    {/* ========== NOTIFICATIONS TAB ========== */}
                    <TabsContent value="notifications" className="mt-6">
                        {/* Filter bar */}
                        <div className="mb-4 flex flex-wrap items-center gap-3">
                            <Select value={currentFilter} onValueChange={(v) => applyFilter('filter', v)}>
                                <SelectTrigger className="w-36">
                                    <SelectValue placeholder="Read status" />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="all">All</SelectItem>
                                    <SelectItem value="unread">Unread</SelectItem>
                                    <SelectItem value="read">Read</SelectItem>
                                </SelectContent>
                            </Select>

                            <Select value={currentType} onValueChange={(v) => applyFilter('type', v)}>
                                <SelectTrigger className="w-40">
                                    <SelectValue placeholder="Type" />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="all">All</SelectItem>
                                    <SelectItem value="operations">Operations</SelectItem>
                                    <SelectItem value="hr">HR</SelectItem>
                                    <SelectItem value="governance">Governance</SelectItem>
                                    <SelectItem value="sites">Sites</SelectItem>
                                    <SelectItem value="incidents">Incidents</SelectItem>
                                    <SelectItem value="system">System</SelectItem>
                                </SelectContent>
                            </Select>

                            {hasActiveFilters && (
                                <button
                                    type="button"
                                    onClick={clearFilters}
                                    className="text-sm text-muted-foreground underline underline-offset-2 hover:text-foreground"
                                >
                                    Clear filters
                                </button>
                            )}
                        </div>

                        {/* Notification cards */}
                        {notifData.length === 0 ? (
                            <div className="flex flex-col items-center justify-center rounded-lg border border-dashed py-16">
                                <BellOff className="mb-3 h-10 w-10 text-muted-foreground/50" />
                                <p className="text-lg font-medium text-muted-foreground">No notifications</p>
                                <p className="mt-1 text-sm text-muted-foreground/70">You're all caught up!</p>
                            </div>
                        ) : (
                            <div className="space-y-2">
                                {notifData.map((n) => {
                                    const isUnread = !n.read_at;
                                    const module = getModuleStyle(n.data?.module);
                                    const Icon = module.icon;
                                    const title = notificationTitle(n);
                                    const body = notificationBody(n);
                                    const url = n.data?.url || n.data?.action_url;
                                    const needsAck = !!n.data?.ack_required && !n.acknowledged_at;

                                    return (
                                        <div
                                            key={n.id}
                                            role="button"
                                            tabIndex={0}
                                            className={`flex items-start gap-3 rounded-lg border-l-4 p-4 transition-colors hover:bg-accent/50 ${
                                                isUnread
                                                    ? `bg-white dark:bg-card ${module.border}`
                                                    : `bg-muted/40 ${module.border} opacity-80`
                                            }`}
                                            onClick={() => {
                                                if (isUnread) markRead(n.id);
                                            }}
                                            onKeyDown={(e) => {
                                                if (e.key === 'Enter' && isUnread) markRead(n.id);
                                            }}
                                        >
                                            {/* Icon */}
                                            <div className={`mt-0.5 flex h-8 w-8 shrink-0 items-center justify-center rounded-full ${module.bg}`}>
                                                <Icon className={`h-4 w-4 ${module.text}`} />
                                            </div>

                                            {/* Content */}
                                            <div className="min-w-0 flex-1">
                                                <div className="flex items-start justify-between gap-2">
                                                    <div className="min-w-0 flex-1">
                                                        <p className={`text-sm ${isUnread ? 'font-semibold' : 'font-normal text-muted-foreground'}`}>
                                                            {title}
                                                        </p>
                                                        {body && (
                                                            <p className="mt-0.5 line-clamp-2 text-xs text-muted-foreground">
                                                                {body}
                                                            </p>
                                                        )}
                                                        <div className="mt-1.5 flex flex-wrap items-center gap-2">
                                                            <Badge variant="outline" className={`text-[10px] ${module.text}`}>
                                                                {(n.data?.module ?? 'system').charAt(0).toUpperCase() + (n.data?.module ?? 'system').slice(1)}
                                                            </Badge>
                                                            {needsAck && (
                                                                <Badge className="bg-amber-100 text-amber-800 dark:bg-amber-900/30 dark:text-amber-300">
                                                                    Acknowledge Required
                                                                </Badge>
                                                            )}
                                                        </div>
                                                    </div>

                                                    {/* Right side */}
                                                    <div className="flex shrink-0 flex-col items-end gap-1.5">
                                                        <span className="text-xs text-muted-foreground">
                                                            {relativeTime(n.created_at)}
                                                        </span>
                                                        <div className="flex items-center gap-1.5">
                                                            {needsAck && (
                                                                <Button
                                                                    variant="outline"
                                                                    size="sm"
                                                                    className="h-7 border-amber-300 px-2 text-xs text-amber-700 hover:bg-amber-50"
                                                                    onClick={(e) => {
                                                                        e.stopPropagation();
                                                                        acknowledge(n.id);
                                                                    }}
                                                                >
                                                                    Acknowledge
                                                                </Button>
                                                            )}
                                                            {url && (
                                                                <Button
                                                                    variant="ghost"
                                                                    size="sm"
                                                                    className="h-7 px-2 text-xs"
                                                                    asChild
                                                                    onClick={(e) => e.stopPropagation()}
                                                                >
                                                                    <Link href={url}>
                                                                        View
                                                                        <ExternalLink className="ml-1 h-3 w-3" />
                                                                    </Link>
                                                                </Button>
                                                            )}
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    );
                                })}
                            </div>
                        )}

                        {/* Pagination */}
                        {lastPage > 1 && (
                            <div className="mt-6 flex items-center justify-center gap-1">
                                {notifLinks.map((link, idx) => (
                                    <Button
                                        key={idx}
                                        variant={link.active ? 'default' : 'outline'}
                                        size="sm"
                                        disabled={!link.url}
                                        asChild={!!link.url}
                                    >
                                        {link.url ? (
                                            <Link href={link.url} preserveScroll dangerouslySetInnerHTML={{ __html: link.label }} />
                                        ) : (
                                            <span dangerouslySetInnerHTML={{ __html: link.label }} />
                                        )}
                                    </Button>
                                ))}
                            </div>
                        )}
                    </TabsContent>

                    {/* ========== ANNOUNCEMENTS TAB ========== */}
                    <TabsContent value="announcements" className="mt-6">
                        {announcementList.length === 0 ? (
                            <div className="flex flex-col items-center justify-center rounded-lg border border-dashed py-16">
                                <Megaphone className="mb-3 h-10 w-10 text-muted-foreground/50" />
                                <p className="text-lg font-medium text-muted-foreground">No announcements</p>
                                <p className="mt-1 text-sm text-muted-foreground/70">Check back later for updates.</p>
                            </div>
                        ) : (
                            <div className="space-y-3">
                                {announcementList.map((a) => {
                                    const isExpanded = expandedAnnouncement === a.id;
                                    return (
                                        <div
                                            key={a.id}
                                            className="rounded-lg border bg-card p-4"
                                        >
                                            <div className="flex items-start justify-between gap-3">
                                                <div className="min-w-0 flex-1">
                                                    <p className="font-semibold">{a.title}</p>
                                                    {a.body && (
                                                        <div className="mt-1.5">
                                                            <p className={`text-sm text-muted-foreground ${isExpanded ? '' : 'line-clamp-2'}`}>
                                                                {a.body}
                                                            </p>
                                                            {a.body.length > 150 && (
                                                                <button
                                                                    type="button"
                                                                    onClick={() => setExpandedAnnouncement(isExpanded ? null : a.id)}
                                                                    className="mt-1 text-xs text-violet-600 hover:underline dark:text-violet-400"
                                                                >
                                                                    {isExpanded ? 'Show less' : 'Read more'}
                                                                </button>
                                                            )}
                                                        </div>
                                                    )}
                                                    <div className="mt-2 flex flex-wrap items-center gap-2 text-xs text-muted-foreground">
                                                        {a.author_name && <span>By {a.author_name}</span>}
                                                        {a.starts_at && a.ends_at && (
                                                            <span>
                                                                {new Date(a.starts_at).toLocaleDateString()} &ndash; {new Date(a.ends_at).toLocaleDateString()}
                                                            </span>
                                                        )}
                                                        {a.created_at && !a.starts_at && (
                                                            <span>{new Date(a.created_at).toLocaleDateString()}</span>
                                                        )}
                                                    </div>
                                                    {Array.isArray(a.roles) && a.roles.length > 0 && (
                                                        <div className="mt-2 flex flex-wrap gap-1">
                                                            {a.roles.map((role) => (
                                                                <Badge key={role} variant="outline" className="text-[10px]">
                                                                    {role}
                                                                </Badge>
                                                            ))}
                                                        </div>
                                                    )}
                                                </div>
                                            </div>
                                        </div>
                                    );
                                })}
                            </div>
                        )}
                    </TabsContent>
                </TabsRoot>
            </div>
        </AppLayout>
    );
}
