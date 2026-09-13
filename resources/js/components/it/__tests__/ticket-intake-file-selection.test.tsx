import {
    cleanup,
    fireEvent,
    render,
    screen,
    waitFor,
} from '@testing-library/react';
import axios from 'axios';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import { clearItTicketDraftMemory } from '@/hooks/use-it-ticket-draft-memory';
import {
    IT_ATTACHMENT_ACCEPT,
    itAttachmentSelectionError,
} from '@/lib/it-attachments';
import { ItWizard } from '../it-wizards';

vi.mock('@inertiajs/react', async (importOriginal) => ({
    ...(await importOriginal<typeof import('@inertiajs/react')>()),
    usePage: () => ({
        props: {
            auth: { user: { id: 230 } },
            draftRecovery: { enabled: false },
        },
    }),
}));

const maxBytes = 10 * 1024 * 1024;
const file = (name: string) =>
    new File(['Synthetic support file'], name, { type: 'text/plain' });

function openIntake(type: 'raise' | 'ticket') {
    render(
        <ItWizard
            modal={{ type }}
            assignees={[]}
            siteOptions={[{ id: 9403, name: 'Approved Site A' }]}
            onClose={vi.fn()}
        />,
    );
    if (type === 'raise') {
        fireEvent.click(
            screen.getByRole('button', { name: /Add more details/ }),
        );
    }
    const title = screen.getByPlaceholderText(
        type === 'raise'
            ? 'e.g. My work phone won’t charge'
            : 'e.g. Printer offline — Sunnyside Lodge',
    );
    const description = screen.getByPlaceholderText(
        type === 'raise'
            ? 'Anything that helps IT find or fix it — where you are, what you tried…'
            : 'Anything that helps IT reproduce or locate the issue…',
    );
    fireEvent.change(title, { target: { value: 'Keep this support request' } });
    fireEvent.change(description, {
        target: { value: 'Keep these entered details' },
    });
    const dropzone = screen.getByRole('button', {
        name:
            type === 'raise'
                ? /Drop a photo of the problem/
                : /Drop a photo or file/,
    });
    const input =
        document.querySelector<HTMLInputElement>('input[type="file"]');
    if (!input) throw new Error('The canonical file picker was not rendered.');
    expect(input).toHaveAttribute('accept', IT_ATTACHMENT_ACCEPT);
    return { title, description, dropzone, input };
}

describe('IT attachment selection limits', () => {
    it('accepts every approved extension, including uppercase, at the exact size and count limits', () => {
        for (const extension of IT_ATTACHMENT_ACCEPT.split(',')) {
            expect(
                itAttachmentSelectionError(
                    [
                        {
                            name: `report${extension.toUpperCase()}`,
                            size: maxBytes,
                        },
                    ],
                    4,
                ),
            ).toBeNull();
        }
    });

    it.each(['program.exe', 'report.txt.html', 'report', 'drawing.svg'])(
        'rejects the whole mixed selection containing %s',
        (name) => {
            expect(
                itAttachmentSelectionError([
                    { name: 'valid.txt', size: 1 },
                    { name, size: 1 },
                ]),
            ).toMatch(/No files from this selection were added/);
        },
    );

    it('includes existing files in the count and rejects one byte beyond the per-file limit', () => {
        expect(
            itAttachmentSelectionError(
                [
                    { name: 'one.txt', size: 1 },
                    { name: 'two.txt', size: 1 },
                ],
                4,
            ),
        ).toMatch(/up to 5 files in total/);
        expect(
            itAttachmentSelectionError([
                { name: 'oversize.txt', size: maxBytes + 1 },
            ]),
        ).toMatch(/10 MB or smaller/);
    });
});

describe.each(['raise', 'ticket'] as const)(
    '%s raw intake file selection',
    (type) => {
        beforeEach(() => {
            clearItTicketDraftMemory();
            localStorage.clear();
            sessionStorage.clear();
            vi.spyOn(axios, 'post').mockRejectedValue({
                isAxiosError: true,
                response: { status: 500 },
            });
            vi.spyOn(axios, 'request').mockRejectedValue({
                isAxiosError: true,
                response: { status: 500 },
            });
        });
        afterEach(() => {
            cleanup();
            clearItTicketDraftMemory();
            localStorage.clear();
            sessionStorage.clear();
            vi.restoreAllMocks();
        });

        it('rejects a mixed drag selection without changing prior files or text, then accepts a corrected picker selection', () => {
            const { title, description, dropzone, input } = openIntake(type);
            fireEvent.drop(dropzone, {
                dataTransfer: { files: [file('existing.txt')] },
            });
            fireEvent.drop(dropzone, {
                dataTransfer: {
                    files: [file('otherwise-valid.txt'), file('program.exe')],
                },
            });

            expect(
                screen.getByText(
                    /Choose an image, PDF, text, CSV, Word or Excel file/,
                ),
            ).toBeVisible();
            expect(screen.getByText('existing.txt')).toBeVisible();
            expect(
                screen.queryByText('otherwise-valid.txt'),
            ).not.toBeInTheDocument();
            expect(screen.queryByText('program.exe')).not.toBeInTheDocument();
            expect(title).toHaveValue('Keep this support request');
            expect(description).toHaveValue('Keep these entered details');

            fireEvent.change(input, {
                target: { files: [file('corrected.TXT')] },
            });
            expect(screen.getByText('corrected.TXT')).toBeVisible();
            expect(screen.getByText('existing.txt')).toBeVisible();
            expect(
                screen.queryByText(
                    /Choose an image, PDF, text, CSV, Word or Excel file/,
                ),
            ).not.toBeInTheDocument();
            expect(axios.post).not.toHaveBeenCalled();
            expect(axios.request).not.toHaveBeenCalled();
        });

        it('rejects all excess files rather than silently keeping the first five and allows a smaller correction', () => {
            const { title, description, dropzone } = openIntake(type);
            const existing = Array.from({ length: 4 }, (_, index) =>
                file(`existing-${index}.txt`),
            );
            fireEvent.drop(dropzone, { dataTransfer: { files: existing } });
            fireEvent.drop(dropzone, {
                dataTransfer: {
                    files: [file('extra-one.txt'), file('extra-two.txt')],
                },
            });

            expect(
                screen.getByText(/Choose up to 5 files in total/),
            ).toBeVisible();
            for (const selected of existing)
                expect(screen.getByText(selected.name)).toBeVisible();
            expect(screen.queryByText('extra-one.txt')).not.toBeInTheDocument();
            expect(screen.queryByText('extra-two.txt')).not.toBeInTheDocument();
            expect(title).toHaveValue('Keep this support request');
            expect(description).toHaveValue('Keep these entered details');

            fireEvent.drop(dropzone, {
                dataTransfer: { files: [file('extra-one.txt')] },
            });
            expect(
                screen.getAllByRole('button', { name: 'Remove file' }),
            ).toHaveLength(5);
            expect(screen.getByText('extra-one.txt')).toBeVisible();
            expect(
                screen.queryByText(/Choose up to 5 files in total/),
            ).not.toBeInTheDocument();
        });

        it('rejects an oversized selection and clears its actionable error when the user removes a prior file', () => {
            const { title, description, dropzone } = openIntake(type);
            fireEvent.drop(dropzone, {
                dataTransfer: { files: [file('existing.txt')] },
            });
            const oversized = new File(
                [new Uint8Array(maxBytes + 1)],
                'oversized.txt',
                { type: 'text/plain' },
            );
            fireEvent.drop(dropzone, {
                dataTransfer: {
                    files: [file('otherwise-valid.txt'), oversized],
                },
            });

            expect(
                screen.getByText(/Each file must be 10 MB or smaller/),
            ).toBeVisible();
            expect(screen.getByText('existing.txt')).toBeVisible();
            expect(screen.queryByText('oversized.txt')).not.toBeInTheDocument();
            expect(
                screen.queryByText('otherwise-valid.txt'),
            ).not.toBeInTheDocument();
            fireEvent.click(
                screen.getByRole('button', { name: 'Remove file' }),
            );
            expect(
                screen.queryByText(/Each file must be 10 MB or smaller/),
            ).not.toBeInTheDocument();
            expect(screen.queryByText('existing.txt')).not.toBeInTheDocument();
            expect(title).toHaveValue('Keep this support request');
            expect(description).toHaveValue('Keep these entered details');
        });

        if (type === 'raise') {
            it('submits the original accepted File objects and unchanged text after a rejected batch', async () => {
                const { dropzone } = openIntake(type);
                const existing = file('existing.txt');
                const corrected = file('corrected.txt');
                fireEvent.drop(dropzone, {
                    dataTransfer: { files: [existing] },
                });
                fireEvent.drop(dropzone, {
                    dataTransfer: {
                        files: [file('bad.exe'), file('not-added.txt')],
                    },
                });
                fireEvent.drop(dropzone, {
                    dataTransfer: { files: [corrected] },
                });
                fireEvent.click(
                    screen.getByRole('button', { name: 'Raise ticket' }),
                );

                await waitFor(() =>
                    expect(axios.post).toHaveBeenCalledTimes(1),
                );
                const submitted = vi.mocked(axios.post).mock
                    .calls[0][1] as FormData;
                expect(submitted.get('attachments[0]')).toBe(existing);
                expect(submitted.get('attachments[1]')).toBe(corrected);
                expect(submitted.has('attachments[2]')).toBe(false);
                expect(submitted.get('title')).toBe(
                    'Keep this support request',
                );
                expect(submitted.get('description')).toBe(
                    'Keep these entered details',
                );
                await screen.findByRole('button', {
                    name: 'Retry same request',
                });
            });
        }
    },
);
