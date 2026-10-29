import { act, cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react';
import { useState, type ReactNode } from 'react';
import { afterEach, beforeEach, expect, it, vi } from 'vitest';
import { InjuryCommandForm } from './injury-command-form';
import type { InjuryFormValues } from './injury-command-values';
import type { InjuryCaptureOptions, InjuryRecord } from './injury-workspace-types';

const io = vi.hoisted(() => ({ get: vi.fn(), save: vi.fn(), source: vi.fn(), allowed: true, checkFailed: false, stale: false, checkedResponse: null as unknown }));
vi.mock('axios', () => ({ default: { get: io.get } }));
vi.mock('@inertiajs/react', () => ({ Link: ({ href, children }: { href: string; children: ReactNode }) => <a href={href}>{children}</a> }));
vi.mock('./use-injury-command', () => ({ useInjuryCommand: io.source }));
vi.mock('@/components/health-safety/restraints/command-status', () => ({ SourceCommandStatus: () => null }));
vi.mock('@/components/wizard/shell', () => ({ WizardShell: ({ children, footerEnd }: { children: ReactNode; footerEnd: ReactNode }) => <>{children}<footer>{footerEnd}</footer></> }));
vi.mock('@/components/wizard/primitives', () => ({
    Field: ({ label, htmlFor, children }: { label: string; htmlFor?: string; children: ReactNode }) => <div><label htmlFor={htmlFor}>{label}</label>{children}</div>,
    SelectInput: ({ id, value, disabled, options, onChange }: { id: string; value: string; disabled: boolean; options: { value: string; label: string }[]; onChange: (value: string) => void }) => <select id={id} value={value} disabled={disabled} onChange={(event) => onChange(event.target.value)}><option value="">Choose</option>{options.map((item) => <option key={item.value} value={item.value}>{item.label}</option>)}</select>,
}));
vi.mock('./injury-people-picker', () => ({ InjuryPeoplePicker: ({ id, value, group, siteId, onChange, onSelection }: { id: string; value: string; group: string; siteId?: string; onChange: (value: string) => void; onSelection: (person: { id: number; name: string }) => void }) => {
    const person = group === 'workers' ? { id: siteId === '8' ? 23 : 12, name: siteId === '8' ? 'Destination worker' : 'Original worker' } : { id: 31, name: 'Destination owner' };
    return <select id={id} value={value} onChange={(event) => { onChange(event.target.value); onSelection(person); }}><option value="">Choose</option><option value={String(person.id)}>{person.name}</option></select>;
} }));

const record = () => ({ id: 9, source_version: 4, source_integrity: 'valid', reference: 'INJ-0009',
    worker: { id: 12, name: 'Original worker' }, site: { id: 4, name: 'Kauri' }, medical_access: 'restricted',
    injury_date: '2026-10-11T12:05:00Z', injury_type: 'strain', severity: 'moderate', body_part_affected: 'Left arm', description: 'Original factual report',
    lost_time_days: 0, coordination: { coordinator: { id: 17, name: 'Original owner' }, next_review_date: '2026-10-20' }, rtw_plans: null,
    related_incident: { id: 7, label: 'INC-0007', title: 'Original incident' }, connections: { incident: { access: 'readable' } },
}) as unknown as InjuryRecord;
const options = (site = 4) => ({ data: { injury_id: 9, expected_version: 4, canonical_site_id: 4, site_id: site, worker_id: 12,
    sites: [{ id: 4, name: 'Kauri' }, { id: 8, name: 'Matai' }], workers: [], coordinators: [], incidents: [] } });
function useTestCommand({ initial }: { initial: InjuryFormValues }) {
    const [data, setData] = useState(initial);
    return { form: { data, errors: {}, processing: false }, change: (key: keyof InjuryFormValues, value: InjuryFormValues[keyof InjuryFormValues]) => setData((before) => ({ ...before, [key]: value })),
        guard: { version: 4 }, allowed: io.allowed, checking: false, stale: io.stale, checkFailed: io.checkFailed, receipt: null, checkedResponse: io.checkedResponse, save: io.save };
}
beforeEach(() => { vi.clearAllMocks(); io.allowed = true; io.checkFailed = false; io.stale = false; io.checkedResponse = null; io.source.mockImplementation(useTestCommand); });
afterEach(cleanup);

it('reviews and submits Site correction and explicit destination owner as one command while retaining entered facts', async () => {
    io.get.mockResolvedValueOnce(options()).mockResolvedValueOnce(options(8));
    render(<InjuryCommandForm detail={record()} command="edit" actorId={20} onDone={vi.fn()} onCancel={vi.fn()} returnTo="/tasks?assigned=me" />);
    await waitFor(() => expect(screen.getByLabelText('Site')).not.toBeDisabled());
    fireEvent.change(screen.getByLabelText('What happened'), { target: { value: 'Retained factual correction' } });
    fireEvent.change(screen.getByLabelText('Site'), { target: { value: '8' } });
    await waitFor(() => expect(io.get).toHaveBeenLastCalledWith('/health-safety/injuries/9/command-options', expect.objectContaining({ params: { site_id: '8' } })));
    expect(screen.getByLabelText('Injured worker')).toHaveValue('');
    expect(screen.getByLabelText('What happened')).toHaveValue('Retained factual correction');
    fireEvent.change(screen.getByLabelText('Injured worker'), { target: { value: '23' } });
    fireEvent.change(screen.getByLabelText('Injury owner at the new site'), { target: { value: '31' } });
    fireEvent.change(screen.getByLabelText('Next check-in date'), { target: { value: '2026-10-21' } });
    fireEvent.change(screen.getByLabelText('Why are you changing the site?'), { target: { value: 'Recorded at the wrong Site; destination owner accepts coordination' } });
    fireEvent.click(screen.getByRole('button', { name: 'Next' }));
    fireEvent.click(screen.getByRole('button', { name: 'Next' }));
    expect(screen.getByText('Matai')).toBeInTheDocument(); expect(screen.getByText('Destination owner')).toBeInTheDocument();
    fireEvent.click(screen.getByRole('button', { name: 'Save changes' }));
    expect(io.save).toHaveBeenCalledWith('put', '/health-safety/injuries/9', expect.objectContaining({ user_id: 23, site_id: 8,
        coordinator_user_id: 31, description: 'Retained factual correction', related_incident_id: null, next_review_date: '2026-10-21', return_to: '/tasks?assigned=me' }));
    expect(io.save.mock.calls[0][2]).not.toHaveProperty('notes');
});

it('withholds saving after mismatched canonical Site choices without replacing the draft', async () => {
    io.get.mockResolvedValueOnce({ data: { ...options().data, canonical_site_id: 8 } });
    render(<InjuryCommandForm detail={record()} command="edit" actorId={20} onDone={vi.fn()} onCancel={vi.fn()} returnTo="/tasks" />);
    await screen.findByRole('alert');
    expect(screen.getByLabelText('What happened')).toHaveValue('Original factual report');
    fireEvent.click(screen.getByRole('button', { name: 'Next' })); fireEvent.click(screen.getByRole('button', { name: 'Next' }));
    expect(screen.getByRole('button', { name: 'Save changes' })).toBeDisabled(); expect(io.save).not.toHaveBeenCalled();
});

it('opens capture without an existing record and keeps medical-read authority separate from the blank report', () => {
    const capture = { sites: [{ id: 4, name: 'Kauri' }], workers: [], incidents: [], can: { create: true, view_register: false }, medical_access: 'restricted' } as unknown as InjuryCaptureOptions;
    render(<InjuryCommandForm detail={null} command="create" actorId={20} capture={capture} onDone={vi.fn()} onCancel={vi.fn()} returnTo="/tasks" />);
    expect(screen.getByLabelText('Site')).toBeInTheDocument(); expect(screen.getByLabelText('What happened')).toHaveValue('');
    expect(io.get).not.toHaveBeenCalled(); expect(io.save).not.toHaveBeenCalled();
    fireEvent.click(screen.getByRole('button', { name: 'Next' }));
    expect(screen.queryByLabelText('Medical treatment')).not.toBeInTheDocument();
    fireEvent.click(screen.getByRole('button', { name: 'Next' }));
    expect(screen.getByRole('button', { name: 'Save injury' })).toBeDisabled();
});

it('permits blank new reported detail entry by explicit server capability and retains the draft through capability withdrawal', () => {
    const capture = { sites: [{ id: 4, name: 'Kauri' }], workers: [], incidents: [], can: { create: true, view_register: false, record_reported_details: true }, medical_access: 'restricted' } as unknown as InjuryCaptureOptions;
    const props = { detail: null, command: 'create' as const, actorId: 20, capture, onDone: vi.fn(), onCancel: vi.fn(), returnTo: '/tasks' };
    const { rerender } = render(<InjuryCommandForm {...props} />);
    fireEvent.click(screen.getByRole('button', { name: 'Next' }));
    expect(screen.getByLabelText('Immediate treatment')).toHaveValue('');
    expect(screen.getByLabelText('Private injury notes')).toHaveValue('');
    fireEvent.change(screen.getByLabelText('Private injury notes'), { target: { value: 'Reporter entered new detail' } });
    rerender(<InjuryCommandForm {...props} capture={{ ...capture, can: { ...capture.can, record_reported_details: false } }} />);
    expect(screen.queryByLabelText('Private injury notes')).not.toBeInTheDocument();
    expect(screen.getByRole('alert')).toHaveTextContent('draft is still here');
    rerender(<InjuryCommandForm {...props} />);
    expect(screen.getByLabelText('Private injury notes')).toHaveValue('Reporter entered new detail');
    expect(io.save).not.toHaveBeenCalled();
});

it.each(['denied', 'malformed'] as const)('withholds private inputs and review serialization after a fresh %s check without falling back to original capture access', (outcome) => {
    const capture = { sites: [{ id: 4, name: 'Kauri' }], workers: [], incidents: [], can: { create: true, view_register: false, record_reported_details: true }, medical_access: 'restricted' } as unknown as InjuryCaptureOptions;
    const props = { detail: null, command: 'create' as const, actorId: 20, capture, onDone: vi.fn(), onCancel: vi.fn(), returnTo: '/tasks' };
    const { rerender } = render(<InjuryCommandForm {...props} />);
    fireEvent.click(screen.getByRole('button', { name: 'Next' }));
    fireEvent.change(screen.getByLabelText('Private injury notes'), { target: { value: 'Retained new reported detail' } });
    io.allowed = outcome !== 'denied'; io.checkFailed = outcome === 'malformed';
    rerender(<InjuryCommandForm {...props} />);
    expect(screen.queryByLabelText('Private injury notes')).not.toBeInTheDocument();
    fireEvent.click(screen.getByRole('button', { name: 'Next' }));
    expect(screen.queryByText('Retained new reported detail')).not.toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Save injury' })).toBeDisabled(); expect(io.save).not.toHaveBeenCalled();
    io.allowed = true; io.checkFailed = false; rerender(<InjuryCommandForm {...props} />);
    fireEvent.click(screen.getByRole('button', { name: 'Back' }));
    expect(screen.getByLabelText('Private injury notes')).toHaveValue('Retained new reported detail');
});

it.each(['denied', 'malformed'] as const)('retains reported draft detail after a fresh %s Site-choice capability response and waits for a valid retry before restoring its fields', async (outcome) => {
    const capture = { sites: [{ id: 4, name: 'Kauri' }], workers: [], incidents: [], can: { create: true, view_register: false, record_reported_details: true }, medical_access: 'restricted' } as unknown as InjuryCaptureOptions;
    io.get.mockResolvedValueOnce({ data: { ...capture, can: { ...capture.can, record_reported_details: outcome === 'denied' ? false : 'true' } } });
    render(<InjuryCommandForm detail={null} command="create" actorId={20} capture={capture} onDone={vi.fn()} onCancel={vi.fn()} returnTo="/tasks" />);
    fireEvent.click(screen.getByRole('button', { name: 'Next' }));
    fireEvent.change(screen.getByLabelText('Private injury notes'), { target: { value: 'Retained pending reported detail' } });
    fireEvent.click(screen.getByRole('button', { name: 'Back' }));
    fireEvent.change(screen.getByLabelText('Site'), { target: { value: '4' } });
    await screen.findByText('We couldn’t load the list of choices. Try again before saving.');
    fireEvent.click(screen.getByRole('button', { name: 'Next' }));
    expect(screen.queryByLabelText('Private injury notes')).not.toBeInTheDocument();
    fireEvent.click(screen.getByRole('button', { name: 'Next' }));
    expect(screen.queryByText('Retained pending reported detail')).not.toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Save injury' })).toBeDisabled();
    fireEvent.click(screen.getByRole('button', { name: 'Back' }));
    let restore!: (value: { data: InjuryCaptureOptions }) => void;
    io.get.mockImplementationOnce(() => new Promise<{ data: InjuryCaptureOptions }>((resolve) => { restore = resolve; }));
    fireEvent.click(screen.getByRole('button', { name: 'Try again' }));
    await waitFor(() => expect(io.get).toHaveBeenCalledTimes(2));
    expect(screen.queryByLabelText('Private injury notes')).not.toBeInTheDocument();
    await act(async () => restore({ data: capture }));
    expect(screen.getByLabelText('Private injury notes')).toHaveValue('Retained pending reported detail');
    expect(io.save).not.toHaveBeenCalled();
});

it.each(['denied', 'malformed', 'medical-restricted'] as const)('withholds saved private edit fields and Review after a fresh %s source read while retaining the draft', async (outcome) => {
    io.get.mockResolvedValue(options());
    const detail = { ...record(), medical_access: 'readable' as const, notes: 'Previously saved private note' };
    const props = { detail, command: 'edit' as const, actorId: 20, onDone: vi.fn(), onCancel: vi.fn(), returnTo: '/tasks' };
    const { rerender } = render(<InjuryCommandForm {...props} />);
    await waitFor(() => expect(screen.getByLabelText('Site')).not.toBeDisabled());
    fireEvent.click(screen.getByRole('button', { name: 'Next' }));
    expect(screen.getByLabelText('Private injury notes')).toHaveValue('Previously saved private note');
    fireEvent.change(screen.getByLabelText('Private injury notes'), { target: { value: 'Retained private correction' } });
    io.allowed = outcome !== 'denied'; io.checkFailed = outcome === 'malformed';
    if (outcome === 'medical-restricted') { io.stale = true; io.checkedResponse = { detail: { ...detail, source_version: 6, medical_access: 'restricted' } }; }
    rerender(<InjuryCommandForm {...props} />);
    expect(screen.queryByLabelText('Private injury notes')).not.toBeInTheDocument();
    fireEvent.click(screen.getByRole('button', { name: 'Next' }));
    expect(screen.queryByText('Retained private correction')).not.toBeInTheDocument();
    expect(screen.getByText('Original factual report')).toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Save changes' })).toBeDisabled(); expect(io.save).not.toHaveBeenCalled();
    io.allowed = true; io.checkFailed = false; io.stale = false; io.checkedResponse = { detail };
    rerender(<InjuryCommandForm {...props} />);
    fireEvent.click(screen.getByRole('button', { name: 'Back' }));
    expect(screen.getByLabelText('Private injury notes')).toHaveValue('Retained private correction');
});

it.each(['review', 'remove_evidence'] as const)('withholds the private %s command body after access withdrawal and restores it only after current access returns', (command) => {
    const detail = { ...record(), medical_access: 'readable' as const, attachments: [{ id: 44, original_name: 'Private clinical certificate.pdf' }] } as InjuryRecord;
    const props = { detail, command, actorId: 20, selected: { attachment: 44 }, onDone: vi.fn(), onCancel: vi.fn(), returnTo: '/tasks' };
    const { rerender } = render(<InjuryCommandForm {...props} />);
    const label = command === 'review' ? 'Review findings and next steps' : 'Removal reason';
    fireEvent.change(screen.getByLabelText(label), { target: { value: 'Retained private command entry' } });
    if (command === 'remove_evidence') expect(screen.getByText(/Private clinical certificate\.pdf/)).toBeInTheDocument();
    io.allowed = false; rerender(<InjuryCommandForm {...props} />);
    expect(screen.queryByLabelText(label)).not.toBeInTheDocument();
    expect(screen.queryByText(/Private clinical certificate\.pdf/)).not.toBeInTheDocument();
    expect(screen.getByRole('alert')).toHaveTextContent('draft is still here');
    expect(screen.getByRole('button', { name: command === 'review' ? 'Save review' : 'Remove file' })).toBeDisabled();
    io.allowed = true; rerender(<InjuryCommandForm {...props} />);
    expect(screen.getByLabelText(label)).toHaveValue('Retained private command entry');
    expect(io.save).not.toHaveBeenCalled();
});
