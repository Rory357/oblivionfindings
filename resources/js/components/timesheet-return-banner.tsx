import { Link } from '@inertiajs/react';
import { ArrowRight } from 'lucide-react';
import { useState } from 'react';

import StaffStatus from '@/components/staff-status';
import TimesheetEditSheet, {
    type InlineTimesheet,
} from '@/components/timesheet-edit-sheet';
import { Button } from '@/components/ui/button';
import { useMyDayLabels } from '@/hooks/use-my-day-labels';
import { cn } from '@/lib/utils';
import { edit as editTimesheet } from '@/routes/operations/timesheets';

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
     * Override the edit-page URL. Defaults to the canonical operations edit
     * URL. My Day callers should pass `timesheet` for inline editing.
     */
    editHref?: string;
    /** Hide the primary action — useful on the edit page itself. */
    hideAction?: boolean;
    /** Optional extra classes. */
    className?: string;
    /** Optional size override for the status pill. Defaults to `sm`. */
    size?: 'sm' | 'md';
    /** Optional inline-edit payload for My Day. Falls back to edit link when omitted. */
    timesheet?: InlineTimesheet;
};

export function TimesheetReturnBanner({
    timesheetId,
    returnNote,
    editHref,
    hideAction = false,
    className,
    size = 'sm',
    timesheet,
}: TimesheetReturnBannerProps) {
    const t = useMyDayLabels();
    const [editOpen, setEditOpen] = useState(false);
    const href = editHref ?? editTimesheet.url(timesheetId);
    const trimmedNote = returnNote?.trim() ?? '';

    return (
        <div
            role="status"
            className={cn(
                'flex flex-col gap-3 rounded-lg border border-status-warning/30 bg-status-warning-bg p-3 text-status-warning',
                'dark:border-status-warning/40 dark:bg-status-warning-bg dark:text-status-warning',
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
                        {t('timesheet_quick_fix')}
                    </span>
                </div>
                {trimmedNote ? (
                    <p className="text-sm leading-snug whitespace-pre-wrap text-status-warning dark:text-status-warning">
                        {trimmedNote}
                    </p>
                ) : (
                    <p className="text-sm leading-snug text-status-warning dark:text-status-warning">
                        {t('timesheet_review_again')}
                    </p>
                )}
            </div>

            {!hideAction ? (
                <div className="shrink-0">
                    {timesheet ? (
                        <>
                            <Button
                                type="button"
                                size="sm"
                                className="w-full bg-status-warning text-white hover:bg-status-warning focus-visible:ring-status-warning sm:w-auto dark:bg-status-warning dark:hover:bg-status-warning"
                                onClick={() => setEditOpen(true)}
                                disabled={!timesheet.can_edit_inline}
                                title={
                                    timesheet.can_edit_inline
                                        ? undefined
                                        : 'This timesheet is locked or no longer editable.'
                                }
                            >
                                {t('update_and_resubmit')}
                                <ArrowRight className="ml-1.5 h-4 w-4" />
                            </Button>
                            <TimesheetEditSheet
                                timesheet={timesheet}
                                open={editOpen}
                                onOpenChange={setEditOpen}
                            />
                        </>
                    ) : (
                        <Button
                            asChild
                            size="sm"
                            className="w-full bg-status-warning text-white hover:bg-status-warning focus-visible:ring-status-warning sm:w-auto dark:bg-status-warning dark:hover:bg-status-warning"
                        >
                            <Link href={href}>
                                Fix and resend
                                <ArrowRight className="ml-1.5 h-4 w-4" />
                            </Link>
                        </Button>
                    )}
                </div>
            ) : null}
        </div>
    );
}

export default TimesheetReturnBanner;
