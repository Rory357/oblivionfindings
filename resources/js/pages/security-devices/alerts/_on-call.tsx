/**
 * Alerts › On call (S&D drawing pg-sdr-13d, main and side): who covers IT now and next from Rostering's
 * on-call shifts, the latest handover note, how each kind of IT alert reaches the person on call (the
 * same switches as Setup › Alerts & checks) and what is waiting on them. Safety alerts aren't routed here.
 */
import {
    EntityContextMenu,
    useEntityContextMenu,
    type MenuItem,
} from '@/components/lists';
import { EntityTable } from '@/components/lists/entity-table';
import { PopupField, PopupForm } from '@/components/popup';
import { NextStep } from '@/components/security-devices/device-blocks';
import {
    Log,
    PeopleCards,
    QuietLine,
    SideBySide,
    SideColumn,
    Story,
} from '@/components/signature/blocks';
import { Button } from '@/components/ui/button';
import { StatusBadge } from '@/components/ui/status-badge';
import { Textarea } from '@/components/ui/textarea';
import { formatDate, formatDateTime } from '@/lib/datetime';
import { seenTime } from '@/lib/security-devices/device-time';
import { Link, router } from '@inertiajs/react';
import axios from 'axios';
import { BellRing, Settings2 } from 'lucide-react';
import { useEffect, useState } from 'react';
import { toast } from 'sonner';
import { BlockHead } from '../devices/_parts';
import type { OnCall } from './_types';

type DeliveryRow = OnCall['delivery'][number];

const WHEN_WORDS: Record<OnCall['people'][number]['when'], string> = {
    now: 'on call now',
    next: 'on call next',
    before: 'was on call',
};

const onOff = (on: boolean) =>
    on ? (
        <StatusBadge variant="success">On</StatusBadge>
    ) : (
        <span className="text-muted-foreground">Off</span>
    );

export function OnCallTab({ data, now }: { data: OnCall; now: Date }) {
    const [writing, setWriting] = useState(false);
    const menu = useEntityContextMenu<DeliveryRow>();
    const who = data.role ?? 'IT for that Site';
    const first = data.now?.first_name;
    const toSetup = () => router.visit('/security-devices/setup/alerts');
    const deliveryActions = (): MenuItem[] =>
        data.can.setup
            ? [
                  {
                      label: 'Change in Setup',
                      icon: Settings2,
                      onClick: toSetup,
                  },
              ]
            : [];

    const actions = (
        <>
            {data.can.rostering ? (
                <Button asChild size="sm">
                    <Link href="/operations/rostering">Open in Rostering</Link>
                </Button>
            ) : null}
            {data.can.handover ? (
                <Button
                    type="button"
                    size="sm"
                    variant="outline"
                    onClick={() => setWriting(true)}
                >
                    Add a handover note
                </Button>
            ) : null}
        </>
    );

    const note = data.handover;
    const paras = note
        ? (
              [
                  ['Still open', note.still_open],
                  ['Coming up', note.coming_up],
                  ['Good to know', note.good_to_know],
              ] as const
          )
              .filter(([, text]) => text)
              .map(([h, t]) => ({ h, t: t as string }))
        : [];

    return (
        <SideBySide cols="minmax(0, 2fr) minmax(0, 1fr)">
            <div className="grid content-start gap-5">
                {data.now ? (
                    <NextStep
                        label={`IT on call · until ${formatDateTime(data.now.until)}`}
                        title={`${data.now.name} is on call`}
                        sub={`From the IT on-call shifts in Rostering. Devices and hubs that stop answering and wrong sensor logins go to ${first} first; everyone else in ${who} hears at the one-hour reminder.`}
                        actions={actions}
                    />
                ) : (
                    <NextStep
                        tone="warning"
                        label="IT on call"
                        title="Nobody is on IT on call right now"
                        sub={`Alerts go to everyone in ${who}, as before. Add on-call shifts for IT people in Rostering and they’re told first.`}
                        actions={actions}
                    />
                )}
                {data.people.length > 0 ? (
                    <PeopleCards
                        title="This week and next"
                        cols={3}
                        items={data.people.map((person) => ({
                            id: person.id,
                            name: person.name,
                            sub: `${person.role ? `${person.role} · ` : ''}${WHEN_WORDS[person.when]}`,
                            presence: person.when === 'now' ? 'on' : undefined,
                            foot: (
                                <>
                                    <b>{formatDate(person.from)}</b> to{' '}
                                    {formatDateTime(person.to)}
                                </>
                            ),
                        }))}
                    />
                ) : (
                    <QuietLine
                        title="This week and next"
                        text="No IT on-call shifts in Rostering for the last week or the next two."
                    />
                )}
                {data.can.handover ? (
                    note && paras.length > 0 ? (
                        <Story
                            title="Handover notes"
                            sub={`From ${note.from}${note.to ? ` to ${note.to}` : ''} · ${formatDateTime(note.at)}`}
                            paras={paras}
                        />
                    ) : (
                        <QuietLine
                            title="Handover notes"
                            text="No handover notes yet."
                            action={{
                                label: 'Add a handover note',
                                onClick: () => setWriting(true),
                            }}
                        />
                    )
                ) : null}
                <section
                    className="overflow-hidden rounded-[14px] border border-border bg-card shadow-sm"
                    aria-label="How alerts reach the person on call"
                >
                    <div className="border-b border-border px-4 py-3.5">
                        <BlockHead
                            title="How alerts reach the person on call"
                            sub="As Setup › Alerts & checks sets it · text messages need a provider first"
                            right={
                                data.can.setup ? (
                                    <Button
                                        type="button"
                                        size="sm"
                                        variant="outline"
                                        onClick={toSetup}
                                    >
                                        Open Setup
                                    </Button>
                                ) : null
                            }
                        />
                    </div>
                    <EntityTable<DeliveryRow>
                        rows={data.delivery}
                        rowKey={(row) => row.key}
                        identityLabel="When"
                        identity={(row) => ({
                            name: row.when,
                            subline: row.sub,
                        })}
                        columns={[
                            {
                                key: 'app',
                                label: 'In-app',
                                width: '0.6fr',
                                cell: (row) => onOff(row.app),
                            },
                            {
                                key: 'email',
                                label: 'Email',
                                width: '0.6fr',
                                cell: (row) => onOff(row.email),
                            },
                            {
                                key: 'push',
                                label: 'Push',
                                width: '0.6fr',
                                cell: (row) => onOff(row.push),
                            },
                            {
                                key: 'text',
                                label: 'Text',
                                width: '0.7fr',
                                cell: () => (
                                    <span className="text-muted-foreground">
                                        Not set up
                                    </span>
                                ),
                            },
                        ]}
                        actionsFor={deliveryActions}
                        onOpen={data.can.setup ? toSetup : undefined}
                        onRowContextMenu={(event, row) => menu.open(event, row)}
                        minWidth={760}
                    />
                </section>
            </div>
            <SideColumn>
                {data.waiting.length === 0 ? (
                    <QuietLine
                        title={first ? `With ${first} now` : 'Waiting on someone'}
                        text="No device or hub alert is waiting for someone to acknowledge it."
                    />
                ) : (
                    <Log
                        title={first ? `With ${first} now` : 'Waiting on someone'}
                        sub="Open IT alerts nobody has acknowledged yet"
                        items={data.waiting.map((alert) => ({
                            key: alert.id,
                            time: seenTime(alert.at, now),
                            tone: alert.tone,
                            title: alert.title,
                            sub: [
                                alert.told_at
                                    ? `Told ${seenTime(alert.told_at, now)}`
                                    : 'Nobody told yet',
                                alert.reminded_at
                                    ? `reminded ${seenTime(alert.reminded_at, now)}`
                                    : null,
                                'not acknowledged yet',
                            ]
                                .filter(Boolean)
                                .join(' · '),
                            action: {
                                label: 'Open',
                                href: `/security-devices/alerts?alert=${alert.id}`,
                                ariaLabel: `Open ${alert.title}`,
                            },
                        }))}
                    />
                )}
                {data.now === null ? (
                    <QuietLine
                        title="Nobody on the roster?"
                        text={`Alerts go to everyone in ${who}, as they do today.`}
                        action={
                            data.can.rostering
                                ? {
                                      label: 'Open Rostering',
                                      href: '/operations/rostering',
                                  }
                                : null
                        }
                    />
                ) : null}
            </SideColumn>
            {menu.ctx && deliveryActions().length > 0 ? (
                <EntityContextMenu
                    x={menu.ctx.x}
                    y={menu.ctx.y}
                    icon={BellRing}
                    title={menu.ctx.record.when}
                    items={deliveryActions()}
                    onClose={menu.close}
                />
            ) : null}
            <AddHandover
                open={writing}
                to={data.people.find((person) => person.when === 'next')?.name ?? null}
                onClose={() => setWriting(false)}
            />
        </SideBySide>
    );
}

/** A handover note for whoever is on IT on call next. */
function AddHandover({
    open,
    to,
    onClose,
}: {
    open: boolean;
    to: string | null;
    onClose: () => void;
}) {
    const [parts, setParts] = useState({
        still_open: '',
        coming_up: '',
        good_to_know: '',
    });
    const [processing, setProcessing] = useState(false);
    const [error, setError] = useState<string | null>(null);
    useEffect(() => {
        if (open) {
            setParts({ still_open: '', coming_up: '', good_to_know: '' });
            setError(null);
        }
    }, [open]);
    const dirty = Object.values(parts).some((text) => text.trim() !== '');

    const submit = () => {
        if (!dirty) {
            setError('Write at least one part of the handover.');
            return;
        }
        setProcessing(true);
        setError(null);
        axios
            .post<{ message?: string }>(
                '/security-devices/alerts/on-call/handover',
                parts,
                { headers: { Accept: 'application/json' } },
            )
            .then((response) => {
                toast.success(response.data?.message ?? 'Handover saved.');
                onClose();
                router.reload({ only: ['on_call'] });
            })
            .catch((failure) => {
                const errors = axios.isAxiosError(failure)
                    ? (failure.response?.data?.errors as
                          | Record<string, string[]>
                          | undefined)
                    : undefined;
                setError(
                    errors
                        ? (Object.values(errors)[0]?.[0] ??
                              'The handover couldn’t be saved. Try again.')
                        : 'The handover couldn’t be saved. Try again.',
                );
            })
            .finally(() => setProcessing(false));
    };

    const field = (
        key: keyof typeof parts,
        label: string,
        placeholder: string,
    ) => (
        <PopupField label={label}>
            <Textarea
                value={parts[key]}
                onChange={(event) =>
                    setParts((current) => ({
                        ...current,
                        [key]: event.target.value,
                    }))
                }
                rows={3}
                maxLength={2000}
                placeholder={placeholder}
            />
        </PopupField>
    );

    return (
        <PopupForm
            open={open}
            onClose={onClose}
            size="md"
            icon={BellRing}
            title="Add a handover note"
            description={
                to
                    ? `For ${to}, on IT on call next`
                    : 'For whoever is on IT on call next'
            }
            error={error ?? undefined}
            dirty={dirty}
            draftName="IT on-call handover"
            processing={processing}
            processingLabel="Saving…"
            submitLabel="Save handover"
            onSubmit={submit}
            testId="sd-on-call-handover"
        >
            {field(
                'still_open',
                'Still open',
                'For example: the router dropped twice at night; check the power board first',
            )}
            {field(
                'coming_up',
                'Coming up',
                'For example: firewall update on Saturday at 6:00 am; quiet time is set',
            )}
            {field(
                'good_to_know',
                'Good to know',
                'For example: the spare memory card is in the IT cupboard',
            )}
        </PopupForm>
    );
}
