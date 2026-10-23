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
    expect(hrefs[0]).toBe('/health-safety/injuries/9?return_to=%2Fhealth-safety%2Finjuries%3Fq%3D0%26worksafe%3D0%26acc_open%3D0%26page%3D2');
    expect(screen.getByText('1 of 26 shown')).toBeInTheDocument();
    expect(screen.queryByText('Supporting records')).not.toBeInTheDocument();
    expect(screen.queryByText(/0 recorded days lost · Open injury/)).not.toBeInTheDocument();
});

it('uses the real shared context menu to open connected work and conceals medical menu entries for factual readers', () => {
    render(<InjuryRegisterList rows={[row]} total={1} returnTo="/tasks?assigned=me" medical={false} />);
    fireEvent.contextMenu(screen.getAllByRole('row')[1], { clientX: 70, clientY: 100 });
    expect(screen.queryByRole('menuitem', { name: 'Recovery plans' })).not.toBeInTheDocument();
    fireEvent.click(screen.getByRole('menuitem', { name: 'Connected work' }));
    expect(io.get).toHaveBeenCalledWith('/health-safety/injuries/9?section=connections&return_to=%2Ftasks%3Fassigned%3Dme');
});

it('keeps unknown private counts distinct from healthy zero and preserves medically authorised source menus', () => {
    render(<InjuryRegisterList rows={[row]} total={1} returnTo="/health-safety/injuries" medical />);
    expect(screen.getByText('Not confirmed plans · 0 assessments · 1 files')).toBeInTheDocument();
    fireEvent.contextMenu(screen.getAllByRole('row')[1], { clientX: 70, clientY: 100 });
    for (const name of ['Open injury', 'Connected work', 'Recovery plans', 'Assessments', 'Evidence', 'History']) expect(screen.getByRole('menuitem', { name })).toBeInTheDocument();
});
