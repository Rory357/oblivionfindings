import {
    act,
    cleanup,
    fireEvent,
    render,
    screen,
} from '@testing-library/react';
import { afterEach, beforeEach, expect, it, vi } from 'vitest';
import { DrillEvidence } from './drill-evidence';
import type { DrillWorkspaceDetail } from './types';

const state = vi.hoisted(() => ({ post: vi.fn() }));
vi.mock('@inertiajs/react', () => ({ router: { post: state.post } }));
vi.mock('@/components/ui/file-dropzone', () => ({
    FileDropzone: ({
        onFiles,
        disabled,
    }: {
        onFiles: (files: File[]) => void;
        disabled: boolean;
    }) => (
        <input
            aria-label="Evidence files"
            disabled={disabled}
            type="file"
            multiple
            onChange={(event) => onFiles(Array.from(event.target.files ?? []))}
        />
    ),
    formatFileSize: (value: number) => `${value} bytes`,
}));
const detail = {
    id: 8,
    expected_version: 4,
    lock_version: 4,
    attachments: [],
    exports: {},
    can: { add_evidence: true },
} as unknown as DrillWorkspaceDetail;
beforeEach(() => {
    state.post.mockReset();
});
afterEach(cleanup);
it('retains an interrupted file and retries with the same reviewed version and command identity', () => {
    state.post.mockImplementation((_href, _data, callbacks) =>
        callbacks.onFinish(),
    );
    const view = render(
        <DrillEvidence detail={detail} returnTo="/tasks?assigned=me" />,
    );
    fireEvent.change(screen.getByLabelText('Evidence files'), {
        target: { files: [new File(['proof'], 'drill-proof.txt')] },
    });
    fireEvent.click(screen.getByRole('button', { name: 'Upload 1 file' }));
    const first = state.post.mock.calls[0][1] as FormData;
    expect(first.get('expected_version')).toBe('4');
    expect(first.get('return_to')).toBe('/tasks?assigned=me');
    expect(screen.getByRole('alert').textContent).toContain(
        'could not be confirmed',
    );
    view.rerender(
        <DrillEvidence
            detail={{ ...detail, expected_version: 6, lock_version: 6 }}
            returnTo="/tasks?assigned=me"
        />,
    );
    fireEvent.click(screen.getByRole('button', { name: 'Upload 1 file' }));
    const second = state.post.mock.calls[1][1] as FormData;
    expect(second.get('idempotency_key')).toBe(first.get('idempotency_key'));
    expect(second.get('expected_version')).toBe('4');
    expect(screen.getByRole('button', { name: 'Upload 1 file' })).toBeTruthy();
});
it('advances each next file only from a confirmed parent receipt and uses a distinct identity', () => {
    render(<DrillEvidence detail={detail} returnTo="/health-safety/drills" />);
    fireEvent.change(screen.getByLabelText('Evidence files'), {
        target: {
            files: [new File(['a'], 'one.txt'), new File(['b'], 'two.txt')],
        },
    });
    fireEvent.click(screen.getByRole('button', { name: 'Upload 2 files' }));
    expect(state.post).toHaveBeenCalledTimes(1);
    const first = state.post.mock.calls[0][1] as FormData;
    act(() =>
        state.post.mock.calls[0][2].onSuccess({
            props: {
                detail: { ...detail, expected_version: 5, lock_version: 5 },
            },
        }),
    );
    expect(state.post).toHaveBeenCalledTimes(2);
    const second = state.post.mock.calls[1][1] as FormData;
    expect(second.get('expected_version')).toBe('5');
    expect(second.get('idempotency_key')).not.toBe(
        first.get('idempotency_key'),
    );
    act(() =>
        state.post.mock.calls[1][2].onSuccess({
            props: {
                detail: { ...detail, expected_version: 6, lock_version: 6 },
            },
        }),
    );
    expect(
        screen.queryByRole('button', { name: /Upload .* files?/ }),
    ).toBeNull();
});
it('does not discard a file or send the next file after an unrelated success page', () => {
    state.post.mockImplementation((_href, _data, callbacks) =>
        callbacks.onSuccess({
            props: { detail: { id: 99, expected_version: 5, lock_version: 5 } },
        }),
    );
    render(<DrillEvidence detail={detail} returnTo="/health-safety/drills" />);
    fireEvent.change(screen.getByLabelText('Evidence files'), {
        target: {
            files: [new File(['a'], 'one.txt'), new File(['b'], 'two.txt')],
        },
    });
    fireEvent.click(screen.getByRole('button', { name: 'Upload 2 files' }));
    expect(state.post).toHaveBeenCalledTimes(1);
    expect(screen.getByRole('alert').textContent).toContain(
        'could not be confirmed',
    );
    expect(screen.getByRole('button', { name: 'Upload 2 files' })).toBeTruthy();
});
