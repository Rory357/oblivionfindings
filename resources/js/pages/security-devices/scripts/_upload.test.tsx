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
import { ScriptUploadDialog } from './_upload';

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
        'resources/js/pages/security-devices/scripts/__fixtures__/catalogue-http.json',
        'utf8',
    ),
);
const original = corpus.uploaded;
function file(text: string, name: string) {
    const bytes = new TextEncoder().encode(text);
    return Object.assign(new File([bytes.buffer], name), {
        arrayBuffer: async () => bytes.buffer,
    });
}
const view = (overrides = {}) => (
    <ScriptUploadDialog
        actorId={original.actor_id}
        open
        onClose={vi.fn()}
        onUploaded={vi.fn()}
        onDirtyChange={vi.fn()}
        {...overrides}
    />
);
async function fill(acknowledge = true) {
    fireEvent.change(screen.getByLabelText('Script name'), {
        target: { value: original.script.manifest.name },
    });
    fireEvent.change(screen.getByLabelText('Publisher or author'), {
        target: { value: original.script.manifest.publisher_label },
    });
    fireEvent.change(document.getElementById('script-file-input')!, {
        target: { files: [file(corpus.material.artifact, 'inspection.ps1')] },
    });
    await screen.findByText(/inspection.ps1 ·/);
    await waitFor(() =>
        expect(
            document.getElementById('script-runtime-input'),
        ).not.toBeDisabled(),
    );
    fireEvent.change(document.getElementById('script-runtime-input')!, {
        target: {
            files: [file(corpus.material.runtime_manifest, 'runtime.json')],
        },
    });
    await screen.findByText(/runtime.json · PowerShell/);
    await waitFor(() =>
        expect(screen.getByLabelText('Script name')).toBeEnabled(),
    );
    fireEvent.click(screen.getByRole('button', { name: 'Next section' }));
    expect(screen.getByLabelText('Time limit (seconds)')).toHaveValue(60);
    fireEvent.click(screen.getByRole('button', { name: 'Next section' }));
    fireEvent.change(screen.getByLabelText('Compensation or recovery plan'), {
        target: { value: original.script.review.compensation },
    });
    if (acknowledge)
        fireEvent.click(
            screen.getByRole('checkbox', { name: /creates no detached work/ }),
        );
    fireEvent.click(screen.getByRole('button', { name: 'Next section' }));
    fireEvent.change(screen.getByLabelText('Reason for this upload'), {
        target: { value: 'Synthetic independent catalogue verification.' },
    });
}
beforeEach(() => {
    vi.clearAllMocks();
    vi.stubGlobal('crypto', {
        subtle: webcrypto.subtle,
        randomUUID: () => original.receipt.request_key,
    });
});
afterEach(() => {
    cleanup();
    vi.unstubAllGlobals();
});
describe('script upload wizard', () => {
    it('saves the selected original bytes and reports a draft rather than execution', async () => {
        const uploaded = vi.fn();
        render(view({ onUploaded: uploaded }));
        await fill();
        vi.mocked(axios.post).mockResolvedValueOnce({ data: original });
        fireEvent.click(screen.getByRole('button', { name: 'Upload draft' }));
        await screen.findByRole('heading', { name: 'Upload saved' });
        expect(uploaded).toHaveBeenCalledWith(original.script);
        const request = vi.mocked(axios.post).mock.calls[0][1] as Record<
            string,
            unknown
        >;
        expect(atob(request.artifact_base64 as string)).toBe(
            corpus.material.artifact,
        );
        expect(atob(request.runtime_manifest_base64 as string)).toBe(
            corpus.material.runtime_manifest,
        );
        expect(screen.getByText(/It has not been run/)).toBeVisible();
    });
    it('requires the declaration before sending and preserves all form entries', async () => {
        render(view());
        await fill(false);
        fireEvent.click(screen.getByRole('button', { name: 'Upload draft' }));
        await screen.findByText(/Confirm the restrictions/);
        expect(axios.post).not.toHaveBeenCalled();
        expect(screen.getByLabelText('Reason for this upload')).toHaveValue(
            'Synthetic independent catalogue verification.',
        );
    });
    it('keeps exactly the same upload after a lost response and close/reopen', async () => {
        const uploaded = vi.fn(),
            dirty = vi.fn(),
            props = { onUploaded: uploaded, onDirtyChange: dirty };
        const rendered = render(view(props));
        await fill();
        vi.mocked(axios.post).mockRejectedValueOnce(Error('lost response'));
        fireEvent.click(screen.getByRole('button', { name: 'Upload draft' }));
        await screen.findByText(/upload outcome could not be confirmed/);
        const first = vi.mocked(axios.post).mock.calls[0][1];
        expect(screen.getByLabelText('Reason for this upload')).toBeDisabled();
        rendered.rerender(view({ ...props, open: false }));
        rendered.rerender(view(props));
        vi.mocked(axios.post).mockRejectedValueOnce(Error('still offline'));
        fireEvent.click(
            screen.getByRole('button', { name: 'Retry same upload' }),
        );
        await waitFor(() => expect(axios.post).toHaveBeenCalledTimes(2));
        expect(vi.mocked(axios.post).mock.calls[1][1]).toBe(first);
        await waitFor(() =>
            expect(
                screen.getByRole('button', { name: 'Check saved upload' }),
            ).toBeEnabled(),
        );
        vi.mocked(axios.get).mockResolvedValueOnce({ data: original });
        fireEvent.click(
            screen.getByRole('button', { name: 'Check saved upload' }),
        );
        await screen.findByRole('heading', { name: 'Upload saved' });
        expect(axios.get).toHaveBeenLastCalledWith(
            '/security-devices/scripts/uploads/' +
                original.receipt.request_key +
                '?expected_actor_id=' +
                original.actor_id,
            expect.any(Object),
        );
        expect(dirty).toHaveBeenLastCalledWith(false);
        expect(uploaded).toHaveBeenCalledTimes(1);
    });
    it('does not accept a mismatched upload receipt or create a replacement request', async () => {
        render(view());
        await fill();
        vi.mocked(axios.post).mockResolvedValueOnce({
            data: {
                ...original,
                receipt: {
                    ...original.receipt,
                    request_key: '11111111-1111-4111-8111-111111111111',
                },
            },
        });
        fireEvent.click(screen.getByRole('button', { name: 'Upload draft' }));
        await screen.findByText(/upload outcome could not be confirmed/);
        expect(
            screen.queryByRole('heading', { name: 'Upload saved' }),
        ).not.toBeInTheDocument();
        expect(
            screen.getByRole('button', { name: 'Upload draft' }),
        ).toBeDisabled();
    });
    it('hides private form details after access denial while retaining recovery', async () => {
        render(view());
        await fill();
        vi.mocked(axios.post).mockRejectedValueOnce({
            isAxiosError: true,
            response: { status: 403 },
        });
        fireEvent.click(screen.getByRole('button', { name: 'Upload draft' }));
        await screen.findByText(/Private upload details are hidden/);
        expect(
            screen.queryByLabelText('Reason for this upload'),
        ).not.toBeInTheDocument();
        expect(
            screen.queryByText(original.script.manifest.name),
        ).not.toBeInTheDocument();
        expect(
            screen.getByRole('button', { name: 'Retry same upload' }),
        ).toBeDisabled();
        expect(
            screen.getByRole('button', { name: 'Check saved upload' }),
        ).toBeEnabled();
        vi.mocked(axios.get).mockRejectedValueOnce(
            Error('network unavailable'),
        );
        fireEvent.click(
            screen.getByRole('button', { name: 'Check saved upload' }),
        );
        await screen.findByText(/No matching saved outcome could be confirmed/);
        expect(
            screen.queryByLabelText('Reason for this upload'),
        ).not.toBeInTheDocument();
        expect(
            screen.getByRole('button', { name: 'Retry same upload' }),
        ).toBeDisabled();
    });
    it('does not apply a late saved response after actor workspace removal', async () => {
        let resolve: (value: unknown) => void = () => {};
        const uploaded = vi.fn(),
            rendered = render(view({ onUploaded: uploaded }));
        await fill();
        vi.mocked(axios.post).mockImplementationOnce(
            () =>
                new Promise((done) => {
                    resolve = done;
                }),
        );
        fireEvent.click(screen.getByRole('button', { name: 'Upload draft' }));
        await waitFor(() => expect(axios.post).toHaveBeenCalledTimes(1));
        const options = vi.mocked(axios.post).mock.calls[0][2];
        rendered.unmount();
        expect(options?.signal?.aborted).toBe(true);
        await act(async () => resolve({ data: original }));
        expect(uploaded).not.toHaveBeenCalled();
    });
});
