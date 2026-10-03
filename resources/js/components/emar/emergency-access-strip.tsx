import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { formatTime } from '@/lib/datetime';
import { emergencyTimeLeft } from '@/lib/emergency-access';
import { Link } from '@inertiajs/react';
import { Clock } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';

export type EmergencyAccessStripGrant = {
    id: number;
    client_id: number;
    client_name: string;
    expires_at: string;
    can_extend?: boolean;
};

/** P01/P02 seam: keep this mounted beside the existing form so expiry never discards its draft. */
export function EmergencyAccessStrip({
    grant,
    onExtend,
    onEnded,
    onStartAgain,
}: {
    grant: EmergencyAccessStripGrant;
    onExtend?: () => void;
    onEnded?: () => void;
    onStartAgain?: () => void;
}) {
    const [now, setNow] = useState(Date.now());
    const left = emergencyTimeLeft(grant.expires_at, now);
    const endedFor = useRef<string | null>(null);
    useEffect(() => {
        const id = window.setInterval(() => setNow(Date.now()), 1000);
        return () => window.clearInterval(id);
    }, []);
    useEffect(() => {
        const identity = `${grant.id}:${grant.expires_at}`;
        if (left.ended && endedFor.current !== identity) {
            endedFor.current = identity;
            onEnded?.();
        }
    }, [grant.id, grant.expires_at, left.ended, onEnded]);
    return (
        <Alert
            role="region"
            aria-label="Emergency access"
            aria-live="off"
            className={
                left.warning || left.ended
                    ? 'border-status-warning bg-status-warning-bg text-status-warning-foreground'
                    : ''
            }
        >
            <Clock className="size-4" />
            <AlertTitle aria-live="polite">
                {left.ended
                    ? 'Emergency access ended — your entry stays here'
                    : `Emergency access — ${grant.client_name}`}
            </AlertTitle>
            <AlertDescription className="flex flex-wrap items-center justify-between gap-3">
                <span>
                    {left.ended
                        ? 'Start it again, or ask someone on shift to record this dose.'
                        : `Only this person’s medication record · ends ${formatTime(grant.expires_at)} · ${left.label} left`}
                </span>
                {left.ended ? (
                    onStartAgain ? (
                        <Button
                            className="frontline-tap"
                            onClick={onStartAgain}
                        >
                            Start it again
                        </Button>
                    ) : (
                        <Button asChild className="frontline-tap">
                            <Link
                                href={`/emar/emergency-access?request_client=${grant.client_id}`}
                            >
                                Start it again
                            </Link>
                        </Button>
                    )
                ) : left.warning && grant.can_extend && onExtend ? (
                    <Button
                        variant="outline"
                        className="frontline-tap"
                        onClick={onExtend}
                    >
                        Extend
                    </Button>
                ) : (
                    <Button variant="outline" asChild className="frontline-tap">
                        <Link href="/emar/emergency-access">Open grant</Link>
                    </Button>
                )}
            </AlertDescription>
        </Alert>
    );
}
