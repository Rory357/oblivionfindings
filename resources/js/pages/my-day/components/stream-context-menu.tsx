import {
    AlertTriangle,
    ArrowRight,
    Check,
    Clock,
    Mic,
    Pill,
    Plus,
    Shield,
    StickyNote,
} from 'lucide-react';
import { type ComponentType, useEffect, useRef } from 'react';

import { Tooltip, TooltipContent, TooltipProvider, TooltipTrigger } from '@/components/ui/tooltip';
import { cn } from '@/lib/utils';

import type { StreamItem } from '../lib/stream-grouping';

export type ContextMenuAction =
    | 'complete-task'
    | 'add-note'
    | 'dictate'
    | 'new-task'
    | 'reschedule'
    | 'open-care-plan'
    | 'skip-task'
    | 'give-med'
    | 'snooze-med'
    | 'open-emar'
    | 'explain-med'
    | 'refuse-med'
    | 'noop';

interface StreamContextMenuProps {
    menu: { item: StreamItem; x: number; y: number };
    onClose: () => void;
    onAction: (action: ContextMenuAction) => void;
}

interface MenuEntry {
    icon: ComponentType<{ className?: string }>;
    label: string;
    action: ContextMenuAction;
    shortcut?: string;
    tone?: 'danger';
    /** Render disabled with a "Coming soon" tooltip — used for unwired actions. */
    disabled?: boolean;
}

export function StreamContextMenu({ menu, onClose, onAction }: StreamContextMenuProps) {
    const ref = useRef<HTMLDivElement | null>(null);

    useEffect(() => {
        const onMouseDown = (e: MouseEvent) => {
            if (ref.current && !ref.current.contains(e.target as Node)) onClose();
        };
        const onKeyDown = (e: KeyboardEvent) => {
            if (e.key === 'Escape') onClose();
        };
        document.addEventListener('mousedown', onMouseDown);
        document.addEventListener('keydown', onKeyDown);
        return () => {
            document.removeEventListener('mousedown', onMouseDown);
            document.removeEventListener('keydown', onKeyDown);
        };
    }, [onClose]);

    const { item, x, y } = menu;
    const sections = item.kind === 'task' ? buildTaskMenu(item) : buildMedMenu(item);

    // Clamp to viewport.
    const left = Math.min(x, (typeof window !== 'undefined' ? window.innerWidth : 1024) - 240);
    const top = Math.min(y, (typeof window !== 'undefined' ? window.innerHeight : 768) - 320);

    return (
        <div
            ref={ref}
            role="menu"
            aria-label={item.kind === 'task' ? 'Task actions' : 'Medication actions'}
            data-test="my-day-stream-context-menu"
            style={{ left, top }}
            className={cn(
                'fixed z-[1000] w-[224px] rounded-xl border border-border bg-popover p-1 text-popover-foreground',
                'shadow-[0_18px_50px_-12px_rgba(0,0,0,0.35),0_4px_12px_-4px_rgba(0,0,0,0.18)]',
                'animate-in fade-in-0 slide-in-from-top-1 duration-100',
            )}
        >
            <div className="flex items-center gap-1.5 overflow-hidden truncate whitespace-nowrap px-2.5 pb-1.5 pt-2 text-[10.5px] font-bold uppercase tracking-[0.08em] text-text-faint">
                {item.kind === 'task' ? 'Care task' : 'Medication'}
                <span className="ml-auto text-[11px] font-medium normal-case tracking-normal text-muted-foreground">
                    {item.at}
                </span>
            </div>

            <TooltipProvider delayDuration={300}>
                {sections.map((section, sIdx) => (
                    <div
                        key={sIdx}
                        className={cn(sIdx > 0 && 'mt-1 border-t border-border pt-1')}
                    >
                        {section.map((entry) => (
                            <ContextMenuEntry
                                key={entry.label}
                                entry={entry}
                                onClick={() => !entry.disabled && onAction(entry.action)}
                            />
                        ))}
                    </div>
                ))}
            </TooltipProvider>
        </div>
    );
}

function ContextMenuEntry({ entry, onClick }: { entry: MenuEntry; onClick: () => void }) {
    const Icon = entry.icon;
    const body = (
        // eslint-disable-next-line no-restricted-syntax -- menuitem in a custom context menu, not a shadcn Button.
        <button
            type="button"
            role="menuitem"
            disabled={entry.disabled}
            onClick={onClick}
            className={cn(
                'flex w-full items-center gap-2.5 rounded-md px-2.5 py-1.5 text-left text-[13px] font-medium transition-colors',
                entry.tone === 'danger'
                    ? 'text-status-critical hover:bg-status-critical-bg'
                    : 'text-foreground hover:bg-muted',
                entry.disabled && 'cursor-not-allowed opacity-50 hover:bg-transparent',
            )}
        >
            <Icon
                className={cn(
                    'h-3.5 w-3.5 shrink-0',
                    entry.tone === 'danger' ? 'text-status-critical' : 'text-muted-foreground',
                )}
            />
            <span className="flex-1">{entry.label}</span>
            {entry.shortcut ? (
                <span className="rounded border border-border px-1.5 py-px font-mono text-[10.5px] text-text-faint">
                    {entry.shortcut}
                </span>
            ) : null}
        </button>
    );

    if (entry.disabled) {
        return (
            <Tooltip>
                <TooltipTrigger asChild>{body}</TooltipTrigger>
                <TooltipContent side="right">Coming soon</TooltipContent>
            </Tooltip>
        );
    }

    return body;
}

function buildTaskMenu(item: Extract<StreamItem, { kind: 'task' }>): MenuEntry[][] {
    const t = item.data;
    return [
        [
            t.is_completed
                ? { icon: ArrowRight, label: 'Mark incomplete', action: 'complete-task', shortcut: '⏎' }
                : { icon: Check, label: 'Complete task', action: 'complete-task', shortcut: '⏎' },
            { icon: StickyNote, label: 'Add note', action: 'add-note', shortcut: 'N' },
            { icon: Mic, label: 'Dictate update', action: 'dictate', disabled: true },
        ],
        [
            { icon: Plus, label: 'New task here', action: 'new-task', shortcut: '⌘N', disabled: true },
            { icon: Clock, label: 'Reschedule', action: 'reschedule', disabled: true },
            { icon: Shield, label: 'Open care plan', action: 'open-care-plan' },
        ],
        [
            { icon: AlertTriangle, label: 'Skip task', action: 'skip-task', tone: 'danger', disabled: true },
        ],
    ];
}

function buildMedMenu(item: Extract<StreamItem, { kind: 'med' }>): MenuEntry[][] {
    const m = item.data;
    const given = m.status === 'given';
    if (given) {
        return [
            [
                { icon: ArrowRight, label: 'Already given', action: 'noop', disabled: true },
                { icon: Pill, label: 'Open in eMAR', action: 'open-emar' },
                { icon: Shield, label: 'Why this dose?', action: 'explain-med', disabled: true },
            ],
        ];
    }
    return [
        [
            { icon: Check, label: 'Mark as given', action: 'give-med', shortcut: '⏎' },
            { icon: Clock, label: 'Snooze 15 min', action: 'snooze-med' },
            { icon: StickyNote, label: 'Add note', action: 'add-note' },
        ],
        [
            { icon: Pill, label: 'Open in eMAR', action: 'open-emar' },
            { icon: Shield, label: 'Why this dose?', action: 'explain-med', disabled: true },
        ],
        [
            { icon: AlertTriangle, label: 'Refuse / not given', action: 'refuse-med', tone: 'danger' },
        ],
    ];
}

export default StreamContextMenu;
