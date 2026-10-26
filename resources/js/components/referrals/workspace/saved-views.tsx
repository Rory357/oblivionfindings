import { CounterPill } from '@/components/lists';
import { PageHeaderGlassButton } from '@/components/page';
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';
import { cn } from '@/lib/utils';
import { Bookmark, Check } from 'lucide-react';
import { useState } from 'react';
import type { WorkspaceSavedView } from '../types';

const HINTS: Record<string, string> = {
    due_today: 'Next actions and follow-ups due today',
    funding_help: 'Referrals at the funding help stage',
    waiting: 'On hold, waiting for the person, evidence or a decision',
    ready_for_handover: 'Ready to review the move into supported living',
    capacity_waitlist: 'On hold for capacity',
    mine: 'Open referrals you are accountable for',
    unassigned: 'Open referrals without a named owner',
    overdue: 'An overdue next action or follow-up',
};

/** Saved views (spec §5): fixed queue presets with live, scope-limited counts. */
export function SavedViews({
    views,
    active,
    onPick,
}: {
    views: WorkspaceSavedView[];
    active: string | null;
    onPick: (key: string) => void;
}) {
    const [open, setOpen] = useState(false);
    return (
        <Popover open={open} onOpenChange={setOpen}>
            <PopoverTrigger asChild>
                <PageHeaderGlassButton icon={Bookmark} aria-haspopup="dialog">
                    Saved views
                </PageHeaderGlassButton>
            </PopoverTrigger>
            <PopoverContent align="end" className="w-80 p-1">
                <div className="px-2.5 pt-2 pb-1.5">
                    <p className="text-sm font-semibold">Saved views</p>
                    <p className="text-caption text-muted-foreground">
                        Counts cover only referrals you can open. Permissions
                        still apply whenever a view is opened.
                    </p>
                </div>
                {views.map((view) => {
                    const on = active === view.key;
                    return (
                        // eslint-disable-next-line no-restricted-syntax -- two-line menu row with a counter, like the header filter menu rows
                        <button
                            key={view.key}
                            type="button"
                            aria-current={on ? 'true' : undefined}
                            onClick={() => {
                                setOpen(false);
                                onPick(view.key);
                            }}
                            className={cn(
                                'flex w-full items-start justify-between gap-3 rounded-md px-2.5 py-2 text-left text-sm text-foreground outline-none hover:bg-muted focus-visible:ring-2 focus-visible:ring-ring',
                                on && 'font-semibold text-primary',
                            )}
                        >
                            <span className="grid min-w-0 gap-0.5">
                                <span className="flex items-center gap-1.5">
                                    {view.label}
                                    {on && <Check className="size-4" />}
                                </span>
                                <span className="text-caption font-normal text-muted-foreground">
                                    {HINTS[view.key]}
                                </span>
                            </span>
                            <CounterPill
                                tone={
                                    view.key === 'overdue' && view.count > 0
                                        ? 'warning'
                                        : 'neutral'
                                }
                            >
                                {view.count}
                            </CounterPill>
                        </button>
                    );
                })}
            </PopoverContent>
        </Popover>
    );
}
