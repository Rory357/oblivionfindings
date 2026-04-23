import PageHeader from '@/components/page-header';
import PageShell from '@/components/page-shell';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import AppLayout from '@/layouts/app-layout';
import { Head, router } from '@inertiajs/react';
import { CalendarDays, DollarSign, FileText, UserRound } from 'lucide-react';

const ANY = '__ANY__';
const nzd = new Intl.NumberFormat('en-NZ', {
    style: 'currency',
    currency: 'NZD',
});

type BillingEntry = {
    id: number;
    service_date: string;
    status: string;
    amount: number;
    notes?: string | null;
    client?: { id: number; first_name: string; last_name: string } | null;
    staff?: { id: number; name: string } | null;
    service_agreement?: { id: number; title: string } | null;
};

type Props = {
    entries: {
        data: BillingEntry[];
        links?: Array<{ url: string | null; label: string; active: boolean }>;
        current_page?: number;
        last_page?: number;
        total?: number;
    };
    clients: Array<{ id: number; first_name: string; last_name: string }>;
    filters: {
        client_id?: string | null;
        status?: string | null;
        date_from?: string | null;
        date_to?: string | null;
    };
};

function formatDate(value?: string | null): string {
    if (!value) return '-';

    return new Date(value).toLocaleDateString('en-NZ', {
        day: 'numeric',
        month: 'short',
        year: 'numeric',
    });
}

export default function BillingEntriesPage({
    entries,
    clients,
    filters,
}: Props) {
    const rows = entries?.data ?? [];

    const updateFilters = (key: string, value: string | null) => {
        router.get('/operations/billing/entries', {
            ...filters,
            [key]: value,
        }, {
            preserveState: true,
            replace: true,
        });
    };

    return (
        <AppLayout>
            <Head title="Billing Entries" />
            <PageHeader
                title="Billing Entries"
                description="Review generated billing rows by client, service date, and status."
                backHref="/operations/billing"
            />
            <PageShell>
                <div className="grid gap-2 md:grid-cols-4">
                    <Select
                        value={filters?.client_id ?? ANY}
                        onValueChange={(value) =>
                            updateFilters('client_id', value === ANY ? null : value)
                        }
                    >
                        <SelectTrigger className="h-9 text-xs">
                            <SelectValue placeholder="All Clients" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value={ANY}>All Clients</SelectItem>
                            {clients.map((client) => (
                                <SelectItem key={client.id} value={String(client.id)}>
                                    {client.first_name} {client.last_name}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>

                    <Select
                        value={filters?.status ?? ANY}
                        onValueChange={(value) =>
                            updateFilters('status', value === ANY ? null : value)
                        }
                    >
                        <SelectTrigger className="h-9 text-xs">
                            <SelectValue placeholder="All Statuses" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value={ANY}>All Statuses</SelectItem>
                            {['pending', 'approved', 'billed', 'paid', 'cancelled'].map((status) => (
                                <SelectItem key={status} value={status}>
                                    {status}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>

                    <Input
                        type="date"
                        className="h-9 text-xs"
                        value={filters?.date_from ?? ''}
                        onChange={(event) =>
                            updateFilters('date_from', event.target.value || null)
                        }
                    />

                    <Input
                        type="date"
                        className="h-9 text-xs"
                        value={filters?.date_to ?? ''}
                        onChange={(event) =>
                            updateFilters('date_to', event.target.value || null)
                        }
                    />
                </div>

                <div className="mt-4 space-y-2">
                    {rows.length === 0 && (
                        <Card>
                            <CardContent className="flex flex-col items-center justify-center py-16">
                                <FileText className="mb-4 h-12 w-12 text-muted-foreground/30" />
                                <h2 className="text-lg font-semibold text-muted-foreground">
                                    No Billing Entries
                                </h2>
                                <p className="mt-1 text-sm text-muted-foreground/80">
                                    Approved billable activity will surface here once it has been generated.
                                </p>
                            </CardContent>
                        </Card>
                    )}

                    {rows.map((entry) => (
                        <Card key={entry.id}>
                            <CardContent className="grid gap-3 p-4 md:grid-cols-[1.3fr,1fr,1fr,0.8fr] md:items-center">
                                <div>
                                    <div className="flex items-center gap-2">
                                        <p className="text-sm font-semibold">
                                            {entry.client
                                                ? `${entry.client.first_name} ${entry.client.last_name}`
                                                : `Entry #${entry.id}`}
                                        </p>
                                        <Badge variant="outline" className="h-4 px-1.5 text-[9px] capitalize">
                                            {entry.status}
                                        </Badge>
                                    </div>
                                    <div className="mt-1 flex flex-wrap items-center gap-3 text-xs text-muted-foreground">
                                        <span className="inline-flex items-center gap-1">
                                            <CalendarDays className="h-3 w-3" />
                                            {formatDate(entry.service_date)}
                                        </span>
                                        {entry.service_agreement?.title && (
                                            <span>{entry.service_agreement.title}</span>
                                        )}
                                        {entry.notes && <span>{entry.notes}</span>}
                                    </div>
                                </div>
                                <p className="inline-flex items-center gap-1 text-sm text-muted-foreground">
                                    <UserRound className="h-3.5 w-3.5" />
                                    {entry.staff?.name ?? '-'}
                                </p>
                                <p className="inline-flex items-center gap-1 text-sm font-semibold text-emerald-700 dark:text-emerald-400">
                                    <DollarSign className="h-3.5 w-3.5" />
                                    {nzd.format(entry.amount ?? 0)}
                                </p>
                                <div className="flex justify-end">
                                    <Button
                                        size="sm"
                                        variant="outline"
                                        onClick={() =>
                                            router.get('/operations/billing', {
                                                status: entry.status,
                                            })
                                        }
                                    >
                                        View in Billing
                                    </Button>
                                </div>
                            </CardContent>
                        </Card>
                    ))}
                </div>
            </PageShell>
        </AppLayout>
    );
}
