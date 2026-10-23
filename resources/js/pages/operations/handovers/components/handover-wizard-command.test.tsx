import { webcrypto } from 'node:crypto';
import { act, cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react';
import type { ReactNode } from 'react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { HandoverWizard } from './handover-wizard';
import type { Catalogue, Handover } from './shared';

const { request, put, post, before, success } = vi.hoisted(() => ({
    request: vi.fn(), put: vi.fn(), post: vi.fn(), before: vi.fn(), success: vi.fn(),
}));
vi.mock('axios', () => ({ default: { request, get: vi.fn().mockResolvedValue({ data: {} }), post: vi.fn().mockResolvedValue({ data: {} }) } }));
vi.mock('@inertiajs/react', () => ({ router: { put, post, on: vi.fn((_event, callback) => { before.mockImplementation(callback); return () => before.mockReset(); }) } }));
vi.mock('sonner', () => ({ toast: { success, error: vi.fn(), warning: vi.fn() } }));
vi.mock('@/hooks/use-mobile', () => ({ useIsMobile: () => false }));
vi.mock('@/components/rostering', () => ({ startOfWeek: (date: Date) => date }));
vi.mock('@/components/wizard/shell', () => ({
    WizardShell: ({ open, title, children, footerStart, footerEnd, onClose, onStepClick }: {
        open: boolean; title: string; children: ReactNode; footerStart: ReactNode; footerEnd: ReactNode;
        onClose: () => void; onStepClick: (step: number) => void;
    }) => open ? <section aria-label={title}>
        <button onClick={onClose}>Header close</button>
        <button onClick={() => onStepClick(1)}>Narrative step</button>
        <button onClick={() => onStepClick(3)}>Review step</button>
        {children}{footerStart}{footerEnd}
    </section> : null,
    WizardStepPane: ({ children }: { children: ReactNode }) => <div>{children}</div>,
}));
const shift = { id: 20, starts_at: '2026-10-13T08:00:00Z', ends_at: '2026-10-13T16:00:00Z', shift_type: 'day', label: 'Day support' };
const incoming = { ...shift, id: 21, starts_at: '2026-10-13T16:00:00Z', ends_at: '2026-10-13T23:00:00Z' };
const handover: Handover = {
    id: 12, version: 3, status: 'draft', handover_notes: 'Support after dinner.', client_mood: null,
    medications_due: [], incidents_to_note: [], follow_up_items: [], tasks_pending: [],
    cd_verification: null, cd_required: false, edit_lock: null, created_at: null, submitted_at: null, acknowledged_at: null,
    client: { id: 7, first_name: 'Aroha', last_name: 'Demo', site_id: 1 }, site: { id: 1, name: 'Aurora House' },
    outgoing_staff: { id: 4, name: 'Demo' }, incoming_staff: { id: 9, name: 'Incoming' }, acknowledger: null,
    outgoing_shift: shift, incoming_shift: incoming, can_submit: true, can_edit: true, can_acknowledge: false,
    lock: { locked: false, reason: '', days_left: null, age_days: null },
};
const catalogue: Catalogue = {
    clients: [{ ...handover.client!, service_context_id: null }], staff: [{ id: 4, name: 'Demo' }, { id: 9, name: 'Incoming' }],
    staffBySite: {}, sites: [handover.site!], serviceContexts: [], controlledWitnessesBySite: {},
    shifts: [{ ...shift, client_id: 7, site_id: 1, user_id: 4, service_context_id: null,
        status: 'scheduled', actual_ends_at: null, staff: { id: 4, name: 'Demo' } },
        { ...incoming, client_id: 7, site_id: 1, user_id: 9, service_context_id: null,
            status: 'scheduled', actual_ends_at: null, staff: { id: 9, name: 'Incoming' } }],
    capabilities: { manage_any_shifts: true, view_controlled: false, record_controlled: false },
};
const props = { open: true, editing: handover, catalogue, currentUser: { id: 4, name: 'Demo' },
    preselectClientId: null, onAddClient: vi.fn(), onSubmitted: vi.fn(), onOpenChange: vi.fn() };
const source = { version: 1, scope: 'operations_handover_command_source', actor_id: 4, shift_id: 20,
    handover_id: 12, expected_version: 3, status: 'draft', expected_source: 'a'.repeat(64),
    can: { create: false, update: true, submit: true } };

class FakeNavigation extends EventTarget {
    currentEntry = { key: 'handovers' };
    traverseTo = vi.fn(() => ({ finished: Promise.resolve() }));
    traverse(key: string) {
        const event = Object.assign(new Event('navigate', { cancelable: true }), {
            navigationType: 'traverse', destination: { key, sameDocument: true },
        });
        this.dispatchEvent(event);
        return event;
    }
}
let navigation: FakeNavigation;

beforeEach(() => {
    vi.clearAllMocks();
    navigation = new FakeNavigation();
    Object.defineProperty(window, 'navigation', { configurable: true, value: navigation });
    vi.stubGlobal('crypto', { subtle: webcrypto.subtle, randomUUID: () => '7d886442-95d4-4bb6-a8b5-364489f0d22d' });
});
afterEach(() => { cleanup(); Reflect.deleteProperty(window, 'navigation'); vi.unstubAllGlobals(); });

describe('mounted Operations handover wizard', () => {
    it('asks before discarding unsent typing and preserves it when the worker keeps editing', () => {
        render(<HandoverWizard {...props} checkedCommands />);
        fireEvent.click(screen.getByRole('button', { name: 'Narrative step' }));
        fireEvent.change(screen.getByPlaceholderText(/Settled day overall/), { target: { value: 'Retained unsent support notes.' } });
        act(() => {
            expect(navigation.traverse('previous').defaultPrevented).toBe(true);
            expect(navigation.traverse('next').defaultPrevented).toBe(true);
        });
        expect(navigation.traverseTo).not.toHaveBeenCalled();
        fireEvent.click(screen.getByRole('button', { name: 'Header close' }));
        expect(props.onOpenChange).not.toHaveBeenCalled();
        expect(screen.getByRole('alertdialog')).toHaveTextContent('Discard unsaved handover typing?');
        fireEvent.click(screen.getByRole('button', { name: 'Keep editing' }));
        expect(screen.getByPlaceholderText(/Settled day overall/)).toHaveValue('Retained unsent support notes.');
        fireEvent.click(screen.getByRole('button', { name: 'Header close' }));
        fireEvent.click(screen.getByRole('button', { name: 'Discard typing' }));
        expect(props.onOpenChange).toHaveBeenCalledWith(false);
        expect(request).not.toHaveBeenCalled();
    });

    it('blocks header close, Escape callback and page navigation while an original command is unresolved', async () => {
        request.mockResolvedValueOnce({ status: 200, data: source })
            .mockResolvedValueOnce({ status: 202, data: { outcome: 'unconfirmed' } });
        render(<HandoverWizard {...props} checkedCommands />);
        fireEvent.click(screen.getByRole('button', { name: 'Review step' }));
        fireEvent.click(screen.getByRole('button', { name: 'Save as draft' }));
        await screen.findByRole('button', { name: 'Check original save' });
        fireEvent.click(screen.getByRole('button', { name: 'Header close' }));
        expect(props.onOpenChange).not.toHaveBeenCalled();
        const preventDefault = vi.fn();
        act(() => before({ preventDefault, detail: { visit: { method: 'get', url: new URL('/dashboard', window.location.origin), only: [], preserveState: false } } }));
        expect(preventDefault).toHaveBeenCalledOnce();
        act(() => expect(navigation.traverse('previous').defaultPrevented).toBe(true));
        expect(navigation.traverseTo).not.toHaveBeenCalled();
        expect(request.mock.calls.map(([args]) => args.method)).toEqual(['get', 'put']);
        expect(success).not.toHaveBeenCalled();
    });

    it('explains the unsupported browser history path while Operations typing is protected', () => {
        Reflect.deleteProperty(window, 'navigation');
        render(<HandoverWizard {...props} checkedCommands />);
        expect(screen.queryByText(/Browser Back and Forward cannot protect/)).not.toBeInTheDocument();
        fireEvent.click(screen.getByRole('button', { name: 'Narrative step' }));
        fireEvent.change(screen.getByPlaceholderText(/Settled day overall/), { target: { value: 'Unsent work' } });
        expect(screen.getByText(/Browser Back and Forward cannot protect/)).toBeInTheDocument();
        expect(request).not.toHaveBeenCalled();
    });

    it('offers submit-only review with no edit grant and sends the dedicated empty-field command', async () => {
        request.mockImplementation(async (args) => {
            if (args.method === 'get') return { status: 200, data: { ...source, can: { ...source.can, update: false } } };
            return { status: 202, data: { outcome: 'unconfirmed' } };
        });
        render(<HandoverWizard {...props} editing={{ ...handover, can_edit: false }} checkedCommands submitOnly />);
        expect(screen.getByRole('region', { name: 'Submit handover' })).toBeVisible();
        expect(screen.queryByRole('button', { name: 'Save as draft' })).not.toBeInTheDocument();
        fireEvent.click(screen.getByRole('button', { name: 'Submit handover' }));
        await waitFor(() => expect(request).toHaveBeenCalledTimes(2));
        expect(request.mock.calls[1][0]).toMatchObject({ method: 'patch', url: '/operations/shifts/20/handovers/12/command/submit', data: { fields: {} } });
        expect(put).not.toHaveBeenCalled();
        expect(post).not.toHaveBeenCalled();
    });

    it('keeps the shared legacy save path when the Operations port is absent', () => {
        render(<HandoverWizard {...props} />);
        fireEvent.click(screen.getByRole('button', { name: 'Narrative step' }));
        fireEvent.change(screen.getByPlaceholderText(/Settled day overall/), { target: { value: 'Legacy notes' } });
        expect(navigation.traverse('previous').defaultPrevented).toBe(false);
        expect(screen.queryByText(/Browser Back and Forward cannot protect/)).not.toBeInTheDocument();
        fireEvent.click(screen.getByRole('button', { name: 'Review step' }));
        fireEvent.click(screen.getByRole('button', { name: 'Save as draft' }));
        expect(put).toHaveBeenCalledWith('/operations/handovers/12', expect.objectContaining({ version: 3, submit: false }), expect.any(Object));
        expect(request).not.toHaveBeenCalled();
    });
});
