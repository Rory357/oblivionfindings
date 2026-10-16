import { fireEvent, render, screen, within } from '@testing-library/react';
import { expect, it, vi } from 'vitest';
import { EventGallery } from './_event-gallery';
import { EventStory } from './_event-story';
import type { HubEvent, HubEventPhoto } from './_types';

vi.mock('@inertiajs/react', () => ({
    Link: ({ children, href }: { children: React.ReactNode; href: string }) => (
        <a href={href}>{children}</a>
    ),
}));
const photos: HubEventPhoto[] = [
    {
        id: 3,
        name: 'garden.jpg',
        url: '/photos/3',
        title: 'Planting together',
        caption: 'A shared afternoon.',
        alt: 'Colleagues planting herbs',
        album: 'Community afternoons',
        shared_by: { name: 'Jamie', profile_url: '/company-hub/people/7' },
        shared_at: '2026-10-12T01:00:00Z',
    },
    {
        id: 5,
        name: 'welcome.jpg',
        url: '/photos/5',
        title: 'A warm welcome',
        alt: 'Welcoming a colleague',
        album: 'Our people',
        shared_by: null,
        shared_at: null,
    },
    {
        id: 8,
        name: 'catch-up.jpg',
        url: '/photos/8',
        title: 'Time to catch up',
        album: 'Community afternoons',
    },
];
it('shows authored titles, accessible descriptions, album filters and canonical order', () => {
    render(<EventGallery photos={photos} title="Community afternoon" />);
    expect(
        screen.getByRole('img', { name: 'Colleagues planting herbs' }),
    ).toBeVisible();
    fireEvent.click(
        screen.getByRole('button', {
            name: /^Community afternoons$/,
        }),
    );
    expect(
        screen.queryByRole('button', {
            name: /View event photo.*A warm welcome/,
        }),
    ).not.toBeInTheDocument();
    expect(
        screen
            .getAllByRole('button', { name: /View event photo/ })
            .map((element) => element.getAttribute('aria-label')),
    ).toEqual([
        'View event photo 1 of 2: Planting together',
        'View event photo 2 of 2: Time to catch up',
    ]);
});
it('opens a full photo with caption, permitted attribution, details and keyboard navigation', () => {
    render(<EventGallery photos={photos} title="Community afternoon" />);
    fireEvent.click(screen.getByRole('button', { name: /View event photo 1/ }));
    const dialog = screen.getByRole('dialog');
    expect(within(dialog).getByText('A shared afternoon.')).toBeVisible();
    expect(within(dialog).getByText(/12 October 2026/)).toBeVisible();
    expect(within(dialog).getByRole('link', { name: 'Jamie' })).toHaveAttribute(
        'href',
        '/company-hub/people/7',
    );
    fireEvent.click(
        within(dialog).getByRole('button', { name: 'Photo details' }),
    );
    expect(within(dialog).getByText('Colleagues planting herbs')).toBeVisible();
    fireEvent.keyDown(dialog, { key: 'ArrowRight' });
    expect(
        within(dialog).getByRole('heading', { name: 'A warm welcome' }),
    ).toBeVisible();
    expect(
        within(dialog).queryByText(/Photo shared by/),
    ).not.toBeInTheDocument();
    fireEvent.keyDown(dialog, { key: 'ArrowLeft' });
    fireEvent.keyDown(dialog, { key: 'ArrowLeft' });
    expect(
        within(dialog).getByRole('heading', { name: 'Time to catch up' }),
    ).toBeVisible();
    fireEvent.click(
        within(dialog).getByRole('button', { name: 'Back to recap' }),
    );
    expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
});
it('closes a removed photo immediately instead of showing another image at its old position', () => {
    const view = render(
        <EventGallery photos={photos} title="Community afternoon" />,
    );
    fireEvent.click(screen.getByRole('button', { name: /View event photo 2/ }));
    view.rerender(
        <EventGallery
            photos={[photos[0], photos[2]]}
            title="Community afternoon"
        />,
    );
    expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
});
it('resets an unavailable album and disables navigation for a single remaining photo', () => {
    const view = render(
        <EventGallery photos={photos} title="Community afternoon" />,
    );
    fireEvent.click(screen.getByRole('button', { name: /^Our people$/ }));
    view.rerender(
        <EventGallery photos={[photos[0]]} title="Community afternoon" />,
    );
    fireEvent.click(screen.getByRole('button', { name: /View event photo 1/ }));
    expect(screen.getByRole('button', { name: 'Next photo' })).toBeDisabled();
    expect(
        screen.getByRole('button', { name: 'Previous photo' }),
    ).toBeDisabled();
});
it('shows recap title only after the event and removes an open gallery when visibility changes', () => {
    const event = {
        id: 'event-1',
        title: 'Community afternoon',
        extendedProps: {
            hubContent: {
                past: true,
                recap_title: 'An afternoon together',
                recap: 'Thank you for coming.',
                gallery: photos,
                agenda: '',
                host: null,
                story: null,
            },
        },
    } as HubEvent;
    const view = render(<EventStory event={event} />);
    expect(
        screen.getByRole('heading', { name: 'An afternoon together' }),
    ).toBeVisible();
    fireEvent.click(screen.getByRole('button', { name: /View event photo 1/ }));
    view.rerender(
        <EventStory
            event={{
                ...event,
                extendedProps: {
                    ...event.extendedProps,
                    hubContent: {
                        ...event.extendedProps.hubContent!,
                        past: false,
                    },
                },
            }}
        />,
    );
    expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
    expect(screen.queryByText('An afternoon together')).not.toBeInTheDocument();
});
