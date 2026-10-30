import { PageHeaderRail } from '@/components/page/page-header';
import { AppreciationIcon } from '@/components/recognition/appreciation-icon';
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
import { Head, Link, router, usePage } from '@inertiajs/react';
import {
    ArrowLeft,
    ArrowRight,
    Bell,
    Bookmark,
    BookOpen,
    Cake,
    CalendarDays,
    ChevronDown,
    Link2,
    Pencil,
    Plus,
    Settings2,
    UsersRound,
} from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { HubAnnouncementDialog } from './_announcement';
import { previewEditorialBlocks } from './_block-preview';
import { HubBlocks, NewsCards } from './_blocks';
import {
    HubBookmarkButton,
    HubBookmarksProvider,
    useHubBookmarks,
} from './_bookmarks';
import { browseReturnLink } from './_browse-state';
import { CampaignReader } from './_campaign-reader';
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
    HubNotificationDialog,
    ShortcutDialog,
} from './_dialogs';
import { HubNewsroom, RecognitionBoard, RelatedPages } from './_discovery';
import { EditorialWorkspace } from './_editorial';
import { EditorialSetup } from './_editorial-setup';
import { eventReaderUrl, HubEventReader } from './_event-reader';
import { EventWorkspace } from './_event-workspace';
import { EventBoard } from './_events';
import { HubArticleHeader, HubSiteHeader } from './_hero';
import { HomeSaved } from './_home-saved';
import { InterestsDialog } from './_interests';
import { isKnowledgePage, KnowledgeReader } from './_knowledge';
import { HubNavigationRail } from './_navigation';
import { navigationDiscovery } from './_navigation-discovery';
import {
    HubPageCreationContext,
    useHubPageCreationWorkspace,
} from './_page-creation-context';
import { PageParticipation } from './_participation';
import { PeopleDirectory } from './_people';
import { PersonProfile } from './_person-profile';
import { readerReturnLabels, type ReaderFrame } from './_reader-trail';
import { ReadingAttention } from './_reading-attention';
import { HubReadingProvider, useHubReading } from './_reading-navigation';
import { shareHubRecognition } from './_recognition-submission';
import { ResourceLibrary } from './_resource-library';
import { savedDocuments, SavedDocuments } from './_saved-documents';
import { HubSearchResults } from './_search';
import { ServicesWorkspace } from './_services';
import type { HubEvent, HubProps, HubSection } from './_types';
import { UpdatesFeed } from './_updates';
import './designer.css';
import './hub.css';

const views = [
    { key: 'home', label: 'Home', description: '' },
    {
        key: 'guides',
        label: 'Company guides',
        description: 'Practical guidance and supporting documents.',
    },
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
    const actorId = usePage<{ auth?: { user?: { id?: number } } }>().props.auth
        ?.user?.id;
    if (props.demo || !actorId) return <CompanyHubView {...props} />;
    const label: ReaderFrame['label'] = props.selectedEvent
        ? 'event'
        : props.selectedPerson
          ? 'profile'
          : props.selectedPage
            ? props.selectedPage.kind === 'department'
                ? 'team'
                : props.selectedPage.kind === 'campaign'
                  ? 'campaign'
                  : props.selectedPage.kind === 'knowledge'
                    ? 'guide'
                    : 'story'
            : props.search
              ? 'search'
              : Object.hasOwn(readerReturnLabels, props.view)
                ? (props.view as ReaderFrame['label'])
                : 'hub';
    return (
        <HubReadingProvider key={actorId} actorId={actorId} label={label}>
            <HubBookmarksProvider
                key={actorId}
                actorId={actorId}
                initial={props.bookmarks}
            >
                <CompanyHubView {...props} />
            </HubBookmarksProvider>
        </HubReadingProvider>
    );
}

function CompanyHubView(incoming: HubProps) {
    const bookmarks = useHubBookmarks();
    const props = bookmarks
        ? { ...incoming, bookmarks: bookmarks.bookmarks }
        : incoming;
    const reading = useHubReading();
    const [seenUpdates, setSeenUpdates] = useState<{
        source: HubProps['updates'];
        keys: string[];
    }>({ source: props.updates, keys: [] });
    const currentUpdates = useRef(props.updates);
    useEffect(() => {
        currentUpdates.current = props.updates;
    }, [props.updates]);
    const navigationUpdates =
        seenUpdates.source === props.updates
            ? props.updates?.map((item) =>
                  seenUpdates.keys.includes(item.key)
                      ? { ...item, unread: false }
                      : item,
              )
            : props.updates;
    const { url: currentUrl, props: shared } = usePage<{
        auth?: { user?: { id?: number } };
    }>();
    const recognitionActorId = shared.auth?.user?.id;
    const eventQuery = new URL(
        currentUrl || window.location.href,
        window.location.origin,
    ).searchParams;
    const requestedWorkspace =
        eventQuery.get('event_workspace') ||
        (eventQuery.has('event_draft') ? 'drafts' : '');
    const eventWorkspace =
        !props.demo &&
        props.capabilities.createEvent &&
        ['new', 'edit', 'drafts'].includes(requestedWorkspace)
            ? requestedWorkspace
            : '';

    const [recognition, setRecognition] = useState(false);
    const [defaults, setDefaults] = useState<RecognitionDefaults>();
    const {
        creation: create,
        open: openCreation,
        close: closeCreation,
    } = useHubPageCreationWorkspace();
    const [manage, setManage] = useState(false);
    const [birthdays, setBirthdays] = useState(false);
    const [shortcuts, setShortcuts] = useState(false);
    const [notifications, setNotifications] = useState(false);
    const [hubMenuOpen, setHubMenuOpen] = useState(false);
    const [notificationInterests, setNotificationInterests] = useState(false);
    const [event, setEvent] = useState<HubEvent | null>(
        props.selectedEvent ?? null,
    );
    const [eventReturnLabel, setEventReturnLabel] = useState('Back to events');
    useEffect(() => {
        setEvent(props.selectedEvent ?? null);
    }, [props.selectedEvent, props.view, props.selectedPage?.id, props.search]);
    const eventReturn = useRef<{
        href: string;
        key: string;
        focus: HTMLElement | null;
        scroll: number;
        regions: number[];
    } | null>(null);
    const openEvent = (item: HubEvent) => {
        if (!event) {
            setEventReturnLabel(
                props.search
                    ? 'Back to search results'
                    : `Back to ${views.find((view) => view.key === props.view)?.label.toLowerCase() ?? 'the hub'}`,
            );
            eventReturn.current = {
                href: window.location.pathname + window.location.search,
                key: item.id,
                focus:
                    document.activeElement instanceof HTMLElement
                        ? document.activeElement
                        : null,
                scroll: window.scrollY,
                regions: [...document.querySelectorAll('[scroll-region]')].map(
                    (region) => region.scrollTop,
                ),
            };
        }
        if (props.demo) setEvent(item);
        else if (reading) reading.open(eventReaderUrl(item));
        else
            router.visit(eventReaderUrl(item), {
                preserveState: true,
                preserveScroll: true,
            });
    };
    const closeEvent = () => {
        if (reading?.returnLabel) {
            reading.back();
            return;
        }
        const origin = eventReturn.current;
        const restore = () =>
            requestAnimationFrame(() => {
                const target = origin?.focus?.isConnected
                    ? origin.focus
                    : [
                          ...document.querySelectorAll<HTMLElement>(
                              '[data-hub-event-key]',
                          ),
                      ].find(
                          (element) =>
                              element.dataset.hubEventKey === origin?.key,
                      );
                target?.focus({ preventScroll: true });
                window.scrollTo({
                    top: origin?.scroll ?? 0,
                    behavior: 'instant',
                });
                document
                    .querySelectorAll('[scroll-region]')
                    .forEach((region, index) => {
                        if (origin?.regions[index] !== undefined)
                            region.scrollTop = origin.regions[index];
                    });
            });
        setEvent(null);
        if (props.demo) restore();
        else
            router.visit(origin?.href ?? '/company-hub?view=events', {
                preserveState: true,
                preserveScroll: true,
                onSuccess: restore,
            });
    };
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
    const returnToBrowse =
        typeof window === 'undefined'
            ? null
            : browseReturnLink(baseUrl, window.location.search);
    const pageData = props.demo
        ? {
              ...props,
              blockData: previewEditorialBlocks(
                  (selected?.content ?? props.home).sections,
                  props,
              ),
          }
        : props;
    const currentView =
        views.find((view) => view.key === props.view) ?? views[0];
    const savedFiles = savedDocuments(props);
    const savedPages = props.pages.filter((page) =>
        props.bookmarks.some(
            (item) =>
                item.subject_type === 'page' && item.subject_id === page.id,
        ),
    );
    const savedEvents = (props.savedEvents ?? props.events).filter((event) =>
        props.bookmarks.some(
            (item) =>
                item.subject_type === 'event' &&
                item.subject_id ===
                    (event.extendedProps.savedSubjectId ??
                        event.extendedProps.recurrenceParentId ??
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
    const hubActions = (
        <>
            {!props.demo && (
                <DropdownMenu open={hubMenuOpen} onOpenChange={setHubMenuOpen}>
                    <DropdownMenuTrigger asChild>
                        <Button variant="ghost">
                            <Link2 className="size-4" />
                            My hub
                            <ChevronDown className="size-3" />
                        </Button>
                    </DropdownMenuTrigger>
                    <DropdownMenuContent
                        align="start"
                        onClickCapture={(event) => {
                            if (
                                event.button === 0 &&
                                !event.metaKey &&
                                !event.ctrlKey &&
                                !event.shiftKey &&
                                !event.altKey &&
                                event.target instanceof Element &&
                                event.target.closest('a[href]')
                            )
                                setHubMenuOpen(false);
                        }}
                    >
                        <DropdownMenuItem asChild>
                            <Link href={`${baseUrl}?view=saved`}>
                                <Bookmark className="size-4" />
                                Saved for later
                            </Link>
                        </DropdownMenuItem>
                        <DropdownMenuItem asChild>
                            <Link href="/company-hub?view=following">
                                <AppreciationIcon className="size-4" />
                                My interests & following
                            </Link>
                        </DropdownMenuItem>
                        <DropdownMenuItem asChild>
                            <Link href="/company-hub?view=updates">
                                <Bell className="size-4" />
                                Company updates
                                {(navigationUpdates ?? []).some(
                                    (item) => item.unread,
                                ) && (
                                    <span
                                        className="ml-2 size-2 rounded-full bg-primary"
                                        aria-label="New updates"
                                    />
                                )}
                            </Link>
                        </DropdownMenuItem>
                        <DropdownMenuItem onSelect={() => setShortcuts(true)}>
                            <Link2 className="size-4" />
                            My shortcuts
                        </DropdownMenuItem>
                        <DropdownMenuItem
                            onSelect={() => setNotifications(true)}
                        >
                            <Bell className="size-4" />
                            Notification preferences
                        </DropdownMenuItem>
                        {props.capabilities.celebrations && (
                            <>
                                <DropdownMenuSeparator />
                                <DropdownMenuItem
                                    onSelect={() => setBirthdays(true)}
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
                        {props.capabilities.layout && (
                            <DropdownMenuItem asChild>
                                <Link href="/company-hub/showcase">
                                    <Pencil className="size-4" />
                                    View showcase
                                </Link>
                            </DropdownMenuItem>
                        )}
                    </DropdownMenuContent>
                </DropdownMenu>
            )}
            {!isHome && props.view !== 'shoutouts' && (
                <span className="flex-1" />
            )}
            {props.demo && (
                <Button variant="ghost" asChild>
                    <Link
                        href={
                            selected?.slug === 'sample-campaign'
                                ? '/company-hub/showcase/edit?story=sample-campaign'
                                : '/company-hub/showcase/edit'
                        }
                    >
                        <Pencil className="size-4" />
                        Try the editor
                    </Link>
                </Button>
            )}
            {props.capabilities.write && (
                <Button
                    variant={isHome ? 'default' : 'ghost'}
                    disabled={!!create}
                    onClick={() => openCreation()}
                >
                    <Plus className="size-4" />
                    Create
                </Button>
            )}
            {props.capabilities.layout && isHome && (
                <Button
                    variant="outline"
                    disabled={!!create}
                    onClick={() =>
                        props.homeId
                            ? router.visit(
                                  `/company-hub/manage/${props.homeId}/edit`,
                              )
                            : router.post('/company-hub/pages', {
                                  kind: 'home',
                                  title: props.home.title,
                              })
                    }
                >
                    <Pencil className="size-4" />
                    Design hub
                </Button>
            )}
            {selected?.can_edit && (
                <Button variant="outline" asChild>
                    <Link href={`/company-hub/manage/${selected.id}/edit`}>
                        <Pencil className="size-4" />
                        Edit page
                    </Link>
                </Button>
            )}
        </>
    );
    return (
        <HubPageCreationContext.Provider value={openCreation}>
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
                <div className="hub-portal" onClickCapture={reading?.capture}>
                    {props.demo && (
                        <div className="hub-demo-strip">
                            <span>
                                <strong>Showcase</strong>
                                <span className="ml-2">
                                    Sample records — nothing is saved or sent
                                </span>
                            </span>
                            <Link href="/company-hub">
                                Your company hub{' '}
                                <ArrowRight className="size-4" />
                            </Link>
                        </div>
                    )}
                    <HubSiteHeader
                        content={selected?.content ?? props.home}
                        summary={selected ? undefined : props.heroSummary}
                        onEditHero={
                            !create &&
                            (selected
                                ? selected.can_edit
                                : props.capabilities.layout)
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
                            if (reading) {
                                reading.primary(
                                    `${baseUrl}?view=home&search=${encodeURIComponent(search)}`,
                                );
                                return;
                            }
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
                            isHome ||
                            props.view === 'shoutouts' ||
                            eventWorkspace ||
                            create
                                ? undefined
                                : hubActions
                        }
                    >
                        {props.sharedNavigation && !props.demo ? (
                            <HubNavigationRail
                                onNavigate={reading?.primary}
                                items={props.sharedNavigation}
                                updates={navigationUpdates}
                                relatedPageIds={props.pages
                                    .filter((page) => page.kind === 'knowledge')
                                    .map((page) => page.id)}
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
                                    maxVisible={8}
                                    items={[
                                        ...views
                                            .filter(
                                                (view) =>
                                                    view.key !== 'publishing' ||
                                                    (!props.demo &&
                                                        (props.capabilities
                                                            .write ||
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
                                                        'guides',
                                                        'teams',
                                                        'campaigns',
                                                        'ideas',
                                                    ].includes(view.key),
                                            )
                                            .map((view) => ({
                                                key: view.key,
                                                label: view.label,
                                                overflow: navigationDiscovery(
                                                    {
                                                        type: 'view',
                                                        target: view.key,
                                                    },
                                                    navigationUpdates,
                                                ),
                                            })),
                                        ...props.navigation.map((page) => ({
                                            key: `page:${page.id}`,
                                            label: page.title,
                                            overflow: navigationDiscovery(
                                                {
                                                    type: 'page',
                                                    target: String(page.id),
                                                },
                                                navigationUpdates,
                                            ),
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
                                        (
                                            reading?.primary ??
                                            router.visit.bind(router)
                                        )(
                                            page
                                                ? `/company-hub/read/${page.slug}`
                                                : `${baseUrl}?view=${key}`,
                                        );
                                    }}
                                />
                            </nav>
                        )}
                    </HubSiteHeader>
                    {create && (
                        <div className="hub-page-canvas">
                            <CreateHubPageDialog
                                open
                                initial={create.initial}
                                context={create.context}
                                close={closeCreation}
                            />
                        </div>
                    )}
                    {!!props.announcementId && !props.demo && (
                        <div className="hub-page-canvas">
                            <HubAnnouncementDialog
                                inline
                                key={props.announcementId}
                                id={props.announcementId}
                                onClose={() =>
                                    reading?.returnHref
                                        ? reading.back()
                                        : router.get(
                                              '/company-hub',
                                              {
                                                  view: 'updates',
                                                  search: props.search,
                                              },
                                              {
                                                  preserveScroll: true,
                                                  preserveState: true,
                                              },
                                          )
                                }
                            />
                        </div>
                    )}
                    {eventWorkspace && !create && (
                        <div className="hub-page-canvas hub-event-workspace-canvas">
                            <EventWorkspace
                                mode={eventWorkspace}
                                event={props.selectedEvent}
                            />
                        </div>
                    )}
                    {event && !eventWorkspace && !create && (
                        <div className="hub-page-canvas">
                            <HubEventReader
                                onRecognise={
                                    props.capabilities.recognise
                                        ? () => recognise()
                                        : undefined
                                }
                                event={
                                    props.events.find(
                                        (item) => item.id === event.id,
                                    ) ?? event
                                }
                                events={props.events}
                                bookmarks={props.bookmarks}
                                preview={props.demo}
                                sample={props.demo}
                                sampleRoster={
                                    props.demo
                                        ? props.showcaseEventRosters?.[event.id]
                                        : undefined
                                }
                                onSelect={(item) => {
                                    if (!props.demo && reading) {
                                        reading.open(eventReaderUrl(item));
                                        return;
                                    }
                                    setEvent(item);
                                    if (!props.demo)
                                        router.visit(eventReaderUrl(item), {
                                            preserveState: true,
                                            preserveScroll: true,
                                        });
                                }}
                                close={closeEvent}
                                returnLabel={
                                    reading?.returnLabel ?? eventReturnLabel
                                }
                            />
                        </div>
                    )}
                    <div
                        className="hub-page-canvas"
                        hidden={
                            !!event ||
                            !!eventWorkspace ||
                            !!create ||
                            !!props.announcementId
                        }
                        style={
                            event ||
                            eventWorkspace ||
                            create ||
                            props.announcementId
                                ? { display: 'none' }
                                : undefined
                        }
                    >
                        {!selected && (
                            <ReadingAttention data={props} home={isHome} />
                        )}
                        {!selected &&
                            !props.selectedPerson &&
                            !props.recognitionId &&
                            !props.recognitionUnavailable &&
                            !event &&
                            reading?.returnLabel && (
                                <Button
                                    variant="link"
                                    className="h-auto justify-start p-0 text-sm"
                                    onClick={reading.back}
                                >
                                    <ArrowLeft className="size-4" />
                                    {reading.returnLabel}
                                </Button>
                            )}
                        {selected ? (
                            <>
                                <Link
                                    className="inline-flex items-center gap-2 text-sm text-primary"
                                    data-hub-reading-back
                                    href={
                                        reading?.returnHref ??
                                        returnToBrowse?.href ??
                                        baseUrl
                                    }
                                    onClick={(event) => {
                                        if (
                                            reading?.returnLabel &&
                                            event.button === 0 &&
                                            !event.metaKey &&
                                            !event.ctrlKey &&
                                            !event.shiftKey &&
                                            !event.altKey
                                        ) {
                                            event.preventDefault();
                                            reading.back();
                                        }
                                    }}
                                >
                                    <ArrowLeft className="size-4" />
                                    {reading?.returnLabel ??
                                        returnToBrowse?.label ??
                                        'Back to the hub'}
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
                                    <div className="hub-home-actions">
                                        {hubActions}
                                    </div>
                                </div>
                                <nav
                                    className="hub-daily-shortcuts"
                                    aria-label="Everyday help"
                                >
                                    <Link href={`${baseUrl}?view=resources`}>
                                        Find a guide{' '}
                                        <BookOpen className="size-4" />
                                    </Link>
                                    {props.capabilities.recognise && (
                                        <Button
                                            variant="ghost"
                                            onClick={() => recognise()}
                                        >
                                            Give a shout-out{' '}
                                            <AppreciationIcon className="size-4" />
                                        </Button>
                                    )}
                                    <Link href={`${baseUrl}?view=events`}>
                                        Join an event{' '}
                                        <CalendarDays className="size-4" />
                                    </Link>
                                    <Link href={`${baseUrl}?view=people`}>
                                        Find a colleague{' '}
                                        <UsersRound className="size-4" />
                                    </Link>
                                </nav>
                                <HomeSaved data={props} onEvent={openEvent} />
                                <EditorialSetup data={props} />
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
                                                    link.url.startsWith(
                                                        'https:',
                                                    )
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
                                    ([
                                        'events',
                                        'shoutouts',
                                        'news',
                                        'resources',
                                        'guides',
                                        'pages',
                                    ].includes(props.view) &&
                                        !props.search) ||
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
                                <HubBookmarkButton
                                    subject={{
                                        subject_type: 'page',
                                        subject_id: selected.id,
                                    }}
                                    title={selected.content.title}
                                    saved={pageSaved}
                                />
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
                        isKnowledgePage(
                            selected.kind ?? '',
                            selected.content,
                        ) ? (
                            <KnowledgeReader
                                content={selected.content}
                                knowledge={props.knowledge}
                                publishedAt={selected.published_at}
                            >
                                <HubBlocks
                                    sections={selected.content.sections}
                                    data={pageData}
                                    onEvent={openEvent}
                                    onRecognise={
                                        props.capabilities.recognise
                                            ? recognise
                                            : undefined
                                    }
                                />
                            </KnowledgeReader>
                        ) : selected && props.campaign ? (
                            <CampaignReader
                                content={selected.content}
                                campaign={props.campaign}
                                data={pageData}
                                onEvent={openEvent}
                            />
                        ) : selected && props.department ? (
                            <DepartmentSpace
                                key={`${selected.id}:${selected.revision_id}`}
                                content={selected.content}
                                space={props.department}
                                data={pageData}
                                onEvent={openEvent}
                                onRecognise={
                                    props.capabilities.recognise
                                        ? recognise
                                        : undefined
                                }
                            />
                        ) : selected ? (
                            <HubBlocks
                                sections={selected.content.sections}
                                data={pageData}
                                onEvent={openEvent}
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
                                onEvent={openEvent}
                            />
                        ) : props.view === 'events' ? (
                            <EventBoard data={props} onEvent={openEvent} />
                        ) : props.view === 'news' ? (
                            <HubNewsroom data={props} />
                        ) : props.view === 'shoutouts' ? (
                            <RecognitionBoard
                                key={`${props.recognition?.actor_id ?? 'anonymous'}:${props.search}`}
                                data={props}
                                actions={hubActions}
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
                                actorId={shared.auth?.user?.id ?? null}
                                onEvent={openEvent}
                                pages={props.editorial ?? []}
                                layout={props.capabilities.layout}
                                canCreate={props.capabilities.write}
                                onCreate={() => openCreation()}
                            />
                        ) : props.view === 'ideas' ? (
                            <IdeaBoard
                                browse={props.ideaBrowse}
                                readOnly={props.demo}
                                key={`${props.ideaActorId ?? 'anonymous'}:${props.ideaId ?? ''}:${props.ideaUnavailable ?? false}:${props.search}`}
                                ideas={
                                    props.selectedIdea &&
                                    !props.ideas?.some(
                                        (idea) =>
                                            idea.id === props.selectedIdea!.id,
                                    )
                                        ? [
                                              props.selectedIdea,
                                              ...(props.ideas ?? []),
                                          ]
                                        : (props.ideas ?? [])
                                }
                                initialIdeaId={props.ideaId ?? null}
                                initialUnavailable={
                                    props.ideaUnavailable ?? false
                                }
                                moderate={props.capabilities.moderate}
                                actorId={props.ideaActorId ?? null}
                                progressReady={props.ideaProgressReady ?? false}
                                teams={props.ideaTeams ?? []}
                                owners={props.ideaOwners ?? []}
                            />
                        ) : ['updates', 'following'].includes(props.view) ? (
                            <UpdatesFeed
                                key={`${props.view}:${props.search}`}
                                data={props}
                                following={props.view === 'following'}
                                onEvent={openEvent}
                                onSeenChange={(keys) =>
                                    currentUpdates.current === props.updates &&
                                    setSeenUpdates({
                                        source: props.updates,
                                        keys,
                                    })
                                }
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
                        ) : ['resources', 'guides', 'pages'].includes(
                              props.view,
                          ) ? (
                            <ResourceLibrary
                                key={props.view}
                                data={props}
                                allPages={props.view === 'pages'}
                                guidesOnly={props.view === 'guides'}
                            />
                        ) : props.view !== 'saved' ? (
                            <HubBlocks
                                sections={
                                    isHome ? props.home.sections : viewSections
                                }
                                data={pageData}
                                onEvent={openEvent}
                                onRecognise={
                                    props.capabilities.recognise
                                        ? recognise
                                        : undefined
                                }
                                hideViewHeading={!isHome}
                            />
                        ) : savedPages.length === 0 &&
                          savedEvents.length === 0 &&
                          savedFiles.length === 0 ? (
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
                                {savedFiles.length > 0 && (
                                    <SavedDocuments
                                        documents={savedFiles}
                                        demo={props.demo}
                                    />
                                )}
                                {savedPages.length > 0 && (
                                    <section>
                                        <h2 className="text-section-title mb-4">
                                            Stories & resources
                                        </h2>
                                        <NewsCards
                                            pages={savedPages}
                                            bookmarks={props.bookmarks}
                                            demo={props.demo}
                                        />
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
                                        onEvent={openEvent}
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
                                ) ||
                                props.participation?.can_moderate) && (
                                <PageParticipation
                                    key={`${selected.id}:${selected.revision_id}:${props.participation?.actor_id}`}
                                    data={props}
                                />
                            )}
                        {(props.editablePages.length > 0 ||
                            props.discardedDrafts?.some(
                                (page) => page.draft_recovery.recovery,
                            )) && (
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
                                                    {page.archived &&
                                                        ' · Archived'}
                                                    {page.has_saved_draft &&
                                                        ' · Saved draft'}
                                                </span>
                                            </Link>
                                        ))}
                                    </div>
                                )}
                                {manage &&
                                    !!props.discardedDrafts?.some(
                                        (page) => page.draft_recovery.recovery,
                                    ) && (
                                        <section
                                            className="mt-6"
                                            aria-label="Discarded drafts"
                                        >
                                            <h2 className="text-sm font-semibold">
                                                Discarded drafts
                                            </h2>
                                            <p className="mt-1 text-sm text-muted-foreground">
                                                Open a page to review recovery
                                                options. Published pages remain
                                                available.
                                            </p>
                                            <div className="mt-3 grid grid-cols-3 gap-5">
                                                {props.discardedDrafts
                                                    .filter(
                                                        (page) =>
                                                            page.draft_recovery
                                                                .recovery,
                                                    )
                                                    .map((page) => (
                                                        <Link
                                                            key={page.id}
                                                            href={`/company-hub/manage/${page.id}/edit`}
                                                            className="rounded-lg border border-border p-4 hover:bg-accent"
                                                        >
                                                            <span className="block font-medium">
                                                                {page
                                                                    .draft_recovery
                                                                    .recovery
                                                                    ?.title ??
                                                                    page.title}
                                                            </span>
                                                            <span className="mt-1 block text-xs text-muted-foreground">
                                                                Review draft
                                                                recovery
                                                            </span>
                                                        </Link>
                                                    ))}
                                            </div>
                                        </section>
                                    )}
                            </div>
                        )}
                    </div>
                </div>
                <Dialog open={demoThanks} onOpenChange={setDemoThanks}>
                    <DialogContent>
                        <DialogHeader>
                            <DialogTitle>
                                A small thank-you can make someone’s day.
                            </DialogTitle>
                            <DialogDescription>
                                On your company hub, choose one colleague or a
                                whole team, share what they did, and celebrate
                                the values they brought to life. The shout-out
                                appears here and in HR.
                            </DialogDescription>
                        </DialogHeader>
                        <div className="rounded-xl bg-primary/5 p-5 text-sm leading-relaxed">
                            <strong>Aroha Bennett</strong>
                            <p className="mt-2">
                                “Thank you for making everyone feel welcome.
                                Your kindness makes a real difference.”
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
                    onInterests={
                        props.interests
                            ? () => {
                                  setNotifications(false);
                                  setNotificationInterests(true);
                              }
                            : undefined
                    }
                    onSaved={() =>
                        router.reload({
                            only: [
                                'notificationPreferences',
                                'notificationPreferenceContext',
                                'events',
                                'selectedEvent',
                            ],
                        })
                    }
                />
                {notificationInterests && props.interests && (
                    <InterestsDialog
                        initial={props.interests}
                        onClose={() => setNotificationInterests(false)}
                        onSaved={() => router.reload({ only: ['interests'] })}
                        onReloaded={() =>
                            router.reload({ only: ['interests'] })
                        }
                    />
                )}
                <RecognitionWizard
                    key={recognitionActorId ?? 'guest'}
                    open={recognition}
                    onClose={() => setRecognition(false)}
                    employees={props.employees.map((person) => ({
                        id: person.id,
                        name: person.name,
                        role: person.position,
                        site: person.site,
                        team: person.department,
                    }))}
                    teams={props.recognitionTeams}
                    kudosCategories={props.kudosCategories}
                    kudosImpacts={props.kudosImpacts}
                    defaults={defaults}
                    submission={
                        recognitionActorId
                            ? {
                                  actorId: recognitionActorId,
                                  mentionOptions:
                                      '/company-hub/recognition/mention-options',
                                  send: (payload) =>
                                      shareHubRecognition(
                                          recognitionActorId,
                                          payload,
                                      ),
                                  onView: (url) => {
                                      setRecognition(false);
                                      if (reading) reading.open(url);
                                      else router.visit(url);
                                  },
                              }
                            : undefined
                    }
                />
            </AppLayout>
        </HubPageCreationContext.Provider>
    );
}
