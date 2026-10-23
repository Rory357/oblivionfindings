import { act, cleanup, fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import { afterEach, expect, it, vi } from 'vitest';
const io = vi.hoisted(() => ({ get: vi.fn(), post: vi.fn() }));
vi.mock('axios', () => ({ default: { get: io.get, post: io.post, isAxiosError: (value: unknown) => !!value && typeof value === 'object' && 'response' in value } }));
vi.mock('@/hooks/use-safety-workspace-drafts', () => ({ useSafetyDraftState: vi.fn() }));
import type { ExposureWorkspaceDetail } from '@/lib/health-safety/chemical-exposure-read-contract';
import { ExposureDeliveryPanel, type ExposureDeliveryAttempt, type ExposureDeliveryCollection } from './exposure-delivery-panel';
function detail(): ExposureWorkspaceDetail {
    const empty = { items: [], page: { current_page: 1, last_page: 1, total: 0, per_page: 25, has_more: false }, source_integrity: 'admitted' as const };
    return { exposure_access: 'readable', id: 22, source_integrity: 'recorded', command_context: { root_type: 'exposure', root_id: 22, expected_version: 3, source_version_id: 13 },
        can: { correct: true, review: true, assign_followup: true, upload_evidence: true }, record: { id: 22, worker: { id: 7, name: 'Synthetic worker' }, site: { id: 3, name: 'Synthetic Site' },
            substance: { id: 9, name: 'Synthetic chemical' }, exposed_at: null, exposure_type: null, exposure_duration: null, circumstances: null, symptoms: null, first_aid_given: null,
            medical_attention_sought: false, medical_treatment: null, medical_outcome: null, incident_reported: false, incident_connection: { state: 'absent', id: null, href: null, reason: null },
            recorded_by: null, updated_by: null, created_at: null, updated_at: null, attribution_state: 'recorded', href: null },
        reviews: empty, followups: empty, attachments: empty, history: empty, delivery: { state: 'unconfirmed', attempts: null } };
}
function pending(id = 41): ExposureDeliveryAttempt { return { id, kind: 'followup', attempt_state: 'pending', currency: 'current', source_revision: 3, attempts: 0, acceptance_integrity: 'recorded',
    destination: { state: 'unconfirmed', channel: 'database', external_delivery: 'not_attempted', href: null, href_reason: null, read: null, actioned: false, notification_preference: 'enabled', can_deliver: true, blocked_reason: null },
    can: { retry: true }, blocked_reason: null }; }
const list = (attempts = [pending()]): ExposureDeliveryCollection => ({ source_integrity: 'recorded', attempts, page: { current_page: 1, per_page: 25, total: attempts.length, last_page: 1, has_more: false } });
afterEach(() => { cleanup(); io.get.mockReset(); io.post.mockReset(); });
it('never dispatches from opening or checking the panel and does not certify reported delivered state', async () => {
    const row = pending(); row.attempt_state = 'delivered'; row.can.retry = false; io.get.mockResolvedValueOnce({ data: list([row]) });
    render(<ExposureDeliveryPanel actorId={7} detail={detail()} active />);
    expect(io.get).not.toHaveBeenCalled(); expect(io.post).not.toHaveBeenCalled();
    fireEvent.click(screen.getByRole('button', { name: 'Check delivery records' }));
    await waitFor(() => expect(screen.getByText('Destination unconfirmed')).toBeInTheDocument());
    expect(screen.getByText('Reported delivered · see destination proof')).toBeInTheDocument(); expect(screen.queryByText('Inbox notification present')).not.toBeInTheDocument();
    expect(screen.queryByRole('link')).not.toBeInTheDocument(); expect(io.post).not.toHaveBeenCalled();
});
it('explains a pending response update without certifying a delivered flag or the returned retry request', async () => {
    const row: ExposureDeliveryAttempt = { ...pending(), kind: 'health_safety', attempt_state: 'delivered', attempts: 1,
        destination: { ...pending().destination, channel: 'health_safety', notification_preference: null, blocked_reason: 'response_floor_pending' } };
    io.get.mockResolvedValueOnce({ data: list([row]) });
    io.post.mockResolvedValueOnce({ data: { retry_requested_by: 7, inspection: { ...row, attempts: 2 } } });
    render(<ExposureDeliveryPanel actorId={7} detail={detail()} active />);
    fireEvent.click(screen.getByRole('button', { name: 'Check delivery records' }));
    await waitFor(() => expect(screen.getByRole('button', { name: 'Update H&S response' })).toBeEnabled());
    expect(screen.getByText(/The saved H&S response needs updating/)).toBeInTheDocument();
    expect(screen.getByText('Destination unconfirmed')).toBeInTheDocument(); expect(screen.queryByText('H&S record present')).not.toBeInTheDocument();
    expect(io.post).not.toHaveBeenCalled(); fireEvent.click(screen.getByRole('button', { name: 'Update H&S response' }));
    await waitFor(() => expect(screen.getByText(/Retry processing returned a fresh destination check/)).toBeInTheDocument());
    expect(io.post).toHaveBeenCalledOnce(); expect(io.post.mock.calls[0][0]).toBe('/health-safety/chemical-exposures/22/deliveries/41/retry');
    expect(screen.queryByText('H&S record present')).not.toBeInTheDocument(); expect(screen.queryByRole('link')).not.toBeInTheDocument();
    const confirmed = { ...row, destination: { ...row.destination, state: 'hs_event_present' as const, blocked_reason: null } };
    io.get.mockResolvedValueOnce({ data: { inspection: confirmed } }); fireEvent.click(screen.getByRole('button', { name: 'Check this destination' }));
    await waitFor(() => expect(screen.getByText('H&S record present')).toBeInTheDocument());
    expect(screen.queryByText(/The saved H&S response needs updating/)).not.toBeInTheDocument(); expect(io.post).toHaveBeenCalledOnce();
});
it('keeps a lost delivery response on its exact original attempt and confirms inbox presence separately from completion', async () => {
    io.get.mockResolvedValueOnce({ data: list() }); io.post.mockRejectedValueOnce(new Error('Response lost'));
    render(<ExposureDeliveryPanel actorId={7} detail={detail()} active />);
    fireEvent.click(screen.getByRole('button', { name: 'Check delivery records' }));
    await waitFor(() => expect(screen.getByRole('button', { name: 'Retry this delivery' })).toBeEnabled());
    fireEvent.click(screen.getByRole('button', { name: 'Retry this delivery' })); fireEvent.click(screen.getByRole('button', { name: 'Retry this delivery' }));
    await waitFor(() => expect(screen.getByRole('button', { name: 'Check original delivery attempt' })).toBeEnabled());
    expect(io.post).toHaveBeenCalledOnce(); expect(io.post.mock.calls[0][0]).toBe('/health-safety/chemical-exposures/22/deliveries/41/retry'); expect(io.post.mock.calls[0][1]).toEqual({});
    expect(screen.getByRole('button', { name: 'Retry this delivery' })).toBeDisabled();
    const row = pending(); row.attempt_state = 'delivered'; row.destination.state = 'inbox_present'; row.destination.read = false;
    row.can.retry = false; row.destination.can_deliver = false; row.destination.notification_preference = 'disabled'; io.get.mockResolvedValueOnce({ data: { inspection: row } });
    fireEvent.click(screen.getByRole('button', { name: 'Check original delivery attempt' }));
    await waitFor(() => expect(screen.getByText('Inbox notification present')).toBeInTheDocument());
    expect(io.get.mock.calls[1][0]).toBe('/health-safety/chemical-exposures/22/deliveries/41');
    expect(screen.getByText('Not marked read')).toBeInTheDocument(); expect(screen.getByText(/does not record follow-up completion/)).toBeInTheDocument();
    expect(screen.getByText(/recipient has opted out/)).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'Retry this delivery' })).not.toBeInTheDocument(); expect(io.post).toHaveBeenCalledOnce();
});
it('refuses a substituted retry or inspection identity and prevents checking another attempt from clearing uncertainty', async () => {
    io.get.mockResolvedValueOnce({ data: list([pending(41), pending(42)]) });
    io.post.mockResolvedValueOnce({ data: { retry_requested_by: 7, inspection: pending(42) } });
    render(<ExposureDeliveryPanel actorId={7} detail={detail()} active />);
    fireEvent.click(screen.getByRole('button', { name: 'Check delivery records' }));
    await waitFor(() => expect(screen.getAllByRole('button', { name: 'Retry this delivery' })).toHaveLength(2));
    fireEvent.click(screen.getAllByRole('button', { name: 'Retry this delivery' })[0]);
    await waitFor(() => expect(screen.getByRole('button', { name: 'Check original delivery attempt' })).toBeEnabled());
    const rows = screen.getAllByRole('listitem'); expect(within(rows[1]).getByRole('button', { name: 'Check this destination' })).toBeDisabled();
    io.get.mockResolvedValueOnce({ data: { inspection: pending(42) } }); fireEvent.click(screen.getByRole('button', { name: 'Check original delivery attempt' }));
    await waitFor(() => expect(screen.getByText(/original delivery state is still unconfirmed/)).toBeInTheDocument());
    expect(screen.getAllByRole('button', { name: 'Retry this delivery' }).every((button) => (button as HTMLButtonElement).disabled)).toBe(true);
    expect(io.post).toHaveBeenCalledOnce();
});
it('withholds cached destination links and late reads after source withdrawal, then requires a fresh read on return', async () => {
    let resolve!: (value: unknown) => void; io.get.mockImplementationOnce(() => new Promise((done) => { resolve = done; }));
    const d = detail(); const props = { actorId: 7, detail: d, active: true }; const { rerender } = render(<ExposureDeliveryPanel {...props} />);
    fireEvent.click(screen.getByRole('button', { name: 'Check delivery records' })); await waitFor(() => expect(io.get).toHaveBeenCalledOnce());
    const signal = io.get.mock.calls[0][1].signal;
    rerender(<ExposureDeliveryPanel {...props} detail={{ ...d, source_integrity: 'repair_required' }} />); expect(signal.aborted).toBe(true);
    resolve({ data: list() }); expect(screen.queryByRole('button', { name: 'Retry this delivery' })).not.toBeInTheDocument(); expect(screen.queryByRole('link')).not.toBeInTheDocument();
    io.get.mockResolvedValueOnce({ data: list([]) }); rerender(<ExposureDeliveryPanel {...props} />);
    await waitFor(() => expect(io.get).toHaveBeenCalledTimes(2));
    expect(screen.queryByText('Named follow-up delivery')).not.toBeInTheDocument();
});
it('retains unknown totals and independent H&S presence when its usable record link is unavailable', async () => {
    const row: ExposureDeliveryAttempt = { ...pending(), kind: 'health_safety', destination: { ...pending().destination, state: 'hs_event_present', channel: 'health_safety', href: null,
        notification_preference: null, href_reason: 'hse_read_composition_unmounted' }, can: { retry: true } };
    io.get.mockResolvedValueOnce({ data: { source_integrity: 'repair_required', attempts: [row], page: { current_page: 1, per_page: 25, total: null, last_page: null, has_more: null } } });
    render(<ExposureDeliveryPanel actorId={7} detail={detail()} active />); fireEvent.click(screen.getByRole('button', { name: 'Check delivery records' }));
    await waitFor(() => expect(screen.getByText('H&S record present')).toBeInTheDocument());
    expect(screen.getByText('Page 1 · total unconfirmed')).toBeInTheDocument(); expect(screen.queryByRole('link')).not.toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Next delivery page' })).toBeDisabled(); expect(screen.getByText(/record link is unavailable here/)).toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Retry this delivery' })).toBeEnabled(); expect(io.post).not.toHaveBeenCalled();
});
it('cannot clear a lost response with an original-attempt inspection that finishes after source access is withdrawn', async () => {
    io.get.mockResolvedValueOnce({ data: list() }); io.post.mockRejectedValueOnce(new Error('Response lost'));
    const props = { actorId: 7, detail: detail(), active: true }; const { rerender } = render(<ExposureDeliveryPanel {...props} />);
    fireEvent.click(screen.getByRole('button', { name: 'Check delivery records' }));
    await waitFor(() => expect(screen.getByRole('button', { name: 'Retry this delivery' })).toBeEnabled());
    fireEvent.click(screen.getByRole('button', { name: 'Retry this delivery' }));
    await waitFor(() => expect(screen.getByRole('button', { name: 'Check original delivery attempt' })).toBeEnabled());
    let resolve!: (value: unknown) => void; io.get.mockImplementationOnce(() => new Promise((done) => { resolve = done; }));
    fireEvent.click(screen.getByRole('button', { name: 'Check original delivery attempt' }));
    await waitFor(() => expect(io.get).toHaveBeenCalledTimes(2)); const signal = io.get.mock.calls[1][1].signal;
    rerender(<ExposureDeliveryPanel {...props} detail={{ ...props.detail, source_integrity: 'repair_required' }} />);
    expect(signal.aborted).toBe(true); await act(async () => { resolve({ data: { inspection: pending() } }); });
    rerender(<ExposureDeliveryPanel {...props} />);
    expect(screen.getByRole('button', { name: 'Check original delivery attempt' })).toBeEnabled();
    expect(screen.getByRole('button', { name: 'Check delivery records' })).toBeDisabled(); expect(io.get).toHaveBeenCalledTimes(2);
    expect(io.post).toHaveBeenCalledOnce();
});
it('retains the original delivery identity when a write finishes while its source panel is withheld', async () => {
    io.get.mockResolvedValueOnce({ data: list() }); let resolve!: (value: unknown) => void;
    io.post.mockImplementationOnce(() => new Promise((done) => { resolve = done; }));
    const props = { actorId: 7, detail: detail(), active: true }; const { rerender } = render(<ExposureDeliveryPanel {...props} />);
    fireEvent.click(screen.getByRole('button', { name: 'Check delivery records' }));
    await waitFor(() => expect(screen.getByRole('button', { name: 'Retry this delivery' })).toBeEnabled());
    fireEvent.click(screen.getByRole('button', { name: 'Retry this delivery' }));
    await waitFor(() => expect(io.post).toHaveBeenCalledOnce()); const signal = io.post.mock.calls[0][2].signal;
    rerender(<ExposureDeliveryPanel {...props} active={false} />); expect(signal.aborted).toBe(false);
    const row = pending(); row.destination.state = 'inbox_present'; row.can.retry = false;
    await act(async () => { resolve({ data: { retry_requested_by: 7, inspection: row } }); });
    rerender(<ExposureDeliveryPanel {...props} />);
    expect(screen.getByRole('button', { name: 'Check original delivery attempt' })).toBeEnabled();
    expect(screen.queryByText('Inbox notification present')).not.toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Check delivery records' })).toBeDisabled(); expect(io.post).toHaveBeenCalledOnce();
});
