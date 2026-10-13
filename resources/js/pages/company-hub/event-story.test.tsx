import { fireEvent, render, screen, within } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import { EventStory } from './_event-story';
import type { HubEvent } from './_types';

vi.mock('@inertiajs/react', () => ({
    Link: ({ children, href }: { children: React.ReactNode; href: string }) => (
        <a href={href}>{children}</a>
    ),
}));
const event = {
    title: 'Our gathering',
    extendedProps: {
        hubContent: {
            host: { id: 4, name: 'Sam Jones' },
            agenda: 'Welcome\nShare ideas',
            recap: 'A useful afternoon',
            past: true,
            editor: null,
            cover: null,
            gallery: [
                { id: 1, name: 'one.png', url: '/company-hub/event-files/1' },
                { id: 2, name: 'two.png', url: '/company-hub/event-files/2' },
            ],
            story: { title: 'Our story', url: '/company-hub/read/our-story' },
        },
    },
} as HubEvent;
describe('Event story', () => {
    it('opens the selected photo and supports keyboard navigation and the related story', () => {
        render(<EventStory event={event} />);
        expect(screen.getByText('Sam Jones')).toBeInTheDocument();
        expect(screen.getAllByRole('listitem')).toHaveLength(2);
        expect(
            screen.getByRole('link', { name: 'Read story: Our story' }),
        ).toHaveAttribute('href', '/company-hub/read/our-story');
        fireEvent.click(
            screen.getByRole('button', { name: 'View event photo 2 of 2' }),
        );
        const dialog = screen.getByRole('dialog');
        expect(within(dialog).getByRole('img')).toHaveAttribute(
            'src',
            '/company-hub/event-files/2',
        );
        fireEvent.keyDown(dialog, { key: 'ArrowLeft' });
        expect(within(dialog).getByRole('img')).toHaveAttribute(
            'src',
            '/company-hub/event-files/1',
        );
        fireEvent.click(
            within(dialog).getByRole('button', { name: 'Next photo' }),
        );
        expect(within(dialog).getByRole('img')).toHaveAttribute(
            'src',
            '/company-hub/event-files/2',
        );
    });
    it('does not render a recap or gallery for a future event', () => {
        render(
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
        expect(
            screen.queryByText('A useful afternoon'),
        ).not.toBeInTheDocument();
        expect(
            screen.queryByRole('button', { name: /View event photo/ }),
        ).not.toBeInTheDocument();
        expect(screen.getByText('Welcome')).toBeInTheDocument();
    });
});
