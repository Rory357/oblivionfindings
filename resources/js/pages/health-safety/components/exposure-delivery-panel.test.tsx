import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react';
import { afterEach, expect, it, vi } from 'vitest';
const io = vi.hoisted(() => ({ get: vi.fn(), post: vi.fn() }));
vi.mock('axios', () => ({ default: { get: io.get, post: io.post, isAxiosError: (value: unknown) => !!value && typeof value === 'object' && 'response' in value } }));
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
// The real GET /health-safety/chemical-exposures/{id}/deliveries shape (ChemicalExposureDeliveryWorkspaceService::index).
const automatic = { failed_attempts: 0, failure: null, needs_manager: false, next_attempt_at: null, last_attempted_at: null, stuck: false } as const;
const row = (over: Partial<ExposureDeliveryAttempt> = {}): ExposureDeliveryAttempt => ({ id: 41, kind: 'health_safety', attempt_state: 'delivered', currency: 'current',
    source_revision: 3, attempts: 1, acceptance_integrity: 'recorded', destination: { state: 'hs_event_present', can_deliver: true, blocked_reason: null },
    automatic: { ...automatic }, can: { retry: false }, blocked_reason: 'current_manage_required', ...over });
const list = (attempts: ExposureDeliveryAttempt[] | null = [row()], source_integrity: ExposureDeliveryCollection['source_integrity'] = 'recorded'): ExposureDeliveryCollection =>
    ({ source_integrity, attempts, page: { current_page: 1, total: attempts?.length ?? null } });
afterEach(() => { cleanup(); io.get.mockReset(); io.post.mockReset(); });

it('reads the real deliveries endpoint only when asked and shows a checked destination', async () => {
    io.get.mockResolvedValueOnce({ data: list() });
    render(<ExposureDeliveryPanel actorId={7} detail={detail()} active />);
    expect(io.get).not.toHaveBeenCalled();
    fireEvent.click(screen.getByRole('button', { name: 'Check delivery records' }));
    await waitFor(() => expect(screen.getByText('On the H&S register')).toBeInTheDocument());
    expect(io.get).toHaveBeenCalledWith('/health-safety/chemical-exposures/22/deliveries', expect.objectContaining({ headers: { Accept: 'application/json' } }));
    expect(screen.queryByRole('button', { name: 'Retry delivery' })).not.toBeInTheDocument();
    expect(screen.queryByRole('link')).not.toBeInTheDocument();
    expect(io.post).not.toHaveBeenCalled();
});

it('loads by itself when the page says the exposure is not on the register yet', async () => {
    io.get.mockResolvedValueOnce({ data: list([row({ attempt_state: 'pending', destination: { state: 'unconfirmed', can_deliver: true, blocked_reason: null },
        automatic: { ...automatic, failed_attempts: 2, failure: 'error', next_attempt_at: '2026-10-12T01:02:00Z', stuck: true } })]) });
    render(<ExposureDeliveryPanel actorId={7} detail={detail()} active autoCheck />);
    await waitFor(() => expect(screen.getByText('Failed · trying again')).toBeInTheDocument());
    expect(screen.getByText('Something went wrong while saving it to the register.')).toBeInTheDocument();
    expect(screen.getByText('Failed attempts')).toBeInTheDocument();
    expect(screen.getByText('Next try')).toBeInTheDocument();
});

it('lets a manager retry a stopped delivery and checks it again', async () => {
    const stopped = row({ attempt_state: 'pending', destination: { state: 'unconfirmed', can_deliver: true, blocked_reason: null },
        automatic: { ...automatic, failed_attempts: 1, failure: 'integrity', needs_manager: true, stuck: true }, can: { retry: true }, blocked_reason: null });
    io.get.mockResolvedValueOnce({ data: list([stopped]) }).mockResolvedValueOnce({ data: list() });
    io.post.mockResolvedValueOnce({ data: { retry_requested_by: 7, inspection: { destination: { state: 'hs_event_present' } } } });
    render(<ExposureDeliveryPanel actorId={7} detail={detail()} active />);
    fireEvent.click(screen.getByRole('button', { name: 'Check delivery records' }));
    await waitFor(() => expect(screen.getByText('Needs a manager')).toBeInTheDocument());
    expect(screen.getByText(/It won’t be tried again automatically/)).toBeInTheDocument();
    fireEvent.click(screen.getByRole('button', { name: 'Retry delivery' }));
    await waitFor(() => expect(screen.getByText('Delivered. The exposure is on the H&S register.')).toBeInTheDocument());
    expect(io.post).toHaveBeenCalledWith('/health-safety/chemical-exposures/22/deliveries/41/retry', {}, expect.objectContaining({ headers: { Accept: 'application/json' } }));
    await waitFor(() => expect(io.get).toHaveBeenCalledTimes(2));
    await waitFor(() => expect(screen.getByText('On the H&S register')).toBeInTheDocument());
});

it('explains a refused retry in plain words', async () => {
    const pending = row({ attempt_state: 'pending', destination: { state: 'unconfirmed', can_deliver: true, blocked_reason: null }, can: { retry: true }, blocked_reason: null });
    io.get.mockResolvedValue({ data: list([pending]) });
    io.post.mockRejectedValueOnce({ response: { status: 422, data: { errors: { delivery: ['source_advanced'] } } } });
    render(<ExposureDeliveryPanel actorId={7} detail={detail()} active />);
    fireEvent.click(screen.getByRole('button', { name: 'Check delivery records' }));
    await waitFor(() => expect(screen.getByRole('button', { name: 'Retry delivery' })).toBeInTheDocument());
    fireEvent.click(screen.getByRole('button', { name: 'Retry delivery' }));
    await waitFor(() => expect(screen.getByText('A later save replaced this delivery.')).toBeInTheDocument());
});

it('shows a follow-up attempt as a notice that is not sent', async () => {
    io.get.mockResolvedValueOnce({ data: list([row({ kind: 'followup', attempt_state: 'pending', destination: { state: 'unconfirmed', can_deliver: false, blocked_reason: null },
        automatic: undefined, blocked_reason: 'followup_notices_off' })]) });
    render(<ExposureDeliveryPanel actorId={7} detail={detail()} active />);
    fireEvent.click(screen.getByRole('button', { name: 'Check delivery records' }));
    await waitFor(() => expect(screen.getByText('Notice not sent')).toBeInTheDocument());
    expect(screen.getByText('Follow-up notices are off. The assignee sees this work in Tasks.')).toBeInTheDocument();
});

it('shows withheld history as unavailable, and refuses a malformed collection', async () => {
    io.get.mockResolvedValueOnce({ data: list(null, 'repair_required') });
    const { unmount } = render(<ExposureDeliveryPanel actorId={7} detail={detail()} active />);
    fireEvent.click(screen.getByRole('button', { name: 'Check delivery records' }));
    await waitFor(() => expect(screen.getByText(/Delivery history needs review/)).toBeInTheDocument());
    unmount();
    io.get.mockResolvedValueOnce({ data: { ...list(), attempts: [{ ...row(), can: { retry: 'yes' } }] } });
    render(<ExposureDeliveryPanel actorId={7} detail={detail()} active />);
    fireEvent.click(screen.getByRole('button', { name: 'Check delivery records' }));
    await waitFor(() => expect(screen.getByText('Delivery records could not be confirmed. Saved source acceptance remains separate.')).toBeInTheDocument());
    expect(screen.queryByText('On the H&S register')).not.toBeInTheDocument();
});

it('withdraws the private view when the read is refused', async () => {
    io.get.mockRejectedValueOnce({ response: { status: 404 } });
    const onWithdraw = vi.fn();
    render(<ExposureDeliveryPanel actorId={7} detail={detail()} active onWithdraw={onWithdraw} />);
    fireEvent.click(screen.getByRole('button', { name: 'Check delivery records' }));
    await waitFor(() => expect(onWithdraw).toHaveBeenCalledOnce());
    expect(screen.getByText('Delivery details and changes are withheld because current source access is unavailable.')).toBeInTheDocument();
});

it('drops a late read after source withdrawal, then reads fresh on return', async () => {
    let resolve!: (value: unknown) => void;
    io.get.mockImplementationOnce(() => new Promise((done) => { resolve = done; }));
    const d = detail(); const props = { actorId: 7, detail: d, active: true };
    const { rerender } = render(<ExposureDeliveryPanel {...props} />);
    fireEvent.click(screen.getByRole('button', { name: 'Check delivery records' }));
    await waitFor(() => expect(io.get).toHaveBeenCalledOnce());
    const signal = io.get.mock.calls[0][1].signal as AbortSignal;
    rerender(<ExposureDeliveryPanel {...props} detail={{ ...d, source_integrity: 'repair_required' }} />);
    expect(signal.aborted).toBe(true);
    resolve({ data: list() });
    expect(screen.queryByText('On the H&S register')).not.toBeInTheDocument();
    io.get.mockResolvedValueOnce({ data: list([]) });
    rerender(<ExposureDeliveryPanel {...props} />);
    await waitFor(() => expect(io.get).toHaveBeenCalledTimes(2));
    await waitFor(() => expect(screen.getByText('No delivery attempts are recorded for this exposure.')).toBeInTheDocument());
});
