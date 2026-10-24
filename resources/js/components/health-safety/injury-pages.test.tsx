/* eslint-disable no-restricted-syntax -- Test doubles use raw buttons as simple controls. */
import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import type { ReactNode } from 'react';
import { afterEach, beforeEach, expect, it, vi } from 'vitest';
import InjuryCreate from '@/pages/health-safety/injuries/create';
import InjuryShow from '@/pages/health-safety/injuries/show';
import type { InjuryCaptureOptions, InjuryRecord } from './injury-workspace-types';

const io = vi.hoisted(() => ({ url: '/health-safety/injuries/9?section=recovery&action=review', get: vi.fn(), replace: vi.fn() }));
vi.mock('@inertiajs/react', () => ({ Head: () => null, usePage: () => ({ url: io.url, props: { auth: { user: { id: 12 } } } }), router: { get: io.get, replace: io.replace } }));
vi.mock('@/layouts/app-layout', () => ({ default: ({ children }: { children: ReactNode }) => <main>{children}</main> }));
vi.mock('@/hooks/use-safety-workspace-drafts', async () => {
    const React = await import('react');
    return { SafetyFormDraftContext: React.createContext(null), useSafetyWorkspaceDrafts: () => ({ register: vi.fn(), request: (next: () => void) => next(), confirmation: null }) };
});
vi.mock('@/components/page', () => {
    const slot = ({ children }: { children: ReactNode }) => <div>{children}</div>;
    const button = ({ children, onClick }: { children: ReactNode; onClick: () => void }) => <button onClick={onClick}>{children}</button>;
    return { PageLayout: ({ hero, children }: { hero: ReactNode; children: ReactNode }) => <>{hero}{children}</>,
        PageHeader: ({ title, actions, rail, meters }: { title: string; actions?: ReactNode; rail?: ReactNode; meters?: ReactNode }) => <header><h1>{title}</h1>{actions}{rail}{meters}</header>,
        PageHeaderGlassButton: button, PageHeaderPrimaryButton: button, PageHeaderMeterBlock: slot, PageHeaderMeterBig: slot, PageHeaderMeterCaption: slot, PageHeaderStatusChip: slot,
        PageHeaderRail: ({ items, onSelect }: { items: { key: string; label: string }[]; onSelect: (key: string) => void }) => <nav>{items.map((item) => <button key={item.key} onClick={() => onSelect(item.key)}>{item.label}</button>)}</nav> };
});
vi.mock('./injury-record-panels', () => ({ injuryDay: (value: string | null) => value ?? 'Not recorded', injuryTime: (value: string | null) => value ?? 'Not recorded', InjuryRecordPanel: ({ section }: { section: string }) => <p>{section} section</p>, InjuryRecordSide: () => null }));
vi.mock('./injury-command-form', async () => {
    const React = await import('react');
    return { InjuryCommandForm: ({ detail, capture }: { detail: InjuryRecord | null; capture?: InjuryCaptureOptions }) => {
        const [notes, setNotes] = React.useState('');
        return <div><input aria-label="Retained injury draft" value={notes} onChange={(event) => setNotes(event.target.value)} /><button disabled={detail ? !detail.can.review : capture?.can.create !== true}>Save test command</button></div>;
    } };
});
const record = (changes: Partial<InjuryRecord> = {}) => ({ id: 9, reference: 'INJ-0009', injury_date: null, severity: 'moderate', injury_type_label: 'Strain', status: 'reported', source_integrity: 'valid',
    medical_access: 'readable', recovery_integrity: 'valid', coordination: { coordinator: { id: 17, name: 'Current owner' }, attribution_state: 'recorded', next_review_date: null },
    worker: { id: 12, name: 'Synthetic worker' }, site: { id: 4, name: 'Kauri' }, commands: { expected_version: 1, transitions: [] },
    can: { assign: false, review: true, transition: false }, ...changes }) as InjuryRecord;
beforeEach(() => { vi.clearAllMocks(); io.url = '/health-safety/injuries/9?section=recovery&action=review'; });
afterEach(cleanup);

it('retains a mounted command draft after current medical permission or source integrity withdraws saving', () => {
    const d = record(); const { rerender } = render(<InjuryShow detail={d} return_to="/tasks?assigned=me" />);
    fireEvent.change(screen.getByRole('textbox', { name: 'Retained injury draft' }), { target: { value: 'Unsaved review notes' } });
    rerender(<InjuryShow detail={{ ...d, medical_access: 'restricted', can: { ...d.can, review: false } }} return_to="/tasks?assigned=me" />);
    expect(screen.getByRole('textbox')).toHaveValue('Unsaved review notes'); expect(screen.getByRole('button', { name: 'Save test command' })).toBeDisabled();
    rerender(<InjuryShow detail={{ ...d, source_integrity: 'repair_required', can: { ...d.can, review: false } }} return_to="/tasks?assigned=me" />);
    expect(screen.getByRole('textbox')).toHaveValue('Unsaved review notes'); expect(screen.getByRole('status')).toHaveTextContent('Source record needs review');
});

it('does not open a never-authorised command from a URL and retains safe record navigation', () => {
    const d = record(); render(<InjuryShow detail={{ ...d, can: { ...d.can, review: false } }} return_to="/tasks?assigned=me" />);
    expect(screen.queryByRole('textbox', { name: 'Retained injury draft' })).not.toBeInTheDocument();
    expect(screen.getByRole('alert')).toHaveTextContent('no longer available');
    fireEvent.click(screen.getByRole('button', { name: 'Overview' }));
    expect(io.replace).toHaveBeenCalledWith({ url: '/health-safety/injuries/9?section=overview&return_to=%2Ftasks%3Fassigned%3Dme', preserveState: true, preserveScroll: true });
});

it('offers one clear assign-owner header action when no accountable person is recorded', () => {
    io.url = '/health-safety/injuries/9'; const d = record();
    render(<InjuryShow detail={{ ...d, coordination: { ...d.coordination, coordinator: null, attribution_state: 'unassigned' }, can: { ...d.can, assign: true } }} />);
    expect(screen.getAllByRole('button', { name: 'Assign owner' })).toHaveLength(1);
    expect(screen.queryByRole('button', { name: 'Change incident owner' })).not.toBeInTheDocument();
});

it('retains a reporting draft when capture permission is withdrawn without turning it into an enduring read grant', () => {
    const capture = { can: { create: true, view_register: false } } as InjuryCaptureOptions;
    const { rerender } = render(<InjuryCreate capture={capture} return_to="/tasks?assigned=me" />);
    fireEvent.change(screen.getByRole('textbox'), { target: { value: 'Unsaved factual occurrence' } });
    rerender(<InjuryCreate capture={{ ...capture, can: { ...capture.can, create: false } }} return_to="/tasks?assigned=me" />);
    expect(screen.getByRole('textbox')).toHaveValue('Unsaved factual occurrence'); expect(screen.getByRole('button', { name: 'Save test command' })).toBeDisabled();
    expect(screen.getByRole('alert')).toHaveTextContent('Reporting is no longer available'); expect(io.get).not.toHaveBeenCalled();
});
