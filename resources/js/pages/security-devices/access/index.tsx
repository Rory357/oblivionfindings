/**
 * Access (S&D rebuild drawings pg-sdr-19 Doors, 19b People, 19c Visitors, 19d Schedules, 19f
 * Activity): who can open which doors, their cards, visitor passes, door groups and the door log,
 * from each Site's UniFi Access through its hub. Changes (pop-ups 81–84, 90) are jobs the hub runs;
 * each pop-up waits on its job. The fire roll call (87) opens from the header.
 * Clock-ins from doors (19e) come later with their own part of the build.
 */
import { EntityContextMenu, useEntityContextMenu, type MenuItem } from '@/components/lists';
import {
    PageHeader,
    PageHeaderFilterSelect,
    PageHeaderGlassButton,
    PageHeaderMeterBar,
    PageHeaderMeterBig,
    PageHeaderMeterBlock,
    PageHeaderMeterCaption,
    PageHeaderPrimaryButton,
    PageHeaderRail,
    PageHeaderSearch,
    PageLayout,
} from '@/components/page';
import {
    AddCard,
    AddVisitorPass,
    CancelVisitorPass,
    ChangeDoors,
    GiveAccess,
    MatchPeople,
    PersonOnOff,
    TurnOffCard,
    UndoMatch,
    type CardTarget,
} from '@/components/security-devices/access-popups';
import { RollCall } from '@/components/security-devices/access-roll-call';
import AppLayout from '@/layouts/app-layout';
import { Head, router } from '@inertiajs/react';
import {
    CreditCard,
    DoorOpen,
    ExternalLink,
    History,
    KeyRound,
    Link as LinkIcon,
    ListChecks,
    Nfc,
    Plus,
    Ticket,
    Unlink,
    UserRoundCheck,
    UserRoundX,
    Users,
    XCircle,
} from 'lucide-react';
import { useEffect, useRef, useState, type ReactNode } from 'react';
import { ActivityView } from './_activity';
import { DoorsView } from './_doors';
import { lineText } from './_parts';
import { PeopleView } from './_people';
import { SchedulesView } from './_schedules';
import type { AccessPageProps, AccessTab, ActivityRow, DoorCard, PersonRow, ScheduleRow, VisitorRow } from './_types';
import { VisitorsView } from './_visitors';

export type { AccessPageProps } from './_types';

const BASE = '/security-devices/access';

const TAB_LABEL: Record<AccessTab, string> = {
    doors: 'Doors',
    people: 'People',
    visitors: 'Visitors',
    schedules: 'Schedules',
    activity: 'Activity',
};

const DOOR_STATES = [
    { value: 'attention', label: 'Needs a look' },
    { value: 'locked', label: 'Locked' },
    { value: 'unlocked', label: 'Unlocked' },
];
const PEOPLE_STATUSES = [
    { value: 'problems', label: 'Problems' },
    { value: 'to_match', label: 'To match' },
    { value: 'active', label: 'Active' },
    { value: 'off', label: 'Turned off' },
];
const VISITOR_WHEN = [
    { value: 'today', label: 'Today' },
    { value: 'coming', label: 'Coming up' },
    { value: 'ended', label: 'Ended' },
];
const EVENT_KINDS = [
    { value: 'opened', label: 'Came in' },
    { value: 'denied', label: 'Refused' },
    { value: 'forced', label: 'Forced open' },
    { value: 'held_open', label: 'Held open' },
    { value: 'unlocked', label: 'Unlocked remotely' },
];
const EVENT_DAYS = [
    { value: '7', label: 'Last 7 days' },
    { value: '30', label: 'Last 30 days' },
    { value: '90', label: 'Last 90 days' },
    { value: 'all', label: 'Everything kept' },
];

type Popup =
    | { kind: 'give' }
    | { kind: 'visitor' }
    | { kind: 'card'; target: CardTarget }
    | { kind: 'card-off'; person: PersonRow; card: { id: number; display: string } }
    | { kind: 'on-off'; person: PersonRow }
    | { kind: 'doors'; person: PersonRow }
    | { kind: 'match'; people: PersonRow[] }
    | { kind: 'unmatch'; person: PersonRow }
    | { kind: 'cancel-visitor'; visitor: VisitorRow }
    | { kind: 'roll'; siteId: string | null }
    | null;

function useNow(): Date {
    const [now, setNow] = useState(() => new Date());
    useEffect(() => {
        const timer = window.setInterval(() => setNow(new Date()), 60_000);
        return () => window.clearInterval(timer);
    }, []);
    return now;
}

export default function AccessIndex(props: AccessPageProps) {
    const { tab, filters, sites, consoles, counts, meters, can } = props;
    const now = useNow();
    const [search, setSearch] = useState(filters.q ?? '');
    const [popup, setPopup] = useState<Popup>(null);
    const searchTimer = useRef<number | null>(null);
    const doorMenu = useEntityContextMenu<DoorCard>();
    const personMenu = useEntityContextMenu<PersonRow>();
    const visitorMenu = useEntityContextMenu<VisitorRow>();
    const groupMenu = useEntityContextMenu<ScheduleRow>();
    const eventMenu = useEntityContextMenu<ActivityRow>();
    const activity = props.activity;
    const siteId = filters.site === 'all' ? null : Number(filters.site);

    const closeMenus = () => {
        doorMenu.close();
        personMenu.close();
        visitorMenu.close();
        groupMenu.close();
        eventMenu.close();
    };

    /** Change the view. Tab-only filters drop when the tab changes. */
    const go = (changes: Record<string, string | number | null | undefined>) => {
        closeMenus();
        const nextTab = (changes.tab as AccessTab | undefined) ?? tab;
        const sameTab = nextTab === tab;
        const next: Record<string, string | number | null | undefined> = {
            tab: nextTab === 'doors' ? undefined : nextTab,
            site: filters.site,
            q: filters.q ?? undefined,
            state: sameTab && tab === 'doors' ? filters.state : undefined,
            status: sameTab && tab === 'people' ? filters.status : undefined,
            when: sameTab && tab === 'visitors' ? filters.when : undefined,
            door: sameTab && activity ? activity.filters.door : undefined,
            kind: sameTab && activity ? activity.filters.kind : undefined,
            days: sameTab && activity && activity.filters.days !== 'today' ? activity.filters.days : undefined,
            person: sameTab && activity ? activity.filters.person : undefined,
            page: undefined,
            ...changes,
        };
        if (next.tab === 'doors') next.tab = undefined;
        router.get(
            BASE,
            Object.fromEntries(Object.entries(next).filter(([, value]) => value !== undefined && value !== null && value !== '' && value !== 'all')) as Record<string, string | number>,
            { preserveState: true, preserveScroll: true },
        );
    };

    const updateSearch = (value: string) => {
        setSearch(value);
        if (searchTimer.current) window.clearTimeout(searchTimer.current);
        searchTimer.current = window.setTimeout(() => go({ q: value.trim() || undefined }), 350);
    };

    const open = (next: Popup) => {
        closeMenus();
        setPopup(next);
    };
    const close = () => setPopup(null);
    const consoleFor = (consoleId: number) => consoles.find((item) => item.id === consoleId) ?? null;
    const cardTarget = (person: PersonRow, readerId?: string | null): CardTarget => ({ person, console: consoleFor(person.console_id), readerId: readerId ?? null });
    const doorLog = (doorId: number | null, site?: number | null) => go({ tab: 'activity', door: doorId ?? undefined, person: undefined, site: site ?? filters.site, days: undefined, kind: undefined });
    const personLog = (personId: number) => go({ tab: 'activity', person: personId, door: undefined, kind: undefined, days: '90' });

    /* -------------------------------- menus -------------------------------- */

    const doorActions = (door: DoorCard): MenuItem[] => [
        ...(door.href ? [{ label: 'Open the door’s page', icon: ExternalLink, onClick: () => router.visit(door.href!) }] : []),
        { label: 'Door log', icon: History, onClick: () => doorLog(door.id, door.site_id) },
        { label: 'Who can open it', icon: Users, onClick: () => go({ tab: 'people', site: door.site_id, status: undefined }) },
    ];

    const personActions = (person: PersonRow): MenuItem[] => {
        const items: MenuItem[] = [];
        if (can.manage) {
            items.push({ label: person.kind === 'resident' ? 'Add a fob at a reader' : 'Add a card at a reader', icon: Nfc, onClick: () => open({ kind: 'card', target: cardTarget(person) }) });
            for (const card of person.cards) {
                items.push({
                    label: `Turn off ${person.kind === 'resident' ? 'fob' : 'card'} ${card.display}`,
                    icon: CreditCard,
                    danger: true,
                    onClick: () => open({ kind: 'card-off', person, card }),
                });
            }
            items.push({ label: 'Change doors', icon: DoorOpen, onClick: () => open({ kind: 'doors', person }) });
            items.push(
                person.status === 'deactivated'
                    ? { label: 'Turn back on', icon: UserRoundCheck, onClick: () => open({ kind: 'on-off', person }) }
                    : { label: 'Turn off', icon: UserRoundX, danger: true, onClick: () => open({ kind: 'on-off', person }) },
            );
            if (person.kind === 'unmatched') items.push({ label: 'Match to staff', icon: LinkIcon, onClick: () => open({ kind: 'match', people: [person] }) });
            if (person.kind === 'staff') items.push({ label: 'Unmatch', icon: Unlink, onClick: () => open({ kind: 'unmatch', person }) });
        }
        if (person.can_log) items.push({ label: 'Their door log', icon: History, onClick: () => personLog(person.id) });
        if (items.length === 0) items.push({ label: 'Doors at this Site', icon: DoorOpen, onClick: () => go({ tab: 'doors', site: person.site_id }) });
        return items;
    };

    const visitorActions = (visitor: VisitorRow): MenuItem[] => [
        ...(can.manage && visitor.can_cancel ? [{ label: 'Cancel pass', icon: XCircle, danger: true, onClick: () => open({ kind: 'cancel-visitor', visitor }) }] : []),
        ...(visitor.site_id ? [{ label: `Doors at ${visitor.site}`, icon: DoorOpen, onClick: () => go({ tab: 'doors', site: visitor.site_id! }) }] : []),
    ];

    const groupActions = (group: ScheduleRow): MenuItem[] =>
        group.site_id
            ? [
                  { label: `Doors at ${group.site}`, icon: DoorOpen, onClick: () => go({ tab: 'doors', site: group.site_id! }) },
                  { label: `People at ${group.site}`, icon: Users, onClick: () => go({ tab: 'people', site: group.site_id! }) },
              ]
            : [{ label: 'Doors', icon: DoorOpen, onClick: () => go({ tab: 'doors' }) }];

    const eventActions = (event: ActivityRow): MenuItem[] => [
        ...(event.door_id ? [{ label: `Door log for ${event.door}`, icon: History, onClick: () => doorLog(event.door_id) }] : []),
        ...(event.who.named && event.who.person_id ? [{ label: `${event.who.text}’s door log`, icon: History, onClick: () => personLog(event.who.person_id!) }] : []),
        ...(activity && (activity.filters.door !== 'all' || activity.filters.person) ? [{ label: 'Every door and person', icon: DoorOpen, onClick: () => go({ door: undefined, person: undefined }) }] : []),
    ];

    /* -------------------------------- header -------------------------------- */

    const lockedPercent = meters.locked.of > 0 ? (meters.locked.count / meters.locked.of) * 100 : 0;
    // Every view keeps the Site filter, so the header keeps its shape between tabs.
    const siteFilter = sites.length > 0 ? <PageHeaderFilterSelect label="All Sites" value={filters.site} options={sites} onChange={(value) => go({ site: value })} /> : null;
    const tabFilters = {
        doors: <PageHeaderFilterSelect label="Any state" value={filters.state} options={DOOR_STATES} onChange={(value) => go({ state: value })} />,
        people: <PageHeaderFilterSelect label="Everyone" value={filters.status} options={PEOPLE_STATUSES} onChange={(value) => go({ status: value })} />,
        visitors: <PageHeaderFilterSelect label="Any time" value={filters.when} options={VISITOR_WHEN} onChange={(value) => go({ when: value })} />,
        schedules: null,
        activity: activity ? (
            <>
                <PageHeaderFilterSelect label="All doors" value={activity.filters.door} options={activity.doors} onChange={(value) => go({ door: value })} />
                <PageHeaderFilterSelect label="Anything" value={activity.filters.kind} options={EVENT_KINDS} onChange={(value) => go({ kind: value })} />
                <PageHeaderFilterSelect label="Today" allValue="today" value={activity.filters.days} options={EVENT_DAYS} onChange={(value) => go({ days: value === 'today' ? undefined : value })} />
            </>
        ) : null,
    }[tab];

    const header = (
        <PageHeader
            icon={KeyRound}
            title="Access"
            subline="Who can open which doors, their cards and visitors · UniFi Access"
            actions={
                <>
                    <PageHeaderSearch value={search} onChange={updateSearch} placeholder="Search people or doors" />
                    {tab === 'doors' && can.roll_call ? (
                        <PageHeaderGlassButton icon={ListChecks} onClick={() => open({ kind: 'roll', siteId: siteId !== null ? String(siteId) : null })}>
                            Roll call
                        </PageHeaderGlassButton>
                    ) : null}
                    {can.manage && consoles.length > 0 ? (
                        <PageHeaderGlassButton icon={Ticket} onClick={() => open({ kind: 'visitor' })}>
                            Add visitor pass
                        </PageHeaderGlassButton>
                    ) : null}
                    {can.manage && consoles.length > 0 ? (
                        <PageHeaderPrimaryButton icon={Plus} onClick={() => open({ kind: 'give' })}>
                            Give someone access
                        </PageHeaderPrimaryButton>
                    ) : null}
                </>
            }
            meters={
                <>
                    <PageHeaderMeterBlock
                        label="Doors locked"
                        tone={meters.locked.of > 0 && meters.locked.count < meters.locked.of ? 'warning' : 'brand'}
                        onClick={() => go({ tab: 'doors', state: meters.locked.count < meters.locked.of ? 'unlocked' : undefined })}
                        ariaLabel="Show unlocked doors"
                    >
                        <PageHeaderMeterBig>{meters.locked.of > 0 ? `${meters.locked.count} of ${meters.locked.of}` : 'Not available'}</PageHeaderMeterBig>
                        {meters.locked.of > 0 ? <PageHeaderMeterBar percent={lockedPercent} /> : null}
                        <PageHeaderMeterCaption>{meters.locked.caption}</PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                    <PageHeaderMeterBlock
                        label="Held open today"
                        tone={meters.held_open.count > 0 ? 'warning' : 'brand'}
                        onClick={() => go({ tab: 'activity', kind: 'held_open', door: undefined, person: undefined, days: undefined })}
                        ariaLabel="Show doors held open today"
                    >
                        <PageHeaderMeterBig>{meters.held_open.count}</PageHeaderMeterBig>
                        <PageHeaderMeterCaption>{lineText(meters.held_open.caption, now)}</PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                    <PageHeaderMeterBlock label="Cards turned off" onClick={() => go({ tab: 'people', status: undefined })} ariaLabel="Show people, lost cards first">
                        <PageHeaderMeterBig>{meters.turned_off.count}</PageHeaderMeterBig>
                        <PageHeaderMeterCaption>{meters.turned_off.caption}</PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                    <PageHeaderMeterBlock
                        label="People to match"
                        tone={meters.to_match.count > 0 ? 'warning' : 'brand'}
                        onClick={() => go({ tab: 'people', status: 'to_match' })}
                        ariaLabel="Show people to match"
                    >
                        <PageHeaderMeterBig>{meters.to_match.count}</PageHeaderMeterBig>
                        <PageHeaderMeterCaption>{meters.to_match.caption}</PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                </>
            }
            filters={
                siteFilter || tabFilters ? (
                    <>
                        {siteFilter}
                        {tabFilters}
                    </>
                ) : undefined
            }
            rail={
                <PageHeaderRail<AccessTab>
                    ariaLabel="Access views"
                    items={(Object.keys(TAB_LABEL) as AccessTab[]).map((key) => ({
                        key,
                        label: TAB_LABEL[key],
                        count: key === 'doors' ? counts.doors : key === 'people' ? counts.people : key === 'visitors' ? counts.visitors : undefined,
                    }))}
                    value={tab}
                    onSelect={(key) => go({ tab: key })}
                />
            }
        />
    );

    /* -------------------------------- body -------------------------------- */

    const searching = Boolean(filters.q);
    let body: ReactNode = null;
    if (consoles.length === 0) {
        body = (
            <section className="rounded-[14px] border border-border bg-card px-5 py-6 shadow-sm">
                <h2 className="text-section-title">No UniFi Access at your Sites yet</h2>
                <p className="text-subtle mt-1">When a Site’s UniFi Access is connected in Setup › Doors & access, its doors, people and door log show here.</p>
            </section>
        );
    } else if (tab === 'doors' && props.doors) {
        body = (
            <DoorsView
                data={props.doors}
                actionsFor={doorActions}
                onOpen={(door) => (door.href ? router.visit(door.href) : doorLog(door.id, door.site_id))}
                onContextMenu={(event, door) => doorMenu.open(event, door)}
                onRollCall={(site) => open({ kind: 'roll', siteId: String(site) })}
                canRollCall={can.roll_call}
                filtered={searching || filters.state !== 'all' || filters.site !== 'all'}
                now={now}
            />
        );
    } else if (tab === 'people' && props.people) {
        body = (
            <PeopleView
                data={props.people}
                canManage={can.manage}
                filtered={searching || filters.status !== 'all' || filters.site !== 'all'}
                actionsFor={personActions}
                onOpen={(person) => {
                    if (person.kind === 'unmatched' && can.manage) open({ kind: 'match', people: [person] });
                    else if (person.can_log) personLog(person.id);
                }}
                onContextMenu={(event, person) => personMenu.open(event, person)}
                onMatch={() => props.people && open({ kind: 'match', people: props.people.to_match.people })}
                onPage={(page) => go({ page })}
                now={now}
            />
        );
    } else if (tab === 'visitors' && props.visitors) {
        body = (
            <VisitorsView
                rows={props.visitors.rows}
                total={props.visitors.total}
                canManage={can.manage}
                filtered={searching || filters.when !== 'all' || filters.site !== 'all'}
                actionsFor={visitorActions}
                onContextMenu={(event, visitor) => visitorMenu.open(event, visitor)}
                onAdd={() => open({ kind: 'visitor' })}
                now={now}
            />
        );
    } else if (tab === 'schedules' && props.schedules) {
        body = (
            <SchedulesView
                rows={props.schedules.rows}
                filtered={searching || filters.site !== 'all'}
                actionsFor={groupActions}
                onContextMenu={(event, group) => groupMenu.open(event, group)}
            />
        );
    } else if (tab === 'activity' && activity) {
        body = (
            <ActivityView
                data={activity}
                filtered={searching || activity.filters.door !== 'all' || activity.filters.kind !== 'all' || activity.filters.days !== 'today' || Boolean(activity.filters.person)}
                actionsFor={eventActions}
                onContextMenu={(event, row) => eventMenu.open(event, row)}
                onPage={(page) => go({ page })}
                now={now}
            />
        );
    }

    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Home', href: '/dashboard' },
                { title: 'Security & devices', href: '/security-devices' },
                { title: 'Access', href: BASE },
            ]}
        >
            <Head title="Access" />
            <PageLayout hero={header}>{body}</PageLayout>

            {doorMenu.ctx ? <EntityContextMenu x={doorMenu.ctx.x} y={doorMenu.ctx.y} icon={DoorOpen} title={doorMenu.ctx.record.name} items={doorActions(doorMenu.ctx.record)} onClose={doorMenu.close} /> : null}
            {personMenu.ctx ? <EntityContextMenu x={personMenu.ctx.x} y={personMenu.ctx.y} icon={Users} title={personMenu.ctx.record.name} items={personActions(personMenu.ctx.record)} onClose={personMenu.close} /> : null}
            {visitorMenu.ctx ? <EntityContextMenu x={visitorMenu.ctx.x} y={visitorMenu.ctx.y} icon={Ticket} title={visitorMenu.ctx.record.name} items={visitorActions(visitorMenu.ctx.record)} onClose={visitorMenu.close} /> : null}
            {groupMenu.ctx ? <EntityContextMenu x={groupMenu.ctx.x} y={groupMenu.ctx.y} icon={Users} title={groupMenu.ctx.record.name} items={groupActions(groupMenu.ctx.record)} onClose={groupMenu.close} /> : null}
            {eventMenu.ctx ? <EntityContextMenu x={eventMenu.ctx.x} y={eventMenu.ctx.y} icon={History} title={eventMenu.ctx.record.door} items={eventActions(eventMenu.ctx.record)} onClose={eventMenu.close} /> : null}

            {can.manage ? (
                <>
                    <GiveAccess
                        open={popup?.kind === 'give'}
                        consoles={consoles}
                        staff={props.staff}
                        initialSiteId={siteId}
                        onClose={close}
                        onVisitorPass={() => setPopup({ kind: 'visitor' })}
                        onAddCard={(added) => {
                            const target = consoleFor(added.consoleId);
                            setPopup({ kind: 'card', target: { person: { id: added.personId, name: added.name, site: target?.site ?? 'the Site', doors: 'their doors' }, console: target, readerId: added.readerId } });
                        }}
                        onSeePeople={() => {
                            close();
                            go({ tab: 'people', status: undefined });
                        }}
                    />
                    <AddCard open={popup?.kind === 'card'} target={popup?.kind === 'card' ? popup.target : null} onClose={close} />
                    <TurnOffCard
                        open={popup?.kind === 'card-off'}
                        person={popup?.kind === 'card-off' ? popup.person : null}
                        card={popup?.kind === 'card-off' ? popup.card : null}
                        canAddCard={can.manage}
                        onClose={close}
                        onAddCardNext={(person) => setPopup({ kind: 'card', target: cardTarget(person) })}
                    />
                    <PersonOnOff open={popup?.kind === 'on-off'} person={popup?.kind === 'on-off' ? popup.person : null} onClose={close} />
                    <ChangeDoors
                        open={popup?.kind === 'doors'}
                        person={popup?.kind === 'doors' ? popup.person : null}
                        console={popup?.kind === 'doors' ? consoleFor(popup.person.console_id) : null}
                        onClose={close}
                    />
                    <MatchPeople open={popup?.kind === 'match'} people={popup?.kind === 'match' ? popup.people : []} staff={props.staff} onClose={close} />
                    <UndoMatch open={popup?.kind === 'unmatch'} person={popup?.kind === 'unmatch' ? popup.person : null} onClose={close} />
                    <AddVisitorPass open={popup?.kind === 'visitor'} consoles={consoles} initialSiteId={siteId} onClose={close} />
                    <CancelVisitorPass open={popup?.kind === 'cancel-visitor'} visitor={popup?.kind === 'cancel-visitor' ? popup.visitor : null} onClose={close} />
                </>
            ) : null}
            {can.roll_call ? <RollCall open={popup?.kind === 'roll'} sites={sites} initialSiteId={popup?.kind === 'roll' ? popup.siteId : null} onClose={close} /> : null}
        </AppLayout>
    );
}
