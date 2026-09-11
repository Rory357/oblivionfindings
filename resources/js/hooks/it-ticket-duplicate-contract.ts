import { draftRecord } from './it-ticket-draft-contract';

export const duplicateReasonLabels = {
    same_title: 'Same title, site and work type',
    same_service_and_title_words:
        'Same service, site and work type; at least two matching title words',
} as const;
export interface DuplicateMatch {
    id: number;
    reference: string;
    title: string;
    status: 'open' | 'in_progress' | 'waiting';
    reasons: (keyof typeof duplicateReasonLabels)[];
    href: string;
}
export function readDuplicateMatches(
    value: unknown,
    actorId: number,
    queryUuid: string,
    sourceId: number | null,
): DuplicateMatch[] | null {
    if (
        !draftRecord(value) ||
        value.viewer_user_id !== actorId ||
        value.query_uuid !== queryUuid ||
        value.source_id !== sourceId ||
        !Array.isArray(value.matches) ||
        value.matches.length > 10
    )
        return null;
    const ids = new Set<number>();
    for (const match of value.matches) {
        if (
            !draftRecord(match) ||
            !Number.isSafeInteger(match.id) ||
            Number(match.id) < 1 ||
            match.id === sourceId ||
            ids.has(Number(match.id)) ||
            typeof match.reference !== 'string' ||
            typeof match.title !== 'string' ||
            !['open', 'in_progress', 'waiting'].includes(
                String(match.status),
            ) ||
            match.href !== `/it/tickets/${match.id}` ||
            !Array.isArray(match.reasons) ||
            !match.reasons.length ||
            match.reasons.some(
                (reason) =>
                    typeof reason !== 'string' ||
                    !Object.hasOwn(duplicateReasonLabels, reason),
            )
        )
            return null;
        ids.add(Number(match.id));
    }
    return value.matches as DuplicateMatch[];
}
