import { describe, expect, it } from 'vitest';
import {
    handoffBody,
    readHandoffPreview,
    readHandoffResult,
} from './it-control-room-handoff-contract';

const identity = {
    actorId: 7,
    alertId: 11,
    requestUuid: 'e87cb510-a8a1-4c7b-bd51-a36e41303c17',
};
const ticket = {
    id: 13,
    reference: 'IT-000013',
    title: 'Technical investigation',
    status: 'open',
    version: 2,
    href: '/it/tickets/13',
};
const data = {
    viewer_user_id: identity.actorId,
    alert_id: identity.alertId,
    request_uuid: identity.requestUuid,
    replayed: false,
    changed: true,
    outcome: 'linked',
    ticket,
};

describe('Control Room handoff response contract', () => {
    it('accepts only the original actor alert and request identity', () => {
        const response = { status: 'committed', data };
        expect(readHandoffResult(response, identity)?.status).toBe('committed');
        for (const patch of [
            { viewer_user_id: 9 },
            { alert_id: 12 },
            { request_uuid: crypto.randomUUID() },
            { changed: false },
            { outcome: 'failed' },
            { ticket: { ...ticket, version: 0 } },
        ]) {
            expect(
                readHandoffResult(
                    { ...response, data: { ...data, ...patch } },
                    identity,
                ),
            ).toBeNull();
        }
    });

    it('keeps unknown and cancelled outcomes distinct from successful writes', () => {
        expect(
            readHandoffResult({ status: 'unconfirmed', data }, identity),
        ).toEqual({ status: 'unconfirmed' });
        expect(
            readHandoffResult({ status: 'cancelled', data }, identity),
        ).toBeNull();
        expect(
            readHandoffResult(
                {
                    status: 'cancelled',
                    data: { ...data, cancelled_at: '2026-09-12T00:00:00Z' },
                },
                identity,
            )?.status,
        ).toBe('cancelled');
        expect(
            readHandoffResult(
                {
                    status: 'committed',
                    data: { ...data, changed: false, outcome: 'existing' },
                },
                identity,
            )?.status,
        ).toBe('committed');
    });

    it('rejects foreign and contradictory destinations and normalizes a canonical absolute link', () => {
        for (const href of [
            'https://outside.example/it/tickets/13',
            '/it/tickets/14',
            'javascript:alert(1)',
            'https://app.test/it/tickets/13?next=other',
        ]) {
            expect(
                readHandoffResult(
                    {
                        status: 'committed',
                        data: { ...data, ticket: { ...ticket, href } },
                    },
                    identity,
                    'https://app.test',
                ),
            ).toBeNull();
        }
        const result = readHandoffResult(
            {
                status: 'committed',
                data: {
                    ...data,
                    ticket: {
                        ...ticket,
                        href: 'https://app.test/it/tickets/13',
                    },
                },
            },
            identity,
            'https://app.test',
        );
        expect(result?.status === 'committed' && result.ticket.href).toBe(
            '/it/tickets/13',
        );
    });

    it('binds discovery to this actor and alert and refuses malformed candidate rows', () => {
        const preview = {
            data: {
                viewer_user_id: 7,
                alert_id: 11,
                alert_version: 'a'.repeat(64),
                can_start: true,
                site: { id: 3, name: 'Approved site' },
                services: [],
                existing_work: [],
                candidates: [ticket],
                has_existing_work: false,
            },
        };
        expect(readHandoffPreview(preview, 7, 11)?.candidates).toHaveLength(1);
        expect(readHandoffPreview(preview, 8, 11)).toBeNull();
        expect(
            readHandoffPreview(
                {
                    data: {
                        ...preview.data,
                        candidates: [{ ...ticket, href: '/other' }],
                    },
                },
                7,
                11,
            ),
        ).toBeNull();
        expect(
            readHandoffPreview(
                {
                    data: {
                        ...preview.data,
                        candidates: Array(26).fill(ticket),
                    },
                },
                7,
                11,
            ),
        ).toBeNull();
    });

    it('sends explicit classification for creation and only the selected identity for linking', () => {
        const base = {
            ...identity,
            alertVersion: 'b'.repeat(64),
            reason: 'Technical repair is needed.',
        };
        expect(
            handoffBody({
                ...base,
                action: 'link',
                ticketId: 13,
                ticketVersion: 2,
            }),
        ).toEqual({
            viewer_user_id: 7,
            request_uuid: identity.requestUuid,
            alert_version: base.alertVersion,
            reason: base.reason,
            action: 'link',
            ticket_id: 13,
            ticket_version: 2,
        });
        expect(
            handoffBody({
                ...base,
                action: 'create',
                title: 'Repair',
                description: 'Check and record the result.',
                category: 'network',
                impact: 'site',
                urgency: 'high',
                serviceId: null,
            }),
        ).toMatchObject({
            category: 'network',
            impact: 'site',
            urgency: 'high',
            it_service_id: null,
        });
    });
});
