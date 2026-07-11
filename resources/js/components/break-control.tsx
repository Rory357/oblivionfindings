import { router } from '@inertiajs/react';
import { Coffee, PauseCircle, PlayCircle } from 'lucide-react';
import { useState } from 'react';

import { Button } from '@/components/ui/button';
import { formatRelative } from '@/lib/datetime';
import { Card as GuardrailCard } from '@/components/ui/card';

export default function BreakControl({
    sessionId,
    isOnBreak,
    breakStartedAt,
    breakMinutes,
}: {
    sessionId: number;
    isOnBreak: boolean;
    breakStartedAt: string | null;
    breakMinutes: number;
}) {
    const [submitting, setSubmitting] = useState(false);

    const post = (url: string) => {
        setSubmitting(true);
        router.post(
            url,
            { session_id: sessionId },
            {
                preserveScroll: true,
                onFinish: () => setSubmitting(false),
            },
        );
    };

    if (isOnBreak) {
        return (
            <div className="flex flex-col gap-3 rounded-lg border border-status-warning/30 bg-status-warning-bg p-3 sm:flex-row sm:items-center sm:justify-between">
                <div className="flex items-center gap-2 text-sm">
                    <PauseCircle className="h-4 w-4 text-status-warning" />
                    <span className="font-medium text-status-warning">
                        On break
                    </span>
                    {breakStartedAt ? (
                        <span className="text-status-warning">
                            since {formatRelative(breakStartedAt)}
                        </span>
                    ) : null}
                </div>
                <Button
                    type="button"
                    onClick={() => post('/attendance/break/end')}
                    disabled={submitting}
                    className="sm:w-auto"
                >
                    <PlayCircle className="mr-2 h-4 w-4" />
                    {submitting ? 'Ending...' : 'End break'}
                </Button>
            </div>
        );
    }

    return (
        <GuardrailCard unstyled className="flex flex-col gap-3 rounded-lg border bg-background/80 p-3 sm:flex-row sm:items-center sm:justify-between">
            <div className="flex items-center gap-2 text-sm">
                <Coffee className="h-4 w-4 text-muted-foreground" />
                <span className="font-medium">Breaks</span>
                <span className="text-muted-foreground">
                    {breakMinutes} min recorded
                </span>
            </div>
            <Button
                type="button"
                variant="outline"
                onClick={() => post('/attendance/break/start')}
                disabled={submitting}
                className="sm:w-auto"
            >
                <PauseCircle className="mr-2 h-4 w-4" />
                {submitting ? 'Starting...' : 'Start break'}
            </Button>
        </GuardrailCard>
    );
}
