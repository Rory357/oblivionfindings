import { CheckCircle2 } from 'lucide-react';

interface StatusTimelineProps {
    currentStatus: string;
    statuses?: string[];
}

const DEFAULT_STATUSES = ['draft', 'active', 'review', 'archived'];

const STATUS_LABELS: Record<string, string> = {
    draft: 'Draft',
    active: 'Active',
    review: 'In Review',
    archived: 'Archived',
};

export function StatusTimeline({ currentStatus, statuses = DEFAULT_STATUSES }: StatusTimelineProps) {
    const currentIndex = statuses.indexOf(currentStatus);

    return (
        <div className="flex items-center w-full">
            {statuses.map((status, i) => {
                const isCompleted = currentIndex > i;
                const isCurrent = currentIndex === i;
                const isFuture = currentIndex < i;

                return (
                    <div key={status} className="flex items-center flex-1 last:flex-none">
                        {/* Node */}
                        <div className="flex flex-col items-center">
                            <div
                                className={`flex h-8 w-8 items-center justify-center rounded-full border-2 transition-colors ${
                                    isCompleted
                                        ? 'border-emerald-500 bg-emerald-500'
                                        : isCurrent
                                          ? 'border-primary bg-primary'
                                          : 'border-border bg-white dark:border-slate-600 dark:bg-muted'
                                }`}
                            >
                                {isCompleted ? (
                                    <CheckCircle2 className="h-4 w-4 text-white" />
                                ) : isCurrent ? (
                                    <div className="h-2.5 w-2.5 rounded-full bg-white" />
                                ) : (
                                    <div className="h-2.5 w-2.5 rounded-full bg-slate-300 dark:bg-slate-600" />
                                )}
                            </div>
                            <span
                                className={`mt-1.5 text-[10px] font-medium whitespace-nowrap ${
                                    isCompleted
                                        ? 'text-emerald-600 dark:text-emerald-400'
                                        : isCurrent
                                          ? 'text-primary dark:text-primary'
                                          : 'text-muted-foreground dark:text-muted-foreground'
                                }`}
                            >
                                {STATUS_LABELS[status] ?? status}
                            </span>
                        </div>

                        {/* Connecting line */}
                        {i < statuses.length - 1 && (
                            <div
                                className={`h-0.5 flex-1 mx-1 ${
                                    currentIndex > i
                                        ? 'bg-emerald-500'
                                        : 'bg-muted dark:bg-slate-700'
                                }`}
                            />
                        )}
                    </div>
                );
            })}
        </div>
    );
}
