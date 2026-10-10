import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Link } from '@inertiajs/react';
import { Settings } from 'lucide-react';
import type { ConflictAssessment } from './build-queue';
export interface ConflictScanSettingsDialogProps {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    assessment?: ConflictAssessment;
}
export function ConflictScanSettingsDialog({
    open,
    onOpenChange,
    assessment,
}: ConflictScanSettingsDialogProps) {
    const criteria = assessment?.scan_criteria;
    const settingsUrl = assessment?.workflow_urls.workforce_settings;
    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="sm:max-w-lg">
                <DialogHeader>
                    <DialogTitle className="flex items-center gap-2">
                        <Settings className="h-5 w-5" />
                        Current scan criteria
                    </DialogTitle>
                    <DialogDescription>
                        What this queue checks when you open or refresh it.
                    </DialogDescription>
                </DialogHeader>
                <dl className="max-h-[60vh] space-y-4 overflow-y-auto text-sm">
                    <div>
                        <dt className="font-semibold">Dates and times</dt>
                        <dd className="mt-1 text-muted-foreground">
                            {criteria?.worker_timezone ??
                                'Time zone not returned'}
                            . The selected week includes duties that start
                            before it and continue into it.
                        </dd>
                    </div>
                    <div>
                        <dt className="font-semibold">Overlapping shifts</dt>
                        <dd className="mt-1 text-muted-foreground">
                            {criteria?.interval_end_exclusive
                                ? 'Two duties overlap when they share time. One ending exactly as the next begins is not an overlap.'
                                : 'Overlap criteria were not returned.'}
                        </dd>
                    </div>
                    <div>
                        <dt className="font-semibold">Tight turnarounds</dt>
                        <dd className="mt-1 text-muted-foreground">
                            {criteria
                                ? 'Consecutive, non-overlapping duties with a gap of 0–' +
                                  criteria.turnaround_threshold_minutes +
                                  ' minutes. This is a review prompt; it does not approve rest, travel or fatigue risk.'
                                : 'Turnaround criteria were not returned.'}
                        </dd>
                    </div>
                    <div>
                        <dt className="font-semibold">Updates</dt>
                        <dd className="mt-1 text-muted-foreground">
                            Refresh after a roster or source change. This queue
                            does not automatically re-scan in the background.
                        </dd>
                    </div>
                    <div>
                        <dt className="font-semibold">
                            Coverage and publication
                        </dt>
                        <dd className="mt-1 text-muted-foreground">
                            Coverage uses the existing House staffing
                            requirements within your access. Partial or
                            unavailable assessments are labelled. Publishing the
                            roster runs separate checks.
                        </dd>
                    </div>
                </dl>
                <DialogFooter>
                    <Button
                        variant="outline"
                        className="frontline-tap"
                        onClick={() => onOpenChange(false)}
                    >
                        Close
                    </Button>
                    {settingsUrl ? (
                        <Button asChild className="frontline-tap">
                            <Link href={settingsUrl}>
                                Open Workforce settings
                            </Link>
                        </Button>
                    ) : null}
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
export default ConflictScanSettingsDialog;
