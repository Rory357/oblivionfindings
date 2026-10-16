import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Grid2X2, List, PenLine, Search } from 'lucide-react';
import { useState } from 'react';
import { NewsCards } from './_blocks';
import { HubBrowseSelect } from './_browse-select';
import { useHubBrowseState } from './_browse-state';
import { CreateHubPageDialog } from './_dialogs';
import { useHubPageCreation } from './_page-creation-context';
import { pageMatches } from './_reader-discovery';
import type { HubProps } from './_types';

export function HubNewsroom({ data }: { data: HubProps }) {
    const {
        state: { category, query, sort, layout },
        set,
        browse,
    } = useHubBrowseState(
        'news',
        {
            category: 'all',
            query: '',
            sort: 'latest',
            layout: 'cards',
        },
        data.demo,
    );
    const setCategory = (value: string) => set('category', value);
    const setQuery = (value: string) => set('query', value);
    const setSort = (value: string) => set('sort', value);
    const setLayout = (value: string) => set('layout', value);
    const [create, setCreate] = useState(false);
    const openCreator = useHubPageCreation();
    const stories = data.pages.filter(
        (p) => p.kind === 'news' && p.show_in_news !== false,
    );
    const matches = stories
        .filter(
            (p) =>
                (category === 'all' || p.category === category) &&
                pageMatches(p, query),
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
        <section
            className="hub-newsroom space-y-5"
            aria-label="Company newsroom"
        >
            <div className="hub-discovery-heading">
                <div>
                    <h2>Company news</h2>
                    <p>The people, ideas and moments bringing us together.</p>
                </div>
                {data.capabilities.write && !data.demo && (
                    <Button
                        onClick={() =>
                            openCreator
                                ? openCreator({
                                      initial: {
                                          title: '',
                                          kind: 'news',
                                          template: 'story',
                                      },
                                      context:
                                          'Share a story with your company. It starts as a private draft for you to shape and review.',
                                  })
                                : setCreate(true)
                        }
                    >
                        <PenLine className="size-4" />
                        Write a story
                    </Button>
                )}
            </div>
            <div className="hub-library-tools">
                <label className="hub-library-search">
                    <Search className="size-4" />
                    <Input
                        aria-label="Search news"
                        value={query}
                        onChange={(e) => setQuery(e.target.value)}
                        placeholder="Find a story, author, team or topic…"
                    />
                </label>
                <HubBrowseSelect
                    label="News order"
                    value={sort}
                    onChange={setSort}
                    options={[
                        { value: 'latest', label: 'Latest first' },
                        { value: 'featured', label: 'Featured first' },
                        { value: 'oldest', label: 'Oldest first' },
                    ]}
                />
                <Button
                    size="icon"
                    variant={layout === 'cards' ? 'secondary' : 'ghost'}
                    aria-label="News card view"
                    aria-pressed={layout === 'cards'}
                    onClick={() => setLayout('cards')}
                >
                    <Grid2X2 className="size-4" />
                </Button>
                <Button
                    size="icon"
                    variant={layout === 'list' ? 'secondary' : 'ghost'}
                    aria-label="News list view"
                    aria-pressed={layout === 'list'}
                    onClick={() => setLayout('list')}
                >
                    <List className="size-4" />
                </Button>
            </div>
            <div
                className="hub-news-categories flex flex-wrap gap-2"
                aria-label="News categories"
            >
                {['all', ...new Set(stories.map((p) => p.category))].map(
                    (c) => (
                        <Button
                            size="sm"
                            variant="ghost"
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
                {(query || category !== 'all') && (
                    <Button
                        size="sm"
                        variant="ghost"
                        className="ml-3"
                        onClick={() => {
                            setQuery('');
                            setCategory('all');
                        }}
                    >
                        Clear filters
                    </Button>
                )}
            </p>
            <NewsCards
                pages={matches}
                demo={data.demo}
                layout={layout === 'list' ? 'list' : 'cards'}
                browse={browse}
                bookmarks={data.bookmarks}
                emptyMessage={
                    stories.length === 0
                        ? data.capabilities.write
                            ? 'Your newsroom is ready. Write your first story to welcome colleagues, share a company update or celebrate a team.'
                            : 'Company stories will appear here when they are published. Explore the people and resources in your Hub in the meantime.'
                        : 'No stories match this view. Clear the filters or try another search.'
                }
            />
            {!data.demo && (
                <CreateHubPageDialog
                    open={create}
                    close={() => setCreate(false)}
                    initial={{ title: '', kind: 'news', template: 'story' }}
                    context="Share a story with your company. It starts as a private draft for you to shape and review."
                />
            )}
        </section>
    );
}

export { RecognitionBoard } from './_recognition';

export function RelatedPages({ data }: { data: HubProps }) {
    const selected = data.selectedPage;
    if (!selected) return null;
    const related = data.pages
        .filter(
            (p) =>
                p.id !== selected.id &&
                p.kind === selected.kind &&
                (p.kind !== 'news' || p.show_in_news !== false) &&
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
            <NewsCards
                pages={related}
                demo={data.demo}
                bookmarks={data.bookmarks}
            />
        </section>
    );
}
