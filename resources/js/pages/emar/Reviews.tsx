import FleetHero from '@/components/fleet-hero';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle, DialogTrigger } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { TabsRoot as Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { Textarea } from '@/components/ui/textarea';
import AppLayout from '@/layouts/app-layout';
import { Head, router, useForm } from '@inertiajs/react';
import { AlertTriangle, Calendar, CheckCircle, Clock, Pencil, Plus, Trash2 } from 'lucide-react';
import { useState } from 'react';

type Props = {
    reviews: { data: any[]; links: any };
    overdueReviews: any[];
    upcomingReviews: any[];
    clients: { id: number; first_name: string; last_name: string }[];
    staff: { id: number; name: string }[];
    filters: { status?: string; client_id?: string };
};

const reviewStatusColors: Record<string, string> = {
    scheduled: 'bg-status-info-bg text-status-info',
    overdue: 'bg-status-critical-bg text-status-critical',
    in_progress: 'bg-status-warning-bg text-status-warning',
    completed: 'bg-status-success-bg text-status-success',
    cancelled: 'bg-muted text-muted-foreground',
};

function ScheduleReviewDialog({ clients }: { clients: Props['clients'] }) {
    const [open, setOpen] = useState(false);
    const form = useForm({
        client_id: '',
        review_type: '',
        scheduled_date: '',
        reviewer_name: '',
        reviewer_role: '',
        trigger_reason: '',
    });

    function submit(e: React.FormEvent) {
        e.preventDefault();
        form.post('/emar/reviews', {
            onSuccess: () => { setOpen(false); form.reset(); },
        });
    }

    return (
        <Dialog open={open} onOpenChange={setOpen}>
            <DialogTrigger asChild>
                <Button><Plus className="mr-1 h-4 w-4" /> Schedule Review</Button>
            </DialogTrigger>
            <DialogContent className="max-w-lg">
                <DialogHeader>
                    <DialogTitle>Schedule Medication Review</DialogTitle>
                    <DialogDescription>
                        Schedule a medication review and capture any trigger or
                        reviewer details up front.
                    </DialogDescription>
                </DialogHeader>
                <form onSubmit={submit} className="space-y-4">
                    <div className="space-y-2">
                        <Label>Client</Label>
                        <Select value={form.data.client_id} onValueChange={(v) => form.setData('client_id', v)}>
                            <SelectTrigger><SelectValue placeholder="Select client" /></SelectTrigger>
                            <SelectContent>
                                {clients.map((c) => (
                                    <SelectItem key={c.id} value={c.id.toString()}>{c.last_name}, {c.first_name}</SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                        {form.errors.client_id && <p className="text-sm text-status-critical">{form.errors.client_id}</p>}
                    </div>

                    <div className="space-y-2">
                        <Label>Review Type</Label>
                        <Select value={form.data.review_type} onValueChange={(v) => form.setData('review_type', v)}>
                            <SelectTrigger><SelectValue placeholder="Select type" /></SelectTrigger>
                            <SelectContent>
                                <SelectItem value="routine">Routine</SelectItem>
                                <SelectItem value="triggered">Triggered</SelectItem>
                                <SelectItem value="comprehensive">Comprehensive</SelectItem>
                                <SelectItem value="admission">Admission</SelectItem>
                                <SelectItem value="discharge">Discharge</SelectItem>
                                <SelectItem value="incident">Incident</SelectItem>
                            </SelectContent>
                        </Select>
                        {form.errors.review_type && <p className="text-sm text-status-critical">{form.errors.review_type}</p>}
                    </div>

                    <div className="space-y-2">
                        <Label>Scheduled Date</Label>
                        <Input type="date" value={form.data.scheduled_date} onChange={(e) => form.setData('scheduled_date', e.target.value)} />
                        {form.errors.scheduled_date && <p className="text-sm text-status-critical">{form.errors.scheduled_date}</p>}
                    </div>

                    <div className="space-y-2">
                        <Label>Reviewer Name (optional)</Label>
                        <Input value={form.data.reviewer_name} onChange={(e) => form.setData('reviewer_name', e.target.value)} placeholder="Reviewer name" />
                    </div>

                    <div className="space-y-2">
                        <Label>Reviewer Role</Label>
                        <Select value={form.data.reviewer_role} onValueChange={(v) => form.setData('reviewer_role', v)}>
                            <SelectTrigger><SelectValue placeholder="Select role" /></SelectTrigger>
                            <SelectContent>
                                <SelectItem value="pharmacist">Pharmacist</SelectItem>
                                <SelectItem value="gp">GP</SelectItem>
                                <SelectItem value="nurse">Nurse</SelectItem>
                                <SelectItem value="specialist">Specialist</SelectItem>
                            </SelectContent>
                        </Select>
                    </div>

                    <div className="space-y-2">
                        <Label>Trigger Reason (optional)</Label>
                        <Textarea value={form.data.trigger_reason} onChange={(e) => form.setData('trigger_reason', e.target.value)} placeholder="Reason for triggered review..." rows={3} />
                    </div>

                    <div className="flex justify-end gap-2 pt-2">
                        <Button type="button" variant="outline" onClick={() => setOpen(false)}>Cancel</Button>
                        <Button type="submit" disabled={form.processing}>Schedule Review</Button>
                    </div>
                </form>
            </DialogContent>
        </Dialog>
    );
}

function CompleteReviewDialog({ review }: { review: any }) {
    const [open, setOpen] = useState(false);
    const form = useForm({
        clinical_summary: '',
        recommendations: '',
        whanau_involved: false,
        whanau_notes: '',
        next_review_date: '',
    });

    function submit(e: React.FormEvent) {
        e.preventDefault();
        form.post(`/emar/reviews/${review.id}/complete`, {
            onSuccess: () => { setOpen(false); form.reset(); },
        });
    }

    return (
        <Dialog open={open} onOpenChange={setOpen}>
            <DialogTrigger asChild>
                <Button size="sm" variant="outline"><CheckCircle className="mr-1 h-3.5 w-3.5" /> Complete</Button>
            </DialogTrigger>
            <DialogContent className="max-w-lg">
                <DialogHeader>
                    <DialogTitle>Complete Review — {review.client?.last_name}, {review.client?.first_name}</DialogTitle>
                    <DialogDescription>
                        Capture the clinical summary, recommendations, and the
                        next planned review date.
                    </DialogDescription>
                </DialogHeader>
                <form onSubmit={submit} className="space-y-4">
                    <div className="space-y-2">
                        <Label>Clinical Summary <span className="text-status-critical">*</span></Label>
                        <Textarea value={form.data.clinical_summary} onChange={(e) => form.setData('clinical_summary', e.target.value)} placeholder="Summary of findings..." rows={4} required />
                        {form.errors.clinical_summary && <p className="text-sm text-status-critical">{form.errors.clinical_summary}</p>}
                    </div>

                    <div className="space-y-2">
                        <Label>Recommendations</Label>
                        <Textarea value={form.data.recommendations} onChange={(e) => form.setData('recommendations', e.target.value)} placeholder="Recommended changes or actions..." rows={3} />
                    </div>

                    <div className="flex items-center gap-2">
                        <Checkbox
                            id={`whanau-${review.id}`}
                            checked={form.data.whanau_involved}
                            onCheckedChange={(checked) => form.setData('whanau_involved', checked === true)}
                        />
                        <Label htmlFor={`whanau-${review.id}`}>Whanau involved in review</Label>
                    </div>

                    {form.data.whanau_involved && (
                        <div className="space-y-2">
                            <Label>Whanau Notes</Label>
                            <Textarea value={form.data.whanau_notes} onChange={(e) => form.setData('whanau_notes', e.target.value)} placeholder="Notes on whanau involvement..." rows={3} />
                        </div>
                    )}

                    <div className="space-y-2">
                        <Label>Next Review Date</Label>
                        <Input type="date" value={form.data.next_review_date} onChange={(e) => form.setData('next_review_date', e.target.value)} />
                    </div>

                    <div className="flex justify-end gap-2 pt-2">
                        <Button type="button" variant="outline" onClick={() => setOpen(false)}>Cancel</Button>
                        <Button type="submit" disabled={form.processing}>Complete Review</Button>
                    </div>
                </form>
            </DialogContent>
        </Dialog>
    );
}

function EditReviewDialog({ review, clients, open, onOpenChange }: { review: any; clients: Props['clients']; open: boolean; onOpenChange: (open: boolean) => void }) {
    const form = useForm({
        review_type: review.review_type ?? '',
        scheduled_date: review.scheduled_date ? review.scheduled_date.split('T')[0] : '',
        reviewer_name: review.reviewer_name ?? '',
        reviewer_role: review.reviewer_role ?? '',
        trigger_reason: review.trigger_reason ?? '',
    });

    function submit(e: React.FormEvent) {
        e.preventDefault();
        form.put(`/emar/reviews/${review.id}`, {
            onSuccess: () => { onOpenChange(false); form.reset(); },
        });
    }

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="max-w-lg">
                <DialogHeader>
                    <DialogTitle>Edit Medication Review</DialogTitle>
                    <DialogDescription>
                        Update the scheduled review details before it is
                        completed.
                    </DialogDescription>
                </DialogHeader>
                <form onSubmit={submit} className="space-y-4">
                    <div className="space-y-2">
                        <Label>Review Type</Label>
                        <Select value={form.data.review_type} onValueChange={(v) => form.setData('review_type', v)}>
                            <SelectTrigger><SelectValue placeholder="Select type" /></SelectTrigger>
                            <SelectContent>
                                <SelectItem value="routine">Routine</SelectItem>
                                <SelectItem value="triggered">Triggered</SelectItem>
                                <SelectItem value="comprehensive">Comprehensive</SelectItem>
                                <SelectItem value="admission">Admission</SelectItem>
                                <SelectItem value="discharge">Discharge</SelectItem>
                                <SelectItem value="incident">Incident</SelectItem>
                            </SelectContent>
                        </Select>
                        {form.errors.review_type && <p className="text-sm text-status-critical">{form.errors.review_type}</p>}
                    </div>

                    <div className="space-y-2">
                        <Label>Scheduled Date</Label>
                        <Input type="date" value={form.data.scheduled_date} onChange={(e) => form.setData('scheduled_date', e.target.value)} />
                        {form.errors.scheduled_date && <p className="text-sm text-status-critical">{form.errors.scheduled_date}</p>}
                    </div>

                    <div className="space-y-2">
                        <Label>Reviewer Name</Label>
                        <Input value={form.data.reviewer_name} onChange={(e) => form.setData('reviewer_name', e.target.value)} placeholder="Reviewer name" />
                    </div>

                    <div className="space-y-2">
                        <Label>Reviewer Role</Label>
                        <Select value={form.data.reviewer_role} onValueChange={(v) => form.setData('reviewer_role', v)}>
                            <SelectTrigger><SelectValue placeholder="Select role" /></SelectTrigger>
                            <SelectContent>
                                <SelectItem value="pharmacist">Pharmacist</SelectItem>
                                <SelectItem value="gp">GP</SelectItem>
                                <SelectItem value="nurse">Nurse</SelectItem>
                                <SelectItem value="specialist">Specialist</SelectItem>
                            </SelectContent>
                        </Select>
                    </div>

                    <div className="space-y-2">
                        <Label>Trigger Reason</Label>
                        <Textarea value={form.data.trigger_reason} onChange={(e) => form.setData('trigger_reason', e.target.value)} placeholder="Reason for triggered review..." rows={3} />
                    </div>

                    <div className="flex justify-end gap-2 pt-2">
                        <Button type="button" variant="outline" onClick={() => onOpenChange(false)}>Cancel</Button>
                        <Button type="submit" disabled={form.processing}>Save Changes</Button>
                    </div>
                </form>
            </DialogContent>
        </Dialog>
    );
}

export default function Reviews({ reviews, overdueReviews, upcomingReviews, clients, staff, filters }: Props) {
    const [editOpen, setEditOpen] = useState(false);
    const [editingReview, setEditingReview] = useState<any>(null);

    function openEditReview(review: any) {
        setEditingReview(review);
        setEditOpen(true);
    }
    function updateFilter(key: string, value: string) {
        router.get('/emar/reviews', { ...filters, [key]: value || undefined }, { preserveState: true });
    }

    function deleteReview(id: number) {
        if (!confirm('Are you sure you want to cancel this review?')) return;
        router.delete(`/emar/reviews/${id}`);
    }

    return (
        <AppLayout>
            <Head title="eMAR - Medication Reviews" />
            <div className="flex flex-col gap-6 p-6">
                <FleetHero
                    title="Medication Reviews"
                    description="Schedule and track medication reviews — routine, triggered, and comprehensive"
                    icon={<Calendar className="h-7 w-7 text-white" />}
                    backHref="/emar"
                    backLabel="Back"
                />
                {/* Stats */}
                <div className="mb-6 grid gap-4 sm:grid-cols-3">
                    <Card>
                        <CardContent className="flex items-center gap-3 p-4">
                            <div className="flex h-10 w-10 items-center justify-center rounded-xl bg-status-critical-bg text-status-critical"><AlertTriangle className="h-5 w-5" /></div>
                            <div><p className="text-2xl font-bold">{overdueReviews.length}</p><p className="text-xs text-muted-foreground">Overdue Reviews</p></div>
                        </CardContent>
                    </Card>
                    <Card>
                        <CardContent className="flex items-center gap-3 p-4">
                            <div className="flex h-10 w-10 items-center justify-center rounded-xl bg-status-warning-bg text-status-warning"><Calendar className="h-5 w-5" /></div>
                            <div><p className="text-2xl font-bold">{upcomingReviews.length}</p><p className="text-xs text-muted-foreground">Upcoming (30 Days)</p></div>
                        </CardContent>
                    </Card>
                    <Card>
                        <CardContent className="flex items-center gap-3 p-4">
                            <div className="flex h-10 w-10 items-center justify-center rounded-xl bg-status-success-bg text-status-success"><CheckCircle className="h-5 w-5" /></div>
                            <div><p className="text-2xl font-bold">{reviews.data.filter((r: any) => r.status === 'completed').length}</p><p className="text-xs text-muted-foreground">Completed (Visible)</p></div>
                        </CardContent>
                    </Card>
                </div>

                {/* Overdue Alert */}
                {overdueReviews.length > 0 && (
                    <Card className="mb-6 border-status-critical/30 dark:border-status-critical/30">
                        <CardHeader className="pb-3">
                            <CardTitle className="flex items-center gap-2 text-base text-status-critical dark:text-status-critical">
                                <AlertTriangle className="h-4 w-4" /> Overdue Reviews
                            </CardTitle>
                        </CardHeader>
                        <CardContent className="p-0">
                            <div className="divide-y">
                                {overdueReviews.map((r: any) => (
                                    <div key={r.id} className="flex items-center justify-between p-3">
                                        <span className="font-medium">{r.client?.last_name}, {r.client?.first_name}</span>
                                        <div className="flex items-center gap-2">
                                            <span className="text-sm">Due: <span className="text-status-critical">{r.scheduled_date ? new Date(r.scheduled_date).toLocaleDateString('en-NZ') : '—'}</span></span>
                                            <CompleteReviewDialog review={r} />
                                            <Button size="sm" variant="ghost" className="text-status-critical hover:text-status-critical" onClick={() => deleteReview(r.id)}>
                                                <Trash2 className="h-3.5 w-3.5" />
                                            </Button>
                                        </div>
                                    </div>
                                ))}
                            </div>
                        </CardContent>
                    </Card>
                )}

                {/* Filters + Schedule Button */}
                <div className="mb-4 flex flex-wrap items-center gap-3">
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
                    <div className="ml-auto">
                        <ScheduleReviewDialog clients={clients} />
                    </div>
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
                                    <th className="p-3 text-left font-medium">Actions</th>
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
                                        <td className="p-3">{r.whanau_involved ? <CheckCircle className="h-4 w-4 text-status-success" /> : <span className="text-muted-foreground">—</span>}</td>
                                        <td className="p-3">
                                            <div className="flex items-center gap-1">
                                                {r.status !== 'completed' && r.status !== 'cancelled' && (
                                                    <Button size="icon" variant="ghost" onClick={() => openEditReview(r)}>
                                                        <Pencil className="h-3.5 w-3.5" />
                                                    </Button>
                                                )}
                                                {(r.status === 'scheduled' || r.status === 'overdue' || r.status === 'in_progress') && (
                                                    <CompleteReviewDialog review={r} />
                                                )}
                                                {r.status !== 'completed' && (
                                                    <Button size="icon" variant="ghost" className="text-status-critical hover:text-status-critical" onClick={() => deleteReview(r.id)}>
                                                        <Trash2 className="h-3.5 w-3.5" />
                                                    </Button>
                                                )}
                                            </div>
                                        </td>
                                    </tr>
                                ))}
                                {reviews.data.length === 0 && <tr><td colSpan={9} className="p-6 text-center text-muted-foreground">No reviews found.</td></tr>}
                            </tbody>
                        </table>
                    </CardContent>
                </Card>
            </div>

            {editingReview && (
                <EditReviewDialog
                    review={editingReview}
                    clients={clients}
                    open={editOpen}
                    onOpenChange={(open) => { setEditOpen(open); if (!open) setEditingReview(null); }}
                />
            )}
        </AppLayout>
    );
}
