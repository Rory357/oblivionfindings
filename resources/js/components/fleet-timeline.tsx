import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { severityVariant } from '@/lib/fleet-utils';
import {
    AlertTriangle,
    Bookmark,
    Car,
    Check,
    CheckCircle,
    Clock,
    Fuel,
    MapPin,
    Play,
    Radio,
    Route,
    Shield,
    ShieldAlert,
    Square,
    Truck,
    User,
    WifiOff,
    XCircle,
} from 'lucide-react';
import { useState } from 'react';

export type TimelineEntry = {
    id: string;
    timestamp: string | null;
    type: string;
    category: 'audit' | 'signal' | 'alert';
    severity: string;
    actor: string | null;
    detail: string;
    meta?: Record<string, any>;
};

type Props = {
    entries: TimelineEntry[];
    title?: string;
    maxVisible?: number;
};

const TYPE_ICONS: Record<string, typeof Truck> = {
    // Bookings
    booking_created: Bookmark,
    booking_approved: Check,
    booking_rejected: XCircle,
    booking_updated: Bookmark,
    vehicle_checkout: Car,
    vehicle_returned: Car,
    // Trips
    trip_started: Play,
    trip_ended: Square,
    // Outings
    outing_created: Route,
    outing_started: Play,
    outing_completed: CheckCircle,
    outing_updated: Route,
    // Driver
    driver_session_started: User,
    driver_session_ended: User,
    // Handovers
    handover_created: Car,
    // Fuel
    fuel_logged: Fuel,
    // Inspections
    inspection_completed: CheckCircle,
    // Incidents
    incident_reported: AlertTriangle,
    incident_updated: AlertTriangle,
    incident_signal: AlertTriangle,
    // Geofence
    geofence_enter: MapPin,
    geofence_exit: MapPin,
    geofence_breach: ShieldAlert,
    geofence_dwell: Clock,
    // Signals
    sos_alert: AlertTriangle,
    device_tamper: Shield,
    vehicle_offline: WifiOff,
    device_offline: WifiOff,
    low_battery: AlertTriangle,
    vehicle_overdue: Clock,
    wof_expiring: AlertTriangle,
    wof_expired: XCircle,
    registration_expiring: AlertTriangle,
    maintenance_overdue: AlertTriangle,
    vehicle_updated: Car,
    // Alerts
    control_room_alert: Radio,
};

const SEVERITY_COLORS: Record<string, string> = {
    critical: 'text-status-critical dark:text-status-critical',
    high: 'text-status-warning dark:text-status-warning',
    medium: 'text-status-warning dark:text-status-warning',
    low: 'text-muted-foreground',
};

const SEVERITY_DOT: Record<string, string> = {
    critical: 'bg-status-critical',
    high: 'bg-status-warning',
    medium: 'bg-status-warning',
    low: 'bg-muted dark:bg-muted-foreground/80',
};

const CATEGORY_LABEL: Record<string, string> = {
    audit: 'Action',
    signal: 'Signal',
    alert: 'Alert',
};

function groupByDate(
    entries: TimelineEntry[],
): Record<string, TimelineEntry[]> {
    const groups: Record<string, TimelineEntry[]> = {};
    for (const entry of entries) {
        const date = entry.timestamp
            ? new Date(entry.timestamp).toLocaleDateString('en-NZ', {
                  weekday: 'short',
                  day: 'numeric',
                  month: 'short',
              })
            : 'Unknown';
        (groups[date] ??= []).push(entry);
    }
    return groups;
}

export default function FleetTimeline({
    entries,
    title = 'Timeline',
    maxVisible = 30,
}: Props) {
    const [showAll, setShowAll] = useState(false);
    const visible = showAll ? entries : entries.slice(0, maxVisible);
    const groups = groupByDate(visible);

    return (
        <Card>
            <CardHeader className="pb-2">
                <CardTitle className="flex items-center gap-2 text-base">
                    <Clock className="h-4 w-4" />
                    {title}
                    {entries.length > 0 && (
                        <Badge variant="outline" className="ml-1 text-[10px]">
                            {entries.length}
                        </Badge>
                    )}
                </CardTitle>
            </CardHeader>
            <CardContent>
                {entries.length === 0 ? (
                    <div className="py-8 text-center text-muted-foreground">
                        <Clock className="mx-auto mb-2 h-8 w-8 opacity-30" />
                        <p className="text-sm">No recent activity</p>
                        <p className="text-xs">
                            Events will appear here as they happen.
                        </p>
                    </div>
                ) : (
                    <div className="space-y-4">
                        {Object.entries(groups).map(([date, items]) => (
                            <div key={date}>
                                <div className="mb-2 text-[10px] font-semibold text-muted-foreground uppercase">
                                    {date}
                                </div>
                                <div className="relative ml-3 space-y-0 border-l border-border/60 pl-4">
                                    {items.map((entry) => {
                                        const Icon =
                                            TYPE_ICONS[entry.type] ?? Radio;
                                        const dotColor =
                                            SEVERITY_DOT[entry.severity] ??
                                            SEVERITY_DOT.low;
                                        const textColor =
                                            SEVERITY_COLORS[entry.severity] ??
                                            SEVERITY_COLORS.low;
                                        const time = entry.timestamp
                                            ? new Date(
                                                  entry.timestamp,
                                              ).toLocaleTimeString('en-NZ', {
                                                  hour: '2-digit',
                                                  minute: '2-digit',
                                              })
                                            : '';

                                        return (
                                            <div
                                                key={entry.id}
                                                className="group relative pb-3 last:pb-0"
                                            >
                                                {/* Timeline dot */}
                                                <div
                                                    className={`absolute top-1.5 -left-[1.35rem] h-2.5 w-2.5 rounded-full border-2 border-background ${dotColor}`}
                                                />

                                                <div className="flex items-start gap-2">
                                                    <Icon
                                                        className={`mt-0.5 h-3.5 w-3.5 shrink-0 ${textColor}`}
                                                    />
                                                    <div className="min-w-0 flex-1">
                                                        <div className="flex flex-wrap items-center gap-1.5">
                                                            <span className="text-sm font-medium">
                                                                {entry.detail}
                                                            </span>
                                                            {entry.severity !==
                                                                'low' && (
                                                                <Badge
                                                                    variant={severityVariant(
                                                                        entry.severity,
                                                                    )}
                                                                    className="h-3.5 px-1 text-[8px]"
                                                                >
                                                                    {
                                                                        entry.severity
                                                                    }
                                                                </Badge>
                                                            )}
                                                        </div>
                                                        <div className="mt-0.5 flex items-center gap-2 text-[10px] text-muted-foreground">
                                                            <span>{time}</span>
                                                            {entry.actor && (
                                                                <>
                                                                    <span>
                                                                        ·
                                                                    </span>
                                                                    <span>
                                                                        {
                                                                            entry.actor
                                                                        }
                                                                    </span>
                                                                </>
                                                            )}
                                                            <span>·</span>
                                                            <span className="capitalize">
                                                                {CATEGORY_LABEL[
                                                                    entry
                                                                        .category
                                                                ] ??
                                                                    entry.category}
                                                            </span>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                        );
                                    })}
                                </div>
                            </div>
                        ))}

                        {!showAll && entries.length > maxVisible && (
                            <Button
                                type="button"
                                variant="outline"
                                onClick={() => setShowAll(true)}
                                className="h-auto w-full rounded-md border-dashed py-2 text-xs text-muted-foreground transition-colors hover:border-foreground/30 hover:text-foreground"
                            >
                                Show {entries.length - maxVisible} more events
                            </Button>
                        )}
                    </div>
                )}
            </CardContent>
        </Card>
    );
}
