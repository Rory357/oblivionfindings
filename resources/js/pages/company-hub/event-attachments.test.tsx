import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { expect, it, vi } from 'vitest';
import { EventAttachments } from './_event-attachments';

vi.mock('./_pdf-reader', () => ({
    HubPdfReader: ({ url, download }: { url: string; download: string }) => (
        <section aria-label="Document preview">
            <span>{url}</span>
            <a href={download}>Download original</a>
        </section>
    ),
}));
const pdf = {
    id: 12,
    name: 'Event guide.pdf',
    mime: 'application/pdf',
    size: 2048,
    url: '/company-hub/event-files/12',
    preview_url: '/company-hub/event-files/12?preview=1',
    download_url: '/company-hub/event-files/12',
};

it('opens the exact protected preview and returns focus while Office stays a download', async () => {
    render(
        <EventAttachments
            files={[
                pdf,
                {
                    ...pdf,
                    id: 13,
                    name: 'Agenda.docx',
                    mime: 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                    url: '/company-hub/event-files/13',
                    download_url: '/company-hub/event-files/13',
                    preview_url: null,
                },
            ]}
        />,
    );
    expect(
        screen.queryByRole('region', { name: 'Document preview' }),
    ).not.toBeInTheDocument();
    expect(
        screen.queryByRole('button', { name: 'Preview Agenda.docx' }),
    ).not.toBeInTheDocument();
    expect(
        screen.getByRole('link', { name: 'Download Agenda.docx' }),
    ).toHaveAttribute('href', '/company-hub/event-files/13');
    fireEvent.click(
        screen.getByRole('button', { name: 'Preview Event guide.pdf' }),
    );
    expect(
        screen.getByRole('heading', { name: 'Event guide.pdf' }),
    ).toHaveFocus();
    expect(
        screen.getByRole('region', { name: 'Document preview' }),
    ).toHaveTextContent(pdf.preview_url);
    expect(
        screen.getByRole('link', { name: 'Download original' }),
    ).toHaveAttribute('href', pdf.download_url);
    fireEvent.click(
        screen.getByRole('button', { name: 'Back to event files' }),
    );
    await waitFor(() =>
        expect(
            screen.getByRole('button', { name: 'Preview Event guide.pdf' }),
        ).toHaveFocus(),
    );
});

it('removes an open preview when its attachment is withdrawn', () => {
    const view = render(<EventAttachments files={[pdf]} />);
    fireEvent.click(
        screen.getByRole('button', { name: 'Preview Event guide.pdf' }),
    );
    view.rerender(<EventAttachments files={[]} />);
    expect(
        screen.queryByRole('region', { name: 'Document preview' }),
    ).not.toBeInTheDocument();
    expect(
        screen.queryByRole('region', { name: 'Event attachments' }),
    ).not.toBeInTheDocument();
});
