import {
    BROADCAST_CHANNEL_LABELS,
    BROADCAST_TEMPLATE_LABELS,
    broadcastDeliveryState,
    broadcastReasonWords,
    channelList,
} from '@/components/control-room/broadcast-wizard';
import { ControlRoomPage } from '@/components/control-room/control-room-page';
import { longDuration, useNow } from '@/components/control-room/live-response/response-clock';
import {
    PageHeaderFilterSelect,
    PageHeaderGlassButton,
    PageHeaderMeterBar,
    PageHeaderMeterBig,
    PageHeaderMeterBlock,
    PageHeaderMeterCaption,
    PageHeaderRail,
    PageHeaderStatusChip,
} from '@/components/page';
import { PopupCallout, PopupFacts } from '@/components/popup';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { EmptyState } from '@/components/ui/empty-state';
import { StatusBadge, type StatusVariant } from '@/components/ui/status-badge';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { formatDateTime, formatRelative } from '@/lib/datetime';
import { cn } from '@/lib/utils';
import { router } from '@inertiajs/react';
import {
    Filter,
    History as HistoryIcon,
    Megaphone,
    RefreshCw,
    Send,
    Users,
} from 'lucide-react';
import { useMemo, useState, type ReactNode } from 'react';

interface TargetUser {
    id: number;
    name: string;
    email: string;
}

interface Recipient {
    id: number;
    channel: string;
    status: string;
    status_detail: string | null;
    sent_at: string | null;
    delivered_at: string | null;
    /** In-app only: when they first marked their copy read. */
    read_at?: string | null;
    /** In-app only: sent through the ledger, so a read is recorded. */
    read_tracked?: boolean;
    target_user: TargetUser | null;
}

interface BroadcastSummary {
    broadcast_group_id: string;
    content: string;
    template_used: string | null;
    sent_at: string | null;
    channels: string[];
    total: number;
    delivered: number;
    sent: number;
    pending: number;
    failed: number;
    /** People who read the in-app copy, of those sent it in-app with tracking. */
    read?: number;
    read_of?: number;
}

type HistoryReason = { reason: string; count: number; detail: string | null };

type HistoryEvent =
    | {
          kind: 'sent';
          at: string | null;
          by: string | null;
          channels: string[];
          people: number;
          force_delivery: boolean;
      }
    | {
          kind: 'delivered' | 'failed' | 'skipped';
          channel: string;
          count: number;
          at: string | null;
          last_at: string | null;
          reasons?: HistoryReason[];
      }
    | {
          kind: 'read';
          count: number;
          of: number;
          at: string | null;
          last_at: string | null;
          first_reader: string | null;
      }
    | { kind: 'waiting'; channel: string; count: number; at: string | null };

interface Props {
    summary: BroadcastSummary;
    recipients: Recipient[];
    history?: HistoryEvent[];
}

type RecordTab = 'message' | 'recipients' | 'history';
type Outcome =
    | 'all'
    | 'delivered'
    | 'waiting'
    | 'sent'
    | 'pending'
    | 'failed'
    | 'skipped'
    | 'read'
    | 'unread';

/** "Waiting" = sent but not confirmed, or still queued. Read/unread look at the in-app copy. */
function matchesOutcome(attempt: Recipient, outcome: Outcome) {
    if (outcome === 'all') return true;
    if (outcome === 'waiting') return attempt.status === 'sent' || attempt.status === 'pending';
    if (outcome === 'read') return attempt.channel === 'in_app' && Boolean(attempt.read_at);
    if (outcome === 'unread') {
        return attempt.channel === 'in_app' && Boolean(attempt.read_tracked) && !attempt.read_at;
    }
    return attempt.status === outcome;
}

/** Each channel's own outcome — delivered, sent and queued stay separate. */
const OUTCOME: Record<string, { label: string; tone: StatusVariant }> = {
    delivered: { label: 'Delivered', tone: 'success' },
    sent: { label: 'Sent, not confirmed', tone: 'info' },
    pending: { label: 'Queued', tone: 'warning' },
    failed: { label: 'Not delivered', tone: 'critical' },
    skipped: { label: 'Skipped', tone: 'neutral' },
};

function outcomeOf(status: string) {
    return OUTCOME[status] ?? { label: 'Status unknown', tone: 'neutral' as const };
}

type Person = {
    key: string;
    name: string;
    email: string | null;
    byChannel: Map<string, Recipient>;
};

function groupByPerson(recipients: Recipient[]): Person[] {
    const people = new Map<string, Person>();
    for (const recipient of recipients) {
        const key = recipient.target_user
            ? `user-${recipient.target_user.id}`
            : `record-${recipient.id}`;
        const person = people.get(key) ?? {
            key,
            name: recipient.target_user?.name ?? 'Unknown person',
            email: recipient.target_user?.email ?? null,
            byChannel: new Map<string, Recipient>(),
        };
        person.byChannel.set(recipient.channel, recipient);
        people.set(key, person);
    }
    return [...people.values()].sort((a, b) => a.name.localeCompare(b.name));
}

const TABS: RecordTab[] = ['message', 'recipients', 'history'];

function initialTab(): RecordTab {
    if (typeof window === 'undefined') return 'message';
    const tab = new URLSearchParams(window.location.search).get('tab');
    return TABS.includes(tab as RecordTab) ? (tab as RecordTab) : 'message';
}

/** Keep the open tab in the address so a refresh or a shared link returns to it. */
function rememberTab(tab: RecordTab) {
    const url = new URL(window.location.href);
    if (tab === 'message') url.searchParams.delete('tab');
    else url.searchParams.set('tab', tab);
    window.history.replaceState(window.history.state, '', url.toString());
}

function people(count: number) {
    return `${count} ${count === 1 ? 'person' : 'people'}`;
}

function Panel({
    title,
    description,
    aside,
    children,
}: {
    title: string;
    description?: string;
    aside?: ReactNode;
    children: ReactNode;
}) {
    return (
        <Card className="min-w-0 gap-0 overflow-hidden py-0">
            <div className="flex flex-wrap items-start justify-between gap-3 border-b border-border px-5 py-4">
                <div className="min-w-0">
                    <h2 className="text-section-title">{title}</h2>
                    {description ? <p className="text-subtle mt-0.5">{description}</p> : null}
                </div>
                {aside}
            </div>
            {children}
        </Card>
    );
}

/**
 * A broadcast's own page — the message as sent, what each channel confirmed
 * for each person, who has read it in the app, and its history. Reading this
 * page never resends anything. Acknowledgement isn't recorded for broadcasts,
 * so it isn't shown.
 */
export default function BroadcastShow({ summary, recipients, history = [] }: Props) {
    const [tab, setTab] = useState<RecordTab>(initialTab);
    const [outcome, setOutcome] = useState<Outcome>('all');
    const [channel, setChannel] = useState('all');
    const [refreshing, setRefreshing] = useState(false);
    const now = useNow(60_000);

    const selectTab = (next: RecordTab) => {
        setTab(next);
        rememberTab(next);
    };
    const showRecipients = (next: Outcome, nextChannel = channel) => {
        setOutcome(next);
        setChannel(nextChannel);
        selectTab('recipients');
    };

    const everyone = useMemo(() => groupByPerson(recipients), [recipients]);
    const visiblePeople = everyone.filter((person) => {
        const attempts = [...person.byChannel.values()].filter(
            (r) => channel === 'all' || r.channel === channel,
        );
        if (attempts.length === 0) return false;
        return attempts.some((r) => matchesOutcome(r, outcome));
    });
    const shownChannels = summary.channels.filter(
        (c) => channel === 'all' || c === channel,
    );

    const read = summary.read ?? 0;
    const readOf = summary.read_of ?? 0;
    const tracksReads = readOf > 0;
    const showReadColumn = tracksReads && shownChannels.includes('in_app');

    const state = broadcastDeliveryState(summary.total, summary.delivered, summary.failed);
    const waiting = summary.sent + summary.pending;
    const deliveredPercent = summary.total
        ? Math.round((summary.delivered / summary.total) * 100)
        : 0;
    const readPercent = readOf ? Math.round((read / readOf) * 100) : 0;
    const template =
        summary.template_used && summary.template_used !== 'custom'
            ? (BROADCAST_TEMPLATE_LABELS[summary.template_used] ?? null)
            : null;
    const firstLine = summary.content.split('\n')[0].trim();
    const title = firstLine.length > 90 ? `${firstLine.slice(0, 88)}…` : firstLine;

    const refresh = () => {
        setRefreshing(true);
        router.reload({ onFinish: () => setRefreshing(false) });
    };

    const byChannel = summary.channels.map((key) => {
        const rows = recipients.filter((r) => r.channel === key);
        const count = (status: string) => rows.filter((r) => r.status === status).length;
        return {
            key,
            total: rows.length,
            delivered: count('delivered'),
            sent: count('sent'),
            pending: count('pending'),
            failed: count('failed'),
            skipped: count('skipped'),
        };
    });

    return (
        <ControlRoomPage
            headTitle="Broadcast"
            recordCrumb={{ title: 'Broadcast' }}
            header={{
                variant: 'profile',
                backHref: '/control-room/broadcast',
                icon: Megaphone,
                title: title || 'Broadcast',
                wrapTitle: true,
                titleChip: (
                    <PageHeaderStatusChip variant={state.tone}>{state.label}</PageHeaderStatusChip>
                ),
                subline: [
                    summary.sent_at ? `Sent ${formatRelative(summary.sent_at)}` : 'Not sent',
                    channelList(summary.channels),
                    template ? `${template} template` : null,
                ]
                    .filter(Boolean)
                    .join(' · '),
                actions: (
                    <PageHeaderGlassButton
                        icon={RefreshCw}
                        onClick={refresh}
                        disabled={refreshing}
                    >
                        {refreshing ? 'Checking…' : 'Check delivery'}
                    </PageHeaderGlassButton>
                ),
                meters: (
                    <>
                        {/* With reads recorded, Read takes the People block's place (four at most). */}
                        {tracksReads ? null : (
                            <PageHeaderMeterBlock
                                label="People"
                                onClick={() => showRecipients('all', 'all')}
                                ariaLabel="Show everyone this broadcast went to"
                            >
                                <PageHeaderMeterBig>{everyone.length}</PageHeaderMeterBig>
                                <PageHeaderMeterCaption>
                                    {summary.total} sends across {summary.channels.length}{' '}
                                    {summary.channels.length === 1 ? 'channel' : 'channels'}
                                </PageHeaderMeterCaption>
                            </PageHeaderMeterBlock>
                        )}
                        <PageHeaderMeterBlock
                            label="Delivered"
                            value={`${summary.delivered}/${summary.total}`}
                            tone={summary.total > 0 && summary.delivered === summary.total ? 'success' : 'brand'}
                            onClick={() => showRecipients('delivered')}
                            ariaLabel="Show deliveries the channel confirmed"
                        >
                            <PageHeaderMeterBig>{deliveredPercent}%</PageHeaderMeterBig>
                            <PageHeaderMeterBar percent={deliveredPercent} />
                            <PageHeaderMeterCaption>confirmed by the channel</PageHeaderMeterCaption>
                        </PageHeaderMeterBlock>
                        <PageHeaderMeterBlock
                            label="Not confirmed"
                            tone={waiting > 0 ? 'warning' : 'brand'}
                            onClick={() => showRecipients('waiting')}
                            ariaLabel="Show sends that aren't confirmed yet"
                        >
                            <PageHeaderMeterBig>{waiting}</PageHeaderMeterBig>
                            <PageHeaderMeterCaption>
                                {summary.sent} sent · {summary.pending} queued
                            </PageHeaderMeterCaption>
                        </PageHeaderMeterBlock>
                        <PageHeaderMeterBlock
                            label="Not delivered"
                            tone={summary.failed > 0 ? 'critical' : 'brand'}
                            onClick={() => showRecipients('failed')}
                            ariaLabel="Show sends that weren't delivered"
                        >
                            <PageHeaderMeterBig>{summary.failed}</PageHeaderMeterBig>
                            <PageHeaderMeterCaption>failed on their channel</PageHeaderMeterCaption>
                        </PageHeaderMeterBlock>
                        {tracksReads ? (
                            <PageHeaderMeterBlock
                                label="Read"
                                value={`${read}/${readOf}`}
                                tone={read === readOf ? 'success' : 'brand'}
                                onClick={() => showRecipients('unread', 'all')}
                                ariaLabel="Show who hasn't read it yet"
                            >
                                <PageHeaderMeterBig>{readPercent}%</PageHeaderMeterBig>
                                <PageHeaderMeterBar percent={readPercent} />
                                <PageHeaderMeterCaption>
                                    {readOf - read} not read yet in the app
                                </PageHeaderMeterCaption>
                            </PageHeaderMeterBlock>
                        ) : null}
                    </>
                ),
                filters: (
                    <>
                        <PageHeaderFilterSelect
                            icon={Filter}
                            label="All outcomes"
                            value={outcome}
                            onChange={(value) => showRecipients(value as Outcome)}
                            options={[
                                { value: 'all', label: 'All outcomes' },
                                { value: 'delivered', label: OUTCOME.delivered.label },
                                { value: 'waiting', label: 'Not confirmed yet' },
                                { value: 'sent', label: OUTCOME.sent.label },
                                { value: 'pending', label: OUTCOME.pending.label },
                                { value: 'failed', label: OUTCOME.failed.label },
                                { value: 'skipped', label: OUTCOME.skipped.label },
                                ...(tracksReads
                                    ? [
                                          { value: 'read', label: 'Read in the app' },
                                          { value: 'unread', label: 'Not read yet' },
                                      ]
                                    : []),
                            ]}
                        />
                        {summary.channels.length > 1 ? (
                            <PageHeaderFilterSelect
                                icon={Send}
                                label="All channels"
                                value={channel}
                                onChange={(value) => showRecipients(outcome, value)}
                                options={[
                                    { value: 'all', label: 'All channels' },
                                    ...summary.channels.map((key) => ({
                                        value: key,
                                        label: BROADCAST_CHANNEL_LABELS[key] ?? key,
                                    })),
                                ]}
                            />
                        ) : null}
                    </>
                ),
            }}
            rail={
                <PageHeaderRail
                    ariaLabel="Broadcast record"
                    value={tab}
                    onSelect={selectTab}
                    showFind={false}
                    items={[
                        { key: 'message', label: 'Message', icon: Megaphone },
                        {
                            key: 'recipients',
                            label: 'Recipients and delivery',
                            icon: Users,
                            count: everyone.length,
                        },
                        { key: 'history', label: 'History', icon: HistoryIcon },
                    ]}
                />
            }
        >
            {summary.failed > 0 || waiting > 0 ? (
                <PopupCallout tone="warning">
                    {summary.failed > 0
                        ? `${summary.failed} ${summary.failed === 1 ? 'send was' : 'sends were'} not delivered`
                        : `${waiting} ${waiting === 1 ? 'send is' : 'sends are'} not confirmed yet`}
                    . Check delivery before sending this again — reading this page
                    doesn&apos;t resend anything.
                </PopupCallout>
            ) : null}

            {tab === 'message' ? (
                <div className="grid min-w-0 items-start gap-5 xl:grid-cols-[minmax(0,1fr)_380px]">
                    <Panel title="Message">
                        <div className="grid gap-4 px-5 py-4">
                            <p className="text-sm whitespace-pre-wrap text-foreground">
                                {summary.content}
                            </p>
                            <PopupFacts
                                rows={[
                                    ...(summary.sent_at
                                        ? ([
                                              [
                                                  'Sent',
                                                  `${formatRelative(summary.sent_at)} · ${formatDateTime(summary.sent_at)}`,
                                              ],
                                          ] as const)
                                        : []),
                                    ['Audience', `${people(everyone.length)} you can see`],
                                    ['Channels requested', channelList(summary.channels)],
                                    ...(tracksReads
                                        ? ([['Read in the app', `Read ${read} of ${readOf}`]] as const)
                                        : []),
                                    ...(template ? ([['Template', template]] as const) : []),
                                ]}
                            />
                        </div>
                    </Panel>
                    <Panel
                        title="Delivery by channel"
                        description="Each channel reports its own result."
                    >
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead className="pl-5">Channel</TableHead>
                                    <TableHead className="text-right">Delivered</TableHead>
                                    <TableHead className="text-right">Not confirmed</TableHead>
                                    <TableHead className="pr-5 text-right">Not delivered</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {byChannel.map((row) => (
                                    <TableRow key={row.key}>
                                        <TableCell className="pl-5 font-medium">
                                            <Button
                                                type="button"
                                                variant="link"
                                                className="h-auto p-0"
                                                onClick={() => showRecipients('all', row.key)}
                                            >
                                                {BROADCAST_CHANNEL_LABELS[row.key] ?? row.key}
                                            </Button>
                                        </TableCell>
                                        <TableCell className="text-right tabular-nums">
                                            {row.delivered} of {row.total}
                                        </TableCell>
                                        <TableCell className="text-right tabular-nums">
                                            {row.sent + row.pending}
                                        </TableCell>
                                        <TableCell
                                            className={
                                                row.failed > 0
                                                    ? 'pr-5 text-right font-semibold text-status-critical tabular-nums'
                                                    : 'pr-5 text-right tabular-nums'
                                            }
                                        >
                                            {row.failed}
                                        </TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    </Panel>
                </div>
            ) : null}

            {tab === 'recipients' ? (
                <Panel
                    title="Recipients and delivery"
                    description="One row per person; each channel keeps its own result."
                    aside={
                        <StatusBadge variant="neutral" className="tabular-nums">
                            {visiblePeople.length} of {everyone.length}
                        </StatusBadge>
                    }
                >
                    {visiblePeople.length === 0 ? (
                        <div className="px-5 py-10">
                            <EmptyState
                                icon={Users}
                                title="No one matches these filters"
                                description="Choose another outcome or channel."
                                action={
                                    <Button
                                        type="button"
                                        variant="outline"
                                        onClick={() => showRecipients('all', 'all')}
                                    >
                                        Clear filters
                                    </Button>
                                }
                            />
                        </div>
                    ) : (
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead className="pl-5">Recipient</TableHead>
                                    {shownChannels.map((key) => (
                                        <ChannelHeads
                                            key={key}
                                            channel={key}
                                            withRead={showReadColumn && key === 'in_app'}
                                        />
                                    ))}
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {visiblePeople.map((person) => (
                                    <TableRow key={person.key}>
                                        <TableCell className="pl-5 whitespace-normal">
                                            <span className="block font-semibold">{person.name}</span>
                                            {person.email ? (
                                                <span className="text-caption block">{person.email}</span>
                                            ) : null}
                                        </TableCell>
                                        {shownChannels.map((key) => (
                                            <ChannelCells
                                                key={key}
                                                attempt={person.byChannel.get(key)}
                                                withRead={showReadColumn && key === 'in_app'}
                                                now={now}
                                            />
                                        ))}
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    )}
                </Panel>
            ) : null}

            {tab === 'history' ? (
                <Panel
                    title="History"
                    description="What happened to this broadcast, from its delivery records. Times are when each result was recorded."
                >
                    <BroadcastHistory events={history} now={now} />
                </Panel>
            ) : null}
        </ControlRoomPage>
    );
}

function ChannelHeads({ channel, withRead }: { channel: string; withRead: boolean }) {
    return (
        <>
            <TableHead>{BROADCAST_CHANNEL_LABELS[channel] ?? channel}</TableHead>
            {withRead ? <TableHead>Read</TableHead> : null}
        </>
    );
}

function ChannelCells({
    attempt,
    withRead,
    now,
}: {
    attempt: Recipient | undefined;
    withRead: boolean;
    now: number;
}) {
    return (
        <>
            <TableCell className="whitespace-normal">
                <ChannelOutcome attempt={attempt} />
            </TableCell>
            {withRead ? (
                <TableCell className="whitespace-nowrap">
                    <ReadOutcome attempt={attempt} now={now} />
                </TableCell>
            ) : null}
        </>
    );
}

function ChannelOutcome({ attempt }: { attempt: Recipient | undefined }) {
    if (!attempt) return <span className="text-caption">Not sent</span>;
    const outcome = outcomeOf(attempt.status);
    const when = attempt.delivered_at
        ? `Delivered ${formatRelative(attempt.delivered_at)}`
        : attempt.sent_at
          ? `Sent ${formatRelative(attempt.sent_at)}`
          : null;
    return (
        <span className="grid gap-0.5">
            <StatusBadge variant={outcome.tone} size="sm" className="justify-self-start">
                {outcome.label}
            </StatusBadge>
            {when ? (
                <span
                    className="text-caption"
                    title={formatDateTime(attempt.delivered_at ?? attempt.sent_at)}
                >
                    {when}
                </span>
            ) : null}
            {attempt.status_detail ? (
                <span className="text-caption">{attempt.status_detail}</span>
            ) : null}
        </span>
    );
}

/** When they first read the in-app copy; nothing for a copy that never reached them. */
function ReadOutcome({ attempt, now }: { attempt: Recipient | undefined; now: number }) {
    if (!attempt) return null;
    if (attempt.read_at) {
        const ms = now - new Date(attempt.read_at).getTime();
        return (
            <span title={formatDateTime(attempt.read_at)}>
                Read {ms < 60_000 ? 'just now' : `${longDuration(ms)} ago`}
            </span>
        );
    }
    if (!attempt.read_tracked) return <span className="text-caption">Not recorded</span>;
    if (attempt.status !== 'delivered') return null;
    return <span className="text-caption">Not yet</span>;
}

type HistoryTone = 'primary' | 'success' | 'critical' | 'warning' | 'neutral';

const DOT: Record<HistoryTone, string> = {
    primary: 'bg-primary',
    success: 'bg-status-success',
    critical: 'bg-status-critical',
    warning: 'bg-status-warning',
    neutral: 'bg-muted-foreground',
};

function reasonsLine(reasons: HistoryReason[] | undefined): string | null {
    if (!reasons?.length) return null;
    return reasons
        .map((r) => {
            const words = broadcastReasonWords(r.reason, r.detail) ?? 'No reason recorded';
            return reasons.length > 1 ? `${words} (${r.count})` : words;
        })
        .join(' · ');
}

function historyItem(
    event: HistoryEvent,
    now: number,
): { title: string; sub: string | null; tone: HistoryTone; time: string | null } {
    const time = event.at ? formatDateTime(event.at) : null;
    switch (event.kind) {
        case 'sent':
            return {
                title: event.by ? `Sent by ${event.by}` : 'Sent',
                sub: [
                    `To ${people(event.people)} you can see`,
                    `by ${channelList(event.channels)}`,
                    event.force_delivery ? 'Force delivery on — Do Not Disturb ignored' : null,
                ]
                    .filter(Boolean)
                    .join(' · '),
                tone: 'primary',
                time,
            };
        case 'delivered': {
            const label = BROADCAST_CHANNEL_LABELS[event.channel] ?? event.channel;
            return {
                title: `${label}: delivered to ${people(event.count)}`,
                sub:
                    event.count > 1 && event.last_at && event.last_at !== event.at
                        ? `Last one ${formatDateTime(event.last_at)}`
                        : null,
                tone: 'success',
                time,
            };
        }
        case 'failed': {
            const label = BROADCAST_CHANNEL_LABELS[event.channel] ?? event.channel;
            return {
                title: `${label}: not delivered to ${people(event.count)}`,
                sub: reasonsLine(event.reasons),
                tone: 'critical',
                time,
            };
        }
        case 'skipped': {
            const label = BROADCAST_CHANNEL_LABELS[event.channel] ?? event.channel;
            return {
                title: `${label}: skipped for ${people(event.count)}`,
                sub: reasonsLine(event.reasons),
                tone: 'neutral',
                time,
            };
        }
        case 'read':
            return {
                title: `Read in the app by ${event.count} of ${event.of}`,
                sub: [
                    event.first_reader ? `First: ${event.first_reader}` : null,
                    event.count > 1 && event.last_at ? `latest ${formatDateTime(event.last_at)}` : null,
                ]
                    .filter(Boolean)
                    .join(' · ') || null,
                tone: 'success',
                time,
            };
        case 'waiting': {
            const label = BROADCAST_CHANNEL_LABELS[event.channel] ?? event.channel;
            const waited = event.at ? now - new Date(event.at).getTime() : NaN;
            return {
                title: `${label}: ${event.count} not confirmed yet`,
                sub: Number.isFinite(waited)
                    ? `No result since it was sent ${waited < 60_000 ? 'just now' : `${longDuration(waited)} ago`}`
                    : null,
                tone: 'warning',
                time: 'Now',
            };
        }
    }
}

function BroadcastHistory({ events, now }: { events: HistoryEvent[]; now: number }) {
    if (events.length === 0) {
        return (
            <div className="px-5 py-10">
                <EmptyState
                    icon={HistoryIcon}
                    title="No history recorded"
                    description="Nothing has been recorded for this broadcast yet."
                />
            </div>
        );
    }
    const items = events.map((event) => historyItem(event, now));
    return (
        <ol className="px-5 py-4" aria-label="Broadcast history">
            {items.map((item, index) => (
                <li key={index} className="relative flex gap-3 pb-5 last:pb-0">
                    {index < items.length - 1 ? (
                        <span
                            aria-hidden="true"
                            className="absolute top-4 bottom-0 left-[5px] w-px bg-border"
                        />
                    ) : null}
                    <span
                        aria-hidden="true"
                        className={cn('relative mt-1.5 size-[11px] shrink-0 rounded-full', DOT[item.tone])}
                    />
                    <div className="min-w-0 flex-1">
                        <p className="text-sm font-medium text-foreground">{item.title}</p>
                        {item.sub ? <p className="text-caption">{item.sub}</p> : null}
                    </div>
                    {item.time ? (
                        <span className="text-caption shrink-0 tabular-nums">{item.time}</span>
                    ) : null}
                </li>
            ))}
        </ol>
    );
}
