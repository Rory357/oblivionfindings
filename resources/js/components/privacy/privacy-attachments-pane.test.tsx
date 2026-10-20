import {
    fireEvent,
    render,
    screen,
    waitFor,
    within,
} from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { PrivacyAttachmentsPane } from './privacy-attachments-pane';

type VisitOptions = { onStart?: () => void; onFinish?: () => void };

const router = vi.hoisted(() => ({
    delete: vi.fn((_url: string, options?: VisitOptions) => {
        options?.onStart?.();
        options?.onFinish?.();
    }),
}));

vi.mock('@inertiajs/react', () => ({ router }));
vi.mock('@/components/ui/file-dropzone', () => ({
    AttachmentUploader: () => null,
    formatFileSize: (bytes: number) => `${bytes} B`,
}));

const consentForm = {
    id: 11,
    locked: false as const,
    name: 'consent-form.pdf',
    mime: 'application/pdf',
    is_image: false,
    size: 2048,
    notes: null,
    is_sensitive: false,
    uploaded_by: 'Aroha Support',
    created_at: '2026-10-01T09:00:00+13:00',
    download_url: '/privacy/attachments/11/download',
};

describe('PrivacyAttachmentsPane — removing a file', () => {
    beforeEach(() => router.delete.mockClear());

    it('asks before deleting, then deletes only the chosen file', async () => {
        render(
            <PrivacyAttachmentsPane
                attachableType="breach"
                attachableId={5}
                attachments={[consentForm]}
                canManage
            />,
        );

        fireEvent.click(
            screen.getByRole('button', { name: 'Remove consent-form.pdf' }),
        );

        expect(router.delete).not.toHaveBeenCalled();
        const dialog = screen.getByRole('alertdialog');
        expect(within(dialog).getByText('Remove this file?')).toBeInTheDocument();
        expect(dialog).toHaveTextContent(
            'consent-form.pdf will be permanently deleted from this breach record. This cannot be undone.',
        );

        fireEvent.click(
            within(dialog).getByRole('button', { name: 'Remove file' }),
        );

        expect(router.delete).toHaveBeenCalledTimes(1);
        expect(router.delete).toHaveBeenCalledWith(
            '/privacy/attachments/11',
            expect.objectContaining({
                preserveScroll: true,
                preserveState: true,
            }),
        );
        await waitFor(() =>
            expect(screen.queryByRole('alertdialog')).not.toBeInTheDocument(),
        );
    });

    it('keeps the file when the person cancels', async () => {
        render(
            <PrivacyAttachmentsPane
                attachableType="request"
                attachableId={5}
                attachments={[consentForm]}
                canManage
            />,
        );

        fireEvent.click(
            screen.getByRole('button', { name: 'Remove consent-form.pdf' }),
        );
        fireEvent.click(
            within(screen.getByRole('alertdialog')).getByRole('button', {
                name: 'Cancel',
            }),
        );

        await waitFor(() =>
            expect(screen.queryByRole('alertdialog')).not.toBeInTheDocument(),
        );
        expect(router.delete).not.toHaveBeenCalled();
    });
});
