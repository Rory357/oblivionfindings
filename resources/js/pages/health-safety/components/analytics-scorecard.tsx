/**
 * Trends scorecard (approved H&S drawing 45): prevention and harm as two tallies. Each measure shows its value, its
 * plain name and the change since last month, or why there is none. Worker participation measures keep their
 * source rules: restricted and unconfirmed values never show as numbers. A measure moving the wrong way carries the
 * critical ring.
 */
import { Tally, type TallyItem } from '@/components/signature/blocks';
import { type SharedData } from '@/types';
import { usePage } from '@inertiajs/react';

import type { ScorecardItem } from '../analytics-types';
import {
    participationAccessForViewer,
    participationSourceMatches,
    participationValue,
    participationViewer,
} from '../participation-source-state';

function deltaArrow(dir: string): string {
    return dir === 'improving' ? '▲' : dir === 'watch' ? '▼' : '—';
}

/** The drawing's plain names for the scorecard measures (approved H&S drawing 45); unknown keys keep their label. */
const PLAIN: Record<string, string> = {
    near_miss_ratio: 'Near misses for each incident',
    actions_on_time_pct: 'Corrective actions on time',
    training_pct: 'Training and audits up to date',
    worker_engagement: 'Meetings completed',
    open_hazards: 'Open hazards',
    worker_consultation: 'Consultations finished',
    procedures_reviewed_pct: 'Procedures reviewed on time',
    ltifr: 'Lost-time injury rate',
    trifr: 'Recordable injury rate',
    incidents: 'Incidents',
    injury_severity_rate: 'Injury severity rate',
    lost_time_days: 'Lost-time days',
    days_since_lti: 'Days since a lost-time injury',
};

/**
 * One scorecard measure as a tally item: the value, its plain name, and the change since last month (or why there
 * is none). Worker participation measures keep the participation source rules: restricted and unconfirmed values
 * never show as numbers. A measure moving the wrong way carries the critical ring.
 */
export function useScorecardItems(items: ScorecardItem[]): TallyItem[] {
    const viewer = participationViewer(usePage<SharedData>().props.auth);
    return items.map((item) => {
        const participation = item.key === 'worker_engagement' || item.key === 'worker_consultation';
        const value = participation
            ? participationValue(item.value, item.source_access, item.viewer_id, viewer)
            : item.value;
        const delta = participation
            ? participationValue(item.delta, item.source_access, item.viewer_id, viewer)
            : item.delta;
        const sourceAccess = participationAccessForViewer(item.source_access, item.viewer_id, viewer);
        const unconfirmedParticipation =
            participation &&
            (item.source_access !== 'readable' || !participationSourceMatches(item.viewer_id, viewer));
        return {
            value: value === null || value === undefined ? '—' : `${value}${item.suffix}`,
            label: PLAIN[item.key] ?? item.label,
            tone: item.dir === 'watch' && value !== null ? 'critical' : undefined,
            sub:
                delta !== null && delta !== undefined && value !== null
                    ? `${deltaArrow(item.dir)} ${delta > 0 ? '+' : delta < 0 ? '−' : ''}${Math.abs(delta)} vs last month`
                    : unconfirmedParticipation
                      ? sourceAccess === 'restricted'
                          ? 'Access restricted'
                          : 'Not confirmed'
                      : value === null
                        ? 'Not confirmed'
                        : 'No earlier data',
        };
    });
}

/** Prevention and harm as two tallies (approved H&S drawing 45). */
export function Scorecard({
    leading,
    lagging,
}: {
    leading: ScorecardItem[];
    lagging: ScorecardItem[];
}) {
    const leadingItems = useScorecardItems(leading);
    const laggingItems = useScorecardItems(lagging);
    return (
        <>
            <Tally
                title="Prevention"
                sub="How well we prevent harm and involve staff"
                keepZeros
                items={leadingItems}
            />
            <Tally
                title="Harm that has already happened"
                sub="Lower is better"
                keepZeros
                items={laggingItems}
            />
        </>
    );
}

