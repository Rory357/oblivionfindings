import PageHeader from '@/components/page-header';
import PageShell from '@/components/page-shell';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import AppLayout from '@/layouts/app-layout';
import { Head, router } from '@inertiajs/react';
import { AlertTriangle, CheckCircle, Clock, TrendingUp } from 'lucide-react';

type PrnAdmin = {
    id: number;
    administered_at: string | null;
    status: string;
    reason: string | null;
    notes: string | null;
    client: { id: number; first_name: string; last_name: string } | null;
    medication: { id: number; name: string; dosage: string; max_per_day: string | null; indication: string | null } | null;
    administered_by: { id: number; name: string } | null;
};

type PendingReview = {
    id: number;
    administered_at: string | null;
    client: { id: number; first_name: string; last_name: string } | null;
    medication: { id: number; name: string } | null;
};

type Props = {
    administrations: { data: PrnAdmin[]; links: any; meta?: any };
    pendingReviews: PendingReview[];
    stats: {
        total_given_period: number;
        effectiveness_reviews_pending: number;
        near_limit_medications: number;
    };
    dateFrom: string;
    dateTo: string;
};

export default function PrnRecords({ administrations, pendingReviews, stats, dateFrom, dateTo }: Props) {
    return (
        <AppLayout>
            <Head title="eMAR - PRN Records" />
            <PageHeader title="PRN Records" description="As-needed medication administration records and effectiveness tracking." backHref="/emar" />
            <PageShell>
                {/* Stats */}
                <div className="mb-6 grid gap-4 sm:grid-cols-3">
                    <Card>
                        <CardContent className="flex items-center gap-3 p-4">
                            <div className="flex h-10 w-10 items-center justify-center rounded-xl bg-blue-100 text-blue-700 dark:bg-blue-900/40 dark:text-blue-300">
                                <TrendingUp className="h-5 w-5" />
                            </div>
                            <div>
                                <p className="text-2xl font-bold">{stats.total_given_period}</p>
                                <p className="text-xs text-muted-foreground">PRN Given (Period)</p>
                            </div>
                        </CardContent>
                    </Card>
                    <Card>
                        <CardContent className="flex items-center gap-3 p-4">
                            <div className="flex h-10 w-10 items-center justify-center rounded-xl bg-amber-100 text-amber-700 dark:bg-amber-900/40 dark:text-amber-300">
                                <Clock className="h-5 w-5" />
                            </div>
                            <div>
                                <p className="text-2xl font-bold">{stats.effectiveness_reviews_pending}</p>
                                <p className="text-xs text-muted-foreground">Effectiveness Reviews Pending</p>
                            </div>
                        </CardContent>
                    </Card>
                    <Card>
                        <CardContent className="flex items-center gap-3 p-4">
                            <div className="flex h-10 w-10 items-center justify-center rounded-xl bg-red-100 text-red-700 dark:bg-red-900/40 dark:text-red-300">
                                <AlertTriangle className="h-5 w-5" />
                            </div>
                            <div>
                                <p className="text-2xl font-bold">{stats.near_limit_medications}</p>
                                <p className="text-xs text-muted-foreground">Near Daily Limit</p>
                            </div>
                        </CardContent>
                    </Card>
                </div>

                {/* Date Filter */}
                <div className="mb-6 flex items-center gap-3">
                    <Input type="date" value={dateFrom} onChange={(e) => router.get('/emar/prn', { from: e.target.value, to: dateTo }, { preserveState: true })} className="w-40" />
                    <span className="text-sm text-muted-foreground">to</span>
                    <Input type="date" value={dateTo} onChange={(e) => router.get('/emar/prn', { from: dateFrom, to: e.target.value }, { preserveState: true })} className="w-40" />
                </div>

                {/* Pending Effectiveness Reviews */}
                {pendingReviews.length > 0 && (
                    <Card className="mb-6 border-amber-200 dark:border-amber-800">
                        <CardHeader className="pb-3">
                            <CardTitle className="flex items-center gap-2 text-base text-amber-700 dark:text-amber-400">
                                <Clock className="h-4 w-4" /> Effectiveness Reviews Due ({pendingReviews.length})
                            </CardTitle>
                        </CardHeader>
                        <CardContent className="p-0">
                            <div className="divide-y">
                                {pendingReviews.map((r) => (
                                    <div key={r.id} className="flex items-center justify-between p-3">
                                        <div>
                                            <span className="font-medium">{r.client?.last_name}, {r.client?.first_name}</span>
                                            <span className="mx-2 text-muted-foreground">—</span>
                                            <span className="text-sm">{r.medication?.name}</span>
                                        </div>
                                        <div className="text-xs text-muted-foreground">
                                            Given: {r.administered_at ? new Date(r.administered_at).toLocaleTimeString('en-NZ', { hour: '2-digit', minute: '2-digit' }) : '—'}
                                        </div>
                                    </div>
                                ))}
                            </div>
                        </CardContent>
                    </Card>
                )}

                {/* PRN Administration Records */}
                <Card>
                    <CardHeader className="pb-3">
                        <CardTitle className="text-base">PRN Administration History</CardTitle>
                    </CardHeader>
                    <CardContent className="p-0">
                        <div className="overflow-x-auto">
                            <table className="w-full text-sm">
                                <thead>
                                    <tr className="border-b bg-muted/50">
                                        <th className="p-3 text-left font-medium">Date/Time</th>
                                        <th className="p-3 text-left font-medium">Client</th>
                                        <th className="p-3 text-left font-medium">Medication</th>
                                        <th className="p-3 text-left font-medium">Dose</th>
                                        <th className="p-3 text-left font-medium">Reason</th>
                                        <th className="p-3 text-left font-medium">Status</th>
                                        <th className="p-3 text-left font-medium">Given By</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {administrations.data.map((a) => (
                                        <tr key={a.id} className="border-b last:border-0">
                                            <td className="p-3 text-xs">
                                                {a.administered_at ? new Date(a.administered_at).toLocaleString('en-NZ', { dateStyle: 'short', timeStyle: 'short' }) : '—'}
                                            </td>
                                            <td className="p-3">{a.client?.last_name}, {a.client?.first_name}</td>
                                            <td className="p-3 font-medium">{a.medication?.name}</td>
                                            <td className="p-3">{a.medication?.dosage}</td>
                                            <td className="p-3 text-xs">{a.reason ?? a.notes ?? '—'}</td>
                                            <td className="p-3">{a.status === 'given' ? <Badge className="bg-green-100 text-green-700 dark:bg-green-900/40 dark:text-green-300">Given</Badge> : <Badge variant="secondary">{a.status}</Badge>}</td>
                                            <td className="p-3 text-xs">{a.administered_by?.name ?? '—'}</td>
                                        </tr>
                                    ))}
                                    {administrations.data.length === 0 && (
                                        <tr><td colSpan={7} className="p-6 text-center text-muted-foreground">No PRN administrations in this period.</td></tr>
                                    )}
                                </tbody>
                            </table>
                        </div>
                    </CardContent>
                </Card>
            </PageShell>
        </AppLayout>
    );
}
