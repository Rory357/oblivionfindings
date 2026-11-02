/**
 * Pop-up 97, "Obli checks <Site>'s network" (S&D Phase I): IT asks Obli about a Site's network;
 * Obli plans a few read-only checks, the hub that watches the Site runs them, and Obli explains
 * what it found. A Large (900) viewer on the pop-up kit: the chat, then "What Obli checked".
 *
 * Opened from Site › Network map › Ask Obli and from a Wi-Fi point's page. Every finding under
 * "What Obli checked" is written by Oblivion from what really came back; a check that couldn't
 * be done says "Not available: <why>". When Obli's AI is off or the hub can't run checks, the
 * pop-up says so instead of asking.
 */
import {
    PopupCallout,
    PopupField,
    PopupSection,
    PopupViewer,
} from '@/components/popup';
import { Ring, type SgTone } from '@/components/signature/blocks';
import { Button } from '@/components/ui/button';
import { StatusBadge } from '@/components/ui/status-badge';
import { Textarea } from '@/components/ui/textarea';
import { formatDateTime } from '@/lib/datetime';
import { durationWords, seenTime } from '@/lib/security-devices/device-time';
import {
    askObli,
    boldParts,
    followSession,
    loadPanel,
    networkCheckError,
    raiseItJob,
    type NetworkCheckPanel,
    type NetworkCheckSession,
    type NetworkCheckStepState,
} from '@/lib/security-devices/network-checks';
import { cn } from '@/lib/utils';
import { Link } from '@inertiajs/react';
import { Bot, LoaderCircle, Wifi } from 'lucide-react';
import { useEffect, useState, type ReactNode } from 'react';

const POLL_MS = 2000;

const STEP_RING: Record<NetworkCheckStepState, SgTone> = {
    done: 'success',
    not_available: 'warning',
    sent: 'info',
    queued: 'none',
};

const STEP_WORDS: Record<NetworkCheckStepState, string> = {
    done: 'Done',
    not_available: 'Not available',
    sent: 'Checking',
    queued: 'Waiting',
};

function Bubble({
    who,
    name,
    time,
    children,
}: {
    who: 'obli' | 'person';
    name: string;
    time: string | null;
    children: ReactNode;
}) {
    return (
        <li
            className={cn(
                'flex gap-2.5',
                who === 'person' && 'flex-row-reverse',
            )}
        >
            {who === 'obli' ? (
                <span
                    className="popup-lens popup-lens--sm mt-0.5 shrink-0"
                    aria-hidden="true"
                >
                    <Bot className="size-[15px]" />
                </span>
            ) : null}
            <div
                className={cn(
                    'grid max-w-[78%] min-w-0 gap-1.5 rounded-[12px] px-3.5 py-2.5 text-sm text-foreground',
                    who === 'obli' ? 'bg-muted' : 'bg-primary-fill/10',
                )}
            >
                <p className="text-caption">
                    {name}
                    {time ? ` · ${time}` : ''}
                </p>
                {children}
            </div>
        </li>
    );
}

/** A plain status line while Obli or the hub works (no spinner: DESIGN.md "Generic spinners"). */
function Waiting({ children }: { children: ReactNode }) {
    return (
        <li className="text-subtle flex items-center gap-2" role="status">
            <Ring tone="info" />
            {children}
        </li>
    );
}

export function NetworkCheckPopup({
    open,
    siteId,
    siteName,
    device,
    sessionId,
    onClose,
}: {
    open: boolean;
    siteId: number;
    siteName: string;
    /** The Wi-Fi point it was opened from, if any. */
    device?: { id: number; name: string } | null;
    /** A session to show (the link on an IT job), instead of the Site's running one. */
    sessionId?: string | null;
    onClose: () => void;
}) {
    const [panel, setPanel] = useState<NetworkCheckPanel | null>(null);
    const [session, setSession] = useState<NetworkCheckSession | null>(null);
    const [loadError, setLoadError] = useState<string | null>(null);
    const [reload, setReload] = useState(0);
    const [question, setQuestion] = useState('');
    const [asking, setAsking] = useState(false);
    const [askError, setAskError] = useState<string | undefined>();
    const [raising, setRaising] = useState(false);
    const [ticketError, setTicketError] = useState<string | null>(null);
    const [now, setNow] = useState(() => new Date());

    useEffect(() => {
        if (!open) return;
        let gone = false;
        setPanel(null);
        setSession(null);
        setLoadError(null);
        setAskError(undefined);
        setTicketError(null);
        setQuestion('');
        loadPanel(siteId, sessionId)
            .then((data) => {
                if (gone) return;
                setPanel(data);
                setSession(data.session);
            })
            .catch(
                (failure) => !gone && setLoadError(networkCheckError(failure)),
            );
        return () => {
            gone = true;
        };
    }, [open, siteId, sessionId, reload]);

    // While Obli works, follow the session; nothing is asked of the hub from here.
    const uuid = session?.uuid ?? null;
    const following = open && !!session?.open;
    useEffect(() => {
        if (!following || !uuid) return;
        let gone = false;
        const timer = window.setTimeout(() => {
            followSession(uuid)
                .then((next) => !gone && setSession(next))
                .catch(
                    () =>
                        !gone &&
                        setSession((current) =>
                            current ? { ...current } : current,
                        ),
                );
        }, POLL_MS);
        return () => {
            gone = true;
            window.clearTimeout(timer);
        };
    }, [following, uuid, session]);

    useEffect(() => {
        if (!open) return;
        const timer = window.setInterval(() => setNow(new Date()), 30_000);
        return () => window.clearInterval(timer);
    }, [open]);

    const ask = async () => {
        setAsking(true);
        setAskError(undefined);
        try {
            setSession(
                await askObli(siteId, question.trim(), device?.id ?? null),
            );
        } catch (failure) {
            setAskError(networkCheckError(failure));
        } finally {
            setAsking(false);
        }
    };

    const raise = async () => {
        if (!session) return;
        setRaising(true);
        setTicketError(null);
        try {
            const ticket = await raiseItJob(session.uuid);
            setSession({ ...session, ticket, can_ticket: false });
        } catch (failure) {
            setTicketError(networkCheckError(failure));
        } finally {
            setRaising(false);
        }
    };

    const ready = panel?.ready ?? null;
    const hub = session?.hub ?? ready?.hub ?? null;
    const finished = session !== null && !session.open;

    const description = session ? (
        <>
            {`Asked by ${session.asked_by} · ${session.asked_at ? formatDateTime(session.asked_at) : 'Not available'} · through ${session.hub}`}{' '}
            <StatusBadge
                variant={session.badge.tone}
                label={session.badge.label}
                size="sm"
            />
        </>
    ) : hub ? (
        `Through ${hub}`
    ) : undefined;

    let action: ReactNode = null;
    if (!session && ready?.ok) {
        action = (
            <Button
                type="button"
                onClick={() => void ask()}
                disabled={asking || question.trim().length < 10}
            >
                {asking ? (
                    <LoaderCircle
                        aria-hidden="true"
                        className="size-4 animate-spin motion-reduce:animate-none"
                    />
                ) : (
                    <Bot aria-hidden="true" className="size-4" />
                )}
                {asking ? 'Asking…' : 'Ask Obli'}
            </Button>
        );
    } else if (session && finished) {
        action = (
            <>
                {session.ticket ? (
                    <Button
                        type="button"
                        variant={session.focus ? 'outline' : 'default'}
                        asChild
                    >
                        <Link href={session.ticket.href}>
                            Open IT job {session.ticket.reference}
                        </Link>
                    </Button>
                ) : session.can_ticket ? (
                    <Button
                        type="button"
                        variant={session.focus ? 'outline' : 'default'}
                        onClick={() => void raise()}
                        disabled={raising}
                    >
                        {raising ? 'Raising…' : 'Raise IT job'}
                    </Button>
                ) : null}
                {session.focus ? (
                    <Button type="button" asChild>
                        <Link href={session.focus.href}>
                            Open {session.focus.name}
                        </Link>
                    </Button>
                ) : null}
            </>
        );
    }

    return (
        <PopupViewer
            open={open}
            onClose={onClose}
            size="lg"
            icon={Bot}
            title={`Obli checks ${siteName}’s network`}
            description={description}
            context={
                device && !session
                    ? {
                          title: device.name,
                          tag: 'Wi-Fi point',
                          note: 'Obli starts with this Wi-Fi point.',
                          icon: Wifi,
                      }
                    : undefined
            }
            callout={{
                tone: 'info',
                content: (
                    <>
                        <b className="font-semibold">Read-only.</b> Obli looks
                        at the network through the hub; it can’t change router,
                        switch or Wi-Fi settings. Those stay with IT, in UniFi.
                    </>
                ),
            }}
            action={action}
            testId="sd-network-check-popup"
        >
            {loadError ? (
                <PopupCallout tone="critical">
                    <span>{loadError} </span>
                    <Button
                        type="button"
                        variant="link"
                        className="h-auto p-0"
                        onClick={() => setReload((value) => value + 1)}
                    >
                        Try again
                    </Button>
                </PopupCallout>
            ) : !panel ? (
                <p className="text-subtle" role="status">
                    Loading…
                </p>
            ) : session ? (
                <SessionView
                    session={session}
                    now={now}
                    raising={raising}
                    ticketError={ticketError}
                    onRaise={() => void raise()}
                />
            ) : !ready?.ok ? (
                <PopupCallout tone="warning">
                    {ready?.reason ?? 'Not available.'}
                </PopupCallout>
            ) : (
                <PopupField
                    label="What’s wrong?"
                    htmlFor="sd-network-check-question"
                    required
                    hint="Say what isn’t working, where and when. Obli picks up to 6 read-only checks and tells you what it found."
                    error={askError}
                >
                    <Textarea
                        id="sd-network-check-question"
                        value={question}
                        onChange={(event) => setQuestion(event.target.value)}
                        placeholder={
                            device
                                ? `e.g. video calls keep dropping near ${device.name} in the evenings`
                                : 'e.g. video calls keep dropping in Room 5 in the evenings'
                        }
                        maxLength={1000}
                        rows={4}
                    />
                </PopupField>
            )}
        </PopupViewer>
    );
}

function SessionView({
    session,
    now,
    raising,
    ticketError,
    onRaise,
}: {
    session: NetworkCheckSession;
    now: Date;
    raising: boolean;
    ticketError: string | null;
    onRaise: () => void;
}) {
    const answeredIn =
        session.asked_at && session.answered_at
            ? ` · answered in ${durationWords(session.asked_at, new Date(session.answered_at))}`
            : '';
    const planned = session.intro !== null || session.plan.length > 0;

    return (
        <>
            <PopupSection
                title={session.title}
                hint={`${session.asked_by} · ${session.asked_at ? seenTime(session.asked_at, now) : ''}${answeredIn}`}
            >
                <ol className="grid gap-3" aria-label="What was said">
                    <Bubble
                        who="person"
                        name={session.asked_by}
                        time={
                            session.asked_at
                                ? seenTime(session.asked_at, now)
                                : null
                        }
                    >
                        <p className="whitespace-pre-line">
                            {session.question}
                        </p>
                    </Bubble>
                    {planned ? (
                        <Bubble
                            who="obli"
                            name="Obli"
                            time={
                                session.planned_at
                                    ? seenTime(session.planned_at, now)
                                    : null
                            }
                        >
                            {session.intro ? <p>{session.intro}</p> : null}
                            {session.plan.length > 0 ? (
                                <ol className="list-decimal space-y-0.5 pl-5">
                                    {session.plan.map((line, index) => (
                                        <li key={index}>{line}</li>
                                    ))}
                                </ol>
                            ) : null}
                        </Bubble>
                    ) : session.state === 'planning' ? (
                        <Waiting>Obli is choosing which checks to run…</Waiting>
                    ) : null}
                    {session.answer ? (
                        <Bubble
                            who="obli"
                            name="Obli"
                            time={
                                session.answered_at
                                    ? seenTime(session.answered_at, now)
                                    : null
                            }
                        >
                            <p className="whitespace-pre-line">
                                {boldParts(session.answer).map((part, index) =>
                                    part.bold ? (
                                        <b
                                            key={index}
                                            className="font-semibold"
                                        >
                                            {part.text}
                                        </b>
                                    ) : (
                                        <span key={index}>{part.text}</span>
                                    ),
                                )}
                            </p>
                            {session.unchecked_numbers ? (
                                <p className="text-caption">
                                    Some numbers here weren’t in what the hub
                                    reported. Check them against “What Obli
                                    checked”.
                                </p>
                            ) : null}
                            {session.can_ticket || session.unifi_href ? (
                                <div className="flex flex-wrap gap-2 pt-0.5">
                                    {session.can_ticket ? (
                                        <Button
                                            type="button"
                                            size="sm"
                                            onClick={onRaise}
                                            disabled={raising}
                                        >
                                            {raising
                                                ? 'Raising…'
                                                : 'Raise IT job'}
                                        </Button>
                                    ) : null}
                                    {session.unifi_href ? (
                                        <Button
                                            type="button"
                                            size="sm"
                                            variant="outline"
                                            asChild
                                        >
                                            <a
                                                href={session.unifi_href}
                                                target="_blank"
                                                rel="noopener noreferrer"
                                            >
                                                Open in UniFi
                                            </a>
                                        </Button>
                                    ) : null}
                                </div>
                            ) : null}
                            {ticketError ? (
                                <p className="text-caption text-status-critical">
                                    {ticketError}
                                </p>
                            ) : null}
                        </Bubble>
                    ) : session.state === 'checking' ? (
                        <Waiting>The hub is running the checks…</Waiting>
                    ) : session.state === 'answering' ? (
                        <Waiting>Obli is writing up what it found…</Waiting>
                    ) : null}
                </ol>
            </PopupSection>
            {session.failure ? (
                <PopupCallout tone="warning">{session.failure}</PopupCallout>
            ) : null}
            {session.steps.length > 0 ? (
                <PopupSection
                    title="What Obli checked"
                    hint={
                        session.hub_at_site
                            ? `Through ${session.hub}, the hub at ${session.site.name}`
                            : `Through ${session.hub}, which watches ${session.site.name}`
                    }
                >
                    <ol
                        className="grid divide-y divide-border"
                        aria-label="What Obli checked"
                    >
                        {session.steps.map((step) => (
                            <li
                                key={step.key}
                                className="grid grid-cols-[auto_minmax(0,1fr)_auto] items-start gap-3 py-2.5"
                            >
                                <span className="mt-0.5">
                                    <Ring
                                        tone={STEP_RING[step.state]}
                                        label={STEP_WORDS[step.state]}
                                    />
                                </span>
                                <div className="min-w-0">
                                    <b className="text-sm font-semibold">
                                        {step.title}
                                    </b>
                                    <p className="text-caption">
                                        {step.finding ??
                                            (step.state === 'sent'
                                                ? 'The hub is checking…'
                                                : 'Waiting for the hub')}
                                    </p>
                                </div>
                                <time className="text-caption tabular-nums">
                                    {step.at ? seenTime(step.at, now) : ''}
                                </time>
                            </li>
                        ))}
                    </ol>
                    <p className="text-caption">{session.note}</p>
                </PopupSection>
            ) : null}
        </>
    );
}
