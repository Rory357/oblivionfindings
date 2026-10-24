/* eslint-disable no-restricted-syntax -- Test doubles use raw buttons as simple controls. */
import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import type { ReactNode } from 'react';
import { afterEach, beforeEach, expect, it, vi } from 'vitest';
import PpeShow from './show';
import { ppeCommandAllowed } from './_command-state';
import type { PpeRecord, PpeRepairRecord } from './_workspace-types';

const io = vi.hoisted(() => ({ url: '/health-safety/ppe/inventory/9?action=edit', get: vi.fn(), replace: vi.fn() }));
vi.mock('@inertiajs/react', () => ({ Head: () => null, usePage: () => ({ url: io.url, props: { auth: { user: { id: 12 } } } }), router: { get: io.get, replace: io.replace } }));
vi.mock('@/layouts/app-layout', () => ({ default: ({ children }: { children: ReactNode }) => <main>{children}</main> }));
vi.mock('@/hooks/use-safety-workspace-drafts', async () => {
    const React = await import('react');
    return { SafetyFormDraftContext: React.createContext(null), useSafetyWorkspaceDrafts: () => ({ register: vi.fn(), request: (callback: () => void) => callback(), confirmation: null }) };
});
vi.mock('@/components/page', () => {
    const slot = ({ children }: { children: ReactNode }) => <div>{children}</div>;
    const button = ({ children, onClick }: { children: ReactNode; onClick?: () => void }) => <button onClick={onClick}>{children}</button>;
    return { PageLayout: ({ hero, children }: { hero: ReactNode; children: ReactNode }) => <>{hero}{children}</>,
        PageHeader: ({ title, actions, rail, meters, backHref }: { title: string; actions?: ReactNode; rail?: ReactNode; meters?: ReactNode; backHref?: string }) => <header><h1>{title}</h1>{backHref && <a href={backHref}>Back</a>}{actions}{rail}{meters}</header>,
        PageHeaderGlassButton: button, PageHeaderPrimaryButton: button, PageHeaderMeterBlock: slot, PageHeaderMeterBig: slot, PageHeaderMeterCaption: slot, PageHeaderStatusChip: slot,
        PageHeaderRail: ({ items, onSelect }: { items: { key: string; label: string }[]; onSelect: (key: string) => void }) => <nav>{items.map((item) => <button key={item.key} onClick={() => onSelect(item.key)}>{item.label}</button>)}</nav> };
});
vi.mock('./_record-panels', () => ({ ppeDay: (value: string | null) => value ?? 'Not recorded', PpeRecordPanel: ({ record, section }: { record: PpeRecord; section: string }) => <div>{section} saved panel {record.kind === 'inventory' && record.detail.brand}</div> }));
vi.mock('./_command-form', async () => {
    const React = await import('react');
    return { PpeCommandForm: ({ record, command, active, onCancel }: { record: PpeRecord; command: 'edit'; active: boolean; onCancel: () => void }) => {
        const [notes, setNotes] = React.useState(''); const allowed = ppeCommandAllowed(record, command);
        return <div hidden={!active}>{allowed && <input aria-label="Retained equipment draft" value={notes} onChange={(event) => setNotes(event.target.value)} />}<button disabled={!allowed}>Save test command</button><button onClick={onCancel}>Cancel test command</button></div>;
    } };
});
const record = () => ({ kind: 'inventory', detail: { id: 9, brand: 'Saved equipment brand', ppe_type: { name: 'Respirator' }, site: { name: 'Kauri' },
    status: 'available', quantity: 1, expiry_date: null, next_inspection_due: null, currency: { expiry_state: 'unknown', inspection_state: 'unknown' },
    can: { manage: true, evidence: false }, command_context: { root_kind: 'inventory', root_id: 9, source_kind: 'inventory', source_id: 9,
        site_id: 4, user_id: null, expected_version: 4, source_schema_version: 1 } } }) as unknown as PpeRecord;
beforeEach(() => { vi.clearAllMocks(); io.url = '/health-safety/ppe/inventory/9?action=edit'; });
afterEach(cleanup);

it('retains a mounted draft through record sections and returns to the same exact filtered register', () => {
    const d = record(); const back = '/health-safety/ppe?tab=inv_inspection&site_id=4&page=3&view=cards';
    const { rerender } = render(<PpeShow {...d} return_to={back} />);
    fireEvent.change(screen.getByRole('textbox'), { target: { value: 'Unsaved equipment correction' } });
    fireEvent.click(screen.getByRole('button', { name: 'Inspections' }));
    expect(io.replace).toHaveBeenCalledWith({ url: '/health-safety/ppe/inventory/9?section=inspections&return_to=' + encodeURIComponent(back), preserveState: true, preserveScroll: true });
    io.url = '/health-safety/ppe/inventory/9?section=inspections'; rerender(<PpeShow {...d} return_to={back} />);
    expect(screen.queryByRole('textbox')).not.toBeInTheDocument();
    io.url = '/health-safety/ppe/inventory/9?action=edit'; rerender(<PpeShow {...d} return_to={back} />);
    expect(screen.getByRole('textbox')).toHaveValue('Unsaved equipment correction'); expect(screen.getByRole('link', { name: 'Back' })).toHaveAttribute('href', back);
});
it('withholds saved source facts and command fields after repair while preserving the in-memory draft for valid recovery', () => {
    const d = record(); const { rerender } = render(<PpeShow {...d} />);
    fireEvent.change(screen.getByRole('textbox'), { target: { value: 'Retained unsaved change' } });
    const damaged = { kind: 'inventory', detail: { id: 9, source_integrity: 'repair_required', command_context: null, read_scope: 'source_repair_required', can: { manage: false } } } as unknown as PpeRepairRecord;
    rerender(<PpeShow {...damaged} />);
    expect(screen.queryByRole('textbox')).not.toBeInTheDocument(); expect(screen.getByRole('button', { name: 'Save test command' })).toBeDisabled();
    expect(screen.queryByText(/Saved equipment brand/)).not.toBeInTheDocument(); expect(screen.getByRole('status')).toHaveTextContent('Source history needs review');
    rerender(<PpeShow {...d} />); expect(screen.getByRole('textbox')).toHaveValue('Retained unsaved change');
});
it('never mounts a never-authorised command from a URL hint or invents a readable damaged record', () => {
    const d = record(); render(<PpeShow {...({ ...d, detail: { ...d.detail, can: { manage: false } } } as PpeRecord)} />);
    expect(screen.queryByRole('textbox')).not.toBeInTheDocument(); expect(screen.getByRole('alert')).toHaveTextContent('unavailable');
    cleanup();
    const damaged = { kind: 'inventory', detail: { id: 9, source_integrity: 'repair_required', command_context: null, can: {} } } as unknown as PpeRepairRecord;
    render(<PpeShow {...damaged} />); expect(screen.getByRole('heading', { level: 1 })).toHaveTextContent('source needs review');
    expect(screen.queryByRole('textbox')).not.toBeInTheDocument();
});
it('keeps own allocation navigation limited to its factual issue and fit-training sections', () => {
    io.url = '/health-safety/ppe/allocations/13?section=evidence&action=return&return_to=%2Fmy-day';
    const d = { kind: 'allocations', detail: { id: 13, read_scope: 'own_allocation', acknowledged: false, returned_at: null,
        user: { name: 'Synthetic worker' }, inventory_item: { site: { name: 'Kauri' } }, can: { manage: false, acknowledge_own: true },
        command_context: { root_kind: 'inventory', root_id: 9, source_kind: 'allocations', source_id: 13, site_id: 4, user_id: 12, expected_version: 4, source_schema_version: 1 } } } as unknown as PpeRecord;
    render(<PpeShow {...d} />);
    expect(screen.queryByRole('button', { name: 'Evidence' })).not.toBeInTheDocument(); expect(screen.queryByRole('button', { name: 'History' })).not.toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'Issues' })).not.toBeInTheDocument(); expect(screen.getByRole('button', { name: 'Fit and training' })).toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Acknowledge issue' })).toBeInTheDocument(); expect(screen.getByRole('link', { name: 'Back' })).toHaveAttribute('href', '/my-day');
});
