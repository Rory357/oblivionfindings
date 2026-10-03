import { EntityCard } from '@/components/lists/entity-card';
import { PersonDisc } from '@/components/lists/entity-cells';
import { compactMenu, type MenuItem } from '@/components/lists/entity-menu';
import { EntityTable } from '@/components/lists/entity-table';
import { ListCaption } from '@/components/lists/list-caption';
import { Card } from '@/components/ui/card';
import { EmptyState } from '@/components/ui/empty-state';
import { LaravelPagination } from '@/components/ui/laravel-pagination';
import { LoadingState } from '@/components/ui/loading-state';
import { StatusBadge } from '@/components/ui/status-badge';
import { formatDateTime } from '@/lib/datetime';
import { Activity, ClipboardList, Flag } from 'lucide-react';
import type { MouseEvent } from 'react';
import { MobileMedsFacts } from './_mobile-facts';
import type { ActivityPage, ActivityRow } from './types';

/** Server-paginated activity, using the same rows and actions on both surfaces. */
export function ActivityView({
    page,
    range,
    houseLabel,
    canReportError,
    chartFor,
    onReportError,
    onContext,
    loading = false,
}: {
    page: ActivityPage | undefined;
    range: string;
    houseLabel: string | null;
    canReportError: boolean;
    /** Opens the person's medication record, or null when this worker can't. */
    chartFor: (clientId: number) => (() => void) | null;
    onReportError: (clientId: number) => void;
    onContext: (e: MouseEvent, title: string, items: MenuItem[]) => void;
    /** Optional pending state for subsequent reloads; missing initial data is loading. */
    loading?: boolean;
}) {
    const rows = page?.data ?? [];
    const pending = loading || page === undefined;
    const menu = (a: ActivityRow): MenuItem[] =>
        compactMenu([
            chartFor(a.client_id) && {
                label: `Open ${a.preferred}’s medication record`,
                icon: ClipboardList,
                onClick: chartFor(a.client_id)!,
            },
            canReportError && {
                label: 'Report a medication error',
                icon: Flag,
                onClick: () => onReportError(a.client_id),
            },
        ]);
    const outcome = (a: ActivityRow) => (
        <StatusBadge
            variant={
                a.status === 'given'
                    ? 'success'
                    : a.status === 'missed'
                      ? 'critical'
                      : 'warning'
            }
        >
            {a.outcome}
        </StatusBadge>
    );
    return (
        <section
            className="flex flex-col gap-2.5"
            aria-label="Activity"
            aria-busy={pending}
        >
            <ListCaption
                className="[&>div:first-child]:flex-wrap [&_h2]:break-words [&_h2]:whitespace-normal"
                title={`${range === 'today' ? 'Recorded today' : 'Recorded in the last 24 hours'} ${houseLabel ? `at ${houseLabel}` : 'for people on your shift'}`}
                caption={
                    pending
                        ? 'Loading…'
                        : `${rows.length} of ${page?.total ?? 0} shown`
                }
            />
            {pending ? (
                <Card className="p-2">
                    <div role="status" aria-live="polite">
                        <LoadingState message="Loading medication activity…" />
                    </div>
                </Card>
            ) : rows.length === 0 ? (
                <Card className="p-2">
                    <EmptyState
                        icon={Activity}
                        title="Nothing recorded"
                        description="Nothing matches this range, outcome or search."
                    />
                </Card>
            ) : (
                <>
                    <ul
                        className="grid min-w-0 gap-5 md:hidden"
                        aria-label="Recorded medication cards"
                    >
                        {rows.map((a) => {
                            const person =
                                `${a.preferred} ${a.surname ?? ''}`.trim();
                            const open = chartFor(a.client_id);
                            return (
                                <li key={a.id} className="min-w-0">
                                    <EntityCard
                                        meridian="neutral"
                                        mark={
                                            <PersonDisc
                                                name={person}
                                                size={40}
                                            />
                                        }
                                        name={person}
                                        className="min-w-0 [&_h3]:break-words [&_h3]:whitespace-normal"
                                        actions={menu(a)}
                                        onOpen={open ?? undefined}
                                        onContextMenu={(e) =>
                                            onContext(
                                                e,
                                                `${a.preferred} · ${a.medication_name}`,
                                                menu(a),
                                            )
                                        }
                                        chips={
                                            <>
                                                {outcome(a)}
                                                <MobileMedsFacts
                                                    facts={[
                                                        {
                                                            label: 'Medicine',
                                                            value:
                                                                a.medication_name ??
                                                                '—',
                                                        },
                                                        {
                                                            label: 'When',
                                                            value: (
                                                                <time
                                                                    dateTime={
                                                                        a.at
                                                                    }
                                                                >
                                                                    {formatDateTime(
                                                                        a.at,
                                                                    )}{' '}
                                                                    NZ time
                                                                </time>
                                                            ),
                                                        },
                                                        {
                                                            label: 'Recorded by',
                                                            value: a.by ?? '—',
                                                        },
                                                        {
                                                            label: 'Detail',
                                                            value:
                                                                a.detail ?? '—',
                                                        },
                                                    ]}
                                                />
                                            </>
                                        }
                                        footer={
                                            open
                                                ? {
                                                      primary:
                                                          'Medication record',
                                                  }
                                                : undefined
                                        }
                                    />
                                </li>
                            );
                        })}
                    </ul>
                    <div className="hidden min-w-0 md:block">
                        <EntityTable<ActivityRow>
                            rows={rows}
                            rowKey={(a) => a.id}
                            minWidth={900}
                            rowHeight="content"
                            identityLabel="Person"
                            identity={(a) => ({
                                mark: (
                                    <PersonDisc
                                        name={`${a.preferred} ${a.surname ?? ''}`.trim()}
                                        size={30}
                                    />
                                ),
                                name: a.preferred,
                                subline: a.surname ?? undefined,
                            })}
                            columns={[
                                {
                                    key: 'when',
                                    label: 'When',
                                    width: '0.9fr',
                                    cell: (a) => (
                                        <span className="text-[12.5px] tabular-nums">
                                            {a.time}
                                            {a.day ? ` ${a.day}` : ''}
                                        </span>
                                    ),
                                },
                                {
                                    key: 'med',
                                    label: 'Medicine',
                                    width: '1.3fr',
                                    cell: (a) => (
                                        <span className="text-[12.5px] font-semibold">
                                            {a.medication_name}
                                        </span>
                                    ),
                                },
                                {
                                    key: 'out',
                                    label: 'Outcome',
                                    width: '1.1fr',
                                    cell: outcome,
                                },
                                {
                                    key: 'by',
                                    label: 'By',
                                    width: '1fr',
                                    cell: (a) => (
                                        <span className="flex items-center gap-2 text-[12.5px]">
                                            {a.by ? (
                                                <PersonDisc
                                                    name={a.by}
                                                    size={22}
                                                />
                                            ) : null}
                                            {a.by ?? '—'}
                                        </span>
                                    ),
                                },
                                {
                                    key: 'd',
                                    label: 'Detail',
                                    width: '1.6fr',
                                    cell: (a) => (
                                        <span className="text-[12px] text-muted-foreground">
                                            {a.detail ?? ''}
                                        </span>
                                    ),
                                },
                            ]}
                            actionsFor={menu}
                            onOpen={(a) => chartFor(a.client_id)?.()}
                            onRowContextMenu={(e, a) =>
                                onContext(
                                    e,
                                    `${a.preferred} · ${a.medication_name}`,
                                    menu(a),
                                )
                            }
                        />
                    </div>
                </>
            )}
            {!pending && page ? (
                <LaravelPagination
                    links={page.links}
                    lastPage={page.last_page}
                    preserveScroll
                />
            ) : null}
            <p className="text-caption">
                Times in NZ time. 10 per page. A row opens the person’s
                medication record when you can view it.
            </p>
        </section>
    );
}
