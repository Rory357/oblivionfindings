import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router } from '@inertiajs/react';
import { ConfirmDialog, LedgerTabsFooter, formatMoney, useRowContextMenu, type RowCtxItem } from '@/components/finance';
import { PageHero, PageLayout } from '@/components/page';
import { Button } from '@/components/ui/button';
import { StatusBadge } from '@/components/ui/status-badge';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { ArrowLeftRight, Plus, TrendingUp, TrendingDown, Globe } from 'lucide-react';
import { useState } from 'react';

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
    { title: 'Finance', href: '/finance' },
    { title: 'FX Revaluations', href: '/finance/fx-revaluations' },
];

const formatDate = (dateStr: string) =>
    new Date(dateStr).toLocaleDateString('en-NZ', { day: 'numeric', month: 'short', year: 'numeric' });

export default function FxRevaluationsIndex({ revaluations }: PageProps) {
    const [postTarget, setPostTarget] = useState<Revaluation | null>(null);
    const [posting, setPosting] = useState(false);

    function confirmPost() {
        if (!postTarget) return;
        router.post(`/finance/fx-revaluations/${postTarget.id}/post`, {}, {
            onStart: () => setPosting(true),
            onFinish: () => setPosting(false),
            onSuccess: () => setPostTarget(null),
        });
    }

    // Compute KPI: total gain/loss across all revaluations on current page
    const totalGainLoss = revaluations.data.reduce((sum, r) => sum + Number(r.total_gain_loss), 0);
    const isGain = totalGainLoss > 0;
    const isLoss = totalGainLoss < 0;

    const postedCount = revaluations.data.filter((r) => r.status === 'posted').length;

    // Right-click row menu — mirrors the row's existing inline action (same guard).
    const rowMenu = useRowContextMenu();
    const rowMenuItems = (reval: Revaluation): RowCtxItem[] => {
        const items: RowCtxItem[] = [];
        if (reval.status === 'draft') {
            items.push({ kind: 'item', label: 'Post to GL', icon: ArrowLeftRight, onSelect: () => setPostTarget(reval) });
        }
        return items;
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="FX Revaluations" />

            <PageLayout
                hero={
                    <PageHero category="finance"
                        footer={<LedgerTabsFooter active="fx-revaluations" />}
                        icon={Globe}
                        title="FX Revaluations"
                        description="Calculate and post unrealised foreign exchange gain/loss adjustments"
                        stats={[
                            { label: 'Revaluations', value: revaluations.data.length },
                            { label: 'Posted', value: postedCount },
                            { label: 'Net gain/loss', value: formatMoney(totalGainLoss) },
                        ]}
                        actions={
                            <Link href="/finance/fx-revaluations/create">
                                <Button size="sm">
                                    <Plus className="mr-1.5 h-4 w-4" />
                                    New Revaluation
                                </Button>
                            </Link>
                        }
                    />
                }
            >
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
                                    {isLoss ? '(' : ''}{formatMoney(Math.abs(totalGainLoss))}{isLoss ? ')' : ''}
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
                                            const menuItems = rowMenuItems(reval);

                                            return (
                                                <tr
                                                    key={reval.id}
                                                    className="border-b last:border-0 hover:bg-muted/50"
                                                    onContextMenu={menuItems.length ? rowMenu.open(menuItems) : undefined}
                                                >
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
                                                        {formatMoney(Math.abs(gainLoss))}
                                                        {rowIsLoss ? ')' : ''}
                                                    </td>
                                                    <td className="py-3 pr-4">
                                                        <StatusBadge status={reval.status} />
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
                                                                onClick={() => setPostTarget(reval)}
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

                {rowMenu.element}
            </PageLayout>

            <ConfirmDialog
                open={!!postTarget}
                onOpenChange={(open) => !open && setPostTarget(null)}
                title="Post revaluation to the General Ledger?"
                description={
                    <>
                        This posts the FX revaluation dated{' '}
                        <span className="font-medium text-foreground">
                            {postTarget ? formatDate(postTarget.revaluation_date) : ''}
                        </span>{' '}
                        and creates the journal entry for the unrealised gain/loss. Once posted it
                        can&rsquo;t be undone.
                    </>
                }
                confirmLabel="Post to GL"
                processing={posting}
                onConfirm={confirmPost}
            />
        </AppLayout>
    );
}
