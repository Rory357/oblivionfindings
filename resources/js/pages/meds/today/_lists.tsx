import { secondPersonDisplay } from '@/lib/medication-second-person';
/* Meds today lists share rows and authorised actions between phone cards
 * and desktop tables. Activity remains server-paginated, 10 a page. */
import { EntityCard } from '@/components/lists/entity-card';
import { PersonDisc } from '@/components/lists/entity-cells';
import { compactMenu, type MenuItem } from '@/components/lists/entity-menu';
import { EntityTable } from '@/components/lists/entity-table';
import { ListCaption } from '@/components/lists/list-caption';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { EmptyState } from '@/components/ui/empty-state';
import { StatusBadge } from '@/components/ui/status-badge';
import { ClipboardList, Flag, HeartPulse, Pill, Repeat } from 'lucide-react';
import type { MouseEvent } from 'react';
import type {
    ClientInfo,
    PrnFollowUp,
    PrnMedication,
    PrnRecorded,
    RefusalFollowUp,
} from './types';

import { formatDateTime } from '@/lib/datetime';
import { MobileMedsFacts } from './_mobile-facts';

export { ActivityView } from './_activity';

type Ctx = (e: MouseEvent, title: string, items: MenuItem[]) => void;

const nameOf = (
    clients: Map<number, ClientInfo>,
    id: number,
    fallback: string,
) => {
    const c = clients.get(id);
    return {
        preferred: c?.preferred ?? fallback.split(' ')[0],
        surname: (c?.name ?? fallback).split(' ').slice(1).join(' '),
        legal: c?.name ?? fallback,
    };
};

/* ───────────── As-needed ───────────── */
export function AsNeededView({
    medications,
    recorded,
    clients,
    search,
    person,
    canRecord,
    canReportError,
    onRecord,
    chartFor,
    onReportError,
    onContext,
}: {
    medications: PrnMedication[];
    recorded: PrnRecorded[];
    clients: Map<number, ClientInfo>;
    search: string;
    person: number | null;
    canRecord: (m: PrnMedication) => boolean;
    canReportError: boolean;
    onRecord: (m: PrnMedication) => void;
    /** Opens the person's medication record, or null when this worker can't. */
    chartFor: (clientId: number) => (() => void) | null;
    onReportError: (clientId: number) => void;
    onContext: Ctx;
}) {
    const q = search.trim().toLowerCase();
    const rows = medications.filter(
        (m) =>
            (person === null || m.client_id === person) &&
            (!q || `${m.client_name} ${m.name}`.toLowerCase().includes(q)),
    );
    const blocked = (m: PrnMedication) => m.over_limit || m.interval_blocked;
    const menu = (m: PrnMedication): MenuItem[] => {
        const p = nameOf(clients, m.client_id, m.client_name).preferred;
        return compactMenu([
            canRecord(m) && {
                label: blocked(m)
                    ? 'Why can’t I record this?'
                    : 'Record as-needed dose',
                icon: Pill,
                onClick: () => onRecord(m),
            },
            { separator: true },
            chartFor(m.client_id) && {
                label: `Open ${p}’s medication record`,
                icon: ClipboardList,
                onClick: chartFor(m.client_id)!,
            },
            canReportError && {
                label: 'Report a medication error',
                icon: Flag,
                onClick: () => onReportError(m.client_id),
            },
        ]);
    };
    const shownRecorded = recorded.filter(
        (x) => person === null || x.client_id === person,
    );
    return (
        <>
            <section
                className="flex flex-col gap-2.5"
                aria-label="As-needed medicines"
            >
                <ListCaption
                    className="[&_h2]:break-words [&_h2]:whitespace-normal [&>div:first-child]:flex-wrap"
                    title="As-needed medicines for people on your shift"
                    caption={`${rows.length} of ${medications.length} shown`}
                />
                {rows.length === 0 ? (
                    <Card className="p-2">
                        <EmptyState
                            icon={Pill}
                            title={
                                medications.length
                                    ? 'No as-needed medicines match'
                                    : 'No as-needed medicines to show'
                            }
                            description={
                                medications.length
                                    ? 'Clear the search or choose “All people”.'
                                    : 'This view shows current medicines available to you. Check the person’s medication record or ask the house lead if you need to confirm an order.'
                            }
                        />
                    </Card>
                ) : (
                    <>
                        <ul
                            className="grid min-w-0 gap-5 md:hidden"
                            aria-label="As-needed medicine cards"
                        >
                            {rows.map((m) => {
                                const p = nameOf(
                                    clients,
                                    m.client_id,
                                    m.client_name,
                                );
                                const personName =
                                    `${p.preferred} ${p.surname}`.trim();
                                const record = canRecord(m);
                                return (
                                    <li key={m.id} className="min-w-0">
                                        <EntityCard
                                            meridian={
                                                m.over_limit
                                                    ? 'critical'
                                                    : m.interval_blocked
                                                      ? 'warning'
                                                      : 'neutral'
                                            }
                                            mark={
                                                <PersonDisc
                                                    name={p.legal}
                                                    size={40}
                                                />
                                            }
                                            name={personName}
                                            className="min-w-0 [&_h3]:break-words [&_h3]:whitespace-normal"
                                            actions={menu(m)}
                                            onOpen={
                                                record
                                                    ? () => onRecord(m)
                                                    : undefined
                                            }
                                            onContextMenu={(e) =>
                                                onContext(
                                                    e,
                                                    `${p.preferred} · ${m.name}`,
                                                    menu(m),
                                                )
                                            }
                                            chips={
                                                <>
                                                    {m.over_limit ? (
                                                        <StatusBadge variant="critical">
                                                            Limit reached
                                                        </StatusBadge>
                                                    ) : m.interval_blocked ? (
                                                        <StatusBadge variant="warning">
                                                            Too soon
                                                            {m.next_allowed_label
                                                                ? ` · from ${m.next_allowed_label}`
                                                                : ''}
                                                        </StatusBadge>
                                                    ) : (
                                                        <StatusBadge variant="neutral">
                                                            Within PRN limits
                                                        </StatusBadge>
                                                    )}
                                                    <MobileMedsFacts
                                                        facts={[
                                                            {
                                                                label: 'Medicine',
                                                                value: (
                                                                    <>
                                                                        {m.name}
                                                                        {m.is_controlled
                                                                            ? ' · controlled'
                                                                            : ''}
                                                                    </>
                                                                ),
                                                            },
                                                            {
                                                                label: 'Ordered dose',
                                                                value:
                                                                    m.dose ??
                                                                    '—',
                                                            },
                                                            {
                                                                label: 'Instructions',
                                                                value:
                                                                    m.instructions ??
                                                                    m.prn_reason ??
                                                                    '—',
                                                            },
                                                            {
                                                                label: 'Last 24 hours',
                                                                value: (
                                                                    <>
                                                                        {
                                                                            m.given_last_24h
                                                                        }{' '}
                                                                        of{' '}
                                                                        {m.max_per_day ??
                                                                            'no limit'}
                                                                    </>
                                                                ),
                                                            },
                                                            {
                                                                label: 'Last given',
                                                                value: m.last_given_label
                                                                    ? `${m.last_given_label} NZ time`
                                                                    : '—',
                                                            },
                                                        ]}
                                                    />
                                                </>
                                            }
                                            alerts={
                                                record ? (
                                                    <Button
                                                        data-return={`prn-${m.id}`}
                                                        variant={
                                                            blocked(m)
                                                                ? 'outline'
                                                                : 'default'
                                                        }
                                                        className="frontline-tap h-auto max-w-full whitespace-normal"
                                                        onClick={(e) => {
                                                            e.stopPropagation();
                                                            onRecord(m);
                                                        }}
                                                    >
                                                        {blocked(m)
                                                            ? 'Why can’t I record this?'
                                                            : 'Record dose'}
                                                    </Button>
                                                ) : undefined
                                            }
                                        />
                                    </li>
                                );
                            })}
                        </ul>
                        <div className="hidden min-w-0 md:block">
                            <EntityTable<PrnMedication>
                                rows={rows}
                                rowKey={(m) => m.id}
                                rowHeight="content"
                                minWidth={960}
                                identityLabel="Person"
                                identityWidth="1fr"
                                identity={(m) => {
                                    const p = nameOf(
                                        clients,
                                        m.client_id,
                                        m.client_name,
                                    );
                                    return {
                                        mark: (
                                            <PersonDisc
                                                name={p.legal}
                                                size={30}
                                            />
                                        ),
                                        name: p.preferred,
                                        subline: p.surname,
                                    };
                                }}
                                columns={[
                                    {
                                        key: 'med',
                                        label: 'Medicine',
                                        width: '1.6fr',
                                        cell: (m) => (
                                            <span className="flex flex-col gap-1">
                                                <span className="text-[13px] font-semibold">
                                                    {m.name}{' '}
                                                    <span className="font-normal text-muted-foreground">
                                                        {m.dose}
                                                    </span>
                                                    {m.is_controlled
                                                        ? ' · controlled'
                                                        : ''}
                                                </span>
                                                <span className="text-[12px] text-muted-foreground">
                                                    {m.instructions ??
                                                        m.prn_reason ??
                                                        ''}
                                                </span>
                                            </span>
                                        ),
                                    },
                                    {
                                        key: 'last',
                                        label: 'Last 24 hours',
                                        width: '1.2fr',
                                        cell: (m) => (
                                            <span className="text-[12.5px]">
                                                <strong>
                                                    {m.given_last_24h} of{' '}
                                                    {m.max_per_day ??
                                                        'no limit'}
                                                </strong>
                                                {m.last_given_label
                                                    ? ` · last ${m.last_given_label.replace(/^Today\s+/i, '').replace(/^Yesterday\s+(.*)$/i, '$1 yesterday')}`
                                                    : ' · none'}
                                            </span>
                                        ),
                                    },
                                    {
                                        key: 'st',
                                        label: 'State',
                                        width: '1fr',
                                        cell: (m) =>
                                            m.over_limit ? (
                                                <StatusBadge variant="critical">
                                                    Limit reached
                                                </StatusBadge>
                                            ) : m.interval_blocked ? (
                                                <StatusBadge variant="warning">
                                                    Too soon
                                                    {m.next_allowed_label
                                                        ? ` · from ${m.next_allowed_label}`
                                                        : ''}
                                                </StatusBadge>
                                            ) : (
                                                <StatusBadge variant="neutral">
                                                    Within PRN limits
                                                </StatusBadge>
                                            ),
                                    },
                                    {
                                        key: 'act',
                                        label: '',
                                        width: '150px',
                                        align: 'right',
                                        cell: (m) =>
                                            canRecord(m) ? (
                                                <Button
                                                    data-return={`prn-${m.id}`}
                                                    variant={
                                                        blocked(m)
                                                            ? 'outline'
                                                            : 'default'
                                                    }
                                                    className="frontline-tap"
                                                    onClick={(e) => {
                                                        e.stopPropagation();
                                                        onRecord(m);
                                                    }}
                                                >
                                                    {blocked(m)
                                                        ? 'Why not?'
                                                        : 'Record'}
                                                </Button>
                                            ) : null,
                                    },
                                ]}
                                actionsFor={menu}
                                onOpen={(m) =>
                                    canRecord(m) ? onRecord(m) : undefined
                                }
                                onRowContextMenu={(e, m) =>
                                    onContext(
                                        e,
                                        `${nameOf(clients, m.client_id, m.client_name).preferred} · ${m.name}`,
                                        menu(m),
                                    )
                                }
                            />
                        </div>
                    </>
                )}
                <p className="text-caption">
                    Counts use the real last 24 hours. A dose at its limit still
                    opens so you can see why and what to do.
                </p>
            </section>
            <section
                className="flex flex-col gap-2.5"
                aria-label="As-needed doses recorded today"
            >
                <ListCaption
                    className="[&_h2]:break-words [&_h2]:whitespace-normal [&>div:first-child]:flex-wrap"
                    title="As-needed doses recorded today"
                    caption={`${shownRecorded.length} shown`}
                />
                {shownRecorded.length === 0 ? (
                    <p className="text-caption">None recorded today.</p>
                ) : (
                    <>
                        <ul
                            className="grid min-w-0 gap-5 md:hidden"
                            aria-label="Recorded as-needed dose cards"
                        >
                            {shownRecorded.map((x) => {
                                const p = nameOf(
                                    clients,
                                    x.client_id,
                                    'Person not recorded',
                                );
                                return (
                                    <li key={x.id} className="min-w-0">
                                        <EntityCard
                                            meridian="neutral"
                                            mark={
                                                <PersonDisc
                                                    name={p.legal}
                                                    size={40}
                                                />
                                            }
                                            name={`${p.preferred} ${p.surname}`.trim()}
                                            className="min-w-0 [&_h3]:break-words [&_h3]:whitespace-normal"
                                            actions={[]}
                                            chips={
                                                <>
                                                    <StatusBadge
                                                        variant={
                                                            x.status === 'given'
                                                                ? 'success'
                                                                : 'warning'
                                                        }
                                                    >
                                                        {x.status === 'given'
                                                            ? 'Given'
                                                            : x.status}
                                                    </StatusBadge>
                                                    <MobileMedsFacts
                                                        facts={[
                                                            ...(secondPersonDisplay(
                                                                x,
                                                            )
                                                                ? [
                                                                      {
                                                                          label: 'Second-person check',
                                                                          value:
                                                                              secondPersonDisplay(
                                                                                  x,
                                                                              )!
                                                                                  .label +
                                                                              ' · ' +
                                                                              secondPersonDisplay(
                                                                                  x,
                                                                              )!
                                                                                  .detail,
                                                                      },
                                                                  ]
                                                                : []),
                                                            {
                                                                label: 'Medicine',
                                                                value:
                                                                    x.medication_name ??
                                                                    '—',
                                                            },
                                                            {
                                                                label: 'Dose recorded',
                                                                value:
                                                                    x.dose_given ??
                                                                    '—',
                                                            },
                                                            {
                                                                label: 'When',
                                                                value: x.time
                                                                    ? `${x.time} NZ time`
                                                                    : '—',
                                                            },
                                                            {
                                                                label: 'Reason',
                                                                value:
                                                                    x.reason ??
                                                                    '—',
                                                            },
                                                            {
                                                                label: 'Recorded by',
                                                                value:
                                                                    x.by ?? '—',
                                                            },
                                                            {
                                                                label: 'Effect check',
                                                                value: x.effect_recorded
                                                                    ? 'Effect recorded'
                                                                    : x.check_at
                                                                      ? `Check by ${x.check_at} NZ time`
                                                                      : '—',
                                                            },
                                                        ]}
                                                    />
                                                </>
                                            }
                                        />
                                    </li>
                                );
                            })}
                        </ul>
                        <Card className="hidden gap-0 divide-y p-0 md:flex">
                            {shownRecorded.map((x) => {
                                const p = nameOf(clients, x.client_id, '');
                                const confirmation = secondPersonDisplay(x);
                                return (
                                    <div
                                        key={x.id}
                                        className="flex flex-wrap items-center justify-between gap-3 px-4 py-3"
                                    >
                                        <span className="flex items-center gap-3">
                                            <PersonDisc
                                                name={p.legal}
                                                size={30}
                                            />
                                            <span>
                                                <span className="block text-[13px] font-semibold">
                                                    {p.preferred} ·{' '}
                                                    {x.medication_name}
                                                    {x.dose_given
                                                        ? ` · ${x.dose_given}`
                                                        : ''}
                                                </span>
                                                <span className="block text-[12px] text-muted-foreground">
                                                    {[
                                                        x.time,
                                                        x.reason
                                                            ? `for ${x.reason.toLowerCase()}`
                                                            : null,
                                                        x.by,
                                                        x.check_at &&
                                                        !x.effect_recorded
                                                            ? `effect check by ${x.check_at}`
                                                            : null,
                                                        x.effect_recorded
                                                            ? 'effect recorded'
                                                            : null,
                                                    ]
                                                        .filter(Boolean)
                                                        .join(' · ')}
                                                </span>
                                                {confirmation && (
                                                    <span className="text-caption mt-1 block">
                                                        <StatusBadge
                                                            variant={
                                                                confirmation.tone
                                                            }
                                                        >
                                                            {confirmation.label}
                                                        </StatusBadge>{' '}
                                                        {confirmation.detail}
                                                    </span>
                                                )}
                                            </span>
                                        </span>
                                        <StatusBadge
                                            variant={
                                                x.status === 'given'
                                                    ? 'success'
                                                    : 'warning'
                                            }
                                        >
                                            {x.status === 'given'
                                                ? 'Given'
                                                : x.status}
                                        </StatusBadge>
                                    </div>
                                );
                            })}
                        </Card>
                    </>
                )}
            </section>
        </>
    );
}

/* ───────────── Follow-ups ───────────── */
type FollowUpRow =
    | { kind: 'refusal'; f: RefusalFollowUp }
    | { kind: 'effect'; f: PrnFollowUp };

export function FollowUpsView({
    refusals,
    effects,
    clients,
    nowIso,
    canRecordRefusal,
    canRecordEffect,
    onReoffer,
    onEffect,
    chartFor,
    onContext,
}: {
    refusals: RefusalFollowUp[];
    effects: PrnFollowUp[];
    clients: Map<number, ClientInfo>;
    nowIso: string;
    canRecordRefusal: (f: RefusalFollowUp) => boolean;
    canRecordEffect: (f: PrnFollowUp) => boolean;
    onReoffer: (f: RefusalFollowUp) => void;
    onEffect: (f: PrnFollowUp) => void;
    /** Opens the person's medication record, or null when this worker can't. */
    chartFor: (clientId: number) => (() => void) | null;
    onContext: Ctx;
}) {
    const now = new Date(nowIso).getTime();
    const rows: FollowUpRow[] = [
        ...refusals.map((f) => ({ kind: 'refusal' as const, f })),
        ...effects.map((f) => ({ kind: 'effect' as const, f })),
    ].sort((a, b) => dueOf(a).localeCompare(dueOf(b)));
    const overdue = (r: FollowUpRow) =>
        r.kind === 'refusal'
            ? r.f.overdue
            : !!r.f.check_due_at && new Date(r.f.check_due_at).getTime() < now;
    const person = (r: FollowUpRow) =>
        r.kind === 'refusal'
            ? r.f.preferred
            : (clients.get(r.f.client_id)?.preferred ??
              clients.get(r.f.client_id)?.name ??
              'Client');
    const legal = (r: FollowUpRow) =>
        clients.get(r.f.client_id)?.name ?? person(r);
    const act = (r: FollowUpRow) =>
        r.kind === 'refusal'
            ? canRecordRefusal(r.f)
                ? () => onReoffer(r.f)
                : null
            : canRecordEffect(r.f)
              ? () => onEffect(r.f)
              : null;
    const menu = (r: FollowUpRow): MenuItem[] =>
        compactMenu([
            act(r) && {
                label:
                    r.kind === 'refusal'
                        ? 'Record re-offer'
                        : 'Record whether it helped',
                icon: r.kind === 'refusal' ? Repeat : HeartPulse,
                onClick: act(r)!,
            },
            { separator: true },
            chartFor(r.f.client_id) && {
                label: `Open ${person(r)}’s medication record`,
                icon: ClipboardList,
                onClick: chartFor(r.f.client_id)!,
            },
        ]);
    if (rows.length === 0)
        return (
            <Card className="p-2">
                <EmptyState
                    icon={HeartPulse}
                    title="No follow-ups open"
                    description="A refused dose or an as-needed dose adds one here, owned by whoever recorded it, until its result is recorded."
                />
            </Card>
        );
    return (
        <section className="flex flex-col gap-2.5" aria-label="Follow-ups">
            <ListCaption
                className="[&_h2]:break-words [&_h2]:whitespace-normal [&>div:first-child]:flex-wrap"
                title="Follow-ups for people on your shift"
                caption={`${rows.length} open · ${rows.filter(overdue).length} overdue`}
            />
            <ul
                className="grid min-w-0 gap-5 md:hidden"
                aria-label="Medication follow-up cards"
            >
                {rows.map((r) => {
                    const action = act(r);
                    const name = nameOf(clients, r.f.client_id, person(r));
                    const dueAt =
                        r.kind === 'refusal' ? r.f.due_at : r.f.check_due_at;
                    const dueLabel =
                        r.kind === 'refusal' ? r.f.due_time : r.f.check_at;
                    return (
                        <li
                            key={`${r.kind}-${r.kind === 'refusal' ? r.f.id : r.f.administration_id}`}
                            className="min-w-0"
                        >
                            <EntityCard
                                meridian={overdue(r) ? 'critical' : 'neutral'}
                                mark={<PersonDisc name={legal(r)} size={40} />}
                                name={`${name.preferred} ${name.surname}`.trim()}
                                className="min-w-0 [&_h3]:break-words [&_h3]:whitespace-normal"
                                actions={menu(r)}
                                onOpen={action ?? undefined}
                                onContextMenu={(e) =>
                                    onContext(
                                        e,
                                        `${person(r)} · follow-up`,
                                        menu(r),
                                    )
                                }
                                chips={
                                    <>
                                        {overdue(r) ? (
                                            <StatusBadge variant="critical">
                                                Overdue
                                            </StatusBadge>
                                        ) : (
                                            <StatusBadge variant="info">
                                                Open
                                            </StatusBadge>
                                        )}
                                        <MobileMedsFacts
                                            facts={[
                                                {
                                                    label: 'Follow-up',
                                                    value:
                                                        r.kind === 'refusal'
                                                            ? 'Refused dose'
                                                            : 'Did the as-needed dose help?',
                                                },
                                                {
                                                    label: 'Medicine',
                                                    value:
                                                        r.f.medication_name ??
                                                        '—',
                                                },
                                                ...(r.kind === 'refusal'
                                                    ? [
                                                          {
                                                              label: 'Refused',
                                                              value: r.f
                                                                  .refused_time
                                                                  ? `${r.f.refused_time} NZ time`
                                                                  : '—',
                                                          },
                                                          {
                                                              label: 'Next step',
                                                              value: 'Offer again, or record why not.',
                                                          },
                                                      ]
                                                    : [
                                                          {
                                                              label: 'Dose given',
                                                              value:
                                                                  r.f
                                                                      .dose_given ??
                                                                  '—',
                                                          },
                                                          {
                                                              label: 'Given',
                                                              value: r.f
                                                                  .given_at ? (
                                                                  <time
                                                                      dateTime={
                                                                          r.f
                                                                              .given_at
                                                                      }
                                                                  >
                                                                      {formatDateTime(
                                                                          r.f
                                                                              .given_at,
                                                                      )}{' '}
                                                                      NZ time
                                                                  </time>
                                                              ) : r.f
                                                                    .given_time ? (
                                                                  `${r.f.given_time} NZ time`
                                                              ) : (
                                                                  '—'
                                                              ),
                                                          },
                                                      ]),
                                                {
                                                    label: 'Due',
                                                    value: dueAt ? (
                                                        <time dateTime={dueAt}>
                                                            {formatDateTime(
                                                                dueAt,
                                                            )}{' '}
                                                            NZ time
                                                        </time>
                                                    ) : dueLabel ? (
                                                        `${dueLabel} NZ time`
                                                    ) : (
                                                        'Check time not set'
                                                    ),
                                                },
                                                {
                                                    label: 'Owner',
                                                    value:
                                                        r.kind === 'refusal'
                                                            ? (r.f.owner ?? '—')
                                                            : (r.f.by ?? '—'),
                                                },
                                            ]}
                                        />
                                    </>
                                }
                                alerts={
                                    action ? (
                                        <Button
                                            variant="outline"
                                            className="frontline-tap h-auto max-w-full whitespace-normal"
                                            onClick={(e) => {
                                                e.stopPropagation();
                                                action();
                                            }}
                                        >
                                            {r.kind === 'refusal'
                                                ? 'Record re-offer'
                                                : 'Record whether it helped'}
                                        </Button>
                                    ) : undefined
                                }
                            />
                        </li>
                    );
                })}
            </ul>
            <div className="hidden min-w-0 md:block">
                <EntityTable<FollowUpRow>
                    rows={rows}
                    rowKey={(r) =>
                        `${r.kind}-${r.kind === 'refusal' ? r.f.id : r.f.administration_id}`
                    }
                    rowHeight="content"
                    minWidth={900}
                    identityLabel="Person"
                    identityWidth="1fr"
                    identity={(r) => ({
                        mark: <PersonDisc name={legal(r)} size={30} />,
                        name: person(r),
                    })}
                    columns={[
                        {
                            key: 'what',
                            label: 'Follow-up',
                            width: '1.6fr',
                            cell: (r) => (
                                <span className="flex flex-col gap-1">
                                    <span className="text-[13px] font-semibold">
                                        {r.kind === 'refusal'
                                            ? `Refused: ${r.f.medication_name ?? 'medicine'}`
                                            : `Did it help? ${r.f.medication_name ?? 'As-needed dose'}`}
                                    </span>
                                    <span className="text-[12px] text-muted-foreground">
                                        {r.kind === 'refusal'
                                            ? `Refused ${r.f.refused_time ?? ''} · offer again, or record why not`
                                            : `Given ${r.f.given_time ?? ''}${r.f.dose_given ? ` · ${r.f.dose_given}` : ''}${r.f.by ? ` · ${r.f.by}` : ''}`}
                                    </span>
                                </span>
                            ),
                        },
                        {
                            key: 'due',
                            label: 'Due',
                            width: '0.8fr',
                            cell: (r) => (
                                <span className="text-[12.5px] font-semibold tabular-nums">
                                    {r.kind === 'refusal'
                                        ? (r.f.due_time ?? 'Check time not set')
                                        : (r.f.check_at ??
                                          'Check time not set')}
                                </span>
                            ),
                        },
                        {
                            key: 'owner',
                            label: 'Owner',
                            width: '1fr',
                            cell: (r) => (
                                <span className="text-[12.5px]">
                                    {r.kind === 'refusal'
                                        ? (r.f.owner ?? '—')
                                        : (r.f.by ?? '—')}
                                </span>
                            ),
                        },
                        {
                            key: 'st',
                            label: 'State',
                            width: '0.9fr',
                            cell: (r) =>
                                overdue(r) ? (
                                    <StatusBadge variant="critical">
                                        Overdue
                                    </StatusBadge>
                                ) : (
                                    <StatusBadge variant="info">
                                        Open
                                    </StatusBadge>
                                ),
                        },
                        {
                            key: 'act',
                            label: '',
                            width: '190px',
                            align: 'right',
                            cell: (r) => {
                                const fn = act(r);
                                return fn ? (
                                    <Button
                                        variant="outline"
                                        className="frontline-tap"
                                        onClick={(e) => {
                                            e.stopPropagation();
                                            fn();
                                        }}
                                    >
                                        {r.kind === 'refusal' ? (
                                            <>
                                                <Repeat
                                                    className="size-4"
                                                    aria-hidden="true"
                                                />{' '}
                                                Record re-offer
                                            </>
                                        ) : (
                                            'Record whether it helped'
                                        )}
                                    </Button>
                                ) : null;
                            },
                        },
                    ]}
                    actionsFor={menu}
                    onOpen={(r) => act(r)?.()}
                    onRowContextMenu={(e, r) =>
                        onContext(e, `${person(r)} · follow-up`, menu(r))
                    }
                />
            </div>
            <p className="text-caption">
                Times in NZ time. An as-needed check uses the time chosen when
                the dose was recorded. If no check time is set, arrange it with
                the medication lead.
            </p>
        </section>
    );
}

function dueOf(r: FollowUpRow): string {
    return (r.kind === 'refusal' ? r.f.due_at : r.f.check_due_at) ?? '9999';
}
