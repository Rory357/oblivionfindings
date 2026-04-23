import PageHeader from '@/components/page-header';
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
import AppLayout from '@/layouts/app-layout';
import { Head, Link, router } from '@inertiajs/react';
import { CalendarDays, FileText, Plus } from 'lucide-react';

const ANY = '__ANY__';
const nzd = new Intl.NumberFormat('en-NZ', {
    style: 'currency',
    currency: 'NZD',
});

type ClaimRow = {
    id: number;
    claim_reference?: string | null;
    status: string;
    total_amount: number;
    period_start?: string | null;
    period_end?: string | null;
    items_count?: number;
    client?: { id: number; first_name: string; last_name: string } | null;
    service_agreement?: { id: number; title: string; reference_number?: string | null } | null;
    submitter?: { id: number; name: string } | null;
};

type Props = {
    claims: {
        data: ClaimRow[];
        links?: Array<{ url: string | null; label: string; active: boolean }>;
    };
    clients: Array<{ id: number; first_name: string; last_name: string }>;
    filters: {
        status?: string | null;
        client_id?: string | null;
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

export default function FundingClaimsIndex({
    claims,
    clients,
    filters,
}: Props) {
    const rows = claims?.data ?? [];

    const updateFilters = (key: string, value: string | null) => {
        router.get('/operations/funding/claims', {
            ...filters,
            [key]: value,
        }, {
            preserveState: true,
            replace: true,
        });
    };

    return (
        <AppLayout>
            <Head title="Funding Claims" />
            <PageHeader
                title="Funding Claims"
                description="Track draft, submitted, and approved claims against live service agreements."
                backHref="/operations/funding"
            />
            <PageShell>
                <div className="flex flex-wrap items-center gap-2">
                    <Select
                        value={filters?.status ?? ANY}
                        onValueChange={(value) =>
                            updateFilters('status', value === ANY ? null : value)
                        }
                    >
                        <SelectTrigger className="h-9 w-[160px] text-xs">
                            <SelectValue placeholder="All Statuses" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value={ANY}>All Statuses</SelectItem>
                            {['draft', 'submitted', 'approved', 'rejected', 'paid'].map((status) => (
                                <SelectItem key={status} value={status}>
                                    {status}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>

                    <Select
                        value={filters?.client_id ?? ANY}
                        onValueChange={(value) =>
                            updateFilters('client_id', value === ANY ? null : value)
                        }
                    >
                        <SelectTrigger className="h-9 w-[220px] text-xs">
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

                    <div className="flex-1" />

                    <Button asChild size="sm">
                        <Link href="/operations/funding/claims/create">
                            <Plus className="mr-1.5 h-3.5 w-3.5" />
                            New Claim
                        </Link>
                    </Button>
                </div>

                <div className="mt-4 space-y-2">
                    {rows.length === 0 && (
                        <Card>
                            <CardContent className="flex flex-col items-center justify-center py-16">
                                <FileText className="mb-4 h-12 w-12 text-muted-foreground/30" />
                                <h2 className="text-lg font-semibold text-muted-foreground">
                                    No Funding Claims
                                </h2>
                                <p className="mt-1 text-sm text-muted-foreground/80">
                                    Draft claims will appear here once a funding period is assembled.
                                </p>
                            </CardContent>
                        </Card>
                    )}

                    {rows.map((claim) => (
                        <Card key={claim.id}>
                            <CardContent className="grid gap-3 p-4 md:grid-cols-[1.4fr,1fr,0.9fr,120px] md:items-center">
                                <div>
                                    <div className="flex items-center gap-2">
                                        <Link
                                            href={`/operations/funding/claims/${claim.id}`}
                                            className="text-sm font-semibold hover:underline"
                                        >
                                            {claim.claim_reference || `Claim #${claim.id}`}
                                        </Link>
                                        <Badge variant="outline" className="h-4 px-1.5 text-[9px] capitalize">
                                            {claim.status}
                                        </Badge>
                                    </div>
                                    <div className="mt-1 flex flex-wrap items-center gap-3 text-xs text-muted-foreground">
                                        {claim.client && (
                                            <span>{claim.client.first_name} {claim.client.last_name}</span>
                                        )}
                                        <span className="inline-flex items-center gap-1">
                                            <CalendarDays className="h-3 w-3" />
                                            {formatDate(claim.period_start)} - {formatDate(claim.period_end)}
                                        </span>
                                        {claim.service_agreement?.title && (
                                            <span>{claim.service_agreement.title}</span>
                                        )}
                                    </div>
                                </div>
                                <p className="text-sm text-muted-foreground">
                                    {claim.items_count ?? 0} items
                                </p>
                                <p className="text-sm font-semibold text-emerald-700 dark:text-emerald-400">
                                    {nzd.format(claim.total_amount ?? 0)}
                                </p>
                                <div className="flex justify-end">
                                    <Button asChild size="sm" variant="outline">
                                        <Link href={`/operations/funding/claims/${claim.id}`}>
                                            Open
                                        </Link>
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
