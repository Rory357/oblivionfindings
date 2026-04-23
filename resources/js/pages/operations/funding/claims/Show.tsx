import PageHeader from '@/components/page-header';
import PageShell from '@/components/page-shell';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import AppLayout from '@/layouts/app-layout';
import { Head, router } from '@inertiajs/react';
import { CalendarDays, CheckCircle2, Send } from 'lucide-react';

type ClaimItem = {
    id: number;
    description: string;
    quantity: number;
    unit_price: number;
    total_amount: number;
    service_date: string;
    ndis_line_item_code?: string | null;
};

type Claim = {
    id: number;
    claim_reference?: string | null;
    status: string;
    total_amount: number;
    period_start?: string | null;
    period_end?: string | null;
    submitted_at?: string | null;
    approved_at?: string | null;
    client?: { id: number; first_name: string; last_name: string } | null;
    service_agreement?: { id: number; title: string; reference_number?: string | null } | null;
    submitter?: { id: number; name: string } | null;
    approver?: { id: number; name: string } | null;
    items: ClaimItem[];
};

type Props = {
    claim: Claim;
};

function formatDate(value?: string | null): string {
    if (!value) return '-';

    return new Date(value).toLocaleDateString('en-NZ', {
        day: 'numeric',
        month: 'short',
        year: 'numeric',
    });
}

export default function FundingClaimShow({ claim }: Props) {
    const nzd = new Intl.NumberFormat('en-NZ', {
        style: 'currency',
        currency: 'NZD',
    });

    return (
        <AppLayout>
            <Head title="Funding Claim" />
            <PageHeader
                title={claim.claim_reference || `Funding Claim #${claim.id}`}
                description="Review the claim summary, line items, and approval state."
                backHref="/operations/funding/claims"
                actions={
                    <div className="flex items-center gap-2">
                        {claim.status === 'draft' && (
                            <Button
                                size="sm"
                                onClick={() =>
                                    router.post(`/operations/funding/claims/${claim.id}/submit`)
                                }
                            >
                                <Send className="mr-1.5 h-3.5 w-3.5" />
                                Submit Claim
                            </Button>
                        )}
                        {claim.status === 'submitted' && (
                            <Button
                                size="sm"
                                variant="outline"
                                onClick={() =>
                                    router.post(`/operations/funding/claims/${claim.id}/approve`)
                                }
                            >
                                <CheckCircle2 className="mr-1.5 h-3.5 w-3.5" />
                                Approve Claim
                            </Button>
                        )}
                    </div>
                }
            />
            <PageShell>
                <div className="grid gap-4 md:grid-cols-3">
                    <Card>
                        <CardHeader className="pb-2">
                            <CardTitle className="text-sm font-medium">Client</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <p className="text-sm font-semibold">
                                {claim.client
                                    ? `${claim.client.first_name} ${claim.client.last_name}`
                                    : '-'}
                            </p>
                        </CardContent>
                    </Card>
                    <Card>
                        <CardHeader className="pb-2">
                            <CardTitle className="text-sm font-medium">Agreement</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <p className="text-sm font-semibold">
                                {claim.service_agreement?.title ?? '-'}
                            </p>
                        </CardContent>
                    </Card>
                    <Card>
                        <CardHeader className="pb-2">
                            <CardTitle className="text-sm font-medium">Claim Status</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <Badge variant="outline" className="h-5 px-2 text-[10px] capitalize">
                                {claim.status}
                            </Badge>
                            <p className="mt-2 text-sm font-semibold text-emerald-700 dark:text-emerald-400">
                                {nzd.format(claim.total_amount ?? 0)}
                            </p>
                        </CardContent>
                    </Card>
                </div>

                <Card className="mt-4">
                    <CardContent className="grid gap-3 p-4 md:grid-cols-4">
                        <div>
                            <p className="text-xs uppercase tracking-wide text-muted-foreground">
                                Claim Window
                            </p>
                            <p className="mt-1 text-sm font-medium">
                                {formatDate(claim.period_start)} - {formatDate(claim.period_end)}
                            </p>
                        </div>
                        <div>
                            <p className="text-xs uppercase tracking-wide text-muted-foreground">
                                Submitted
                            </p>
                            <p className="mt-1 text-sm font-medium">
                                {formatDate(claim.submitted_at)}
                            </p>
                        </div>
                        <div>
                            <p className="text-xs uppercase tracking-wide text-muted-foreground">
                                Approved
                            </p>
                            <p className="mt-1 text-sm font-medium">
                                {formatDate(claim.approved_at)}
                            </p>
                        </div>
                        <div>
                            <p className="text-xs uppercase tracking-wide text-muted-foreground">
                                Raised By
                            </p>
                            <p className="mt-1 text-sm font-medium">
                                {claim.submitter?.name ?? '-'}
                            </p>
                        </div>
                    </CardContent>
                </Card>

                <Card className="mt-4">
                    <CardHeader>
                        <CardTitle className="text-base">Claim Items</CardTitle>
                    </CardHeader>
                    <CardContent className="space-y-2">
                        {claim.items.map((item) => (
                            <div
                                key={item.id}
                                className="grid gap-3 rounded-lg border p-4 md:grid-cols-[1.5fr,0.8fr,0.8fr,1fr]"
                            >
                                <div>
                                    <p className="text-sm font-semibold">{item.description}</p>
                                    <p className="mt-1 text-xs text-muted-foreground">
                                        <span className="inline-flex items-center gap-1">
                                            <CalendarDays className="h-3 w-3" />
                                            {formatDate(item.service_date)}
                                        </span>
                                        {item.ndis_line_item_code && (
                                            <span> • {item.ndis_line_item_code}</span>
                                        )}
                                    </p>
                                </div>
                                <p className="text-sm text-muted-foreground">
                                    Qty {item.quantity}
                                </p>
                                <p className="text-sm text-muted-foreground">
                                    {nzd.format(item.unit_price)}
                                </p>
                                <p className="text-sm font-semibold text-emerald-700 dark:text-emerald-400">
                                    {nzd.format(item.total_amount)}
                                </p>
                            </div>
                        ))}
                    </CardContent>
                </Card>
            </PageShell>
        </AppLayout>
    );
}
