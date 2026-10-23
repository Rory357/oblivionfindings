import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react';
import type { AnchorHTMLAttributes, ReactNode } from 'react';
import { afterEach, expect, it, vi } from 'vitest';
const io = vi.hoisted(() => ({ get: vi.fn(), replace: vi.fn(), request: vi.fn(), read: vi.fn(), post: vi.fn(), url: '/health-safety/chemical-exposures/22?section=overview' }));
vi.mock('axios', () => ({ default: { request: io.request, get: io.read, post: io.post, isAxiosError: (value: unknown) => !!value && typeof value === 'object' && 'response' in value } }));
vi.mock('@inertiajs/react', () => ({ Head: () => null,
    Link: ({ href, preserveState: _state, preserveScroll: _scroll, ...props }: AnchorHTMLAttributes<HTMLAnchorElement> & { href: string; preserveState?: boolean; preserveScroll?: boolean }) => <a href={href} {...props} />,
    usePage: () => ({ url: io.url, props: { auth: { user: { id: 7 }, can: {} } } }), router: { get: io.get, replace: io.replace } }));
vi.mock('@/layouts/app-layout', () => ({ default: ({ children }: { children: ReactNode }) => <main>{children}</main> }));
vi.mock('@/hooks/use-safety-workspace-drafts', async () => {
    const React = await import('react');
    return { SafetyFormDraftContext: React.createContext(null), useSafetyDraftState: vi.fn(), useSafetyWorkspaceDrafts: () => ({ register: vi.fn(), request: (callback: () => void) => callback(), confirmation: null }) };
});
import type { ExposureCapture, ExposureIndexProps, ExposureWorkspaceDetail } from '@/lib/health-safety/chemical-exposure-read-contract';
import ChemicalExposureIndex from './chemical-exposure-index';
import ChemicalExposureShow from './chemical-exposure-show';
import ChemicalExposureCapture from './chemical-exposure-capture';
import ChemicalExposureRecovery from './chemical-exposure-recovery';
import { ExposureCommandForm } from './components/exposure-command-form';

function detail(): ExposureWorkspaceDetail {
    const empty = { items: [], page: { current_page: 1, last_page: 1, total: 0, per_page: 25, has_more: false }, source_integrity: 'admitted' as const };
    return { exposure_access: 'readable', id: 22, source_integrity: 'recorded', command_context: { root_type: 'exposure', root_id: 22, expected_version: 3, source_version_id: 13 },
        can: { correct: true, review: true, assign_followup: true, upload_evidence: true },
        record: { id: 22, substance: { id: 9, name: 'Synthetic chemical' }, worker: { id: 7, name: 'Synthetic worker' }, site: { id: 3, name: 'Synthetic Site' },
            exposed_at: '2026-07-03T00:00:42Z', exposure_type: 'skin_contact', exposure_duration: null, circumstances: 'Private reported circumstances', symptoms: null,
            first_aid_given: null, medical_attention_sought: false, medical_treatment: 'none', medical_outcome: null, incident_reported: false,
            incident_connection: { state: 'unknown', id: null, href: null, reason: null }, recorded_by: null, updated_by: null, created_at: null, updated_at: null,
            attribution_state: 'recorded', href: '/health-safety/chemical-exposures/22' },
        reviews: empty, followups: { ...empty, items: [{ id: 31, attribution_state: 'recorded', assigned_to: { id: 7, name: 'Synthetic assignee' }, instruction: 'Private named instruction',
            due_at: null, completed_at: null, completion_notes: null, recorded_by: null, completed_by: null, can_complete: true, can_reassign: true }] },
        attachments: empty, history: empty, delivery: { state: 'unconfirmed', attempts: null } };
}
function index(): ExposureIndexProps {
    const record = detail().record!; const { incident_connection: _connection, ...row } = record;
    return { exposure_access: 'readable', records: [row], sites: [{ id: 3, name: 'Synthetic Site' }], counts: { exposures: 1, awaiting_medical_outcome: 0 },
        page: { current_page: 1, last_page: 1, total: 1, per_page: 25, has_more: false }, filters: { site_id: 3, worker_id: null, substance_id: null, from: null, to: null, page: 1 },
        can: { capture: true }, chemical_exposure_confirmation: null };
}
function capture(): ExposureCapture { return { substance: { id: 9, name: 'Synthetic chemical' }, sites: [{ id: 3, name: 'Synthetic Site' }], staff: { items: [], next_cursor: null },
    incidents: { access: 'not_selected', items: [], page: null }, options: { exposure_types: ['skin_contact'], medical_treatments: ['none'] }, can: { create: true, read_saved: false } }; }
async function choose(name: string, option: string) {
    fireEvent.keyDown(screen.getByRole('combobox', { name }), { key: 'ArrowDown' });
    fireEvent.click(await screen.findByRole('option', { name: option }));
}
afterEach(() => { cleanup(); vi.clearAllMocks(); io.read.mockReset(); io.request.mockReset(); io.post.mockReset(); });

it('withholds cached private identities, Site choices, counts and open actions on a restricted index', () => {
    render(<ChemicalExposureIndex {...index()} exposure_access="restricted" />);
    expect(screen.queryByText('Synthetic worker')).not.toBeInTheDocument(); expect(screen.queryByText('Synthetic Site')).not.toBeInTheDocument();
    expect(screen.queryByText('Synthetic chemical')).not.toBeInTheDocument(); expect(screen.queryByRole('combobox')).not.toBeInTheDocument();
    expect(screen.queryByRole('link', { name: /Synthetic/ })).not.toBeInTheDocument();
    fireEvent.click(screen.getByRole('button', { name: 'View recorded exposures' }));
    expect(screen.getByText(/Saved identities, facts and counts are withheld/).parentElement).toHaveFocus();
    expect(io.get).not.toHaveBeenCalled();
});
it('distinguishes unknown care answers from source repair and a confirmed zero pending outcomes', () => {
    const data = index(); const { rerender } = render(<ChemicalExposureIndex {...data} counts={{ exposures: 1, awaiting_medical_outcome: 0 }}
        count_integrity={{ awaiting_medical_outcome: 'reported_answer_unknown' }} />);
    expect(screen.getByText('Care answers need clarification')).toBeInTheDocument();
    expect(screen.getByText('Unconfirmed')).toBeInTheDocument(); expect(screen.queryByText('0')).not.toBeInTheDocument();
    expect(screen.getByText(/The number awaiting an outcome cannot yet be confirmed/)).toBeInTheDocument();
    expect(screen.queryByText(/Some source history needs review/)).not.toBeInTheDocument();
    rerender(<ChemicalExposureIndex {...data} count_integrity={{ awaiting_medical_outcome: 'confirmed' }} />);
    expect(screen.queryByText('Care answers need clarification')).not.toBeInTheDocument(); expect(screen.getByText('0')).toBeInTheDocument();
    rerender(<ChemicalExposureIndex {...data} exposure_access="restricted" count_integrity={{ awaiting_medical_outcome: 'reported_answer_unknown' }} />);
    expect(screen.queryByText(/unknown or unconfirmed care answer/)).not.toBeInTheDocument(); expect(screen.queryByText('Synthetic worker')).not.toBeInTheDocument();
});
it('keeps both mobile cards and desktop actions on the same exact record and retains the index filter in Back', () => {
    render(<ChemicalExposureIndex {...index()} />);
    const link = screen.getByRole('link', { name: 'Synthetic worker' });
    const target = new URL(link.getAttribute('href')!, 'https://example.invalid');
    expect(target.pathname).toBe('/health-safety/chemical-exposures/22'); expect(target.searchParams.get('return_to')).toContain('site_id=3');
    expect(screen.getAllByText('Synthetic worker')).toHaveLength(2); expect(screen.queryByText('Private reported circumstances')).not.toBeInTheDocument();
});
it('withholds retained index rows when original source admission is unavailable while keeping capture independently authorised', () => {
    const props = index(); const { rerender } = render(<ChemicalExposureIndex {...props} />);
    expect(screen.getAllByText('Synthetic worker')).toHaveLength(2);
    for (const source_integrity of ['association_unconfirmed', 'original_context_restricted', 'repair_required'] as const) {
        rerender(<ChemicalExposureIndex {...props} exposure_access="unavailable" source_integrity={source_integrity} />);
        expect(screen.queryByText('Synthetic worker')).not.toBeInTheDocument(); expect(screen.queryByText('Synthetic Site')).not.toBeInTheDocument();
        expect(screen.queryByText('Synthetic chemical')).not.toBeInTheDocument(); expect(screen.queryByRole('combobox')).not.toBeInTheDocument();
        expect(screen.queryByRole('button', { name: 'Apply exposure times' })).not.toBeInTheDocument();
        expect(screen.getByText('Unconfirmed')).toBeInTheDocument(); expect(screen.queryByText('Restricted')).not.toBeInTheDocument();
        fireEvent.click(screen.getByRole('button', { name: 'View recorded exposures' }));
        expect(screen.getByRole('status').parentElement).toHaveFocus(); expect(io.get).not.toHaveBeenCalled();
    }
    fireEvent.click(screen.getByRole('button', { name: 'Choose a chemical to report exposure' }));
    expect(io.get).toHaveBeenCalledWith('/health-safety/substances');
    rerender(<ChemicalExposureIndex {...props} />); expect(screen.getAllByText('Synthetic worker')).toHaveLength(2);
    rerender(<ChemicalExposureIndex {...props} source_integrity="repair_required" counts={{ exposures: null, awaiting_medical_outcome: null }}
        records={[...props.records!, { id: 23, attribution_state: 'repair_required', record: null, href: null }]}
        page={{ current_page: 1, per_page: 25, last_page: null, total: null, has_more: null }} />);
    expect(screen.getByText('Page 1 · total unconfirmed')).toBeInTheDocument(); expect(screen.getByRole('button', { name: 'Next page' })).toBeDisabled();
    expect(screen.queryByRole('link', { name: 'Exposure source needs review' })).not.toBeInTheDocument();
});
it('does not display cached facts or child actions when the current record loses source integrity', () => {
    io.url = '/health-safety/chemical-exposures/22?section=followups&action=complete_followup&followup=31';
    const d = detail(); const props = { detail: d, return_to: '/my-day', chemical_exposure_confirmation: null };
    const { rerender } = render(<ChemicalExposureShow {...props} />);
    expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
    expect(screen.getByRole('textbox', { name: 'Completion notes' })).toBeInTheDocument();
    rerender(<ChemicalExposureShow {...props} detail={{ ...d, source_integrity: 'repair_required' }} />);
    expect(screen.queryByText('Synthetic worker')).not.toBeInTheDocument(); expect(screen.queryByText('Private named instruction')).not.toBeInTheDocument();
    expect(screen.queryByRole('textbox', { name: 'Completion notes' })).not.toBeInTheDocument();
    expect(screen.getByText(/Saved private details and changes are withheld/)).toBeInTheDocument();
});
it('opens the exact read-only task on its resolved page and keeps that page and Back when choosing an allowed change', () => {
    io.url = '/health-safety/chemical-exposures/22?section=followups&followup=31';
    const d = detail(); d.focused_followup_id = 31; d.followup_focus_state = 'available';
    d.followups.page = { current_page: 2, last_page: 2, total: 26, per_page: 25, has_more: false };
    render(<ChemicalExposureShow detail={d} return_to="/my-day" chemical_exposure_confirmation={null} />);
    expect(screen.getByRole('listitem', { name: 'Selected follow-up' })).toHaveFocus();
    expect(screen.getByText('Page 2 of 2')).toBeInTheDocument();
    expect(screen.getByRole('link', { name: 'Back' })).toHaveAttribute('href', '/my-day');
    expect(screen.queryByRole('textbox', { name: 'Completion notes' })).not.toBeInTheDocument();
    expect(io.request).not.toHaveBeenCalled(); expect(io.post).not.toHaveBeenCalled(); expect(io.read).not.toHaveBeenCalled();
    fireEvent.click(screen.getByRole('button', { name: 'Record completion' }));
    const destination = new URL(io.replace.mock.calls[0][0].url, 'https://example.invalid');
    expect(destination.searchParams.get('action')).toBe('complete_followup'); expect(destination.searchParams.get('followup')).toBe('31');
    expect(destination.searchParams.get('followups_page')).toBe('2'); expect(destination.searchParams.get('return_to')).toBe('/my-day');
    fireEvent.click(screen.getByRole('button', { name: 'Previous page' }));
    const previous = new URL(io.get.mock.calls[0][0], 'https://example.invalid');
    expect(previous.searchParams.get('section')).toBe('followups'); expect(previous.searchParams.has('followup')).toBe(false);
    expect(previous.searchParams.has('action')).toBe(false); expect(previous.searchParams.has('followups_page')).toBe(false);
    expect(io.request).not.toHaveBeenCalled();
});
it('does not select a URL-only child or grant its actions and withdraws the focus hint with private access', () => {
    io.url = '/health-safety/chemical-exposures/22?section=followups&followup=31';
    const d = detail(); const row = d.followups.items![0]; if (row.attribution_state === 'repair_required') throw new Error('Expected admitted fixture');
    row.can_complete = false; row.can_reassign = false;
    const props = { detail: d, return_to: '/tasks?assigned=me', chemical_exposure_confirmation: null };
    const { rerender } = render(<ChemicalExposureShow {...props} />);
    expect(screen.getByText(/The linked follow-up cannot currently be confirmed/)).toBeInTheDocument();
    expect(screen.getByText(/The linked follow-up cannot currently be confirmed/).parentElement).toHaveFocus();
    expect(screen.queryByRole('listitem', { name: 'Selected follow-up' })).not.toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'Record completion' })).not.toBeInTheDocument();
    rerender(<ChemicalExposureShow {...props} detail={{ ...d, focused_followup_id: 90, followup_focus_state: 'available' }} />);
    expect(screen.queryByRole('listitem', { name: 'Selected follow-up' })).not.toBeInTheDocument();
    rerender(<ChemicalExposureShow {...props} detail={{ ...d, source_integrity: 'repair_required', focused_followup_id: 31, followup_focus_state: 'available' }} />);
    expect(screen.queryByText(/The linked follow-up/)).not.toBeInTheDocument(); expect(screen.queryByText('Private named instruction')).not.toBeInTheDocument();
    expect(io.request).not.toHaveBeenCalled(); expect(io.replace).not.toHaveBeenCalled();
});
it('opens the inline care review from the current server signal and permission without submitting or changing facts', () => {
    io.url = '/health-safety/chemical-exposures/22'; const d = detail();
    d.record!.response = { reported_attention: 'unknown', recorded_category: null, category_state: 'unanswered', response_floor: 'medium',
        basis: 'operational_triage', care_review_needed: true, harm_assessment: 'unassessed' };
    const props = { detail: d, return_to: '/my-day', chemical_exposure_confirmation: null };
    const { rerender } = render(<ChemicalExposureShow {...props} />);
    fireEvent.click(screen.getByRole('button', { name: 'Record a review' }));
    const target = new URL(io.replace.mock.calls[0][0].url, 'https://example.invalid');
    expect(target.searchParams.get('section')).toBe('reviews'); expect(target.searchParams.get('action')).toBe('review');
    expect(target.searchParams.get('return_to')).toBe('/my-day'); expect(io.request).not.toHaveBeenCalled();
    io.url = target.pathname + target.search;
    rerender(<ChemicalExposureShow {...props} />);
    expect(screen.getByRole('textbox', { name: 'Review notes' })).toBeInTheDocument(); expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
    expect(io.request).not.toHaveBeenCalled();
    rerender(<ChemicalExposureShow {...props} detail={{ ...d, can: { ...d.can, review: false } }} />);
    expect(screen.getByRole('textbox', { name: 'Review notes' })).toBeDisabled();
    expect(screen.getByRole('button', { name: 'Review submission' })).toBeDisabled(); expect(io.request).not.toHaveBeenCalled();
});
it('uses the permanent connected rail and keeps unconfirmed totals separate from a readable zero', () => {
    io.url = '/health-safety/chemical-exposures/22?section=overview'; const d = detail(); d.reviews.page!.total = null;
    render(<ChemicalExposureShow detail={d} return_to="/my-day" chemical_exposure_confirmation={null} />);
    expect(screen.getByText('Unconfirmed')).toBeInTheDocument(); expect(screen.getByText(/Private reported circumstances/)).toBeInTheDocument();
    fireEvent.click(screen.getByRole('button', { name: 'View reviews' }));
    expect(io.replace).toHaveBeenCalledWith(expect.objectContaining({ preserveState: true, preserveScroll: true, url: expect.stringContaining('section=reviews') }));
    expect(io.get).not.toHaveBeenCalled();
});
it('keeps a draft review hidden through authority withdrawal and restores the same in-memory entries after renewed admission', () => {
    const d = detail(); const props = { action: 'review' as const, actorId: 7, detail: d, active: true, onCancel: vi.fn(), onDone: vi.fn() };
    const { rerender } = render(<ExposureCommandForm {...props} />);
    fireEvent.change(screen.getByRole('textbox', { name: 'Review notes' }), { target: { value: 'Retained unsaved private review' } });
    rerender(<ExposureCommandForm {...props} detail={{ ...d, record: null, command_context: null, source_integrity: 'repair_required' }} />);
    expect(screen.queryByRole('textbox', { name: 'Review notes' })).not.toBeInTheDocument(); expect(screen.queryByText('Retained unsaved private review')).not.toBeInTheDocument();
    rerender(<ExposureCommandForm {...props} />);
    expect(screen.getByRole('textbox', { name: 'Review notes' })).toHaveValue('Retained unsaved private review'); expect(io.request).not.toHaveBeenCalled();
});
it('requires an explicit fresh revision after a definite rejected completion while preserving the entered notes', async () => {
    const d = detail(); io.request.mockRejectedValueOnce({ response: { status: 422, data: { errors: { expected_version: ['Record changed.'] } } } });
    render(<ExposureCommandForm action="complete_followup" actorId={7} detail={d} sourceId={31} active onCancel={vi.fn()} onDone={vi.fn()} />);
    fireEvent.change(screen.getByRole('textbox', { name: 'Completion notes' }), { target: { value: 'Retained completion details' } });
    fireEvent.click(screen.getByRole('button', { name: 'Review submission' })); fireEvent.click(screen.getByRole('button', { name: 'Confirm and record' }));
    await waitFor(() => expect(io.request).toHaveBeenCalledOnce()); const original = io.request.mock.calls[0][0].data;
    const next = detail(); next.command_context!.expected_version = 4; next.record!.circumstances = 'Current separately reviewed facts';
    io.read.mockResolvedValueOnce({ data: { detail: next } });
    fireEvent.click(screen.getByRole('button', { name: 'Review current record' }));
    await waitFor(() => expect(screen.getByText('Current separately reviewed facts')).toBeInTheDocument());
    expect(io.request).toHaveBeenCalledOnce(); fireEvent.click(screen.getByRole('button', { name: 'Use reviewed revision and keep my entries' }));
    expect(screen.getByRole('textbox', { name: 'Completion notes' })).toHaveValue('Retained completion details');
    io.request.mockRejectedValueOnce(new Error('Next response lost'));
    fireEvent.click(screen.getByRole('button', { name: 'Review submission' })); fireEvent.click(screen.getByRole('button', { name: 'Confirm and record' }));
    await waitFor(() => expect(io.request).toHaveBeenCalledTimes(2));
    expect(io.request.mock.calls[1][0].data).toMatchObject({ expected_version: 4, completion_notes: 'Retained completion details' });
    expect(io.request.mock.calls[1][0].data.idempotency_key).not.toBe(original.idempotency_key);
});
it('keeps an untouched occurrence omitted when a symptom correction is retried against an explicitly reviewed fresh revision', async () => {
    const d = detail();
    io.read.mockResolvedValue({ data: { sites: [{ id: 3, name: 'Synthetic Site' }], staff: { items: [{ id: 7, name: 'Synthetic worker' }], next_cursor: null },
        incidents: { access: 'restricted', items: null, page: null }, receivers: { items: [], next_cursor: null } } });
    io.request.mockRejectedValueOnce({ response: { status: 422, data: { errors: { expected_version: ['Record changed.'] } } } });
    render(<ExposureCommandForm action="correct" actorId={7} detail={d} active onCancel={vi.fn()} onDone={vi.fn()} />);
    fireEvent.change(screen.getByRole('textbox', { name: 'Reported symptoms' }), { target: { value: 'Updated reported symptoms' } });
    fireEvent.change(screen.getByRole('textbox', { name: 'Reason for this correction' }), { target: { value: 'Correct the reported symptoms' } });
    await waitFor(() => expect(screen.getByRole('button', { name: 'Review submission' })).toBeEnabled());
    fireEvent.click(screen.getByRole('button', { name: 'Review submission' }));
    expect(screen.getByText('The saved exposure time is kept unless you change it.')).toBeInTheDocument();
    fireEvent.click(screen.getByRole('button', { name: 'Confirm and record' }));
    await waitFor(() => expect(io.request).toHaveBeenCalledOnce());
    const request = io.request.mock.calls[0][0]; expect(request.method).toBe('put'); expect(request.url).toBe('/health-safety/chemical-exposures/22');
    expect(request.data).toMatchObject({ expected_version: 3, symptoms: 'Updated reported symptoms', reason: 'Correct the reported symptoms' });
    expect(request.data).not.toHaveProperty('exposed_at');
    for (const key of ['medical_attention_sought', 'medical_treatment', 'incident_reported', 'circumstances', 'user_id', 'site_id']) expect(request.data).not.toHaveProperty(key);
    const current = detail(); current.command_context!.expected_version = 4; current.command_context!.source_version_id = 14;
    current.record!.exposed_at = '2026-07-04T00:00:42Z'; current.record!.medical_attention_sought = true;
    current.record!.circumstances = 'New independently recorded circumstances'; io.read.mockResolvedValueOnce({ data: { detail: current } });
    fireEvent.click(screen.getByRole('button', { name: 'Review current record' }));
    await waitFor(() => expect(screen.getByRole('button', { name: 'Use reviewed revision and keep my entries' })).toBeInTheDocument());
    fireEvent.click(screen.getByRole('button', { name: 'Use reviewed revision and keep my entries' }));
    io.request.mockRejectedValueOnce(new Error('New response lost'));
    await waitFor(() => expect(screen.getByRole('button', { name: 'Review submission' })).toBeEnabled());
    fireEvent.click(screen.getByRole('button', { name: 'Review submission' })); fireEvent.click(screen.getByRole('button', { name: 'Confirm and record' }));
    await waitFor(() => expect(io.request).toHaveBeenCalledTimes(2));
    const next = io.request.mock.calls[1][0].data;
    expect(next).toMatchObject({ expected_version: 4, symptoms: 'Updated reported symptoms' }); expect(next).not.toHaveProperty('exposed_at');
    for (const key of ['medical_attention_sought', 'medical_treatment', 'incident_reported', 'circumstances', 'user_id', 'site_id']) expect(next).not.toHaveProperty(key);
    expect(next.idempotency_key).not.toBe(request.data.idempotency_key);
});
it('records care sought independently from unchanged treatment and preserves an explicit Unknown through exact retry', async () => {
    io.read.mockResolvedValue({ data: { sites: [{ id: 3, name: 'Synthetic Site' }], staff: { items: [{ id: 7, name: 'Synthetic worker' }], next_cursor: null },
        incidents: { access: 'restricted', items: null, page: null }, receivers: { items: [], next_cursor: null } } });
    io.request.mockRejectedValue(new Error('Lost response'));
    render(<ExposureCommandForm action="correct" actorId={7} detail={detail()} active onCancel={vi.fn()} onDone={vi.fn()} />);
    await choose('Medical attention sought', 'Yes'); await choose('Incident reported', 'Unknown');
    expect(screen.getByRole('combobox', { name: 'Recorded treatment' })).toHaveTextContent('none');
    fireEvent.change(screen.getByRole('textbox', { name: 'Reason for this correction' }), { target: { value: 'Record answers independently' } });
    await waitFor(() => expect(screen.getByRole('button', { name: 'Review submission' })).toBeEnabled());
    fireEvent.click(screen.getByRole('button', { name: 'Review submission' }));
    expect(screen.getByText('Unknown')).toBeInTheDocument();
    fireEvent.click(screen.getByRole('button', { name: 'Confirm and record' }));
    await waitFor(() => expect(screen.getByRole('button', { name: 'Retry exact submission' })).toBeEnabled());
    const original = io.request.mock.calls[0][0].data;
    expect(original).toMatchObject({ medical_attention_sought: true, incident_reported: null, expected_version: 3 });
    expect(original).not.toHaveProperty('medical_treatment');
    fireEvent.click(screen.getByRole('button', { name: 'Retry exact submission' }));
    await waitFor(() => expect(io.request).toHaveBeenCalledTimes(2)); expect(io.request.mock.calls[1][0].data).toEqual(original);
});
it('requires explicit confirmation to re-report an unchanged earlier answer and withholds that draft after access withdrawal', async () => {
    io.read.mockResolvedValue({ data: { sites: [{ id: 3, name: 'Synthetic Site' }], staff: { items: [{ id: 7, name: 'Synthetic worker' }], next_cursor: null },
        incidents: { access: 'restricted', items: null, page: null }, receivers: { items: [], next_cursor: null } } });
    io.request.mockRejectedValue(new Error('Lost response')); const d = detail();
    const props = { action: 'correct' as const, actorId: 7, detail: d, active: true, onCancel: vi.fn(), onDone: vi.fn() };
    const { rerender } = render(<ExposureCommandForm {...props} />);
    fireEvent.click(screen.getByRole('checkbox', { name: 'Confirm incident reported for this correction' }));
    fireEvent.change(screen.getByRole('textbox', { name: 'Reason for this correction' }), { target: { value: 'Confirm the earlier incident answer' } });
    rerender(<ExposureCommandForm {...props} detail={{ ...d, record: null, command_context: null, source_integrity: 'repair_required' }} />);
    expect(screen.queryByRole('checkbox')).not.toBeInTheDocument(); expect(screen.queryByText(/Current record:/)).not.toBeInTheDocument();
    expect(io.request).not.toHaveBeenCalled(); rerender(<ExposureCommandForm {...props} />);
    expect(screen.getByRole('checkbox', { name: 'Confirm incident reported for this correction' })).toBeChecked();
    await waitFor(() => expect(screen.getByRole('button', { name: 'Review submission' })).toBeEnabled());
    fireEvent.click(screen.getByRole('button', { name: 'Review submission' })); fireEvent.click(screen.getByRole('button', { name: 'Confirm and record' }));
    await waitFor(() => expect(io.request).toHaveBeenCalledOnce()); const payload = io.request.mock.calls[0][0].data;
    expect(payload.incident_reported).toBe(false); expect(payload).not.toHaveProperty('medical_attention_sought'); expect(payload).not.toHaveProperty('medical_treatment');
});
it('locks the original evidence submission through a lost response and offers its exact retained-byte retry', async () => {
    io.request.mockRejectedValueOnce(new Error('Lost write response')); io.request.mockRejectedValueOnce(new Error('Lost retry response'));
    const { container } = render(<ExposureCommandForm action="upload_evidence" actorId={7} detail={detail()} active onCancel={vi.fn()} onDone={vi.fn()} />);
    const file = new File(['retained synthetic bytes'], 'synthetic-private.txt'); const input = container.querySelector('input[type="file"]')!;
    fireEvent.change(input, { target: { files: [file] } });
    fireEvent.click(screen.getByRole('button', { name: 'Review submission' })); fireEvent.click(screen.getByRole('button', { name: 'Confirm and record' }));
    await waitFor(() => expect(screen.getByRole('button', { name: 'Retry exact submission' })).toBeEnabled());
    expect(screen.queryByRole('button', { name: 'Review current record' })).not.toBeInTheDocument();
    const original = io.request.mock.calls[0][0].data as FormData;
    fireEvent.click(screen.getByRole('button', { name: 'Retry exact submission' }));
    await waitFor(() => expect(io.request).toHaveBeenCalledTimes(2)); const retry = io.request.mock.calls[1][0].data as FormData;
    const readBytes = (blob: Blob) => new Promise<string>((resolve, reject) => { const reader = new FileReader(); reader.onload = () => resolve(String(reader.result)); reader.onerror = reject; reader.readAsText(blob); });
    expect(await readBytes(retry.get('file') as File)).toBe('retained synthetic bytes'); expect((retry.get('file') as File).name).toBe('synthetic-private.txt');
    expect(retry.get('idempotency_key')).toBe(original.get('idempotency_key')); expect(retry.get('expected_version')).toBe('3');
});
it('uses the shared capture dialog and lets a create-only reporter choose a Site without a saved-read grant', async () => {
    io.read.mockResolvedValue({ data: { ...capture(), staff: { items: [{ id: 7, name: 'Synthetic worker' }], next_cursor: null }, incidents: { access: 'restricted', items: null, page: null } } });
    render(<ChemicalExposureCapture capture={capture()} return_to="/my-day" chemical_exposure_confirmation={null} chemical_exposure_href={null} />);
    expect(screen.getByRole('dialog')).toBeInTheDocument();
    const submit = screen.getByRole('button', { name: 'Review submission' }) as HTMLButtonElement;
    expect(submit.form).toBeInstanceOf(HTMLFormElement); expect(submit.closest('form')).toBeNull();
    await choose('Site where this happened', 'Synthetic Site');
    await waitFor(() => expect(io.read).toHaveBeenCalledWith('/health-safety/chemical-exposures/capture-options', expect.objectContaining({ params: expect.objectContaining({ site_id: '3', substance_id: 9 }) })));
    expect(screen.queryByRole('link', { name: 'Open private exposure record' })).not.toBeInTheDocument();
    fireEvent.click(screen.getByRole('button', { name: 'Cancel' }));
    expect(screen.getByRole('link', { name: 'Back' })).toHaveAttribute('href', '/my-day');
    fireEvent.click(screen.getByRole('button', { name: 'Record an exposure' }));
    expect(screen.getByRole('combobox', { name: 'Site where this happened' })).not.toHaveTextContent('Synthetic Site');
});
it('keeps recovered physical cleanup pending until the actual result confirms it, and refuses a duplicate after an uncertain response', async () => {
    io.read.mockImplementation((path: string) => Promise.resolve({ data: path.endsWith('/staging') ? { attempts: [], next_cursor: null } : { attempts: [{ id: 42, state: 'pending', removed_from_record: true, can_retry: true }], next_cursor: null } }));
    io.post.mockRejectedValueOnce(new Error('Response lost'));
    render(<ChemicalExposureRecovery can={{ inspect: true, retry: true }} />);
    await waitFor(() => expect(io.read).toHaveBeenCalledTimes(2));
    fireEvent.click(screen.getByRole('button', { name: 'View removal cleanups' }));
    await waitFor(() => expect(screen.getByRole('button', { name: 'Retry physical file cleanup' })).toBeEnabled());
    fireEvent.click(screen.getByRole('button', { name: 'Retry physical file cleanup' }));
    await waitFor(() => expect(screen.getByText(/recovery response is unconfirmed/)).toBeInTheDocument());
    expect(screen.getByRole('button', { name: 'Retry physical file cleanup' })).toBeDisabled(); expect(screen.queryByText('File cleanup confirmed')).not.toBeInTheDocument();
    fireEvent.click(screen.getByRole('button', { name: 'Check recorded recovery state' }));
    await waitFor(() => expect(screen.getByRole('button', { name: 'Retry physical file cleanup' })).toBeEnabled());
    expect(io.post).toHaveBeenCalledOnce();
});
it('withholds cached recovery references after private access withdrawal', async () => {
    io.read.mockImplementation((path: string) => Promise.resolve({ data: path.endsWith('/staging') ? { attempts: [{ id: 41, state: 'cleanup_pending', accepted: false, can_retry: true }], next_cursor: null } : { attempts: [], next_cursor: null } }));
    const { rerender } = render(<ChemicalExposureRecovery can={{ inspect: true, retry: true }} />);
    await waitFor(() => expect(screen.getByText('Upload reference 41')).toBeInTheDocument());
    rerender(<ChemicalExposureRecovery can={{ inspect: false, retry: false }} />);
    expect(screen.queryByText('Upload reference 41')).not.toBeInTheDocument(); expect(screen.queryByRole('button', { name: 'Clean unaccepted staged bytes' })).not.toBeInTheDocument();
    expect(screen.getByText(/Recovery details and changes are withheld/)).toBeInTheDocument();
});
it('transfers the same incomplete follow-up to an eligible named person with a reason and exact reviewed revision', async () => {
    const d = detail(); io.read.mockResolvedValue({ data: { sites: [d.record!.site], staff: { items: [], next_cursor: null },
        receivers: { items: [{ id: 7, name: 'Synthetic assignee' }, { id: 8, name: 'New eligible coordinator' }], next_cursor: null }, incidents: { access: 'restricted', items: null, page: null } } });
    io.request.mockImplementationOnce(async (request) => ({ data: { chemical_exposure_confirmation: { accepted: true, actor_id: 7, command: 'reassign_followup',
        idempotency_key: request.data.idempotency_key, root_type: 'exposure', root_id: 22, source_type: 'followup', source_id: 31,
        expected_version: 3, version: 4, source_version_id: 14, changed: true }, chemical_exposure_href: '/health-safety/chemical-exposures/22' } }));
    render(<ExposureCommandForm action="reassign_followup" actorId={7} detail={d} sourceId={31} active onCancel={vi.fn()} onDone={vi.fn()} />);
    await waitFor(() => expect(io.read).toHaveBeenCalled());
    await choose('New named assignee', 'New eligible coordinator');
    fireEvent.change(screen.getByRole('textbox', { name: 'Reason for this transfer' }), { target: { value: 'Change of responsible coordinator' } });
    fireEvent.click(screen.getByRole('button', { name: 'Review submission' }));
    expect(screen.getByText('New eligible coordinator')).toBeInTheDocument(); expect(io.request).not.toHaveBeenCalled();
    fireEvent.click(screen.getByRole('button', { name: 'Confirm and record' }));
    await waitFor(() => expect(screen.getByText('Recorded submission confirmed')).toBeInTheDocument());
    const sent = io.request.mock.calls[0][0]; expect(sent).toMatchObject({ method: 'put', url: '/health-safety/chemical-exposures/22/followups/31/reassign',
        data: { assigned_to: '8', reason: 'Change of responsible coordinator', expected_version: 3 } });
    expect(sent.data).not.toHaveProperty('instruction'); expect(sent.data).not.toHaveProperty('due_at');
});
it('withholds a repaired child and its retained private form entries without certifying or replacing a lost submission', async () => {
    const d = detail(); io.request.mockRejectedValueOnce(new Error('Write response lost'));
    const props = { action: 'complete_followup' as const, actorId: 7, detail: d, sourceId: 31, active: true, retainedChild: d.followups.items![0] as import('@/lib/health-safety/chemical-exposure-read-contract').ExposureFollowup, onCancel: vi.fn(), onDone: vi.fn() };
    const { rerender } = render(<ExposureCommandForm {...props} />);
    fireEvent.change(screen.getByRole('textbox', { name: 'Completion notes' }), { target: { value: 'Retained private completion' } });
    fireEvent.click(screen.getByRole('button', { name: 'Review submission' })); fireEvent.click(screen.getByRole('button', { name: 'Confirm and record' }));
    await waitFor(() => expect(screen.getByRole('button', { name: 'Check original submission' })).toBeEnabled());
    const damaged = detail(); damaged.followups.items = [{ id: 31, attribution_state: 'repair_required', record: null, available: false, href: null, can_complete: false, can_reassign: false, can_remove: false }];
    rerender(<ExposureCommandForm {...props} detail={damaged} />);
    expect(screen.queryByText('Private named instruction')).not.toBeInTheDocument(); expect(screen.queryByText('Retained private completion')).not.toBeInTheDocument();
    expect(screen.queryByRole('textbox', { name: 'Completion notes' })).not.toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Check original submission' })).toBeEnabled(); expect(io.request).toHaveBeenCalledOnce();
    rerender(<ExposureCommandForm {...props} />); expect(screen.getByText('Retained private completion')).toBeInTheDocument();
    expect(io.request).toHaveBeenCalledOnce();
});
