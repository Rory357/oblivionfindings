import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react';
import axios from 'axios';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { RecordingWindowEditor } from './recording-window-editor';
import { confirmsRecordingWindow, recordingWindowInterval, validRecordingPreview, type RecordingPreview, type RecordingWindowCommand } from './recording-window-command';

vi.mock('axios', () => ({ default: { get: vi.fn(), post: vi.fn(), isAxiosError: (value: unknown) => Boolean((value as { isAxiosError?: boolean })?.isAxiosError) } }));
afterEach(cleanup);
beforeEach(() => { vi.mocked(axios.get).mockReset(); vi.mocked(axios.post).mockReset(); });

const preview = (): RecordingPreview => ({
    schema_version: 1, actor_id: 7, incident_id: 9, incident_evidence_version: 0, active_window_revision: null,
    policy: { state: 'configured', revision: 2, rule: {
        identifier: 'incident-camera', before_seconds: 60, after_seconds: 120, max_before_seconds: 300, max_after_seconds: 600,
        default_start_at: '2026-10-20T10:00:00Z', default_end_at: '2026-10-20T10:03:00Z',
        earliest_start_at: '2026-10-20T09:56:00Z', latest_end_at: '2026-10-20T10:11:00Z',
    } },
    permissions: { can_record: true, can_expand: false, can_attach: false },
    sources: { items: [{
        camera: { id: 30, name: 'Entrance' }, recorder: { id: 40, name: 'Site recorder' }, site_id: 5,
        references_href: '/security-devices/devices/30/recording-references', in_active_window: false, can_record_window: true,
    }], complete: true, limit: 64 },
});
const receipt = (command: RecordingWindowCommand) => ({
    schema_version: 1, actor_id: 7, incident_id: 9,
    command: { idempotency_key: command.idempotency_key, kind: 'record_evidence_window',
        confirmed_incident_evidence_version: command.expected_incident_evidence_version + 1,
        confirmed_evidence_window_revision: 1, confirmed_at: '2026-10-20T10:05:00.123456Z' },
    window: { revision: 1, start_at: command.requested_start_at, end_at: command.requested_end_at,
        rule_identifier: 'incident-camera', rule_revision: 2, window_sha256: 'a'.repeat(64) },
    current: { incident_evidence_version: 1, evidence_window_revision: 1 },
});
function setup(data = preview()) {
    const callbacks = { onDraftStateChange: vi.fn(), onSaved: vi.fn(), onRefresh: vi.fn(), requestDiscard: vi.fn() };
    const props = { preview: data, actorId: 7, incidentId: 9, ...callbacks };
    return { ...render(<RecordingWindowEditor {...props} />), props, ...callbacks };
}
function fillAndSubmit() {
    fireEvent.click(screen.getByRole('checkbox', { name: /Entrance/ }));
    fireEvent.change(screen.getByLabelText('Reason for this period and selection'), { target: { value: 'Review the entrance around the incident.' } });
    fireEvent.click(screen.getByRole('button', { name: 'Record evidence window' }));
}

describe('incident evidence window commands', () => {
    it('rejects crossed accounts, malformed source lists and inconsistent server intervals before rendering choices', () => {
        expect(validRecordingPreview(preview(), 7, 9)).toBe(true);
        expect(validRecordingPreview(preview(), 8, 9)).toBe(false);
        const duplicate = preview(); duplicate.sources.items.push(duplicate.sources.items[0]);
        expect(validRecordingPreview(duplicate, 7, 9)).toBe(false);
        const crossed = preview(); crossed.sources.items[0].references_href = '/security-devices/devices/999/recording-references';
        expect(validRecordingPreview(crossed, 7, 9)).toBe(false);
        const wrongTime = preview(); wrongTime.policy.rule!.default_end_at = '2026-10-20T10:04:00Z';
        expect(validRecordingPreview(wrongTime, 7, 9)).toBe(false);
    });
    it('calculates the interval from the server instant and enforces expansion authority', () => {
        expect(recordingWindowInterval(preview(), '60', '120')).toEqual({
            requested_start_at: '2026-10-20T10:00:00Z', requested_end_at: '2026-10-20T10:03:00Z',
        });
        expect(() => recordingWindowInterval(preview(), '30', '90')).toThrow();
        expect(() => recordingWindowInterval(preview(), '61', '120')).toThrow();
        const expanded = preview(); expanded.permissions.can_expand = true;
        expect(recordingWindowInterval(expanded, '300', '600').requested_start_at).toBe('2026-10-20T09:56:00Z');
        expect(() => recordingWindowInterval(expanded, '301', '600')).toThrow();
    });

    it('retains the exact command after a lost response, then recovers without posting twice', async () => {
        vi.mocked(axios.post).mockRejectedValueOnce(new Error('connection lost'));
        const view = setup();
        fillAndSubmit();
        await screen.findByRole('button', { name: 'Check save result' });
        await waitFor(() => expect(screen.getByRole('button', { name: 'Check save result' })).not.toBeDisabled());
        const command = vi.mocked(axios.post).mock.calls.at(-1)![1] as RecordingWindowCommand;
        expect(command.approved_sources).toEqual([{ camera_id: 30, recorder_device_id: 40, site_id: 5 }]);
        const confirmed = receipt(command);
        confirmed.current = { incident_evidence_version: 4, evidence_window_revision: 3 };
        vi.mocked(axios.get).mockResolvedValueOnce({ status: 200, data: confirmed });
        const posts = vi.mocked(axios.post).mock.calls.length;
        fireEvent.click(screen.getByRole('button', { name: 'Check save result' }));
        await waitFor(() => expect(view.onSaved).toHaveBeenCalledTimes(1));
        expect(axios.get).toHaveBeenLastCalledWith(
            `/incidents/9/evidence-window-commands/${command.idempotency_key}?expected_actor_id=7`, expect.any(Object),
        );
        expect(vi.mocked(axios.post).mock.calls.length).toBe(posts);
        expect(view.onDraftStateChange).toHaveBeenLastCalledWith({ dirty: false, busy: false, uncertain: false });
    });

    it('does not trust a 201 response belonging to another actor; retry sends original bytes', async () => {
        vi.mocked(axios.post).mockImplementationOnce(async (_url, body) => {
            const data = receipt(body as RecordingWindowCommand); data.actor_id = 8;
            return { status: 201, data };
        });
        const view = setup();
        fillAndSubmit();
        await waitFor(() => expect(screen.getByRole('button', { name: 'Retry same submission' })).not.toBeDisabled());
        const original = vi.mocked(axios.post).mock.calls.at(-1)![1] as RecordingWindowCommand;
        expect(view.onSaved).not.toHaveBeenCalled();
        vi.mocked(axios.post).mockResolvedValueOnce({ status: 200, data: receipt(original) });
        fireEvent.click(screen.getByRole('button', { name: 'Retry same submission' }));
        await waitFor(() => expect(view.onSaved).toHaveBeenCalledTimes(1));
        expect(vi.mocked(axios.post).mock.calls.at(-1)![1]).toBe(original);
    });

    it('aborts the old request and ignores its success when the signed-in actor changes', async () => {
        let finish!: (value: unknown) => void;
        vi.mocked(axios.post).mockImplementationOnce(() => new Promise((resolve) => { finish = resolve; }));
        const view = setup();
        fillAndSubmit();
        const call = vi.mocked(axios.post).mock.calls.at(-1)!;
        const command = call[1] as RecordingWindowCommand;
        view.rerender(<RecordingWindowEditor {...view.props} actorId={8} />);
        expect(call[2]?.signal?.aborted).toBe(true);
        finish({ status: 201, data: receipt(command) });
        await waitFor(() => expect(screen.getByRole('alert')).toHaveTextContent('Reload the incident'));
        expect(view.onSaved).not.toHaveBeenCalled();
        expect(screen.queryByDisplayValue('Review the entrance around the incident.')).not.toBeInTheDocument();
    });

    it('requires explicit discard before replacing a stale edited camera selection', () => {
        const view = setup();
        fireEvent.change(screen.getByLabelText('Reason for this period and selection'), { target: { value: 'Keep these unsaved details.' } });
        const next = preview(); next.sources.items = [];
        view.rerender(<RecordingWindowEditor {...view.props} preview={next} />);
        expect(screen.getByRole('button', { name: 'Record evidence window' })).toBeDisabled();
        fireEvent.click(screen.getByRole('button', { name: 'Review current choices' }));
        expect(view.requestDiscard).toHaveBeenCalledTimes(1);
        expect(screen.queryByDisplayValue('Keep these unsaved details.')).not.toBeInTheDocument();
        expect(screen.queryByText('Entrance')).not.toBeInTheDocument();
    });

    it('hides details after refusal and uses a fresh same-account preview to enable exact retry', async () => {
        vi.mocked(axios.post).mockRejectedValueOnce({ isAxiosError: true, response: { status: 403 } });
        const view = setup();
        fillAndSubmit();
        await waitFor(() => expect(screen.getByRole('button', { name: 'Retry same submission' })).toBeDisabled());
        const original = vi.mocked(axios.post).mock.calls.at(-1)![1] as RecordingWindowCommand;
        expect(screen.queryByText('Entrance')).not.toBeInTheDocument();
        vi.mocked(axios.get).mockRejectedValueOnce({ isAxiosError: true, response: { status: 404 } });
        fireEvent.click(screen.getByRole('button', { name: 'Check save result' }));
        await waitFor(() => expect(screen.getByRole('button', { name: 'Check save result' })).not.toBeDisabled());
        expect(view.onSaved).not.toHaveBeenCalled();
        fireEvent.click(screen.getByRole('button', { name: 'Refresh access and choices' }));
        expect(view.onRefresh).toHaveBeenCalledTimes(1);
        view.rerender(<RecordingWindowEditor {...view.props} preview={preview()} />);
        vi.mocked(axios.post).mockResolvedValueOnce({ status: 200, data: receipt(original) });
        fireEvent.click(screen.getByRole('button', { name: 'Retry same submission' }));
        await waitFor(() => expect(view.onSaved).toHaveBeenCalledTimes(1));
        expect(vi.mocked(axios.post).mock.calls.at(-1)![1]).toBe(original);
    });

    it.each(['malformed', 'null', 'omitted'] as const)('retains an uncertain command through a %s same-record preview refresh', async (kind) => {
        vi.mocked(axios.post).mockRejectedValueOnce(new Error('lost response'));
        const view = setup();
        fillAndSubmit();
        await waitFor(() => expect(screen.getByRole('button', { name: 'Check save result' })).not.toBeDisabled());
        const original = vi.mocked(axios.post).mock.calls.at(-1)![1] as RecordingWindowCommand;
        const malformed = preview(); malformed.policy.rule!.default_end_at = 'broken';
        view.rerender(<RecordingWindowEditor {...view.props}
            preview={kind === 'malformed' ? malformed : kind === 'null' ? null : undefined} />);
        expect(screen.queryByText('Entrance')).not.toBeInTheDocument();
        expect(screen.queryByDisplayValue(original.reason)).not.toBeInTheDocument();
        expect(screen.getByRole('button', { name: 'Retry same submission' })).toBeDisabled();
        expect(view.onDraftStateChange).toHaveBeenLastCalledWith({ dirty: true, busy: false, uncertain: true });
        vi.mocked(axios.get).mockResolvedValueOnce({ status: 200, data: receipt(original) });
        fireEvent.click(screen.getByRole('button', { name: 'Check save result' }));
        await waitFor(() => expect(view.onSaved).toHaveBeenCalledTimes(1));
        expect(axios.get).toHaveBeenLastCalledWith(
            `/incidents/9/evidence-window-commands/${original.idempotency_key}?expected_actor_id=7`, expect.any(Object));
        expect(axios.post).toHaveBeenCalledTimes(1);
    });

    it('rejects malformed confirmation dates and mismatched policy or intervals', () => {
        const command: RecordingWindowCommand = {
            expected_actor_id: 7, idempotency_key: '11111111-1111-4111-8111-111111111111',
            expected_incident_evidence_version: 0, expected_policy_revision: 2,
            requested_start_at: '2026-10-20T10:00:00Z', requested_end_at: '2026-10-20T10:03:00Z',
            approved_sources: [], reason: 'A period without camera sources.',
        };
        const valid = receipt(command);
        expect(confirmsRecordingWindow(valid, command, 9, 'incident-camera')).toBe(true);
        for (const invalid of [
            { ...valid, command: { ...valid.command, confirmed_at: '2026-02-30T10:05:00.123456Z' } },
            { ...valid, window: { ...valid.window, rule_revision: 3 } },
            { ...valid, window: { ...valid.window, end_at: '2026-10-20T10:04:00Z' } },
        ]) expect(confirmsRecordingWindow(invalid, command, 9, 'incident-camera')).toBe(false);
    });
});
