import {
    AlertTriangle,
    Check,
    Clock,
    Heart,
    MoreHorizontal,
    Pill,
    StickyNote,
} from 'lucide-react';
import { type MouseEvent, useState } from 'react';

import { Badge } from '@/components/ui/badge';
import { cn } from '@/lib/utils';

import type { StreamItem as Item } from '../lib/stream-grouping';
import type { MyDayResident } from '../lib/types';

import { HoverAction } from './hover-action';
import { ResidentDot } from './resident-dot';

interface StreamItemProps {
    item: Item;
    isNow: boolean;
    /** When showing all residents, the resident's dot+name is shown inline. */
    showResident: boolean;
    /** Resident lookup for inline dot+name. */
    resident?: MyDayResident;
    onToggleTask: (taskId: number) => void;
    onGiveMed: (medicationId: number, scheduledFor: string) => void;
    onRefuseMed?: (medicationId: number, scheduledFor: string) => void;
    onSnoozeMed?: (medicationId: number, scheduledFor: string) => void;
    onAddNote?: (item: Item) => void;
    onOpenContextMenu: (item: Item, x: number, y: number) => void;
}

/** Single row in the WhatsNextRail (task OR med). */
export function StreamItemRow(props: StreamItemProps) {
    if (props.item.kind === 'task') return <TaskRow {...props} />;
    return <MedRow {...props} />;
}

function TaskRow({
    item,
    isNow,
    showResident,
    resident,
    onToggleTask,
    onAddNote,
    onOpenContextMenu,
}: StreamItemProps) {
    const [hover, setHover] = useState(false);
    if (item.kind !== 'task') return null;
    const task = item.data;

    const handleContext = (e: MouseEvent<HTMLDivElement>) => {
        e.preventDefault();
        onOpenContextMenu(item, e.clientX, e.clientY);
    };

    return (
        <div
            onMouseEnter={() => setHover(true)}
            onMouseLeave={() => setHover(false)}
            onContextMenu={handleContext}
            className={cn(
                'flex items-center gap-3 px-[18px] py-2.5 transition-colors',
                hover && !isNow && 'bg-muted',
            )}
        >
            {/* eslint-disable-next-line no-restricted-syntax -- 20px circular checkbox with custom borders; not a shadcn Button. */}
            <button
                type="button"
                onClick={() => onToggleTask(task.id)}
                title={task.is_completed ? 'Mark incomplete' : 'Mark complete'}
                aria-pressed={task.is_completed}
                className={cn(
                    'flex h-5 w-5 shrink-0 items-center justify-center rounded-full border-[1.5px]',
                    task.is_completed
                        ? 'border-status-success bg-status-success text-status-success-foreground'
                        : isNow
                          ? 'border-primary text-transparent'
                          : 'border-muted-foreground text-transparent',
                )}
            >
                {task.is_completed ? <Check className="h-2.5 w-2.5" /> : null}
            </button>

            <div className="min-w-0 flex-1">
                <div
                    className={cn(
                        'text-sm',
                        isNow ? 'font-semibold' : 'font-medium',
                        task.is_completed
                            ? 'text-muted-foreground line-through'
                            : 'text-foreground',
                    )}
                >
                    {task.label}
                </div>
                <div className="mt-0.5 flex items-center gap-2 text-[11px] text-muted-foreground">
                    {showResident && resident ? (
                        <span className="inline-flex items-center gap-1">
                            <ResidentDot
                                hue={resident.hue}
                                initials={resident.initials}
                            />
                            {resident.first_name}
                        </span>
                    ) : null}
                    <span className="inline-flex items-center gap-1">
                        <Heart className="h-2.5 w-2.5" /> Care task
                    </span>
                    {isNow ? (
                        <Badge
                            variant="outline"
                            className="border-primary/30 bg-accent text-[10px] text-primary"
                        >
                            Happening now
                        </Badge>
                    ) : null}
                </div>
            </div>

            <div
                className={cn(
                    'flex gap-1 transition-opacity',
                    hover ? 'opacity-100' : 'pointer-events-none opacity-0',
                )}
            >
                {!task.is_completed ? (
                    <HoverAction
                        icon={Check}
                        label="Mark complete"
                        tone="success"
                        onClick={() => onToggleTask(task.id)}
                    />
                ) : null}
                <HoverAction
                    icon={StickyNote}
                    label="Add note"
                    onClick={() => onAddNote?.(item)}
                />
                <HoverAction
                    icon={MoreHorizontal}
                    label="More"
                    onClick={(e) => {
                        e.stopPropagation();
                        const rect = (
                            e.currentTarget as HTMLElement
                        ).getBoundingClientRect();
                        onOpenContextMenu(item, rect.right, rect.bottom);
                    }}
                />
            </div>
        </div>
    );
}

function MedRow({
    item,
    isNow,
    showResident,
    resident,
    onGiveMed,
    onSnoozeMed,
    onRefuseMed,
    onAddNote,
    onOpenContextMenu,
}: StreamItemProps) {
    const [hover, setHover] = useState(false);
    if (item.kind !== 'med') return null;
    const med = item.data;
    const overdue = med.status === 'overdue';
    const given = med.status === 'given';
    const notGiven = med.status === 'refused' || med.status === 'withheld';
    const resolved = given || notGiven;
    const resolvedLabel = given
        ? 'Given'
        : med.status === 'withheld'
          ? 'Withheld'
          : 'Refused';

    const handleContext = (e: MouseEvent<HTMLDivElement>) => {
        e.preventDefault();
        onOpenContextMenu(item, e.clientX, e.clientY);
    };

    return (
        <div
            onMouseEnter={() => setHover(true)}
            onMouseLeave={() => setHover(false)}
            onContextMenu={handleContext}
            className={cn(
                'flex items-center gap-3 px-[18px] py-2.5 transition-colors',
                overdue
                    ? 'bg-status-critical-bg'
                    : hover && !isNow
                      ? 'bg-muted'
                      : undefined,
            )}
        >
            {/* eslint-disable-next-line no-restricted-syntax -- 20px circular checkbox with status-tinted border; not a shadcn Button. */}
            <button
                type="button"
                onClick={() =>
                    !resolved && onGiveMed(med.medication_id, med.scheduled_for)
                }
                title={resolved ? resolvedLabel : 'Mark as given'}
                aria-pressed={resolved}
                className={cn(
                    'flex h-5 w-5 shrink-0 items-center justify-center rounded-full border-[1.5px]',
                    given
                        ? 'border-status-success bg-status-success text-status-success-foreground'
                        : notGiven
                          ? 'border-status-warning bg-status-warning text-status-warning-foreground'
                          : overdue
                            ? 'border-status-critical text-transparent'
                            : 'border-muted-foreground text-transparent',
                )}
            >
                {resolved ? <Check className="h-2.5 w-2.5" /> : null}
            </button>

            <div
                className={cn(
                    'flex h-[26px] w-[26px] shrink-0 items-center justify-center rounded-md',
                    given
                        ? 'bg-status-success-bg text-status-success'
                        : notGiven
                          ? 'bg-status-warning-bg text-status-warning'
                          : overdue
                            ? 'bg-status-critical-bg text-status-critical'
                            : 'bg-accent text-primary',
                )}
            >
                <Pill className="h-3 w-3" />
            </div>

            <div className="min-w-0 flex-1">
                <div
                    className={cn(
                        'text-[13.5px] font-medium',
                        resolved
                            ? 'text-muted-foreground line-through'
                            : 'text-foreground',
                    )}
                >
                    {med.medication_name}
                    <span className="ml-1 font-normal text-muted-foreground">
                        · {med.dose}
                    </span>
                </div>
                <div className="mt-px flex items-center gap-1.5 text-[11px] text-muted-foreground">
                    {showResident && resident ? (
                        <>
                            <ResidentDot
                                hue={resident.hue}
                                initials={resident.initials}
                            />
                            <span>{resident.first_name}</span>
                            <span>·</span>
                        </>
                    ) : null}
                    {med.route ? <span>{med.route}</span> : null}
                </div>
            </div>

            {/* Static badges (visible when not hovering) */}
            {!hover ? (
                <div className="flex shrink-0 items-center gap-1.5">
                    {med.flag ? (
                        <Badge
                            variant="outline"
                            className="border-status-info/30 bg-status-info-bg text-[10px] text-status-info"
                        >
                            {med.flag}
                        </Badge>
                    ) : null}
                    {overdue ? (
                        <Badge
                            variant="outline"
                            className="border-status-critical/30 bg-status-critical-bg text-[10px] text-status-critical"
                        >
                            Overdue
                        </Badge>
                    ) : null}
                    {resolved ? (
                        <Badge
                            variant="outline"
                            className={cn(
                                'text-[10px]',
                                given
                                    ? 'border-status-success/30 bg-status-success-bg text-status-success'
                                    : 'border-status-warning/30 bg-status-warning-bg text-status-warning',
                            )}
                        >
                            {resolvedLabel}
                        </Badge>
                    ) : null}
                </div>
            ) : null}

            {/* Hover actions (replace static badges except resolved states above) */}
            {!resolved ? (
                <div
                    className={cn(
                        'flex gap-1 transition-opacity',
                        hover ? 'opacity-100' : 'pointer-events-none opacity-0',
                    )}
                >
                    <HoverAction
                        icon={Check}
                        label="Mark as given"
                        tone="success"
                        onClick={() =>
                            onGiveMed(med.medication_id, med.scheduled_for)
                        }
                    />
                    <HoverAction
                        icon={Clock}
                        label="Snooze 15m"
                        onClick={() =>
                            onSnoozeMed?.(med.medication_id, med.scheduled_for)
                        }
                    />
                    <HoverAction
                        icon={AlertTriangle}
                        label="Refuse / not given"
                        tone="danger"
                        onClick={() =>
                            onRefuseMed?.(med.medication_id, med.scheduled_for)
                        }
                    />
                    <HoverAction
                        icon={MoreHorizontal}
                        label="More"
                        onClick={(e) => {
                            e.stopPropagation();
                            const rect = (
                                e.currentTarget as HTMLElement
                            ).getBoundingClientRect();
                            onOpenContextMenu(item, rect.right, rect.bottom);
                        }}
                    />
                </div>
            ) : null}
        </div>
    );
}

export default StreamItemRow;
