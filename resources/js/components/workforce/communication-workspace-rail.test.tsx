import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import {
    CommunicationWorkspaceRail,
    communicationAccess,
} from './communication-workspace-rail';

const state = vi.hoisted(() => ({ can: {} as unknown, visit: vi.fn() }));
vi.mock('@inertiajs/react', () => ({
    usePage: () => ({ props: { auth: { can: state.can } } }),
    router: { visit: state.visit },
}));
vi.mock('@/components/page', () => ({
    PageHeaderRail: ({
        items,
        value,
        onSelect,
    }: {
        items: { key: string; label: string }[];
        value: string;
        onSelect: (key: string) => void;
    }) => (
        <div role="tablist">
            {items.map((item) => (
                <button
                    key={item.key}
                    role="tab"
                    aria-selected={item.key === value}
                    onClick={() => onSelect(item.key)}
                >
                    {item.label}
                </button>
            ))}
        </div>
    ),
}));

afterEach(() => {
    cleanup();
    state.can = {};
    state.visit.mockReset();
});

describe('communication workspace navigation', () => {
    it('offers notes only to a lawful notes reader and retains the loaded week/site', () => {
        state.can = { shifts: { viewAny: true, manageAny: true } };
        render(
            <CommunicationWorkspaceRail
                active="handovers"
                week="2026-10-05"
                site={12}
            />,
        );
        fireEvent.click(screen.getByRole('tab', { name: 'Shift notes' }));
        expect(state.visit).toHaveBeenCalledExactlyOnceWith(
            '/operations/shift-notes?week=2026-10-05&site_id=12',
        );
    });

    it('does not offer manager notes to an assigned worker', () => {
        state.can = { shifts: { viewAssigned: true } };
        render(
            <CommunicationWorkspaceRail
                active="handovers"
                week="2026-10-05"
                site={null}
            />,
        );
        expect(screen.queryByRole('tab', { name: 'Shift notes' })).toBeNull();
        fireEvent.click(screen.getByRole('tab', { name: 'Handovers' }));
        expect(state.visit).not.toHaveBeenCalled();
    });

    it('returns to the independent handover page with its canonical site parameter', () => {
        state.can = { shifts: { viewAny: true, manageAny: true } };
        render(
            <CommunicationWorkspaceRail
                active="notes"
                week="2026-10-12"
                site={4}
            />,
        );
        fireEvent.click(screen.getByRole('tab', { name: 'Handovers' }));
        expect(state.visit).toHaveBeenCalledExactlyOnceWith(
            '/operations/handovers?week=2026-10-12&site=4',
        );
    });

    it('removes an alternate view when current shared permission is withdrawn', () => {
        state.can = { shifts: { viewAny: true, manageAny: true } };
        const view = render(
            <CommunicationWorkspaceRail
                active="handovers"
                week="2026-10-05"
                site={null}
            />,
        );
        state.can = { shifts: { viewAssigned: true } };
        view.rerender(
            <CommunicationWorkspaceRail
                active="handovers"
                week="2026-10-05"
                site={null}
            />,
        );
        expect(screen.queryByRole('tab', { name: 'Shift notes' })).toBeNull();
        expect(state.visit).not.toHaveBeenCalled();
    });

    it('keeps the workspace while a read is in flight', () => {
        state.can = { shifts: { viewAny: true, manageAny: true } };
        render(
            <CommunicationWorkspaceRail
                active="handovers"
                week="2026-10-05"
                site={null}
                disabled
            />,
        );
        fireEvent.click(screen.getByRole('tab', { name: 'Shift notes' }));
        expect(state.visit).not.toHaveBeenCalled();
    });

    it('does not interpret malformed or truthy permission values as grants', () => {
        expect(
            communicationAccess({
                shifts: { viewAny: 'true' },
                handovers: ['viewAny'],
            }),
        ).toEqual({ handovers: false, notes: false });
        expect(communicationAccess(null)).toEqual({ handovers: false, notes: false });
    });

    it('does not offer a manager notes page to a shift reader without a manager capability', () => {
        state.can = { shifts: { viewAny: true } };
        render(
            <CommunicationWorkspaceRail
                active="handovers"
                week="2026-10-05"
                site={null}
            />,
        );
        expect(screen.queryByRole('tab', { name: 'Shift notes' })).toBeNull();
        expect(state.visit).not.toHaveBeenCalled();
    });

    it('does not equate handover creation with permission to read the list', () => {
        expect(communicationAccess({ handovers: { create: true } })).toEqual({
            handovers: false,
            notes: false,
        });
    });

    it('retains the portal-role redirect boundary even with manager flags', () => {
        const can = { shifts: { viewAny: true, manageAny: true } };
        expect(communicationAccess(can, 'client').notes).toBe(false);
        expect(communicationAccess(can, 'next_of_kin').notes).toBe(false);
    });
});
