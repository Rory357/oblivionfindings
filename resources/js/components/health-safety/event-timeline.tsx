/**
 * Event Timeline — read-only, derived from existing data.
 *
 * Builds a chronological timeline from HsEvent, investigations,
 * and corrective actions. No new backend structure required.
 */

import {
    AlertTriangle,
    CheckCircle2,
    ClipboardList,
    Clock,
    FileText,
    Search,
    Shield,
} from 'lucide-react';

interface TimelineEntry {
    date: string; // ISO 8601
    label: string;
    detail?: string;
    icon: React.ElementType;
    color: string; // Tailwind bg class
}

interface Investigation {
    status: string;
    started_at: string | null;
    completed_at: string | null;
    reference_number: string;
    lead_investigator_name: string | null;
}

interface CorrectiveAction {
    status: string;
    reference_number: string;
    title: string;
    due_date: string | null;
    completed_at: string | null;
    verified_at: string | null;
}

interface EventTimelineProps {
    reportedAt: string | null;
    occurredAt: string | null;
    closedAt: string | null;
    investigations: Investigation[];
    correctiveActions: CorrectiveAction[];
}

function fmtDate(iso: string | null): string {
    if (!iso) return '';
    return new Date(iso).toLocaleDateString('en-NZ', {
        day: 'numeric',
        month: 'short',
        year: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
    });
}

export function EventTimeline({
    reportedAt,
    occurredAt,
    closedAt,
    investigations,
    correctiveActions,
}: EventTimelineProps) {
    const entries: TimelineEntry[] = [];

    // Event occurred
    if (occurredAt) {
        entries.push({
            date: occurredAt,
            label: 'Event occurred',
            icon: AlertTriangle,
            color: 'bg-status-critical',
        });
    }

    // Event reported
    if (reportedAt) {
        entries.push({
            date: reportedAt,
            label: 'Event reported',
            icon: Shield,
            color: 'bg-status-info',
        });
    }

    // Investigation milestones
    for (const inv of investigations) {
        if (inv.started_at) {
            entries.push({
                date: inv.started_at,
                label: 'Investigation started',
                detail: `${inv.reference_number}${inv.lead_investigator_name ? ` — ${inv.lead_investigator_name}` : ''}`,
                icon: Search,
                color: 'bg-primary',
            });
        }
        if (inv.completed_at) {
            entries.push({
                date: inv.completed_at,
                label: 'Investigation completed',
                detail: inv.reference_number,
                icon: FileText,
                color: 'bg-primary',
            });
        }
    }

    // Corrective action milestones
    for (const action of correctiveActions) {
        if (action.completed_at) {
            entries.push({
                date: action.completed_at,
                label: 'Action completed',
                detail: `${action.reference_number} — ${action.title}`,
                icon: ClipboardList,
                color: 'bg-status-warning',
            });
        }
        if (action.verified_at) {
            entries.push({
                date: action.verified_at,
                label: 'Action verified',
                detail: action.reference_number,
                icon: CheckCircle2,
                color: 'bg-status-success',
            });
        }
    }

    // Event closed
    if (closedAt) {
        entries.push({
            date: closedAt,
            label: 'Event closed',
            icon: CheckCircle2,
            color: 'bg-muted-foreground/80',
        });
    }

    // Sort chronologically
    entries.sort(
        (a, b) => new Date(a.date).getTime() - new Date(b.date).getTime(),
    );

    if (entries.length === 0) {
        return (
            <div className="py-8 text-center text-sm text-muted-foreground">
                <Clock className="mx-auto mb-2 h-8 w-8 opacity-30" />
                No timeline events yet.
            </div>
        );
    }

    return (
        <div className="relative space-y-0 pl-6">
            {/* Vertical line */}
            <div className="absolute top-2 bottom-2 left-[11px] w-px bg-border" />

            {entries.map((entry, i) => {
                const Icon = entry.icon;
                return (
                    <div key={i} className="relative flex gap-3 pb-4">
                        {/* Dot */}
                        <div
                            className={`absolute top-0.5 -left-6 flex h-[22px] w-[22px] shrink-0 items-center justify-center rounded-full ${entry.color} text-white`}
                        >
                            <Icon className="h-3 w-3" />
                        </div>

                        {/* Content */}
                        <div className="min-w-0 pt-0.5">
                            <div className="flex items-center gap-2">
                                <span className="text-sm font-medium">
                                    {entry.label}
                                </span>
                                <span className="text-xs text-muted-foreground">
                                    {fmtDate(entry.date)}
                                </span>
                            </div>
                            {entry.detail && (
                                <p className="mt-0.5 max-w-md truncate text-xs text-muted-foreground">
                                    {entry.detail}
                                </p>
                            )}
                        </div>
                    </div>
                );
            })}
        </div>
    );
}
