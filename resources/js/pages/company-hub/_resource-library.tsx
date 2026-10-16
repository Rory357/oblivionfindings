import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { formatDate } from '@/lib/datetime';
import { Link, router } from '@inertiajs/react';
import {
    ArrowRight,
    ArrowUpRight,
    BookOpen,
    CalendarClock,
    FileText,
    Folder,
    Grid2X2,
    Link2,
    List,
    MessageCircle,
    Plus,
    Search,
    Upload,
    Users,
} from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { HubBookmarkButton } from './_bookmarks';
import { HubBrowseSelect } from './_browse-select';
import { HubBrowseLink, useHubBrowseState } from './_browse-state';
import {
    ResourceCollectionWorkspace,
    resourceCollectionChoices,
    resourceInCollection,
} from './_collections';
import { CreateHubPageDialog } from './_dialogs';
import { DocumentUploadWorkspace } from './_document-upload';
import { PageBookmark } from './_page-bookmark';
import { useHubPageCreation } from './_page-creation-context';
import { pageMatches, pageRelevance } from './_reader-discovery';
import type { HubPageSummary, HubProps } from './_types';
import { fileSize } from './_upload';

export function ResourceLibrary({
    data,
    allPages = false,
    guidesOnly = false,
    preview = false,
}: {
    data: HubProps;
    allPages?: boolean;
    guidesOnly?: boolean;
    preview?: boolean;
}) {
    const {
        state: {
            search,
            category,
            collection,
            team,
            kind,
            personal,
            sort,
            layout,
        },
        set,
        browse,
    } = useHubBrowseState(
        allPages ? 'pages' : guidesOnly ? 'guides' : 'resources',
        {
            search: '',
            category: 'all',
            collection: 'all',
            team: 'all',
            kind: 'all',
            personal: 'all',
            sort: 'newest',
            layout: 'list',
        },
        data.demo,
    );
    const setSearch = (value: string) => set('search', value);
    const setCategory = (value: string) => set('category', value);
    const setCollection = (value: string) => set('collection', value);
    const setTeam = (value: string) => set('team', value);
    const setKind = (value: string) => set('kind', value);
    const setPersonal = (value: string) => set('personal', value);
    const setSort = (value: string) => set('sort', value);
    const setLayout = (value: string) => set('layout', value);
    const [create, setCreate] = useState(false);
    const [createLink, setCreateLink] = useState(false);
    const [upload, setUpload] = useState(false);
    const openCreator = useHubPageCreation();
    const [manageCollections, setManageCollections] = useState(
        () =>
            typeof window !== 'undefined' &&
            new URLSearchParams(window.location.search).get(
                'resource_workspace',
            ) === 'collections',
    );
    const wasManaging = useRef(manageCollections);
    useEffect(() => {
        if (wasManaging.current && !manageCollections) {
            const destination = new URL(window.location.href);
            destination.searchParams.set('view', 'resources');
            destination.searchParams.delete('resource_workspace');
            router.visit(
                destination.pathname + destination.search + destination.hash,
                {
                    preserveState: true,
                    preserveScroll: true,
                    only: [
                        'search',
                        'resourceCollections',
                        'pages',
                        'resourceItems',
                    ],
                },
            );
        }
        wasManaging.current = manageCollections;
    }, [manageCollections]);
    const canManageCollections =
        !allPages &&
        !preview &&
        !data.demo &&
        (data.resourceCollections?.can_create ||
            data.resourceCollections?.items.some((item) => item.can_rename) ||
            data.resourceCollections?.legacy_groups.some(
                (item) => item.can_adopt,
            ));
    const source = [
        ...data.pages.filter(
            (page) =>
                allPages ||
                (guidesOnly
                    ? page.kind === 'knowledge' ||
                      (page.kind === 'page' &&
                          [
                              'Guide',
                              'Policy',
                              'Process',
                              'Checklist',
                              'Form',
                          ].includes(page.resource_type ?? ''))
                    : ['page', 'knowledge'].includes(page.kind)),
        ),
        ...(!allPages && !guidesOnly ? (data.resourceItems ?? []) : []),
    ];
    const categories = [...new Set(source.map((page) => page.category))].sort();
    const types = [
        ...new Set(source.map((page) => page.resource_type || 'Information')),
    ].sort();
    const collections = resourceCollectionChoices(
        source,
        data.resourceCollections,
    );
    const teams = data.interests?.available_teams ?? [];
    const query = search.trim();
    const saved = (page: HubPageSummary) =>
        data.bookmarks.some(
            (item) =>
                item.subject_type === (page.document_id ? 'media' : 'page') &&
                item.subject_id === (page.document_id ?? page.id),
        );
    const recent =
        (!allPages ? data.recentResources : undefined) ??
        (data.recentPages ?? []).map((id) => `page:${id}`);
    const recentRank = (item: HubPageSummary) => {
        const position = recent.indexOf(
            item.document_id ? `media:${item.document_id}` : `page:${item.id}`,
        );
        return position < 0 ? Infinity : position;
    };
    const pages = source
        .filter(
            (page) =>
                (category === 'all' || page.category === category) &&
                resourceInCollection(page, collection, collections) &&
                (team === 'all' || page.team_ids?.includes(Number(team))) &&
                (kind === 'all' ||
                    (page.resource_type || 'Information') === kind) &&
                (personal !== 'saved' || saved(page)) &&
                (personal !== 'featured' || page.featured) &&
                (personal !== 'recent' || Number.isFinite(recentRank(page))) &&
                pageMatches(page, query),
        )
        .sort((a, b) => {
            if (sort === 'recent' && recentRank(a) !== recentRank(b))
                return recentRank(a) - recentRank(b);
            if (sort === 'title') return a.title.localeCompare(b.title);
            if (sort === 'relevance') {
                const rank = pageRelevance(b, query) - pageRelevance(a, query);
                if (rank) return rank;
            }
            return b.published_at.localeCompare(a.published_at) || b.id - a.id;
        });
    const filtered =
        !!query ||
        category !== 'all' ||
        collection !== 'all' ||
        team !== 'all' ||
        kind !== 'all' ||
        personal !== 'all';
    const featuredGuides = guidesOnly
        ? pages.filter((page) => page.featured).slice(0, 3)
        : [];
    const clear = () => {
        setSearch('');
        setCategory('all');
        setCollection('all');
        setTeam('all');
        setKind('all');
        setPersonal('all');
        if (sort === 'recent') setSort('newest');
    };
    if (upload && !data.demo && !preview && data.capabilities.write)
        return (
            <DocumentUploadWorkspace
                onClose={() => {
                    setUpload(false);
                    router.reload({
                        only: ['editablePages', 'pages', 'resourceItems'],
                    });
                }}
            />
        );
    if (manageCollections && canManageCollections)
        return (
            <section className="hub-resource-library space-y-5">
                <div className="hub-discovery-heading">
                    <div>
                        <h2>Useful answers, easy to find</h2>
                        <p>Organise the resources that help us do good work.</p>
                    </div>
                </div>
                <ResourceCollectionWorkspace
                    onClose={() => setManageCollections(false)}
                    onAdopt={(oldKey, nextKey) => {
                        if (collection === oldKey) setCollection(nextKey);
                    }}
                />
            </section>
        );
    return (
        <section
            className="hub-resource-library space-y-5"
            aria-label={
                allPages
                    ? 'Published pages'
                    : guidesOnly
                      ? 'Company guides'
                      : 'Resource library'
            }
        >
            <div className="hub-discovery-heading">
                <div>
                    <h2>
                        {allPages
                            ? 'All company pages'
                            : guidesOnly
                              ? 'Company guides'
                              : 'Resources'}
                    </h2>
                    <p>
                        {allPages
                            ? 'Browse every published story, guide, team space and campaign available to you.'
                            : guidesOnly
                              ? 'Clear guidance, supporting documents and a person to turn to.'
                              : 'Find a guide, open a document or follow a useful link.'}
                    </p>
                </div>
                <div className="flex items-center gap-2">
                    {!allPages &&
                        !preview &&
                        !data.demo &&
                        data.capabilities.write && (
                            <Button
                                variant="outline"
                                onClick={() =>
                                    openCreator
                                        ? openCreator({
                                              initial: {
                                                  title: '',
                                                  kind: 'knowledge',
                                                  template: 'information',
                                              },
                                              context:
                                                  'Create a useful link with a clear title and purpose. Add its web address in the Links block, then review and publish it to Resources.',
                                          })
                                        : setCreateLink(true)
                                }
                            >
                                <Link2 className="size-4" />
                                Add useful link
                            </Button>
                        )}
                    {!allPages &&
                        !preview &&
                        !data.demo &&
                        data.capabilities.write && (
                            <Button
                                variant="outline"
                                onClick={() => setUpload(true)}
                            >
                                <Upload className="size-4" />
                                Upload documents
                            </Button>
                        )}
                    {canManageCollections && (
                        <Button
                            variant="outline"
                            onClick={() => setManageCollections(true)}
                        >
                            <Folder className="size-4" />
                            Collections
                        </Button>
                    )}
                    {!preview && !data.demo && data.capabilities.write && (
                        <Button
                            onClick={() =>
                                openCreator
                                    ? openCreator({
                                          initial: {
                                              title: '',
                                              kind: allPages
                                                  ? 'page'
                                                  : 'knowledge',
                                              template: allPages
                                                  ? 'information'
                                                  : 'guide',
                                          },
                                          context: allPages
                                              ? 'Start a useful company page. Save it privately while you work.'
                                              : 'Give colleagues a useful answer, with a clear owner and review date.',
                                      })
                                    : setCreate(true)
                            }
                        >
                            <Plus className="size-4" />
                            {allPages ? 'New page' : 'Create guide'}
                        </Button>
                    )}
                </div>
            </div>
            {!allPages && (
                <nav
                    aria-label="Knowledge library"
                    className="flex items-center gap-2 border-b pb-4"
                >
                    <Button
                        variant={guidesOnly ? 'ghost' : 'secondary'}
                        asChild
                    >
                        <Link
                            href={`${data.demo ? '/company-hub/showcase' : '/company-hub'}?view=resources`}
                        >
                            All resources
                        </Link>
                    </Button>
                    <Button
                        variant={guidesOnly ? 'secondary' : 'ghost'}
                        asChild
                    >
                        <Link
                            href={`${data.demo ? '/company-hub/showcase' : '/company-hub'}?view=guides`}
                        >
                            <BookOpen className="size-4" /> Company guides
                        </Link>
                    </Button>
                </nav>
            )}
            {!allPages && collections.some((item) => item.count > 0) && (
                <div
                    className="hub-resource-collections"
                    aria-label="Resource collections"
                >
                    {collections
                        .filter((item) => item.count > 0)
                        .map((item) => (
                            <Button
                                key={item.key}
                                variant="ghost"
                                className="hub-collection-tile"
                                aria-label={`${item.name} · ${item.count} ${item.count === 1 ? 'resource' : 'resources'}`}
                                aria-pressed={collection === item.key}
                                onClick={() =>
                                    setCollection(
                                        collection === item.key
                                            ? 'all'
                                            : item.key,
                                    )
                                }
                            >
                                <span className="hub-collection-copy">
                                    <strong>{item.name}</strong>
                                    <small>
                                        {item.count}{' '}
                                        {item.count === 1
                                            ? 'resource'
                                            : 'resources'}
                                    </small>
                                </span>
                            </Button>
                        ))}
                </div>
            )}
            {guidesOnly && !filtered && featuredGuides.length > 0 && (
                <section aria-label="Featured guides" className="space-y-3">
                    <div className="flex items-center justify-between gap-3">
                        <h3 className="text-sm font-semibold">
                            Featured guides
                        </h3>
                        <Button
                            size="sm"
                            variant="ghost"
                            onClick={() => setPersonal('featured')}
                        >
                            View all featured <ArrowRight className="size-4" />
                        </Button>
                    </div>
                    <div className="hub-featured-guides">
                        {featuredGuides.map((page) => (
                            <HubBrowseLink
                                key={page.id}
                                href={`${data.demo ? '/company-hub/showcase?story=' : '/company-hub/read/'}${page.slug}`}
                                browse={
                                    preview || data.selectedPage
                                        ? undefined
                                        : browse
                                }
                                returnKey={`featured-guide-${page.id}`}
                                className="hub-featured-guide"
                            >
                                <BookOpen className="size-5 shrink-0 text-primary" />
                                <span className="min-w-0 space-y-1">
                                    <strong className="block text-sm">
                                        {page.title}
                                    </strong>
                                    <span className="line-clamp-2 block text-xs text-muted-foreground">
                                        {page.summary}
                                    </span>
                                </span>
                                <ArrowUpRight className="ml-auto size-4 shrink-0 text-primary" />
                            </HubBrowseLink>
                        ))}
                    </div>
                </section>
            )}
            <div className="hub-library-tools">
                <label className="hub-library-search">
                    <Search className="size-4" />
                    <Input
                        aria-label={
                            allPages ? 'Search pages' : 'Search resources'
                        }
                        placeholder={
                            allPages
                                ? 'Find a page, owner or topic…'
                                : 'Search a guide, topic, team or owner…'
                        }
                        value={search}
                        onChange={(event) => {
                            setSearch(event.target.value);
                            setSort(
                                event.target.value.trim()
                                    ? 'relevance'
                                    : 'newest',
                            );
                        }}
                    />
                </label>
                <div className="hub-sort-control">
                    <span>Sort by</span>
                    <HubBrowseSelect
                        label={allPages ? 'Sort pages' : 'Sort resources'}
                        value={sort}
                        onChange={setSort}
                        options={[
                            {
                                value: 'newest',
                                label: guidesOnly
                                    ? 'Recently updated'
                                    : 'Recently published',
                            },
                            { value: 'title', label: 'Title A–Z' },
                            { value: 'relevance', label: 'Relevance' },
                            ...(personal === 'recent'
                                ? [
                                      {
                                          value: 'recent',
                                          label: 'Recently opened',
                                      },
                                  ]
                                : []),
                        ]}
                    />
                </div>
            </div>
            <div
                className={
                    allPages ? 'hub-pages-browser' : 'hub-resource-browser'
                }
            >
                <aside
                    className="hub-resource-facets"
                    aria-label="Resource filters"
                >
                    {!allPages && (
                        <div>
                            <h3>Browse by team</h3>
                            <div className="hub-team-facets">
                                <Button
                                    variant="ghost"
                                    aria-pressed={team === 'all'}
                                    onClick={() => setTeam('all')}
                                >
                                    <Users className="size-4" />
                                    <span>All teams</span>
                                    <small>{source.length}</small>
                                </Button>
                                {teams.map((item) => (
                                    <Button
                                        key={item.id}
                                        variant="ghost"
                                        aria-pressed={team === String(item.id)}
                                        onClick={() => setTeam(String(item.id))}
                                    >
                                        <span>{item.name}</span>
                                        <small>
                                            {
                                                source.filter((page) =>
                                                    page.team_ids?.includes(
                                                        item.id,
                                                    ),
                                                ).length
                                            }
                                        </small>
                                    </Button>
                                ))}
                            </div>
                        </div>
                    )}
                    <div className="hub-facet-field">
                        <span>Category</span>
                        <HubBrowseSelect
                            label="Category"
                            value={category}
                            onChange={setCategory}
                            options={[
                                { value: 'all', label: 'All categories' },
                                ...categories.map((value) => ({
                                    value,
                                    label: value,
                                })),
                            ]}
                        />
                    </div>
                    {!allPages && (
                        <>
                            <div className="hub-facet-field">
                                <span>Collection</span>
                                <HubBrowseSelect
                                    label="Resource collection"
                                    value={collection}
                                    onChange={setCollection}
                                    options={[
                                        {
                                            value: 'all',
                                            label: 'All collections',
                                        },
                                        ...collections.map((item) => ({
                                            value: item.key,
                                            label: item.name,
                                        })),
                                    ]}
                                />
                            </div>
                            <div className="hub-facet-field">
                                <span>Resource type</span>
                                <HubBrowseSelect
                                    label="Resource type"
                                    value={kind}
                                    onChange={setKind}
                                    options={[
                                        { value: 'all', label: 'All types' },
                                        ...types.map((value) => ({
                                            value,
                                            label: value,
                                        })),
                                    ]}
                                />
                            </div>
                            <div className="hub-resource-help">
                                <MessageCircle className="size-5 text-primary" />
                                <h3>Can’t find an answer?</h3>
                                <p>
                                    Our teams can help you find the right next
                                    step.
                                </p>
                                <Link
                                    href={
                                        (data.demo
                                            ? '/company-hub/showcase'
                                            : '/company-hub') + '?view=services'
                                    }
                                >
                                    Ask for help
                                    <ArrowRight className="size-4" />
                                </Link>
                            </div>
                        </>
                    )}
                </aside>
                <div className="min-w-0">
                    <div className="hub-resource-results-tools">
                        <div
                            className="flex gap-1"
                            aria-label="Resource reading filters"
                        >
                            {[
                                [
                                    'all',
                                    allPages
                                        ? 'All pages'
                                        : guidesOnly
                                          ? 'All guides'
                                          : 'All resources',
                                ],
                                ...(guidesOnly
                                    ? [['featured', 'Featured']]
                                    : []),
                                ['saved', 'Saved'],
                                ['recent', 'Recently opened'],
                            ].map(([value, label]) => (
                                <Button
                                    key={value}
                                    size="sm"
                                    variant={
                                        personal === value
                                            ? 'secondary'
                                            : 'ghost'
                                    }
                                    aria-pressed={personal === value}
                                    onClick={() => {
                                        setPersonal(value);
                                        if (value === 'recent')
                                            setSort('recent');
                                        else if (sort === 'recent')
                                            setSort(
                                                query ? 'relevance' : 'newest',
                                            );
                                    }}
                                >
                                    {label}
                                </Button>
                            ))}
                        </div>
                        <div className="ml-auto flex gap-1">
                            <Button
                                size="icon"
                                variant={
                                    layout === 'cards' ? 'secondary' : 'ghost'
                                }
                                aria-label="Card view"
                                aria-pressed={layout === 'cards'}
                                onClick={() => setLayout('cards')}
                            >
                                <Grid2X2 className="size-4" />
                            </Button>
                            <Button
                                size="icon"
                                variant={
                                    layout === 'list' ? 'secondary' : 'ghost'
                                }
                                aria-label="List view"
                                aria-pressed={layout === 'list'}
                                onClick={() => setLayout('list')}
                            >
                                <List className="size-4" />
                            </Button>
                        </div>
                    </div>
                    <div className="mb-4 flex min-h-9 items-center justify-between gap-3">
                        <p
                            className="text-xs text-muted-foreground"
                            role="status"
                        >
                            {pages.length}{' '}
                            {allPages
                                ? pages.length === 1
                                    ? 'page'
                                    : 'pages'
                                : pages.length === 1
                                  ? 'resource'
                                  : 'resources'}
                            {collection !== 'all' && ' · ' + collection}
                        </p>
                        {filtered && (
                            <Button size="sm" variant="ghost" onClick={clear}>
                                Clear filters
                            </Button>
                        )}
                    </div>
                    <div className="hub-library-results" data-layout={layout}>
                        {pages.map((page) => {
                            const pageHref = data.demo
                                ? '/company-hub/showcase?story=' + page.slug
                                : '/company-hub/read/' + page.slug;
                            const href = data.demo
                                ? pageHref
                                : (page.reader_url ??
                                  page.resource_url ??
                                  pageHref);
                            return (
                                <article
                                    key={page.resource_key ?? page.id}
                                    className="hub-resource-card"
                                    aria-label={page.title}
                                >
                                    <span className="hub-resource-symbol">
                                        <FileText className="size-5" />
                                    </span>
                                    <div className="hub-resource-copy">
                                        <span className="text-caption text-primary">
                                            {page.resource_type ||
                                                'Information'}
                                            {page.category &&
                                                ' · ' + page.category}
                                        </span>
                                        {href === pageHref ||
                                        page.reader_url ||
                                        (href.startsWith('/') &&
                                            !href.startsWith('//')) ? (
                                            <HubBrowseLink
                                                href={href}
                                                browse={
                                                    preview || data.selectedPage
                                                        ? undefined
                                                        : browse
                                                }
                                                returnKey={
                                                    page.document_id
                                                        ? `document:${page.document_id}`
                                                        : `resource-${page.id}`
                                                }
                                                className="hub-resource-title"
                                            >
                                                {page.title}
                                                <ArrowUpRight className="size-4" />
                                            </HubBrowseLink>
                                        ) : (
                                            <a
                                                href={href}
                                                className="hub-resource-title"
                                                rel="noopener noreferrer"
                                                target="_blank"
                                            >
                                                {page.title}
                                                <ArrowUpRight className="size-4" />
                                                <span className="sr-only">
                                                    {' '}
                                                    (opens in a new tab)
                                                </span>
                                            </a>
                                        )}
                                        <p className="line-clamp-3 text-sm text-muted-foreground">
                                            {page.summary}
                                        </p>
                                        {page.filename && (
                                            <p className="mt-2 text-xs text-muted-foreground">
                                                {page.filename}
                                                {typeof page.size === 'number'
                                                    ? ` · ${fileSize(page.size)}`
                                                    : ''}
                                                {page.document_file_version
                                                    ? ` · Version ${page.document_file_version}`
                                                    : ''}
                                            </p>
                                        )}
                                        {page.topics &&
                                            page.topics.length > 0 && (
                                                <div
                                                    className="hub-resource-topics"
                                                    aria-label="Topics"
                                                >
                                                    {page.topics.map(
                                                        (topic) => (
                                                            <span key={topic}>
                                                                {topic}
                                                            </span>
                                                        ),
                                                    )}
                                                </div>
                                            )}
                                        {(page.owner ||
                                            !!page.team_names?.length) && (
                                            <div className="hub-resource-owner">
                                                <strong>{page.owner}</strong>
                                                <span>
                                                    {page.team_names?.join(
                                                        ' · ',
                                                    )}
                                                </span>
                                            </div>
                                        )}
                                        <small>
                                            Published{' '}
                                            {formatDate(page.published_at)}
                                        </small>
                                        {page.review_on && (
                                            <span
                                                className={
                                                    'hub-resource-review ' +
                                                    (page.review_overdue
                                                        ? 'is-overdue'
                                                        : '')
                                                }
                                            >
                                                <CalendarClock className="size-3.5" />
                                                {page.review_overdue
                                                    ? 'Review overdue · '
                                                    : 'Review '}
                                                {formatDate(page.review_on)}
                                            </span>
                                        )}
                                        {page.resource_url && (
                                            <HubBrowseLink
                                                className="mt-3 block text-xs text-primary"
                                                href={pageHref}
                                                browse={
                                                    preview || data.selectedPage
                                                        ? undefined
                                                        : browse
                                                }
                                                returnKey={`about-${page.id}-${page.resource_key?.replace(/[^\w:-]/g, '').slice(0, 50) ?? 'page'}`}
                                            >
                                                About this resource
                                            </HubBrowseLink>
                                        )}
                                    </div>
                                    {!data.demo &&
                                        !preview &&
                                        (page.document_id ? (
                                            <HubBookmarkButton
                                                subject={{
                                                    subject_type: 'media',
                                                    subject_id:
                                                        page.document_id,
                                                }}
                                                title={page.title}
                                                saved={saved(page)}
                                                compact
                                            />
                                        ) : (
                                            <PageBookmark
                                                pageId={page.id}
                                                title={
                                                    (page.resource_url
                                                        ? 'the page for '
                                                        : '') + page.title
                                                }
                                                saved={saved(page)}
                                            />
                                        ))}
                                </article>
                            );
                        })}
                    </div>
                    {!pages.length && (
                        <div className="hub-empty-state">
                            <BookOpen className="mx-auto mb-3 size-7 text-primary" />
                            <h3 className="text-section-title">
                                {source.length
                                    ? `No ${allPages ? 'pages' : 'resources'} match this view`
                                    : allPages
                                      ? 'Your company pages will live here'
                                      : 'A home for useful answers'}
                            </h3>
                            <p className="mt-2">
                                {source.length
                                    ? 'Try another team, collection, type or search.'
                                    : 'Published guides and information will appear here for everyone who can access them.'}
                            </p>
                        </div>
                    )}
                </div>
            </div>
            {!preview && !data.demo && (
                <CreateHubPageDialog
                    open={create || createLink}
                    close={() => {
                        setCreate(false);
                        setCreateLink(false);
                    }}
                    initial={{
                        title: '',
                        kind: allPages ? 'page' : 'knowledge',
                        template:
                            allPages || createLink ? 'information' : 'guide',
                    }}
                    context={
                        createLink
                            ? 'Add the destination in the Links block. Review and publish the page to share the link in Resources.'
                            : allPages
                              ? 'Start a useful company page. Save it privately while you work.'
                              : 'Give colleagues a useful answer, with a clear owner and review date.'
                    }
                />
            )}
        </section>
    );
}
