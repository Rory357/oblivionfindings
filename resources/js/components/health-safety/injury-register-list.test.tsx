import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import type { AnchorHTMLAttributes } from 'react';
import { afterEach, beforeEach, expect, it, vi } from 'vitest';
import { InjuryRegisterList, type InjuryRegisterRow } from './injury-register-list';

const io = vi.hoisted(() => ({ get: vi.fn() }));
vi.mock('@inertiajs/react', () => ({ router: { get: io.get }, Link: ({ href, children, ...props }: AnchorHTMLAttributes<HTMLAnchorElement>) => <a href={href} {...props}>{children}</a> }));
const row = { id: 9, reference: 'INJ-0009', status: 'closed', severity: 'moderate', injury_type: 'strain', injury_type_label: 'Strain',
    worker: { id: 12, name: 'Synthetic worker' }, site: { id: 4, name: 'Kauri' }, injury_date: '2026-10-11T12:05:00Z', lost_time_days: 0,
    worksafe_notifiable: false, rtw_count: null, capacity_count: 0, attachment_count: 1, source_integrity: 'repair_required' } as InjuryRegisterRow;
beforeEach(() => vi.clearAllMocks()); afterEach(cleanup);

it('uses the permanent injury href and full filtered return without inferring an open record', () => {
    // One table on the desktop web app (drawing pg-hsn-86); the phone card list is gone.
    render(<InjuryRegisterList rows={[row]} total={26} returnTo="/health-safety/injuries?q=0&worksafe=0&acc_open=0&page=2" medical={false} />);
    const hrefs = screen.getAllByRole('link', { name: 'Open injury INJ-0009' }).map((link) => link.getAttribute('href'));
    expect(hrefs).toHaveLength(1);
    // The same record and the full filtered return (query key order doesn't change the link).
    const link = new URL(hrefs[0]!, 'https://test.invalid');
    expect(link.pathname).toBe('/health-safety/injuries/9');
    const back = new URL(link.searchParams.get('return_to')!, 'https://test.invalid');
    expect(back.pathname).toBe('/health-safety/injuries');
    expect(Object.fromEntries(back.searchParams)).toEqual({ q: '0', worksafe: '0', acc_open: '0', page: '2' });
    expect(screen.getByText('1 of 26 shown')).toBeInTheDocument();
    expect(screen.queryByText('Supporting records')).not.toBeInTheDocument();
    expect(screen.queryByText(/0 recorded days lost · Open injury/)).not.toBeInTheDocument();
});

it('uses the real shared context menu to open connected work and conceals medical menu entries for factual readers', () => {
    render(<InjuryRegisterList rows={[row]} total={1} returnTo="/tasks?assigned=me" medical={false} />);
    fireEvent.contextMenu(screen.getAllByRole('row')[1], { clientX: 70, clientY: 100 });
    expect(screen.queryByRole('menuitem', { name: 'Recovery plans' })).not.toBeInTheDocument();
    fireEvent.click(screen.getByRole('menuitem', { name: 'Linked records' }));
    expect(io.get).toHaveBeenCalledWith('/health-safety/injuries/9?section=connections&return_to=%2Ftasks%3Fassigned%3Dme');
});

it('keeps unknown private counts distinct from healthy zero and preserves medically authorised source menus', () => {
    render(<InjuryRegisterList rows={[row]} total={1} returnTo="/health-safety/injuries" medical />);
    expect(screen.getByText('Plans not available · 0 checks · 1 file')).toBeInTheDocument();
    fireEvent.contextMenu(screen.getAllByRole('row')[1], { clientX: 70, clientY: 100 });
    for (const name of ['Open injury', 'Linked records', 'Recovery plans', 'Fitness-for-work checks', 'Evidence', 'History']) expect(screen.getByRole('menuitem', { name })).toBeInTheDocument();
});
