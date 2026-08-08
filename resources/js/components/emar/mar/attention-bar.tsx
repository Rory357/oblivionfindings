import { Button } from '@/components/ui/button';
import { cn } from '@/lib/utils';
import { AlertTriangle } from 'lucide-react';

export type AttentionAlert = {
    id: number;
    type: string;
    title: string;
    detail?: string | null;
    prompt_on_open: boolean;
};

type Props = {
    alerts: AttentionAlert[];
    onReview: () => void;
    onManage: () => void;
    canManage: boolean;
};

function toneFor(type: string): string {
    switch (type) {
        case 'warfarin':
            return 'bg-status-critical-bg text-status-critical';
        case 'paper_prescription':
            return 'bg-status-warning-bg text-status-warning';
        default:
            return 'bg-muted text-muted-foreground';
    }
}

export default function AttentionBar({
    alerts,
    onReview,
    onManage,
    canManage,
}: Props) {
    if (alerts.length === 0) {
        return null;
    }

    return (
        <div className="flex flex-wrap items-center gap-3 rounded-xl border border-status-critical/30 bg-status-critical-bg/50 px-4 py-3">
            <span className="flex items-center gap-1.5 text-[13px] font-bold text-status-critical">
                <AlertTriangle className="h-4 w-4" />
                Attention
            </span>
            <div className="flex flex-1 flex-wrap items-center gap-1.5">
                {alerts.map((alert) => (
                    <span
                        key={alert.id}
                        className={cn(
                            'inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-xs font-medium',
                            toneFor(alert.type),
                        )}
                        title={alert.detail ?? undefined}
                    >
                        <span className="h-1.5 w-1.5 rounded-full bg-current" />
                        {alert.title}
                    </span>
                ))}
            </div>
            <div className="flex items-center gap-2">
                <Button variant="outline" size="sm" onClick={onReview}>
                    Review warnings
                </Button>
                {canManage && (
                    <Button variant="outline" size="sm" onClick={onManage}>
                        Manage alerts
                    </Button>
                )}
            </div>
        </div>
    );
}
