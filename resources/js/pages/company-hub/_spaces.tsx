import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { formatDate } from '@/lib/datetime';
import { router } from '@inertiajs/react';
import { ArrowRight, Bell, Flag, Plus, Search, UsersRound } from 'lucide-react';
import { useState } from 'react';
import { HubBrowseSelect } from './_browse-select';
import { HubBrowseLink, useHubBrowseState } from './_browse-state';
import { useHubPageCreation } from './_page-creation-context';
import { pageMatches } from './_reader-discovery';
import type { HubProps } from './_types';

export function CompanySpaces({
    data,
    mode,
}: {
    data: HubProps;
    mode: 'teams' | 'campaigns' | 'following';
}) {
    const { state, set, browse } = useHubBrowseState(
        mode,
        { query: '', category: 'all', sort: 'newest', personal: 'all' },
        data.demo,
    );
    const [limit, setLimit] = useState(24);
    const [pending, setPending] = useState<number | null>(null);
    const [error, setError] = useState('');
    const create = useHubPageCreation();
    const followed = data.followedPages ?? [];
    const all = data.pages.filter((page) =>
        mode === 'following'
            ? followed.includes(page.id)
            : page.kind === (mode === 'teams' ? 'department' : 'campaign'),
    );
    const pages = all
        .filter(
            (page) =>
                pageMatches(page, state.query) &&
                (state.category === 'all' ||
                    page.category === state.category) &&
                (state.personal !== 'following' || followed.includes(page.id)),
        )
        .sort((a, b) =>
            state.sort === 'name'
                ? a.title.localeCompare(b.title)
                : state.sort === 'oldest'
                  ? a.published_at.localeCompare(b.published_at)
                  : b.published_at.localeCompare(a.published_at),
        );
    const filtered =
        !!state.query || state.category !== 'all' || state.personal !== 'all';
    const reset = () => {
        set('query', '');
        set('category', 'all');
        set('personal', 'all');
        setLimit(24);
    };
    const start = () =>
        create?.({
            initial: {
                title: '',
                kind: mode === 'teams' ? 'department' : 'campaign',
                template: mode === 'teams' ? 'team' : 'campaign',
            },
        });
    return (
        <section
            className="hub-space-directory space-y-4"
            aria-label={mode === 'teams' ? 'Team spaces' : 'Company campaigns'}
        >
            <div className="hub-library-tools">
                <label className="hub-library-search">
                    <Search className="size-4" />
                    <Input
                        aria-label={`Search ${mode}`}
                        placeholder={
                            mode === 'teams'
                                ? 'Find a team, owner or area of work…'
                                : 'Find a campaign, topic or owner…'
                        }
                        value={state.query}
                        onChange={(e) => {
                            set('query', e.target.value);
                            setLimit(24);
                        }}
                    />
                </label>
                <HubBrowseSelect
                    label="Category"
                    value={state.category}
                    onChange={(value) => {
                        set('category', value);
                        setLimit(24);
                    }}
                    options={[
                        { value: 'all', label: 'All categories' },
                        ...[
                            ...new Set(
                                all
                                    .map((page) => page.category)
                                    .filter(Boolean),
                            ),
                        ].map((value) => ({ value, label: value })),
                    ]}
                />
                <HubBrowseSelect
                    label={`Sort ${mode}`}
                    value={state.sort}
                    onChange={(value) => {
                        set('sort', value);
                        setLimit(24);
                    }}
                    options={[
                        { value: 'newest', label: 'Newest first' },
                        { value: 'oldest', label: 'Oldest first' },
                        { value: 'name', label: 'A–Z' },
                    ]}
                />
                {create &&
                    data.capabilities.write &&
                    !data.demo &&
                    mode !== 'following' && (
                        <Button onClick={start}>
                            <Plus className="size-4" />
                            {mode === 'teams'
                                ? 'Create team space'
                                : 'Create campaign'}
                        </Button>
                    )}
            </div>
            <div className="flex items-center justify-between gap-3">
                <div className="flex gap-1">
                    <Button
                        size="sm"
                        variant={
                            state.personal === 'all' ? 'secondary' : 'ghost'
                        }
                        aria-pressed={state.personal === 'all'}
                        onClick={() => set('personal', 'all')}
                    >
                        All {mode}
                    </Button>
                    <Button
                        size="sm"
                        variant={
                            state.personal === 'following'
                                ? 'secondary'
                                : 'ghost'
                        }
                        aria-pressed={state.personal === 'following'}
                        onClick={() => set('personal', 'following')}
                    >
                        Following
                    </Button>
                </div>
                <p className="text-xs text-muted-foreground" role="status">
                    {pages.length} {mode}
                    {filtered && (
                        <Button size="sm" variant="link" onClick={reset}>
                            Clear filters
                        </Button>
                    )}
                </p>
            </div>
            {error && (
                <p role="alert" className="text-sm text-destructive">
                    {error}
                </p>
            )}
            <div className="hub-library-results">
                {pages.slice(0, limit).map((page) => (
                    <article key={page.id} className="hub-space-card">
                        {page.cover_url ? (
                            <img src={page.cover_url} alt="" loading="lazy" />
                        ) : (
                            <div className="hub-space-cover">
                                {mode === 'teams' ? (
                                    <UsersRound className="size-10" />
                                ) : (
                                    <Flag className="size-10" />
                                )}
                            </div>
                        )}
                        <div>
                            <span className="text-xs text-primary">
                                {page.category}
                            </span>
                            <HubBrowseLink
                                className="mt-2 block text-lg font-semibold"
                                browse={browse}
                                returnKey={`space-${page.id}`}
                                href={
                                    data.demo
                                        ? `/company-hub/showcase?story=${page.slug}`
                                        : `/company-hub/read/${page.slug}`
                                }
                            >
                                {page.title}
                            </HubBrowseLink>
                            <p className="mt-2 text-sm text-muted-foreground">
                                {page.summary}
                            </p>
                            {!!page.campaign_progress?.total && (
                                <div className="mt-4 space-y-1">
                                    <progress
                                        className="h-1.5 w-full accent-primary"
                                        value={page.campaign_progress.done}
                                        max={page.campaign_progress.total}
                                        aria-label={`${page.title} milestones`}
                                    />
                                    <p className="text-xs text-muted-foreground">
                                        {page.campaign_progress.done} of{' '}
                                        {page.campaign_progress.total}{' '}
                                        milestones complete
                                    </p>
                                </div>
                            )}
                            <div className="mt-4 flex items-center justify-between gap-3 border-t pt-3">
                                <span className="text-xs text-muted-foreground">
                                    {page.owner}
                                    <span className="mt-1 block">
                                        Published{' '}
                                        {formatDate(page.published_at)}
                                    </span>
                                </span>
                                <Button
                                    size="sm"
                                    variant={
                                        followed.includes(page.id)
                                            ? 'secondary'
                                            : 'outline'
                                    }
                                    aria-pressed={followed.includes(page.id)}
                                    disabled={data.demo || pending !== null}
                                    onClick={() => {
                                        setPending(page.id);
                                        setError('');
                                        router.post(
                                            `/company-hub/pages/${page.id}/follow`,
                                            {
                                                following: !followed.includes(
                                                    page.id,
                                                ),
                                            },
                                            {
                                                preserveScroll: true,
                                                onError: () =>
                                                    setError(
                                                        'Your following preference could not be saved. Please try again.',
                                                    ),
                                                onFinish: () =>
                                                    setPending(null),
                                            },
                                        );
                                    }}
                                >
                                    <Bell className="size-3.5" />
                                    {pending === page.id
                                        ? 'Saving…'
                                        : followed.includes(page.id)
                                          ? 'Following'
                                          : 'Follow'}
                                </Button>
                            </div>
                        </div>
                    </article>
                ))}
            </div>
            {!pages.length && (
                <div className="hub-purposeful-empty">
                    <span className="hub-empty-symbol">
                        {mode === 'teams' ? <UsersRound /> : <Flag />}
                    </span>
                    <div>
                        <h3 className="font-semibold">
                            {filtered
                                ? 'No matches in this view'
                                : mode === 'teams'
                                  ? 'Bring your teams together'
                                  : 'Make shared priorities visible'}
                        </h3>
                        <p className="mt-1 text-sm text-muted-foreground">
                            {filtered
                                ? 'Try a different search or clear your filters.'
                                : mode === 'teams'
                                  ? 'Team spaces bring contacts, useful documents and updates together. Published spaces will appear here.'
                                  : 'Campaigns bring a shared goal, milestones and ways to take part into one place.'}
                        </p>
                    </div>
                    {filtered ? (
                        <Button variant="outline" onClick={reset}>
                            Clear filters
                        </Button>
                    ) : create &&
                      data.capabilities.write &&
                      !data.demo &&
                      mode !== 'following' ? (
                        <Button onClick={start}>
                            {mode === 'teams'
                                ? 'Create the first team space'
                                : 'Create the first campaign'}
                            <ArrowRight className="size-4" />
                        </Button>
                    ) : null}
                </div>
            )}
            {pages.length > limit && (
                <Button
                    variant="outline"
                    onClick={() => setLimit((count) => count + 24)}
                >
                    Show more {mode}
                </Button>
            )}
        </section>
    );
}
