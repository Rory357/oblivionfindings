import { DonutChart, OPS_COLORS } from '@/components/ops-stat-card';
import PageHeader from '@/components/page-header';
import PageShell from '@/components/page-shell';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import AppLayout from '@/layouts/app-layout';
import { Head, Link } from '@inertiajs/react';
import { CalendarDays, DollarSign, Pencil } from 'lucide-react';

type LineItem = {
    id: number;
    item_number: string | null;
    description: string;
    unit_price: number;
    quantity: number | null;
    unit: string;
    budget_allocated: number;
    budget_used: number;
    category: string | null;
    ndis_line_item_code: string | null;
};

type Props = {
    agreement: {
        id: number;
        title: string;
        reference_number: string | null;
        status: string;
        agreement_type: string;
        funding_body: string | null;
        funding_reference: string | null;
        starts_at: string | null;
        ends_at: string | null;
        total_budget: number;
        budget_used: number;
        budget_remaining: number;
        budget_utilisation_percent: number;
        hourly_rate: number | null;
        daily_rate: number | null;
        terms: string | null;
        notes: string | null;
        signed_at: string | null;
        signed_by: string | null;
        client: { id: number; first_name: string; last_name: string } | null;
        creator: { id: number; name: string } | null;
        line_items: LineItem[];
        funding_claims_count: number;
    };
};

function formatCurrency(n: number): string {
    return new Intl.NumberFormat('en-NZ', { style: 'currency', currency: 'NZD', minimumFractionDigits: 2 }).format(n);
}

function formatDate(d: string | null): string {
    if (!d) return '-';
    return new Date(d).toLocaleDateString('en-NZ', { day: 'numeric', month: 'short', year: 'numeric' });
}

export default function ServiceAgreementShow({ agreement: ag }: Props) {
    const utilPct = ag.budget_utilisation_percent;

    return (
        <AppLayout>
            <Head title={ag.title} />
            <PageHeader title={ag.title} description={ag.client ? `${ag.client.first_name} ${ag.client.last_name}` : ''} backHref="/operations/service-agreements" />
            <PageShell>
                {/* Header */}
                <div className="flex flex-wrap items-center gap-2">
                    <Badge variant={ag.status === 'active' ? 'default' : 'outline'} className="capitalize">{ag.status}</Badge>
                    <Badge variant="outline">{ag.agreement_type.toUpperCase()}</Badge>
                    {ag.reference_number && <span className="text-xs text-muted-foreground">#{ag.reference_number}</span>}
                    {ag.funding_body && <span className="text-xs text-muted-foreground">{ag.funding_body}</span>}
                    {ag.starts_at && (
                        <span className="flex items-center gap-1 text-xs text-muted-foreground">
                            <CalendarDays className="h-3 w-3" /> {formatDate(ag.starts_at)} — {formatDate(ag.ends_at)}
                        </span>
                    )}
                    <div className="ml-auto">
                        <Button asChild size="sm" variant="outline">
                            <Link href={`/operations/service-agreements/${ag.id}/edit`}><Pencil className="mr-1.5 h-3.5 w-3.5" /> Edit</Link>
                        </Button>
                    </div>
                </div>

                {/* Budget + Line Items */}
                <div className="mt-6 grid gap-4 lg:grid-cols-3">
                    {/* Budget gauge */}
                    <Card>
                        <CardHeader className="pb-2"><CardTitle className="text-sm font-medium">Budget</CardTitle></CardHeader>
                        <CardContent>
                            <div className="flex flex-col items-center gap-3">
                                <DonutChart
                                    segments={[
                                        { label: 'Used', value: ag.budget_used, color: utilPct > 90 ? OPS_COLORS.danger : utilPct > 70 ? OPS_COLORS.warning : OPS_COLORS.primary },
                                        { label: 'Remaining', value: ag.budget_remaining, color: '#e2e8f0' },
                                    ]}
                                    centerValue={`${utilPct}%`}
                                    centerLabel="Used"
                                    size={130}
                                    strokeWidth={16}
                                />
                                <div className="w-full space-y-1 text-xs">
                                    <div className="flex justify-between"><span className="text-muted-foreground">Total Budget</span><span className="font-medium">{formatCurrency(ag.total_budget)}</span></div>
                                    <div className="flex justify-between"><span className="text-muted-foreground">Used</span><span className="font-medium">{formatCurrency(ag.budget_used)}</span></div>
                                    <div className="flex justify-between"><span className="text-muted-foreground">Remaining</span><span className="font-medium text-emerald-600">{formatCurrency(ag.budget_remaining)}</span></div>
                                    {ag.hourly_rate && <div className="flex justify-between"><span className="text-muted-foreground">Hourly Rate</span><span>{formatCurrency(ag.hourly_rate)}</span></div>}
                                    {ag.daily_rate && <div className="flex justify-between"><span className="text-muted-foreground">Daily Rate</span><span>{formatCurrency(ag.daily_rate)}</span></div>}
                                </div>
                            </div>
                        </CardContent>
                    </Card>

                    {/* Line Items */}
                    <Card className="lg:col-span-2">
                        <CardHeader className="pb-2"><CardTitle className="text-sm font-medium">Line Items ({ag.line_items.length})</CardTitle></CardHeader>
                        <CardContent>
                            {ag.line_items.length === 0 ? (
                                <p className="py-4 text-center text-xs text-muted-foreground">No line items added yet.</p>
                            ) : (
                                <div className="space-y-2">
                                    <div className="grid grid-cols-12 gap-2 text-[10px] font-medium uppercase tracking-wider text-muted-foreground">
                                        <div className="col-span-4">Description</div>
                                        <div className="col-span-2 text-right">Unit Price</div>
                                        <div className="col-span-1 text-center">Unit</div>
                                        <div className="col-span-2 text-right">Allocated</div>
                                        <div className="col-span-2 text-right">Used</div>
                                        <div className="col-span-1 text-right">%</div>
                                    </div>
                                    {ag.line_items.map((item) => {
                                        const itemPct = item.budget_allocated > 0 ? Math.round((item.budget_used / item.budget_allocated) * 100) : 0;
                                        return (
                                            <div key={item.id} className="grid grid-cols-12 items-center gap-2 rounded-md border px-2 py-1.5">
                                                <div className="col-span-4">
                                                    <div className="text-xs font-medium">{item.description}</div>
                                                    {item.ndis_line_item_code && <div className="text-[10px] text-muted-foreground">{item.ndis_line_item_code}</div>}
                                                </div>
                                                <div className="col-span-2 text-right text-xs tabular-nums">{formatCurrency(item.unit_price)}</div>
                                                <div className="col-span-1 text-center text-[10px] text-muted-foreground">{item.unit}</div>
                                                <div className="col-span-2 text-right text-xs tabular-nums">{formatCurrency(item.budget_allocated)}</div>
                                                <div className="col-span-2 text-right text-xs tabular-nums">{formatCurrency(item.budget_used)}</div>
                                                <div className="col-span-1 text-right text-xs tabular-nums">{itemPct}%</div>
                                            </div>
                                        );
                                    })}
                                </div>
                            )}
                        </CardContent>
                    </Card>
                </div>

                {/* Terms & Notes */}
                {(ag.terms || ag.notes) && (
                    <div className="mt-4 grid gap-4 md:grid-cols-2">
                        {ag.terms && (
                            <Card>
                                <CardHeader className="pb-2"><CardTitle className="text-sm font-medium">Terms & Conditions</CardTitle></CardHeader>
                                <CardContent><p className="whitespace-pre-wrap text-xs">{ag.terms}</p></CardContent>
                            </Card>
                        )}
                        {ag.notes && (
                            <Card>
                                <CardHeader className="pb-2"><CardTitle className="text-sm font-medium">Notes</CardTitle></CardHeader>
                                <CardContent><p className="whitespace-pre-wrap text-xs">{ag.notes}</p></CardContent>
                            </Card>
                        )}
                    </div>
                )}
            </PageShell>
        </AppLayout>
    );
}
