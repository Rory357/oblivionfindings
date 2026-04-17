import { Link } from '@inertiajs/react';
import { ArrowRight } from 'lucide-react';

import StaffStatus from '@/components/staff-status';
import { Button } from '@/components/ui/button';
import { cn } from '@/lib/utils';

/**
 * `<TimesheetReturnBanner>` — inline banner for returned / needs-changes
 * timesheets on staff-facing surfaces (`/my-day`, the staff timesheet list,
 * the timesheet edit page).
 *
 * Replaces the older hover-only return-note tooltip so frontline workers on a
 * phone can actually see what a manager asked them to change. Uses the
 * `<StaffStatus kind="timesheet" state="needs_changes">` pill so this banner
 * never invents a competing status vocabulary.
 *
 * The banner always renders:
 *   - the "Needs your changes" status pill
 *   - a short, worker-facing heading
 *   - the manager/reviewer return note (if any), in plain sight
 *   - one primary action — "Fix and resend" — that deep-links to the edit view
 *
 * Kept visually calm (amber/warning tone, not destructive red) because a
 * returned timesheet is fixable, not a failure.
 */
export type TimesheetReturnBannerProps = {
    /** Timesheet id — used to build the "Fix and resend" link. */
    timesheetId: number;
    /** Manager / reviewer return note, if any. Whitespace is preserved. */
    returnNote?: string | null;
    /**
     * Override the edit-page URL. Defaults to `/timesheets/{id}/edit`, which
     * the active `TimesheetController::edit` route handles for both staff and
     * operations users.
     */
    editHref?: string;
    /** Hide the primary action — useful on the edit page itself. */
    hideAction?: boolean;
    /** Optional extra classes. */
    className?: string;
    /** Optional size override for the status pill. Defaults to `sm`. */
    size?: 'sm' | 'md';
};

export function TimesheetReturnBanner({
    timesheetId,
    returnNote,
    editHref,
    hideAction = false,
    className,
    size = 'sm',
}: TimesheetReturnBannerProps) {
    const href = editHref ?? `/timesheets/${timesheetId}/edit`;
    const trimmedNote = returnNote?.trim() ?? '';

    return (
        <div
            role="status"
            className={cn(
                'flex flex-col gap-3 rounded-lg border border-amber-300 bg-amber-50/80 p-3 text-amber-900',
                'dark:border-amber-500/40 dark:bg-amber-500/10 dark:text-amber-100',
                'sm:flex-row sm:items-start sm:justify-between',
                className,
            )}
        >
            <div className="min-w-0 space-y-1.5">
                <div className="flex flex-wrap items-center gap-2">
                    <StaffStatus
                        kind="timesheet"
                        state="needs_changes"
                        size={size}
                    />
                    <span className="text-sm font-semibold">
                        Your timesheet needs a quick fix
                    </span>
                </div>
                {trimmedNote ? (
                    <p className="whitespace-pre-wrap text-sm leading-snug text-amber-900/90 dark:text-amber-100/90">
                        {trimmedNote}
                    </p>
                ) : (
                    <p className="text-sm leading-snug text-amber-900/80 dark:text-amber-100/80">
                        Your manager asked for a change. Open it, make the fix,
                        then resend for approval.
                    </p>
                )}
            </div>

            {!hideAction ? (
                <div className="shrink-0">
                    <Button
                        asChild
                        size="sm"
                        className="w-full bg-amber-600 text-white hover:bg-amber-700 focus-visible:ring-amber-500 sm:w-auto dark:bg-amber-500 dark:hover:bg-amber-400"
                    >
                        <Link href={href}>
                            Fix and resend
                            <ArrowRight className="ml-1.5 h-4 w-4" />
                        </Link>
                    </Button>
                </div>
            ) : null}
        </div>
    );
}

export default TimesheetReturnBanner;
