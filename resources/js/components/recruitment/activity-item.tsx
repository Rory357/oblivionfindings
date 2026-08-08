import {
    ArrowRight,
    Calendar,
    FileText,
    Gift,
    MessageSquare,
} from 'lucide-react';

interface ActivityItemProps {
    type: 'status_change' | 'interview' | 'offer' | 'note' | 'application';
    description: string;
    timestamp: string;
    actor?: string;
}

const typeConfig: Record<
    string,
    { icon: React.ElementType; dotColor: string }
> = {
    status_change: { icon: ArrowRight, dotColor: 'bg-status-info' },
    interview: { icon: Calendar, dotColor: 'bg-status-warning' },
    offer: { icon: Gift, dotColor: 'bg-status-success' },
    note: { icon: MessageSquare, dotColor: 'bg-slate-500' },
    application: { icon: FileText, dotColor: 'bg-primary' },
};

export function ActivityItem({
    type,
    description,
    timestamp,
    actor,
}: ActivityItemProps) {
    const config = typeConfig[type] ?? typeConfig.note;
    const Icon = config.icon;

    return (
        <div className="group flex gap-3">
            <div className="flex flex-col items-center">
                <div
                    className={`mt-1 flex h-6 w-6 shrink-0 items-center justify-center rounded-full ${config.dotColor}/10`}
                >
                    <Icon
                        className={`h-3 w-3 ${config.dotColor.replace('bg-', 'text-')}`}
                    />
                </div>
                <div className="w-px flex-1 bg-border group-last:hidden" />
            </div>
            <div className="min-w-0 pb-4">
                <p className="text-sm">{description}</p>
                <div className="mt-0.5 flex items-center gap-2 text-xs text-muted-foreground">
                    <span>{timestamp}</span>
                    {actor && <span>by {actor}</span>}
                </div>
            </div>
        </div>
    );
}
