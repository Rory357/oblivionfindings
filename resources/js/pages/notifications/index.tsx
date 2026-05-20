import { PageHero, PageLayout } from '@/components/page';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Switch } from '@/components/ui/switch';
import {
    TabsContent,
    TabsList,
    TabsRoot,
    TabsTrigger,
} from '@/components/ui/tabs';
import AppLayout from '@/layouts/app-layout';
import { Head, Link, router } from '@inertiajs/react';
import {
    ArrowRight,
    Bell,
    BellOff,
    BellRing,
    Building2,
    CheckCircle2,
    ClipboardList,
    ExternalLink,
    Filter,
    Inbox,
    Megaphone,
    Search,
    Settings,
    ShieldAlert,
    TriangleAlert,
    Users,
    Wrench,
} from 'lucide-react';
import { useMemo, useState } from 'react';

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

const MODULE_COLOURS: Record<
    string,
    { border: string; bg: string; text: string; icon: typeof Bell; dot: string }
> = {
    operations: {
        border: 'border-l-violet-500',
        bg: 'bg-primary/10 dark:bg-primary/30',
        text: 'text-primary dark:text-primary/70',
        icon: ClipboardList,
        dot: 'bg-primary',
    },
    hr: {
        border: 'border-l-blue-500',
        bg: 'bg-status-info-bg',
        text: 'text-status-info dark:text-status-info',
        icon: Users,
        dot: 'bg-status-info',
    },
    governance: {
        border: 'border-l-emerald-500',
        bg: 'bg-status-success-bg',
        text: 'text-status-success dark:text-status-success',
        icon: ShieldAlert,
        dot: 'bg-status-success',
    },
    sites: {
        border: 'border-l-amber-500',
        bg: 'bg-status-warning-bg',
        text: 'text-status-warning dark:text-status-warning',
        icon: Building2,
        dot: 'bg-status-warning',
    },
    incidents: {
        border: 'border-l-red-500',
        bg: 'bg-status-critical-bg',
        text: 'text-status-critical dark:text-status-critical',
        icon: TriangleAlert,
        dot: 'bg-status-critical',
    },
    system: {
        border: 'border-l-slate-500',
        bg: 'bg-muted dark:bg-muted/30',
        text: 'text-foreground dark:text-muted-foreground',
        icon: Wrench,
        dot: 'bg-muted-foreground/80',
    },
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
    const notifData = useMemo(
        () => notifications?.data ?? [],
        [notifications?.data],
    );
    const notifLinks = notifications?.links ?? [];
    const lastPage = notifications?.last_page ?? 1;
    const totalNotifs = notifications?.total ?? 0;
    const currentFilter = filters?.filter ?? 'all';
    const currentType = filters?.type ?? 'all';
    const announcementList = announcements ?? [];
    const unread = unread_count ?? 0;

    const [expandedAnnouncement, setExpandedAnnouncement] = useState<
        number | null
    >(null);
    const [searchText, setSearchText] = useState('');
    const [doNotDisturb, setDoNotDisturb] = useState(false);

    // Derive stats
    const acknowledged = useMemo(
        () => notifData.filter((n) => n.acknowledged_at).length,
        [notifData],
    );
    const requiresAction = useMemo(
        () =>
            notifData.filter(
                (n) => !!n.data?.ack_required && !n.acknowledged_at,
            ).length,
        [notifData],
    );

    // Module counts for sidebar
    const moduleCounts = useMemo(() => {
        const counts: Record<string, number> = {};
        Object.keys(MODULE_COLOURS).forEach((m) => (counts[m] = 0));
        notifData.forEach((n) => {
            const mod = (n.data?.module ?? 'system').toLowerCase();
            counts[mod] = (counts[mod] ?? 0) + 1;
        });
        return counts;
    }, [notifData]);

    // Filter by search text client-side
    const filteredNotifData = useMemo(() => {
        if (!searchText.trim()) return notifData;
        const q = searchText.toLowerCase();
        return notifData.filter((n) => {
            const title = notificationTitle(n).toLowerCase();
            const body = (notificationBody(n) ?? '').toLowerCase();
            const mod = (n.data?.module ?? '').toLowerCase();
            return title.includes(q) || body.includes(q) || mod.includes(q);
        });
    }, [notifData, searchText]);

    const markRead = (id: string) => {
        router.post(
            `/inbox/notifications/${id}/read`,
            {},
            { preserveScroll: true },
        );
    };

    const markAllRead = () => {
        router.post(
            '/inbox/notifications/read-all',
            {},
            { preserveScroll: true },
        );
    };

    const acknowledge = (id: string) => {
        router.post(
            `/inbox/notifications/${id}/acknowledge`,
            {},
            { preserveScroll: true },
        );
    };

    const applyFilter = (key: string, value: string) => {
        const params: Record<string, string> = {
            filter: currentFilter,
            type: currentType,
            [key]: value,
        };
        if (params.filter === 'all') delete params.filter;
        if (params.type === 'all') delete params.type;
        router.get('/notifications', params, {
            preserveState: true,
            preserveScroll: true,
        });
    };

    const clearFilters = () => {
        setSearchText('');
        router.get(
            '/notifications',
            {},
            { preserveState: true, preserveScroll: true },
        );
    };

    const hasActiveFilters = currentFilter !== 'all' || currentType !== 'all';

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Notification Centre" />

            <PageLayout
                hero={
                    <PageHero
                        icon={Bell}
                        title="Notification Centre"
                        description="Stay on top of what matters"
                        stats={[
                            { label: 'Total', value: totalNotifs },
                            { label: 'Unread', value: unread },
                            { label: 'Acknowledged', value: acknowledged },
                            { label: 'Action required', value: requiresAction },
                        ]}
                        actions={
                            <Button
                                variant="outline"
                                size="sm"
                                disabled={!unread}
                                className="border-primary-foreground/30 bg-primary-foreground/10 text-primary-foreground backdrop-blur-sm hover:bg-primary-foreground/20 hover:text-primary-foreground"
                                onClick={markAllRead}
                            >
                                <CheckCircle2 className="mr-1.5 h-4 w-4" />
                                Mark All Read
                            </Button>
                        }
                    />
                }
            >
                {/* Stats Row */}
                <div className="mb-6 grid grid-cols-2 gap-3 lg:grid-cols-4">
                    <Card className="border-primary dark:border-primary/30">
                        <CardContent className="flex items-center gap-3 p-4">
                            <div className="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-primary/10 dark:bg-primary/30">
                                <Inbox className="h-5 w-5 text-primary dark:text-primary" />
                            </div>
                            <div>
                                <p className="text-2xl font-bold text-primary dark:text-primary">
                                    {totalNotifs}
                                </p>
                                <p className="text-xs font-medium text-muted-foreground">
                                    Total
                                </p>
                            </div>
                        </CardContent>
                    </Card>
                    <Card className="border-primary dark:border-primary/30">
                        <CardContent className="flex items-center gap-3 p-4">
                            <div className="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-primary/10 dark:bg-primary/30">
                                <BellRing className="h-5 w-5 text-primary dark:text-primary" />
                            </div>
                            <div>
                                <p className="text-2xl font-bold text-primary dark:text-primary">
                                    {unread}
                                </p>
                                <p className="text-xs font-medium text-muted-foreground">
                                    Unread
                                </p>
                            </div>
                        </CardContent>
                    </Card>
                    <Card className="border-status-success/30 dark:border-status-success/30">
                        <CardContent className="flex items-center gap-3 p-4">
                            <div className="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-status-success-bg">
                                <CheckCircle2 className="h-5 w-5 text-status-success dark:text-status-success" />
                            </div>
                            <div>
                                <p className="text-2xl font-bold text-status-success dark:text-status-success">
                                    {acknowledged}
                                </p>
                                <p className="text-xs font-medium text-muted-foreground">
                                    Acknowledged
                                </p>
                            </div>
                        </CardContent>
                    </Card>
                    <Card className="border-status-warning/30 dark:border-status-warning/30">
                        <CardContent className="flex items-center gap-3 p-4">
                            <div className="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-status-warning-bg">
                                <TriangleAlert className="h-5 w-5 text-status-warning dark:text-status-warning" />
                            </div>
                            <div>
                                <p className="text-2xl font-bold text-status-warning dark:text-status-warning">
                                    {requiresAction}
                                </p>
                                <p className="text-xs font-medium text-muted-foreground">
                                    Requires Action
                                </p>
                            </div>
                        </CardContent>
                    </Card>
                </div>

                {/* Main layout: content + sidebar */}
                <div className="flex gap-6">
                    {/* Main content area */}
                    <div className="min-w-0 flex-1">
                        <TabsRoot defaultValue="notifications">
                            <TabsList>
                                <TabsTrigger value="notifications">
                                    <Bell className="mr-1.5 h-4 w-4" />
                                    Notifications
                                    {totalNotifs > 0 && (
                                        <Badge
                                            variant="secondary"
                                            className="ml-2 bg-primary/10 text-xs text-primary dark:bg-primary/30 dark:text-primary/70"
                                        >
                                            {totalNotifs}
                                        </Badge>
                                    )}
                                </TabsTrigger>
                                <TabsTrigger value="announcements">
                                    <Megaphone className="mr-1.5 h-4 w-4" />
                                    Announcements
                                    {announcementList.length > 0 && (
                                        <Badge
                                            variant="secondary"
                                            className="ml-2 bg-status-info-bg text-xs text-status-info dark:bg-status-info-bg dark:text-status-info"
                                        >
                                            {announcementList.length}
                                        </Badge>
                                    )}
                                </TabsTrigger>
                            </TabsList>

                            {/* ========== NOTIFICATIONS TAB ========== */}
                            <TabsContent value="notifications" className="mt-6">
                                {/* Filter bar */}
                                <Card className="mb-4">
                                    <CardContent className="flex flex-wrap items-center gap-3 p-3">
                                        <div className="flex items-center gap-2">
                                            <Filter className="h-4 w-4 text-muted-foreground" />
                                            <span className="text-xs font-medium text-muted-foreground">
                                                Filters
                                            </span>
                                        </div>

                                        <div className="flex items-center gap-1.5">
                                            <label className="text-xs text-muted-foreground">
                                                Status:
                                            </label>
                                            <Select
                                                value={currentFilter}
                                                onValueChange={(v) =>
                                                    applyFilter('filter', v)
                                                }
                                            >
                                                <SelectTrigger className="h-8 w-32 text-xs">
                                                    <SelectValue placeholder="Read status" />
                                                </SelectTrigger>
                                                <SelectContent>
                                                    <SelectItem value="all">
                                                        All
                                                    </SelectItem>
                                                    <SelectItem value="unread">
                                                        Unread
                                                    </SelectItem>
                                                    <SelectItem value="read">
                                                        Read
                                                    </SelectItem>
                                                </SelectContent>
                                            </Select>
                                        </div>

                                        <div className="flex items-center gap-1.5">
                                            <label className="text-xs text-muted-foreground">
                                                Module:
                                            </label>
                                            <Select
                                                value={currentType}
                                                onValueChange={(v) =>
                                                    applyFilter('type', v)
                                                }
                                            >
                                                <SelectTrigger className="h-8 w-36 text-xs">
                                                    <SelectValue placeholder="Type" />
                                                </SelectTrigger>
                                                <SelectContent>
                                                    <SelectItem value="all">
                                                        All Modules
                                                    </SelectItem>
                                                    <SelectItem value="operations">
                                                        Operations
                                                    </SelectItem>
                                                    <SelectItem value="hr">
                                                        HR
                                                    </SelectItem>
                                                    <SelectItem value="governance">
                                                        Governance
                                                    </SelectItem>
                                                    <SelectItem value="sites">
                                                        Sites
                                                    </SelectItem>
                                                    <SelectItem value="incidents">
                                                        Incidents
                                                    </SelectItem>
                                                    <SelectItem value="system">
                                                        System
                                                    </SelectItem>
                                                </SelectContent>
                                            </Select>
                                        </div>

                                        <div className="relative ml-auto flex-1 sm:max-w-xs">
                                            <Search className="absolute top-1/2 left-2.5 h-3.5 w-3.5 -translate-y-1/2 text-muted-foreground" />
                                            <input
                                                type="text"
                                                placeholder="Search notifications..."
                                                value={searchText}
                                                onChange={(e) =>
                                                    setSearchText(
                                                        e.target.value,
                                                    )
                                                }
                                                className="h-8 w-full rounded-md border bg-background pr-3 pl-8 text-xs placeholder:text-muted-foreground focus:border-primary focus:ring-1 focus:ring-ring focus:outline-none"
                                            />
                                        </div>

                                        {(hasActiveFilters || searchText) && (
                                            <Button
                                                type="button"
                                                variant="link"
                                                size="sm"
                                                onClick={clearFilters}
                                                className="h-auto p-0 text-xs font-medium text-primary dark:text-primary"
                                            >
                                                Clear all
                                            </Button>
                                        )}
                                    </CardContent>
                                </Card>

                                {/* Notification cards */}
                                {filteredNotifData.length === 0 ? (
                                    <div className="flex flex-col items-center justify-center rounded-xl border-2 border-dashed border-muted-foreground/20 bg-muted/30 py-20">
                                        <div className="mb-4 flex h-20 w-20 items-center justify-center rounded-full bg-primary/10 dark:from-primary/30 dark:to-primary/30">
                                            <BellOff className="h-10 w-10 text-primary dark:text-primary" />
                                        </div>
                                        <h3 className="text-xl font-semibold text-foreground">
                                            All caught up!
                                        </h3>
                                        <p className="mt-2 max-w-sm text-center text-sm text-muted-foreground">
                                            No new notifications. We'll let you
                                            know when something needs your
                                            attention.
                                        </p>
                                        <Link
                                            href="/settings/notifications"
                                            className="mt-5 inline-flex items-center gap-1.5 text-sm font-medium text-primary hover:text-primary dark:text-primary dark:hover:text-primary/70"
                                        >
                                            Manage notification preferences
                                            <ArrowRight className="h-4 w-4" />
                                        </Link>
                                    </div>
                                ) : (
                                    <div className="space-y-2">
                                        {filteredNotifData.map((n) => {
                                            const isUnread = !n.read_at;
                                            const module = getModuleStyle(
                                                n.data?.module,
                                            );
                                            const Icon = module.icon;
                                            const title = notificationTitle(n);
                                            const body = notificationBody(n);
                                            const url =
                                                n.data?.url ||
                                                n.data?.action_url;
                                            const needsAck =
                                                !!n.data?.ack_required &&
                                                !n.acknowledged_at;

                                            return (
                                                <div
                                                    key={n.id}
                                                    role="button"
                                                    tabIndex={0}
                                                    className={`flex items-start gap-3 rounded-lg border-l-4 p-4 transition-colors hover:bg-accent/50 ${
                                                        isUnread
                                                            ? `bg-white dark:bg-card ${module.border} shadow-sm`
                                                            : `bg-muted/40 ${module.border} opacity-80`
                                                    }`}
                                                    onClick={() => {
                                                        if (isUnread)
                                                            markRead(n.id);
                                                    }}
                                                    onKeyDown={(e) => {
                                                        if (
                                                            e.key === 'Enter' &&
                                                            isUnread
                                                        )
                                                            markRead(n.id);
                                                    }}
                                                >
                                                    {/* Icon */}
                                                    <div
                                                        className={`mt-0.5 flex h-8 w-8 shrink-0 items-center justify-center rounded-full ${module.bg}`}
                                                    >
                                                        <Icon
                                                            className={`h-4 w-4 ${module.text}`}
                                                        />
                                                    </div>

                                                    {/* Content */}
                                                    <div className="min-w-0 flex-1">
                                                        <div className="flex items-start justify-between gap-2">
                                                            <div className="min-w-0 flex-1">
                                                                <p
                                                                    className={`text-sm ${isUnread ? 'font-semibold' : 'font-normal text-muted-foreground'}`}
                                                                >
                                                                    {title}
                                                                </p>
                                                                {body && (
                                                                    <p className="mt-0.5 line-clamp-2 text-xs text-muted-foreground">
                                                                        {body}
                                                                    </p>
                                                                )}
                                                                <div className="mt-1.5 flex flex-wrap items-center gap-2">
                                                                    <Badge
                                                                        variant="outline"
                                                                        className={`text-[10px] ${module.text}`}
                                                                    >
                                                                        {(
                                                                            n
                                                                                .data
                                                                                ?.module ??
                                                                            'system'
                                                                        )
                                                                            .charAt(
                                                                                0,
                                                                            )
                                                                            .toUpperCase() +
                                                                            (
                                                                                n
                                                                                    .data
                                                                                    ?.module ??
                                                                                'system'
                                                                            ).slice(
                                                                                1,
                                                                            )}
                                                                    </Badge>
                                                                    {needsAck && (
                                                                        <Badge className="bg-status-warning-bg text-status-warning dark:bg-status-warning-bg dark:text-status-warning">
                                                                            Acknowledge
                                                                            Required
                                                                        </Badge>
                                                                    )}
                                                                    {isUnread && (
                                                                        <span className="inline-block h-2 w-2 rounded-full bg-primary" />
                                                                    )}
                                                                </div>
                                                            </div>

                                                            {/* Right side */}
                                                            <div className="flex shrink-0 flex-col items-end gap-1.5">
                                                                <span className="text-xs text-muted-foreground">
                                                                    {relativeTime(
                                                                        n.created_at,
                                                                    )}
                                                                </span>
                                                                <div className="flex items-center gap-1.5">
                                                                    {needsAck && (
                                                                        <Button
                                                                            variant="outline"
                                                                            size="sm"
                                                                            className="h-7 border-status-warning/30 px-2 text-xs text-status-warning hover:bg-status-warning-bg"
                                                                            onClick={(
                                                                                e,
                                                                            ) => {
                                                                                e.stopPropagation();
                                                                                acknowledge(
                                                                                    n.id,
                                                                                );
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
                                                                            onClick={(
                                                                                e,
                                                                            ) =>
                                                                                e.stopPropagation()
                                                                            }
                                                                        >
                                                                            <Link
                                                                                href={
                                                                                    url
                                                                                }
                                                                            >
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
                                                variant={
                                                    link.active
                                                        ? 'default'
                                                        : 'outline'
                                                }
                                                size="sm"
                                                disabled={!link.url}
                                                asChild={!!link.url}
                                            >
                                                {link.url ? (
                                                    <Link
                                                        href={link.url}
                                                        preserveScroll
                                                        dangerouslySetInnerHTML={{
                                                            __html: link.label,
                                                        }}
                                                    />
                                                ) : (
                                                    <span
                                                        dangerouslySetInnerHTML={{
                                                            __html: link.label,
                                                        }}
                                                    />
                                                )}
                                            </Button>
                                        ))}
                                    </div>
                                )}
                            </TabsContent>

                            {/* ========== ANNOUNCEMENTS TAB ========== */}
                            <TabsContent value="announcements" className="mt-6">
                                {announcementList.length === 0 ? (
                                    <div className="flex flex-col items-center justify-center rounded-xl border-2 border-dashed border-muted-foreground/20 bg-muted/30 py-20">
                                        <div className="mb-4 flex h-20 w-20 items-center justify-center rounded-full bg-primary/10 dark:from-status-info/30 dark:to-primary/30">
                                            <Megaphone className="h-10 w-10 text-status-info dark:text-status-info" />
                                        </div>
                                        <h3 className="text-xl font-semibold text-foreground">
                                            No announcements
                                        </h3>
                                        <p className="mt-2 text-sm text-muted-foreground">
                                            Check back later for updates.
                                        </p>
                                    </div>
                                ) : (
                                    <div className="space-y-3">
                                        {announcementList.map((a) => {
                                            const isExpanded =
                                                expandedAnnouncement === a.id;
                                            return (
                                                <Card
                                                    key={a.id}
                                                    className="p-4"
                                                >
                                                    <div className="flex items-start justify-between gap-3">
                                                        <div className="min-w-0 flex-1">
                                                            <p className="font-semibold">
                                                                {a.title}
                                                            </p>
                                                            {a.body && (
                                                                <div className="mt-1.5">
                                                                    <p
                                                                        className={`text-sm text-muted-foreground ${isExpanded ? '' : 'line-clamp-2'}`}
                                                                    >
                                                                        {a.body}
                                                                    </p>
                                                                    {a.body
                                                                        .length >
                                                                        150 && (
                                                                        <Button
                                                                            type="button"
                                                                            variant="link"
                                                                            size="sm"
                                                                            onClick={() =>
                                                                                setExpandedAnnouncement(
                                                                                    isExpanded
                                                                                        ? null
                                                                                        : a.id,
                                                                                )
                                                                            }
                                                                            className="mt-1 h-auto p-0 text-xs text-primary dark:text-primary"
                                                                        >
                                                                            {isExpanded
                                                                                ? 'Show less'
                                                                                : 'Read more'}
                                                                        </Button>
                                                                    )}
                                                                </div>
                                                            )}
                                                            <div className="mt-2 flex flex-wrap items-center gap-2 text-xs text-muted-foreground">
                                                                {a.author_name && (
                                                                    <span>
                                                                        By{' '}
                                                                        {
                                                                            a.author_name
                                                                        }
                                                                    </span>
                                                                )}
                                                                {a.starts_at &&
                                                                    a.ends_at && (
                                                                        <span>
                                                                            {new Date(
                                                                                a.starts_at,
                                                                            ).toLocaleDateString()}{' '}
                                                                            &ndash;{' '}
                                                                            {new Date(
                                                                                a.ends_at,
                                                                            ).toLocaleDateString()}
                                                                        </span>
                                                                    )}
                                                                {a.created_at &&
                                                                    !a.starts_at && (
                                                                        <span>
                                                                            {new Date(
                                                                                a.created_at,
                                                                            ).toLocaleDateString()}
                                                                        </span>
                                                                    )}
                                                            </div>
                                                            {Array.isArray(
                                                                a.roles,
                                                            ) &&
                                                                a.roles.length >
                                                                    0 && (
                                                                    <div className="mt-2 flex flex-wrap gap-1">
                                                                        {a.roles.map(
                                                                            (
                                                                                role,
                                                                            ) => (
                                                                                <Badge
                                                                                    key={
                                                                                        role
                                                                                    }
                                                                                    variant="outline"
                                                                                    className="text-[10px]"
                                                                                >
                                                                                    {
                                                                                        role
                                                                                    }
                                                                                </Badge>
                                                                            ),
                                                                        )}
                                                                    </div>
                                                                )}
                                                        </div>
                                                    </div>
                                                </Card>
                                            );
                                        })}
                                    </div>
                                )}
                            </TabsContent>
                        </TabsRoot>
                    </div>

                    {/* Right sidebar - desktop only */}
                    <div className="hidden w-72 shrink-0 space-y-4 lg:block">
                        {/* Quick Settings */}
                        <Card>
                            <CardContent className="p-4">
                                <div className="mb-3 flex items-center gap-2">
                                    <Settings className="h-4 w-4 text-muted-foreground" />
                                    <h3 className="text-sm font-semibold">
                                        Quick Settings
                                    </h3>
                                </div>
                                <div className="flex items-center justify-between gap-3 rounded-lg border px-3 py-2.5">
                                    <div className="flex items-center gap-2">
                                        <BellOff className="h-4 w-4 text-muted-foreground" />
                                        <span className="text-xs font-medium">
                                            Do Not Disturb
                                        </span>
                                    </div>
                                    <Switch
                                        checked={doNotDisturb}
                                        onCheckedChange={setDoNotDisturb}
                                    />
                                </div>
                                <Link
                                    href="/settings/notifications"
                                    className="mt-3 inline-flex items-center gap-1 text-xs font-medium text-primary hover:text-primary dark:text-primary"
                                >
                                    All notification settings
                                    <ArrowRight className="h-3 w-3" />
                                </Link>
                            </CardContent>
                        </Card>

                        {/* Recent Activity by Module */}
                        <Card>
                            <CardContent className="p-4">
                                <h3 className="mb-3 text-sm font-semibold">
                                    Activity by Module
                                </h3>
                                <div className="space-y-2">
                                    {Object.entries(MODULE_COLOURS).map(
                                        ([mod, style]) => {
                                            const ModIcon = style.icon;
                                            const count =
                                                moduleCounts[mod] ?? 0;
                                            return (
                                                <div
                                                    key={mod}
                                                    className="flex items-center justify-between rounded-lg px-2.5 py-1.5 transition-colors hover:bg-muted/50"
                                                >
                                                    <div className="flex items-center gap-2">
                                                        <span
                                                            className={`inline-block h-2 w-2 rounded-full ${style.dot}`}
                                                        />
                                                        <span className="text-xs font-medium capitalize">
                                                            {mod}
                                                        </span>
                                                    </div>
                                                    <span
                                                        className={`text-xs font-semibold ${count > 0 ? style.text : 'text-muted-foreground'}`}
                                                    >
                                                        {count}
                                                    </span>
                                                </div>
                                            );
                                        },
                                    )}
                                </div>
                            </CardContent>
                        </Card>
                    </div>
                </div>
            </PageLayout>
        </AppLayout>
    );
}
