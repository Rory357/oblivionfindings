import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { useState } from 'react';
import { BlockContent, NewsCards } from './_blocks';
import type { HubProps } from './_types';

export function HubNewsroom({ data }: { data: HubProps }) {
    const [category, setCategory] = useState('all');
    const [query, setQuery] = useState('');
    const [sort, setSort] = useState('latest');
    const stories = data.pages.filter((p) => p.kind === 'news');
    const matches = stories
        .filter(
            (p) =>
                (category === 'all' || p.category === category) &&
                `${p.title} ${p.summary}`
                    .toLowerCase()
                    .includes(query.toLowerCase()),
        )
        .sort((a, b) =>
            sort === 'featured'
                ? Number(b.featured) - Number(a.featured) ||
                  b.published_at.localeCompare(a.published_at)
                : sort === 'oldest'
                  ? a.published_at.localeCompare(b.published_at)
                  : b.published_at.localeCompare(a.published_at),
        );
    return (
        <section className="space-y-5">
            <div className="hub-library-tools">
                <Input
                    className="max-w-sm"
                    aria-label="Search news"
                    value={query}
                    onChange={(e) => setQuery(e.target.value)}
                    placeholder="Find a story…"
                />
                <select
                    className="hub-native-select"
                    aria-label="News order"
                    value={sort}
                    onChange={(e) => setSort(e.target.value)}
                >
                    <option value="latest">Latest first</option>
                    <option value="featured">Featured first</option>
                    <option value="oldest">Oldest first</option>
                </select>
            </div>
            <div className="flex flex-wrap gap-2" aria-label="News categories">
                {['all', ...new Set(stories.map((p) => p.category))].map(
                    (c) => (
                        <Button
                            size="sm"
                            variant={category === c ? 'default' : 'outline'}
                            aria-pressed={category === c}
                            key={c}
                            onClick={() => setCategory(c)}
                        >
                            {c === 'all' ? 'All stories' : c}
                        </Button>
                    ),
                )}
            </div>
            <p className="text-xs text-muted-foreground" role="status">
                {matches.length} {matches.length === 1 ? 'story' : 'stories'}
            </p>
            <NewsCards
                pages={matches}
                demo={data.demo}
                emptyMessage="No stories match this view. Try another category or search."
            />
        </section>
    );
}

export function RecognitionBoard({
    data,
    onRecognise,
}: {
    data: HubProps;
    onRecognise?: (userId?: number) => void;
}) {
    const [category, setCategory] = useState('all');
    const [query, setQuery] = useState('');
    const [mode, setMode] = useState('recent');
    const kudos = data.kudos.filter(
        (k) =>
            (category === 'all' || k.category === category) &&
            `${k.to} ${k.from} ${k.message}`
                .toLowerCase()
                .includes(query.toLowerCase()),
    );
    const ordered = [...kudos].sort((a, b) =>
        mode === 'spotlight'
            ? Object.values(b.reactions).reduce((sum, r) => sum + r.count, 0) -
              Object.values(a.reactions).reduce((sum, r) => sum + r.count, 0)
            : b.created_at.localeCompare(a.created_at),
    );
    return (
        <section className="space-y-5">
            <div className="flex items-start justify-between gap-4">
                <div>
                    <h2 className="text-xl font-semibold">
                        Good work deserves to be seen
                    </h2>
                    <p className="text-sm text-muted-foreground">
                        Celebrate a colleague or recognise a team through the
                        shared recognition form.
                    </p>
                </div>
                {onRecognise && (
                    <Button onClick={() => onRecognise()}>
                        Give a shout-out
                    </Button>
                )}
            </div>
            <div className="hub-library-tools">
                <Input
                    className="max-w-sm"
                    aria-label="Search shout-outs"
                    placeholder="Search people or messages…"
                    value={query}
                    onChange={(e) => setQuery(e.target.value)}
                />
                <select
                    aria-label="Company value"
                    className="hub-native-select"
                    value={category}
                    onChange={(e) => setCategory(e.target.value)}
                >
                    <option value="all">All values</option>
                    {[...new Set(data.kudos.map((k) => k.category))].map(
                        (c) => (
                            <option key={c}>{c}</option>
                        ),
                    )}
                </select>
                <Button
                    variant={mode === 'recent' ? 'secondary' : 'ghost'}
                    aria-pressed={mode === 'recent'}
                    onClick={() => setMode('recent')}
                >
                    Most recent
                </Button>
                <Button
                    variant={mode === 'spotlight' ? 'secondary' : 'ghost'}
                    aria-pressed={mode === 'spotlight'}
                    onClick={() => setMode('spotlight')}
                >
                    Most celebrated
                </Button>
            </div>
            <BlockContent
                block={{
                    id: 'recognition-feed',
                    type: 'shoutouts',
                    title: 'Company recognition',
                    limit: ordered.length || 1,
                }}
                data={{ ...data, kudos: ordered }}
                onEvent={() => {}}
                onRecognise={onRecognise}
            />
        </section>
    );
}

export function RelatedPages({ data }: { data: HubProps }) {
    const selected = data.selectedPage;
    if (!selected) return null;
    const related = data.pages
        .filter(
            (p) =>
                p.id !== selected.id &&
                p.kind === selected.kind &&
                p.category === selected.content.category,
        )
        .slice(0, 3);
    if (!related.length) return null;
    return (
        <section className="mt-10 space-y-4 border-t pt-6">
            <h2 className="text-xl font-semibold">
                {selected.kind === 'news'
                    ? 'More stories like this'
                    : 'Related resources and pages'}
            </h2>
            <NewsCards pages={related} demo={data.demo} />
        </section>
    );
}
