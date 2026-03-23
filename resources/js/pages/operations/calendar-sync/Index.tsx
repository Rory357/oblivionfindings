import PageHeader from '@/components/page-header';
import PageShell from '@/components/page-shell';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import AppLayout from '@/layouts/app-layout';
import { Head, Link, router } from '@inertiajs/react';
import { Calendar, CheckCircle2, Clock, Link2, Plus, RefreshCw, XCircle } from 'lucide-react';

type SyncConnection = {
    id: number;
    provider: string;
    account_name: string;
    status: string;
    last_synced_at: string | null;
    calendars_count: number;
};

type Props = {
    connections: {
        data: SyncConnection[];
        links: any[];
        current_page: number;
        last_page: number;
        total: number;
    };
};

const STATUS_VARIANTS: Record<string, 'default' | 'secondary' | 'destructive' | 'outline'> = {
    connected: 'default',
    syncing: 'secondary',
    error: 'destructive',
    disconnected: 'outline',
};

const STATUS_ICONS: Record<string, typeof CheckCircle2> = {
    connected: CheckCircle2,
    syncing: RefreshCw,
    error: XCircle,
    disconnected: Link2,
};

const PROVIDER_LABELS: Record<string, string> = {
    google: 'Google Calendar',
    outlook: 'Microsoft Outlook',
    apple: 'Apple iCloud',
    caldav: 'CalDAV',
};

function formatDate(d: string | null): string {
    if (!d) return 'Never';
    return new Date(d).toLocaleDateString('en-NZ', { day: 'numeric', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' });
}

export default function CalendarSyncIndex({ connections }: Props) {
    const triggerSync = (id: number) => {
        router.post(`/operations/calendar-sync/${id}/sync`, {}, { preserveState: true });
    };

    return (
        <AppLayout>
            <Head title="Calendar Sync" />
            <PageHeader
                title="Calendar Sync"
                description="Manage calendar synchronisation connections."
                backHref="/operations"
            />
            <PageShell>
                {/* Actions */}
                <div className="flex items-center gap-2">
                    <div className="flex-1" />
                    <Button asChild size="sm">
                        <Link href="/operations/calendar-sync/create">
                            <Plus className="mr-1.5 h-3.5 w-3.5" />
                            Add Connection
                        </Link>
                    </Button>
                </div>

                {/* List */}
                <div className="mt-4 space-y-2">
                    {connections.data.length === 0 && (
                        <Card>
                            <CardContent className="flex flex-col items-center justify-center py-16">
                                <Calendar className="mb-4 h-12 w-12 text-muted-foreground/30" />
                                <h2 className="text-lg font-semibold text-muted-foreground">No Calendar Connections</h2>
                                <p className="mt-1 text-sm text-muted-foreground/80">Add a calendar connection to sync shifts and schedules.</p>
                                <Button asChild size="sm" className="mt-4">
                                    <Link href="/operations/calendar-sync/create">Add Connection</Link>
                                </Button>
                            </CardContent>
                        </Card>
                    )}
                    {connections.data.map((conn) => {
                        const StatusIcon = STATUS_ICONS[conn.status] ?? Link2;
                        return (
                            <Card key={conn.id} className="transition-all hover:border-border hover:shadow-sm">
                                <CardContent className="flex items-center gap-4 p-4">
                                    <div className="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-indigo-100 text-indigo-700 dark:bg-indigo-900/40 dark:text-indigo-300">
                                        <Calendar className="h-5 w-5" />
                                    </div>
                                    <div className="min-w-0 flex-1">
                                        <div className="flex items-center gap-2">
                                            <span className="text-sm font-semibold">{conn.account_name}</span>
                                            <Badge variant="outline" className="h-4 px-1.5 text-[9px]">
                                                {PROVIDER_LABELS[conn.provider] ?? conn.provider}
                                            </Badge>
                                            <Badge variant={STATUS_VARIANTS[conn.status] ?? 'outline'} className="h-4 gap-0.5 px-1.5 text-[9px] capitalize">
                                                <StatusIcon className="h-2.5 w-2.5" /> {conn.status}
                                            </Badge>
                                        </div>
                                        <div className="mt-0.5 flex items-center gap-3 text-xs text-muted-foreground">
                                            <span>{conn.calendars_count} calendar{conn.calendars_count !== 1 ? 's' : ''}</span>
                                            <span className="flex items-center gap-1">
                                                <Clock className="h-3 w-3" />
                                                Last synced: {formatDate(conn.last_synced_at)}
                                            </span>
                                        </div>
                                    </div>
                                    <div className="flex shrink-0 gap-1">
                                        <Button size="sm" variant="ghost" className="h-7 px-2 text-xs" onClick={() => triggerSync(conn.id)}>
                                            <RefreshCw className="mr-1 h-3 w-3" /> Sync Now
                                        </Button>
                                    </div>
                                </CardContent>
                            </Card>
                        );
                    })}
                </div>

                {/* Pagination */}
                {connections.last_page > 1 && (
                    <div className="mt-4 flex items-center justify-center gap-1">
                        {connections.links.map((link: any, i: number) => (
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
