import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import {
    Activity,
    AlertTriangle,
    Droplets,
    Heart,
    Moon,
    Scale,
    Thermometer,
} from 'lucide-react';

export interface HealthSummary {
    latest_observations: Record<
        string,
        | {
              id: number;
              recorded_at: string;
              data: Record<string, any>;
              recorded_by: number;
          }
        | null
    >;
    recent_events: {
        count: number;
        high_severity_count: number;
        items: Array<{
            id: number;
            event_type: string;
            event_type_label: string;
            severity: string;
            occurred_at: string;
            status: string;
        }>;
    };
    protocol_compliance: {
        rate: number;
        due_count: number;
        overdue_count: number;
    };
}

function timeAgo(dateStr: string): string {
    const diff = Date.now() - new Date(dateStr).getTime();
    const hours = Math.floor(diff / 3600000);
    if (hours < 1) return 'just now';
    if (hours < 24) return `${hours}h ago`;
    const days = Math.floor(hours / 24);
    if (days === 1) return '1d ago';
    return `${days}d ago`;
}

function ObsRow({
    icon: Icon,
    label,
    value,
    time,
}: {
    icon: typeof Heart;
    label: string;
    value: string | null;
    time: string | null;
}) {
    return (
        <div className="flex items-center justify-between py-1">
            <div className="flex items-center gap-2 text-xs text-muted-foreground">
                <Icon className="h-3.5 w-3.5" />
                <span>{label}</span>
            </div>
            {value ? (
                <div className="text-right">
                    <span className="text-xs font-medium">{value}</span>
                    {time && (
                        <span className="ml-1.5 text-[10px] text-muted-foreground">
                            {time}
                        </span>
                    )}
                </div>
            ) : (
                <span className="text-[10px] text-muted-foreground">
                    No data
                </span>
            )}
        </div>
    );
}

function formatVitals(
    data: Record<string, any> | undefined,
): string | null {
    if (!data) return null;
    const parts: string[] = [];
    if (data.systolic && data.diastolic)
        parts.push(`${data.systolic}/${data.diastolic}`);
    if (data.pulse) parts.push(`P${data.pulse}`);
    if (data.temperature) parts.push(`${data.temperature}\u00B0C`);
    return parts.length > 0 ? parts.join(' \u00B7 ') : null;
}

export default function HealthSummaryCard({
    summary,
}: {
    summary: HealthSummary | null;
}) {
    if (!summary) return null;

    const { latest_observations: obs, recent_events: events, protocol_compliance: compliance } =
        summary;

    const vitals = obs.vitals;
    const weight = obs.weight;
    const sleep = obs.sleep;
    const fluid = obs.fluid_intake;

    return (
        <Card>
            <CardHeader className="pb-2">
                <CardTitle className="flex items-center gap-2 text-sm">
                    <Activity className="h-4 w-4 text-status-success" />
                    Health Summary
                </CardTitle>
            </CardHeader>
            <CardContent className="space-y-2">
                {/* Key observations */}
                <div className="space-y-0.5">
                    <ObsRow
                        icon={Heart}
                        label="Vitals"
                        value={formatVitals(vitals?.data)}
                        time={vitals ? timeAgo(vitals.recorded_at) : null}
                    />
                    <ObsRow
                        icon={Scale}
                        label="Weight"
                        value={
                            weight?.data?.weight_kg
                                ? `${weight.data.weight_kg} kg`
                                : null
                        }
                        time={weight ? timeAgo(weight.recorded_at) : null}
                    />
                    <ObsRow
                        icon={Moon}
                        label="Sleep"
                        value={
                            sleep?.data?.quality
                                ? `${sleep.data.quality}`
                                : null
                        }
                        time={sleep ? timeAgo(sleep.recorded_at) : null}
                    />
                    <ObsRow
                        icon={Droplets}
                        label="Fluids"
                        value={
                            fluid?.data?.amount_ml
                                ? `${fluid.data.amount_ml}ml`
                                : null
                        }
                        time={fluid ? timeAgo(fluid.recorded_at) : null}
                    />
                </div>

                {/* Protocol compliance */}
                {(compliance.due_count > 0 || compliance.overdue_count > 0) && (
                    <div className="flex items-center gap-2 border-t pt-2">
                        {compliance.overdue_count > 0 && (
                            <Badge
                                variant="destructive"
                                className="text-[10px]"
                            >
                                {compliance.overdue_count} overdue
                            </Badge>
                        )}
                        {compliance.due_count > 0 && (
                            <Badge variant="secondary" className="text-[10px]">
                                {compliance.due_count} due
                            </Badge>
                        )}
                    </div>
                )}

                {/* Recent events */}
                {events.count > 0 && (
                    <div className="flex items-center gap-2 border-t pt-2 text-xs">
                        <AlertTriangle className="h-3.5 w-3.5 text-status-warning" />
                        <span className="text-muted-foreground">
                            {events.count} event{events.count !== 1 ? 's' : ''}{' '}
                            (30d)
                        </span>
                        {events.high_severity_count > 0 && (
                            <Badge
                                variant="destructive"
                                className="text-[10px]"
                            >
                                {events.high_severity_count} high
                            </Badge>
                        )}
                    </div>
                )}
            </CardContent>
        </Card>
    );
}
