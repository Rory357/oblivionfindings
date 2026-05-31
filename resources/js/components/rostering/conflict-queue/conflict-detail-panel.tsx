import { Check, Inbox, Layers, Zap } from 'lucide-react';

import { Button } from '@/components/ui/button';
import { cn } from '@/lib/utils';

import { ShiftSummaryCard } from './shift-summary-card';
import {
    SEVERITY_BADGE_LABEL,
    TYPE_META,
    type ContextTone,
    type QueueAction,
    type QueueItem,
    type Severity,
} from './types';

const SEV_TILE: Record<Severity, string> = {
    critical: 'bg-status-critical-bg text-status-critical',
    warning: 'bg-status-warning-bg text-status-warning',
    info: 'bg-accent text-primary',
};

const SEV_BADGE: Record<Severity, string> = {
    critical: 'bg-status-critical-bg text-status-critical',
    warning: 'bg-status-warning-bg text-status-warning',
    info: 'bg-status-info-bg text-status-info',
};

const CONTEXT_TONE: Record<ContextTone, string> = {
    crit: 'bg-status-critical-bg text-status-critical',
    warn: 'bg-status-warning-bg text-status-warning',
    info: 'bg-status-info-bg text-status-info',
};

function DetailEmpty() {
    return (
        <div className="flex flex-col items-center justify-center px-6 py-20 text-center">
            <span className="flex h-14 w-14 items-center justify-center rounded-full bg-muted text-muted-foreground">
                <Inbox className="h-7 w-7" />
            </span>
            <p className="mt-3 text-sm font-semibold">Nothing selected</p>
            <p className="mt-1 max-w-[280px] text-xs text-muted-foreground">
                Pick a conflict from the queue, or hit{' '}
                <span className="font-semibold text-foreground">
                    Resolve next
                </span>{' '}
                to start with the highest-impact one.
            </p>
        </div>
    );
}

function ActionButton({
    action,
    onClick,
}: {
    action: QueueAction;
    onClick: () => void;
}) {
    if (action.tone === 'primary') {
        return (
            <Button size="sm" onClick={onClick}>
                <Check className="mr-1.5 h-4 w-4" />
                {action.label}
            </Button>
        );
    }
    return (
        <Button
            size="sm"
            variant={action.tone === 'subtle' ? 'ghost' : 'outline'}
            onClick={onClick}
        >
            {action.label}
        </Button>
    );
}

export interface ConflictDetailPanelProps {
    item: QueueItem | null;
    onAction: (item: QueueItem, action: QueueAction) => void;
}

export function ConflictDetailPanel({
    item,
    onAction,
}: ConflictDetailPanelProps) {
    return (
        <div className="rounded-2xl border bg-card p-[18px] lg:sticky lg:top-5">
            {!item ? (
                <DetailEmpty />
            ) : (
                <DetailBody item={item} onAction={onAction} />
            )}
        </div>
    );
}

function DetailBody({
    item,
    onAction,
}: {
    item: QueueItem;
    onAction: (item: QueueItem, action: QueueAction) => void;
}) {
    const meta = TYPE_META[item.type];
    const Icon = meta.icon;
    const ContextIcon = item.context?.icon;
    const twoUp = item.shifts.length > 1;

    return (
        <div>
            <div className="flex items-start gap-3">
                <span
                    className={cn(
                        'flex h-[42px] w-[42px] shrink-0 items-center justify-center rounded-xl',
                        SEV_TILE[meta.severity],
                    )}
                >
                    <Icon className="h-[18px] w-[18px]" />
                </span>
                <div className="min-w-0 flex-1">
                    <div className="flex flex-wrap items-center gap-2">
                        <span className="text-[11px] font-semibold tracking-wider text-muted-foreground uppercase">
                            {meta.label}
                        </span>
                        <span
                            className={cn(
                                'rounded-full px-2 py-0.5 text-[10.5px] font-semibold',
                                SEV_BADGE[meta.severity],
                            )}
                        >
                            {SEVERITY_BADGE_LABEL[meta.severity]}
                        </span>
                    </div>
                    <h2 className="mt-1 text-[19px] leading-tight font-bold">
                        {item.who}
                    </h2>
                    <p className="mt-0.5 text-sm text-muted-foreground">
                        {item.summary}
                    </p>
                </div>
            </div>

            {item.context && ContextIcon ? (
                <div
                    className={cn(
                        'mt-3 flex items-center gap-2 rounded-lg px-3 py-2 text-[12.5px] font-medium',
                        CONTEXT_TONE[item.context.tone],
                    )}
                >
                    <ContextIcon className="h-3.5 w-3.5 shrink-0" />
                    <span>{item.context.text}</span>
                </div>
            ) : null}

            {item.shifts.length > 0 ? (
                <div
                    className={cn(
                        'mt-3 grid gap-2',
                        twoUp ? 'sm:grid-cols-2' : 'grid-cols-1',
                    )}
                >
                    {item.shifts.map((shift) => (
                        <ShiftSummaryCard key={shift.id} shift={shift} />
                    ))}
                </div>
            ) : item.type === 'coverage_gap' ? (
                <div className="mt-3 flex items-center gap-2 rounded-xl border border-dashed p-3 text-[12.5px] text-muted-foreground">
                    <Layers className="h-4 w-4 shrink-0" />
                    No supply scheduled in this window yet.
                </div>
            ) : null}

            <div className="mt-3 flex items-start gap-2.5 rounded-xl bg-[color-mix(in_oklch,var(--primary)_6%,transparent)] p-3">
                <span className="flex h-7 w-7 shrink-0 items-center justify-center rounded-lg bg-accent text-primary">
                    <Zap className="h-3.5 w-3.5" />
                </span>
                <div className="min-w-0">
                    <span className="block text-[10.5px] font-semibold tracking-wider text-primary uppercase">
                        Recommended
                    </span>
                    <span className="mt-0.5 block text-[13px] text-foreground">
                        {item.recommended}
                    </span>
                </div>
            </div>

            <div className="mt-3 flex flex-wrap gap-2">
                {item.actions.map((action) => (
                    <ActionButton
                        key={action.key}
                        action={action}
                        onClick={() => onAction(item, action)}
                    />
                ))}
            </div>
        </div>
    );
}

export default ConflictDetailPanel;
