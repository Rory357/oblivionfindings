import { Button } from '@/components/ui/button';
import { formatRelativeTime } from '@/lib/fleet-utils';
import { ShieldAlert, ShieldCheck } from 'lucide-react';

type Props = {
    panicActive: boolean;
    lastSafetyEvent?: string | null;
    lastSafetyEventAt?: string | null;
    canManage?: boolean;
    onAcknowledge?: () => void;
    compact?: boolean;
};

function safetyEventLabel(event?: string | null): string {
    switch (event) {
        case 'vehicle_sos':
        case 'sos':
            return 'SOS received';
        case 'man_down':
            return 'Man down alert';
        default:
            return event ? event.replace(/_/g, ' ') : 'Panic';
    }
}

export default function PanicStatusBadge({
    panicActive,
    lastSafetyEvent,
    lastSafetyEventAt,
    canManage,
    onAcknowledge,
    compact = false,
}: Props) {
    if (panicActive) {
        return (
            <div
                className={`flex items-center justify-between gap-3 rounded-md border border-status-critical/30 bg-status-critical-bg px-3 py-2 ${
                    compact ? 'text-xs' : 'text-sm'
                }`}
                role="alert"
            >
                <div className="flex items-center gap-2 text-status-critical">
                    <ShieldAlert className="h-4 w-4 shrink-0" />
                    <div className="leading-tight">
                        <div className="font-semibold">
                            {safetyEventLabel(lastSafetyEvent)}
                        </div>
                        {lastSafetyEventAt && (
                            <div className="text-[11px] opacity-80">
                                Triggered {formatRelativeTime(lastSafetyEventAt)}
                            </div>
                        )}
                    </div>
                </div>
                {canManage && onAcknowledge && (
                    <Button
                        type="button"
                        size="sm"
                        variant="outline"
                        className="border-status-critical/30 text-status-critical hover:bg-status-critical/10"
                        onClick={onAcknowledge}
                    >
                        Acknowledge
                    </Button>
                )}
            </div>
        );
    }

    return (
        <div
            className={`flex items-center gap-2 rounded-md border border-status-success/20 bg-status-success-bg/60 px-3 py-2 text-status-success ${
                compact ? 'text-xs' : 'text-sm'
            }`}
        >
            <ShieldCheck className="h-4 w-4 shrink-0" />
            <div className="leading-tight">
                <div className="font-medium">Panic not currently active</div>
                <div className="text-[11px] opacity-80">
                    {lastSafetyEventAt
                        ? `Last panic: ${safetyEventLabel(lastSafetyEvent)} · ${formatRelativeTime(
                              lastSafetyEventAt,
                          )}`
                        : 'No panic events recorded'}
                </div>
            </div>
        </div>
    );
}
