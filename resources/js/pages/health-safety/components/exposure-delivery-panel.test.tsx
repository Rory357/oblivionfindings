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
// The real GET /health-safety/chemical-exposures/{id}/delivery-attempts shape (ChemicalExposureWorkspaceService::deliveryAttempts).
const row = (over: Partial<ExposureDeliveryAttempt> = {}): ExposureDeliveryAttempt => ({ id: 41, kind: 'followup', reported_state: 'pending', source_revision: 3, attempts: 0, can_retry: false, ...over });
const list = (attempts: ExposureDeliveryAttempt[] | null = [row()], state: ExposureDeliveryCollection['state'] = 'recorded'): ExposureDeliveryCollection =>
    ({ state, destination_confirmation: 'not_checked', attempts });
afterEach(() => { cleanup(); io.get.mockReset(); io.post.mockReset(); });

it('reads the real delivery-attempts endpoint only when asked and never sends or retries', async () => {
    io.get.mockResolvedValueOnce({ data: list([row({ kind: 'health_safety', reported_state: 'delivered', attempts: 1 })]) });
    render(<ExposureDeliveryPanel actorId={7} detail={detail()} active />);
    expect(io.get).not.toHaveBeenCalled();
    fireEvent.click(screen.getByRole('button', { name: 'Check delivery records' }));
    await waitFor(() => expect(screen.getByText('Health & Safety delivery')).toBeInTheDocument());
    expect(io.get).toHaveBeenCalledWith('/health-safety/chemical-exposures/22/delivery-attempts', expect.objectContaining({ headers: { Accept: 'application/json' } }));
    // A reported "delivered" is never shown as received: the destination is not checked.
    expect(screen.getByText('Reported delivered · destination not checked')).toBeInTheDocument();
    expect(screen.getByText('Destination not checked')).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: /Retry|Check this destination/ })).not.toBeInTheDocument();
    expect(screen.queryByRole('link')).not.toBeInTheDocument();
    expect(io.post).not.toHaveBeenCalled();
});

it('keeps follow-up completion separate from inbox presence', async () => {
    io.get.mockResolvedValueOnce({ data: list() });
    render(<ExposureDeliveryPanel actorId={7} detail={detail()} active />);
    fireEvent.click(screen.getByRole('button', { name: 'Check delivery records' }));
    await waitFor(() => expect(screen.getByText('Named follow-up delivery')).toBeInTheDocument());
    expect(screen.getByText('Inbox presence or a read flag does not record follow-up completion. Review the named work in Follow-ups.')).toBeInTheDocument();
    expect(screen.getByText('This counter does not include every failed or interrupted retry.')).toBeInTheDocument();
});

it('shows repair-required history as unavailable, and refuses a malformed collection', async () => {
    io.get.mockResolvedValueOnce({ data: list(null, 'repair_required') });
    const { unmount } = render(<ExposureDeliveryPanel actorId={7} detail={detail()} active />);
    fireEvent.click(screen.getByRole('button', { name: 'Check delivery records' }));
    await waitFor(() => expect(screen.getByText(/Delivery history needs review/)).toBeInTheDocument());
    unmount();
    io.get.mockResolvedValueOnce({ data: { ...list(), attempts: [{ ...row(), can_retry: true }] } });
    render(<ExposureDeliveryPanel actorId={7} detail={detail()} active />);
    fireEvent.click(screen.getByRole('button', { name: 'Check delivery records' }));
    await waitFor(() => expect(screen.getByText('Delivery records could not be confirmed. Saved source acceptance remains separate.')).toBeInTheDocument());
    expect(screen.queryByText('Named follow-up delivery')).not.toBeInTheDocument();
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
    expect(screen.queryByText('Named follow-up delivery')).not.toBeInTheDocument();
    io.get.mockResolvedValueOnce({ data: list([]) });
    rerender(<ExposureDeliveryPanel {...props} />);
    await waitFor(() => expect(io.get).toHaveBeenCalledTimes(2));
    await waitFor(() => expect(screen.getByText('No delivery attempts are recorded for this exposure.')).toBeInTheDocument());
});
