import { PageHeaderRail } from '@/components/page/page-header';
import {
    RecognitionWizard,
    type RecognitionDefaults,
} from '@/components/recognition/recognition-wizard';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import AppLayout from '@/layouts/app-layout';
import { Head, Link, router } from '@inertiajs/react';
import {
    ArrowLeft,
    ArrowRight,
    Bell,
    Bookmark,
    Cake,
    CalendarDays,
    ChevronDown,
    HeartHandshake,
    Link2,
    Megaphone,
    Pencil,
    Plus,
    Settings2,
} from 'lucide-react';
import { useEffect, useState } from 'react';
import { HubAnnouncementDialog } from './_announcement';
import { HubBlocks, NewsCards } from './_blocks';
import {
    CampaignProgress,
    CompanySpaces,
    IdeaBoard,
    ReadReceipt,
} from './_community';
import { DepartmentSpace } from './_department';
import {
    BirthdaySettingsDialog,
    CreateHubPageDialog,
    HubEventDialog,
    HubNotificationDialog,
    ShortcutDialog,
} from './_dialogs';
import { HubNewsroom, RecognitionBoard, RelatedPages } from './_discovery';
import { EditorialWorkspace } from './_editorial';
import { EventBoard } from './_events';
import { HubArticleHeader, HubHero, HubSiteHeader } from './_hero';
import { isKnowledgePage, KnowledgeReader } from './_knowledge';
import { HubNavigationRail } from './_navigation';
import { PageParticipation } from './_participation';
import { PeopleDirectory } from './_people';
import { PersonProfile } from './_person-profile';
import { ResourceLibrary } from './_resource-library';
import { HubSearchResults } from './_search';
import { ServicesWorkspace } from './_services';
import type { HubEvent, HubProps, HubSection } from './_types';
import { UpdatesFeed } from './_updates';
import './designer.css';
import './hub.css';

const views = [
    { key: 'home', label: 'Home', description: '' },
    {
        key: 'news',
        label: 'News',
        description: 'Updates, ideas and stories from across the company.',
    },
    {
        key: 'shoutouts',
        label: 'Shout-outs',
        description: 'Recognising the people who make a difference.',
    },
    {
        key: 'events',
        label: 'Events',
        description: 'See what’s coming up and find time to connect.',
    },
    {
        key: 'resources',
        label: 'Resources',
        description: 'Company information, guides and useful resources.',
    },
    {
        key: 'pages',
        label: 'Pages',
        description: 'Company stories, guides and spaces, all in one place.',
    },
    {
        key: 'people',
        label: 'People',
        description:
            'Find a colleague, a work contact or your company’s reporting lines.',
    },
    {
        key: 'teams',
        label: 'Teams',
        description:
            'Connect with departments and the work happening across the company.',
    },
    {
        key: 'campaigns',
        label: 'Campaigns',
        description:
            'Shared initiatives and small actions that make a difference.',
    },
    {
        key: 'ideas',
        label: 'Ideas',
        description:
            'A place for suggestions, thoughtful discussion and visible progress.',
    },
    {
        key: 'following',
        label: 'Following',
        description:
            'The topics, teams and pages you follow, together in one place.',
    },
    {
        key: 'updates',
        label: 'Updates',
        description: 'What’s new and what needs your attention.',
    },
    {
        key: 'services',
        label: 'Services',
        description:
            'Find the right help, make a request and follow its progress.',
    },
    {
        key: 'publishing',
        label: 'Publishing',
        description: 'Plan, review and manage your company’s pages.',
    },
    {
        key: 'saved',
        label: 'Saved',
        description: 'Stories, resources and events you’ve kept for later.',
    },
];

export default function CompanyHub(props: HubProps) {
    const [recognition, setRecognition] = useState(false);
    const [defaults, setDefaults] = useState<RecognitionDefaults>();
    const [create, setCreate] = useState(false);
    const [manage, setManage] = useState(false);
    const [birthdays, setBirthdays] = useState(false);
    const [shortcuts, setShortcuts] = useState(false);
    const [notifications, setNotifications] = useState(false);
    const [event, setEvent] = useState<HubEvent | null>(null);
    const [demoThanks, setDemoThanks] = useState(false);
    const [search, setSearch] = useState(props.search);
    useEffect(() => setSearch(props.search), [props.search]);
    const recognise = (userId?: number) => {
        if (props.demo) {
            setDemoThanks(true);
            return;
        }
        setDefaults(userId ? { recipients: [String(userId)] } : undefined);
        setRecognition(true);
    };
    const selected = props.selectedPage;
    const isHome = props.view === 'home' && !selected && !props.search;
    const baseUrl = props.demo ? '/company-hub/showcase' : '/company-hub';
    const featured =
        props.pages.find((page) => page.kind === 'news' && page.featured) ??
        props.pages.find((page) => page.kind === 'news');
    const currentView =
        views.find((view) => view.key === props.view) ?? views[0];
    const savedPages = props.pages.filter((page) =>
        props.bookmarks.some(
            (item) =>
                item.subject_type === 'page' && item.subject_id === page.id,
        ),
    );
    const savedEvents = props.events.filter((event) =>
        props.bookmarks.some(
            (item) =>
                item.subject_type === 'event' &&
                item.subject_id ===
                    (event.extendedProps.recurrenceParentId ??
                        event.extendedProps.eventId),
        ),
    );
    const pageSaved =
        !!selected &&
        props.bookmarks.some(
            (item) =>
                item.subject_type === 'page' && item.subject_id === selected.id,
        );
    const viewSections: HubSection[] = [
        {
            id: 'all',
            columns: 1,
            blocks: [
                {
                    id: props.view,
                    type: props.view,
                    title: currentView.label,
                    limit: 12,
                },
            ],
        },
    ];
    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Home', href: '/dashboard' },
                { title: 'Company Hub', href: '/company-hub' },
                ...(selected
                    ? [
                          {
                              title: selected.content.title,
                              href: `/company-hub/read/${selected.slug}`,
                          },
                      ]
                    : props.view !== 'home'
                      ? [
                            {
                                title: currentView.label,
                                href: `${baseUrl}?view=${props.view}`,
                            },
                        ]
                      : []),
                ...(props.selectedPerson
                    ? [
                          {
                              title: props.selectedPerson.name,
                              href: `/company-hub/people/${props.selectedPerson.id}`,
                          },
                      ]
                    : []),
            ]}
        >
            <Head
                title={
                    selected
                        ? `${selected.content.title} · Company Hub`
                        : `${props.selectedPerson?.name ?? currentView.label} · Company Hub`
                }
            />
            <div className="hub-portal">
                {props.demo && (
                    <div className="hub-demo-strip">
                        <span>
                            <strong>Showcase</strong>
                            <span className="ml-2">
                                Fictional people, stories and photography
                            </span>
                        </span>
                        <Link href="/company-hub">
                            Your company hub <ArrowRight className="size-4" />
                        </Link>
                    </div>
                )}
                <HubSiteHeader
                    content={selected?.content ?? props.home}
                    summary={selected ? undefined : props.heroSummary}
                    onEditHero={
                        (
                            selected
                                ? selected.can_edit
                                : props.capabilities.layout
                        )
                            ? () => {
                                  if (selected)
                                      router.visit(
                                          `/company-hub/manage/${selected.id}/edit?panel=hero`,
                                      );
                                  else if (props.demo)
                                      router.visit(
                                          '/company-hub/showcase/edit?panel=hero',
                                      );
                                  else if (props.homeId)
                                      router.visit(
                                          `/company-hub/manage/${props.homeId}/edit?panel=hero`,
                                      );
                                  else
                                      router.post('/company-hub/pages', {
                                          kind: 'home',
                                          title: 'Company Hub',
                                      });
                              }
                            : undefined
                    }
                    search={search}
                    onSearch={setSearch}
                    onSubmit={(event) => {
                        event.preventDefault();
                        router.get(
                            baseUrl,
                            {
                                search,
                                view: 'home',
                            },
                            { preserveState: true },
                        );
                    }}
                    actions={
                        <>
                            {props.capabilities.recognise && (
                                <Button
                                    variant="ghost"
                                    onClick={() => recognise()}
                                >
                                    <HeartHandshake className="size-4" />
                                    Give a shout-out
                                </Button>
                            )}
                            {!props.demo && (
                                <DropdownMenu>
                                    <DropdownMenuTrigger asChild>
                                        <Button variant="ghost">
                                            <Link2 className="size-4" />
                                            My hub
                                            <ChevronDown className="size-3" />
                                        </Button>
                                    </DropdownMenuTrigger>
                                    <DropdownMenuContent align="start">
                                        <DropdownMenuItem asChild>
                                            <Link href="/company-hub?view=following">
                                                <HeartHandshake className="size-4" />
                                                My interests & following
                                            </Link>
                                        </DropdownMenuItem>
                                        <DropdownMenuItem asChild>
                                            <Link href="/company-hub?view=updates">
                                                <Bell className="size-4" />
                                                Company updates
                                                {(props.updates ?? []).some(
                                                    (item) => item.unread,
                                                ) && (
                                                    <span
                                                        className="ml-2 size-2 rounded-full bg-primary"
                                                        aria-label="New updates"
                                                    />
                                                )}
                                            </Link>
                                        </DropdownMenuItem>
                                        <DropdownMenuItem
                                            onSelect={() => setShortcuts(true)}
                                        >
                                            <Link2 className="size-4" />
                                            My shortcuts
                                        </DropdownMenuItem>
                                        <DropdownMenuItem
                                            onSelect={() =>
                                                setNotifications(true)
                                            }
                                        >
                                            <Bell className="size-4" />
                                            Notification preferences
                                        </DropdownMenuItem>
                                        {props.capabilities.celebrations && (
                                            <>
                                                <DropdownMenuSeparator />
                                                <DropdownMenuItem
                                                    onSelect={() =>
                                                        setBirthdays(true)
                                                    }
                                                >
                                                    <Cake className="size-4" />
                                                    Birthday settings
                                                </DropdownMenuItem>
                                            </>
                                        )}
                                        {(props.capabilities.layout ||
                                            props.capabilities.write ||
                                            props.capabilities.edit_all) && (
                                            <>
                                                <DropdownMenuSeparator />
                                                <DropdownMenuItem asChild>
                                                    <Link href="/company-hub?view=publishing">
                                                        <Settings2 className="size-4" />
                                                        Publishing & navigation
                                                    </Link>
                                                </DropdownMenuItem>
                                            </>
                                        )}
                                    </DropdownMenuContent>
                                </DropdownMenu>
                            )}
                            <span className="flex-1" />
                            {props.demo && (
                                <Button variant="ghost" asChild>
                                    <Link href="/company-hub/showcase/edit">
                                        <Pencil className="size-4" />
                                        Try the editor
                                    </Link>
                                </Button>
                            )}
                            {props.capabilities.layout && !props.demo && (
                                <Button variant="ghost" asChild>
                                    <Link href="/company-hub/showcase">
                                        View showcase
                                    </Link>
                                </Button>
                            )}
                            {props.capabilities.write && (
                                <Button
                                    variant="ghost"
                                    onClick={() => setCreate(true)}
                                >
                                    <Plus className="size-4" />
                                    Create
                                </Button>
                            )}
                            {props.capabilities.layout && isHome && (
                                <Button
                                    variant="outline"
                                    onClick={() =>
                                        props.homeId
                                            ? router.visit(
                                                  `/company-hub/manage/${props.homeId}/edit`,
                                              )
                                            : router.post(
                                                  '/company-hub/pages',
                                                  {
                                                      kind: 'home',
                                                      title: props.home.title,
                                                  },
                                              )
                                    }
                                >
                                    <Pencil className="size-4" />
                                    Design hub
                                </Button>
                            )}
                            {selected?.can_edit && (
                                <Button variant="outline" asChild>
                                    <Link
                                        href={`/company-hub/manage/${selected.id}/edit`}
                                    >
                                        <Pencil className="size-4" />
                                        Edit page
                                    </Link>
                                </Button>
                            )}
                        </>
                    }
                >
                    {props.sharedNavigation && !props.demo ? (
                        <HubNavigationRail
                            items={props.sharedNavigation}
                            current={
                                selected
                                    ? `page:${selected.id}`
                                    : `view:${props.view}`
                            }
                        />
                    ) : (
                        <nav
                            aria-label="Company Hub"
                            className="w-full min-w-0"
                        >
                            <PageHeaderRail
                                ariaLabel="Company Hub views"
                                showFind={false}
                                items={[
                                    ...views
                                        .filter(
                                            (view) =>
                                                view.key !== 'publishing' ||
                                                (!props.demo &&
                                                    (props.capabilities.write ||
                                                        props.capabilities
                                                            .edit_all)),
                                        )
                                        .filter(
                                            (view) =>
                                                !props.demo ||
                                                [
                                                    'home',
                                                    'news',
                                                    'shoutouts',
                                                    'events',
                                                    'resources',
                                                    'pages',
                                                ].includes(view.key),
                                        )
                                        .map((view) => ({
                                            key: view.key,
                                            label: view.label,
                                        })),
                                    ...props.navigation.map((page) => ({
                                        key: `page:${page.id}`,
                                        label: page.title,
                                    })),
                                ]}
                                value={
                                    selected
                                        ? `page:${selected.id}`
                                        : props.view
                                }
                                onSelect={(key) => {
                                    const page = props.navigation.find(
                                        (p) => `page:${p.id}` === key,
                                    );
                                    router.visit(
                                        page
                                            ? `/company-hub/read/${page.slug}`
                                            : `${baseUrl}?view=${key}`,
                                    );
                                }}
                            />
                        </nav>
                    )}
                </HubSiteHeader>
                <div className="hub-page-canvas">
                    {props.announcements.length > 0 && !selected && (
                        <div className="hub-notice-strip">
                            <Megaphone className="size-4 text-primary" />
                            <span className="text-xs font-semibold text-primary">
                                NOTICE
                            </span>
                            <Link
                                className="min-w-0 flex-1 font-medium hover:underline"
                                href={`/hr/announcements/${props.announcements[0].id}`}
                            >
                                {props.announcements[0].title}
                            </Link>
                            <Link
                                className="text-sm text-primary"
                                href={`/hr/announcements/${props.announcements[0].id}`}
                            >
                                {props.announcements[0]
                                    .requires_acknowledgement &&
                                !props.announcements[0].viewer_acknowledged
                                    ? 'Read and acknowledge'
                                    : 'Read notice'}
                                <ArrowRight className="ml-2 inline size-4" />
                            </Link>
                        </div>
                    )}
                    {selected ? (
                        <>
                            <Link
                                className="inline-flex items-center gap-2 text-sm text-primary"
                                href={baseUrl}
                            >
                                <ArrowLeft className="size-4" />
                                Back to the hub
                            </Link>
                            <HubArticleHeader
                                content={selected.content}
                                publishedAt={selected.published_at}
                            />
                        </>
                    ) : isHome ? (
                        <>
                            <div className="hub-welcome-line">
                                <h2>From across the company</h2>
                                <Link href={`${baseUrl}?view=saved`}>
                                    Saved for later{' '}
                                    <Bookmark className="size-4" />
                                </Link>
                            </div>
                            <nav
                                className="hub-daily-shortcuts"
                                aria-label="Everyday help"
                            >
                                <Link href={`${baseUrl}?view=resources`}>
                                    Find a guide <Link2 className="size-4" />
                                </Link>
                                {props.capabilities.recognise && (
                                    <Button
                                        variant="ghost"
                                        onClick={() => recognise()}
                                    >
                                        Give a shout-out{' '}
                                        <HeartHandshake className="size-4" />
                                    </Button>
                                )}
                                <Link href={`${baseUrl}?view=events`}>
                                    Join an event{' '}
                                    <CalendarDays className="size-4" />
                                </Link>
                                <Link href={`${baseUrl}?view=people`}>
                                    Find a colleague{' '}
                                    <ArrowRight className="size-4" />
                                </Link>
                            </nav>
                            <HubHero
                                content={props.home}
                                featured={featured}
                                pages={props.pages}
                                demo={props.demo}
                                baseUrl={baseUrl}
                            />
                            {!props.demo && props.shortcuts.length > 0 && (
                                <div className="hub-personal-links">
                                    <span className="text-xs font-semibold text-muted-foreground">
                                        MY SHORTCUTS
                                    </span>
                                    {props.shortcuts.map((link) => (
                                        <a
                                            key={link.id}
                                            href={link.url}
                                            rel="noopener noreferrer"
                                            target={
                                                link.url.startsWith('https:')
                                                    ? '_blank'
                                                    : undefined
                                            }
                                        >
                                            {link.title}
                                            <ArrowRight className="size-3" />
                                        </a>
                                    ))}
                                    <Button
                                        variant="ghost"
                                        size="sm"
                                        onClick={() => setShortcuts(true)}
                                    >
                                        Edit
                                    </Button>
                                </div>
                            )}
                        </>
                    ) : (
                        <div
                            className={
                                (props.view === 'events' && !props.search) ||
                                props.selectedPerson
                                    ? 'hidden'
                                    : 'hub-view-heading'
                            }
                        >
                            <div>
                                <h1>
                                    {props.search
                                        ? 'Search results'
                                        : props.view === 'saved'
                                          ? 'Saved for later'
                                          : currentView.label}
                                </h1>
                                <p>
                                    {props.search
                                        ? `Results for “${props.search}” ${props.view === 'home' ? 'across Company Hub' : `in ${currentView.label.toLowerCase()}`}.`
                                        : currentView.description}
                                </p>
                            </div>
                            {props.view === 'events' &&
                                props.capabilities.calendar && (
                                    <Button variant="outline" asChild>
                                        <Link href="/hr/calendar">
                                            <CalendarDays className="size-4" />
                                            Open calendar
                                        </Link>
                                    </Button>
                                )}
                        </div>
                    )}
                    {selected && !props.demo && (
                        <div className="flex items-center gap-2">
                            <ReadReceipt
                                id={selected.id}
                                revisionId={selected.revision_id}
                            />
                            <Button
                                variant="outline"
                                onClick={() =>
                                    router.post(
                                        '/company-hub/bookmarks',
                                        {
                                            subject_type: 'page',
                                            subject_id: selected.id,
                                            saved: !pageSaved,
                                        },
                                        { preserveScroll: true },
                                    )
                                }
                            >
                                <Bookmark className="size-4" />
                                {pageSaved
                                    ? 'Remove from saved'
                                    : 'Save for later'}
                            </Button>
                            <Button
                                variant="outline"
                                aria-pressed={(
                                    props.followedPages ?? []
                                ).includes(selected.id)}
                                onClick={() =>
                                    router.post(
                                        `/company-hub/pages/${selected.id}/follow`,
                                        {
                                            following: !(
                                                props.followedPages ?? []
                                            ).includes(selected.id),
                                        },
                                        { preserveScroll: true },
                                    )
                                }
                            >
                                <Bell className="size-4" />
                                {(props.followedPages ?? []).includes(
                                    selected.id,
                                )
                                    ? 'Following'
                                    : 'Follow page'}
                            </Button>
                        </div>
                    )}
                    {selected &&
                    isKnowledgePage(selected.kind ?? '', selected.content) ? (
                        <KnowledgeReader
                            content={selected.content}
                            knowledge={props.knowledge}
                            publishedAt={selected.published_at}
                        >
                            <HubBlocks
                                sections={selected.content.sections}
                                data={props}
                                onEvent={setEvent}
                                onRecognise={
                                    props.capabilities.recognise
                                        ? recognise
                                        : undefined
                                }
                            />
                        </KnowledgeReader>
                    ) : selected && props.department ? (
                        <DepartmentSpace
                            key={`${selected.id}:${selected.revision_id}`}
                            content={selected.content}
                            space={props.department}
                            data={props}
                            onEvent={setEvent}
                            onRecognise={
                                props.capabilities.recognise
                                    ? recognise
                                    : undefined
                            }
                        />
                    ) : selected ? (
                        <HubBlocks
                            sections={selected.content.sections}
                            data={props}
                            onEvent={setEvent}
                            onRecognise={
                                props.capabilities.recognise
                                    ? recognise
                                    : undefined
                            }
                        />
                    ) : props.search && props.view === 'home' ? (
                        <HubSearchResults
                            key={props.search}
                            data={props}
                            onEvent={setEvent}
                        />
                    ) : props.view === 'events' ? (
                        <EventBoard data={props} onEvent={setEvent} />
                    ) : props.view === 'news' ? (
                        <HubNewsroom data={props} />
                    ) : props.view === 'shoutouts' ? (
                        <RecognitionBoard
                            data={props}
                            onRecognise={
                                props.capabilities.recognise
                                    ? recognise
                                    : undefined
                            }
                        />
                    ) : props.view === 'people' ? (
                        props.selectedPerson ? (
                            <PersonProfile
                                key={`${props.selectedPerson.id}:${props.selectedPerson.shared_profile?.version ?? 0}`}
                                initial={props.selectedPerson}
                                pages={props.pages}
                                people={props.people ?? []}
                                canViewOrg={props.canViewOrg}
                                onRecognise={
                                    props.capabilities.recognise
                                        ? recognise
                                        : undefined
                                }
                            />
                        ) : (
                            <PeopleDirectory
                                people={props.people ?? []}
                                canViewOrg={props.canViewOrg}
                            />
                        )
                    ) : props.view === 'services' ? (
                        <ServicesWorkspace
                            key={props.search}
                            data={props.services}
                            initialSearch={props.search}
                        />
                    ) : props.view === 'publishing' ? (
                        <EditorialWorkspace
                            onEvent={setEvent}
                            pages={props.editorial ?? []}
                            layout={props.capabilities.layout}
                            canCreate={props.capabilities.write}
                            onCreate={() => setCreate(true)}
                        />
                    ) : props.view === 'ideas' ? (
                        <IdeaBoard
                            ideas={props.ideas ?? []}
                            moderate={props.capabilities.moderate}
                        />
                    ) : ['updates', 'following'].includes(props.view) ? (
                        <UpdatesFeed
                            key={`${props.view}:${props.search}`}
                            data={props}
                            following={props.view === 'following'}
                            onEvent={setEvent}
                        />
                    ) : ['teams', 'campaigns'].includes(props.view) ? (
                        <CompanySpaces
                            data={props}
                            mode={
                                props.view as
                                    | 'teams'
                                    | 'campaigns'
                                    | 'following'
                            }
                        />
                    ) : ['resources', 'pages'].includes(props.view) ? (
                        <ResourceLibrary
                            data={props}
                            allPages={props.view === 'pages'}
                        />
                    ) : props.view !== 'saved' ? (
                        <HubBlocks
                            sections={
                                isHome ? props.home.sections : viewSections
                            }
                            data={props}
                            onEvent={setEvent}
                            onRecognise={
                                props.capabilities.recognise
                                    ? recognise
                                    : undefined
                            }
                            hideViewHeading={!isHome}
                        />
                    ) : savedPages.length === 0 && savedEvents.length === 0 ? (
                        <div className="hub-saved-empty">
                            <span className="hub-empty-symbol">
                                <Bookmark className="size-7" />
                            </span>
                            <h2>
                                {props.search
                                    ? 'No saved items match this search'
                                    : 'Keep something good for later'}
                            </h2>
                            <p>
                                {props.search
                                    ? 'Try a different word or clear your search to see everything you have saved.'
                                    : 'Save a story, resource or event and you’ll find it here, ready when you need it.'}
                            </p>
                            <div className="flex gap-3">
                                <Button variant="outline" asChild>
                                    <Link href="/company-hub?view=news">
                                        Explore news
                                        <ArrowRight className="size-4" />
                                    </Link>
                                </Button>
                                <Button variant="ghost" asChild>
                                    <Link href="/company-hub?view=events">
                                        Browse events
                                    </Link>
                                </Button>
                            </div>
                        </div>
                    ) : (
                        <div className="space-y-5">
                            {savedPages.length > 0 && (
                                <section>
                                    <h2 className="text-section-title mb-4">
                                        Stories & resources
                                    </h2>
                                    <NewsCards pages={savedPages} />
                                </section>
                            )}
                            {savedEvents.length > 0 && (
                                <HubBlocks
                                    sections={[
                                        {
                                            id: 'saved-events',
                                            columns: 1,
                                            blocks: [
                                                {
                                                    id: 'saved-events',
                                                    type: 'events',
                                                    title: 'Saved events',
                                                    limit: 12,
                                                },
                                            ],
                                        },
                                    ]}
                                    data={{ ...props, events: savedEvents }}
                                    onEvent={setEvent}
                                />
                            )}
                        </div>
                    )}
                    {selected && <CampaignProgress data={props} />}
                    {selected && !props.knowledge && (
                        <RelatedPages data={props} />
                    )}
                    {selected &&
                        (selected.content.comments_enabled ||
                            selected.content.reactions_enabled ||
                            ['page', 'knowledge'].includes(
                                selected.kind ?? '',
                            )) && <PageParticipation data={props} />}
                    {props.editablePages.length > 0 && (
                        <div className="hub-management">
                            <Button
                                variant="ghost"
                                onClick={() => setManage(!manage)}
                                aria-expanded={manage}
                            >
                                <Settings2 className="size-4" />
                                Manage pages and drafts
                                <ChevronDown className="size-3" />
                            </Button>
                            {manage && (
                                <div className="mt-4 grid grid-cols-3 gap-5">
                                    {props.editablePages.map((page) => (
                                        <Link
                                            key={page.id}
                                            href={`/company-hub/manage/${page.id}/edit`}
                                            className="rounded-lg border border-border p-4 hover:bg-accent"
                                        >
                                            <span className="block font-medium">
                                                {page.title}
                                            </span>
                                            <span className="mt-1 block text-xs text-muted-foreground">
                                                {page.kind === 'home'
                                                    ? 'Company homepage'
                                                    : page.kind === 'news'
                                                      ? 'News story'
                                                      : 'Resource page'}
                                                {page.archived && ' · Archived'}
                                            </span>
                                        </Link>
                                    ))}
                                </div>
                            )}
                        </div>
                    )}
                </div>
            </div>
            <CreateHubPageDialog open={create} close={() => setCreate(false)} />
            {!!props.announcementId && !props.demo && (
                <HubAnnouncementDialog
                    key={props.announcementId}
                    id={props.announcementId}
                    onClose={() =>
                        router.get(
                            '/company-hub',
                            { view: 'updates', search: props.search },
                            { preserveScroll: true, preserveState: true },
                        )
                    }
                />
            )}
            <Dialog open={demoThanks} onOpenChange={setDemoThanks}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>
                            A small thank-you can make someone’s day.
                        </DialogTitle>
                        <DialogDescription>
                            On your company hub, choose one colleague or a whole
                            team, share what they did, and celebrate the values
                            they brought to life. The shout-out appears here and
                            in HR.
                        </DialogDescription>
                    </DialogHeader>
                    <div className="rounded-xl bg-primary/5 p-5 text-sm leading-relaxed">
                        <strong>Aroha Bennett</strong>
                        <p className="mt-2">
                            “Thank you for making everyone feel welcome. Your
                            kindness makes a real difference.”
                        </p>
                        <p className="mt-3 text-muted-foreground">
                            Fictional example · no notification will be sent
                        </p>
                    </div>
                    <DialogFooter>
                        <Button onClick={() => setDemoThanks(false)}>
                            Back to the showcase
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
            <BirthdaySettingsDialog
                open={birthdays}
                close={() => setBirthdays(false)}
                people={props.birthdaySettings}
            />
            <ShortcutDialog
                open={shortcuts}
                close={() => setShortcuts(false)}
                shortcuts={props.shortcuts}
            />
            <HubNotificationDialog
                open={notifications}
                close={() => setNotifications(false)}
                preferences={props.notificationPreferences}
            />
            <HubEventDialog
                events={props.events}
                onSelect={setEvent}
                bookmarks={props.bookmarks}
                preview={props.demo}
                event={
                    event
                        ? (props.events.find((e) => e.id === event.id) ?? event)
                        : null
                }
                close={() => setEvent(null)}
            />
            <RecognitionWizard
                open={recognition}
                onClose={() => setRecognition(false)}
                employees={props.employees}
                kudosCategories={props.kudosCategories}
                kudosImpacts={props.kudosImpacts}
                defaults={defaults}
            />
        </AppLayout>
    );
}
