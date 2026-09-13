import { draftRecord, IT_DRAFT_UUID } from './it-ticket-draft-contract';

export const relationshipLabels = {
    related_ticket: 'Related ticket',
    duplicate_ticket: 'Possible duplicate',
} as const;
export type TicketRelationship = keyof typeof relationshipLabels;
export interface RelatedTicket {
    id: number;
    reference: string | null;
    title: string;
    status: string;
    work_type: string;
    lock_version: number;
    href: string;
}
export interface RelatedLink {
    id: number;
    relationship: TicketRelationship;
    ticket: RelatedTicket;
    can_remove: boolean;
}
interface RelatedPage<T> {
    data: T[];
    page: number;
    has_more: boolean;
}
export interface RelatedWork {
    viewer_user_id: number;
    source: RelatedTicket;
    can_change: boolean;
    links: RelatedPage<RelatedLink>;
    candidates: RelatedPage<RelatedTicket>;
}
export interface RelationshipIdentity {
    actorId: number;
    sourceId: number;
    targetId: number;
    requestUuid: string;
    action: 'add' | 'remove';
    relationship: TicketRelationship;
}
export interface RelationshipIntent extends RelationshipIdentity {
    sourceVersion: number;
    targetVersion: number;
}
export type RelationshipResult =
    | { status: 'unconfirmed' }
    | { status: 'cancelled'; replayed: boolean }
    | {
          status: 'committed';
          changed: boolean;
          sourceVersion: number;
          targetVersion: number;
          replayed: boolean;
      };
const positive = (value: unknown): value is number =>
    Number.isSafeInteger(value) && Number(value) > 0;
const relationship = (value: unknown): value is TicketRelationship =>
    value === 'related_ticket' || value === 'duplicate_ticket';
export function validRelationshipIdentity(
    value: unknown,
): value is RelationshipIdentity {
    return (
        draftRecord(value) &&
        positive(value.actorId) &&
        positive(value.sourceId) &&
        positive(value.targetId) &&
        value.sourceId !== value.targetId &&
        typeof value.requestUuid === 'string' &&
        IT_DRAFT_UUID.test(value.requestUuid) &&
        (value.action === 'add' || value.action === 'remove') &&
        relationship(value.relationship)
    );
}
function ticket(value: unknown): value is RelatedTicket {
    return (
        draftRecord(value) &&
        positive(value.id) &&
        positive(value.lock_version) &&
        (value.reference === null || typeof value.reference === 'string') &&
        typeof value.title === 'string' &&
        typeof value.status === 'string' &&
        typeof value.work_type === 'string' &&
        (value.href === `/it/tickets/${value.id}` ||
            value.href === `/it/tickets/${value.id}/original`)
    );
}
function page<T>(
    value: unknown,
    row: (item: unknown) => item is T,
): value is RelatedPage<T> {
    return (
        draftRecord(value) &&
        positive(value.page) &&
        typeof value.has_more === 'boolean' &&
        Array.isArray(value.data) &&
        value.data.length <= 20 &&
        value.data.every(row)
    );
}
export function readRelatedWork(
    value: unknown,
    actorId: number,
    sourceId: number,
): RelatedWork | null {
    if (!draftRecord(value) || !draftRecord(value.data)) return null;
    const data = value.data;
    if (
        data.viewer_user_id !== actorId ||
        !ticket(data.source) ||
        data.source.id !== sourceId ||
        typeof data.can_change !== 'boolean' ||
        !page(data.candidates, ticket) ||
        !page(
            data.links,
            (row): row is RelatedLink =>
                draftRecord(row) &&
                positive(row.id) &&
                relationship(row.relationship) &&
                ticket(row.ticket) &&
                typeof row.can_remove === 'boolean',
        )
    )
        return null;
    return data as unknown as RelatedWork;
}
export function relationshipParameters(identity: RelationshipIdentity) {
    return {
        actor_user_id: identity.actorId,
        target_ticket_id: identity.targetId,
        action: identity.action,
        relationship: identity.relationship,
    };
}
export function readRelationshipResult(
    value: unknown,
    identity: RelationshipIdentity,
): RelationshipResult | null {
    if (
        !validRelationshipIdentity(identity) ||
        !draftRecord(value) ||
        !draftRecord(value.data)
    )
        return null;
    const data = value.data;
    if (
        data.viewer_user_id !== identity.actorId ||
        data.source_id !== identity.sourceId ||
        data.target_id !== identity.targetId ||
        data.request_uuid !== identity.requestUuid ||
        data.operation !== 'ticket.relationship' ||
        data.action !== identity.action ||
        data.relationship !== identity.relationship
    )
        return null;
    if (value.status === 'unconfirmed') return { status: 'unconfirmed' };
    if (typeof data.replayed !== 'boolean') return null;
    if (
        value.status === 'cancelled' &&
        typeof data.cancelled_at === 'string' &&
        Number.isFinite(Date.parse(data.cancelled_at))
    )
        return { status: 'cancelled', replayed: data.replayed };
    if (
        value.status === 'committed' &&
        typeof data.changed === 'boolean' &&
        positive(data.source_version) &&
        positive(data.target_version)
    )
        return {
            status: 'committed',
            changed: data.changed,
            sourceVersion: data.source_version,
            targetVersion: data.target_version,
            replayed: data.replayed,
        };
    return null;
}
