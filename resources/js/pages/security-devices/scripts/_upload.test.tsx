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
const httpError = (status: number) => ({
    isAxiosError: true,
    response: { status },
});
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
    fireEvent.change(screen.getByLabelText('Recovery plan'), {
        target: { value: original.script.review.compensation },
    });
    if (acknowledge)
        fireEvent.click(
            screen.getByRole('checkbox', { name: /leaves no background work/ }),
        );
    fireEvent.click(screen.getByRole('button', { name: 'Next section' }));
    fireEvent.change(screen.getByLabelText('Reason for this upload'), {
        target: { value: 'Synthetic independent catalogue verification.' },
    });
}
/** Move to the inputs section and add one input. */
function addInput() {
    fireEvent.click(screen.getByRole('button', { name: /Inputs and limits/ }));
    fireEvent.click(screen.getByRole('button', { name: 'Add input' }));
}
let keys: string[] = [];
beforeEach(() => {
    vi.clearAllMocks();
    keys = [];
    vi.stubGlobal('crypto', {
        subtle: webcrypto.subtle,
        randomUUID: () => {
            const next =
                keys.length === 0
                    ? original.receipt.request_key
                    : '33333333-3333-4333-8333-33333333333' + keys.length;
            keys.push(next);
            return next;
        },
    });
});
afterEach(() => {
    cleanup();
    vi.unstubAllGlobals();
});
describe('script upload wizard', () => {
    it('saves the selected original bytes and reports a draft rather than execution', async () => {
        const uploaded = vi.fn(),
            another = vi.fn();
        render(view({ onUploaded: uploaded, onAddAnother: another }));
        await fill();
        vi.mocked(axios.post).mockResolvedValueOnce({ data: original });
        fireEvent.click(screen.getByRole('button', { name: 'Upload draft' }));
        await screen.findByRole('heading', { name: 'Script draft saved' });
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
        fireEvent.click(
            screen.getByRole('button', { name: 'Add another script' }),
        );
        expect(another).toHaveBeenCalledTimes(1);
    });
    it('marks a missing declaration next to its field and preserves all form entries', async () => {
        render(view());
        await fill(false);
        fireEvent.click(screen.getByRole('button', { name: 'Upload draft' }));
        await screen.findByText(/One detail needs fixing before upload/);
        expect(axios.post).not.toHaveBeenCalled();
        // The wizard jumps to the section holding the problem.
        expect(screen.getByText(/Tick this to confirm/)).toBeVisible();
        expect(
            screen.getByRole('checkbox', { name: /leaves no background work/ }),
        ).toHaveAttribute('aria-invalid', 'true');
        expect(screen.getByLabelText('Recovery plan')).toHaveValue(
            original.script.review.compensation,
        );
        fireEvent.click(
            screen.getByRole('button', { name: /Check and upload/ }),
        );
        expect(screen.getByLabelText('Reason for this upload')).toHaveValue(
            'Synthetic independent catalogue verification.',
        );
    });
    it('explains reserved, unnamed and blank-choice inputs at the field instead of a refresh message', async () => {
        render(view());
        await fill();
        addInput();
        fireEvent.change(screen.getByLabelText('Input 1 name'), {
            target: { value: 'Verbose' },
        });
        addInput();
        fireEvent.click(
            screen.getByRole('button', { name: /Check and upload/ }),
        );
        fireEvent.click(screen.getByRole('button', { name: 'Upload draft' }));
        await screen.findByText(/2 details need fixing before upload/);
        expect(axios.post).not.toHaveBeenCalled();
        expect(
            screen.getByText(
                '“Verbose” is reserved by PowerShell. Choose another name.',
            ),
        ).toBeVisible();
        expect(screen.getByText('Name this input.')).toBeVisible();
        expect(screen.queryByText(/Refresh the catalogue/)).toBeNull();
        await waitFor(() =>
            expect(screen.getByLabelText('Input 1 name')).toHaveFocus(),
        );
        fireEvent.change(screen.getByLabelText('Input 1 name'), {
            target: { value: 'Mode' },
        });
        expect(screen.queryByText(/reserved by PowerShell/)).toBeNull();
        fireEvent.click(screen.getByRole('button', { name: 'Remove input 2' }));
        vi.mocked(axios.post).mockRejectedValueOnce(Error('offline'));
        fireEvent.click(
            screen.getByRole('button', { name: /Check and upload/ }),
        );
        fireEvent.click(screen.getByRole('button', { name: 'Upload draft' }));
        await waitFor(() => expect(axios.post).toHaveBeenCalledTimes(1));
        const body = vi.mocked(axios.post).mock.calls[0][1] as {
            metadata: { parameter_schema: { name: string }[] };
        };
        expect(body.metadata.parameter_schema).toEqual([
            {
                name: 'Mode',
                type: 'boolean',
                required: false,
                constraints: {},
            },
        ]);
    });
    it('ignores blank lines between choices', async () => {
        render(view());
        await fill();
        addInput();
        fireEvent.change(screen.getByLabelText('Input 1 name'), {
            target: { value: 'Mode' },
        });
        fireEvent.keyDown(
            screen.getByRole('combobox', { name: 'Input type' }),
            {
                key: 'ArrowDown',
            },
        );
        fireEvent.click(await screen.findByRole('option', { name: 'Choice' }));
        const options = await screen.findByLabelText('Choices (one per line)');
        fireEvent.change(options, {
            target: { value: 'inspect\n\nreport\n' },
        });
        vi.mocked(axios.post).mockRejectedValueOnce(Error('offline'));
        fireEvent.click(
            screen.getByRole('button', { name: /Check and upload/ }),
        );
        fireEvent.click(screen.getByRole('button', { name: 'Upload draft' }));
        await waitFor(() => expect(axios.post).toHaveBeenCalledTimes(1));
        const body = vi.mocked(axios.post).mock.calls[0][1] as {
            metadata: {
                parameter_schema: { constraints: { values: string[] } }[];
            };
        };
        expect(body.metadata.parameter_schema[0].constraints.values).toEqual([
            'inspect',
            'report',
        ]);
    });
    it('keeps reasons on one line', async () => {
        render(view());
        await fill();
        const field = screen.getByLabelText('Reason for this upload');
        expect(fireEvent.keyDown(field, { key: 'Enter' })).toBe(false);
        fireEvent.change(field, {
            target: { value: 'First line\r\nsecond line\tend' },
        });
        expect(field).toHaveValue('First line second line end');
    });
    it('hands the form back after a definite refusal and sends the edit under a new request key', async () => {
        render(view());
        await fill();
        vi.mocked(axios.post).mockRejectedValueOnce(httpError(422));
        fireEvent.click(screen.getByRole('button', { name: 'Upload draft' }));
        await screen.findByText(
            /didn’t accept this upload, so nothing was saved/,
        );
        const field = screen.getByLabelText('Reason for this upload');
        expect(field).toBeEnabled();
        expect(
            screen.queryByRole('button', { name: 'Retry same upload' }),
        ).not.toBeInTheDocument();
        fireEvent.change(field, {
            target: { value: 'Corrected synthetic catalogue verification.' },
        });
        vi.mocked(axios.post).mockRejectedValueOnce(Error('offline'));
        fireEvent.click(screen.getByRole('button', { name: 'Upload draft' }));
        await waitFor(() => expect(axios.post).toHaveBeenCalledTimes(2));
        const [first, second] = vi
            .mocked(axios.post)
            .mock.calls.map((call) => call[1] as Record<string, unknown>);
        expect(second.request_key).not.toBe(first.request_key);
        expect(second.reason).toBe(
            'Corrected synthetic catalogue verification.',
        );
    });
    it('unlocks the form when recovery finds no saved upload for the request', async () => {
        render(view());
        await fill();
        vi.mocked(axios.post).mockRejectedValueOnce(Error('timed out'));
        fireEvent.click(screen.getByRole('button', { name: 'Upload draft' }));
        await screen.findByText(/upload outcome could not be confirmed/);
        expect(screen.getByLabelText('Reason for this upload')).toBeDisabled();
        vi.mocked(axios.get).mockRejectedValueOnce(httpError(404));
        fireEvent.click(
            screen.getByRole('button', { name: 'Check saved upload' }),
        );
        await screen.findByText(/No saved upload was found for this request/);
        expect(screen.getByLabelText('Reason for this upload')).toBeEnabled();
        expect(
            screen.getByRole('button', { name: 'Upload draft' }),
        ).toBeEnabled();
    });
    it('keeps a busy upload locked and retries exactly the same request', async () => {
        render(view());
        await fill();
        vi.mocked(axios.post).mockRejectedValueOnce(httpError(503));
        fireEvent.click(screen.getByRole('button', { name: 'Upload draft' }));
        await screen.findByText(/catalogue is busy saving another change/);
        expect(screen.getByLabelText('Reason for this upload')).toBeDisabled();
        const first = vi.mocked(axios.post).mock.calls[0][1];
        vi.mocked(axios.post).mockResolvedValueOnce({ data: original });
        fireEvent.click(
            screen.getByRole('button', { name: 'Retry same upload' }),
        );
        await screen.findByRole('heading', { name: 'Script draft saved' });
        expect(vi.mocked(axios.post).mock.calls[1][1]).toBe(first);
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
        await screen.findByRole('heading', { name: 'Script draft saved' });
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
            screen.queryByRole('heading', { name: 'Script draft saved' }),
        ).not.toBeInTheDocument();
        expect(
            screen.getByRole('button', { name: 'Upload draft' }),
        ).toBeDisabled();
    });
    it('hides private form details after access denial while retaining recovery', async () => {
        render(view());
        await fill();
        vi.mocked(axios.post).mockRejectedValueOnce(httpError(403));
        fireEvent.click(screen.getByRole('button', { name: 'Upload draft' }));
        await screen.findByText(/private upload details are hidden/i);
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
