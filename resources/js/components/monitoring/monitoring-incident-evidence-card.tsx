import { Card } from '@/components/ui/card';
import { StatusBadge } from '@/components/ui/status-badge';
import { formatDateTime } from '@/lib/datetime';
import {
    CheckCircle2,
    Clock3,
    LockKeyhole,
    RadioTower,
    Server,
} from 'lucide-react';

export interface MonitoringIncidentEvidence {
    id: number;
    version: number;
    captured_at: string | null;
    checksum: string;
    integrity: 'verified';
    site: { id?: number; name?: string | null };
    alert: {
        id?: number;
        reference?: string | null;
        type?: string | null;
        severity?: string | null;
        source?: string | null;
        triggered_at?: string | null;
    };
    ticket: { id?: number; reference?: string | null; title?: string | null };
    device: {
        id?: number;
        uid?: string | null;
        name?: string | null;
        domain?: string | null;
        category?: string | null;
        subcategory?: string | null;
        status?: string | null;
        health_status?: string | null;
        last_seen_at?: string | null;
    };
    observation: {
        id?: number;
        event_type?: string | null;
        severity?: string | null;
        source?: string | null;
        occurred_at?: string | null;
        message?: string | null;
        monitor_correlation_key?: string | null;
    };
}

const label = (value: string | null | undefined) =>
    value
        ? value
              .replace(/[_-]/g, ' ')
              .replace(/^\w/, (character) => character.toUpperCase())
        : 'Not recorded';

export function MonitoringIncidentEvidenceCard({
    evidence,
}: {
    evidence: MonitoringIncidentEvidence;
}) {
    return (
        <article
            data-testid="monitoring-incident-evidence"
            className="overflow-hidden rounded-xl border border-status-info/30 bg-status-info-bg/50"
        >
            <div className="flex items-start gap-2.5 border-b border-status-info/20 px-3 py-2.5">
                <LockKeyhole
                    aria-hidden="true"
                    className="mt-0.5 h-4 w-4 flex-none text-status-info"
                />
                <div className="min-w-0 flex-1">
                    <div className="flex flex-wrap items-center justify-between gap-2">
                        <h3 className="text-[12.5px] font-bold text-foreground">
                            Frozen when the incident was raised
                        </h3>
                        <StatusBadge variant="success" size="sm">
                            <CheckCircle2
                                aria-hidden="true"
                                className="h-3 w-3"
                            />
                            Integrity verified
                        </StatusBadge>
                    </div>
                    <p className="mt-0.5 text-[11px] text-muted-foreground">
                        Sealed {formatDateTime(evidence.captured_at)} · later
                        live changes do not rewrite this evidence.
                    </p>
                </div>
            </div>

            <dl className="grid gap-2 px-3 py-3 sm:grid-cols-2">
                <Card
                    unstyled
                    className="rounded-lg border border-border/60 bg-background/70 px-2.5 py-2"
                >
                    <dt className="flex items-center gap-1.5 text-[10.5px] font-bold tracking-wide text-muted-foreground uppercase">
                        <RadioTower
                            aria-hidden="true"
                            className="h-3.5 w-3.5"
                        />
                        Original alert
                    </dt>
                    <dd className="mt-1 text-[12px] font-semibold text-foreground">
                        {evidence.alert.reference ?? 'Control Room alert'} ·{' '}
                        {label(evidence.alert.type)}
                    </dd>
                    <dd className="mt-1 flex flex-wrap items-center gap-1.5 text-[11px] text-muted-foreground">
                        <StatusBadge
                            variant={
                                evidence.alert.severity === 'critical' ||
                                evidence.alert.severity === 'high'
                                    ? 'critical'
                                    : 'warning'
                            }
                            size="sm"
                        >
                            {label(evidence.alert.severity)}
                        </StatusBadge>
                        <span>
                            {formatDateTime(evidence.alert.triggered_at)}
                        </span>
                    </dd>
                </Card>

                <Card
                    unstyled
                    className="rounded-lg border border-border/60 bg-background/70 px-2.5 py-2"
                >
                    <dt className="flex items-center gap-1.5 text-[10.5px] font-bold tracking-wide text-muted-foreground uppercase">
                        <Server aria-hidden="true" className="h-3.5 w-3.5" />
                        Device at capture
                    </dt>
                    <dd className="mt-1 truncate text-[12px] font-semibold text-foreground">
                        {evidence.device.name ?? 'Monitored device'}
                    </dd>
                    <dd className="mt-0.5 text-[11px] text-muted-foreground">
                        {[evidence.device.uid, evidence.site.name]
                            .filter(Boolean)
                            .join(' · ')}
                    </dd>
                    <dd className="mt-1 flex flex-wrap gap-1.5">
                        <StatusBadge variant="neutral" size="sm">
                            {label(evidence.device.status)}
                        </StatusBadge>
                        <StatusBadge variant="neutral" size="sm">
                            {label(evidence.device.health_status)}
                        </StatusBadge>
                    </dd>
                </Card>

                <Card
                    unstyled
                    className="rounded-lg border border-border/60 bg-background/70 px-2.5 py-2 sm:col-span-2"
                >
                    <dt className="flex items-center gap-1.5 text-[10.5px] font-bold tracking-wide text-muted-foreground uppercase">
                        <Clock3 aria-hidden="true" className="h-3.5 w-3.5" />
                        Trigger observation
                    </dt>
                    <dd className="mt-1 text-[12px] font-semibold text-foreground">
                        {label(evidence.observation.event_type)} ·{' '}
                        {formatDateTime(evidence.observation.occurred_at)}
                    </dd>
                    {evidence.observation.message ? (
                        <dd className="mt-1 text-[11.5px] text-muted-foreground">
                            {evidence.observation.message}
                        </dd>
                    ) : null}
                </Card>
            </dl>

            <p className="border-t border-status-info/20 px-3 py-2 font-mono text-[10px] text-muted-foreground">
                Evidence v{evidence.version} · SHA-256{' '}
                {evidence.checksum.slice(0, 12)}…
            </p>
        </article>
    );
}
