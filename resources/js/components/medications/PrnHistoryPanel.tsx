import { Badge } from '@/components/ui/badge';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Clock, History, User } from 'lucide-react';

interface PrnAdministration {
    id: number;
    administered_at: string;
    dose_given?: string;
    reason?: string;
    administered_by?: string;
}

interface Props {
    history: PrnAdministration[];
    count24h: number;
    maxPerDay?: string;
    remainingToday?: number;
}

export default function PrnHistoryPanel({
    history,
    count24h,
    maxPerDay,
    remainingToday,
}: Props) {
    const maxCount = maxPerDay
        ? parseInt(maxPerDay.replace(/\D/g, ''), 10)
        : null;
    const percentage = maxCount ? (count24h / maxCount) * 100 : 0;

    const getBarColor = () => {
        if (percentage >= 100) return 'bg-status-critical';
        if (percentage >= 75) return 'bg-status-warning';
        if (percentage >= 50) return 'bg-status-warning';
        return 'bg-status-success';
    };

    return (
        <Card className="border-border">
            <CardHeader className="pb-3">
                <CardTitle className="flex items-center gap-2 text-sm font-medium">
                    <History className="h-4 w-4" />
                    PRN History (Last 24h)
                </CardTitle>
            </CardHeader>
            <CardContent className="space-y-4">
                {/* Usage Bar */}
                <div className="space-y-2">
                    <div className="flex items-center justify-between text-sm">
                        <span className="text-muted-foreground">Usage</span>
                        <span className="font-medium">
                            {count24h} / {maxPerDay || 'unlimited'}
                            {remainingToday !== null &&
                                remainingToday !== undefined && (
                                    <span className="ml-2 text-muted-foreground">
                                        ({remainingToday} remaining)
                                    </span>
                                )}
                        </span>
                    </div>
                    {maxCount && (
                        <div className="h-2 w-full overflow-hidden rounded-full bg-muted">
                            <div
                                className={`h-full transition-all duration-300 ${getBarColor()}`}
                                style={{
                                    width: `${Math.min(percentage, 100)}%`,
                                }}
                            />
                        </div>
                    )}
                </div>

                {/* History List */}
                {history.length === 0 ? (
                    <div className="text-center text-sm text-muted-foreground">
                        No PRN administrations in last 24 hours
                    </div>
                ) : (
                    <div className="max-h-48 space-y-2 overflow-y-auto">
                        {history.map((admin) => (
                            <div
                                key={admin.id}
                                className="flex items-start gap-2 rounded-md border border-border bg-muted p-2"
                            >
                                <Clock className="mt-0.5 h-3 w-3 shrink-0 text-muted-foreground" />
                                <div className="min-w-0 flex-1 text-xs">
                                    <div className="flex items-center gap-2">
                                        <span className="font-medium">
                                            {new Date(
                                                admin.administered_at,
                                            ).toLocaleTimeString([], {
                                                hour: '2-digit',
                                                minute: '2-digit',
                                            })}
                                        </span>
                                        {admin.dose_given && (
                                            <Badge
                                                variant="outline"
                                                className="text-xs"
                                            >
                                                {admin.dose_given}
                                            </Badge>
                                        )}
                                    </div>
                                    {admin.reason && (
                                        <div className="mt-1 truncate text-muted-foreground">
                                            {admin.reason}
                                        </div>
                                    )}
                                    {admin.administered_by && (
                                        <div className="mt-1 flex items-center gap-1 text-muted-foreground">
                                            <User className="h-3 w-3" />
                                            {admin.administered_by}
                                        </div>
                                    )}
                                </div>
                            </div>
                        ))}
                    </div>
                )}

                {/* Limit Warning */}
                {maxCount && count24h >= maxCount && (
                    <div className="rounded-md border border-status-critical/30 bg-status-critical-bg p-2 text-center text-xs text-status-critical">
                        ⚠️ PRN limit reached - cannot administer
                    </div>
                )}
                {maxCount &&
                    count24h >= maxCount * 0.75 &&
                    count24h < maxCount && (
                        <div className="rounded-md border border-status-warning/30 bg-status-warning-bg p-2 text-center text-xs text-status-warning">
                            ⚠️ Approaching PRN limit
                        </div>
                    )}
            </CardContent>
        </Card>
    );
}
