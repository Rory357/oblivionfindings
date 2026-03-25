import AppLayout from '@/layouts/app-layout';
import PageHeader from '@/components/page-header';
import PageShell from '@/components/page-shell';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { Badge } from '@/components/ui/badge';
import { Dialog, DialogContent, DialogHeader, DialogTitle, DialogTrigger } from '@/components/ui/dialog';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Switch } from '@/components/ui/switch';
import { Head, Link, useForm, router } from '@inertiajs/react';
import { useState } from 'react';
import {
    MessageSquare,
    Star,
    Plus,
    AlertCircle,
    CheckCircle2,
    Clock,
    TrendingUp,
    Send,
    User,
} from 'lucide-react';

type SiteLite = { id: number; name: string };

type FeedbackItem = {
    id: number;
    feedback_type: string;
    submitted_by_name?: string | null;
    submitted_by_relationship?: string | null;
    content: string;
    rating?: number | null;
    category?: string | null;
    status: string;
    response?: string | null;
    responded_by?: { id: number; name: string } | null;
    responded_at?: string | null;
    is_anonymous: boolean;
    created_at: string;
};

type PaginatedData = {
    data: FeedbackItem[];
    links: Array<{ url: string | null; label: string; active: boolean }>;
    current_page: number;
    last_page: number;
    total: number;
};

type Stats = {
    total: number;
    average_rating: number | null;
    open: number;
    response_rate: number;
};

type Filters = {
    type?: string;
    status?: string;
    rating?: string;
    from?: string;
    to?: string;
};

type Props = {
    site: SiteLite;
    feedback: PaginatedData;
    stats: Stats;
    filters: Filters;
};

const typeLabels: Record<string, string> = {
    whanau: 'Whanau',
    client: 'Client',
    staff: 'Staff',
    external: 'External',
    complaint: 'Complaint',
    compliment: 'Compliment',
};

const typeColors: Record<string, string> = {
    whanau: 'border-purple-500/30 text-purple-300 bg-purple-500/10',
    client: 'border-blue-500/30 text-blue-300 bg-blue-500/10',
    staff: 'border-emerald-500/30 text-emerald-300 bg-emerald-500/10',
    external: 'border-slate-500/30 text-slate-300 bg-slate-500/10',
    complaint: 'border-red-500/30 text-red-300 bg-red-500/10',
    compliment: 'border-green-500/30 text-green-300 bg-green-500/10',
};

const statusLabels: Record<string, string> = {
    new: 'New',
    acknowledged: 'Acknowledged',
    in_progress: 'In Progress',
    resolved: 'Resolved',
    closed: 'Closed',
};

const statusColors: Record<string, string> = {
    new: 'border-blue-500/30 text-blue-300 bg-blue-500/10',
    acknowledged: 'border-amber-500/30 text-amber-300 bg-amber-500/10',
    in_progress: 'border-indigo-500/30 text-indigo-300 bg-indigo-500/10',
    resolved: 'border-emerald-500/30 text-emerald-300 bg-emerald-500/10',
    closed: 'border-slate-500/30 text-slate-300 bg-slate-500/10',
};

const categoryLabels: Record<string, string> = {
    care_quality: 'Care Quality',
    communication: 'Communication',
    environment: 'Environment',
    staff: 'Staff',
    food: 'Food',
    activities: 'Activities',
    safety: 'Safety',
    other: 'Other',
};

function StarRating({ rating, size = 'sm' }: { rating: number; size?: 'sm' | 'md' }) {
    const cls = size === 'sm' ? 'w-3.5 h-3.5' : 'w-5 h-5';
    return (
        <div className="flex items-center gap-0.5">
            {[1, 2, 3, 4, 5].map((i) => (
                <Star key={i} className={`${cls} ${i <= rating ? 'fill-amber-400 text-amber-400' : 'text-slate-600'}`} />
            ))}
        </div>
    );
}

function StarRatingInput({ value, onChange }: { value: number; onChange: (v: number) => void }) {
    return (
        <div className="flex items-center gap-1">
            {[1, 2, 3, 4, 5].map((i) => (
                <button key={i} type="button" onClick={() => onChange(i)} className="p-0.5 hover:scale-110 transition-transform">
                    <Star className={`w-6 h-6 ${i <= value ? 'fill-amber-400 text-amber-400' : 'text-slate-600 hover:text-slate-400'}`} />
                </button>
            ))}
        </div>
    );
}

export default function FeedbackIndex({ site, feedback, stats, filters }: Props) {
    const [dialogOpen, setDialogOpen] = useState(false);
    const [respondingId, setRespondingId] = useState<number | null>(null);

    const feedbackForm = useForm({
        feedback_type: 'whanau',
        submitted_by_name: '',
        submitted_by_relationship: 'whanau',
        content: '',
        rating: 0 as number,
        category: 'care_quality',
        is_anonymous: false,
    });

    const responseForm = useForm({
        response: '',
    });

    function submitFeedback(e: React.FormEvent) {
        e.preventDefault();
        feedbackForm.post(`/sites/${site.id}/feedback`, {
            preserveScroll: true,
            onSuccess: () => {
                feedbackForm.reset();
                setDialogOpen(false);
            },
        });
    }

    function submitResponse(feedbackId: number) {
        responseForm.post(`/sites/${site.id}/feedback/${feedbackId}/respond`, {
            preserveScroll: true,
            onSuccess: () => {
                responseForm.reset();
                setRespondingId(null);
            },
        });
    }

    function updateStatus(feedbackId: number, status: string) {
        router.put(`/sites/${site.id}/feedback/${feedbackId}/status`, { status }, { preserveScroll: true });
    }

    function applyFilter(key: string, value: string) {
        const newFilters = { ...filters, [key]: value || undefined };
        router.get(`/sites/${site.id}/feedback`, newFilters as any, { preserveState: true, preserveScroll: true });
    }

    function clearFilters() {
        router.get(`/sites/${site.id}/feedback`, {}, { preserveState: true, preserveScroll: true });
    }

    return (
        <AppLayout breadcrumbs={[{ title: 'Sites', href: '/sites' }, { title: site.name, href: `/sites/${site.id}` }, { title: 'Feedback' }]}>
            <Head title={`${site.name} — Quality & Feedback`} />

            <PageShell>
                <PageHeader
                    title={`${site.name} — Quality & Feedback`}
                    description="Manage whanau, client, and staff feedback for continuous quality improvement"
                    actions={
                        <Dialog open={dialogOpen} onOpenChange={setDialogOpen}>
                            <DialogTrigger asChild>
                                <Button>
                                    <Plus className="w-4 h-4 mr-1" />
                                    Submit Feedback
                                </Button>
                            </DialogTrigger>
                            <DialogContent className="max-w-lg">
                                <DialogHeader>
                                    <DialogTitle>Submit Feedback</DialogTitle>
                                </DialogHeader>
                                <form onSubmit={submitFeedback} className="space-y-3">
                                    <div>
                                        <Label>Type</Label>
                                        <Select value={feedbackForm.data.feedback_type} onValueChange={(v) => feedbackForm.setData('feedback_type', v)}>
                                            <SelectTrigger><SelectValue /></SelectTrigger>
                                            <SelectContent>
                                                {Object.entries(typeLabels).map(([val, label]) => (
                                                    <SelectItem key={val} value={val}>{label}</SelectItem>
                                                ))}
                                            </SelectContent>
                                        </Select>
                                    </div>

                                    <div className="flex items-center gap-3">
                                        <Switch checked={feedbackForm.data.is_anonymous} onCheckedChange={(v) => feedbackForm.setData('is_anonymous', v)} />
                                        <Label>Submit Anonymously</Label>
                                    </div>

                                    {!feedbackForm.data.is_anonymous && (
                                        <div className="grid gap-3 sm:grid-cols-2">
                                            <div>
                                                <Label>Name</Label>
                                                <Input value={feedbackForm.data.submitted_by_name} onChange={(e) => feedbackForm.setData('submitted_by_name', e.target.value)} />
                                            </div>
                                            <div>
                                                <Label>Relationship</Label>
                                                <Select value={feedbackForm.data.submitted_by_relationship} onValueChange={(v) => feedbackForm.setData('submitted_by_relationship', v)}>
                                                    <SelectTrigger><SelectValue /></SelectTrigger>
                                                    <SelectContent>
                                                        <SelectItem value="whanau">Whanau</SelectItem>
                                                        <SelectItem value="parent">Parent</SelectItem>
                                                        <SelectItem value="sibling">Sibling</SelectItem>
                                                        <SelectItem value="advocate">Advocate</SelectItem>
                                                        <SelectItem value="staff">Staff</SelectItem>
                                                        <SelectItem value="other">Other</SelectItem>
                                                    </SelectContent>
                                                </Select>
                                            </div>
                                        </div>
                                    )}

                                    <div>
                                        <Label>Feedback</Label>
                                        <Textarea value={feedbackForm.data.content} onChange={(e) => feedbackForm.setData('content', e.target.value)} rows={4} required />
                                    </div>

                                    <div>
                                        <Label>Rating</Label>
                                        <StarRatingInput value={feedbackForm.data.rating} onChange={(v) => feedbackForm.setData('rating', v)} />
                                    </div>

                                    <div>
                                        <Label>Category</Label>
                                        <Select value={feedbackForm.data.category} onValueChange={(v) => feedbackForm.setData('category', v)}>
                                            <SelectTrigger><SelectValue /></SelectTrigger>
                                            <SelectContent>
                                                {Object.entries(categoryLabels).map(([val, label]) => (
                                                    <SelectItem key={val} value={val}>{label}</SelectItem>
                                                ))}
                                            </SelectContent>
                                        </Select>
                                    </div>

                                    <Button type="submit" disabled={feedbackForm.processing} className="w-full">
                                        {feedbackForm.processing ? 'Submitting...' : 'Submit Feedback'}
                                    </Button>
                                </form>
                            </DialogContent>
                        </Dialog>
                    }
                />

                {/* Stats */}
                <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    <Card>
                        <CardContent className="pt-6">
                            <div className="flex items-center gap-3">
                                <div className="rounded-lg bg-blue-500/10 p-2.5">
                                    <MessageSquare className="w-5 h-5 text-blue-400" />
                                </div>
                                <div>
                                    <div className="text-2xl font-bold">{stats.total}</div>
                                    <div className="text-sm text-slate-400">Total Feedback</div>
                                </div>
                            </div>
                        </CardContent>
                    </Card>
                    <Card>
                        <CardContent className="pt-6">
                            <div className="flex items-center gap-3">
                                <div className="rounded-lg bg-amber-500/10 p-2.5">
                                    <Star className="w-5 h-5 text-amber-400" />
                                </div>
                                <div>
                                    <div className="flex items-center gap-2">
                                        <span className="text-2xl font-bold">{stats.average_rating ?? '—'}</span>
                                        {stats.average_rating && <StarRating rating={Math.round(stats.average_rating)} />}
                                    </div>
                                    <div className="text-sm text-slate-400">Average Rating</div>
                                </div>
                            </div>
                        </CardContent>
                    </Card>
                    <Card>
                        <CardContent className="pt-6">
                            <div className="flex items-center gap-3">
                                <div className="rounded-lg bg-orange-500/10 p-2.5">
                                    <AlertCircle className="w-5 h-5 text-orange-400" />
                                </div>
                                <div>
                                    <div className="text-2xl font-bold">{stats.open}</div>
                                    <div className="text-sm text-slate-400">Open Items</div>
                                </div>
                            </div>
                        </CardContent>
                    </Card>
                    <Card>
                        <CardContent className="pt-6">
                            <div className="flex items-center gap-3">
                                <div className="rounded-lg bg-emerald-500/10 p-2.5">
                                    <TrendingUp className="w-5 h-5 text-emerald-400" />
                                </div>
                                <div>
                                    <div className="text-2xl font-bold">{stats.response_rate}%</div>
                                    <div className="text-sm text-slate-400">Response Rate</div>
                                </div>
                            </div>
                        </CardContent>
                    </Card>
                </div>

                {/* Filters */}
                <Card>
                    <CardContent className="pt-6">
                        <div className="flex flex-wrap items-end gap-3">
                            <div className="min-w-[140px]">
                                <Label className="text-xs text-slate-400">Type</Label>
                                <Select value={filters.type || ''} onValueChange={(v) => applyFilter('type', v)}>
                                    <SelectTrigger><SelectValue placeholder="All Types" /></SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="">All Types</SelectItem>
                                        {Object.entries(typeLabels).map(([val, label]) => (
                                            <SelectItem key={val} value={val}>{label}</SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            </div>
                            <div className="min-w-[140px]">
                                <Label className="text-xs text-slate-400">Status</Label>
                                <Select value={filters.status || ''} onValueChange={(v) => applyFilter('status', v)}>
                                    <SelectTrigger><SelectValue placeholder="All Statuses" /></SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="">All Statuses</SelectItem>
                                        {Object.entries(statusLabels).map(([val, label]) => (
                                            <SelectItem key={val} value={val}>{label}</SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            </div>
                            <div className="min-w-[120px]">
                                <Label className="text-xs text-slate-400">Rating</Label>
                                <Select value={filters.rating || ''} onValueChange={(v) => applyFilter('rating', v)}>
                                    <SelectTrigger><SelectValue placeholder="Any" /></SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="">Any Rating</SelectItem>
                                        {[5, 4, 3, 2, 1].map((r) => (
                                            <SelectItem key={r} value={String(r)}>{r} Star{r !== 1 ? 's' : ''}</SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            </div>
                            <div>
                                <Label className="text-xs text-slate-400">From</Label>
                                <Input type="date" value={filters.from || ''} onChange={(e) => applyFilter('from', e.target.value)} className="w-[150px]" />
                            </div>
                            <div>
                                <Label className="text-xs text-slate-400">To</Label>
                                <Input type="date" value={filters.to || ''} onChange={(e) => applyFilter('to', e.target.value)} className="w-[150px]" />
                            </div>
                            {(filters.type || filters.status || filters.rating || filters.from || filters.to) && (
                                <Button variant="ghost" size="sm" onClick={clearFilters}>Clear</Button>
                            )}
                        </div>
                    </CardContent>
                </Card>

                {/* Feedback List */}
                <div className="space-y-3">
                    {feedback.data.length === 0 ? (
                        <Card>
                            <CardContent className="py-12 text-center">
                                <MessageSquare className="w-12 h-12 mx-auto mb-3 text-slate-500 opacity-50" />
                                <p className="text-slate-400">No feedback found</p>
                                <p className="text-sm text-slate-500 mt-1">Submit feedback to start tracking quality and engagement</p>
                            </CardContent>
                        </Card>
                    ) : (
                        feedback.data.map((item) => (
                            <Card key={item.id}>
                                <CardContent className="pt-5 pb-4 space-y-3">
                                    {/* Header row */}
                                    <div className="flex items-start justify-between gap-3">
                                        <div className="flex items-center gap-2 flex-wrap">
                                            <Badge variant="outline" className={typeColors[item.feedback_type] || 'border-slate-500/30 text-slate-300'}>
                                                {typeLabels[item.feedback_type] || item.feedback_type}
                                            </Badge>
                                            {item.rating && <StarRating rating={item.rating} />}
                                            {item.category && (
                                                <Badge variant="outline" className="text-xs border-slate-500/30 text-slate-400">
                                                    {categoryLabels[item.category] || item.category}
                                                </Badge>
                                            )}
                                        </div>
                                        <div className="flex items-center gap-2 shrink-0">
                                            <Badge variant="outline" className={statusColors[item.status] || ''}>
                                                {statusLabels[item.status] || item.status}
                                            </Badge>
                                            <Select value={item.status} onValueChange={(v) => updateStatus(item.id, v)}>
                                                <SelectTrigger className="h-7 w-7 p-0 border-0 [&>svg]:hidden">
                                                    <span className="sr-only">Change status</span>
                                                    <Clock className="w-3.5 h-3.5 text-slate-500" />
                                                </SelectTrigger>
                                                <SelectContent>
                                                    {Object.entries(statusLabels).map(([val, label]) => (
                                                        <SelectItem key={val} value={val}>{label}</SelectItem>
                                                    ))}
                                                </SelectContent>
                                            </Select>
                                        </div>
                                    </div>

                                    {/* Content */}
                                    <p className="text-sm text-slate-200 whitespace-pre-wrap">{item.content}</p>

                                    {/* Submitted by */}
                                    <div className="flex items-center gap-4 text-xs text-slate-500">
                                        {item.is_anonymous ? (
                                            <span className="flex items-center gap-1">
                                                <User className="w-3 h-3" /> Anonymous
                                            </span>
                                        ) : item.submitted_by_name ? (
                                            <span className="flex items-center gap-1">
                                                <User className="w-3 h-3" />
                                                {item.submitted_by_name}
                                                {item.submitted_by_relationship && ` (${item.submitted_by_relationship})`}
                                            </span>
                                        ) : null}
                                        <span>{new Date(item.created_at).toLocaleDateString('en-NZ', { day: 'numeric', month: 'short', year: 'numeric' })}</span>
                                    </div>

                                    {/* Response section */}
                                    {item.response && (
                                        <div className="rounded-lg bg-muted/50 p-3 border-l-2 border-emerald-500/50">
                                            <div className="flex items-center gap-2 text-xs text-slate-400 mb-1">
                                                <CheckCircle2 className="w-3 h-3 text-emerald-400" />
                                                Response from {item.responded_by?.name || 'Staff'}
                                                {item.responded_at && (
                                                    <span> — {new Date(item.responded_at).toLocaleDateString('en-NZ', { day: 'numeric', month: 'short', year: 'numeric' })}</span>
                                                )}
                                            </div>
                                            <p className="text-sm text-slate-300 whitespace-pre-wrap">{item.response}</p>
                                        </div>
                                    )}

                                    {/* Respond button / form */}
                                    {!item.response && respondingId !== item.id && (
                                        <Button variant="outline" size="sm" onClick={() => setRespondingId(item.id)}>
                                            <Send className="w-3.5 h-3.5 mr-1" />
                                            Respond
                                        </Button>
                                    )}

                                    {respondingId === item.id && (
                                        <div className="space-y-2">
                                            <Textarea
                                                value={responseForm.data.response}
                                                onChange={(e) => responseForm.setData('response', e.target.value)}
                                                placeholder="Write your response..."
                                                rows={3}
                                            />
                                            <div className="flex items-center gap-2">
                                                <Button size="sm" onClick={() => submitResponse(item.id)} disabled={responseForm.processing || !responseForm.data.response.trim()}>
                                                    {responseForm.processing ? 'Sending...' : 'Send Response'}
                                                </Button>
                                                <Button variant="ghost" size="sm" onClick={() => { setRespondingId(null); responseForm.reset(); }}>
                                                    Cancel
                                                </Button>
                                            </div>
                                        </div>
                                    )}
                                </CardContent>
                            </Card>
                        ))
                    )}
                </div>

                {/* Pagination */}
                {feedback.last_page > 1 && (
                    <div className="flex items-center justify-center gap-1">
                        {feedback.links.map((link, idx) => (
                            <Button
                                key={idx}
                                variant={link.active ? 'default' : 'outline'}
                                size="sm"
                                disabled={!link.url}
                                asChild={!!link.url}
                            >
                                {link.url ? (
                                    <Link href={link.url} preserveScroll dangerouslySetInnerHTML={{ __html: link.label }} />
                                ) : (
                                    <span dangerouslySetInnerHTML={{ __html: link.label }} />
                                )}
                            </Button>
                        ))}
                    </div>
                )}
            </PageShell>
        </AppLayout>
    );
}
