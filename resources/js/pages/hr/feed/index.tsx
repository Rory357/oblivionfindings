import { RecognitionDialog } from '@/components/hr/recognition-dialog';
import { PageHero, PageLayout } from '@/components/page';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Label } from '@/components/ui/label';
import { LaravelPagination } from '@/components/ui/laravel-pagination';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Textarea } from '@/components/ui/textarea';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, router, useForm } from '@inertiajs/react';
import { Briefcase, Gift, Heart, Pin, Rss, Star, Trophy } from 'lucide-react';
import { useState } from 'react';

type User = { id: number; name: string };

type KudosData = {
    id: number;
    category: string;
    from_user: User | null;
    to_user: User | null;
};

type Post = {
    id: number;
    post_type: string;
    content: string;
    is_pinned: boolean;
    user: User | null;
    kudos: KudosData | null;
    created_at: string;
    created_at_date: string;
};

type Milestone = {
    type: string;
    user_name: string;
    user_id: number;
    date: string;
    years?: number;
    position?: string;
};

type LeaderboardEntry = {
    user_id: number;
    user_name: string;
    kudos_count: number;
};

type Props = {
    posts: {
        data: Post[];
        links: Array<{ url: string | null; label: string; active: boolean }>;
    };
    milestones: {
        birthdays: Milestone[];
        anniversaries: Milestone[];
        new_hires: Milestone[];
    };
    leaderboard: LeaderboardEntry[];
    filters: { type: string | null };
    kudosCategories: Record<string, string>;
    postTypes: string[];
    employees: User[];
};

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'HR', href: '/hr' },
    { title: 'Community Feed', href: '/hr/feed' },
];

const postTypeBadge: Record<string, { className: string; label: string }> = {
    update: {
        className: 'border-status-info/30 text-status-info bg-status-info',
        label: 'Update',
    },
    milestone: {
        className:
            'border-status-warning/30 text-status-warning bg-status-warning',
        label: 'Milestone',
    },
    kudos: {
        className:
            'border-status-critical/30 text-status-critical bg-status-critical',
        label: 'Kudos',
    },
    announcement: {
        className:
            'border-status-success/30 text-status-success bg-status-success',
        label: 'Announcement',
    },
};

export default function FeedIndex({
    posts,
    milestones,
    leaderboard,
    filters,
    kudosCategories,
    employees,
}: Props) {
    const [kudosOpen, setKudosOpen] = useState(false);

    const postForm = useForm({
        content: '',
        post_type: 'update',
    });

    const onFilter = (next: Partial<typeof filters>) => {
        router.get(
            '/hr/feed',
            { ...filters, ...next },
            { preserveState: true, preserveScroll: true },
        );
    };

    const submitPost = (e: React.FormEvent) => {
        e.preventDefault();
        postForm.post('/hr/feed', {
            preserveScroll: true,
            onSuccess: () => postForm.reset(),
        });
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Community Feed" />
            <PageLayout
                    hero={
                        <PageHero category="hr"
                            icon={Rss}
                            title="Community Feed"
                            description="Stay connected with your team."
                            stats={[
                                { label: 'Posts', value: posts.data.length },
                                { label: 'Birthdays', value: milestones.birthdays.length },
                                { label: 'Anniversaries', value: milestones.anniversaries.length },
                                { label: 'New hires', value: milestones.new_hires.length },
                            ]}
                            actions={
                                <Button
                                    size="sm"
                                    onClick={() => setKudosOpen(true)}
                                >
                                    <Heart className="mr-1.5 h-4 w-4" />
                                    Send Kudos
                                </Button>
                            }
                        />
                    }
                >
                    <div className="grid grid-cols-1 gap-6 lg:grid-cols-4">
                    {/* Left Sidebar — Milestones */}
                    <div className="space-y-4 lg:col-span-1">
                        <Card>
                            <CardHeader className="pb-3">
                                <CardTitle className="flex items-center gap-2 text-sm">
                                    <Gift className="h-4 w-4 text-status-critical" />
                                    Upcoming Birthdays
                                </CardTitle>
                            </CardHeader>
                            <CardContent className="pt-0">
                                {milestones.birthdays.length === 0 ? (
                                    <p className="text-sm text-muted-foreground">
                                        No upcoming birthdays
                                    </p>
                                ) : (
                                    <ul className="space-y-2">
                                        {milestones.birthdays.map((m, i) => (
                                            <li
                                                key={i}
                                                className="flex items-center justify-between text-sm"
                                            >
                                                <span>{m.user_name}</span>
                                                <span className="text-muted-foreground">
                                                    {m.date}
                                                </span>
                                            </li>
                                        ))}
                                    </ul>
                                )}
                            </CardContent>
                        </Card>

                        <Card>
                            <CardHeader className="pb-3">
                                <CardTitle className="flex items-center gap-2 text-sm">
                                    <Star className="h-4 w-4 text-status-warning" />
                                    Work Anniversaries
                                </CardTitle>
                            </CardHeader>
                            <CardContent className="pt-0">
                                {milestones.anniversaries.length === 0 ? (
                                    <p className="text-sm text-muted-foreground">
                                        No upcoming anniversaries
                                    </p>
                                ) : (
                                    <ul className="space-y-2">
                                        {milestones.anniversaries.map(
                                            (m, i) => (
                                                <li
                                                    key={i}
                                                    className="flex items-center justify-between text-sm"
                                                >
                                                    <span>{m.user_name}</span>
                                                    <span className="text-muted-foreground">
                                                        {m.years}yr - {m.date}
                                                    </span>
                                                </li>
                                            ),
                                        )}
                                    </ul>
                                )}
                            </CardContent>
                        </Card>

                        <Card>
                            <CardHeader className="pb-3">
                                <CardTitle className="flex items-center gap-2 text-sm">
                                    <Briefcase className="h-4 w-4 text-status-success" />
                                    New Hires
                                </CardTitle>
                            </CardHeader>
                            <CardContent className="pt-0">
                                {milestones.new_hires.length === 0 ? (
                                    <p className="text-sm text-muted-foreground">
                                        No recent new hires
                                    </p>
                                ) : (
                                    <ul className="space-y-2">
                                        {milestones.new_hires.map((m, i) => (
                                            <li key={i} className="text-sm">
                                                <div className="font-medium">
                                                    {m.user_name}
                                                </div>
                                                {m.position && (
                                                    <div className="text-muted-foreground">
                                                        {m.position}
                                                    </div>
                                                )}
                                            </li>
                                        ))}
                                    </ul>
                                )}
                            </CardContent>
                        </Card>
                    </div>

                    {/* Main Feed */}
                    <div className="space-y-4 lg:col-span-2">
                        {/* Post Creation Form */}
                        <Card>
                            <CardContent className="pt-6">
                                <form
                                    onSubmit={submitPost}
                                    className="space-y-3"
                                >
                                    <Textarea
                                        value={postForm.data.content}
                                        onChange={(e) =>
                                            postForm.setData(
                                                'content',
                                                e.target.value,
                                            )
                                        }
                                        placeholder="Share an update with your team..."
                                        rows={3}
                                    />
                                    {postForm.errors.content && (
                                        <p className="text-sm text-destructive">
                                            {postForm.errors.content}
                                        </p>
                                    )}
                                    <div className="flex items-center justify-between">
                                        <Select
                                            value={postForm.data.post_type}
                                            onValueChange={(v) =>
                                                postForm.setData(
                                                    'post_type',
                                                    v as
                                                        | 'update'
                                                        | 'announcement',
                                                )
                                            }
                                        >
                                            <SelectTrigger className="w-40">
                                                <SelectValue />
                                            </SelectTrigger>
                                            <SelectContent>
                                                <SelectItem value="update">
                                                    Update
                                                </SelectItem>
                                                <SelectItem value="announcement">
                                                    Announcement
                                                </SelectItem>
                                            </SelectContent>
                                        </Select>
                                        <Button
                                            type="submit"
                                            size="sm"
                                            disabled={postForm.processing}
                                        >
                                            Post
                                        </Button>
                                    </div>
                                </form>
                            </CardContent>
                        </Card>

                        {/* Type Filter */}
                        <div className="flex gap-2">
                            {[
                                'all',
                                'update',
                                'kudos',
                                'announcement',
                                'milestone',
                            ].map((t) => (
                                <Button
                                    key={t}
                                    variant={
                                        (!filters.type && t === 'all') ||
                                        filters.type === t
                                            ? 'default'
                                            : 'outline'
                                    }
                                    size="sm"
                                    onClick={() =>
                                        onFilter({
                                            type: t === 'all' ? null : t,
                                        })
                                    }
                                >
                                    <span className="capitalize">{t}</span>
                                </Button>
                            ))}
                        </div>

                        {/* Posts */}
                        {posts.data.map((post) => {
                            const badge =
                                postTypeBadge[post.post_type] ||
                                postTypeBadge.update;
                            return (
                                <Card key={post.id}>
                                    <CardContent className="pt-6">
                                        <div className="flex items-start gap-3">
                                            <div className="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-muted text-sm font-medium">
                                                {post.user?.name
                                                    ?.charAt(0)
                                                    ?.toUpperCase() ?? '?'}
                                            </div>
                                            <div className="min-w-0 flex-1">
                                                <div className="flex items-center gap-2">
                                                    <span className="font-medium">
                                                        {post.user?.name ??
                                                            'Unknown'}
                                                    </span>
                                                    <Badge
                                                        variant="outline"
                                                        className={
                                                            badge.className
                                                        }
                                                    >
                                                        {badge.label}
                                                    </Badge>
                                                    {post.is_pinned && (
                                                        <Pin className="h-3.5 w-3.5 text-status-warning" />
                                                    )}
                                                    <span className="text-xs text-muted-foreground">
                                                        {post.created_at}
                                                    </span>
                                                </div>

                                                {post.post_type === 'kudos' &&
                                                    post.kudos && (
                                                        <div className="mt-1 flex items-center gap-1 text-sm text-status-critical">
                                                            <Heart className="h-3.5 w-3.5" />
                                                            <span>
                                                                gave kudos to{' '}
                                                                <strong>
                                                                    {
                                                                        post
                                                                            .kudos
                                                                            .to_user
                                                                            ?.name
                                                                    }
                                                                </strong>{' '}
                                                                for{' '}
                                                                <span className="capitalize">
                                                                    {post.kudos.category.replace(
                                                                        '_',
                                                                        ' ',
                                                                    )}
                                                                </span>
                                                            </span>
                                                        </div>
                                                    )}

                                                <p className="mt-2 text-sm whitespace-pre-wrap">
                                                    {post.content}
                                                </p>
                                            </div>
                                        </div>
                                    </CardContent>
                                </Card>
                            );
                        })}

                        {posts.data.length === 0 && (
                            <Card>
                                <CardContent className="py-12 text-center text-muted-foreground">
                                    No posts yet. Be the first to share
                                    something!
                                </CardContent>
                            </Card>
                        )}

                        {/* Pagination */}
                        {posts.links?.length > 3 && (
                            <LaravelPagination links={posts.links} />
                        )}
                    </div>

                    {/* Right Sidebar — Kudos Leaderboard */}
                    <div className="lg:col-span-1">
                        <Card>
                            <CardHeader className="pb-3">
                                <CardTitle className="flex items-center gap-2 text-sm">
                                    <Trophy className="h-4 w-4 text-status-warning" />
                                    Kudos Leaderboard
                                </CardTitle>
                            </CardHeader>
                            <CardContent className="pt-0">
                                {leaderboard.length === 0 ? (
                                    <p className="text-sm text-muted-foreground">
                                        No kudos given yet
                                    </p>
                                ) : (
                                    <ul className="space-y-3">
                                        {leaderboard.map((entry, i) => (
                                            <li
                                                key={entry.user_id}
                                                className="flex items-center gap-3"
                                            >
                                                <span className="flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-muted text-xs font-bold">
                                                    {i + 1}
                                                </span>
                                                <div className="min-w-0 flex-1">
                                                    <div className="truncate text-sm font-medium">
                                                        {entry.user_name}
                                                    </div>
                                                </div>
                                                <Badge
                                                    variant="secondary"
                                                    className="shrink-0"
                                                >
                                                    {entry.kudos_count}
                                                </Badge>
                                            </li>
                                        ))}
                                    </ul>
                                )}
                            </CardContent>
                        </Card>
                    </div>
                </div>

                <RecognitionDialog
                    open={kudosOpen}
                    onClose={() => setKudosOpen(false)}
                    employees={employees}
                    kudosCategories={kudosCategories}
                />
                </PageLayout>
        </AppLayout>
    );
}
