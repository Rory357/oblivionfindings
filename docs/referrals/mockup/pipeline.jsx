import { EntityCard } from '@/components/lists/entity-card';
import {
    EntityChip,
    EntityStatusChip,
    PersonDisc,
} from '@/components/lists/entity-cells';
import { Button } from '@/components/ui/button';
import {
    ArrowLeft,
    ArrowRight,
    CalendarDays,
    ClipboardCheck,
    ClipboardList,
    Clock,
    HeartHandshake,
    Inbox,
    PauseCircle,
    Wallet,
    Workflow,
} from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { usePrototype } from './context';
import { TODAY, date } from './data';
import { Select } from './ui';

const stagesInfo = {
    New: { icon: Inbox, hint: 'Make a safe first connection' },
    Triage: { icon: ClipboardList, hint: 'Confirm needs and ownership' },
    Assessment: { icon: ClipboardCheck, hint: 'Understand goals and support' },
    'Funding assistance': {
        icon: Wallet,
        hint: 'Prepare, apply and follow up',
    },
    'Ready for handover': {
        icon: HeartHandshake,
        hint: 'Review the move into supported living',
    },
    'On hold': { icon: PauseCircle, hint: 'Keep a reason and review date' },
    Converted: {
        icon: HeartHandshake,
        hint: 'Continue through the linked person',
    },
    Closed: { icon: ClipboardCheck, hint: 'Reason and history preserved' },
};
const motion = () =>
    matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth';
const savedStage = () => {
    try {
        return sessionStorage.getItem('referrals-pipeline-stage');
    } catch {
        return null;
    }
};

export function ReferralPipeline({ records, actions, open, contextMenu }) {
    const { data } = usePrototype();
    const board = useRef(null),
        overview = useRef(null),
        lanes = useRef({}),
        choices = useRef({});
    const stages = [
        ...new Set([...data.config.stages, ...records.map((r) => r.stage)]),
    ].filter(
        (s) =>
            !['Closed', 'Converted'].includes(s) ||
            records.some((r) => r.stage === s),
    );
    const [selected, setSelected] = useState(
        () =>
            savedStage() ||
            stages.find((s) => records.some((r) => r.stage === s)) ||
            stages[0],
    );
    const [columns, setColumns] = useState(3),
        [start, setStart] = useState(0),
        [phone, setPhone] = useState(
            () => matchMedia('(max-width:640px)').matches,
        );
    const current = stages.includes(selected) ? selected : stages[0];
    const currentIndex = Math.max(0, stages.indexOf(current));
    const visibleCount = Math.min(columns, stages.length);
    const lastStart = Math.max(0, stages.length - visibleCount);
    const rangeStart = Math.min(start, lastStart);
    const pastDue = (r) =>
        !r.nextDone &&
        r.due < TODAY &&
        !['Closed', 'Converted'].includes(r.stage);

    useEffect(() => {
        const observer = new ResizeObserver(([entry]) => {
            setColumns(
                Math.max(
                    1,
                    Math.min(
                        3,
                        Math.floor((entry.contentRect.width + 20) / 320),
                    ),
                ),
            );
            setPhone(matchMedia('(max-width:640px)').matches);
        });
        observer.observe(board.current);
        return () => observer.disconnect();
    }, []);
    useEffect(() => {
        try {
            sessionStorage.setItem('referrals-pipeline-stage', current);
        } catch {
            /* Selection remains usable without browser storage. */
        }
        const frame = requestAnimationFrame(() => {
            const lane = lanes.current[current];
            if (!phone && lane && board.current)
                board.current.scrollTo({
                    left: lane.offsetLeft,
                    behavior: 'auto',
                });
            const choice = choices.current[current];
            if (phone && choice && overview.current)
                overview.current.scrollTo({
                    left: choice.offsetLeft,
                    behavior: motion(),
                });
        });
        return () => cancelAnimationFrame(frame);
    }, [current, columns, phone]);
    const focus = (stage) => {
        setSelected(stage);
        if (!phone && board.current && lanes.current[stage])
            board.current.scrollTo({
                left: lanes.current[stage].offsetLeft,
                behavior: motion(),
            });
    };
    const move = (direction) =>
        focus(
            stages[
                phone
                    ? Math.max(
                          0,
                          Math.min(stages.length - 1, currentIndex + direction),
                      )
                    : Math.max(
                          0,
                          Math.min(
                              lastStart,
                              rangeStart + direction * visibleCount,
                          ),
                      )
            ],
        );
    const inView = (i) =>
        phone
            ? i === currentIndex
            : i >= rangeStart && i < rangeStart + visibleCount;
    const onScroll = () => {
        if (!phone && board.current) {
            const first = lanes.current[stages[0]];
            if (first)
                setStart(
                    Math.round(
                        board.current.scrollLeft /
                            (first.getBoundingClientRect().width + 20),
                    ),
                );
        }
    };
    return (
        <div className="referral-pipeline">
            <div
                ref={overview}
                className="pipeline-stage-overview"
                role="group"
                aria-label="Pipeline stage overview"
            >
                {stages.map((stage, i) => {
                    const rows = records.filter((r) => r.stage === stage),
                        late = rows.filter(pastDue).length;
                    return (
                        <Button
                            key={stage}
                            ref={(el) => {
                                choices.current[stage] = el;
                            }}
                            variant="ghost"
                            className="pipeline-stage-choice frontline-tap"
                            aria-label={`${stage} ${rows.length}`}
                            aria-pressed={inView(i)}
                            aria-controls={'referral-lane-' + i}
                            onClick={() => focus(stage)}
                        >
                            <span className="pipeline-stage-label">
                                {stage}
                            </span>
                            <span className="pipeline-stage-summary">
                                <span className="pipeline-stage-total">
                                    {rows.length}
                                </span>
                                <span className="text-caption">
                                    {rows.length === 1
                                        ? 'referral'
                                        : 'referrals'}
                                </span>
                                {late > 0 && (
                                    <span className="pipeline-overdue-mark">
                                        <EntityStatusChip
                                            variant="warning"
                                            icon={Clock}
                                        >
                                            {late} overdue
                                        </EntityStatusChip>
                                    </span>
                                )}
                            </span>
                        </Button>
                    );
                })}
            </div>
            <div className="pipeline-caption">
                <div className="pipeline-mobile-stage">
                    <span className="text-caption text-muted-foreground">
                        Pipeline stage
                    </span>
                    <Select
                        label="Pipeline stage"
                        value={current}
                        onChange={focus}
                        options={stages.map((stage) => ({
                            value: stage,
                            label:
                                stage +
                                ' · ' +
                                records.filter((r) => r.stage === stage).length,
                        }))}
                    />
                </div>
                <div className="pipeline-position" aria-live="polite">
                    <span className="text-section-title">
                        {phone
                            ? current
                            : `${stages[rangeStart]} — ${stages[rangeStart + visibleCount - 1]}`}
                    </span>
                    <span className="text-caption text-muted-foreground">
                        {phone
                            ? `Stage ${currentIndex + 1} of ${stages.length}`
                            : `Stages ${rangeStart + 1}–${rangeStart + visibleCount} of ${stages.length}`}
                    </span>
                </div>
                <div className="pipeline-scroll-actions">
                    <Button
                        variant="outline"
                        size="icon"
                        className="frontline-hit"
                        aria-label="Previous pipeline stages"
                        disabled={phone ? currentIndex === 0 : rangeStart === 0}
                        onClick={() => move(-1)}
                    >
                        <ArrowLeft />
                    </Button>
                    <Button
                        variant="outline"
                        size="icon"
                        className="frontline-hit"
                        aria-label="Next pipeline stages"
                        disabled={
                            phone
                                ? currentIndex === stages.length - 1
                                : rangeStart === lastStart
                        }
                        onClick={() => move(1)}
                    >
                        <ArrowRight />
                    </Button>
                </div>
            </div>
            <div
                ref={board}
                className="pipeline-board"
                role="region"
                aria-label="Referral pipeline board"
                tabIndex={0}
                style={{ '--pipeline-columns': visibleCount }}
                onScroll={onScroll}
                onKeyDown={(e) => {
                    if (
                        e.target === e.currentTarget &&
                        ['ArrowLeft', 'ArrowRight'].includes(e.key)
                    ) {
                        e.preventDefault();
                        move(e.key === 'ArrowRight' ? 1 : -1);
                    }
                }}
            >
                {stages.map((stage, i) => {
                    const rows = records.filter((r) => r.stage === stage),
                        info = stagesInfo[stage] || {
                            icon: Workflow,
                            hint: 'Your configured workflow stage',
                        },
                        Icon = info.icon;
                    return (
                        <section
                            key={stage}
                            id={'referral-lane-' + i}
                            ref={(el) => {
                                lanes.current[stage] = el;
                            }}
                            data-focused={current === stage}
                            className="pipeline-lane"
                            aria-labelledby={'referral-lane-heading-' + i}
                        >
                            <header className="pipeline-lane-heading">
                                <div className="pipeline-lane-identity">
                                    <span className="pipeline-lane-icon">
                                        <Icon aria-hidden="true" />
                                    </span>
                                    <h2
                                        id={'referral-lane-heading-' + i}
                                        className="text-section-title"
                                    >
                                        {stage}
                                    </h2>
                                    <span className="pipeline-lane-count text-caption">
                                        {rows.length}
                                    </span>
                                </div>
                                <p className="text-caption">
                                    {data.config.stages.includes(stage)
                                        ? info.hint
                                        : 'Historical stage · existing referrals remain visible'}
                                </p>
                            </header>
                            <div className="pipeline-lane-cards">
                                {rows.length ? (
                                    rows.map((r) => (
                                        <EntityCard
                                            key={r.id}
                                            name={r.name}
                                            href={'#referral=' + r.id}
                                            linkLabel={`Open ${r.name}`}
                                            mark={
                                                <PersonDisc
                                                    name={r.name}
                                                    size={40}
                                                />
                                            }
                                            subline={`${r.id} · ${r.source}`}
                                            meridian={
                                                pastDue(r)
                                                    ? 'warning'
                                                    : 'neutral'
                                            }
                                            actions={actions(r)}
                                            onOpen={() => open(r)}
                                            onContextMenu={(e) =>
                                                contextMenu(e, r)
                                            }
                                            openLabel="Review"
                                            chips={
                                                <>
                                                    <EntityChip>
                                                        {r.site}
                                                    </EntityChip>
                                                    {pastDue(r) ? (
                                                        <EntityStatusChip
                                                            variant="warning"
                                                            icon={Clock}
                                                        >
                                                            Overdue ·{' '}
                                                            {date(r.due)}
                                                        </EntityStatusChip>
                                                    ) : (
                                                        <EntityChip
                                                            icon={CalendarDays}
                                                        >
                                                            {r.due === TODAY &&
                                                            !r.nextDone
                                                                ? 'Due today'
                                                                : date(r.due)}
                                                        </EntityChip>
                                                    )}
                                                </>
                                            }
                                            alerts={
                                                <div className="referral-next-step">
                                                    <span className="text-caption text-muted-foreground">
                                                        {r.nextDone ||
                                                        [
                                                            'Closed',
                                                            'Converted',
                                                        ].includes(r.stage)
                                                            ? 'Last recorded action'
                                                            : 'Next step'}
                                                    </span>
                                                    <p>{r.next}</p>
                                                </div>
                                            }
                                            footer={{
                                                personName:
                                                    r.owner === 'Intake team'
                                                        ? null
                                                        : r.owner,
                                                primary: r.owner,
                                                secondary:
                                                    r.owner === 'Intake team'
                                                        ? 'Coordinator not yet assigned'
                                                        : undefined,
                                            }}
                                        />
                                    ))
                                ) : (
                                    <div className="pipeline-lane-empty">
                                        <Icon aria-hidden="true" />
                                        <p className="text-subtle">
                                            No referrals here
                                        </p>
                                        <span className="text-caption">
                                            Referrals appear here after a
                                            reviewed stage change.
                                        </span>
                                    </div>
                                )}
                            </div>
                        </section>
                    );
                })}
            </div>
        </div>
    );
}
