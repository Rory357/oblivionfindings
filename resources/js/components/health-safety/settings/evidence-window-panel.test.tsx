import { act, cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react';
import axios from 'axios';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { EvidenceWindowPanel } from './evidence-window-panel';
import {
    blankRule, confirmsEvidenceWindowReceipt, EVIDENCE_POLICY_KEY, policyFromDraft, ruleDraft,
    type EvidenceWindowCommand, type EvidenceWindowOptions, type EvidenceWindowSnapshot,
} from './evidence-window-policy';

vi.mock('axios', () => ({ default: { put: vi.fn(), get: vi.fn(), isAxiosError: vi.fn(() => false) } }));
vi.mock('@inertiajs/react', () => ({ router: { get: vi.fn() } }));
vi.mock('@/hooks/use-safety-workspace-drafts', () => ({ useSafetyDraftState: vi.fn() }));
const options: EvidenceWindowOptions = {
    triggers: [{ value: 'fall', label: 'Fall' }],
    source_types: [{ value: 'camera', label: 'Cameras' }, { value: 'response', label: 'Response history' }],
    sites: [{ id: 4, name: 'Garden Site', is_active: true }, { id: 5, name: 'Closed Site', is_active: false }],
};
const rule = { identifier: 'fall-rule', trigger: 'fall', site_id: null,
    before_seconds: 60, after_seconds: 120, max_before_seconds: 300, max_after_seconds: 300, source_types: ['camera'] };
function snapshot(): EvidenceWindowSnapshot {
    return { setting_key: EVIDENCE_POLICY_KEY, schema_version: 1, configuration_state: 'configured',
        configuration_valid: true, policy_revision: 2, scope_complete: true,
        policy_snapshot: { schema_version: 1, enabled: true, rules: [{ ...rule, source_types: ['camera'] }] } };
}
const history = { data: [], current_page: 1, last_page: 1, total: 0 };
function receipt(command: EvidenceWindowCommand, actor = 7) {
    return { evidence_window_viewer_id: actor, receipt: {
        accepted: true, setting_key: EVIDENCE_POLICY_KEY, schema_version: 1,
        idempotency_key: command.idempotency_key, policy_revision: command.expected_policy_revision + 1,
        setting_change_id: 12, action: 'changed', policy_digest: 'a'.repeat(64),
        recorded_at: '2026-10-20T01:00:00Z', policy_snapshot: command.policy,
    } };
}
function props() {
    return { policy: snapshot(), history, options, actorId: 7, canManage: true, discard: (run: () => void) => run() };
}
function openEditor() {
    fireEvent.click(screen.getByRole('button', { name: 'Review evidence windows' }));
    fireEvent.change(screen.getByRole('textbox', { name: 'Reason for this decision' }), { target: { value: 'Reviewed for our incident response.' } });
}
beforeEach(() => vi.resetAllMocks());
afterEach(cleanup);

describe('Evidence window policy rules', () => {
    it('requires explicit timings and rejects duplicate Site/type combinations and inactive Sites', () => {
        expect(() => policyFromDraft('', [], options)).toThrow(/Choose/);
        const draft = blankRule();
        draft.trigger = 'fall';
        draft.source_types = ['camera'];
        expect(() => policyFromDraft('enabled', [draft], options)).toThrow(/duration/);
        expect(() => policyFromDraft('enabled', [ruleDraft(rule), ruleDraft({ ...rule, identifier: 'second' })], options)).toThrow(/already has a rule/);
        expect(() => policyFromDraft('enabled', [ruleDraft({ ...rule, site_id: 5 })], options)).toThrow(/active Site/);
    });
    it('retains zero where explicit and allows a distinct Site override', () => {
        const result = policyFromDraft('enabled', [ruleDraft({ ...rule, before_seconds: 0 }),
            ruleDraft({ ...rule, identifier: 'site-rule', site_id: 4 })], options);
        expect(result.rules[0].before_seconds).toBe(0);
        expect(result.rules[1].site_id).toBe(4);
        expect(policyFromDraft('disabled', [ruleDraft(rule)], options).rules).toEqual([]);
    });
    it('requires the exact actor, command, revision and accepted policy before confirming a save', () => {
        const command: EvidenceWindowCommand = { expected_actor_id: 7, expected_policy_revision: 2,
            idempotency_key: '12345678-1234-4234-8234-123456789abc', reason: 'Reviewed this setting.',
            policy: { schema_version: 1, enabled: true, rules: [rule] } };
        expect(confirmsEvidenceWindowReceipt(receipt(command), command, 7)).toBe(true);
        expect(confirmsEvidenceWindowReceipt(receipt(command, 8), command, 7)).toBe(false);
        const changed = receipt(command);
        changed.receipt.policy_revision = 4;
        expect(confirmsEvidenceWindowReceipt(changed, command, 7)).toBe(false);
        const wrongPolicy = receipt(command);
        wrongPolicy.receipt.policy_snapshot = { schema_version: 1, enabled: false, rules: [] };
        expect(confirmsEvidenceWindowReceipt(wrongPolicy, command, 7)).toBe(false);
    });
});

describe('Evidence window editor', () => {
    it('keeps an interrupted command unchanged for retry and confirms the original result', async () => {
        vi.mocked(axios.put).mockRejectedValueOnce(new Error('Connection interrupted'));
        render(<EvidenceWindowPanel {...props()} />);
        openEditor();
        fireEvent.click(screen.getByRole('button', { name: 'Save evidence policy' }));
        await waitFor(() => expect(screen.getByRole('button', { name: 'Retry same submission' })).toBeTruthy());
        const submitted = vi.mocked(axios.put).mock.calls[0][1] as EvidenceWindowCommand;
        expect(screen.getByRole('textbox', { name: 'Reason for this decision' })).toBeDisabled();
        vi.mocked(axios.put).mockResolvedValueOnce({ data: receipt(submitted) });
        vi.mocked(axios.get).mockResolvedValueOnce({ data: { policy: { ...snapshot(), policy_revision: 3 }, history, options, can_manage: true, evidence_window_viewer_id: 7 } });
        fireEvent.click(screen.getByRole('button', { name: 'Retry same submission' }));
        await waitFor(() => expect(screen.getByText('Evidence-window policy saved.')).toBeTruthy());
        expect(vi.mocked(axios.put).mock.calls[1][1]).toEqual(submitted);
    });
    it('recovers an unknown save using its original key and actor', async () => {
        vi.mocked(axios.put).mockRejectedValueOnce(new Error('Interrupted'));
        render(<EvidenceWindowPanel {...props()} />);
        openEditor();
        fireEvent.click(screen.getByRole('button', { name: 'Save evidence policy' }));
        await screen.findByRole('button', { name: 'Check save result' });
        await waitFor(() => expect(screen.getByRole('button', { name: 'Check save result' })).toBeEnabled());
        const command = vi.mocked(axios.put).mock.calls[0][1] as EvidenceWindowCommand;
        vi.mocked(axios.get).mockResolvedValueOnce({ data: receipt(command) }).mockRejectedValueOnce(new Error('Refresh unavailable'));
        fireEvent.click(screen.getByRole('button', { name: 'Check save result' }));
        await waitFor(() => expect(axios.get).toHaveBeenCalledWith(
            `/health-safety/settings/evidence-windows/requests/${command.idempotency_key}`,
            expect.objectContaining({ params: { expected_actor_id: 7 } }),
        ));
        await screen.findByText(/policy saved.*could not be refreshed/i);
        expect(axios.put).toHaveBeenCalledTimes(1);
    });
    it('does not accept a successful response carrying a different actor', async () => {
        vi.mocked(axios.put).mockImplementationOnce(async (_url, command) => ({ data: receipt(command as EvidenceWindowCommand, 8) }));
        render(<EvidenceWindowPanel {...props()} />);
        openEditor();
        fireEvent.click(screen.getByRole('button', { name: 'Save evidence policy' }));
        await screen.findByText(/This save is not confirmed/);
        expect(screen.queryByText('Evidence-window policy saved.')).toBeNull();
    });
    it('prevents a late response from restoring a previous account draft', async () => {
        let resolve!: (value: unknown) => void;
        vi.mocked(axios.put).mockImplementationOnce(() => new Promise((done) => { resolve = done; }));
        const view = render(<EvidenceWindowPanel {...props()} />);
        openEditor();
        fireEvent.click(screen.getByRole('button', { name: 'Save evidence policy' }));
        const command = vi.mocked(axios.put).mock.calls[0][1] as EvidenceWindowCommand;
        view.rerender(<EvidenceWindowPanel {...props()} actorId={8} />);
        await act(async () => resolve({ data: receipt(command) }));
        expect(screen.queryByText('Evidence-window policy saved.')).toBeNull();
        expect(screen.queryByRole('textbox', { name: 'Reason for this decision' })).toBeNull();
    });
    it('conceals the open editor and disables saves after scoped access is lost', () => {
        const original = props();
        const view = render(<EvidenceWindowPanel {...original} />);
        openEditor();
        view.rerender(<EvidenceWindowPanel {...original} canManage={false} policy={{ ...original.policy, scope_complete: false, policy_snapshot: null }} />);
        expect(screen.queryByRole('textbox', { name: 'Reason for this decision' })).toBeNull();
        expect(screen.getByRole('button', { name: 'Save evidence policy' })).toBeDisabled();
        expect(axios.put).not.toHaveBeenCalled();
    });
    it.each([403, 404])('conceals cached rules and history immediately after a refresh refusal (%s)', async (status) => {
        vi.mocked(axios.isAxiosError).mockReturnValue(true);
        vi.mocked(axios.get).mockRejectedValueOnce({ response: { status } });
        render(<EvidenceWindowPanel {...props()} />);
        expect(screen.getByLabelText('Current evidence rules')).toBeTruthy();
        fireEvent.click(screen.getByRole('button', { name: 'Refresh settings' }));
        await screen.findByRole('button', { name: 'Check current access' });
        expect(screen.queryByLabelText('Current evidence rules')).toBeNull();
        expect(screen.queryByText('Evidence-window policy history')).toBeNull();
        expect(screen.queryByRole('button', { name: 'Review evidence windows' })).toBeNull();
    });
    it('hides refused save content and requires fresh access before retrying the retained command', async () => {
        vi.mocked(axios.isAxiosError).mockReturnValue(true);
        vi.mocked(axios.put).mockRejectedValueOnce({ response: { status: 403 } });
        render(<EvidenceWindowPanel {...props()} />);
        openEditor();
        fireEvent.click(screen.getByRole('button', { name: 'Save evidence policy' }));
        await screen.findByRole('button', { name: 'Check current access' });
        const command = vi.mocked(axios.put).mock.calls[0][1] as EvidenceWindowCommand;
        expect(screen.queryByRole('textbox', { name: 'Reason for this decision' })).toBeNull();
        expect(screen.queryByText('Evidence-window policy history')).toBeNull();
        expect(screen.getByRole('button', { name: 'Retry same submission' })).toBeDisabled();
        vi.mocked(axios.get).mockResolvedValueOnce({ data: {
            policy: snapshot(), history, options, can_manage: true, evidence_window_viewer_id: 7,
        } });
        fireEvent.click(screen.getByRole('button', { name: 'Check current access' }));
        await waitFor(() => expect(screen.getByRole('button', { name: 'Retry same submission' })).toBeEnabled());
        vi.mocked(axios.put).mockResolvedValueOnce({ data: receipt(command) });
        fireEvent.click(screen.getByRole('button', { name: 'Retry same submission' }));
        await waitFor(() => expect(axios.put).toHaveBeenCalledTimes(2));
        expect(vi.mocked(axios.put).mock.calls[1][1]).toEqual(command);
    });
    it('also hides cached content when recovery refuses the original request', async () => {
        vi.mocked(axios.put).mockRejectedValueOnce(new Error('Interrupted'));
        render(<EvidenceWindowPanel {...props()} />);
        openEditor();
        fireEvent.click(screen.getByRole('button', { name: 'Save evidence policy' }));
        await waitFor(() => expect(screen.getByRole('button', { name: 'Check save result' })).toBeEnabled());
        vi.mocked(axios.isAxiosError).mockReturnValue(true);
        vi.mocked(axios.get).mockRejectedValueOnce({ response: { status: 404 } });
        fireEvent.click(screen.getByRole('button', { name: 'Check save result' }));
        await screen.findByRole('button', { name: 'Check current access' });
        expect(screen.queryByRole('textbox', { name: 'Reason for this decision' })).toBeNull();
        expect(screen.getByRole('button', { name: 'Retry same submission' })).toBeDisabled();
        expect(axios.put).toHaveBeenCalledTimes(1);
    });
});
