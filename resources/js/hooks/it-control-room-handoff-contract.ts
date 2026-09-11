import { draftRecord, IT_DRAFT_UUID } from './it-ticket-draft-contract';

export interface HandoffIdentity {
    actorId: number;
    alertId: number;
    requestUuid: string;
}

export interface HandoffTicket {
    id: number;
    reference: string | null;
    title: string;
    status: string;
    version: number;
    href: string;
}

export interface HandoffPreview {
    viewer_user_id: number;
    alert_id: number;
    alert_version: string;
    existing_work: HandoffTicket[];
    has_existing_work: boolean;
    candidates: HandoffTicket[];
}

export type HandoffIntent = HandoffIdentity & {
    alertVersion: string;
    reason: string;
} & (
        | {
              action: 'link';
              ticketId: number;
              ticketVersion: number;
          }
        | {
              action: 'create';
              title: string;
              description: string;
              category: 'hardware' | 'account' | 'network' | 'other';
              impact: 'individual' | 'team' | 'site' | 'organization';
              urgency: 'low' | 'normal' | 'high' | 'critical';
              serviceId: number | null;
          }
    );

export type HandoffResult =
    | { status: 'unconfirmed' }
    | { status: 'cancelled'; replayed: boolean; cancelledAt: string }
    | {
          status: 'committed';
          replayed: boolean;
          changed: boolean;
          outcome: 'created' | 'linked' | 'existing';
          ticket: HandoffTicket;
      };

const positive = (value: unknown): value is number =>
    Number.isSafeInteger(value) && Number(value) > 0;

export function validHandoffIdentity(value: unknown): value is HandoffIdentity {
    return (
        draftRecord(value) &&
        positive(value.actorId) &&
        positive(value.alertId) &&
        typeof value.requestUuid === 'string' &&
        IT_DRAFT_UUID.test(value.requestUuid)
    );
}

/** Only the canonical same-origin ticket destination survives a server response. */
function readTicket(value: unknown, origin?: string): HandoffTicket | null {
    if (
        !draftRecord(value) ||
        !positive(value.id) ||
        !positive(value.version) ||
        (value.reference !== null && typeof value.reference !== 'string') ||
        typeof value.title !== 'string' ||
        typeof value.status !== 'string' ||
        !['open', 'in_progress', 'waiting', 'resolved', 'closed'].includes(
            value.status,
        ) ||
        typeof value.href !== 'string'
    )
        return null;
    const path = `/it/tickets/${value.id}`;
    if (value.href !== path) {
        try {
            const destination = new URL(value.href);
            if (
                !origin ||
                destination.origin !== origin ||
                destination.pathname !== path ||
                destination.search ||
                destination.hash ||
                destination.username ||
                destination.password
            )
                return null;
        } catch {
            return null;
        }
    }
    return {
        id: value.id,
        reference: value.reference,
        title: value.title,
        status: value.status,
        version: value.version,
        href: path,
    };
}

export function readHandoffPreview(
    value: unknown,
    actorId: number,
    alertId: number,
    origin?: string,
): HandoffPreview | null {
    if (!draftRecord(value) || !draftRecord(value.data)) return null;
    const data = value.data;
    if (
        data.viewer_user_id !== actorId ||
        data.alert_id !== alertId ||
        typeof data.alert_version !== 'string' ||
        !/^[a-f0-9]{64}$/.test(data.alert_version) ||
        typeof data.has_existing_work !== 'boolean' ||
        !Array.isArray(data.existing_work) ||
        data.existing_work.length > 25 ||
        !Array.isArray(data.candidates) ||
        data.candidates.length > 25
    )
        return null;
    const existing = data.existing_work.map((row) => readTicket(row, origin));
    const candidates = data.candidates.map((row) => readTicket(row, origin));
    if (existing.includes(null) || candidates.includes(null)) return null;
    return {
        viewer_user_id: actorId,
        alert_id: alertId,
        alert_version: data.alert_version,
        existing_work: existing as HandoffTicket[],
        has_existing_work: data.has_existing_work,
        candidates: candidates as HandoffTicket[],
    };
}

export function handoffParameters(identity: HandoffIdentity) {
    return {
        viewer_user_id: identity.actorId,
        request_uuid: identity.requestUuid,
    };
}

export function handoffBody(intent: HandoffIntent) {
    return {
        ...handoffParameters(intent),
        alert_version: intent.alertVersion,
        action: intent.action,
        reason: intent.reason,
        ...(intent.action === 'link'
            ? {
                  ticket_id: intent.ticketId,
                  ticket_version: intent.ticketVersion,
              }
            : {
                  title: intent.title,
                  description: intent.description,
                  category: intent.category,
                  impact: intent.impact,
                  urgency: intent.urgency,
                  it_service_id: intent.serviceId,
              }),
    };
}

export function readHandoffResult(
    value: unknown,
    identity: HandoffIdentity,
    origin?: string,
): HandoffResult | null {
    if (
        !validHandoffIdentity(identity) ||
        !draftRecord(value) ||
        !draftRecord(value.data)
    )
        return null;
    const data = value.data;
    if (
        data.viewer_user_id !== identity.actorId ||
        data.alert_id !== identity.alertId ||
        data.request_uuid !== identity.requestUuid
    )
        return null;
    if (value.status === 'unconfirmed') return { status: 'unconfirmed' };
    if (typeof data.replayed !== 'boolean') return null;
    if (value.status === 'cancelled') {
        return typeof data.cancelled_at === 'string' &&
            Number.isFinite(Date.parse(data.cancelled_at))
            ? {
                  status: 'cancelled',
                  replayed: data.replayed,
                  cancelledAt: data.cancelled_at,
              }
            : null;
    }
    if (
        value.status !== 'committed' ||
        typeof data.changed !== 'boolean' ||
        !['created', 'linked', 'existing'].includes(String(data.outcome)) ||
        (data.outcome === 'existing' ? data.changed : !data.changed)
    )
        return null;
    const ticket = readTicket(data.ticket, origin);
    return ticket
        ? {
              status: 'committed',
              replayed: data.replayed,
              changed: data.changed,
              outcome: data.outcome as 'created' | 'linked' | 'existing',
              ticket,
          }
        : null;
}
