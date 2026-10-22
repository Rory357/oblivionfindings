import {
    act,
    cleanup,
    fireEvent,
    render,
    screen,
    waitFor,
    within,
} from '@testing-library/react';
import axios from 'axios';
import { webcrypto } from 'node:crypto';
import { readFileSync } from 'node:fs';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { scriptIndex } from './_contracts';
import { ScriptReviewDialog } from './_dialogs';

vi.mock('axios', () => ({
    default: {
        get: vi.fn(),
        post: vi.fn(),
        isAxiosError: (value: { isAxiosError?: boolean }) =>
            value?.isAxiosError === true,
    },
}));
const corpus = JSON.parse(
    readFileSync(
        process.env.ENDPOINT_SCRIPT_HTTP_CORPUS ??
            'resources/js/pages/security-devices/scripts/__fixtures__/catalogue-http.json',
        'utf8',
    ),
);
const index = scriptIndex(corpus.index_pending),
    row = index.scripts[0];
const key = '11111111-1111-4111-8111-111111111111';
const reason = 'Reviewed original bytes and declared effects.';
const httpError = (status: number) => ({
    isAxiosError: true,
    response: { status },
});
function view(overrides = {}) {
    return (
        <ScriptReviewDialog
            actorId={index.actor_id}
            item={row}
            open
            onClose={vi.fn()}
            onChanged={vi.fn()}
            onPendingChange={vi.fn()}
            {...overrides}
        />
    );
}
function decision() {
    fireEvent.click(screen.getByRole('button', { name: 'Next section' }));
    fireEvent.click(screen.getByRole('button', { name: 'Next section' }));
    fireEvent.change(screen.getByLabelText('Reason for this decision'), {
        target: { value: reason },
    });
}
async function verify() {
    vi.mocked(axios.get)
        .mockResolvedValueOnce({
            data: { actor_id: index.actor_id, script: row },
        })
        .mockResolvedValueOnce({ data: corpus.material });
    fireEvent.click(
        screen.getByRole('button', { name: 'Read and verify script' }),
    );
    await screen.findByLabelText('Verified script content');
}
/** Click a decision, then confirm it in the shared confirmation dialog. */
async function confirmDecision(button: string, confirm: string) {
    fireEvent.click(screen.getByRole('button', { name: button }));
    const dialog = await screen.findByRole('alertdialog');
    fireEvent.click(within(dialog).getByRole('button', { name: confirm }));
}
const approve = () =>
    confirmDecision('Approve this version', 'Approve version');
beforeEach(() => {
    vi.clearAllMocks();
    vi.stubGlobal('crypto', {
        subtle: webcrypto.subtle,
        randomUUID: () => key,
    });
});
afterEach(() => {
    cleanup();
    vi.unstubAllGlobals();
});

describe('script review and uncertain-outcome recovery', () => {
    it('requires verified original bytes before approval and shows every execution limit in plain words', async () => {
        render(view());
        decision();
        expect(
            screen.getByRole('button', { name: 'Approve this version' }),
        ).toBeDisabled();
        fireEvent.click(screen.getByRole('button', { name: 'Back to script' }));
        await verify();
        expect(
            screen.getByLabelText('Verified script content'),
        ).toHaveTextContent(corpus.material.artifact.trim());
        // Hashes live only in the collapsed technical details.
        expect(screen.getByText('Technical details')).toBeInTheDocument();
        expect(screen.getByText('Script file (SHA-256)')).not.toBeVisible();
        fireEvent.click(screen.getByRole('button', { name: 'Next section' }));
        for (const text of [
            'Time limit',
            'If it runs too long',
            'Output kept',
            'Output kept for',
            'Network use',
            'Success exit codes',
            'Independent outcome check',
            'Programs it starts',
            'Network destinations',
            'Output sensitivity',
            'Recovery plan',
            'Restart behaviour',
            'Background work left running',
            'Downloads or runs other code',
        ]) {
            expect(screen.getByText(text)).toBeVisible();
        }
        expect(screen.getByText('Operational information')).toBeVisible();
        expect(screen.queryByText('operational')).not.toBeInTheDocument();
        fireEvent.click(screen.getByRole('button', { name: 'Next section' }));
        expect(
            screen.getByRole('button', { name: 'Approve this version' }),
        ).toBeEnabled();
    });
    it('asks before approving, states the effect, and sends nothing on cancel', async () => {
        render(view());
        await verify();
        decision();
        fireEvent.click(
            screen.getByRole('button', { name: 'Approve this version' }),
        );
        const dialog = await screen.findByRole('alertdialog');
        expect(
            within(dialog).getByText('Approve this script version?'),
        ).toBeVisible();
        expect(dialog).toHaveTextContent(/scripts can’t run yet/);
        const confirm = within(dialog).getByRole('button', {
            name: 'Approve version',
        });
        // Affirmative decisions stay the default primary tone.
        expect(confirm).toHaveClass('btn-soft-primary');
        fireEvent.click(within(dialog).getByRole('button', { name: 'Cancel' }));
        await waitFor(() =>
            expect(screen.queryByRole('alertdialog')).not.toBeInTheDocument(),
        );
        expect(axios.post).not.toHaveBeenCalled();
    });
    it('asks before withdrawing with the destructive tone', async () => {
        render(view());
        decision();
        fireEvent.click(
            screen.getByRole('button', { name: 'Withdraw this version' }),
        );
        const dialog = await screen.findByRole('alertdialog');
        expect(dialog).toHaveTextContent(/can’t be undone/);
        const confirm = within(dialog).getByRole('button', {
            name: 'Withdraw version',
        });
        expect(confirm).toHaveClass('bg-destructive');
        expect(confirm).not.toHaveClass('btn-soft-primary');
        vi.mocked(axios.post).mockResolvedValueOnce({
            data: {
                ...corpus.withdrawn,
                receipt: { ...corpus.withdrawn.receipt, request_key: key },
            },
        });
        fireEvent.click(confirm);
        await waitFor(() => expect(axios.post).toHaveBeenCalledTimes(1));
        expect(vi.mocked(axios.post).mock.calls[0][0]).toMatch(/\/withdraw$/);
    });
    it('keeps a reason on one line instead of silently disabling the decision', async () => {
        render(view());
        await verify();
        decision();
        const field = screen.getByLabelText('Reason for this decision');
        // Enter adds no line break, and pasted line breaks become spaces.
        expect(fireEvent.keyDown(field, { key: 'Enter' })).toBe(false);
        fireEvent.change(field, {
            target: { value: 'Checked the bytes\nand declared effects' },
        });
        expect(field).toHaveValue('Checked the bytes and declared effects');
        expect(
            screen.getByRole('button', { name: 'Approve this version' }),
        ).toBeEnabled();
        fireEvent.change(field, {
            target: {
                value:
                    'Checked' +
                    String.fromCharCode(0x200b) +
                    ' the declared effects',
            },
        });
        expect(screen.getByText(/Remove hidden or special characters/)).toBe(
            document.getElementById('script-decision-reason-hint'),
        );
        expect(
            screen.getByRole('button', { name: 'Approve this version' }),
        ).toBeDisabled();
    });
    it('retains the exact uncertain request across close/reopen and recovers the saved receipt', async () => {
        const onChanged = vi.fn(),
            onPendingChange = vi.fn();
        const props = { onChanged, onPendingChange };
        const rendered = render(view(props));
        await verify();
        decision();
        vi.mocked(axios.post).mockRejectedValueOnce(Error('lost response'));
        await approve();
        await screen.findByText(/outcome could not be confirmed/i);
        const body = vi.mocked(axios.post).mock.calls[0][1];
        expect(body).toEqual({
            expected_actor_id: index.actor_id,
            request_key: key,
            expected_version: row.status_version,
            reason,
            reviewed_content_hash: row.content_hash,
        });
        expect(
            screen.getByLabelText('Reason for this decision'),
        ).toBeDisabled();
        rendered.rerender(view({ ...props, open: false }));
        rendered.rerender(view(props));
        vi.mocked(axios.post).mockRejectedValueOnce(Error('still offline'));
        fireEvent.click(
            screen.getByRole('button', { name: 'Retry same request' }),
        );
        await waitFor(() => expect(axios.post).toHaveBeenCalledTimes(2));
        expect(vi.mocked(axios.post).mock.calls[1][1]).toBe(body);
        await waitFor(() =>
            expect(
                screen.getByRole('button', { name: 'Check saved outcome' }),
            ).toBeEnabled(),
        );
        vi.mocked(axios.get).mockResolvedValueOnce({
            data: {
                ...corpus.withdrawn,
                receipt: { ...corpus.published.receipt, request_key: key },
            },
        });
        fireEvent.click(
            screen.getByRole('button', { name: 'Check saved outcome' }),
        );
        await screen.findByText(
            'Your change was recorded. Current catalogue status: Withdrawn.',
        );
        expect(onChanged).toHaveBeenLastCalledWith(corpus.withdrawn.script);
        expect(onPendingChange).toHaveBeenLastCalledWith(false);
        expect(
            screen.queryByRole('button', { name: 'Retry same request' }),
        ).not.toBeInTheDocument();
        expect(axios.get).toHaveBeenLastCalledWith(
            expect.stringContaining(
                '/requests/' + key + '?expected_actor_id=' + index.actor_id,
            ),
            expect.any(Object),
        );
    });
    it('rejects a mismatched saved receipt and keeps the original recovery request', async () => {
        render(view());
        await verify();
        decision();
        vi.mocked(axios.post).mockResolvedValueOnce({ data: corpus.published });
        await approve();
        await screen.findByText(/outcome could not be confirmed/i);
        expect(
            screen.getByRole('button', { name: 'Check saved outcome' }),
        ).toBeEnabled();
        expect(
            screen.queryByText(/Your change was recorded/),
        ).not.toBeInTheDocument();
    });
    it('reloads the current version after a stale-version conflict and never reports lost access', async () => {
        const onChanged = vi.fn();
        render(view({ onChanged }));
        await verify();
        decision();
        const newer = { ...row, status_version: row.status_version + 1 };
        vi.mocked(axios.post).mockRejectedValueOnce(httpError(409));
        vi.mocked(axios.get).mockResolvedValueOnce({
            data: { actor_id: index.actor_id, script: newer },
        });
        await approve();
        await screen.findByText(/This script changed since you opened it/);
        expect(vi.mocked(axios.get).mock.calls.at(-1)?.[0]).toBe(
            '/security-devices/scripts/' +
                row.uuid +
                '?expected_actor_id=' +
                index.actor_id,
        );
        expect(onChanged).toHaveBeenLastCalledWith(newer);
        expect(screen.queryByText(/no longer available/)).toBeNull();
        expect(
            screen.queryByRole('button', { name: 'Retry same request' }),
        ).not.toBeInTheDocument();
        // The kept reason and verified code allow a fresh decision against
        // the current version, under a new request key.
        expect(screen.getByLabelText('Reason for this decision')).toHaveValue(
            reason,
        );
        vi.stubGlobal('crypto', {
            subtle: webcrypto.subtle,
            randomUUID: () => '22222222-2222-4222-8222-222222222222',
        });
        vi.mocked(axios.post).mockRejectedValueOnce(Error('offline'));
        await approve();
        await waitFor(() => expect(axios.post).toHaveBeenCalledTimes(2));
        expect(vi.mocked(axios.post).mock.calls[1][1]).toMatchObject({
            request_key: '22222222-2222-4222-8222-222222222222',
            expected_version: newer.status_version,
        });
    });
    it('reports a change that never landed as not saved when recovery finds no receipt', async () => {
        render(view());
        await verify();
        decision();
        vi.mocked(axios.post).mockRejectedValueOnce(Error('timed out'));
        await approve();
        await screen.findByText(/outcome could not be confirmed/i);
        vi.mocked(axios.get)
            .mockRejectedValueOnce(httpError(404))
            .mockResolvedValueOnce({
                data: { actor_id: index.actor_id, script: row },
            });
        fireEvent.click(
            screen.getByRole('button', { name: 'Check saved outcome' }),
        );
        await screen.findByText(/Your change was not saved/);
        expect(screen.queryByText(/no longer available/)).toBeNull();
        expect(
            screen.queryByRole('button', { name: 'Check saved outcome' }),
        ).not.toBeInTheDocument();
        // A fresh decision is possible again (approval re-reads the code).
        expect(screen.getByLabelText('Reason for this decision')).toBeEnabled();
        expect(
            screen.getByRole('button', { name: 'Withdraw this version' }),
        ).toBeEnabled();
    });
    it('asks for identity again when the 15-minute check expires instead of hiding the record', async () => {
        render(view());
        await verify();
        decision();
        vi.mocked(axios.post).mockRejectedValueOnce(httpError(403));
        vi.mocked(axios.get).mockResolvedValueOnce({
            data: {
                actor_id: index.actor_id,
                script: {
                    ...row,
                    can_review: false,
                    can_withdraw: false,
                    can_read_material: false,
                },
            },
        });
        await approve();
        await screen.findByText(/Your identity check has expired/);
        expect(screen.queryByText(/no longer available/)).toBeNull();
        expect(
            screen.getByRole('link', {
                name: 'Confirm identity for review actions',
            }),
        ).toHaveAttribute(
            'href',
            '/security-devices/scripts/confirm-identity?expected_actor_id=' +
                index.actor_id,
        );
        expect(
            screen.queryByRole('button', { name: 'Retry same request' }),
        ).not.toBeInTheDocument();
    });
    it('treats a busy catalogue as retryable and resends the same request', async () => {
        render(view());
        await verify();
        decision();
        vi.mocked(axios.post).mockRejectedValueOnce(httpError(503));
        await approve();
        await screen.findByText(
            'Another change to this script is being saved. Try again in a moment.',
        );
        expect(screen.queryByText(/no longer available/)).toBeNull();
        const body = vi.mocked(axios.post).mock.calls[0][1];
        vi.mocked(axios.post).mockResolvedValueOnce({
            data: {
                ...corpus.published,
                receipt: { ...corpus.published.receipt, request_key: key },
            },
        });
        fireEvent.click(
            screen.getByRole('button', { name: 'Retry same request' }),
        );
        await screen.findByText(
            'Your change was recorded. Current catalogue status: Approved.',
        );
        expect(vi.mocked(axios.post).mock.calls[1][1]).toBe(body);
    });
    it('removes private details after a genuine access denial', async () => {
        render(view());
        await verify();
        decision();
        vi.mocked(axios.post).mockRejectedValueOnce(httpError(403));
        vi.mocked(axios.get).mockRejectedValueOnce(httpError(403));
        await approve();
        await screen.findByText('This script is no longer available to you');
        expect(
            screen.queryByLabelText('Reason for this decision'),
        ).not.toBeInTheDocument();
        expect(
            screen.queryByLabelText('Verified script content'),
        ).not.toBeInTheDocument();
    });
    it('removes private details after an access denial during receipt recovery', async () => {
        render(view());
        await verify();
        decision();
        vi.mocked(axios.post).mockRejectedValueOnce(Error('lost response'));
        await approve();
        await screen.findByText(/outcome could not be confirmed/i);
        vi.mocked(axios.get).mockRejectedValueOnce(httpError(403));
        fireEvent.click(
            screen.getByRole('button', { name: 'Check saved outcome' }),
        );
        await screen.findByText(/no longer available/);
        expect(
            screen.queryByLabelText('Reason for this decision'),
        ).not.toBeInTheDocument();
        expect(
            screen.getByRole('button', { name: 'Retry same request' }),
        ).toBeDisabled();
        vi.mocked(axios.get).mockRejectedValueOnce(
            Error('network unavailable'),
        );
        fireEvent.click(
            screen.getByRole('button', { name: 'Check saved outcome' }),
        );
        await screen.findByText(/No matching saved outcome is available yet/);
        expect(
            screen.queryByLabelText('Reason for this decision'),
        ).not.toBeInTheDocument();
        expect(
            screen.getByRole('button', { name: 'Retry same request' }),
        ).toBeDisabled();
    });
    it('discards a late material response after the workspace is removed', async () => {
        let resolve: (value: unknown) => void = () => {};
        vi.mocked(axios.get)
            .mockResolvedValueOnce({
                data: { actor_id: index.actor_id, script: row },
            })
            .mockImplementationOnce(
                () =>
                    new Promise((done) => {
                        resolve = done;
                    }),
            );
        const rendered = render(view());
        fireEvent.click(
            screen.getByRole('button', { name: 'Read and verify script' }),
        );
        await waitFor(() => expect(axios.get).toHaveBeenCalledTimes(2));
        const options = vi.mocked(axios.get).mock.calls[1][1];
        rendered.unmount();
        expect(options?.signal?.aborted).toBe(true);
        await act(async () => resolve({ data: corpus.material }));
        expect(
            screen.queryByLabelText('Verified script content'),
        ).not.toBeInTheDocument();
    });
    it('refuses tampered code without enabling approval', async () => {
        render(view());
        vi.mocked(axios.get)
            .mockResolvedValueOnce({
                data: { actor_id: index.actor_id, script: row },
            })
            .mockResolvedValueOnce({
                data: { ...corpus.material, artifact: 'Changed' },
            });
        fireEvent.click(
            screen.getByRole('button', { name: 'Read and verify script' }),
        );
        await screen.findByText(/could not be read and verified/);
        expect(
            screen.queryByLabelText('Verified script content'),
        ).not.toBeInTheDocument();
        decision();
        expect(
            screen.getByRole('button', { name: 'Approve this version' }),
        ).toBeDisabled();
    });
});
