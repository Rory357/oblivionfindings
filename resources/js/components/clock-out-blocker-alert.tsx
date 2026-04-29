import { AlertTriangle } from 'lucide-react';

import type { EndOfShiftBlocker } from '@/components/end-of-shift-checklist';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';

export default function ClockOutBlockerAlert({
    blockers,
}: {
    blockers: EndOfShiftBlocker[];
}) {
    if (blockers.length === 0) {
        return null;
    }

    return (
        <Alert
            variant="destructive"
            data-test="clock-out-blockers"
            className="border-status-warning/30 bg-status-warning-bg text-status-warning"
        >
            <AlertTriangle aria-hidden />
            <AlertTitle>Before you can end this shift</AlertTitle>
            <AlertDescription>
                <ul className="list-disc pl-4">
                    {blockers.map((blocker) => (
                        <li key={blocker.key}>
                            <span className="font-medium">{blocker.label}</span>
                            {blocker.detail ? ` - ${blocker.detail}` : null}
                        </li>
                    ))}
                </ul>
            </AlertDescription>
        </Alert>
    );
}
