import PageHeader from '@/components/page-header';
import PageShell from '@/components/page-shell';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import AppLayout from '@/layouts/app-layout';
import { Head, Link, router } from '@inertiajs/react';
import { Bell, Eye, Home, Mail, MessageSquare, Pencil, Search, Smartphone, Users } from 'lucide-react';

type PortalClient = {
    id: number;
    first_name: string;
    last_name: string;
    portal_enabled: boolean;
    notifications: {
        shift_updates: boolean;
        care_notes: boolean;
        incident_alerts: boolean;
        billing_updates: boolean;
        messages: boolean;
    };
    family_contacts_count: number;
};

type Props = {
    clients: {
        data: PortalClient[];
        links: any[];
        current_page: number;
        last_page: number;
        total: number;
    };
    filters: {
        q?: string;
    };
};

const NOTIFICATION_LABELS: Record<string, { label: string; icon: typeof Bell }> = {
    shift_updates: { label: 'Shift Updates', icon: Home },
    care_notes: { label: 'Care Notes', icon: MessageSquare },
    incident_alerts: { label: 'Incident Alerts', icon: Bell },
    billing_updates: { label: 'Billing', icon: Mail },
    messages: { label: 'Messages', icon: Smartphone },
};

export default function FamilyPortalIndex({ clients = { data: [], links: [], current_page: 1, last_page: 1, total: 0 }, filters = {} as any }: Props) {
    const updateFilters = (key: string, value: string | null) => {
        router.get('/operations/family-portal', { ...filters, [key]: value }, { preserveState: true, replace: true });
    };

    return (
        <AppLayout>
            <Head title="Family Portal" />
            <PageHeader
                title="Family Portal"
                description="Manage client portal access and notification settings for families."
                backHref="/operations"
            />
            <PageShell>
                {/* Search */}
                <div className="flex flex-wrap items-center gap-2">
                    <div className="relative flex-1">
                        <Search className="absolute left-2.5 top-2.5 h-3.5 w-3.5 text-muted-foreground" />
                        <Input
                            placeholder="Search clients..."
                            className="h-9 pl-8 text-sm"
                            defaultValue={filters?.q ?? ''}
                            onChange={(e) => updateFilters('q', e.target.value || null)}
                        />
                    </div>
                </div>

                {/* List */}
                <div className="mt-4 space-y-2">
                    {(clients?.data ?? []).length === 0 && (
                        <Card>
                            <CardContent className="flex flex-col items-center justify-center py-16">
                                <Users className="mb-4 h-12 w-12 text-muted-foreground/30" />
                                <h2 className="text-lg font-semibold text-muted-foreground">No Clients Found</h2>
                                <p className="mt-1 text-sm text-muted-foreground/80">Client portal settings will appear here.</p>
                            </CardContent>
                        </Card>
                    )}
                    {(clients?.data ?? []).map((client) => (
                        <Card key={client.id} className="transition-all hover:border-border hover:shadow-sm">
                            <CardContent className="p-4">
                                <div className="flex items-start gap-4">
                                    <div className="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-indigo-100 text-indigo-700 dark:bg-indigo-900/40 dark:text-indigo-300">
                                        <Users className="h-5 w-5" />
                                    </div>
                                    <div className="min-w-0 flex-1">
                                        <div className="flex items-center gap-2">
                                            <Link href={`/operations/family-portal/${client.id}`} className="text-sm font-semibold hover:underline">
                                                {client.first_name} {client.last_name}
                                            </Link>
                                            <Badge variant={client.portal_enabled ? 'default' : 'secondary'} className="h-4 px-1.5 text-[9px]">
                                                {client.portal_enabled ? 'Portal Active' : 'Portal Inactive'}
                                            </Badge>
                                            <span className="text-xs text-muted-foreground">
                                                {client.family_contacts_count} family contact{client.family_contacts_count !== 1 ? 's' : ''}
                                            </span>
                                        </div>
                                        {/* Notification toggles display */}
                                        <div className="mt-2 flex flex-wrap gap-1.5">
                                            {Object.entries(NOTIFICATION_LABELS).map(([key, { label, icon: Icon }]) => {
                                                const enabled = client.notifications[key as keyof typeof client.notifications];
                                                return (
                                                    <Badge
                                                        key={key}
                                                        variant={enabled ? 'default' : 'outline'}
                                                        className={`h-5 gap-1 px-2 text-[9px] ${!enabled ? 'opacity-40' : ''}`}
                                                    >
                                                        <Icon className="h-2.5 w-2.5" />
                                                        {label}
                                                    </Badge>
                                                );
                                            })}
                                        </div>
                                    </div>
                                    <div className="flex shrink-0 gap-1">
                                        <Button asChild size="sm" variant="ghost" className="h-7 w-7 p-0">
                                            <Link href={`/operations/family-portal/${client.id}`}>
                                                <Eye className="h-3.5 w-3.5" />
                                            </Link>
                                        </Button>
                                        <Button asChild size="sm" variant="ghost" className="h-7 w-7 p-0">
                                            <Link href={`/operations/family-portal/${client.id}/edit`}>
                                                <Pencil className="h-3.5 w-3.5" />
                                            </Link>
                                        </Button>
                                    </div>
                                </div>
                            </CardContent>
                        </Card>
                    ))}
                </div>

                {/* Pagination */}
                {(clients?.last_page ?? 1) > 1 && (
                    <div className="mt-4 flex items-center justify-center gap-1">
                        {(clients?.links ?? []).map((link: any, i: number) => (
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
