/* eslint-disable no-restricted-syntax -- The composer bar + filter tabs are
 * bespoke on-page surfaces (raw <button>/<input>) styled with semantic tokens. */
import {
    AnnounceWizard,
    ComposeWizard,
    RecognitionInsightsDialog,
    RecognitionWizard,
} from '@/components/recognition';
import { type RecognitionDefaults } from '@/components/recognition/recognition-wizard';
import { FeedHero, type FeedCelebration, type FeedMetrics } from '@/components/hr/feed-hero';
import { PageLayout } from '@/components/page';
import { Input } from '@/components/ui/input';
import { LaravelPagination } from '@/components/ui/laravel-pagination';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, router } from '@inertiajs/react';
import { Heart, Megaphone, Search, Sparkles } from 'lucide-react';
import { useMemo, useState } from 'react';

import {
    AnnouncementCard,
    CelebrationsCard,
    FeedEmpty,
    KudosCard,
    TopRecognised,
    UpdateCard,
    type FeedAnnouncement,
    type FeedEmployee,
    type FeedPost,
    type LeaderboardEntry,
    type Milestone,
} from './parts';

type Props = {
    posts: {
        data: FeedPost[];
        links: Array<{ url: string | null; label: string; active: boolean }>;
    };
    announcements: FeedAnnouncement[];
    metrics: FeedMetrics;
    milestones: {
        birthdays: Milestone[];
        anniversaries: Milestone[];
        new_hires: Milestone[];
    };
    leaderboard: LeaderboardEntry[];
    valueBreakdown: Array<{ key: string; label: string; count: number }>;
    filters: { type: string | null };
    kudosCategories: Record<string, string>;
    kudosImpacts: Record<string, string>;
    reactionEmojis: string[];
    employees: FeedEmployee[];
    sites: Array<{ id: number; name: string }>;
    currentUserId: number;
    can: { manageAnnouncements: boolean };
};

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'HR', href: '/hr' },
    { title: 'Community & Recognition', href: '/hr/feed' },
];

const TABS = [
    { key: 'all', label: 'All', type: null as string | null },
    { key: 'updates', label: 'Updates', type: 'update' as string | null },
    { key: 'kudos', label: 'Kudos', type: 'kudos' as string | null },
    { key: 'notices', label: 'Notices', type: 'announcement' as string | null },
];

function congratsMessage(kind: string): string {
    if (kind === 'anniversary') return 'Happy work anniversary! 🎉 Thank you for everything you bring to the team.';
    if (kind === 'birthday') return 'Happy birthday! 🎂 Hope you have a wonderful day.';
    if (kind === 'new_hire') return 'Welcome to the team! 👋 So glad to have you with us.';
    return '';
}

export default function FeedIndex({
    posts,
    announcements,
    metrics,
    milestones,
    leaderboard,
    valueBreakdown,
    filters,
    kudosCategories,
    kudosImpacts,
    employees,
    sites,
    can,
}: Props) {
    const [recogOpen, setRecogOpen] = useState(false);
    const [recogDefaults, setRecogDefaults] = useState<RecognitionDefaults | undefined>(undefined);
    const [composeOpen, setComposeOpen] = useState(false);
    const [announceOpen, setAnnounceOpen] = useState(false);
    const [insightsOpen, setInsightsOpen] = useState(false);
    const [search, setSearch] = useState('');

    const activeTab =
        filters.type === 'update'
            ? 'updates'
            : filters.type === 'kudos'
              ? 'kudos'
              : filters.type === 'announcement'
                ? 'notices'
                : 'all';
    const showAnnouncements = activeTab === 'all' || activeTab === 'notices';

    const employeeById = useMemo(
        () => new Map(employees.map((e) => [e.id, e])),
        [employees],
    );

    const allMilestones = useMemo<Milestone[]>(
        () => [...milestones.anniversaries, ...milestones.birthdays, ...milestones.new_hires],
        [milestones],
    );

    const heroCelebrations = useMemo<FeedCelebration[]>(() => {
        const items: FeedCelebration[] = [];
        for (const m of milestones.anniversaries) {
            if ((m.days_away ?? 99) <= 7) {
                items.push({
                    user_id: m.user_id,
                    user_name: m.user_name,
                    kind: 'anniversary',
                    sublabel: `${m.years ?? ''}-year work anniversary`.trim(),
                });
            }
        }
        for (const m of milestones.birthdays) {
            if ((m.days_away ?? 99) <= 7) {
                items.push({
                    user_id: m.user_id,
                    user_name: m.user_name,
                    kind: 'birthday',
                    sublabel: m.days_away === 0 ? 'Birthday today' : `Birthday · ${m.date}`,
                });
            }
        }
        for (const m of milestones.new_hires) {
            if ((m.days_away ?? -99) >= -7) {
                items.push({
                    user_id: m.user_id,
                    user_name: m.user_name,
                    kind: 'new_hire',
                    sublabel: m.position ? `New hire · ${m.position}` : 'New hire',
                });
            }
        }
        return items;
    }, [milestones]);

    const onFilter = (type: string | null) => {
        router.get('/hr/feed', type ? { type } : {}, { preserveState: true, preserveScroll: true });
    };

    const openRecognition = (defaults?: RecognitionDefaults) => {
        setRecogDefaults(defaults);
        setRecogOpen(true);
    };

    const congratulate = (userId: number, kind: string) => {
        openRecognition({
            recipients: [String(userId)],
            message: congratsMessage(kind),
            openStep: 1,
        });
    };

    const q = search.trim().toLowerCase();
    const visiblePosts = useMemo(
        () =>
            posts.data.filter((p) => {
                if (!q) return true;
                return (
                    p.content.toLowerCase().includes(q) ||
                    (p.user?.name.toLowerCase().includes(q) ?? false) ||
                    (p.kudos?.to_user?.name?.toLowerCase().includes(q) ?? false)
                );
            }),
        [posts.data, q],
    );
    const visibleAnnouncements = useMemo(
        () =>
            showAnnouncements
                ? announcements.filter(
                      (a) => !q || a.title.toLowerCase().includes(q) || a.content.toLowerCase().includes(q),
                  )
                : [],
        [announcements, q, showAnnouncements],
    );

    const wallEmpty = visiblePosts.length === 0 && visibleAnnouncements.length === 0;

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Community & Recognition" />
            <PageLayout
                hero={
                    <FeedHero
                        metrics={metrics}
                        celebrations={heroCelebrations}
                        canAnnounce={can.manageAnnouncements}
                        onGiveRecognition={() => openRecognition(undefined)}
                        onPostUpdate={() => setComposeOpen(true)}
                        onMakeAnnouncement={() => setAnnounceOpen(true)}
                        onViewInsights={() => setInsightsOpen(true)}
                        onCongratulate={(c) => congratulate(c.user_id, c.kind)}
                    />
                }
            >
                <div className="grid grid-cols-1 gap-6 lg:grid-cols-3">
                    {/* Main wall */}
                    <div className="space-y-4 lg:col-span-2">
                        {/* Composer + search */}
                        <div className="flex flex-col gap-3 sm:flex-row sm:items-center">
                            <button
                                type="button"
                                onClick={() => setComposeOpen(true)}
                                className="flex flex-1 items-center gap-3 rounded-xl border border-border bg-card px-4 py-3 text-left text-sm text-muted-foreground transition-colors hover:border-primary/40"
                            >
                                <Sparkles className="h-4 w-4 text-primary" />
                                Share an update with your team…
                            </button>
                            <div className="flex items-center gap-1.5">
                                <button
                                    type="button"
                                    onClick={() => openRecognition(undefined)}
                                    aria-label="Give recognition"
                                    title="Give recognition"
                                    className="grid h-10 w-10 place-items-center rounded-xl border border-border bg-card text-status-critical transition-colors hover:border-primary/40"
                                >
                                    <Heart className="h-4 w-4" />
                                </button>
                                {can.manageAnnouncements ? (
                                    <button
                                        type="button"
                                        onClick={() => setAnnounceOpen(true)}
                                        aria-label="Make announcement"
                                        title="Make announcement"
                                        className="grid h-10 w-10 place-items-center rounded-xl border border-border bg-card text-primary transition-colors hover:border-primary/40"
                                    >
                                        <Megaphone className="h-4 w-4" />
                                    </button>
                                ) : null}
                            </div>
                        </div>

                        {/* Tabs + search */}
                        <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                            <div className="flex flex-wrap gap-1 rounded-lg bg-muted p-1">
                                {TABS.map((t) => {
                                    const active = activeTab === t.key;
                                    return (
                                        <button
                                            key={t.key}
                                            type="button"
                                            onClick={() => onFilter(t.type)}
                                            aria-pressed={active}
                                            className={
                                                'rounded-md px-3 py-1.5 text-[13px] font-semibold transition-colors ' +
                                                (active
                                                    ? 'bg-card text-foreground shadow-sm'
                                                    : 'text-muted-foreground hover:text-foreground')
                                            }
                                        >
                                            {t.label}
                                        </button>
                                    );
                                })}
                            </div>
                            <div className="relative sm:w-56">
                                <Search className="pointer-events-none absolute left-2.5 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
                                <Input
                                    value={search}
                                    onChange={(e) => setSearch(e.target.value)}
                                    placeholder="Search the wall"
                                    className="pl-8"
                                />
                            </div>
                        </div>

                        {/* Wall */}
                        {visibleAnnouncements.map((a) => (
                            <AnnouncementCard key={`a-${a.id}`} announcement={a} employeeById={employeeById} />
                        ))}

                        {visiblePosts.map((post) =>
                            post.post_type === 'kudos' && post.kudos ? (
                                <KudosCard
                                    key={`p-${post.id}`}
                                    post={post}
                                    categoryLabel={kudosCategories[post.kudos.category] ?? post.kudos.category}
                                    impactLabel={kudosImpacts[post.kudos.impact] ?? post.kudos.impact}
                                    employeeById={employeeById}
                                />
                            ) : (
                                <UpdateCard key={`p-${post.id}`} post={post} employeeById={employeeById} />
                            ),
                        )}

                        {wallEmpty ? (
                            <FeedEmpty
                                label={
                                    q
                                        ? 'No posts match your search.'
                                        : 'Nothing here yet — be the first to share or recognise a colleague!'
                                }
                            />
                        ) : null}

                        {posts.links?.length > 3 ? <LaravelPagination links={posts.links} /> : null}
                    </div>

                    {/* Right sidebar */}
                    <div className="space-y-4">
                        <TopRecognised leaderboard={leaderboard} />
                        <CelebrationsCard
                            milestones={allMilestones}
                            onCongratulate={(m) => congratulate(m.user_id, m.type)}
                        />
                    </div>
                </div>
            </PageLayout>

            <RecognitionWizard
                open={recogOpen}
                onClose={() => setRecogOpen(false)}
                employees={employees}
                kudosCategories={kudosCategories}
                kudosImpacts={kudosImpacts}
                defaults={recogDefaults}
            />
            <ComposeWizard open={composeOpen} onClose={() => setComposeOpen(false)} />
            <AnnounceWizard open={announceOpen} onClose={() => setAnnounceOpen(false)} sites={sites} />
            <RecognitionInsightsDialog
                open={insightsOpen}
                onClose={() => setInsightsOpen(false)}
                metrics={metrics}
                valueBreakdown={valueBreakdown}
                leaderboard={leaderboard}
            />
        </AppLayout>
    );
}
