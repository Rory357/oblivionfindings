import {
    act,
    cleanup,
    fireEvent,
    render,
    screen,
    waitFor,
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
        target: { value: 'Reviewed original bytes and declared effects.' },
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
    it('requires verified original bytes before approval and shows every execution limit', async () => {
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
        fireEvent.click(screen.getByRole('button', { name: 'Next section' }));
        for (const text of [
            'Time limit',
            'On timeout',
            'Retained output',
            'Output retention',
            'Network use',
            'Completion exit codes',
            'Independent outcome schema',
            'Child processes',
            'Network destinations',
            'Output sensitivity',
            'Compensation',
            'Restart behaviour',
            'Detached work',
            'Unpublished dynamic code',
        ]) {
            expect(screen.getByText(text)).toBeVisible();
        }
        fireEvent.click(screen.getByRole('button', { name: 'Next section' }));
        expect(
            screen.getByRole('button', { name: 'Approve this version' }),
        ).toBeEnabled();
    });
    it('retains the exact uncertain request across close/reopen and recovers the saved receipt', async () => {
        const onChanged = vi.fn(),
            onPendingChange = vi.fn();
        const props = { onChanged, onPendingChange };
        const rendered = render(view(props));
        await verify();
        decision();
        vi.mocked(axios.post).mockRejectedValueOnce(Error('lost response'));
        fireEvent.click(
            screen.getByRole('button', { name: 'Approve this version' }),
        );
        await screen.findByText(/outcome could not be confirmed/i);
        const body = vi.mocked(axios.post).mock.calls[0][1];
        expect(body).toEqual({
            expected_actor_id: index.actor_id,
            request_key: key,
            expected_version: row.status_version,
            reason: 'Reviewed original bytes and declared effects.',
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
        fireEvent.click(
            screen.getByRole('button', { name: 'Approve this version' }),
        );
        await screen.findByText(/outcome could not be confirmed/i);
        expect(
            screen.getByRole('button', { name: 'Check saved outcome' }),
        ).toBeEnabled();
        expect(
            screen.queryByText(/Your change was recorded/),
        ).not.toBeInTheDocument();
    });
    it('removes private details after an access denial during receipt recovery', async () => {
        render(view());
        await verify();
        decision();
        vi.mocked(axios.post).mockRejectedValueOnce(Error('lost response'));
        fireEvent.click(
            screen.getByRole('button', { name: 'Approve this version' }),
        );
        await screen.findByText(/outcome could not be confirmed/i);
        vi.mocked(axios.get).mockRejectedValueOnce({
            isAxiosError: true,
            response: { status: 403 },
        });
        fireEvent.click(
            screen.getByRole('button', { name: 'Check saved outcome' }),
        );
        await screen.findByText(/record is no longer available/);
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
