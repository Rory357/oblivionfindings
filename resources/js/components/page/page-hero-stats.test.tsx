import { render, screen } from '@testing-library/react';
import type React from 'react';
import { describe, expect, it, vi } from 'vitest';

import { PageHeroStats } from './page-hero-stats';

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

describe('PageHeroStats', () => {
    it('applies semantic tone classes to inline stat values', () => {
        render(
            <PageHeroStats
                stats={[
                    {
                        label: 'Open',
                        value: 4,
                        hideOnMobile: false,
                        tone: 'warning',
                    } as any,
                    {
                        label: 'Meds',
                        value: '1/3',
                        hideOnMobile: false,
                        tone: 'critical',
                    } as any,
                ]}
            />,
        );

        expect(screen.getByText('4')).toHaveClass(
            'text-status-warning-foreground',
        );
        expect(screen.getByText('1/3')).toHaveClass(
            'text-status-critical-foreground',
        );
    });
});
