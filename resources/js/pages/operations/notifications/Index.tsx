import PageShell from '@/components/page-shell';
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
import { PageHero } from '@/components/page';
import AppLayout from '@/layouts/app-layout';
import { Head, router } from '@inertiajs/react';
import { AlertTriangle, Bell, BellOff, CalendarDays, CheckCircle2, Info, Mail, MessageSquare } from 'lucide-react';

const ANY = '__ANY__';

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
        links: any[];
        current_page: number;
        last_page: number;
        total: number;
    };
    filters: {
        type?: string;
    };
};

const TYPE_CONFIG: Record<string, { icon: typeof Bell; color: string }> = {
    info: { icon: Info, color: 'bg-status-info-bg text-status-info dark:bg-status-info-bg dark:text-status-info' },
    alert: { icon: AlertTriangle, color: 'bg-status-warning-bg text-status-warning dark:bg-status-warning-bg dark:text-status-warning' },
    success: { icon: CheckCircle2, color: 'bg-status-success-bg text-status-success dark:bg-status-success-bg dark:text-status-success' },
    message: { icon: MessageSquare, color: 'bg-primary/10 text-primary dark:bg-primary/40 dark:text-primary/70' },
    reminder: { icon: CalendarDays, color: 'bg-primary/10 text-primary dark:bg-primary/40 dark:text-primary/70' },
    email: { icon: Mail, color: 'bg-status-info-bg text-status-info dark:bg-status-info-bg dark:text-status-info' },
};

function formatDate(d: string | null): string {
    if (!d) return '-';
    return new Date(d).toLocaleDateString('en-NZ', { day: 'numeric', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' });
}

export default function NotificationsIndex({ notifications = { data: [], links: [], current_page: 1, last_page: 1, total: 0 }, filters = {} as any }: Props) {
    const updateFilters = (key: string, value: string | null) => {
        router.get('/operations/notifications', { ...filters, [key]: value }, { preserveState: true, replace: true });
    };

    const markRead = (id: number) => {
        router.post(`/operations/notifications/${id}/read`, {}, { preserveState: true });
    };

    const markAllRead = () => {
        router.post('/operations/notifications/read-all', {}, { preserveState: true });
    };

    return (
        <AppLayout>
            <Head title="Notifications" />
            <PageHero
                icon={Bell}
                title="Notifications"
                description="View and manage your notifications."
                stats={[
                    { label: 'Total', value: notifications?.total ?? 0 },
                    {
                        label: 'Unread',
                        value: (notifications?.data ?? []).filter((n) => !n.is_read).length,
                    },
                ]}
            />
            <PageShell>
                {/* Filters + Actions */}
                <div className="flex flex-wrap items-center gap-2">
                    <Select value={filters?.type ?? ANY} onValueChange={(v) => updateFilters('type', v === ANY ? null : v)}>
                        <SelectTrigger className="h-9 w-[140px] text-xs">
                            <SelectValue placeholder="Type" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value={ANY}>All Types</SelectItem>
                            <SelectItem value="info">Info</SelectItem>
                            <SelectItem value="alert">Alert</SelectItem>
                            <SelectItem value="success">Success</SelectItem>
                            <SelectItem value="message">Message</SelectItem>
                            <SelectItem value="reminder">Reminder</SelectItem>
                            <SelectItem value="email">Email</SelectItem>
                        </SelectContent>
                    </Select>
                    <div className="flex-1" />
                    <Button size="sm" variant="outline" onClick={markAllRead}>
                        <CheckCircle2 className="mr-1.5 h-3.5 w-3.5" />
                        Mark All Read
                    </Button>
                </div>

                {/* List */}
                <div className="mt-4 space-y-2">
                    {(notifications?.data ?? []).length === 0 && (
                        <Card>
                            <CardContent className="flex flex-col items-center justify-center py-16">
                                <BellOff className="mb-4 h-12 w-12 text-muted-foreground/30" />
                                <h2 className="text-lg font-semibold text-muted-foreground">No Notifications</h2>
                                <p className="mt-1 text-sm text-muted-foreground/80">You're all caught up!</p>
                            </CardContent>
                        </Card>
                    )}
                    {(notifications?.data ?? []).map((notif) => {
                        const typeConf = TYPE_CONFIG[notif.type] ?? TYPE_CONFIG.info;
                        const Icon = typeConf.icon;
                        return (
                            <Card key={notif.id} className={`transition-all hover:border-border hover:shadow-sm ${!notif.is_read ? 'border-l-2 border-l-indigo-500 bg-primary/10/30 dark:bg-primary/10' : ''}`}>
                                <CardContent className="flex items-center gap-4 p-4">
                                    <div className={`flex h-10 w-10 shrink-0 items-center justify-center rounded-xl ${typeConf.color}`}>
                                        <Icon className="h-5 w-5" />
                                    </div>
                                    <div className="min-w-0 flex-1">
                                        <div className="flex items-center gap-2">
                                            <span className={`text-sm ${!notif.is_read ? 'font-semibold' : 'font-medium'}`}>
                                                {notif.title}
                                            </span>
                                            <Badge variant="outline" className="h-4 px-1.5 text-[9px] capitalize">
                                                {notif.type}
                                            </Badge>
                                            {!notif.is_read && (
                                                <span className="h-2 w-2 rounded-full bg-primary" />
                                            )}
                                        </div>
                                        <p className="mt-0.5 text-xs text-muted-foreground line-clamp-1">{notif.body}</p>
                                        <span className="mt-0.5 text-[10px] text-muted-foreground/60">{formatDate(notif.created_at)}</span>
                                    </div>
                                    <div className="flex shrink-0 gap-1">
                                        {!notif.is_read && (
                                            <Button size="sm" variant="ghost" className="h-7 px-2 text-xs" onClick={() => markRead(notif.id)}>
                                                <CheckCircle2 className="mr-1 h-3 w-3" /> Mark Read
                                            </Button>
                                        )}
                                    </div>
                                </CardContent>
                            </Card>
                        );
                    })}
                </div>

                {/* Pagination */}
                {(notifications?.last_page ?? 1) > 1 && (
                    <div className="mt-4 flex items-center justify-center gap-1">
                        {(notifications?.links ?? []).map((link: any, i: number) => (
                            <Button
                                key={i}
                                size="sm"
                                variant={link.active ? 'default' : 'outline'}
                                className="h-7 min-w-[28px] px-2 text-xs"
                                disabled={!link.url}
                                onClick={() => link.url && router.get(link.url, {}, { preserveState: true })}
                                dangerouslySetInnerHTML={{ __html: link.label }}
                            />
                        ))}
                    </div>
                )}
            </PageShell>
        </AppLayout>
    );
}
