import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router } from '@inertiajs/react';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { ArrowLeftRight, Plus, TrendingUp, TrendingDown } from 'lucide-react';

type Revaluation = {
    id: number;
    revaluation_date: string;
    total_gain_loss: string;
    status: string;
    journal_number: string | null;
    notes: string | null;
    created_by_name: string | null;
    created_at: string;
};

type PaginatedData = {
    data: Revaluation[];
    links: { url: string | null; label: string; active: boolean }[];
    current_page: number;
    last_page: number;
};

type PageProps = {
    revaluations: PaginatedData;
};

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Finance', href: '/finance/dashboard' },
    { title: 'FX Revaluations', href: '/finance/fx-revaluations' },
];

const formatCurrency = (amount: number) =>
    new Intl.NumberFormat('en-NZ', { style: 'currency', currency: 'NZD' }).format(amount);

const formatDate = (dateStr: string) =>
    new Date(dateStr).toLocaleDateString('en-NZ', { day: 'numeric', month: 'short', year: 'numeric' });

const statusConfig: Record<string, { label: string; className: string }> = {
    draft: { label: 'Draft', className: 'bg-muted text-muted-foreground border-border' },
    posted: { label: 'Posted', className: 'bg-status-success-bg text-status-success border-status-success/30' },
    reversed: { label: 'Reversed', className: 'bg-status-critical-bg text-status-critical border-status-critical/30' },
};

export default function FxRevaluationsIndex({ revaluations }: PageProps) {
    function handlePost(id: number) {
        if (confirm('Are you sure you want to post this revaluation to the General Ledger? This will create a journal entry.')) {
            router.post(`/finance/fx-revaluations/${id}/post`);
        }
    }

    // Compute KPI: total gain/loss across all revaluations on current page
    const totalGainLoss = revaluations.data.reduce((sum, r) => sum + Number(r.total_gain_loss), 0);
    const isGain = totalGainLoss > 0;
    const isLoss = totalGainLoss < 0;

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="FX Revaluations" />

            <div className="mx-auto max-w-6xl space-y-6 p-6">
                <div className="flex items-center justify-between">
                    <div>
                        <h1 className="text-2xl font-bold tracking-tight">FX Revaluations</h1>
                        <p className="text-muted-foreground">
                            Calculate and post unrealised foreign exchange gain/loss adjustments
                        </p>
                    </div>
                    <Link href="/finance/fx-revaluations/create">
                        <Button>
                            <Plus className="mr-2 h-4 w-4" />
                            New Revaluation
                        </Button>
                    </Link>
                </div>

                {/* KPI Summary */}
                {revaluations.data.length > 0 && (
                    <Card>
                        <CardContent className="flex items-center gap-4 pt-6">
                            <div className={`flex h-10 w-10 shrink-0 items-center justify-center rounded-lg ${isGain ? 'bg-status-success' : isLoss ? 'bg-status-critical' : 'bg-muted'}`}>
                                {isGain ? (
                                    <TrendingUp className="h-5 w-5 text-status-success" />
                                ) : isLoss ? (
                                    <TrendingDown className="h-5 w-5 text-status-critical" />
                                ) : (
                                    <ArrowLeftRight className="h-5 w-5 text-muted-foreground" />
                                )}
                            </div>
                            <div>
                                <p className="text-sm text-muted-foreground">
                                    Total Unrealised {isGain ? 'Gain' : isLoss ? 'Loss' : 'Gain/Loss'}
                                </p>
                                <p className={`text-2xl font-bold font-mono tabular-nums ${isGain ? 'text-status-success' : isLoss ? 'text-status-critical' : 'text-foreground'}`}>
                                    {isLoss ? '(' : ''}{formatCurrency(Math.abs(totalGainLoss))}{isLoss ? ')' : ''}
                                </p>
                            </div>
                        </CardContent>
                    </Card>
                )}

                <Card>
                    <CardHeader>
                        <div className="flex items-center gap-2">
                            <ArrowLeftRight className="h-5 w-5 text-muted-foreground" />
                            <CardTitle>Revaluation History</CardTitle>
                        </div>
                    </CardHeader>
                    <CardContent>
                        <div className="overflow-x-auto">
                            <table className="w-full text-sm">
                                <thead>
                                    <tr className="border-b text-left text-muted-foreground">
                                        <th className="pb-3 pr-4 font-medium">Date</th>
                                        <th className="pb-3 pr-4 font-medium text-right">Gain / Loss</th>
                                        <th className="pb-3 pr-4 font-medium">Status</th>
                                        <th className="pb-3 pr-4 font-medium">Journal</th>
                                        <th className="pb-3 pr-4 font-medium">Created By</th>
                                        <th className="pb-3 font-medium">Notes</th>
                                        <th className="pb-3 font-medium text-right">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {revaluations.data.length === 0 ? (
                                        <tr>
                                            <td colSpan={7} className="py-8 text-center text-muted-foreground">
                                                No FX revaluations found. Create your first revaluation to calculate unrealised gain/loss.
                                            </td>
                                        </tr>
                                    ) : (
                                        revaluations.data.map((reval) => {
                                            const gainLoss = Number(reval.total_gain_loss);
                                            const rowIsGain = gainLoss > 0;
                                            const rowIsLoss = gainLoss < 0;
                                            const status = statusConfig[reval.status] ?? statusConfig.draft;

                                            return (
                                                <tr key={reval.id} className="border-b last:border-0 hover:bg-muted/50">
                                                    <td className="py-3 pr-4 font-medium">
                                                        {formatDate(reval.revaluation_date)}
                                                    </td>
                                                    <td
                                                        className={`py-3 pr-4 text-right font-mono font-semibold tabular-nums ${
                                                            rowIsGain
                                                                ? 'text-status-success'
                                                                : rowIsLoss
                                                                  ? 'text-status-critical'
                                                                  : ''
                                                        }`}
                                                    >
                                                        {rowIsLoss ? '(' : ''}
                                                        {formatCurrency(Math.abs(gainLoss))}
                                                        {rowIsLoss ? ')' : ''}
                                                    </td>
                                                    <td className="py-3 pr-4">
                                                        <Badge variant="outline" className={status.className}>
                                                            {status.label}
                                                        </Badge>
                                                    </td>
                                                    <td className="py-3 pr-4 font-mono text-sm text-muted-foreground">
                                                        {reval.journal_number ?? '-'}
                                                    </td>
                                                    <td className="py-3 pr-4 text-sm text-muted-foreground">
                                                        {reval.created_by_name ?? '-'}
                                                    </td>
                                                    <td className="py-3 pr-4 text-sm text-muted-foreground max-w-[200px] truncate">
                                                        {reval.notes ?? '-'}
                                                    </td>
                                                    <td className="py-3 text-right">
                                                        {reval.status === 'draft' && (
                                                            <Button
                                                                variant="outline"
                                                                size="sm"
                                                                onClick={() => handlePost(reval.id)}
                                                            >
                                                                Post to GL
                                                            </Button>
                                                        )}
                                                    </td>
                                                </tr>
                                            );
                                        })
                                    )}
                                </tbody>
                            </table>
                        </div>

                        {revaluations.last_page > 1 && (
                            <div className="mt-4 flex items-center justify-center gap-1">
                                {revaluations.links.map((link, i) => (
                                    <Button
                                        key={i}
                                        variant={link.active ? 'default' : 'outline'}
                                        size="sm"
                                        disabled={!link.url}
                                        onClick={() => link.url && router.visit(link.url)}
                                        dangerouslySetInnerHTML={{ __html: link.label }}
                                    />
                                ))}
                            </div>
                        )}
                    </CardContent>
                </Card>
            </div>
        </AppLayout>
    );
}
