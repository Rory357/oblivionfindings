import AppLayout from '@/layouts/app-layout';
import PageShell from '@/components/page-shell';
import PageHeader from '@/components/page-header';
import { Head, router } from '@inertiajs/react';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { DollarSign, Plus, CheckCircle2 } from 'lucide-react';

type Bonus = { id: number; employee_name: string; bonus_type: string; amount: number; currency: string; reason: string | null; payment_date: string; status: string };
type Props = {
    bonuses: { data: Bonus[]; current_page: number; last_page: number; total: number; links: any[] };
    can: { manage?: boolean };
};

const breadcrumbs = [{ title: 'HR', href: '/hr' }, { title: 'Compensation', href: '/hr/compensation/bands' }, { title: 'Bonuses', href: '/hr/compensation/bonuses' }];
const statusConfig: Record<string, { className: string; label: string }> = {
    pending: { className: 'border-yellow-500/30 text-yellow-400 bg-yellow-500/10', label: 'Pending' },
    approved: { className: 'border-blue-500/30 text-blue-400 bg-blue-500/10', label: 'Approved' },
    paid: { className: 'border-emerald-500/30 text-emerald-400 bg-emerald-500/10', label: 'Paid' },
    cancelled: { className: 'border-slate-500/30 text-slate-400', label: 'Cancelled' },
};

export default function BonusIndex({ bonuses, can }: Props) {
    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Bonus Payments" />
            <PageShell>
                <PageHeader title="Bonus Payments" description="Track and manage employee bonuses and incentives." />
                <Card>
                    <CardHeader><CardTitle>All Bonuses ({bonuses.total})</CardTitle></CardHeader>
                    <CardContent className="p-0">
                        {bonuses.data.length === 0 ? (
                            <div className="text-center py-12 text-muted-foreground"><DollarSign className="h-12 w-12 mx-auto mb-3 opacity-50" /><p>No bonus payments recorded.</p></div>
                        ) : (
                            <table className="w-full text-sm">
                                <thead className="border-b bg-muted/50"><tr><th className="px-4 py-3 text-left">Employee</th><th className="px-4 py-3 text-left">Type</th><th className="px-4 py-3 text-right">Amount</th><th className="px-4 py-3 text-left">Date</th><th className="px-4 py-3 text-left">Reason</th><th className="px-4 py-3 text-center">Status</th>{can.manage && <th className="px-4 py-3 text-right">Actions</th>}</tr></thead>
                                <tbody>{bonuses.data.map(b => {
                                    const sc = statusConfig[b.status] || statusConfig.pending;
                                    return (
                                        <tr key={b.id} className="border-b hover:bg-muted/50">
                                            <td className="px-4 py-3 font-medium">{b.employee_name}</td>
                                            <td className="px-4 py-3 text-muted-foreground capitalize">{b.bonus_type.replace('_', ' ')}</td>
                                            <td className="px-4 py-3 text-right font-medium">${b.amount.toFixed(2)}</td>
                                            <td className="px-4 py-3 text-muted-foreground">{b.payment_date}</td>
                                            <td className="px-4 py-3 text-muted-foreground max-w-[200px] truncate">{b.reason || '-'}</td>
                                            <td className="px-4 py-3 text-center"><Badge variant="outline" className={sc.className}>{sc.label}</Badge></td>
                                            {can.manage && <td className="px-4 py-3 text-right">{b.status === 'pending' && <Button variant="outline" size="sm" onClick={() => router.post(`/hr/compensation/bonuses/${b.id}/approve`)}><CheckCircle2 className="h-3 w-3 mr-1" />Approve</Button>}</td>}
                                        </tr>
                                    );
                                })}</tbody>
                            </table>
                        )}
                    </CardContent>
                </Card>
            </PageShell>
        </AppLayout>
    );
}
