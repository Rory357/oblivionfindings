import type { TrendPoint } from './analytics-types';

export type ParticipationSourceAccess =
    | 'readable'
    | 'restricted'
    | 'unavailable';
export type ParticipationCoverage = {
    pct: number | null;
    committees: number | null;
    source_access?: ParticipationSourceAccess;
    viewer_id?: number | null;
};

export type ParticipationViewer = { id: number | null; canView: boolean };
export function participationViewer(auth: unknown): ParticipationViewer {
    const current = auth as
        | {
              user?: { id?: unknown } | null;
              can?: { hazards?: { view?: unknown } };
          }
        | null
        | undefined;
    const id = current?.user?.id;
    const hazards = current?.can?.hazards;
    return {
        id:
            typeof id === 'number' && Number.isSafeInteger(id) && id > 0
                ? id
                : null,
        canView: hazards?.view === true,
    };
}
export function participationSourceMatches(
    sourceId: number | null | undefined,
    viewer: ParticipationViewer,
): boolean {
    return (
        typeof sourceId === 'number' &&
        Number.isSafeInteger(sourceId) &&
        sourceId > 0 &&
        sourceId === viewer.id &&
        viewer.canView
    );
}

export function participationValue(
    value: number | null,
    source?: ParticipationSourceAccess,
    sourceActorId?: number | null,
    viewer: ParticipationViewer = { id: null, canView: false },
): number | null {
    return source === 'readable' &&
        participationSourceMatches(sourceActorId, viewer) &&
        value !== null &&
        Number.isFinite(value)
        ? value
        : null;
}

/** A retained denial is also source data; it cannot describe another viewer. */
export function participationAccessForViewer(
    source: ParticipationSourceAccess | undefined,
    sourceId: number | null | undefined,
    viewer: ParticipationViewer,
): ParticipationSourceAccess {
    if (!viewer.canView) return 'restricted';
    return participationSourceMatches(sourceId, viewer)
        ? (source ?? 'unavailable')
        : 'unavailable';
}

/** The accessible table obeys the same source boundary as its visible chart. */
export function workerParticipationTable(
    trends: TrendPoint[],
    viewer: ParticipationViewer,
): (string | number)[][] {
    return trends.map((point) => {
        const access = participationAccessForViewer(
            point.worker_participation_source_access,
            point.worker_participation_viewer_id,
            viewer,
        );
        const missing =
            access === 'restricted'
                ? 'Hidden — you don’t have access'
                : access === 'readable'
                  ? '—'
                  : 'Not available';
        return [
            point.label,
            participationValue(
                point.worker_engagement,
                access,
                point.worker_participation_viewer_id,
                viewer,
            ) ?? missing,
            participationValue(
                point.worker_consultation,
                access,
                point.worker_participation_viewer_id,
                viewer,
            ) ?? missing,
        ];
    });
}
