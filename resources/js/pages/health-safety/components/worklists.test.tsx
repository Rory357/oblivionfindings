import { cleanup, fireEvent, render, screen, within } from '@testing-library/react';
import type { ReactNode } from 'react';
import { afterEach, expect, it, vi } from 'vitest';
import { completedAgo, HsWorklists, type WorklistsPayload } from './worklists';

const navigation = vi.hoisted(() => ({ visit: vi.fn() }));

vi.mock('@inertiajs/react', () => ({
    Link: ({ href, children, ...props }: { href: string; children: ReactNode }) => <a href={href} {...props}>{children}</a>,
    router: { visit: navigation.visit }, usePage: () => ({ props: { auth: { can: { governance: { view: false } } } } }),
}));
vi.mock('@/components/health-safety/event-detail-dialog', () => ({ worksafeLabel: (value: { status: string }) => value.status }));
vi.mock('./hs-detail-dialog', () => ({ HsDetailDialog: ({ detail }: { detail: { railTitle: string } }) => <div role="dialog" aria-label="Recorded summary">{detail.railTitle}</div> }));
afterEach(() => { cleanup(); vi.clearAllMocks(); });
const href = '/health-safety/ppe/inventory/9?return_to=%2Fhealth-safety%2Fppe%3Ftab%3Dinv_expiring%26site_id%3D2';
const data: WorklistsPayload = {
    attention: [], overdue_corrective_actions: [], open_investigations: [], notifiable_events: [],
    expiring: [{ type: 'ppe_expiry', type_label: 'PPE expiry', label: 'Recorded gloves', due_date: '2026-10-12', days_until: 0, register_url: href, site: 'House A' }],
    ppe_expiring_state: { confirmed_count: 1, total: 1, repair_count: 0, physical_record_count: 1, displayed_count: 1, truncated: false, source_integrity: 'intact' },
};
it('opens the permanent equipment record directly instead of a second detail modal', () => {
    render(<HsWorklists worklists={data} show={['expiring']} />);
    expect(screen.getByRole('link', { name: /Recorded gloves/ })).toHaveAttribute('href', href);
    expect(screen.getByText(/it doesn’t confirm equipment is fit to use/)).toBeInTheDocument();
    expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
});
it('does not turn an undated repair marker into a due item or expose stale recorded facts', () => {
    render(<HsWorklists worklists={{ ...data, ppe_expiring_state: { ...data.ppe_expiring_state!, source_integrity: 'repair_required', total: null },
        expiring: [{ ...data.expiring[0], type: 'ppe_repair', label: 'Stale private-looking name', due_date: '2026-10-12', days_until: 0 }] }} show={['expiring']} />);
    const row = screen.getByRole('link', { name: /Equipment facts need review/ });
    expect(row).toHaveAttribute('href', href);
    expect(within(row).getByText('Source needs review')).toBeInTheDocument();
    expect(screen.getByText(/Some equipment facts are unconfirmed/)).toBeInTheDocument();
    expect(screen.queryByText('0 days')).not.toBeInTheDocument();
    expect(screen.queryByText('Due today')).not.toBeInTheDocument();
    expect(screen.queryByText('Stale private-looking name')).not.toBeInTheDocument();
});
it('withdraws stale equipment rows when current authority is restricted while keeping unrelated due items', () => {
    render(<HsWorklists worklists={{ ...data, ppe_expiring_state: { ...data.ppe_expiring_state!, source_integrity: 'restricted' },
        expiring: [...data.expiring, { ...data.expiring[0], type: 'drill', type_label: 'Emergency drill', label: 'Recorded drill', register_url: '/health-safety/drills' }] }} show={['expiring']} />);
    expect(screen.queryByRole('link', { name: /Recorded gloves/ })).not.toBeInTheDocument();
    expect(screen.queryByText('Recorded gloves')).not.toBeInTheDocument();
    expect(screen.getByText('Recorded drill')).toBeInTheDocument();
    expect(screen.getByText(/Equipment attention requires current safety and Site access/)).toBeInTheDocument();
});
it('retains a capped-preview explanation even when no equipment row fits the combined feed', () => {
    render(<HsWorklists worklists={{ ...data, expiring: [], ppe_expiring_state: { ...data.ppe_expiring_state!, truncated: true, displayed_count: 0 } }} show={['expiring']} />);
    expect(screen.getByText(/Some equipment items are outside this preview/)).toBeInTheDocument();
    expect(screen.getByText('No matching due items in this preview.')).toBeInTheDocument();
    expect(screen.queryByText('Nothing expiring soon.')).not.toBeInTheDocument();
});
it('describes empty filtered previews without claiming that the underlying work is complete', () => {
    render(<HsWorklists worklists={{ ...data, expiring: [], attention: [{ key: 'awaiting_hs_acceptance', label: 'Awaiting H&S acceptance', help: 'Accept responsibility for these handovers.', count: 7, items: [] }] }} show={['acceptance', 'corrective_actions', 'investigations', 'notifiable', 'expiring']} />);
    expect(screen.getByLabelText('7 H&S handovers awaiting acceptance')).toHaveTextContent('7');
    for (const text of [
        'No matching H&S handovers in this preview.',
        'No matching overdue actions in this preview.',
        'No matching investigations in this preview.',
        'No matching notifiable events in this preview.',
        'No matching due items in this preview.',
    ]) {
        expect(screen.getByText(text)).toBeInTheDocument();
    }
    expect(screen.queryByText('No overdue corrective actions.')).not.toBeInTheDocument();
    expect(screen.queryByText('No notifiable events on record.')).not.toBeInTheDocument();
});
const connected: WorklistsPayload = {
    ...data,
    overdue_corrective_actions: [{ id: 31, reference: 'CA-31', title: 'Repair the rail', priority: 'high', status: 'in_progress', due_date: '2026-10-10', days_overdue: 2, owner: 'Alex Chen', client_id: null, staff_id: null, event_reference: 'HS-22' }],
    open_investigations: [{ id: 41, event_id: 22, reference: 'INV-41', type: 'formal', status: 'in_progress', target_completion_date: '2026-10-20', is_overdue: false, owner: 'Alex Chen', client_id: null, staff_id: null, event_reference: 'HS-22' }],
    notifiable_events: [{ id: 22, event_reference: 'HS-22', title: 'Recorded event', incident_type: 'injury', status: 'pending', occurred_at: '2026-10-11T11:30:00Z', notified_at: null, notification_deadline: null, site_preserved: false, worksafe_ref: null, related_incident_id: null }],
};
it('opens actions, investigations and notifications on their permanent workspaces with visible owners', () => {
    render(<HsWorklists worklists={connected} show={['corrective_actions', 'investigations', 'notifiable']} />);
    expect(screen.getByRole('link', { name: 'Open corrective action: Repair the rail' })).toHaveAttribute('href', '/health-safety/corrective-actions/31');
    expect(screen.getByRole('link', { name: 'Open investigation: INV-41' })).toHaveAttribute('href', '/health-safety/events/22');
    expect(screen.getByRole('link', { name: /Recorded event/ })).toHaveAttribute('href', '/health-safety/events/22');
    expect(screen.getAllByText(/Alex Chen/)).toHaveLength(2);
    expect(screen.getAllByRole('button', { name: 'More actions' })).toHaveLength(3);
    expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
});
it('uses the real shared context menu and withdraws it when its source leaves the current preview', () => {
    const { rerender } = render(<HsWorklists worklists={connected} show={['corrective_actions']} />);
    const row = () => screen.getByRole('link', { name: 'Open corrective action: Repair the rail' }).closest('li')!;
    fireEvent.contextMenu(row(), { clientX: 40, clientY: 40 });
    const menu = screen.getByRole('menu');
    expect(within(menu).getByRole('menuitem', { name: 'Open record or register' })).toBeInTheDocument();
    fireEvent.click(within(menu).getByRole('menuitem', { name: 'View recorded summary' }));
    expect(screen.getByRole('dialog', { name: 'Recorded summary' })).toHaveTextContent('Repair the rail');
    expect(screen.queryByRole('menu')).not.toBeInTheDocument();
    fireEvent.contextMenu(row(), { clientX: 40, clientY: 40 });
    rerender(<HsWorklists worklists={{ ...connected, overdue_corrective_actions: [] }} show={['corrective_actions']} />);
    expect(screen.queryByRole('menu')).not.toBeInTheDocument();
    expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
    expect(screen.queryByText('Repair the rail')).not.toBeInTheDocument();
    rerender(<HsWorklists worklists={connected} show={['corrective_actions']} />);
    expect(screen.queryByRole('menu')).not.toBeInTheDocument();
    expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
    expect(navigation.visit).not.toHaveBeenCalled();
});
it('uses the events register when a preview lacks a confirmed investigation parent', () => {
    render(<HsWorklists worklists={{ ...connected, open_investigations: [{ ...connected.open_investigations[0], event_id: null }] }} show={['investigations']} />);
    expect(screen.getByRole('link', { name: 'Find investigation: INV-41' })).toHaveAttribute('href', '/health-safety/events');
    expect(screen.queryByRole('link', { name: 'Open investigation: INV-41' })).not.toBeInTheDocument();
});
it('labels a completed but unverified action as waiting for verification, not overdue', () => {
    const waiting = { ...connected.overdue_corrective_actions[0], status: 'completed', days_overdue: 5, completed_days_ago: 2 };
    render(<HsWorklists worklists={{ ...connected, overdue_corrective_actions: [waiting, { ...waiting, id: 32, title: 'Fix the gate', status: 'open' }] }} show={['corrective_actions']} />);
    expect(screen.getAllByText(/5d overdue/).length).toBeGreaterThan(0);
    expect(screen.getAllByText(/Waiting for verification/).length).toBeGreaterThan(0);
    expect(document.body.textContent?.match(/5d overdue/g)?.length).toBe(document.body.textContent?.match(/Waiting for verification/g)?.length);
    expect([0, 1, 4].map(completedAgo)).toEqual(['completed today', 'completed 1 day ago', 'completed 4 days ago']);
});
