import { describe, expect, it, vi } from 'vitest';

// buildItems wires router.visit into the cross-entity jump items; mock Inertia so
// importing the module works (we assert the items exist, we don't invoke jumps).
const visit = vi.fn();
vi.mock('@inertiajs/react', () => ({ router: { visit: (...a: unknown[]) => visit(...a) } }));

import { buildItems, type HandoverCtxHandlers } from './handover-context-menu';
import type { Handover } from './shared';

function makeHandover(over: Partial<Handover> = {}): Handover {
    return {
        id: 1,
        status: 'draft',
        handover_notes: 'note',
        client_mood: null,
        medications_due: [],
        cd_verification: null,
        version: 1,
        incidents_to_note: [],
        follow_up_items: [],
        tasks_pending: [],
        created_at: '2026-06-16T08:00:00+12:00',
        submitted_at: null,
        acknowledged_at: null,
        client: { id: 7, first_name: 'Ada', last_name: 'Lovelace', site_id: 2 },
        site: { id: 2, name: 'Maple House' },
        outgoing_staff: { id: 11, name: 'Joy Bell', role: 'support_worker' },
        incoming_staff: { id: 12, name: 'Brock Stone', role: 'support_worker' },
        acknowledger: null,
        outgoing_shift: {
            id: 100,
            starts_at: '2026-06-16T07:00:00+12:00',
            ends_at: '2026-06-16T15:00:00+12:00',
            shift_type: 'morning',
            label: 'Morning',
        },
        incoming_shift: null,
        can_submit: true,
        can_acknowledge: false,
        can_edit: true,
        lock: { locked: false, reason: 'within_window', days_left: 7, age_days: 0 },
        ...over,
    };
}

const labels = (items: ReturnType<typeof buildItems>): string[] =>
    items.flatMap((i) => ('label' in i ? [i.label] : []));

const allHandlers = (): HandoverCtxHandlers => ({
    onOpen: vi.fn(),
    onSubmit: vi.fn(),
    onAcknowledge: vi.fn(),
    onEdit: vi.fn(),
});

describe('buildItems (handover context menu)', () => {
    it('always offers View handover first and routes it to onOpen', () => {
        const handlers = allHandlers();
        const h = makeHandover();
        const items = buildItems(h, handlers);
        const first = items[0];
        expect('label' in first ? first.label : null).toBe('View handover');
        if ('onClick' in first) first.onClick?.();
        expect(handlers.onOpen).toHaveBeenCalledWith(h);
    });

    it('offers Submit + Edit for an editable own draft, but not Acknowledge', () => {
        const ls = labels(
            buildItems(
                makeHandover({ status: 'draft', can_submit: true, can_edit: true, can_acknowledge: false }),
                allHandlers(),
            ),
        );
        expect(ls).toContain('Submit to incoming');
        expect(ls).toContain('Edit handover');
        expect(ls).not.toContain('Acknowledge');
    });

    it('offers Acknowledge only when submitted and can_acknowledge', () => {
        const can = labels(
            buildItems(makeHandover({ status: 'submitted', can_acknowledge: true, can_submit: false }), allHandlers()),
        );
        expect(can).toContain('Acknowledge');

        const cannot = labels(
            buildItems(makeHandover({ status: 'submitted', can_acknowledge: false }), allHandlers()),
        );
        expect(cannot).not.toContain('Acknowledge');
    });

    it('hides Edit when can_edit is false', () => {
        const ls = labels(buildItems(makeHandover({ can_edit: false }), allHandlers()));
        expect(ls).not.toContain('Edit handover');
    });

    it('omits action items whose handler is not supplied', () => {
        const ls = labels(
            buildItems(makeHandover({ status: 'draft', can_submit: true, can_edit: true }), { onOpen: vi.fn() }),
        );
        expect(ls).toContain('View handover');
        expect(ls).not.toContain('Submit to incoming');
        expect(ls).not.toContain('Edit handover');
    });

    it('includes cross-entity jumps when those entities exist', () => {
        const ls = labels(buildItems(makeHandover(), allHandlers()));
        expect(ls).toContain('View client');
        expect(ls).toContain('View shift');
        expect(ls.some((l) => /outgoing/i.test(l))).toBe(true);
        expect(ls.some((l) => /incoming/i.test(l))).toBe(true);
        expect(ls).toContain('Open on MAR chart');
        expect(ls).toContain('Raise concern');
    });

    it('drops the client/shift/staff jumps when those entities are absent', () => {
        const ls = labels(
            buildItems(
                makeHandover({ client: null, outgoing_shift: null, outgoing_staff: null, incoming_staff: null }),
                allHandlers(),
            ),
        );
        expect(ls).toContain('View handover');
        expect(ls).not.toContain('View client');
        expect(ls).not.toContain('View shift');
        expect(ls).not.toContain('Open on MAR chart');
        expect(ls).not.toContain('Raise concern');
    });
});
