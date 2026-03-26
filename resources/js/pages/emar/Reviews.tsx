import PageHeader from '@/components/page-header';
import PageShell from '@/components/page-shell';
import { Badge } from '@/components/ui/badge';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { TabsRoot as Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import AppLayout from '@/layouts/app-layout';
import { Head, router } from '@inertiajs/react';
import { AlertTriangle, Calendar, CheckCircle, Clock } from 'lucide-react';

type Props = {
    reviews: { data: any[]; links: any };
    overdueReviews: any[];
    upcomingReviews: any[];
    clients: { id: number; first_name: string; last_name: string }[];
    filters: { status?: string; client_id?: string };
};

const reviewStatusColors: Record<string, string> = {
    scheduled: 'bg-blue-100 text-blue-700',
    overdue: 'bg-red-100 text-red-700',
    in_progress: 'bg-amber-100 text-amber-700',
    completed: 'bg-green-100 text-green-700',
    cancelled: 'bg-gray-100 text-gray-600',
};

export default function Reviews({ reviews, overdueReviews, upcomingReviews, clients, filters }: Props) {
    function updateFilter(key: string, value: string) {
        router.get('/emar/reviews', { ...filters, [key]: value || undefined }, { preserveState: true });
    }

    return (
        <AppLayout>
            <Head title="eMAR - Medication Reviews" />
            <PageHeader title="Medication Reviews" description="Schedule and track medication reviews — routine, triggered, and comprehensive." backHref="/emar" />
            <PageShell>
                {/* Stats */}
                <div className="mb-6 grid gap-4 sm:grid-cols-3">
                    <Card>
                        <CardContent className="flex items-center gap-3 p-4">
                            <div className="flex h-10 w-10 items-center justify-center rounded-xl bg-red-100 text-red-700 dark:bg-red-900/40"><AlertTriangle className="h-5 w-5" /></div>
                            <div><p className="text-2xl font-bold">{overdueReviews.length}</p><p className="text-xs text-muted-foreground">Overdue Reviews</p></div>
                        </CardContent>
                    </Card>
                    <Card>
                        <CardContent className="flex items-center gap-3 p-4">
                            <div className="flex h-10 w-10 items-center justify-center rounded-xl bg-amber-100 text-amber-700 dark:bg-amber-900/40"><Calendar className="h-5 w-5" /></div>
                            <div><p className="text-2xl font-bold">{upcomingReviews.length}</p><p className="text-xs text-muted-foreground">Upcoming (30 Days)</p></div>
                        </CardContent>
                    </Card>
                    <Card>
                        <CardContent className="flex items-center gap-3 p-4">
                            <div className="flex h-10 w-10 items-center justify-center rounded-xl bg-green-100 text-green-700 dark:bg-green-900/40"><CheckCircle className="h-5 w-5" /></div>
                            <div><p className="text-2xl font-bold">{reviews.data.filter((r: any) => r.status === 'completed').length}</p><p className="text-xs text-muted-foreground">Completed (Visible)</p></div>
                        </CardContent>
                    </Card>
                </div>

                {/* Overdue Alert */}
                {overdueReviews.length > 0 && (
                    <Card className="mb-6 border-red-200 dark:border-red-800">
                        <CardHeader className="pb-3">
                            <CardTitle className="flex items-center gap-2 text-base text-red-700 dark:text-red-400">
                                <AlertTriangle className="h-4 w-4" /> Overdue Reviews
                            </CardTitle>
                        </CardHeader>
                        <CardContent className="p-0">
                            <div className="divide-y">
                                {overdueReviews.map((r: any) => (
                                    <div key={r.id} className="flex items-center justify-between p-3">
                                        <span className="font-medium">{r.client?.last_name}, {r.client?.first_name}</span>
                                        <div className="text-sm">Due: <span className="text-red-600">{r.scheduled_date ? new Date(r.scheduled_date).toLocaleDateString('en-NZ') : '—'}</span></div>
                                    </div>
                                ))}
                            </div>
                        </CardContent>
                    </Card>
                )}

                {/* Filters */}
                <div className="mb-4 flex flex-wrap gap-3">
                    <Select value={filters.status ?? ''} onValueChange={(v) => updateFilter('status', v)}>
                        <SelectTrigger className="w-40"><SelectValue placeholder="All statuses" /></SelectTrigger>
                        <SelectContent>
                            <SelectItem value="scheduled">Scheduled</SelectItem>
                            <SelectItem value="overdue">Overdue</SelectItem>
                            <SelectItem value="in_progress">In Progress</SelectItem>
                            <SelectItem value="completed">Completed</SelectItem>
                            <SelectItem value="cancelled">Cancelled</SelectItem>
                        </SelectContent>
                    </Select>
                    <Select value={filters.client_id ?? ''} onValueChange={(v) => updateFilter('client_id', v)}>
                        <SelectTrigger className="w-56"><SelectValue placeholder="All clients" /></SelectTrigger>
                        <SelectContent>
                            {clients.map((c) => <SelectItem key={c.id} value={c.id.toString()}>{c.last_name}, {c.first_name}</SelectItem>)}
                        </SelectContent>
                    </Select>
                </div>

                {/* Reviews List */}
                <Card>
                    <CardContent className="p-0">
                        <table className="w-full text-sm">
                            <thead>
                                <tr className="border-b bg-muted/50">
                                    <th className="p-3 text-left font-medium">Client</th>
                                    <th className="p-3 text-left font-medium">Type</th>
                                    <th className="p-3 text-left font-medium">Scheduled</th>
                                    <th className="p-3 text-left font-medium">Completed</th>
                                    <th className="p-3 text-left font-medium">Reviewer</th>
                                    <th className="p-3 text-left font-medium">Status</th>
                                    <th className="p-3 text-left font-medium">Next Review</th>
                                    <th className="p-3 text-left font-medium">Whanau</th>
                                </tr>
                            </thead>
                            <tbody>
                                {reviews.data.map((r: any) => (
                                    <tr key={r.id} className="border-b last:border-0">
                                        <td className="p-3">{r.client?.last_name}, {r.client?.first_name}</td>
                                        <td className="p-3"><Badge variant="outline" className="text-xs">{r.review_type}</Badge></td>
                                        <td className="p-3 text-xs">{r.scheduled_date ? new Date(r.scheduled_date).toLocaleDateString('en-NZ') : '—'}</td>
                                        <td className="p-3 text-xs">{r.completed_date ? new Date(r.completed_date).toLocaleDateString('en-NZ') : '—'}</td>
                                        <td className="p-3 text-xs">{r.reviewer_name ?? r.reviewer?.name ?? '—'}</td>
                                        <td className="p-3"><Badge className={`text-xs ${reviewStatusColors[r.status] ?? ''}`}>{r.status}</Badge></td>
                                        <td className="p-3 text-xs">{r.next_review_date ? new Date(r.next_review_date).toLocaleDateString('en-NZ') : '—'}</td>
                                        <td className="p-3">{r.whanau_involved ? <CheckCircle className="h-4 w-4 text-green-600" /> : <span className="text-muted-foreground">—</span>}</td>
                                    </tr>
                                ))}
                                {reviews.data.length === 0 && <tr><td colSpan={8} className="p-6 text-center text-muted-foreground">No reviews found.</td></tr>}
                            </tbody>
                        </table>
                    </CardContent>
                </Card>
            </PageShell>
        </AppLayout>
    );
}
