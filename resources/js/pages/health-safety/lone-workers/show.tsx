/**
 * One safety session (approved H&S drawings pg-hsn-82, 82b–82f — Stephan 23
 * Oct 2026): one band on every section shows the session's line — started,
 * check-ins received, where it is now, due to end — with the next step on top
 * and Record check-in / Request help beside it. While help is requested the
 * band follows the help request instead (requested › sent › responder ›
 * acknowledged › resolved). The command forms and their submissions are
 * unchanged; they open in the main column as before.
 */
import { type SharedData } from '@/types';
import { safetyHubCrumbs } from '@/lib/health-safety/workspace-navigation';
import {
    SESSION_COMMAND_LABELS,
    SESSION_COMMAND_SECTIONS,
    sessionCommandAllowed,
    SessionCommandForm,
} from '@/components/health-safety/lone-workers/session-command-form';
import {
    deliveryLabel,
    observationLabel,
    responseStatusLabel,
    sessionClock,
    SessionCheckIns,
    SessionConnections,
    SessionHistory,
    SessionLocation,
    SessionMonitorRing,
    SessionOverview,
    SessionPeople,
    SessionResponse,
    sessionStarter,
    SessionTrackerRing,
} from '@/components/health-safety/lone-workers/session-panels';
import type {
    LinkedRecord,
    SafetySession,
    SessionCommand,
    SessionPickers,
    SessionSection,
} from '@/components/health-safety/lone-workers/types';
import { dateWithYear } from '@/components/incidents/incident-stages';
import {
    PageHeader,
    PageHeaderGlassButton,
    PageHeaderPrimaryButton,
    PageHeaderRail,
    PageHeaderStatusChip,
    PageLayout,
} from '@/components/page';
import {
    PageHeaderFacts,
    PageHeaderStages,
    type PageHeaderFact,
    type PageHeaderNextStep,
    type PageHeaderStage,
} from '@/components/page/page-header-strip';
import { Note, SideColumn, Surface } from '@/components/signature/blocks';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import {
    SafetyFormDraftContext,
    useSafetyWorkspaceDrafts,
} from '@/hooks/use-safety-workspace-drafts';
import AppLayout from '@/layouts/app-layout';
import {
    LONE_WORKER_REGISTER,
    loneWorkerHref,
    loneWorkerReturn,
} from '@/lib/health-safety/lone-worker-navigation';
import { Head, Link, router, usePage } from '@inertiajs/react';
import {
    BellRing,
    CheckCircle2,
    Clock,
    Info,
    MapPin,
    MoreHorizontal,
    Pencil,
    Plus,
    Radio,
    Siren,
    Square,
    Trash2,
    type LucideIcon,
} from 'lucide-react';
import { useEffect, useRef, useState } from 'react';

const SECTIONS = [
    { key: 'overview', label: 'Overview' },
    { key: 'check-ins', label: 'Check-ins' },
    { key: 'response', label: 'Response' },
    { key: 'location', label: 'Location' },
    { key: 'history', label: 'History' },
    { key: 'connections', label: 'Linked records' },
] as const;
const COMMAND_ICONS: Record<SessionCommand, LucideIcon> = {
    edit: Pencil,
    'check-in': Plus,
    end: Square,
    panic: Siren,
    locate: MapPin,
    'acknowledge-panic': BellRing,
    remove: Trash2,
};
/** Commands the drawing draws in the header; the rest stay under More. */
const HEADER_GLASS: SessionCommand[] = ['panic', 'end'];
const MORE: SessionCommand[] = ['edit', 'locate', 'acknowledge-panic', 'remove'];

export default function LoneWorkerShow({
    detail: d,
    pickers,
    return_to: incomingReturnTo,
    section: initial = 'overview',
}: {
    detail: SafetySession;
    pickers: SessionPickers;
    return_to?: string | null;
    section?: SessionSection;
}) {
    const return_to = loneWorkerReturn(incomingReturnTo);
    const { url } = usePage();
    const safetyCan = usePage<SharedData>().props.auth.can;
    const [section, setSection] = useState<SessionSection>(initial);
    const [command, setCommand] = useState<SessionCommand | null>(null);
    const drafts = useSafetyWorkspaceDrafts();
    const panel = useRef<HTMLDivElement>(null);
    const interacted = useRef(false);
    useEffect(() => {
        const params = new URL(url, 'https://session.invalid').searchParams;
        const key = params.get('section');
        setSection(
            SECTIONS.some((item) => item.key === key)
                ? (key as SessionSection)
                : 'overview',
        );
        const action = params.get('action');
        setCommand(
            action && Object.hasOwn(SESSION_COMMAND_LABELS, action)
                ? (action as SessionCommand)
                : null,
        );
    }, [url]);
    useEffect(() => {
        if (interacted.current) panel.current?.focus();
    }, [section, command]);
    const href = (next: SessionSection, action: SessionCommand | null = null) =>
        loneWorkerHref(d.id, {
            section: next,
            command: action,
            returnTo: return_to,
        });
    const replace = (
        next: SessionSection,
        action: SessionCommand | null = null,
    ) => {
        interacted.current = true;
        router.replace({
            url: href(next, action),
            preserveState: true,
            preserveScroll: true,
            onSuccess: () => {
                setSection(next);
                setCommand(action);
            },
        });
    };
    const move = (next: SessionSection, action: SessionCommand | null = null) =>
        drafts.request(() => replace(next, action));
    const run = (action: SessionCommand) =>
        move(SESSION_COMMAND_SECTIONS[action], action);

    const emergency = d.status === 'emergency';
    const ended = !!d.ended_at || d.status === 'completed';
    const overdue = !ended && !emergency && d.is_check_in_overdue;
    const status = emergency
        ? 'Help requested'
        : d.status === 'completed'
          ? 'Ended'
          : d.is_check_in_overdue
            ? 'Check-in overdue'
            : 'Active';
    const worker = d.worker?.name ?? null;
    const base = d.started_at;
    const response: LinkedRecord | null = d.connections.control_room[0] ?? null;
    const delivery = d.source_delivery;

    /* ---- header actions: Record check-in first, Request help and End session beside it, the rest under More ---- */
    const allowed = (Object.keys(SESSION_COMMAND_LABELS) as SessionCommand[])
        .filter((action) => sessionCommandAllowed(d, action));
    const continueResponse = emergency && response ? response : null;
    const primaryCommand: SessionCommand | null =
        !continueResponse && allowed.includes('check-in') ? 'check-in' : null;
    const glass = [
        ...HEADER_GLASS.filter((action) => allowed.includes(action)),
        ...(continueResponse && allowed.includes('check-in')
            ? (['check-in'] as SessionCommand[])
            : []),
    ];
    const more = MORE.filter((action) => allowed.includes(action));

    /* ---- facts ---- */
    const facts: PageHeaderFact[] = [
        ...(!ended
            ? [
                  {
                      value: sessionClock(
                          d.next_check_in_at,
                          base,
                          'Not known',
                      ),
                      label: 'Next check-in',
                      tone: overdue ? ('critical' as const) : undefined,
                  },
              ]
            : []),
        {
            value: d.observation.real_check_in_at
                ? sessionClock(d.observation.real_check_in_at, base)
                : observationLabel('no_check_in'),
            label: 'Last check-in',
        },
        ...(d.emergency_triggered_at || delivery
            ? [
                  {
                      value: deliveryLabel(delivery),
                      label: 'Help request',
                      tone: emergency ? ('warning' as const) : undefined,
                  },
              ]
            : []),
    ];

    /* ---- the stage line and the next step ---- */
    const received = d.check_ins.length;
    const allOk =
        received > 0 && d.check_ins.every((check) => check.status === 'ok');
    const starter = sessionStarter(d);
    const startedMeta = [base ? sessionClock(base, base) : null, starter]
        .filter(Boolean)
        .join(' · ');
    const checkInsMeta = received
        ? `${received} received${allOk ? ', all OK' : ''}`
        : 'None received';
    const interval = `Check-ins every ${d.check_in_interval_minutes} minutes`;
    const canCheckIn = allowed.includes('check-in');
    const canPanic = allowed.includes('panic');
    const doNext = [
        canCheckIn ? 'Record a check-in' : null,
        canPanic ? (canCheckIn ? 'or ask for help' : 'Ask for help') : null,
    ]
        .filter(Boolean)
        .join(' ');

    let steps: PageHeaderStage[];
    let next: PageHeaderNextStep | null = null;
    if (emergency) {
        const assigned = response
            ? typeof response.assigned_to === 'object'
                ? (response.assigned_to?.name ?? null)
                : (response.assigned_to ?? null)
            : null;
        const sent = delivery?.status === 'delivered';
        const reached = [
            true,
            sent,
            !!assigned,
            !!response?.acknowledged_at,
            !!response?.resolved_at,
        ];
        const nowAt = reached.indexOf(false);
        const state = (index: number): PageHeaderStage['state'] =>
            reached[index] && (nowAt === -1 || index < nowAt)
                ? 'done'
                : index === nowAt
                  ? 'now'
                  : 'todo';
        steps = [
            {
                label: 'Help requested',
                meta: sessionClock(d.emergency_triggered_at, base),
                state: state(0),
            },
            {
                label: 'Sent to control room',
                meta: deliveryLabel(delivery),
                state: state(1),
                tone:
                    delivery?.status === 'failed' ? 'critical' : 'warning',
            },
            ...(response
                ? [
                      {
                          label: 'Responder',
                          meta: assigned ?? 'Needs a responder',
                          state: state(2),
                          tone: 'critical' as const,
                      },
                      {
                          label: 'Acknowledged',
                          meta: response.acknowledged_at
                              ? sessionClock(response.acknowledged_at, base)
                              : 'Not yet',
                          state: state(3),
                          tone: 'warning' as const,
                      },
                      {
                          label: 'Resolved',
                          meta: response.resolved_at
                              ? sessionClock(response.resolved_at, base)
                              : 'Not yet',
                          state: state(4),
                          tone: 'warning' as const,
                      },
                  ]
                : [
                      {
                          label: 'Response',
                          meta: 'You can’t see the response from here',
                          state: sent ? ('now' as const) : ('todo' as const),
                          tone: 'warning' as const,
                      },
                  ]),
        ];
        const reference = response
            ? (response.reference_number ?? 'Control room response')
            : null;
        next = response
            ? {
                  label: 'Next step · now',
                  tone: 'critical',
                  text: !assigned
                      ? 'The control room needs to send someone'
                      : !response.acknowledged_at
                        ? `Waiting for ${assigned} to acknowledge`
                        : 'Waiting for the control room to close it off',
                  meta: [
                      reference,
                      responseStatusLabel(response.status),
                      d.emergency_notes,
                  ]
                      .filter(Boolean)
                      .join(' · '),
              }
            : {
                  label: 'Help requested',
                  tone: 'critical',
                  text: deliveryLabel(delivery),
                  meta: 'You can’t see the control room’s response from here. That doesn’t mean nobody is coming — phone the control room to check.',
              };
    } else if (ended) {
        steps = [
            { label: 'Started', meta: startedMeta, state: 'done' },
            { label: 'Check-ins', meta: checkInsMeta, state: 'done' },
            {
                label: 'Ended',
                meta: sessionClock(d.ended_at, base),
                state: 'done',
            },
        ];
    } else {
        const nextDue = sessionClock(d.next_check_in_at, base, 'Not known');
        steps = [
            { label: 'Started', meta: startedMeta, state: 'done' },
            ...(received
                ? [
                      {
                          label: 'Check-ins',
                          meta: checkInsMeta,
                          state: 'done' as const,
                      },
                  ]
                : []),
            overdue
                ? {
                      label: 'Check-in overdue',
                      meta: `Was due ${nextDue}`,
                      state: 'now',
                      tone: 'critical',
                  }
                : {
                      label: received ? 'Next check-in' : 'First check-in',
                      meta: `Due ${nextDue}`,
                      state: 'now',
                  },
            {
                label: 'Ended',
                meta: `Expected ${sessionClock(d.expected_end_at, base)}`,
                state: 'todo',
            },
        ];
        next = overdue
            ? {
                  label: 'Next step · now',
                  tone: 'critical',
                  text: `${worker ?? 'The worker'} hasn’t checked in`,
                  meta: [observationLabel(d.observation.state), doNext, interval]
                      .filter(Boolean)
                      .join(' · '),
              }
            : {
                  label: `Next step · due ${nextDue}`,
                  text: d.next_check_in_at
                      ? `${worker ?? 'The worker'} checks in by ${nextDue}`
                      : observationLabel(d.observation.state),
                  meta: [interval, `Expected end ${sessionClock(d.expected_end_at, base)}`]
                      .filter(Boolean)
                      .join(' · '),
              };
    }

    const historyCount = d.history.length;
    const main = command ? (
        <Surface aria-label={SESSION_COMMAND_LABELS[command]}>
            <div className="p-5 sm:p-6">
                <SessionCommandForm
                    key={`${d.id}-${command}`}
                    detail={d}
                    pickers={pickers}
                    command={command}
                    returnTo={return_to}
                    onSaved={() => replace(section)}
                    onCancel={() => move(section)}
                />
            </div>
        </Surface>
    ) : section === 'check-ins' ? (
        <SessionCheckIns detail={d} />
    ) : section === 'response' ? (
        <SessionResponse detail={d} />
    ) : section === 'location' ? (
        <SessionLocation detail={d} />
    ) : section === 'history' ? (
        <SessionHistory detail={d} />
    ) : section === 'connections' ? (
        <SessionConnections detail={d} />
    ) : (
        <SessionOverview detail={d} />
    );
    const side = (
        <>
            {section === 'check-ins' && !command ? (
                <SessionMonitorRing detail={d} />
            ) : null}
            {section === 'location' && !command ? (
                <SessionTrackerRing detail={d} />
            ) : null}
            <SessionPeople detail={d} />
        </>
    );

    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Home', href: '/dashboard' },
                ...safetyHubCrumbs('prevention', safetyCan),
                { title: 'Lone-worker safety', href: LONE_WORKER_REGISTER },
                {
                    title: worker
                        ? `Lone-worker session — ${worker}`
                        : d.reference,
                    href: `${LONE_WORKER_REGISTER}/sessions/${d.id}`,
                },
            ]}
        >
            <Head
                title={`${worker ? `Lone-worker session — ${worker}` : `Lone-worker session ${d.reference}`} · Lone-worker safety`}
            />
            <SafetyFormDraftContext.Provider value={drafts.register}>
                <PageLayout
                    hero={
                        <PageHeader
                            variant="profile"
                            wrapTitle
                            backHref={return_to}
                            icon={Radio}
                            title={
                                worker
                                    ? `Lone-worker session — ${worker}`
                                    : `Lone-worker session ${d.reference}`
                            }
                            titleChip={
                                <PageHeaderStatusChip
                                    variant={
                                        emergency
                                            ? 'critical'
                                            : ended
                                              ? 'neutral'
                                              : d.is_check_in_overdue
                                                ? 'warning'
                                                : 'info'
                                    }
                                    icon={
                                        emergency
                                            ? Siren
                                            : ended
                                              ? CheckCircle2
                                              : Clock
                                    }
                                >
                                    {status}
                                </PageHeaderStatusChip>
                            }
                            subline={[
                                d.reference,
                                d.site?.name ?? 'Site not recorded',
                                base ? dateWithYear(base) : null,
                            ]
                                .filter(Boolean)
                                .join(' · ')}
                            actions={
                                glass.length ||
                                more.length ||
                                primaryCommand ||
                                continueResponse ? (
                                    <div
                                        role="group"
                                        aria-label="Safety session actions"
                                        className="contents"
                                    >
                                        {glass.map((action) => (
                                            <PageHeaderGlassButton
                                                key={action}
                                                onClick={() => run(action)}
                                            >
                                                {SESSION_COMMAND_LABELS[action]}
                                            </PageHeaderGlassButton>
                                        ))}
                                        {more.length ? (
                                            <DropdownMenu>
                                                <DropdownMenuTrigger asChild>
                                                    <PageHeaderGlassButton
                                                        icon={MoreHorizontal}
                                                        aria-label="More session actions"
                                                    />
                                                </DropdownMenuTrigger>
                                                <DropdownMenuContent align="end">
                                                    {more.map((action) => {
                                                        const Icon =
                                                            COMMAND_ICONS[
                                                                action
                                                            ];
                                                        return (
                                                            <DropdownMenuItem
                                                                key={action}
                                                                onSelect={() =>
                                                                    run(action)
                                                                }
                                                            >
                                                                <Icon className="size-4" />
                                                                {
                                                                    SESSION_COMMAND_LABELS[
                                                                        action
                                                                    ]
                                                                }
                                                            </DropdownMenuItem>
                                                        );
                                                    })}
                                                </DropdownMenuContent>
                                            </DropdownMenu>
                                        ) : null}
                                        {continueResponse ? (
                                            <PageHeaderPrimaryButton
                                                asChild
                                                icon={Siren}
                                            >
                                                <Link href={continueResponse.href}>
                                                    Open in control room
                                                </Link>
                                            </PageHeaderPrimaryButton>
                                        ) : primaryCommand ? (
                                            <PageHeaderPrimaryButton
                                                icon={Plus}
                                                onClick={() =>
                                                    run(primaryCommand)
                                                }
                                            >
                                                {
                                                    SESSION_COMMAND_LABELS[
                                                        primaryCommand
                                                    ]
                                                }
                                            </PageHeaderPrimaryButton>
                                        ) : null}
                                    </div>
                                ) : undefined
                            }
                            facts={<PageHeaderFacts items={facts} />}
                            strip={
                                <PageHeaderStages
                                    steps={steps}
                                    next={next}
                                    ariaLabel={
                                        emergency
                                            ? 'Help request progress'
                                            : 'Session progress'
                                    }
                                />
                            }
                            rail={
                                <PageHeaderRail<SessionSection>
                                    items={SECTIONS.map((item) => ({
                                        ...item,
                                        count:
                                            item.key === 'check-ins'
                                                ? received || undefined
                                                : item.key === 'history'
                                                  ? historyCount || undefined
                                                  : undefined,
                                    }))}
                                    value={section}
                                    onSelect={(key) => move(key)}
                                    ariaLabel="Safety session sections"
                                />
                            }
                        />
                    }
                >
                    <div className="grid min-w-0 items-start gap-5 xl:grid-cols-[minmax(0,1fr)_minmax(18rem,22rem)]">
                        <div
                            ref={panel}
                            tabIndex={-1}
                            className="grid min-w-0 gap-5 outline-none"
                        >
                            {!command &&
                            !glass.length &&
                            !more.length &&
                            !primaryCommand &&
                            !continueResponse ? (
                                <Note icon={Info}>
                                    {ended
                                        ? 'This session has ended, so nothing more can be recorded on it.'
                                        : 'You can’t change this session. The worker on it, or someone who manages lone-worker safety, can check in, ask for help or end it — ask your team leader if something needs doing.'}
                                </Note>
                            ) : null}
                            {main}
                        </div>
                        <aside
                            className="min-w-0"
                            aria-label="Session people"
                        >
                            <SideColumn>{side}</SideColumn>
                        </aside>
                    </div>
                    {drafts.confirmation}
                </PageLayout>
            </SafetyFormDraftContext.Provider>
        </AppLayout>
    );
}
