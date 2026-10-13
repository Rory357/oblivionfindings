import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { formatDate } from '@/lib/datetime';
import { Link, router } from '@inertiajs/react';
import {
    ArrowUpRight,
    Bookmark,
    FileText,
    Grid2X2,
    List,
    Search,
} from 'lucide-react';
import { useState } from 'react';
import type { HubProps } from './_types';

export function ResourceLibrary({
    data,
    allPages = false,
    preview = false,
}: {
    data: HubProps;
    allPages?: boolean;
    preview?: boolean;
}) {
    const [search, setSearch] = useState('');
    const [category, setCategory] = useState('all');
    const [collection, setCollection] = useState('all');
    const [kind, setKind] = useState('all');
    const [personal, setPersonal] = useState('all');
    const [sort, setSort] = useState('newest');
    const [layout, setLayout] = useState<'cards' | 'list'>('cards');
    const [busy, setBusy] = useState<number | null>(null);
    const [error, setError] = useState('');
    const source = [
        ...data.pages.filter(
            (page) => allPages || ['page', 'knowledge'].includes(page.kind),
        ),
        ...(!allPages ? (data.resourceItems ?? []) : []),
    ];
    const categories = [...new Set(source.map((p) => p.category))].sort();
    const query = search.trim().toLocaleLowerCase();
    const pages = source
        .filter(
            (page) =>
                (category === 'all' || page.category === category) &&
                (collection === 'all' || page.collection === collection) &&
                (kind === 'all' ||
                    (page.resource_type || 'Information') === kind) &&
                (personal !== 'saved' ||
                    data.bookmarks.some(
                        (b) =>
                            b.subject_type === 'page' &&
                            b.subject_id === page.id,
                    )) &&
                (personal !== 'recent' ||
                    data.recentPages?.includes(page.id)) &&
                `${page.title} ${page.summary} ${page.category}`
                    .toLocaleLowerCase()
                    .includes(query),
        )
        .sort((a, b) => {
            if (personal === 'recent')
                return (
                    (data.recentPages ?? []).indexOf(a.id) -
                    (data.recentPages ?? []).indexOf(b.id)
                );
            if (query) {
                const rank =
                    Number(b.title.toLocaleLowerCase().includes(query)) -
                    Number(a.title.toLocaleLowerCase().includes(query));
                if (rank) return rank;
            }
            return sort === 'title'
                ? a.title.localeCompare(b.title)
                : b.published_at.localeCompare(a.published_at);
        });
    return (
        <section
            className="hub-resource-library"
            aria-label={allPages ? 'Published pages' : 'Resource library'}
        >
            <div className="hub-library-tools">
                <label className="hub-library-search">
                    <Search className="size-4" />
                    <Input
                        aria-label={
                            allPages ? 'Search pages' : 'Search resources'
                        }
                        placeholder={
                            allPages
                                ? 'Find a page…'
                                : 'What do you need help with?'
                        }
                        value={search}
                        onChange={(e) => setSearch(e.target.value)}
                    />
                </label>
                <select
                    className="hub-native-select"
                    aria-label="Category"
                    value={category}
                    onChange={(e) => setCategory(e.target.value)}
                >
                    <option value="all">All categories</option>
                    {categories.map((c) => (
                        <option key={c}>{c}</option>
                    ))}
                </select>
                <select
                    className="hub-native-select"
                    aria-label="Sort resources"
                    value={sort}
                    onChange={(e) => setSort(e.target.value)}
                >
                    <option value="newest">Recently published</option>
                    <option value="title">Title A–Z</option>
                </select>
                <Button
                    variant={layout === 'cards' ? 'secondary' : 'ghost'}
                    size="icon"
                    aria-label="Card view"
                    aria-pressed={layout === 'cards'}
                    onClick={() => setLayout('cards')}
                >
                    <Grid2X2 className="size-4" />
                </Button>
                <Button
                    variant={layout === 'list' ? 'secondary' : 'ghost'}
                    size="icon"
                    aria-label="List view"
                    aria-pressed={layout === 'list'}
                    onClick={() => setLayout('list')}
                >
                    <List className="size-4" />
                </Button>
            </div>
            {!allPages && (
                <div className="mt-3 flex flex-wrap items-center gap-2">
                    <select
                        className="hub-native-select"
                        aria-label="Resource collection"
                        value={collection}
                        onChange={(e) => setCollection(e.target.value)}
                    >
                        <option value="all">All collections</option>
                        {[
                            ...new Set(
                                source.map((p) => p.collection).filter(Boolean),
                            ),
                        ].map((c) => (
                            <option key={c} value={c!}>
                                {c}
                            </option>
                        ))}
                    </select>
                    <select
                        className="hub-native-select"
                        aria-label="Resource type"
                        value={kind}
                        onChange={(e) => setKind(e.target.value)}
                    >
                        <option value="all">All types</option>
                        {[
                            ...new Set(
                                source.map(
                                    (p) => p.resource_type || 'Information',
                                ),
                            ),
                        ].map((c) => (
                            <option key={c}>{c}</option>
                        ))}
                    </select>
                    {[
                        ['all', 'Everything'],
                        ['saved', 'Saved'],
                        ['recent', 'Recently opened'],
                    ].map(([value, label]) => (
                        <Button
                            size="sm"
                            variant={personal === value ? 'secondary' : 'ghost'}
                            aria-pressed={personal === value}
                            key={value}
                            onClick={() => setPersonal(value)}
                        >
                            {label}
                        </Button>
                    ))}
                    {(category !== 'all' ||
                        collection !== 'all' ||
                        kind !== 'all' ||
                        personal !== 'all' ||
                        search) && (
                        <Button
                            size="sm"
                            variant="ghost"
                            onClick={() => {
                                setCategory('all');
                                setCollection('all');
                                setKind('all');
                                setPersonal('all');
                                setSearch('');
                            }}
                        >
                            Clear filters
                        </Button>
                    )}
                </div>
            )}
            <p
                className="text-caption my-4 text-muted-foreground"
                role="status"
            >
                {pages.length} {allPages ? 'pages' : 'resources'}
            </p>
            {error && (
                <p role="alert" className="mb-4 text-sm text-destructive">
                    {error}
                </p>
            )}
            <div className="hub-library-results" data-layout={layout}>
                {pages.map((page) => {
                    const saved = data.bookmarks.some(
                        (b) =>
                            b.subject_type === 'page' &&
                            b.subject_id === page.id,
                    );
                    const href = data.demo
                        ? `/company-hub/showcase?story=${page.slug}`
                        : (page.resource_url ??
                          `/company-hub/read/${page.slug}`);
                    return (
                        <article
                            key={page.resource_key ?? page.id}
                            className="hub-resource-card"
                        >
                            <span className="hub-resource-symbol">
                                <FileText className="size-5" />
                            </span>
                            <div className="hub-resource-copy">
                                <span className="text-caption text-primary">
                                    {page.category}
                                    {page.resource_type &&
                                        ` · ${page.resource_type}`}
                                </span>
                                <a
                                    href={href}
                                    className="hub-resource-title"
                                    rel="noopener noreferrer"
                                    target={
                                        href.startsWith('https:')
                                            ? '_blank'
                                            : undefined
                                    }
                                >
                                    {page.title}
                                    <ArrowUpRight className="size-4" />
                                </a>
                                <p className="text-sm text-muted-foreground">
                                    {page.summary}
                                </p>
                                <small>
                                    {page.owner && `${page.owner} · `}
                                    Published {formatDate(page.published_at)}
                                    {page.review_on &&
                                        ` · Review ${formatDate(page.review_on)}`}
                                </small>
                                {page.resource_url && (
                                    <Link
                                        className="mt-3 block text-xs text-primary"
                                        href={`/company-hub/read/${page.slug}`}
                                    >
                                        About this resource
                                    </Link>
                                )}
                            </div>
                            {!data.demo && !preview && (
                                <Button
                                    size="icon"
                                    variant="ghost"
                                    disabled={busy !== null}
                                    aria-label={`${saved ? 'Unsave' : 'Save'} ${page.resource_url ? 'the page for ' : ''}${page.title}`}
                                    aria-pressed={saved}
                                    onClick={() => {
                                        setBusy(page.id);
                                        setError('');
                                        router.post(
                                            '/company-hub/bookmarks',
                                            {
                                                subject_type: 'page',
                                                subject_id: page.id,
                                                saved: !saved,
                                            },
                                            {
                                                preserveScroll: true,
                                                onError: (errors) =>
                                                    setError(
                                                        Object.values(
                                                            errors,
                                                        ).join(' '),
                                                    ),
                                                onFinish: () => setBusy(null),
                                            },
                                        );
                                    }}
                                >
                                    <Bookmark
                                        className="size-4"
                                        fill={saved ? 'currentColor' : 'none'}
                                    />
                                </Button>
                            )}
                        </article>
                    );
                })}
            </div>
            {!pages.length && (
                <div className="hub-empty-state">
                    <h3 className="text-section-title">
                        {source.length
                            ? 'No matches yet'
                            : allPages
                              ? 'Your company pages will live here'
                              : 'A home for useful answers'}
                    </h3>
                    <p className="mt-2">
                        {source.length
                            ? 'Try a different word or category.'
                            : 'Published guides and information will appear here, ready for everyone who can access them.'}
                    </p>
                    {(search || category !== 'all') && (
                        <Button
                            variant="ghost"
                            onClick={() => {
                                setSearch('');
                                setCategory('all');
                            }}
                        >
                            Clear filters
                        </Button>
                    )}
                </div>
            )}
        </section>
    );
}
