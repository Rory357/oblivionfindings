import { render, screen } from '@testing-library/react';
import type React from 'react';
import { describe, expect, it, vi } from 'vitest';

import { WorkspaceStrip } from './workspace-strip';

vi.mock('@inertiajs/react', () => ({
    Link: ({
        href,
        children,
        ...props
    }: {
        href: string;
        children: React.ReactNode;
    }) => (
        <a href={href} {...props}>
            {children}
        </a>
    ),
}));

describe('WorkspaceStrip', () => {
    it('renders the six operator destinations as navigation links', () => {
        render(
            <WorkspaceStrip
                current="/control-room/escalations"
                badges={{ '/control-room/alerts': 7 }}
            />,
        );

        const navigation = screen.getByRole('navigation', {
            name: 'Control Room workspace',
        });
        expect(navigation).toBeInTheDocument();
        expect(screen.getByRole('link', { name: 'Desk' })).toHaveAttribute(
            'href',
            '/control-room',
        );
        expect(
            screen.getByRole('link', { name: /Active alerts/ }),
        ).toHaveAttribute('href', '/control-room/alerts');
        expect(
            screen.getByRole('link', { name: 'Escalations' }),
        ).toHaveAttribute('aria-current', 'page');
        expect(
            screen.getByRole('link', { name: 'Safety handovers' }),
        ).toHaveAttribute('href', '/control-room/incidents');
        expect(screen.getByRole('link', { name: 'My queue' })).toHaveAttribute(
            'href',
            '/control-room/my-tasks',
        );
        expect(screen.getByRole('link', { name: 'Shifts' })).toHaveAttribute(
            'href',
            '/control-room/shifts',
        );
        expect(screen.queryByRole('tab')).not.toBeInTheDocument();
    });

    it('uses normal focusable links rather than simulated tab behaviour', () => {
        render(<WorkspaceStrip current="/control-room" />);

        const desk = screen.getByRole('link', { name: 'Desk' });
        expect(desk.className).toContain('focus-visible:ring-2');
        expect(desk).not.toHaveAttribute('role', 'tab');
    });
});
