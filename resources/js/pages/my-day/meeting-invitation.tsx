/**
 * My day › Meeting invitation (approved H&S drawings pg-hsn-25, 25b): the
 * worker's own invitation — when and where, their recorded response and
 * attendance — and nothing from the committee's register. Details show only
 * after a current server check admits them (useCurrentInvitation, unchanged);
 * opening the page never accepts the invitation or records attendance.
 */
import {
    PageHeader,
    PageHeaderGlassButton,
    PageHeaderStatusChip,
    PageLayout,
} from '@/components/page';
import {
    KnowList,
    Note,
    SideColumn,
    Story,
} from '@/components/signature/blocks';
import { Button } from '@/components/ui/button';
import { EmptyState } from '@/components/ui/empty-state';
import AppLayout from '@/layouts/app-layout';
import { formatDateTimeLong } from '@/lib/datetime';
import { Head, Link, usePage } from '@inertiajs/react';
import {
    Building2,
    CalendarClock,
    Info,
    MapPin,
    RefreshCw,
    ShieldAlert,
    Users,
} from 'lucide-react';
import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { flushSync } from 'react-dom';

type Ref = { id: number; name: string };
export type MeetingInvitation = {
    meeting: {
        id: number;
        scheduled_at: string | null;
        location: string | null;
        status: string;
        committee: Ref;
        site: Ref | null;
        site_ownership: 'recorded' | 'unconfirmed';
        attendance: { response: string; attended: boolean };
    };
    context: { can_open_register: boolean; register_href: string | null };
    source_viewer_id: number;
};
type Shared = {
    auth?: {
        user?: { id?: number } | null;
        can?: { hazards?: { view?: boolean } };
    };
};
type SourceFrame = Pick<
    MeetingInvitation,
    'meeting' | 'context' | 'source_viewer_id'
>;
const positive = (value: unknown): value is number =>
    Number.isSafeInteger(value) && Number(value) > 0;
const textOrNull = (value: unknown) =>
    value === null || typeof value === 'string';
const object = (value: unknown): Record<string, unknown> | null =>
    value !== null && typeof value === 'object' && !Array.isArray(value)
        ? (value as Record<string, unknown>)
        : null;
const ref = (value: unknown) => {
    const row = object(value);
    return !!row && positive(row.id) && typeof row.name === 'string';
};

/** Presentation validation only. Canonical invitation authority remains server-owned. */
function admitted(
    value: unknown,
    actorId: number | null,
    meetingId: number | null,
): MeetingInvitation | null {
    const data = object(value);
    const meeting = object(data?.meeting);
    const context = object(data?.context);
    const attendance = object(meeting?.attendance);
    if (
        !data ||
        !meeting ||
        !context ||
        !attendance ||
        !positive(actorId) ||
        !positive(meetingId) ||
        data.source_viewer_id !== actorId ||
        meeting.id !== meetingId ||
        !ref(meeting.committee) ||
        !textOrNull(meeting.location) ||
        typeof meeting.status !== 'string' ||
        !textOrNull(meeting.scheduled_at) ||
        (typeof meeting.scheduled_at === 'string' &&
            (!/T.*(?:Z|[+-]\d{2}:\d{2})$/.test(meeting.scheduled_at) ||
                !Number.isFinite(Date.parse(meeting.scheduled_at)))) ||
        typeof attendance.response !== 'string' ||
        typeof attendance.attended !== 'boolean' ||
        typeof context.can_open_register !== 'boolean' ||
        !textOrNull(context.register_href) ||
        (meeting.site_ownership !== 'recorded' &&
            meeting.site_ownership !== 'unconfirmed') ||
        (meeting.site_ownership === 'recorded'
            ? !ref(meeting.site)
            : meeting.site !== null)
    )
        return null;
    const registerHref = `/health-safety/worker-participation?tab=meetings&meeting=${meetingId}`;
    if (
        context.can_open_register
            ? context.register_href !== registerHref
            : context.register_href !== null
    )
        return null;
    return value as MeetingInvitation;
}

function useCurrentInvitation(
    sourceFrame: SourceFrame,
    actorId: number | null,
    meetingId: number | null,
) {
    // Server props can be restored from Inertia history. They identify this
    // page; only a current native response may make private details visible.
    const [saved, setSaved] = useState({
        payload: null as MeetingInvitation | null,
        baseline: sourceFrame,
    });
    const [state, setState] = useState<
        'available' | 'checking' | 'unavailable'
    >('checking');
    const controller = useRef<AbortController | null>(null);
    const deadline = useRef<ReturnType<typeof setTimeout> | null>(null);
    const generation = useRef(0);
    const latest = useRef({ sourceFrame, actorId, meetingId });
    latest.current = { sourceFrame, actorId, meetingId };
    const conceal = useCallback(() => {
        generation.current++;
        controller.current?.abort();
        if (deadline.current !== null) clearTimeout(deadline.current);
        deadline.current = null;
        setSaved({ payload: null, baseline: latest.current.sourceFrame });
        setState('checking');
    }, []);
    const refresh = useCallback(async () => {
        if (!positive(actorId) || !positive(meetingId)) return;
        if (document.visibilityState === 'hidden') {
            conceal();
            return;
        }
        controller.current?.abort();
        if (deadline.current !== null) clearTimeout(deadline.current);
        const active = new AbortController();
        controller.current = active;
        const request = ++generation.current;
        const baseline = latest.current;
        setState('checking');
        setSaved({ payload: null, baseline: baseline.sourceFrame });
        const current = () =>
            !active.signal.aborted &&
            document.visibilityState !== 'hidden' &&
            request === generation.current &&
            latest.current.actorId === actorId &&
            latest.current.meetingId === meetingId &&
            latest.current.sourceFrame === baseline.sourceFrame;
        const timer = setTimeout(() => {
            if (current()) {
                setSaved({ payload: null, baseline: baseline.sourceFrame });
                setState('unavailable');
            }
            active.abort();
        }, 15_000);
        deadline.current = timer;
        try {
            const response = await fetch(
                `/my-day/safety/meetings/${meetingId}`,
                {
                    credentials: 'same-origin',
                    cache: 'no-store',
                    headers: { Accept: 'application/json' },
                    signal: active.signal,
                },
            );
            if (!current()) return;
            if (!response.ok) {
                setSaved({ payload: null, baseline: baseline.sourceFrame });
                setState('unavailable');
                return;
            }
            const payload: unknown = await response.json();
            if (!current()) return;
            const next = admitted(payload, actorId, meetingId);
            setSaved({ payload: next, baseline: baseline.sourceFrame });
            setState(next ? 'available' : 'unavailable');
        } catch {
            if (current()) {
                setSaved({ payload: null, baseline: baseline.sourceFrame });
                setState('unavailable');
            }
        } finally {
            clearTimeout(timer);
            if (deadline.current === timer) deadline.current = null;
        }
    }, [actorId, meetingId, conceal]);
    useEffect(() => {
        generation.current++;
        controller.current?.abort();
        if (deadline.current !== null) clearTimeout(deadline.current);
        deadline.current = null;
        setSaved({ payload: null, baseline: sourceFrame });
        if (positive(actorId) && positive(meetingId)) void refresh();
        else setState('unavailable');
        return () => {
            generation.current++;
            controller.current?.abort();
            if (deadline.current !== null) clearTimeout(deadline.current);
            deadline.current = null;
        };
    }, [sourceFrame, actorId, meetingId, refresh]);
    useEffect(() => {
        const focus = () => {
            void refresh();
        };
        const shown = () => {
            void refresh();
        };
        // Clear visible details before a page can be retained for browser Back.
        // This is a page-local presentation guard, not response/history erasure.
        const hidden = () => flushSync(conceal);
        const visible = () => {
            if (document.visibilityState === 'visible') void refresh();
            else hidden();
        };
        window.addEventListener('focus', focus);
        window.addEventListener('pageshow', shown);
        window.addEventListener('pagehide', hidden);
        document.addEventListener('visibilitychange', visible);
        return () => {
            window.removeEventListener('focus', focus);
            window.removeEventListener('pageshow', shown);
            window.removeEventListener('pagehide', hidden);
            document.removeEventListener('visibilitychange', visible);
        };
    }, [refresh, conceal]);
    // Mask synchronously on actor/route/prop changes, before effect cleanup.
    const payload =
        state === 'available' && saved.baseline === sourceFrame
            ? admitted(saved.payload, actorId, meetingId)
            : null;
    return { payload, state, refresh };
}

const STATUSES: Record<string, string> = {
    scheduled: 'Scheduled',
    in_progress: 'In progress',
    completed: 'Completed',
    cancelled: 'Cancelled',
};
const RESPONSES: Record<string, string> = {
    pending: 'Awaiting response',
    invited: 'Invited',
    accepted: 'Accepted',
    declined: 'Declined',
};
const statusLabel = (status: string) =>
    Object.prototype.hasOwnProperty.call(STATUSES, status)
        ? STATUSES[status]
        : 'Status not confirmed';
const responseLabel = (response: string) =>
    Object.prototype.hasOwnProperty.call(RESPONSES, response)
        ? RESPONSES[response]
        : 'Response not confirmed';

export default function MeetingInvitationPage(props: MeetingInvitation) {
    const page = usePage<Shared>();
    const actorId = positive(page.props.auth?.user?.id)
        ? page.props.auth!.user!.id!
        : null;
    const route = /^\/my-day\/safety\/meetings\/([1-9][0-9]*)\/?$/.exec(
        page.url.split('?')[0],
    );
    const meetingId =
        route && positive(Number(route[1])) ? Number(route[1]) : null;
    // Invalid prop frames must retain distinct identities too: collapsing
    // different stale frames to null would skip their required recheck.
    const sourceFrame = useMemo(
        () => ({
            meeting: props.meeting,
            context: props.context,
            source_viewer_id: props.source_viewer_id,
        }),
        [props.meeting, props.context, props.source_viewer_id],
    );
    const { payload, state, refresh } = useCurrentInvitation(
        sourceFrame,
        actorId,
        meetingId,
    );
    const d = payload?.meeting;
    const canOpenRegister =
        payload?.context.can_open_register === true &&
        page.props.auth?.can?.hazards?.view === true;
    const check = (
        <PageHeaderGlassButton
            onClick={() => void refresh()}
            disabled={state === 'checking'}
        >
            <RefreshCw className="size-4" />
            {state === 'checking' ? 'Checking access' : 'Refresh invitation'}
        </PageHeaderGlassButton>
    );
    const response = d ? responseLabel(d.attendance.response) : '';
    const unanswered = d
        ? ['pending', 'invited'].includes(d.attendance.response)
        : false;
    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Home', href: '/dashboard' },
                { title: 'My day', href: '/my-day' },
                {
                    title: 'Meeting invitation',
                    href: meetingId
                        ? `/my-day/safety/meetings/${meetingId}`
                        : '/my-day',
                },
            ]}
        >
            <Head title="Meeting invitation" />
            <PageLayout
                hero={
                    <PageHeader
                        variant="profile"
                        wrapTitle
                        title="Meeting invitation"
                        icon={Users}
                        backHref="/my-day"
                        titleChip={
                            <PageHeaderStatusChip variant="neutral">
                                {d
                                    ? statusLabel(d.status)
                                    : state === 'checking'
                                      ? 'Checking access'
                                      : 'Unavailable'}
                            </PageHeaderStatusChip>
                        }
                        subline={
                            d
                                ? `${d.committee.name} · your invitation`
                                : 'Your meeting invitation'
                        }
                        actions={check}
                    />
                }
            >
                <p
                    className="sr-only"
                    role="status"
                    aria-live="polite"
                    aria-atomic="true"
                >
                    {d
                        ? 'Invitation details available.'
                        : state === 'checking'
                          ? 'Invitation details hidden while access is checked.'
                          : 'Invitation details could not be confirmed.'}
                </p>
                {d ? (
                    <div className="grid min-w-0 items-start gap-5 xl:grid-cols-[minmax(0,1fr)_minmax(18rem,22rem)]">
                        <div
                            id="meeting-details"
                            tabIndex={-1}
                            className="grid min-w-0 gap-5 outline-none"
                        >
                            <Story
                                title={d.committee.name}
                                sub="Meeting details"
                                facts={[
                                    {
                                        icon: CalendarClock,
                                        text: d.scheduled_at
                                            ? `${formatDateTimeLong(d.scheduled_at)} · Pacific/Auckland`
                                            : 'Time not recorded',
                                    },
                                    {
                                        icon: MapPin,
                                        text: d.location || 'Not recorded',
                                    },
                                    {
                                        icon: Building2,
                                        text:
                                            d.site?.name ??
                                            'Site ownership not confirmed',
                                    },
                                ]}
                                paras={[]}
                            />
                            {d.site_ownership === 'unconfirmed' && (
                                <Note icon={Info}>
                                    No Site is recorded. The original Site may
                                    be missing; this does not confirm
                                    organisation-wide ownership.
                                </Note>
                            )}
                        </div>
                        <aside
                            id="your-invitation"
                            tabIndex={-1}
                            aria-label="Your invitation"
                            className="min-w-0 outline-none"
                        >
                            <SideColumn>
                                <KnowList
                                    title="Your invitation"
                                    items={[
                                        {
                                            tone:
                                                d.attendance.response ===
                                                'accepted'
                                                    ? 'success'
                                                    : 'none',
                                            text: (
                                                <>
                                                    <b>{response}</b>
                                                    {unanswered
                                                        ? ' — you haven’t replied yet'
                                                        : ' — your recorded response'}
                                                </>
                                            ),
                                        },
                                        {
                                            tone: d.attendance.attended
                                                ? 'success'
                                                : 'none',
                                            text: (
                                                <>
                                                    <b>Attendance</b> —{' '}
                                                    <span>
                                                        {d.attendance.attended
                                                            ? 'Recorded'
                                                            : 'Not recorded'}
                                                    </span>
                                                </>
                                            ),
                                        },
                                        {
                                            tone: 'info',
                                            text: 'Opening this page does not accept the invitation or confirm that you attended.',
                                        },
                                        {
                                            tone: 'info',
                                            text: 'Minutes, private actions and unshared agenda details are handled in the H&S register.',
                                        },
                                    ]}
                                />
                                <div className="flex flex-wrap gap-3">
                                    <Button variant="outline" asChild>
                                        <Link href="/my-day">
                                            Back to My day
                                        </Link>
                                    </Button>
                                    {canOpenRegister && (
                                        <Button asChild>
                                            <Link
                                                href={
                                                    payload!.context
                                                        .register_href!
                                                }
                                            >
                                                Open H&amp;S meeting
                                            </Link>
                                        </Button>
                                    )}
                                </div>
                            </SideColumn>
                        </aside>
                    </div>
                ) : (
                    <EmptyState
                        icon={ShieldAlert}
                        title={
                            state === 'checking'
                                ? 'Checking invitation access'
                                : 'This invitation is unavailable'
                        }
                        description={
                            state === 'checking'
                                ? 'Meeting details will appear after access is confirmed.'
                                : 'Your account or access may have changed. Return to My day or contact the meeting organiser.'
                        }
                        action={
                            <Button variant="outline" asChild>
                                <Link href="/my-day">Back to My day</Link>
                            </Button>
                        }
                    />
                )}
            </PageLayout>
        </AppLayout>
    );
}
