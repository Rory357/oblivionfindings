import {
    BROADCAST_CHANNEL_LABELS,
    BROADCAST_TEMPLATE_LABELS,
    broadcastDeliveryState,
    channelList,
} from '@/components/control-room/broadcast-wizard';
import { ControlRoomPage } from '@/components/control-room/control-room-page';
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
import { router } from '@inertiajs/react';
import { Filter, Megaphone, RefreshCw, Send, Users } from 'lucide-react';
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
}

interface Props {
    summary: BroadcastSummary;
    recipients: Recipient[];
}

type RecordTab = 'message' | 'recipients';
type Outcome =
    | 'all'
    | 'delivered'
    | 'waiting'
    | 'sent'
    | 'pending'
    | 'failed'
    | 'skipped';

/** "Waiting" = sent but not confirmed, or still queued. */
function matchesOutcome(status: string, outcome: Outcome) {
    if (outcome === 'all') return true;
    if (outcome === 'waiting') return status === 'sent' || status === 'pending';
    return status === outcome;
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

function initialTab(): RecordTab {
    if (typeof window === 'undefined') return 'message';
    return new URLSearchParams(window.location.search).get('tab') === 'recipients'
        ? 'recipients'
        : 'message';
}

/** Keep the open tab in the address so a refresh or a shared link returns to it. */
function rememberTab(tab: RecordTab) {
    const url = new URL(window.location.href);
    if (tab === 'message') url.searchParams.delete('tab');
    else url.searchParams.set('tab', tab);
    window.history.replaceState(window.history.state, '', url.toString());
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
 * A broadcast's own page — the message as sent, and what each channel
 * confirmed for each person. Reading this page never resends anything.
 */
export default function BroadcastShow({ summary, recipients }: Props) {
    const [tab, setTab] = useState<RecordTab>(initialTab);
    const [outcome, setOutcome] = useState<Outcome>('all');
    const [channel, setChannel] = useState('all');
    const [refreshing, setRefreshing] = useState(false);

    const selectTab = (next: RecordTab) => {
        setTab(next);
        rememberTab(next);
    };
    const showRecipients = (next: Outcome, nextChannel = channel) => {
        setOutcome(next);
        setChannel(nextChannel);
        selectTab('recipients');
    };

    const people = useMemo(() => groupByPerson(recipients), [recipients]);
    const visiblePeople = people.filter((person) => {
        const attempts = [...person.byChannel.values()].filter(
            (r) => channel === 'all' || r.channel === channel,
        );
        if (attempts.length === 0) return false;
        return attempts.some((r) => matchesOutcome(r.status, outcome));
    });
    const shownChannels = summary.channels.filter(
        (c) => channel === 'all' || c === channel,
    );

    const state = broadcastDeliveryState(summary.total, summary.delivered, summary.failed);
    const waiting = summary.sent + summary.pending;
    const deliveredPercent = summary.total
        ? Math.round((summary.delivered / summary.total) * 100)
        : 0;
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
                        <PageHeaderMeterBlock
                            label="People"
                            onClick={() => showRecipients('all', 'all')}
                            ariaLabel="Show everyone this broadcast went to"
                        >
                            <PageHeaderMeterBig>{people.length}</PageHeaderMeterBig>
                            <PageHeaderMeterCaption>
                                {summary.total} sends across {summary.channels.length}{' '}
                                {summary.channels.length === 1 ? 'channel' : 'channels'}
                            </PageHeaderMeterCaption>
                        </PageHeaderMeterBlock>
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
                            count: people.length,
                        },
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
                                    [
                                        'Audience',
                                        `${people.length} ${people.length === 1 ? 'person' : 'people'} you can see`,
                                    ],
                                    ['Channels requested', channelList(summary.channels)],
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
            ) : (
                <Panel
                    title="Recipients and delivery"
                    description="One row per person; each channel keeps its own result."
                    aside={
                        <StatusBadge variant="neutral" className="tabular-nums">
                            {visiblePeople.length} of {people.length}
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
                                        <TableHead key={key}>
                                            {BROADCAST_CHANNEL_LABELS[key] ?? key}
                                        </TableHead>
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
                                            <TableCell key={key} className="whitespace-normal">
                                                <ChannelOutcome attempt={person.byChannel.get(key)} />
                                            </TableCell>
                                        ))}
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    )}
                </Panel>
            )}
        </ControlRoomPage>
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
