/* Incidents & Accidents tab — design surface (tabs-plans.jsx IncidentsTab):
 * stat strip + severity-toned incident cards with the actions-taken panel.
 * Extracted from show.tsx (Babel deopt threshold). */
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { formatDateTimeLong } from '@/lib/datetime';
import { Link } from '@inertiajs/react';
import { AlertTriangle, Check } from 'lucide-react';

const SEV_TILE: Record<string, string> = {
    critical: 'bg-status-critical-bg text-status-critical',
    high: 'bg-status-critical-bg text-status-critical',
    medium: 'bg-status-warning-bg text-status-warning',
    low: 'bg-status-success-bg text-status-success',
};

export function IncidentsTab({ incidents }: { incidents: any[] }) {
    const openCount = incidents.filter(
        (i) =>
            !['closed', 'resolved'].includes(
                String(i.status ?? '').toLowerCase(),
            ),
    ).length;
    const last30 = incidents.filter(
        (i) =>
            i.occurred_at &&
            Date.now() - new Date(i.occurred_at).getTime() < 30 * 86400000,
    ).length;
    const notifiable = incidents.filter((i) => i.is_notifiable).length;

    return (
        <div className="space-y-4">
            {/* Stat strip */}
            <div className="grid grid-cols-2 gap-3 sm:grid-cols-4">
                {(
                    [
                        [
                            'Recent incidents',
                            incidents.length,
                            'text-status-warning',
                        ],
                        ['Last 30 days', last30, 'text-status-info'],
                        [
                            'Open',
                            openCount,
                            openCount > 0
                                ? 'text-status-critical'
                                : 'text-status-success',
                        ],
                        [
                            'Notifiable',
                            notifiable,
                            notifiable > 0
                                ? 'text-status-critical'
                                : 'text-muted-foreground',
                        ],
                    ] as [string, number, string][]
                ).map(([label, value, tone]) => (
                    /* eslint-disable-next-line no-restricted-syntax -- MiniStat tile per the profile pattern language */
                    <div
                        key={label}
                        className="rounded-xl border bg-card px-4 py-3"
                    >
                        <div className={`text-xl font-bold ${tone}`}>
                            {value}
                        </div>
                        <div className="text-xs text-muted-foreground">
                            {label}
                        </div>
                    </div>
                ))}
            </div>

            {/* Incident cards */}
            {incidents.length ? (
                <div className="space-y-3">
                    {incidents.map((inc: any) => {
                        const sev = String(inc.severity ?? 'low').toLowerCase();
                        const closed = ['closed', 'resolved'].includes(
                            String(inc.status ?? '').toLowerCase(),
                        );
                        return (
                            <Card key={inc.id} className="p-4">
                                <div className="flex items-start gap-3">
                                    <span
                                        className={`mt-0.5 flex h-9 w-9 shrink-0 items-center justify-center rounded-lg ${SEV_TILE[sev] ?? 'bg-muted text-muted-foreground'}`}
                                    >
                                        <AlertTriangle className="h-[17px] w-[17px]" />
                                    </span>
                                    <div className="min-w-0 flex-1">
                                        <div className="flex flex-wrap items-center gap-2">
                                            <span className="text-sm font-semibold capitalize">
                                                {inc.title ??
                                                    inc.type ??
                                                    'Incident'}
                                            </span>
                                            <Badge
                                                className={`border-0 capitalize ${SEV_TILE[sev] ?? 'bg-muted text-muted-foreground'}`}
                                            >
                                                {sev}
                                            </Badge>
                                            <Badge
                                                className={`border-0 capitalize ${closed ? 'bg-status-success-bg text-status-success' : 'bg-status-warning-bg text-status-warning'}`}
                                            >
                                                {String(
                                                    inc.status ?? 'open',
                                                ).replace(/_/g, ' ')}
                                            </Badge>
                                            <span className="ml-auto text-[11px] text-muted-foreground">
                                                {inc.occurred_at
                                                    ? formatDateTimeLong(
                                                          inc.occurred_at,
                                                      )
                                                    : ''}
                                                {inc.reporter?.name
                                                    ? ` · ${inc.reporter.name}`
                                                    : ''}
                                            </span>
                                        </div>
                                        {inc.description ? (
                                            <p className="mt-1.5 text-sm leading-relaxed text-foreground/90">
                                                {inc.description}
                                            </p>
                                        ) : null}
                                        {inc.immediate_action_taken ? (
                                            <div className="mt-2 rounded-lg bg-muted/40 p-3">
                                                <div className="mb-0.5 flex items-center gap-1.5 text-[11px] font-semibold tracking-wide text-muted-foreground uppercase">
                                                    <Check className="h-3 w-3" />{' '}
                                                    Actions taken
                                                </div>
                                                <p className="text-sm leading-relaxed text-foreground/85">
                                                    {inc.immediate_action_taken}
                                                </p>
                                            </div>
                                        ) : null}
                                        <div className="mt-2">
                                            <Button
                                                size="sm"
                                                variant="outline"
                                                asChild
                                            >
                                                <Link
                                                    href={`/incidents/${inc.id}`}
                                                >
                                                    Open incident
                                                </Link>
                                            </Button>
                                        </div>
                                    </div>
                                </div>
                            </Card>
                        );
                    })}
                </div>
            ) : (
                <Card className="border-dashed">
                    <CardContent className="flex flex-col items-center justify-center py-12">
                        <div className="mb-3 flex h-12 w-12 items-center justify-center rounded-full bg-muted text-muted-foreground">
                            <AlertTriangle className="h-[22px] w-[22px]" />
                        </div>
                        <p className="text-sm font-medium">
                            No incidents recorded
                        </p>
                        <p className="mt-1 max-w-xs text-center text-xs text-muted-foreground">
                            Log an incident from the button above — it appears
                            here and on the timeline.
                        </p>
                    </CardContent>
                </Card>
            )}
        </div>
    );
}
