import { EntityStatusChip, type MenuItem } from '@/components/lists';
import { Button } from '@/components/ui/button';
import { cn } from '@/lib/utils';
import {
    ArrowLeft,
    ArrowRight,
    ClipboardCheck,
    ClipboardList,
    Clock,
    HeartHandshake,
    Inbox,
    PauseCircle,
    Wallet,
    Workflow,
    type LucideIcon,
} from 'lucide-react';
import {
    useEffect,
    useRef,
    useState,
    type CSSProperties,
    type MouseEvent,
} from 'react';
import type { WorkspacePipelineLane, WorkspaceReferral } from '../types';
import { ReferralCard } from './referral-card';

const STAGE_INFO: Record<string, { icon: LucideIcon; hint: string }> = {
    new: { icon: Inbox, hint: 'Make a safe first connection' },
    triage: { icon: ClipboardList, hint: 'Confirm needs and ownership' },
    assessment: {
        icon: ClipboardCheck,
        hint: 'Understand goals and support',
    },
    funding_assistance: {
        icon: Wallet,
        hint: 'Prepare, apply and follow up',
    },
    ready_for_handover: {
        icon: HeartHandshake,
        hint: 'Review the move into supported living',
    },
    on_hold: { icon: PauseCircle, hint: 'Keep a reason and review date' },
    converted: {
        icon: HeartHandshake,
        hint: 'Continue through the linked person',
    },
    closed: { icon: ClipboardCheck, hint: 'Reason and history preserved' },
};
const STORAGE_KEY = 'referrals-pipeline-stage';
const COLUMN_MIN = 320;
const GAP = 20;
/** Whole columns only: n lanes plus (n − 1) 20px gaps fill the board exactly. */
const LANE_WIDTH: CSSProperties = {
    flexBasis:
        'calc((100% - (var(--pipeline-columns) - 1) * 20px) / var(--pipeline-columns))',
};

const readStage = () => {
    try {
        return window.sessionStorage.getItem(STORAGE_KEY);
    } catch {
        return null;
    }
};
const motion = (): ScrollBehavior =>
    window.matchMedia('(prefers-reduced-motion: reduce)').matches
        ? 'auto'
        : 'smooth';

/**
 * Pipeline per the 16 Oct refinement: complete columns fitted to the board
 * width (3 at 1440px, 2 at ~1024px), a stage overview strip, previous/next
 * controls and bounded previews with "View all in {stage}". Stage changes
 * still happen through the reviewed stage-change workflow, never by drag.
 */
export function ReferralPipeline({
    lanes,
    stages,
    actionsFor,
    onContextMenu,
    onViewAll,
}: {
    lanes: WorkspacePipelineLane[];
    stages: { id: string; label: string }[];
    actionsFor: (record: WorkspaceReferral) => MenuItem[];
    onContextMenu: (event: MouseEvent, record: WorkspaceReferral) => void;
    onViewAll: (stage: string) => void;
}) {
    const visible = lanes.filter(
        (lane) =>
            lane.total > 0 || !['closed', 'converted'].includes(lane.stage),
    );
    const board = useRef<HTMLDivElement>(null);
    const laneRefs = useRef<Record<string, HTMLElement | null>>({});
    const [columns, setColumns] = useState(3);
    const [start, setStart] = useState(() => {
        const saved = readStage();
        const index = visible.findIndex((lane) => lane.stage === saved);
        return index >= 0 ? index : 0;
    });
    const count = Math.min(columns, Math.max(1, visible.length));
    const lastStart = Math.max(0, visible.length - count);
    const rangeStart = Math.min(start, lastStart);

    useEffect(() => {
        const element = board.current;
        if (!element || typeof ResizeObserver === 'undefined') return;
        const observer = new ResizeObserver(([entry]) =>
            setColumns(
                Math.max(
                    1,
                    Math.min(
                        3,
                        Math.floor(
                            (entry.contentRect.width + GAP) /
                                (COLUMN_MIN + GAP),
                        ) || 1,
                    ),
                ),
            ),
        );
        observer.observe(element);
        return () => observer.disconnect();
    }, []);
    useEffect(() => {
        const stage = visible[rangeStart]?.stage;
        if (!stage) return;
        try {
            window.sessionStorage.setItem(STORAGE_KEY, stage);
        } catch {
            /* The pipeline works without browser storage. */
        }
        const lane = laneRefs.current[stage];
        if (lane && board.current)
            board.current.scrollTo({ left: lane.offsetLeft, behavior: 'auto' });
        // Scroll only when the visible range or column count changes.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [rangeStart, count]);

    const focus = (index: number) => {
        const next = Math.max(0, Math.min(lastStart, index));
        setStart(next);
        const lane = laneRefs.current[visible[next]?.stage ?? ''];
        if (lane && board.current)
            board.current.scrollTo({
                left: lane.offsetLeft,
                behavior: motion(),
            });
    };
    const onScroll = () => {
        const first = laneRefs.current[visible[0]?.stage ?? ''];
        if (!board.current || !first) return;
        const index = Math.round(
            board.current.scrollLeft /
                (first.getBoundingClientRect().width + GAP),
        );
        if (index !== rangeStart) setStart(index);
    };
    const inView = (index: number) =>
        index >= rangeStart && index < rangeStart + count;
    if (visible.length === 0) return null;

    return (
        <div className="grid min-w-0 gap-5">
            {/* eslint-disable-next-line no-restricted-syntax -- segmented stage strip (mockup .pipeline-stage-overview), not a content card */}
            <div
                role="group"
                aria-label="Pipeline stage overview"
                className="grid [grid-template-columns:repeat(auto-fit,minmax(145px,1fr))] gap-1 rounded-lg border border-border bg-card p-1"
            >
                {visible.map((lane, index) => (
                    <Button
                        key={lane.stage}
                        variant="ghost"
                        aria-pressed={inView(index)}
                        aria-controls={`referral-lane-${lane.stage}`}
                        aria-label={`${lane.label}: ${lane.total} ${lane.total === 1 ? 'referral' : 'referrals'}${lane.overdue ? `, ${lane.overdue} overdue` : ''}`}
                        onClick={() => focus(index)}
                        className={cn(
                            'h-auto flex-col items-start justify-between gap-2 p-3 text-left whitespace-normal',
                            inView(index) &&
                                'bg-primary-fill/10 text-primary shadow-[inset_0_-2px_0_var(--primary)]',
                        )}
                    >
                        {/* Journey pathway: passed stages filled, the stages in view ringed, later ones hollow. */}
                        <span
                            aria-hidden="true"
                            className="-mx-3 flex w-[calc(100%+1.5rem)] items-center"
                        >
                            <span
                                className={cn(
                                    'h-px flex-1 bg-border',
                                    index === 0 && 'invisible',
                                )}
                            />
                            <span
                                className={cn(
                                    'shrink-0 rounded-full',
                                    inView(index)
                                        ? 'size-3 border-2 border-primary bg-card ring-2 ring-primary/20'
                                        : index < rangeStart
                                          ? 'size-2.5 bg-primary'
                                          : 'size-2.5 border border-muted-foreground bg-card',
                                )}
                            />
                            <span
                                className={cn(
                                    'h-px flex-1 bg-border',
                                    index === visible.length - 1 && 'invisible',
                                )}
                            />
                        </span>
                        <span className="text-sm font-semibold">
                            {lane.label}
                        </span>
                        <span className="flex flex-wrap items-baseline gap-1">
                            <span className="font-semibold text-foreground tabular-nums">
                                {lane.total}
                            </span>
                            <span className="text-caption">
                                {lane.total === 1 ? 'referral' : 'referrals'}
                            </span>
                            {lane.overdue > 0 && (
                                <EntityStatusChip
                                    variant="warning"
                                    icon={Clock}
                                    className="ml-1"
                                >
                                    {lane.overdue} overdue
                                </EntityStatusChip>
                            )}
                        </span>
                    </Button>
                ))}
            </div>
            <div className="flex items-center justify-between gap-4">
                <div
                    className="flex flex-wrap items-baseline gap-3"
                    aria-live="polite"
                >
                    <span className="text-section-title">
                        {count > 1
                            ? `${visible[rangeStart]?.label} — ${visible[rangeStart + count - 1]?.label}`
                            : visible[rangeStart]?.label}
                    </span>
                    <span className="text-caption text-muted-foreground">
                        {count > 1
                            ? `Stages ${rangeStart + 1}–${rangeStart + count} of ${visible.length}`
                            : `Stage ${rangeStart + 1} of ${visible.length}`}
                    </span>
                </div>
                <div className="flex gap-3">
                    <Button
                        variant="outline"
                        size="icon"
                        className="frontline-hit"
                        aria-label="Previous pipeline stages"
                        disabled={rangeStart === 0}
                        onClick={() => focus(rangeStart - count)}
                    >
                        <ArrowLeft className="size-4" />
                    </Button>
                    <Button
                        variant="outline"
                        size="icon"
                        className="frontline-hit"
                        aria-label="Next pipeline stages"
                        disabled={rangeStart >= lastStart}
                        onClick={() => focus(rangeStart + count)}
                    >
                        <ArrowRight className="size-4" />
                    </Button>
                </div>
            </div>
            <div
                ref={board}
                role="region"
                aria-label="Referral pipeline board"
                tabIndex={0}
                onScroll={onScroll}
                onKeyDown={(event) => {
                    if (
                        event.target === event.currentTarget &&
                        (event.key === 'ArrowLeft' ||
                            event.key === 'ArrowRight')
                    ) {
                        event.preventDefault();
                        focus(
                            rangeStart +
                                (event.key === 'ArrowRight' ? count : -count),
                        );
                    }
                }}
                style={{ '--pipeline-columns': count } as CSSProperties}
                className="scrollbar-pretty flex snap-x snap-mandatory items-start gap-5 overflow-x-auto pt-1 pb-4 outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-4"
            >
                {visible.map((lane) => {
                    const info = STAGE_INFO[lane.stage] ?? {
                        icon: Workflow,
                        hint: 'Your configured workflow stage',
                    };
                    const Icon = info.icon;
                    return (
                        <section
                            key={lane.stage}
                            id={`referral-lane-${lane.stage}`}
                            ref={(element) => {
                                laneRefs.current[lane.stage] = element;
                            }}
                            aria-labelledby={`referral-lane-heading-${lane.stage}`}
                            style={LANE_WIDTH}
                            className="min-w-0 shrink-0 grow-0 snap-start"
                        >
                            <header className="grid min-h-[68px] content-start gap-2 px-1 pb-4">
                                <div className="flex items-center gap-2">
                                    <span className="inline-flex size-7 shrink-0 items-center justify-center rounded-full bg-primary/10 text-primary ring-1 ring-primary/25">
                                        <Icon
                                            aria-hidden="true"
                                            className="size-4"
                                        />
                                    </span>
                                    <h2
                                        id={`referral-lane-heading-${lane.stage}`}
                                        className="text-section-title"
                                    >
                                        {lane.label}
                                    </h2>
                                    <span className="text-caption ml-auto rounded-sm border border-border bg-card px-2 py-0.5 text-muted-foreground tabular-nums">
                                        {lane.total}
                                    </span>
                                </div>
                                <p className="text-caption text-muted-foreground">
                                    {info.hint}
                                </p>
                            </header>
                            <div className="grid gap-5">
                                {lane.data.length ? (
                                    lane.data.map((record) => (
                                        <ReferralCard
                                            key={record.reference}
                                            record={record}
                                            stages={stages}
                                            showStage={false}
                                            openLabel="Review"
                                            actions={actionsFor(record)}
                                            onContextMenu={(event) =>
                                                onContextMenu(event, record)
                                            }
                                        />
                                    ))
                                ) : (
                                    <div className="grid justify-items-center gap-2 rounded-lg border border-dashed border-border px-5 py-8 text-center text-muted-foreground">
                                        <Icon
                                            aria-hidden="true"
                                            className="size-5"
                                        />
                                        <p className="text-subtle">
                                            No referrals here
                                        </p>
                                        <span className="text-caption">
                                            Referrals appear here after a
                                            reviewed stage change.
                                        </span>
                                    </div>
                                )}
                                {lane.total > lane.data.length && (
                                    <Button
                                        variant="outline"
                                        className="w-full"
                                        onClick={() => onViewAll(lane.stage)}
                                    >
                                        View all {lane.total} in {lane.label}
                                        <ArrowRight className="size-4" />
                                    </Button>
                                )}
                            </div>
                        </section>
                    );
                })}
            </div>
        </div>
    );
}
