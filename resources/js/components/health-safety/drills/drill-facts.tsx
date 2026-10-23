import { formatDateTimeLong } from '@/lib/datetime';
import { fmtEvacTime, outcomeMeta } from '@/pages/health-safety/drills/shared';
import type { ReactNode } from 'react';
import type { DrillWorkspaceDetail } from './types';

export function DrillFact({
    label,
    children,
}: {
    label: string;
    children: ReactNode;
}) {
    return (
        <div className="min-w-0 space-y-1">
            <dt className="text-subtle">{label}</dt>
            <dd className="text-sm break-words whitespace-pre-wrap">
                {children === '' || children == null
                    ? 'Not recorded'
                    : children}
            </dd>
        </div>
    );
}

export function DrillOverview({ detail: d }: { detail: DrillWorkspaceDetail }) {
    return (
        <div className="space-y-5">
            <h2 className="text-section-title">Planning</h2>
            <p className="font-medium">{d.title}</p>
            <dl className="grid gap-4 sm:grid-cols-2">
                <DrillFact label="Site">{d.site?.name}</DrillFact>
                <DrillFact label="Drill type">{d.type_label}</DrillFact>
                <DrillFact label="Scheduled for">
                    {formatDateTimeLong(d.scheduled_at, 'Not recorded')}
                </DrillFact>
                <DrillFact label="Coordinator">{d.coordinator?.name}</DrillFact>
                <DrillFact label="Assembly point">{d.assembly_point}</DrillFact>
                <DrillFact label="Evacuation scheme">
                    {d.evacuation_scheme}
                </DrillFact>
                <DrillFact label="Unannounced drill">
                    {d.is_unannounced ? 'Yes' : 'No'}
                </DrillFact>
                <DrillFact label="Scheduled by">{d.created_by_name}</DrillFact>
            </dl>
            <dl>
                <DrillFact label="Scenario">{d.scenario_description}</DrillFact>
            </dl>
            {d.status === 'cancelled' && (
                <dl className="space-y-3 rounded-lg border border-border p-4">
                    <DrillFact label="Cancelled on">
                        {formatDateTimeLong(
                            d.cancelled_at,
                            'Time not recorded',
                        )}
                    </DrillFact>
                    <DrillFact label="Reason for cancellation">
                        {d.cancellation_reason}
                    </DrillFact>
                </dl>
            )}
            <p className="text-subtle">
                Drill completion records what happened. Findings and any linked
                corrective actions keep their own resolution and verification
                checks.
            </p>
        </div>
    );
}

type RunFacts = Pick<
    DrillWorkspaceDetail,
    | 'status'
    | 'started_at'
    | 'completed_at'
    | 'duration_minutes'
    | 'evacuation_time_seconds'
    | 'outcome'
    | 'all_areas_checked'
    | 'assembly_point_reached'
    | 'roll_call_completed'
    | 'total_participants'
    | 'residents_evacuated'
    | 'weather_conditions'
    | 'improvements_identified'
    | 'observer_notes'
>;
export function DrillRunFacts({ detail: d }: { detail: RunFacts }) {
    const completed = d.status === 'completed';
    const check = (value: boolean) =>
        completed ? (value ? 'Confirmed' : 'Not confirmed') : 'Not recorded';
    const outcome = outcomeMeta(d.outcome);
    return (
        <div className="space-y-5">
            <h2 className="text-section-title">Run & timings</h2>
            <dl className="grid gap-4 sm:grid-cols-2">
                <DrillFact label="Started">
                    {formatDateTimeLong(d.started_at, 'Not recorded')}
                </DrillFact>
                <DrillFact label="Completion recorded">
                    {formatDateTimeLong(d.completed_at, 'Not recorded')}
                </DrillFact>
                <DrillFact label="Duration">
                    {d.duration_minutes === null
                        ? 'Not recorded'
                        : `${d.duration_minutes} min`}
                </DrillFact>
                <DrillFact label="Evacuation time">
                    {d.evacuation_time_seconds === null
                        ? 'Not recorded'
                        : fmtEvacTime(d.evacuation_time_seconds)}
                </DrillFact>
                <DrillFact label="Recorded outcome">
                    {outcome?.label ?? d.outcome ?? 'Not recorded'}
                </DrillFact>
                <DrillFact label="Weather">{d.weather_conditions}</DrillFact>
            </dl>
            <h3 className="font-semibold">Recorded roll-call</h3>
            <dl className="grid gap-4 sm:grid-cols-2">
                <DrillFact label="Total participants recorded">
                    {d.total_participants ?? 'Not recorded'}
                </DrillFact>
                <DrillFact label="Residents evacuated — recorded count">
                    {d.residents_evacuated ?? 'Not recorded'}
                </DrillFact>
                <DrillFact label="Assembly point reached">
                    {check(d.assembly_point_reached)}
                </DrillFact>
                <DrillFact label="Roll-call completed">
                    {check(d.roll_call_completed)}
                </DrillFact>
                <DrillFact label="All areas checked">
                    {check(d.all_areas_checked)}
                </DrillFact>
            </dl>
            <p className="text-subtle">
                Participant totals and the recorded evacuated count are
                separate. These counts alone do not establish that every
                resident was safely evacuated.
            </p>
            <dl className="space-y-4">
                <DrillFact label="Improvements identified">
                    {d.improvements_identified}
                </DrillFact>
                <DrillFact label="Observer notes">{d.observer_notes}</DrillFact>
            </dl>
        </div>
    );
}
