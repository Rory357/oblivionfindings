import {
    act,
    cleanup,
    fireEvent,
    render,
    screen,
    waitFor,
} from '@testing-library/react';
import axios from 'axios';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { SoftwareTransitionDialog } from './_dialogs';
import { SoftwarePackagePicker } from './_package-picker';
import { SoftwareUploadDialog } from './_upload';
import { actor, catalogue, device, release } from './software-fixtures';
vi.mock('axios', () => ({
    default: {
        get: vi.fn(),
        post: vi.fn(),
        isAxiosError: (error: unknown) =>
            !!error && typeof error === 'object' && 'response' in error,
    },
}));
vi.mock('@inertiajs/react', () => ({ router: { on: () => () => undefined } }));
beforeEach(() => vi.resetAllMocks());
afterEach(cleanup);
const draft = () =>
    release({
        status: 'uploaded',
        status_version: 1,
        reviewed_by: null,
        reviewed_at: null,
        published_at: null,
        can_submit: true,
        submit_href: '/security-devices/software/' + release().uuid + '/submit',
    });
const pending = () =>
    release({
        status: 'pending_review',
        status_version: 2,
        reviewed_by: null,
        reviewed_at: null,
        published_at: null,
        can_review: true,
        review_href: '/security-devices/software/' + release().uuid + '/review',
    });
async function fillUpload() {
    fireEvent.change(screen.getByLabelText('Application name'), {
        target: { value: 'Document viewer' },
    });
    fireEvent.change(screen.getByLabelText('Publisher'), {
        target: { value: 'Example Software Limited' },
    });
    fireEvent.change(screen.getByLabelText('Version'), {
        target: { value: '4.2.1' },
    });
    const input = document.querySelector('input[type=file]')!;
    fireEvent.change(input, {
        target: { files: [new File(['1234567890123456'], 'viewer.msi')] },
    });
    fireEvent.click(screen.getByRole('button', { name: 'Continue' }));
    fireEvent.change(await screen.findByLabelText('MSI ProductCode'), {
        target: { value: release().product_code },
    });
    fireEvent.change(
        screen.getByLabelText('Expected signing certificate SHA-256'),
        { target: { value: release().signer_sha256 } },
    );
    fireEvent.change(screen.getByLabelText('Reason and review notes'), {
        target: { value: 'Approved viewer for reception computers.' },
    });
    fireEvent.click(screen.getByRole('button', { name: 'Continue' }));
    await screen.findByRole('button', { name: 'Upload draft' });
}
function upload(changed = vi.fn()) {
    return render(
        <SoftwareUploadDialog
            actorId={actor}
            maxBytes={536870912}
            onClose={vi.fn()}
            onUploaded={changed}
        />,
    );
}
async function review(changed = vi.fn(), item = pending()) {
    render(
        <SoftwareTransitionDialog
            actorId={actor}
            item={item}
            action="review"
            onClose={vi.fn()}
            onChanged={changed}
        />,
    );
    fireEvent.click(
        screen.getByRole('button', { name: 'Continue to decision' }),
    );
    fireEvent.change(await screen.findByLabelText('Reason and review notes'), {
        target: {
            value: 'Verified vendor certificate and tested installation.',
        },
    });
}
describe('choose an approved release', () => {
    it('requires an explicit choice and keeps catalogue requests scoped to the computer', async () => {
        vi.mocked(axios.get).mockResolvedValue({ data: catalogue() });
        const changed = vi.fn();
        render(
            <SoftwarePackagePicker
                actorId={actor}
                deviceId={device}
                value=""
                onChange={changed}
            />,
        );
        await screen.findByText('Document viewer');
        expect(changed).not.toHaveBeenCalled();
        expect(axios.get).toHaveBeenCalledWith(
            '/security-devices/software?expected_actor_id=9001&device_id=9100&status=published',
            expect.anything(),
        );
        fireEvent.click(screen.getByRole('button', { name: 'Choose' }));
        expect(changed).toHaveBeenCalledWith(release().uuid);
    });
    it('searches beyond the first page and honours the returned scoped cursor', async () => {
        const page = catalogue();
        page.pagination.next_href =
            page.pagination.reset_href + '&software_cursor=reviewed-page';
        page.pagination.has_more = true;
        vi.mocked(axios.get).mockResolvedValue({ data: page });
        render(
            <SoftwarePackagePicker
                actorId={actor}
                deviceId={device}
                value=""
                onChange={vi.fn()}
            />,
        );
        fireEvent.click(await screen.findByRole('button', { name: 'Next' }));
        await waitFor(() =>
            expect(axios.get).toHaveBeenCalledWith(
                page.pagination.next_href,
                expect.anything(),
            ),
        );
        await waitFor(() =>
            expect(
                screen.getByRole('textbox', {
                    name: 'Search approved software',
                }),
            ).not.toBeDisabled(),
        );
        fireEvent.change(
            screen.getByRole('textbox', { name: 'Search approved software' }),
            { target: { value: 'Different application' } },
        );
        fireEvent.click(
            screen.getByRole('button', { name: 'Search approved software' }),
        );
        await waitFor(() =>
            expect(axios.get).toHaveBeenLastCalledWith(
                '/security-devices/software?expected_actor_id=9001&device_id=9100&status=published&search=Different+application',
                expect.anything(),
            ),
        );
    });
    it('does not expose foreign-account or unapproved results', async () => {
        vi.mocked(axios.get).mockResolvedValue({
            data: { ...catalogue(), actor_id: 9002 },
        });
        render(
            <SoftwarePackagePicker
                actorId={actor}
                deviceId={device}
                value=""
                onChange={vi.fn()}
            />,
        );
        await screen.findByText(/Approved software could not be loaded/);
        expect(screen.queryByText('Document viewer')).not.toBeInTheDocument();
        vi.mocked(axios.get).mockResolvedValue({
            data: { ...catalogue(), packages: [pending()] },
        });
        fireEvent.click(
            screen.getByRole('button', { name: 'Reload catalogue' }),
        );
        await waitFor(() => expect(axios.get).toHaveBeenCalledTimes(2));
        expect(
            screen.queryByRole('button', { name: 'Choose' }),
        ).not.toBeInTheDocument();
    });
});
describe('upload recovery', () => {
    it('reviews the exact file and identity before uploading only a draft', async () => {
        const changed = vi.fn();
        upload(changed);
        await fillUpload();
        expect(axios.post).not.toHaveBeenCalled();
        vi.mocked(axios.post).mockResolvedValue({
            data: { actor_id: actor, package: draft() },
        });
        fireEvent.click(screen.getByRole('button', { name: 'Upload draft' }));
        await screen.findByText('Software release uploaded');
        const [url, body] = vi.mocked(axios.post).mock.calls[0];
        expect(url).toBe('/security-devices/software');
        const data = body as FormData;
        expect(data.get('expected_actor_id')).toBe('9001');
        expect(data.get('file')).toBeInstanceOf(File);
        expect(data.get('request_key')).toMatch(/^[0-9a-f-]{36}$/);
        expect(data.get('signer_sha256')).toBe(release().signer_sha256);
        expect(changed).toHaveBeenCalledWith(draft());
        expect(axios.post).toHaveBeenCalledTimes(1);
    });
    it('recovers a lost upload by reading and preserves the original request without resubmitting', async () => {
        const changed = vi.fn();
        upload(changed);
        await fillUpload();
        vi.mocked(axios.post).mockRejectedValue(Error('Lost reply'));
        fireEvent.click(screen.getByRole('button', { name: 'Upload draft' }));
        await screen.findByText(/upload result could not be confirmed/);
        expect(
            screen.queryByRole('button', { name: 'Retry same upload' }),
        ).not.toBeInTheDocument();
        vi.mocked(axios.get).mockResolvedValue({
            data: { actor_id: actor, package: draft() },
        });
        fireEvent.click(
            screen.getByRole('button', { name: 'Check saved upload' }),
        );
        await screen.findByText('Software release uploaded');
        const key = (vi.mocked(axios.post).mock.calls[0][1] as FormData).get(
            'request_key',
        );
        expect(axios.get).toHaveBeenCalledWith(
            '/security-devices/software/uploads/' + key,
            expect.objectContaining({ params: { expected_actor_id: actor } }),
        );
        expect(axios.post).toHaveBeenCalledTimes(1);
        expect(changed).toHaveBeenCalledWith(draft());
    });
    it('only offers an explicit identical retry after a not-found recovery', async () => {
        upload();
        await fillUpload();
        vi.mocked(axios.post).mockRejectedValue(Error('Lost reply'));
        fireEvent.click(screen.getByRole('button', { name: 'Upload draft' }));
        await screen.findByText(/upload result could not be confirmed/);
        vi.mocked(axios.get).mockRejectedValue({ response: { status: 404 } });
        fireEvent.click(
            screen.getByRole('button', { name: 'Check saved upload' }),
        );
        await screen.findByRole('button', { name: 'Retry same upload' });
        expect(axios.post).toHaveBeenCalledTimes(1);
        vi.mocked(axios.post).mockResolvedValue({
            data: { actor_id: actor, package: draft() },
        });
        fireEvent.click(
            screen.getByRole('button', { name: 'Retry same upload' }),
        );
        await screen.findByText('Software release uploaded');
        const original = vi.mocked(axios.post).mock.calls[0][1] as FormData,
            retry = vi.mocked(axios.post).mock.calls[1][1] as FormData;
        expect([...retry.entries()]).toEqual([...original.entries()]);
    });
    it('does not accept a recovered upload with different reviewed identity', async () => {
        const changed = vi.fn();
        upload(changed);
        await fillUpload();
        vi.mocked(axios.post).mockRejectedValue(Error('Lost reply'));
        fireEvent.click(screen.getByRole('button', { name: 'Upload draft' }));
        await screen.findByText(/upload result could not be confirmed/);
        vi.mocked(axios.get).mockResolvedValue({
            data: { actor_id: actor, package: draftWithChangedVersion() },
        });
        fireEvent.click(
            screen.getByRole('button', { name: 'Check saved upload' }),
        );
        await screen.findByText(/saved upload could not be checked/);
        expect(changed).not.toHaveBeenCalled();
        expect(
            screen.queryByRole('button', { name: 'Retry same upload' }),
        ).not.toBeInTheDocument();
    });
    it('discards a late upload result after the private form closes', async () => {
        let complete!: (value: unknown) => void;
        const changed = vi.fn();
        const ui = upload(changed);
        await fillUpload();
        vi.mocked(axios.post).mockImplementation(
            () =>
                new Promise((resolve) => {
                    complete = resolve;
                }),
        );
        fireEvent.click(screen.getByRole('button', { name: 'Upload draft' }));
        const signal = vi.mocked(axios.post).mock.calls[0][2]!.signal!;
        ui.unmount();
        expect(signal.aborted).toBe(true);
        await act(async () =>
            complete({ data: { actor_id: actor, package: draft() } }),
        );
        expect(changed).not.toHaveBeenCalled();
    });
});
function draftWithChangedVersion() {
    return { ...draft(), product_version: '4.2.2' };
}
describe('independent approval', () => {
    it('posts only the reviewed release revision and purpose', async () => {
        const changed = vi.fn();
        await review(changed);
        expect(axios.post).not.toHaveBeenCalled();
        vi.mocked(axios.post).mockResolvedValue({
            data: { actor_id: actor, package: release() },
        });
        fireEvent.click(
            screen.getByRole('button', { name: 'Approve for use' }),
        );
        await screen.findByText('Release approved');
        expect(axios.post).toHaveBeenCalledWith(
            '/security-devices/software/' + release().uuid + '/review',
            {
                expected_actor_id: actor,
                expected_version: 2,
                reason: 'Verified vendor certificate and tested installation.',
            },
            expect.anything(),
        );
        expect(changed).toHaveBeenCalledWith(release());
    });
    it('requires a fresh read after a lost review result', async () => {
        await review();
        vi.mocked(axios.post).mockRejectedValue(Error('Lost reply'));
        fireEvent.click(
            screen.getByRole('button', { name: 'Approve for use' }),
        );
        await screen.findByText(/decision could not be confirmed/);
        expect(
            screen.queryByRole('button', { name: 'Approve for use' }),
        ).not.toBeInTheDocument();
        vi.mocked(axios.get).mockResolvedValue({
            data: { actor_id: actor, package: release() },
        });
        fireEvent.click(
            screen.getByRole('button', { name: 'Check saved release' }),
        );
        await screen.findByText('Release approved');
        expect(axios.post).toHaveBeenCalledTimes(1);
    });
    it('does not approve the uploader own package', () => {
        render(
            <SoftwareTransitionDialog
                actorId={actor}
                item={{
                    ...pending(),
                    uploaded_by: { id: actor, name: 'Current user' },
                }}
                action="review"
                onClose={vi.fn()}
                onChanged={vi.fn()}
            />,
        );
        expect(
            screen.getByRole('button', { name: 'Continue to decision' }),
        ).toBeDisabled();
        expect(axios.post).not.toHaveBeenCalled();
    });
    it('rejects changed bytes from a review response', async () => {
        const changed = vi.fn();
        await review(changed);
        vi.mocked(axios.post).mockResolvedValue({
            data: {
                actor_id: actor,
                package: release({ sha256: 'f'.repeat(64) }),
            },
        });
        fireEvent.click(
            screen.getByRole('button', { name: 'Approve for use' }),
        );
        await screen.findByText(/decision could not be confirmed/);
        expect(changed).not.toHaveBeenCalled();
    });
});
