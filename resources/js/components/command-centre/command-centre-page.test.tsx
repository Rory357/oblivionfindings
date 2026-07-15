import { render, screen } from '@testing-library/react';
import { Activity } from 'lucide-react';
import type React from 'react';
import { describe, expect, it, vi } from 'vitest';

import { CommandCentrePage } from './command-centre-page';

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

describe('CommandCentrePage', () => {
    it('provides one shared hero contract for specialist pages', () => {
        render(
            <CommandCentrePage
                variant="compact"
                current="/control-room/reports"
                icon={Activity}
                title="Reports"
                description="Review Control Room performance."
                status="Reporting workspace"
                freshness="Updated 2 minutes ago"
                actions={<button type="button">Export report</button>}
                workflow={<div>Detect → record → govern</div>}
                footer={<div>Site filter</div>}
            >
                <div>Report content</div>
            </CommandCentrePage>,
        );

        expect(
            screen.getByRole('heading', { level: 1, name: 'Reports' }),
        ).toBeInTheDocument();
        expect(
            screen.getByText('Review Control Room performance.'),
        ).toBeInTheDocument();
        expect(screen.getByText('Reporting workspace')).toBeInTheDocument();
        expect(screen.getByText('Updated 2 minutes ago')).toBeInTheDocument();
        expect(
            screen.getByRole('button', { name: 'Export report' }),
        ).toBeInTheDocument();
        expect(
            screen.getByText('Detect → record → govern'),
        ).toBeInTheDocument();
        expect(screen.getByText('Site filter')).toBeInTheDocument();
        expect(screen.getByText('Report content')).toBeInTheDocument();
        expect(
            screen.getByRole('navigation', { name: 'Control Room workspace' }),
        ).toBeInTheDocument();
    });
});
