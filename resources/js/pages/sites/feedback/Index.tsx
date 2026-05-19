import PageShell from '@/components/page-shell';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import {
    Dialog,
    DialogContent,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Switch } from '@/components/ui/switch';
import { Textarea } from '@/components/ui/textarea';
import { PageHero } from '@/components/page';
import AppLayout from '@/layouts/app-layout';
import { Head, Link, router, useForm } from '@inertiajs/react';
import {
    AlertCircle,
    CheckCircle2,
    Clock,
    MessageSquare,
    Plus,
    Send,
    Star,
    TrendingUp,
    User,
} from 'lucide-react';
import { useState } from 'react';

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
    whanau: 'border-primary/30 text-primary/70 bg-primary/10',
    client: 'border-status-info/30 text-status-info bg-status-info',
    staff: 'border-status-success/30 text-status-success bg-status-success',
    external:
        'border-border/30 text-muted-foreground bg-muted-foreground/80/10',
    complaint:
        'border-status-critical/30 text-status-critical bg-status-critical',
    compliment:
        'border-status-success/30 text-status-success bg-status-success',
};

const statusLabels: Record<string, string> = {
    new: 'New',
    acknowledged: 'Acknowledged',
    in_progress: 'In Progress',
    resolved: 'Resolved',
    closed: 'Closed',
};

const statusColors: Record<string, string> = {
    new: 'border-status-info/30 text-status-info bg-status-info',
    acknowledged:
        'border-status-warning/30 text-status-warning bg-status-warning',
    in_progress: 'border-primary/30 text-primary/70 bg-primary/10',
    resolved: 'border-status-success/30 text-status-success bg-status-success',
    closed: 'border-border/30 text-muted-foreground bg-muted-foreground/80/10',
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

const ALL_SENTINEL = '__all__';

function StarRating({
    rating,
    size = 'sm',
}: {
    rating: number;
    size?: 'sm' | 'md';
}) {
    const cls = size === 'sm' ? 'w-3.5 h-3.5' : 'w-5 h-5';
    return (
        <div className="flex items-center gap-0.5">
            {[1, 2, 3, 4, 5].map((i) => (
                <Star
                    key={i}
                    className={`${cls} ${i <= rating ? 'fill-amber-400 text-status-warning' : 'text-muted-foreground'}`}
                />
            ))}
        </div>
    );
}

function StarRatingInput({
    value,
    onChange,
}: {
    value: number;
    onChange: (v: number) => void;
}) {
    return (
        <div className="flex items-center gap-1">
            {[1, 2, 3, 4, 5].map((i) => (
                <Button
                    key={i}
                    type="button"
                    variant="ghost"
                    size="icon"
                    onClick={() => onChange(i)}
                    className="h-7 w-7 p-0 hover:scale-110 hover:bg-transparent"
                >
                    <Star
                        className={`size-6 ${i <= value ? 'fill-amber-400 text-status-warning' : 'text-muted-foreground hover:text-muted-foreground'}`}
                    />
                </Button>
            ))}
        </div>
    );
}

export default function FeedbackIndex({
    site,
    feedback,
    stats,
    filters,
}: Props) {
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
        feedbackForm.transform((data) => ({
            ...data,
            rating: data.rating > 0 ? data.rating : null,
        }));
        feedbackForm.post(`/sites/${site.id}/feedback`, {
            preserveScroll: true,
            onSuccess: () => {
                feedbackForm.reset();
                setDialogOpen(false);
            },
            onFinish: () => {
                feedbackForm.transform((data) => data);
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
        router.put(
            `/sites/${site.id}/feedback/${feedbackId}/status`,
            { status },
            { preserveScroll: true },
        );
    }

    function applyFilter(key: string, value: string) {
        const nextValue =
            value === ALL_SENTINEL ? undefined : value || undefined;
        const newFilters = { ...filters, [key]: nextValue };
        router.get(`/sites/${site.id}/feedback`, newFilters as any, {
            preserveState: true,
            preserveScroll: true,
        });
    }

    function clearFilters() {
        router.get(
            `/sites/${site.id}/feedback`,
            {},
            { preserveState: true, preserveScroll: true },
        );
    }

    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Sites', href: '/sites' },
                { title: site.name, href: `/sites/${site.id}` },
                { title: 'Feedback' },
            ]}
        >
            <Head title={`${site.name} — Quality & Feedback`} />

            <PageShell>
                <PageHero variant="compact"
                    title={`${site.name} — Quality & Feedback`}
                    description="Manage whanau, client, and staff feedback for continuous quality improvement"
                    actions={
                        <Dialog open={dialogOpen} onOpenChange={setDialogOpen}>
                            <DialogTrigger asChild>
                                <Button>
                                    <Plus className="mr-1 h-4 w-4" />
                                    Submit Feedback
                                </Button>
                            </DialogTrigger>
                            <DialogContent className="max-w-lg">
                                <DialogHeader>
                                    <DialogTitle>Submit Feedback</DialogTitle>
                                </DialogHeader>
                                <form
                                    onSubmit={submitFeedback}
                                    className="space-y-3"
                                >
                                    <div>
                                        <Label>Type</Label>
                                        <Select
                                            value={
                                                feedbackForm.data.feedback_type
                                            }
                                            onValueChange={(v) =>
                                                feedbackForm.setData(
                                                    'feedback_type',
                                                    v,
                                                )
                                            }
                                        >
                                            <SelectTrigger>
                                                <SelectValue />
                                            </SelectTrigger>
                                            <SelectContent>
                                                {Object.entries(typeLabels).map(
                                                    ([val, label]) => (
                                                        <SelectItem
                                                            key={val}
                                                            value={val}
                                                        >
                                                            {label}
                                                        </SelectItem>
                                                    ),
                                                )}
                                            </SelectContent>
                                        </Select>
                                    </div>

                                    <div className="flex items-center gap-3">
                                        <Switch
                                            checked={
                                                feedbackForm.data.is_anonymous
                                            }
                                            onCheckedChange={(v) =>
                                                feedbackForm.setData(
                                                    'is_anonymous',
                                                    v,
                                                )
                                            }
                                        />
                                        <Label>Submit Anonymously</Label>
                                    </div>

                                    {!feedbackForm.data.is_anonymous && (
                                        <div className="grid gap-3 sm:grid-cols-2">
                                            <div>
                                                <Label>Name</Label>
                                                <Input
                                                    value={
                                                        feedbackForm.data
                                                            .submitted_by_name
                                                    }
                                                    onChange={(e) =>
                                                        feedbackForm.setData(
                                                            'submitted_by_name',
                                                            e.target.value,
                                                        )
                                                    }
                                                />
                                            </div>
                                            <div>
                                                <Label>Relationship</Label>
                                                <Select
                                                    value={
                                                        feedbackForm.data
                                                            .submitted_by_relationship
                                                    }
                                                    onValueChange={(v) =>
                                                        feedbackForm.setData(
                                                            'submitted_by_relationship',
                                                            v,
                                                        )
                                                    }
                                                >
                                                    <SelectTrigger>
                                                        <SelectValue />
                                                    </SelectTrigger>
                                                    <SelectContent>
                                                        <SelectItem value="whanau">
                                                            Whanau
                                                        </SelectItem>
                                                        <SelectItem value="parent">
                                                            Parent
                                                        </SelectItem>
                                                        <SelectItem value="sibling">
                                                            Sibling
                                                        </SelectItem>
                                                        <SelectItem value="advocate">
                                                            Advocate
                                                        </SelectItem>
                                                        <SelectItem value="staff">
                                                            Staff
                                                        </SelectItem>
                                                        <SelectItem value="other">
                                                            Other
                                                        </SelectItem>
                                                    </SelectContent>
                                                </Select>
                                            </div>
                                        </div>
                                    )}

                                    <div>
                                        <Label>Feedback</Label>
                                        <Textarea
                                            value={feedbackForm.data.content}
                                            onChange={(e) =>
                                                feedbackForm.setData(
                                                    'content',
                                                    e.target.value,
                                                )
                                            }
                                            rows={4}
                                            required
                                        />
                                    </div>

                                    <div>
                                        <Label>Rating</Label>
                                        <StarRatingInput
                                            value={feedbackForm.data.rating}
                                            onChange={(v) =>
                                                feedbackForm.setData(
                                                    'rating',
                                                    v,
                                                )
                                            }
                                        />
                                    </div>

                                    <div>
                                        <Label>Category</Label>
                                        <Select
                                            value={feedbackForm.data.category}
                                            onValueChange={(v) =>
                                                feedbackForm.setData(
                                                    'category',
                                                    v,
                                                )
                                            }
                                        >
                                            <SelectTrigger>
                                                <SelectValue />
                                            </SelectTrigger>
                                            <SelectContent>
                                                {Object.entries(
                                                    categoryLabels,
                                                ).map(([val, label]) => (
                                                    <SelectItem
                                                        key={val}
                                                        value={val}
                                                    >
                                                        {label}
                                                    </SelectItem>
                                                ))}
                                            </SelectContent>
                                        </Select>
                                    </div>

                                    <Button
                                        type="submit"
                                        disabled={feedbackForm.processing}
                                        className="w-full"
                                    >
                                        {feedbackForm.processing
                                            ? 'Submitting...'
                                            : 'Submit Feedback'}
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
                                <div className="rounded-lg bg-status-info p-2.5">
                                    <MessageSquare className="h-5 w-5 text-status-info" />
                                </div>
                                <div>
                                    <div className="text-2xl font-bold">
                                        {stats.total}
                                    </div>
                                    <div className="text-sm text-muted-foreground">
                                        Total Feedback
                                    </div>
                                </div>
                            </div>
                        </CardContent>
                    </Card>
                    <Card>
                        <CardContent className="pt-6">
                            <div className="flex items-center gap-3">
                                <div className="rounded-lg bg-status-warning p-2.5">
                                    <Star className="h-5 w-5 text-status-warning" />
                                </div>
                                <div>
                                    <div className="flex items-center gap-2">
                                        <span className="text-2xl font-bold">
                                            {stats.average_rating ?? '—'}
                                        </span>
                                        {stats.average_rating && (
                                            <StarRating
                                                rating={Math.round(
                                                    stats.average_rating,
                                                )}
                                            />
                                        )}
                                    </div>
                                    <div className="text-sm text-muted-foreground">
                                        Average Rating
                                    </div>
                                </div>
                            </div>
                        </CardContent>
                    </Card>
                    <Card>
                        <CardContent className="pt-6">
                            <div className="flex items-center gap-3">
                                <div className="rounded-lg bg-status-warning p-2.5">
                                    <AlertCircle className="h-5 w-5 text-status-warning" />
                                </div>
                                <div>
                                    <div className="text-2xl font-bold">
                                        {stats.open}
                                    </div>
                                    <div className="text-sm text-muted-foreground">
                                        Open Items
                                    </div>
                                </div>
                            </div>
                        </CardContent>
                    </Card>
                    <Card>
                        <CardContent className="pt-6">
                            <div className="flex items-center gap-3">
                                <div className="rounded-lg bg-status-success p-2.5">
                                    <TrendingUp className="h-5 w-5 text-status-success" />
                                </div>
                                <div>
                                    <div className="text-2xl font-bold">
                                        {stats.response_rate}%
                                    </div>
                                    <div className="text-sm text-muted-foreground">
                                        Response Rate
                                    </div>
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
                                <Label className="text-xs text-muted-foreground">
                                    Type
                                </Label>
                                <Select
                                    value={filters.type || ALL_SENTINEL}
                                    onValueChange={(v) =>
                                        applyFilter('type', v)
                                    }
                                >
                                    <SelectTrigger>
                                        <SelectValue placeholder="All Types" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value={ALL_SENTINEL}>
                                            All Types
                                        </SelectItem>
                                        {Object.entries(typeLabels).map(
                                            ([val, label]) => (
                                                <SelectItem
                                                    key={val}
                                                    value={val}
                                                >
                                                    {label}
                                                </SelectItem>
                                            ),
                                        )}
                                    </SelectContent>
                                </Select>
                            </div>
                            <div className="min-w-[140px]">
                                <Label className="text-xs text-muted-foreground">
                                    Status
                                </Label>
                                <Select
                                    value={filters.status || ALL_SENTINEL}
                                    onValueChange={(v) =>
                                        applyFilter('status', v)
                                    }
                                >
                                    <SelectTrigger>
                                        <SelectValue placeholder="All Statuses" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value={ALL_SENTINEL}>
                                            All Statuses
                                        </SelectItem>
                                        {Object.entries(statusLabels).map(
                                            ([val, label]) => (
                                                <SelectItem
                                                    key={val}
                                                    value={val}
                                                >
                                                    {label}
                                                </SelectItem>
                                            ),
                                        )}
                                    </SelectContent>
                                </Select>
                            </div>
                            <div className="min-w-[120px]">
                                <Label className="text-xs text-muted-foreground">
                                    Rating
                                </Label>
                                <Select
                                    value={filters.rating || ALL_SENTINEL}
                                    onValueChange={(v) =>
                                        applyFilter('rating', v)
                                    }
                                >
                                    <SelectTrigger>
                                        <SelectValue placeholder="Any" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value={ALL_SENTINEL}>
                                            Any Rating
                                        </SelectItem>
                                        {[5, 4, 3, 2, 1].map((r) => (
                                            <SelectItem
                                                key={r}
                                                value={String(r)}
                                            >
                                                {r} Star{r !== 1 ? 's' : ''}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            </div>
                            <div>
                                <Label className="text-xs text-muted-foreground">
                                    From
                                </Label>
                                <Input
                                    type="date"
                                    value={filters.from || ''}
                                    onChange={(e) =>
                                        applyFilter('from', e.target.value)
                                    }
                                    className="w-[150px]"
                                />
                            </div>
                            <div>
                                <Label className="text-xs text-muted-foreground">
                                    To
                                </Label>
                                <Input
                                    type="date"
                                    value={filters.to || ''}
                                    onChange={(e) =>
                                        applyFilter('to', e.target.value)
                                    }
                                    className="w-[150px]"
                                />
                            </div>
                            {(filters.type ||
                                filters.status ||
                                filters.rating ||
                                filters.from ||
                                filters.to) && (
                                <Button
                                    variant="ghost"
                                    size="sm"
                                    onClick={clearFilters}
                                >
                                    Clear
                                </Button>
                            )}
                        </div>
                    </CardContent>
                </Card>

                {/* Feedback List */}
                <div className="space-y-3">
                    {feedback.data.length === 0 ? (
                        <Card>
                            <CardContent className="py-12 text-center">
                                <MessageSquare className="mx-auto mb-3 h-12 w-12 text-muted-foreground opacity-50" />
                                <p className="text-muted-foreground">
                                    No feedback found
                                </p>
                                <p className="mt-1 text-sm text-muted-foreground">
                                    Submit feedback to start tracking quality
                                    and engagement
                                </p>
                            </CardContent>
                        </Card>
                    ) : (
                        feedback.data.map((item) => (
                            <Card key={item.id}>
                                <CardContent className="space-y-3 pt-5 pb-4">
                                    {/* Header row */}
                                    <div className="flex items-start justify-between gap-3">
                                        <div className="flex flex-wrap items-center gap-2">
                                            <Badge
                                                variant="outline"
                                                className={
                                                    typeColors[
                                                        item.feedback_type
                                                    ] ||
                                                    'border-border/30 text-muted-foreground'
                                                }
                                            >
                                                {typeLabels[
                                                    item.feedback_type
                                                ] || item.feedback_type}
                                            </Badge>
                                            {item.rating && (
                                                <StarRating
                                                    rating={item.rating}
                                                />
                                            )}
                                            {item.category && (
                                                <Badge
                                                    variant="outline"
                                                    className="border-border/30 text-xs text-muted-foreground"
                                                >
                                                    {categoryLabels[
                                                        item.category
                                                    ] || item.category}
                                                </Badge>
                                            )}
                                        </div>
                                        <div className="flex shrink-0 items-center gap-2">
                                            <Badge
                                                variant="outline"
                                                className={
                                                    statusColors[item.status] ||
                                                    ''
                                                }
                                            >
                                                {statusLabels[item.status] ||
                                                    item.status}
                                            </Badge>
                                            <Select
                                                value={item.status}
                                                onValueChange={(v) =>
                                                    updateStatus(item.id, v)
                                                }
                                            >
                                                <SelectTrigger className="h-7 w-7 border-0 p-0 [&>svg]:hidden">
                                                    <span className="sr-only">
                                                        Change status
                                                    </span>
                                                    <Clock className="h-3.5 w-3.5 text-muted-foreground" />
                                                </SelectTrigger>
                                                <SelectContent>
                                                    {Object.entries(
                                                        statusLabels,
                                                    ).map(([val, label]) => (
                                                        <SelectItem
                                                            key={val}
                                                            value={val}
                                                        >
                                                            {label}
                                                        </SelectItem>
                                                    ))}
                                                </SelectContent>
                                            </Select>
                                        </div>
                                    </div>

                                    {/* Content */}
                                    <p className="text-sm whitespace-pre-wrap text-foreground">
                                        {item.content}
                                    </p>

                                    {/* Submitted by */}
                                    <div className="flex items-center gap-4 text-xs text-muted-foreground">
                                        {item.is_anonymous ? (
                                            <span className="flex items-center gap-1">
                                                <User className="h-3 w-3" />{' '}
                                                Anonymous
                                            </span>
                                        ) : item.submitted_by_name ? (
                                            <span className="flex items-center gap-1">
                                                <User className="h-3 w-3" />
                                                {item.submitted_by_name}
                                                {item.submitted_by_relationship &&
                                                    ` (${item.submitted_by_relationship})`}
                                            </span>
                                        ) : null}
                                        <span>
                                            {new Date(
                                                item.created_at,
                                            ).toLocaleDateString('en-NZ', {
                                                day: 'numeric',
                                                month: 'short',
                                                year: 'numeric',
                                            })}
                                        </span>
                                    </div>

                                    {/* Response section */}
                                    {item.response && (
                                        <div className="rounded-lg border-l-2 border-status-success/50 bg-muted/50 p-3">
                                            <div className="mb-1 flex items-center gap-2 text-xs text-muted-foreground">
                                                <CheckCircle2 className="h-3 w-3 text-status-success" />
                                                Response from{' '}
                                                {item.responded_by?.name ||
                                                    'Staff'}
                                                {item.responded_at && (
                                                    <span>
                                                        {' '}
                                                        —{' '}
                                                        {new Date(
                                                            item.responded_at,
                                                        ).toLocaleDateString(
                                                            'en-NZ',
                                                            {
                                                                day: 'numeric',
                                                                month: 'short',
                                                                year: 'numeric',
                                                            },
                                                        )}
                                                    </span>
                                                )}
                                            </div>
                                            <p className="text-sm whitespace-pre-wrap text-muted-foreground">
                                                {item.response}
                                            </p>
                                        </div>
                                    )}

                                    {/* Respond button / form */}
                                    {!item.response &&
                                        respondingId !== item.id && (
                                            <Button
                                                variant="outline"
                                                size="sm"
                                                onClick={() =>
                                                    setRespondingId(item.id)
                                                }
                                            >
                                                <Send className="mr-1 h-3.5 w-3.5" />
                                                Respond
                                            </Button>
                                        )}

                                    {respondingId === item.id && (
                                        <div className="space-y-2">
                                            <Textarea
                                                value={
                                                    responseForm.data.response
                                                }
                                                onChange={(e) =>
                                                    responseForm.setData(
                                                        'response',
                                                        e.target.value,
                                                    )
                                                }
                                                placeholder="Write your response..."
                                                rows={3}
                                            />
                                            <div className="flex items-center gap-2">
                                                <Button
                                                    size="sm"
                                                    onClick={() =>
                                                        submitResponse(item.id)
                                                    }
                                                    disabled={
                                                        responseForm.processing ||
                                                        !responseForm.data.response.trim()
                                                    }
                                                >
                                                    {responseForm.processing
                                                        ? 'Sending...'
                                                        : 'Send Response'}
                                                </Button>
                                                <Button
                                                    variant="ghost"
                                                    size="sm"
                                                    onClick={() => {
                                                        setRespondingId(null);
                                                        responseForm.reset();
                                                    }}
                                                >
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
                                    <Link
                                        href={link.url}
                                        preserveScroll
                                        dangerouslySetInnerHTML={{
                                            __html: link.label,
                                        }}
                                    />
                                ) : (
                                    <span
                                        dangerouslySetInnerHTML={{
                                            __html: link.label,
                                        }}
                                    />
                                )}
                            </Button>
                        ))}
                    </div>
                )}
            </PageShell>
        </AppLayout>
    );
}
