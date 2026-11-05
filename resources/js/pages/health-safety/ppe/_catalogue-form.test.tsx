/* eslint-disable no-restricted-syntax -- Test doubles use raw elements as simple slots. */
import { cleanup, render, screen } from '@testing-library/react';
import type { ReactNode } from 'react';
import { afterEach, beforeEach, expect, it, vi } from 'vitest';
import { PpeCatalogueForm } from './_catalogue-form';
import type { CatalogueRow } from './_catalogue-state';

// Decided 6 Nov 2026: a brand-new record has nothing to have changed, so "Check for changes" only appears with a
// problem notice there. An existing type keeps the button, beside the "changed elsewhere" notice when it shows.
const state = vi.hoisted(() => ({ source: {} as Record<string, unknown> }));
vi.mock('./_use-catalogue-command', () => ({ usePpeCatalogueCommand: () => state.source }));
vi.mock('@/components/wizard/shell', () => ({
    WizardShell: ({ children }: { children: ReactNode }) => <div>{children}</div>,
    ReviewCard: ({ children }: { children: ReactNode }) => <div>{children}</div>,
    ReviewRow: ({ label }: { label: string }) => <p>{label}</p>,
}));

const options = { can: { create: true, catalogue_manage: true }, sites: [{ id: 4, name: 'Kauri' }], types: [{ id: 2, name: 'Respirator' }] };
function source(overrides: Record<string, unknown> = {}) {
    return {
        form: { data: { name: '', category: '', description: '', hazards_addressed: '', standards_reference: '', inspection_frequency: '',
            typical_lifespan_months: '', ppe_type_id: '', site_id: '', brand: '', model: '', serial_number: '', quantity: '1', location: '',
            condition: 'good', purchase_date: '', expiry_date: '', next_inspection_due: '', documents: [] }, errors: {} },
        change: vi.fn(), busy: false, saving: false, checking: false, receipt: null, failure: null, unresolved: false, stale: false,
        allowed: true, currentType: null, options, checkCurrent: vi.fn(async () => undefined), useCurrent: vi.fn(), checkFailed: false,
        reviewAvailable: true, save: vi.fn(), ...overrides,
    };
}
const check = () => screen.queryByRole('button', { name: /Check for changes|Checking…/ });
const open = (type?: CatalogueRow) => render(<PpeCatalogueForm command={type ? 'update_type' : 'create_inventory'} actorId={12} type={type} onClose={vi.fn()} onDone={vi.fn()} />);
beforeEach(() => { state.source = source(); });
afterEach(cleanup);

it('hides Check for changes on a brand-new record with nothing wrong', () => {
    open();
    expect(check()).not.toBeInTheDocument();
    expect(screen.queryByText(/Select Check for changes/)).not.toBeInTheDocument();
});

it('says nothing about permission while the first check is still loading', () => {
    state.source = source({ allowed: false, options: null, checking: true });
    open();
    expect(check()).not.toBeInTheDocument();
    expect(screen.queryByText(/Only people who manage PPE/)).not.toBeInTheDocument();
});

it('shows Check for changes with the notice when the check failed or a save is uncertain', () => {
    state.source = source({ allowed: false, options: null, checkFailed: true, failure: 'We couldn’t check for changes. Your entries are kept — try again before saving.' });
    const first = open();
    expect(screen.getByRole('alert')).toHaveTextContent('We couldn’t check for changes');
    expect(check()).toBeInTheDocument();
    first.unmount();
    state.source = source({ unresolved: true, failure: 'We can’t tell if this saved. Select Check for changes before trying again — your entries are kept.' });
    open();
    expect(check()).toBeInTheDocument();
});

it('tells a person without PPE rights why, without pointing at a hidden button', () => {
    state.source = source({ allowed: false, options: { ...options, can: { create: false, catalogue_manage: false } } });
    open();
    expect(screen.getByRole('status')).toHaveTextContent('Only people who manage PPE can add or change equipment');
    expect(screen.getByRole('status')).not.toHaveTextContent('Select Check for changes');
    expect(check()).not.toBeInTheDocument();
});

it('keeps Check for changes on an existing equipment type', () => {
    const type = { id: 2, name: 'Respirator', category: 'respiratory', can: { manage: true }, command_context: { expected_version: 3 } } as unknown as CatalogueRow;
    state.source = source({ currentType: type });
    open(type);
    expect(check()).toBeInTheDocument();
});
