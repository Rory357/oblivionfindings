import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import {
    ChatAttachmentQueue,
    ChatUploadStatus,
    chatFileIssue,
} from './chat-attachment-queue';

const photo = new File(['photo'], 'visit.png', { type: 'image/png' });
const document = new File(['plan'], 'plan.pdf', { type: 'application/pdf' });
const props = {
    open: true,
    onOpenChange: vi.fn(),
    files: [photo, document],
    captions: ['Garden visit', ''],
    maxFiles: 5,
    maxMb: 20,
    locked: false,
    sending: false,
    progress: null,
    onChoose: vi.fn(),
    onRemove: vi.fn(),
    onCaption: vi.fn(),
    onClear: vi.fn(),
    onReturnFocus: vi.fn(),
};
describe('shared attachment review', () => {
    beforeEach(() => {
        vi.clearAllMocks();
        URL.createObjectURL = vi.fn(() => 'blob:selected-photo');
        URL.revokeObjectURL = vi.fn();
    });
    it('keeps the dialog and selection when removal is not confirmed', async () => {
        const onClear = vi.fn().mockResolvedValue(false);
        render(<ChatAttachmentQueue {...props} onClear={onClear} />);
        fireEvent.click(
            screen.getByRole('button', { name: 'Cancel attachments' }),
        );
        await waitFor(() =>
            expect(
                screen.getByRole('button', { name: 'Cancel attachments' }),
            ).toBeEnabled(),
        );
        expect(onClear).toHaveBeenCalledTimes(1);
        expect(props.onOpenChange).not.toHaveBeenCalled();
        expect(
            screen.getByLabelText('Caption for plan.pdf'),
        ).toBeInTheDocument();
    });
    it('previews local photos, edits per-file captions and returns to the message without sending', async () => {
        const view = render(<ChatAttachmentQueue {...props} />);
        expect(
            await screen.findByRole('img', {
                name: 'Selected photo: visit.png',
            }),
        ).toHaveAttribute('src', 'blob:selected-photo');
        fireEvent.change(screen.getByLabelText('Caption for plan.pdf'), {
            target: { value: 'Friday visit plan' },
        });
        expect(props.onCaption).toHaveBeenCalledWith(1, 'Friday visit plan');
        fireEvent.click(screen.getByRole('button', { name: 'Add to message' }));
        expect(props.onOpenChange).toHaveBeenCalledWith(false);
        expect(props.onClear).not.toHaveBeenCalled();
        view.unmount();
        expect(URL.revokeObjectURL).toHaveBeenCalledWith('blob:selected-photo');
    });
    it('explains invalid files, blocks adding them and supports deliberate removal', () => {
        const unsafe = new File(['executable'], 'bad.exe', {
            type: 'application/octet-stream',
        });
        render(
            <ChatAttachmentQueue {...props} files={[unsafe]} captions={['']} />,
        );
        expect(screen.getByText(/Unsupported format/)).toBeVisible();
        expect(
            screen.getByRole('button', { name: 'Add to message' }),
        ).toBeDisabled();
        fireEvent.click(
            screen.getByRole('button', { name: 'Remove selected bad.exe' }),
        );
        expect(props.onRemove).toHaveBeenCalledWith(0);
        expect(chatFileIssue(new File([], 'empty.txt'), 20)).toMatch(/empty/);
        expect(
            chatFileIssue(new File(['x'.repeat(1100)], 'large.txt'), 0.001),
        ).toMatch(/Too large/);
    });
    it('replaces the queue with a local preview and restores its trigger and caption without sending', async () => {
        const word = new File(['document'], 'visit.docx', {
            type: 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        });
        render(
            <ChatAttachmentQueue
                {...props}
                files={[word]}
                captions={['Retained caption']}
            />,
        );
        fireEvent.click(
            screen.getByRole('button', { name: 'Preview selected visit.docx' }),
        );
        expect(
            await screen.findByText('Local preview · Not sent'),
        ).toBeVisible();
        expect(
            screen.queryByRole('dialog', { name: 'Add photos or documents' }),
        ).not.toBeInTheDocument();
        expect(
            screen.getByText(
                'This file format cannot be previewed here. Open the original on your device before sending.',
            ),
        ).toBeVisible();
        fireEvent.click(screen.getByRole('button', { name: 'Close viewer' }));
        await waitFor(() =>
            expect(
                screen.getByRole('button', {
                    name: 'Preview selected visit.docx',
                }),
            ).toHaveFocus(),
        );
        expect(screen.getByLabelText('Caption for visit.docx')).toHaveValue(
            'Retained caption',
        );
        expect(props.onClear).not.toHaveBeenCalled();
        expect(props.onOpenChange).not.toHaveBeenCalled();
    });

    it('drops a local preview when its file no longer belongs to the current selection', async () => {
        const view = render(<ChatAttachmentQueue {...props} />);
        fireEvent.click(
            screen.getByRole('button', { name: 'Preview selected plan.pdf' }),
        );
        view.rerender(
            <ChatAttachmentQueue {...props} files={[]} captions={[]} />,
        );
        await screen.findByRole('dialog', { name: 'Add photos or documents' });
        expect(
            screen.queryByText('Local preview · Not sent or scanned'),
        ).not.toBeInTheDocument();
    });
    it('locks the reviewed files after uncertain sending and distinguishes upload from confirmation', () => {
        render(
            <ChatAttachmentQueue
                {...props}
                locked
                sending
                progress={{ loaded: 100, total: 100 }}
            />,
        );
        expect(screen.getByLabelText('Caption for plan.pdf')).toBeDisabled();
        expect(
            screen.getByRole('button', { name: 'Cancel attachments' }),
        ).toBeDisabled();
        expect(
            screen.getByRole('button', { name: 'Remove selected visit.png' }),
        ).toBeDisabled();
        expect(
            screen.getByText('Upload complete. Confirming the message…'),
        ).toBeVisible();
        expect(screen.queryByRole('progressbar')).not.toBeInTheDocument();
    });
    it('uses a measured percentage or indeterminate progress without claiming delivery', () => {
        const view = render(
            <ChatUploadStatus progress={{ loaded: 40, total: 100 }} />,
        );
        expect(screen.getByText('Uploading attachments… 40%')).toBeVisible();
        expect(screen.getByRole('progressbar')).toHaveAttribute('value', '40');
        view.rerender(
            <ChatUploadStatus progress={{ loaded: 40, total: null }} />,
        );
        expect(screen.getByRole('progressbar')).not.toHaveAttribute('value');
    });
});
