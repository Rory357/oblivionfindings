import { AlertTriangle } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { formatDateTime } from '@/lib/datetime';
import { cn } from '@/lib/utils';

/* -------------------------------------------------------------------------- */
/*  Shared draft recovery prompt                                              */
/* -------------------------------------------------------------------------- */
/*
 * PR 16 — Small inline "you have an unfinished draft" card used by every
 * long-form write surface. Calm, plain language; matches the incident wizard's
 * tone so workers see the same idea wherever they write.
 */

export type DraftResumePromptProps = {
    savedAt: number | null;
    onResume: () => void;
    onDiscard: () => void;
    title?: string;
    description?: string;
    className?: string;
};

export default function DraftResumePrompt({
    savedAt,
    onResume,
    onDiscard,
    title = 'Resume your unsaved draft?',
    description = 'We found unfinished work on this device.',
    className,
}: DraftResumePromptProps) {
    return (
        <div
            role="alertdialog"
            aria-label={title}
            className={cn(
                'rounded-lg border border-status-warning/30 bg-status-warning-bg p-3 text-sm shadow-sm',
                'dark:border-status-warning/40 dark:bg-status-warning',
                className,
            )}
        >
            <div className="flex items-start gap-3">
                <div className="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-status-warning-bg text-status-warning">
                    <AlertTriangle className="h-4 w-4" aria-hidden />
                </div>
                <div className="min-w-0 flex-1">
                    <div className="font-medium text-status-warning dark:text-status-warning">{title}</div>
                    <p className="mt-0.5 text-xs text-status-warning dark:text-status-warning">
                        {description}
                        {savedAt ? ` Last saved ${formatDateTime(savedAt)}.` : null}
                    </p>
                    <div className="mt-2 flex flex-wrap gap-2">
                        <Button size="sm" onClick={onResume}>
                            Continue draft
                        </Button>
                        <Button size="sm" variant="outline" onClick={onDiscard}>
                            Discard
                        </Button>
                    </div>
                </div>
            </div>
        </div>
    );
}
