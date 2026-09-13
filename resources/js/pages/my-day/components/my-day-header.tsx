import {
    CalendarDays,
    Clock3,
    MessagesSquare,
    Plus,
    Users,
} from 'lucide-react';

import {
    PageHeader,
    PageHeaderFilterButton,
    PageHeaderFilterSelect,
    PageHeaderMeterAvatars,
    PageHeaderMeterBar,
    PageHeaderMeterBig,
    PageHeaderMeterBlock,
    PageHeaderMeterCaption,
    PageHeaderPrimaryButton,
    PageHeaderRail,
    PageHeaderSearch,
    PageHeaderStatusChip,
} from '@/components/page/page-header';

import type { MyDayResident } from '../lib/types';

export type MyDayView = 'today' | 'handover' | 'shift';
export type WorkFilter = 'all' | 'tasks' | 'meds' | 'attention';

interface Props {
    unavailable?: string[];
    dateLabel: string;
    siteName: string;
    shiftLabel: string;
    clockedIn: boolean;
    hasShift: boolean;
    onBreak: boolean;
    residents: MyDayResident[];
    person: 'all' | number;
    onPerson: (person: 'all' | number) => void;
    search: string;
    onSearch: (value: string) => void;
    workFilter: WorkFilter;
    onWorkFilter: (value: WorkFilter) => void;
    view: MyDayView;
    onView: (view: MyDayView) => void;
    taskTotal: number;
    taskDone: number;
    medTotal: number;
    medRecorded: number;
    attention: number;
    unreadHandover: boolean;
    canAdd: boolean;
    onAdd: () => void;
}

export function MyDayHeader(p: Props) {
    const tasksUnavailable = p.unavailable?.some((section) =>
        ['Shifts', 'Current shift'].includes(section),
    );
    const medsUnavailable = p.unavailable?.includes('Medications');
    const showWork = (filter: WorkFilter) => {
        p.onView('today');
        p.onWorkFilter(filter);
        p.onSearch('');
    };
    return (
        <PageHeader
            icon={CalendarDays}
            title="My Day"
            titleChip={
                <PageHeaderStatusChip
                    variant={p.clockedIn ? 'success' : 'neutral'}
                >
                    {p.onBreak
                        ? 'On a break'
                        : p.clockedIn
                          ? p.hasShift
                              ? 'On shift'
                              : 'Clocked in'
                          : 'Not clocked in'}
                </PageHeaderStatusChip>
            }
            subline={[p.dateLabel, p.siteName, p.shiftLabel]
                .filter(Boolean)
                .join(' · ')}
            actions={
                <>
                    <PageHeaderSearch
                        value={p.search}
                        onChange={(value) => {
                            p.onSearch(value);
                            p.onView('today');
                        }}
                        placeholder="Find in today's work…"
                    />
                    {p.canAdd && (
                        <PageHeaderPrimaryButton
                            className="frontline-tap"
                            icon={Plus}
                            onClick={p.onAdd}
                        >
                            Add task
                        </PageHeaderPrimaryButton>
                    )}
                </>
            }
            meters={
                <>
                    <PageHeaderMeterBlock
                        label="Needs attention"
                        tone={p.attention ? 'warning' : 'brand'}
                        onClick={() => {
                            p.onPerson('all');
                            showWork('attention');
                        }}
                        ariaLabel={
                            p.unavailable?.length
                                ? 'Review attention items; some information is unavailable'
                                : `Show ${p.attention} ${p.attention === 1 ? 'item' : 'items'} needing attention across this shift`
                        }
                    >
                        <PageHeaderMeterBig>
                            {p.unavailable?.length ? '—' : p.attention}
                        </PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            {p.unavailable?.length
                                ? 'Some information unavailable'
                                : 'Across this shift'}
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                    <PageHeaderMeterBlock
                        label="Today’s tasks"
                        onClick={() => showWork('tasks')}
                    >
                        <PageHeaderMeterBig>
                            {tasksUnavailable ? '—' : p.taskDone}
                            <span className="text-base font-medium">
                                {' '}
                                {tasksUnavailable ? '' : `/ ${p.taskTotal}`}
                            </span>
                        </PageHeaderMeterBig>
                        <PageHeaderMeterBar
                            percent={
                                p.taskTotal
                                    ? (p.taskDone / p.taskTotal) * 100
                                    : 0
                            }
                        />
                        <PageHeaderMeterCaption>
                            {tasksUnavailable
                                ? 'Refresh to check tasks'
                                : p.taskTotal
                                  ? `${p.taskTotal - p.taskDone} still to do`
                                  : p.hasShift
                                    ? 'No tasks added'
                                    : 'No rostered work to show'}
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                    <PageHeaderMeterBlock
                        label="Meds today"
                        onClick={() => showWork('meds')}
                    >
                        <PageHeaderMeterBig>
                            {medsUnavailable ? '—' : p.medRecorded}
                            <span className="text-base font-medium">
                                {' '}
                                {medsUnavailable ? '' : `/ ${p.medTotal}`}
                            </span>
                        </PageHeaderMeterBig>
                        <PageHeaderMeterBar
                            percent={
                                p.medTotal
                                    ? (p.medRecorded / p.medTotal) * 100
                                    : 0
                            }
                        />
                        <PageHeaderMeterCaption>
                            {medsUnavailable
                                ? 'Refresh to check doses'
                                : p.hasShift
                                  ? 'Given or outcome recorded'
                                  : 'No rostered work to show'}
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                    <PageHeaderMeterBlock
                        label="People today"
                        value={tasksUnavailable ? '—' : p.residents.length}
                        onClick={() => {
                            p.onPerson('all');
                            showWork('all');
                        }}
                    >
                        <PageHeaderMeterAvatars
                            people={p.residents.slice(0, 6).map((person) => ({
                                ...person,
                                href: `/clients/${person.id}`,
                            }))}
                            overflow={Math.max(0, p.residents.length - 6)}
                        />
                        <PageHeaderMeterCaption>
                            {p.residents.length
                                ? p.residents
                                      .map(
                                          (person) =>
                                              person.first_name || person.name,
                                      )
                                      .join(' · ')
                                : 'Shown for your rostered shift'}
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                </>
            }
            filters={
                p.view === 'today' && p.hasShift ? (
                    <div className="flex w-full flex-wrap items-center justify-between gap-x-5 gap-y-2">
                        {p.residents.length > 1 ? (
                            <div
                                role="group"
                                aria-label="People to show"
                                className="flex flex-wrap items-center gap-2"
                            >
                                <span className="text-sm text-primary-foreground/80">
                                    For
                                </span>
                                {p.residents.length <= 6 ? (
                                    <>
                                        <PageHeaderFilterButton
                                            active={p.person === 'all'}
                                            aria-pressed={p.person === 'all'}
                                            className="frontline-tap px-3 text-sm"
                                            onClick={() => p.onPerson('all')}
                                        >
                                            Everyone
                                        </PageHeaderFilterButton>
                                        {p.residents.map((person) => (
                                            <PageHeaderFilterButton
                                                key={person.id}
                                                active={p.person === person.id}
                                                aria-pressed={
                                                    p.person === person.id
                                                }
                                                className="frontline-tap px-3 text-sm"
                                                onClick={() =>
                                                    p.onPerson(person.id)
                                                }
                                            >
                                                {person.name}
                                            </PageHeaderFilterButton>
                                        ))}
                                    </>
                                ) : (
                                    <div className="[&_button]:min-h-[44px] [&_button]:min-w-[44px] [&_button]:text-sm [&>div]:h-[44px]">
                                        <PageHeaderFilterSelect
                                            icon={Users}
                                            label="Everyone"
                                            value={String(p.person)}
                                            options={[
                                                {
                                                    value: 'all',
                                                    label: 'Everyone',
                                                },
                                                ...p.residents.map(
                                                    (person) => ({
                                                        value: String(
                                                            person.id,
                                                        ),
                                                        label: person.name,
                                                    }),
                                                ),
                                            ]}
                                            onChange={(value) =>
                                                p.onPerson(
                                                    value === 'all'
                                                        ? 'all'
                                                        : Number(value),
                                                )
                                            }
                                        />
                                    </div>
                                )}
                            </div>
                        ) : (
                            <span className="text-sm text-primary-foreground/80">
                                {p.residents[0]?.name ?? 'Your shift’s work'}
                            </span>
                        )}
                        <div
                            role="group"
                            aria-label="Work to show"
                            className="flex flex-wrap items-center gap-2"
                        >
                            <span className="text-sm text-primary-foreground/80">
                                Show
                            </span>
                            {(
                                [
                                    ['all', 'All work'],
                                    ['tasks', 'Support tasks'],
                                    ['meds', 'Meds'],
                                    ...(p.workFilter === 'attention'
                                        ? [['attention', 'Needs attention']]
                                        : []),
                                ] as [WorkFilter, string][]
                            ).map(([value, label]) => (
                                <PageHeaderFilterButton
                                    key={value}
                                    active={p.workFilter === value}
                                    aria-pressed={p.workFilter === value}
                                    className="frontline-tap px-3 text-sm"
                                    onClick={() => showWork(value)}
                                >
                                    {label}
                                </PageHeaderFilterButton>
                            ))}
                        </div>
                    </div>
                ) : (
                    <p className="w-full text-sm text-primary-foreground/80">
                        {p.hasShift
                            ? 'Your shift, handover and follow-up work in one place.'
                            : 'Your own shifts and care work appear here when you are rostered.'}
                    </p>
                )
            }
            rail={
                <PageHeaderRail<MyDayView>
                    items={[
                        { key: 'today', label: 'Today', icon: CalendarDays },
                        {
                            key: 'handover',
                            label: 'Handover',
                            icon: MessagesSquare,
                            ...(p.unreadHandover
                                ? { count: 1, alert: true }
                                : {}),
                        },
                        { key: 'shift', label: 'My shift', icon: Clock3 },
                    ]}
                    value={p.view}
                    onSelect={p.onView}
                    ariaLabel="My Day views"
                />
            }
        />
    );
}
