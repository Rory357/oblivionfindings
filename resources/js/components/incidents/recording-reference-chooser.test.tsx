import { recordingDetailFixture, recordingPageFixture, recordingUuid } from '@/pages/security-devices/cctv/recording-test-fixtures';
import { act, cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react';
import axios from 'axios';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { attachmentBasis, confirmsRecordingAttachment, type RecordingAttachmentCommand } from './recording-attachment-command';
import type { RecordingReferenceEvidenceData } from './recording-reference-evidence';
import { RecordingReferenceChooser } from './recording-reference-chooser';
import type { RecordingPreview } from './recording-window-command';
import type { RecordingRequest } from '@/pages/security-devices/cctv/_recording-contract';

const transport = vi.hoisted(() => ({ json: vi.fn(), detail: vi.fn() }));
vi.mock('@/pages/security-devices/cctv/live-transport', () => ({
    cctvRecordingJson: transport.json, cctvRecordingDetailJson: transport.detail,
}));
vi.mock('axios', () => ({ default: { get: vi.fn(), post: vi.fn() } }));
beforeEach(() => { vi.clearAllMocks(); transport.json.mockReset(); transport.detail.mockReset(); vi.mocked(axios.get).mockReset(); vi.mocked(axios.post).mockReset(); });
afterEach(cleanup);
function fixture() {
    const reference = recordingDetailFixture();
    const preview: RecordingPreview = {
        schema_version: 1, actor_id: 7, incident_id: 9, incident_evidence_version: 2, active_window_revision: 1,
        policy: { state: 'unavailable', revision: null, rule: null },
        permissions: { can_attach: true, can_record: false, can_expand: false },
        sources: { limit: 64, complete: true, items: [{ camera: { id: 42, name: 'Main entrance' },
            recorder: { id: 43, name: 'Reception recorder' }, site_id: 9,
            in_active_window: true, can_record_window: false, references_href: '/security-devices/devices/42/recording-references' }] },
    };
    const evidence: RecordingReferenceEvidenceData = { schema_version: 1, incident_id: 9, incident_evidence_version: 2,
        window: { revision: 1, start_at: reference.reference.requested_start_at, end_at: reference.reference.requested_end_at,
            rule_identifier: 'fall-review', rule_revision: 1, context_state: 'current' },
        references: { items: [], page: 1, per_page: 20, total: 0, has_more: false, next_page: null, previous_page: null } };
    return { reference, preview, evidence };
}
function pageWithReference() {
    const page = recordingPageFixture(), d = recordingDetailFixture(), r = d.reference;
    page.references.items = [{ reference_uuid: r.reference_uuid, requested_start_at: r.requested_start_at,
        requested_end_at: r.requested_end_at, created_at: r.created_at, source_revision: r.source_revision,
        recorder: d.recorder, state: 'reference_only', preserved: false, reader_href: r.reader_href, current_source: d.current_source }];
    return page;
}
function setup(data = fixture()) {
    const props = { actorId: 7, incidentId: 9, preview: data.preview, evidence: data.evidence,
        onDraftStateChange: vi.fn(), onSaved: vi.fn(), onRefresh: vi.fn(), requestDiscard: vi.fn() };
    return { ...render(<RecordingReferenceChooser {...props} />), props };
}
async function choose() {
    fireEvent.click(screen.getByRole('button', { name: 'Main entrance · Reception recorder' }));
    await screen.findByRole('heading', { name: 'Existing references for this period' });
}
async function selectReference() {
    fireEvent.click(screen.getByRole('button', { name: /Review reference created/ }));
    await screen.findByRole('heading', { name: 'Selected reference' });
}
function submitLink() {
    fireEvent.change(screen.getByLabelText('Why this reference belongs with the incident'), { target: { value: 'Review this entrance incident reference.' } });
    fireEvent.click(screen.getByRole('button', { name: 'Link reference' }));
}
function receipt(command: RecordingAttachmentCommand) {
    const d = fixture();
    return { schema_version: 1, actor_id: 7, incident_id: 9,
        command: { idempotency_key: command.idempotency_key, kind: 'attach_recording_reference',
            confirmed_incident_evidence_version: 3, confirmed_evidence_window_revision: 1, confirmed_at: '2026-10-08T08:01:00.123456Z' },
        attachment: { attachment_uuid: '22222222-2222-4222-8222-222222222222', kind: 'recording_reference',
            reference_uuid: command.reference_uuid, requested_start_at: d.evidence.window!.start_at,
            requested_end_at: d.evidence.window!.end_at, state: 'reference_only', preserved: false, window_coverage: 'not_verified' },
        current: { incident_evidence_version: 5, evidence_window_revision: null, current_source: { state: 'ready', source_verified_at: null } } };
}
describe('inline recording reference selection and independent recovery', () => {
    it.each(['Review this incident\nat the entrance.', 'Review this\u200b incident at the entrance.', 'Review this \ud800 incident at the entrance.'])('refuses unsupported purpose text before creating an uncertain command: %s', async (purpose) => {
        transport.json.mockResolvedValueOnce(pageWithReference()); transport.detail.mockResolvedValueOnce(recordingDetailFixture());
        const view = setup(); await choose();
        fireEvent.change(screen.getByLabelText('Purpose of the new camera reference'), { target: { value: purpose } });
        fireEvent.click(screen.getByRole('button', { name: 'Create camera reference' }));
        expect(transport.json).toHaveBeenCalledOnce();
        expect(screen.queryByRole('button', { name: 'Check reference creation' })).not.toBeInTheDocument();
        await selectReference();
        fireEvent.change(screen.getByLabelText('Why this reference belongs with the incident'), { target: { value: purpose } });
        fireEvent.click(screen.getByRole('button', { name: 'Link reference' }));
        expect(axios.post).not.toHaveBeenCalled();
        expect(screen.queryByRole('button', { name: 'Check incident link' })).not.toBeInTheDocument();
        expect(view.props.onDraftStateChange).toHaveBeenLastCalledWith({ dirty: true, busy: false, uncertain: false });
    });
    it('allows an existing reference by another creator with a changed source, without granting new-window permission', async () => {
        const data = fixture(); data.reference.reference.created_by = { id: 88, name: 'Earlier colleague' };
        data.reference.current_source = { state: 'changed', reason_code: 'source_changed', revision: 3, source_verified_at: null };
        transport.json.mockResolvedValueOnce(pageWithReference()); transport.detail.mockResolvedValueOnce(data.reference);
        vi.mocked(axios.post).mockImplementationOnce(async (_url, body) => ({ status: 201, data: receipt(body as RecordingAttachmentCommand) }));
        const view = setup(data); await choose(); await selectReference(); submitLink();
        await waitFor(() => expect(view.props.onSaved).toHaveBeenCalledOnce());
        expect(transport.detail).toHaveBeenCalledWith(recordingUuid, expect.any(AbortSignal));
        expect(axios.post).toHaveBeenCalledWith('/incidents/9/recording-attachments', expect.objectContaining({
            expected_actor_id: 7, expected_incident_evidence_version: 2, evidence_window_revision: 1, reference_uuid: recordingUuid,
        }), expect.any(Object));
    });
    it('retains independent create and link commands through two lost responses and confirms after the incident closes', async () => {
        transport.json.mockResolvedValueOnce(recordingPageFixture()).mockRejectedValueOnce(Error('lost create'));
        const view = setup(); await choose();
        fireEvent.change(screen.getByLabelText('Purpose of the new camera reference'), { target: { value: 'Review the entrance around this incident.' } });
        fireEvent.click(screen.getByRole('button', { name: 'Create camera reference' }));
        await waitFor(() => expect(screen.getByRole('button', { name: 'Check reference creation' })).toBeEnabled());
        expect(axios.post).not.toHaveBeenCalled();
        const create = transport.json.mock.calls[1][4] as RecordingRequest;
        expect(create.requested_start_at).toBe(fixture().evidence.window!.start_at);
        const detail = recordingDetailFixture(); detail.reference.purpose = create.purpose;
        transport.json.mockResolvedValueOnce(detail);
        fireEvent.click(screen.getByRole('button', { name: 'Check reference creation' }));
        await screen.findByRole('heading', { name: 'Selected reference' });
        expect(view.props.onSaved).not.toHaveBeenCalled();
        expect(transport.json.mock.calls[2][2]).toBe(`/security-devices/cctv/recording-references/requests/${create.request_key}?expected_actor_id=7`);
        vi.mocked(axios.post).mockRejectedValueOnce(Error('lost link'));
        submitLink();
        await waitFor(() => expect(screen.getByRole('button', { name: 'Check incident link' })).toBeEnabled());
        const link = vi.mocked(axios.post).mock.calls[0][1] as RecordingAttachmentCommand;
        expect(link.idempotency_key).not.toBe(create.request_key);
        const closed = fixture().preview; closed.permissions.can_attach = false;
        view.rerender(<RecordingReferenceChooser {...view.props} preview={closed} />);
        vi.mocked(axios.get).mockResolvedValueOnce({ status: 200, data: receipt(link) });
        fireEvent.click(screen.getByRole('button', { name: 'Check incident link' }));
        await waitFor(() => expect(view.props.onSaved).toHaveBeenCalledOnce());
        expect(axios.get).toHaveBeenCalledWith(`/incidents/9/recording-attachment-commands/${link.idempotency_key}?expected_actor_id=7`, expect.any(Object));
        expect(axios.post).toHaveBeenCalledOnce();
        expect(transport.json).toHaveBeenCalledTimes(3);
    });
    it('rejects detail that differs from the approved period by one second', async () => {
        transport.json.mockResolvedValueOnce(pageWithReference());
        const detail = recordingDetailFixture(); detail.reference.requested_end_at = '2026-10-08T08:15:01Z';
        transport.detail.mockResolvedValueOnce(detail);
        setup(); await choose();
        fireEvent.click(screen.getByRole('button', { name: /Review reference created/ }));
        await screen.findByText(/Current reference access could not be confirmed/);
        expect(screen.queryByRole('button', { name: 'Link reference' })).not.toBeInTheDocument();
        expect(axios.post).not.toHaveBeenCalled();
    });
    it.each(['technical limit', 'different recorder', 'withdrawn'] as const)('keeps exact existing references selectable when creation is unavailable: %s', async (reason) => {
        const page = pageWithReference();
        if (reason === 'technical limit') page.window_limits.max_seconds = 60;
        if (reason === 'different recorder') page.source!.recorder_device_id = 99;
        if (reason === 'withdrawn') page.current_source = { state: 'withdrawn', reason_code: 'source_withdrawn', revision: 2, source_verified_at: null };
        transport.json.mockResolvedValueOnce(page); setup(); await choose();
        expect(screen.queryByRole('button', { name: 'Create camera reference' })).not.toBeInTheDocument();
        expect(screen.getByRole('button', { name: /Review reference created/ })).toBeEnabled();
    });
    it('keeps server cursor navigation when a page has no exact matching period', async () => {
        const page = pageWithReference(); page.references.items[0].requested_end_at = '2026-10-08T08:14:00Z';
        page.references.next_href = '/security-devices/devices/42/recording-references?cursor=next-page';
        transport.json.mockResolvedValueOnce(page).mockResolvedValueOnce(pageWithReference());
        setup(); await choose();
        expect(screen.getByText(/No exact match on this page/)).toBeVisible();
        fireEvent.click(screen.getByRole('button', { name: 'Next page' }));
        await screen.findByRole('button', { name: /Review reference created/ });
        expect(transport.json.mock.calls[1][2]).toBe(page.references.next_href);
    });
    it('ignores a late reference detail after the current incident window changes', async () => {
        transport.json.mockResolvedValueOnce(pageWithReference());
        let finish!: (value: unknown) => void;
        transport.detail.mockImplementationOnce(() => new Promise((resolve) => { finish = resolve; }));
        const view = setup(); await choose();
        fireEvent.click(screen.getByRole('button', { name: /Review reference created/ }));
        const preview = fixture().preview; preview.incident_evidence_version = 3; preview.active_window_revision = 2;
        view.rerender(<RecordingReferenceChooser {...view.props} preview={preview} />);
        await act(async () => { finish(recordingDetailFixture()); });
        expect(screen.queryByRole('heading', { name: 'Selected reference' })).not.toBeInTheDocument();
        expect(screen.queryByRole('button', { name: 'Link reference' })).not.toBeInTheDocument();
    });
    it('aborts and clears an old account request instead of showing its late success', async () => {
        let finish!: (value: unknown) => void;
        transport.json.mockImplementationOnce(() => new Promise((resolve) => { finish = resolve; }));
        const view = setup();
        fireEvent.click(screen.getByRole('button', { name: 'Main entrance · Reception recorder' }));
        const signal = transport.json.mock.calls[0][3] as AbortSignal;
        view.rerender(<RecordingReferenceChooser {...view.props} actorId={8} />);
        await act(async () => { finish(pageWithReference()); });
        expect(signal.aborted).toBe(true);
        expect(screen.queryByRole('heading', { name: 'Existing references for this period' })).not.toBeInTheDocument();
        expect(screen.queryByText('Main entrance · Reception recorder')).not.toBeInTheDocument();
    });
    it.each(['wrong actor', 'array source state', 'access refusal'])('requires fresh incident authority and retains the original attachment after %s', async (failure) => {
        transport.json.mockResolvedValueOnce(pageWithReference()).mockResolvedValueOnce(pageWithReference());
        transport.detail.mockResolvedValueOnce(recordingDetailFixture());
        vi.mocked(axios.post).mockImplementationOnce(async (_url, body) => {
            if (failure === 'access refusal') throw { response: { status: 403 } };
            const data = receipt(body as RecordingAttachmentCommand);
            if (failure === 'wrong actor') data.actor_id = 99;
            else (data.current.current_source as unknown as Record<string, unknown>).state = ['ready'];
            return { status: 201, data };
        });
        const view = setup(); await choose(); await selectReference(); submitLink();
        await screen.findByText(/The incident link is not confirmed/);
        const original = vi.mocked(axios.post).mock.calls[0][1] as RecordingAttachmentCommand;
        expect(view.props.onSaved).not.toHaveBeenCalled();
        expect(screen.getByRole('button', { name: 'Retry same incident link' })).toBeDisabled();
        fireEvent.click(screen.getByRole('button', { name: 'Refresh camera references' }));
        await waitFor(() => expect(screen.getByRole('button', { name: 'Refresh camera references' })).toBeEnabled());
        expect(screen.getByRole('button', { name: 'Retry same incident link' })).toBeDisabled();
        view.rerender(<RecordingReferenceChooser {...view.props} preview={fixture().preview} />);
        await waitFor(() => expect(screen.getByRole('button', { name: 'Retry same incident link' })).toBeEnabled());
        vi.mocked(axios.post).mockResolvedValueOnce({ status: 200, data: receipt(original) });
        fireEvent.click(screen.getByRole('button', { name: 'Retry same incident link' }));
        await waitFor(() => expect(view.props.onSaved).toHaveBeenCalledOnce());
        expect(vi.mocked(axios.post).mock.calls[1][1]).toBe(original);
    });
});

describe('immutable incident attachment confirmation', () => {
    const request: RecordingAttachmentCommand = { expected_actor_id: 7, idempotency_key: '33333333-3333-4333-8333-333333333333',
        expected_incident_evidence_version: 2, evidence_window_revision: 1, reference_uuid: recordingUuid, purpose: 'Review the entrance incident.' };
    it('accepts an original confirmation with newer current state and no active window', () => {
        const data = fixture(), basis = attachmentBasis(data.preview, data.evidence, 7, 9)!;
        expect(confirmsRecordingAttachment(receipt(request), request, basis)).toBe(true);
    });
    it.each([
        ['actor_id', 8], ['incident_id', 8], ['command.idempotency_key', 'wrong'], ['command.kind', 'record_evidence_window'],
        ['command.confirmed_incident_evidence_version', 2], ['command.confirmed_evidence_window_revision', 2],
        ['command.confirmed_at', 'invalid'], ['attachment.reference_uuid', '44444444-4444-4444-8444-444444444444'],
        ['attachment.attachment_uuid', 'invalid'], ['attachment.requested_end_at', '2026-10-08T08:15:01Z'],
        ['attachment.preserved', true], ['attachment.window_coverage', 'verified'], ['current.incident_evidence_version', 2],
    ])('refuses a crossed or fabricated receipt field %s', (path, value) => {
        const data = fixture(), basis = attachmentBasis(data.preview, data.evidence, 7, 9)!;
        const response = receipt(request), [parent, key] = String(path).split('.');
        if (key) (response[parent as keyof typeof response] as unknown as Record<string, unknown>)[key] = value;
        else (response as unknown as Record<string, unknown>)[parent] = value;
        expect(confirmsRecordingAttachment(response, request, basis)).toBe(false);
    });
    it('rejects an array posing as the current source state', () => {
        const data = fixture(), basis = attachmentBasis(data.preview, data.evidence, 7, 9)!;
        const response = receipt(request);
        (response.current.current_source as unknown as Record<string, unknown>).state = ['ready'];
        expect(confirmsRecordingAttachment(response, request, basis)).toBe(false);
    });
});
