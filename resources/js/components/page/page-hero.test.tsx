import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import { Button } from '@/components/ui/button';

import { PageHero } from './page-hero';

describe('PageHero', () => {
    it('uses its available container width before switching to the three-column layout', () => {
        render(
            <PageHero
                title="Security & Devices estate"
                description="See what needs attention."
                stats={[{ label: 'Active findings', value: 2 }]}
                actions={<button type="button">All devices</button>}
            />,
        );

        const heading = screen.getByRole('heading', {
            name: 'Security & Devices estate',
        });
        const hero = heading.closest('[class~="@container"]');
        const layout = heading.parentElement?.parentElement;

        expect(hero).toBeInTheDocument();
        expect(layout).toHaveClass(
            'flex-col',
            '@5xl:flex-row',
            '@5xl:items-start',
        );
        expect(heading.parentElement).toHaveClass(
            'text-center',
            '@5xl:text-left',
        );
    });

    it('keeps dashboard identity, context, stats, and actions in the rich contract', () => {
        render(
            <PageHero
                pageType="dashboard"
                avatar={{ fallback: 'MS' }}
                title="A deliberately long dashboard title for a named support context"
                description="The context remains visible for safe decisions."
                stats={[{ label: 'Due now', value: 3 }]}
                actions={<Button type="button">Start priority task</Button>}
            />,
        );

        const heading = screen.getByRole('heading', {
            name: 'A deliberately long dashboard title for a named support context',
        });
        const hero = heading.closest('[data-page-hero]');

        expect(hero).toHaveAttribute('data-page-hero-type', 'dashboard');
        expect(hero).toHaveAttribute('data-page-hero-variant', 'hero');
        expect(hero).toHaveClass('@container');
        expect(screen.getByText('MS')).toBeInTheDocument();
        expect(screen.getByText('Due now')).toBeInTheDocument();
        expect(
            screen.getByRole('button', { name: 'Start priority task' }),
        ).toBeInTheDocument();
        expect(
            screen.getByRole('button', { name: 'Start priority task' })
                .parentElement,
        ).toHaveClass(
            '[&_[data-slot=button]]:min-h-11',
            '[&_[data-slot=button]]:min-w-11',
        );
    });

    it('uses the compact task contract for a long title with no hero action or duplicate stats', () => {
        render(
            <PageHero
                pageType="task"
                title="Integration alerts requiring review across multiple connected services"
                description="Monitor and triage alerts raised by external integrations."
                stats={[{ label: 'Open', value: 7 }]}
            />,
        );

        const heading = screen.getByRole('heading', {
            name: 'Integration alerts requiring review across multiple connected services',
        });
        const hero = heading.closest('[data-page-hero]');

        expect(hero).toHaveAttribute('data-page-hero-type', 'task');
        expect(hero).toHaveAttribute('data-page-hero-variant', 'compact');
        expect(hero).toHaveClass('flex', 'flex-col', 'gap-4');
        expect(hero).not.toHaveClass('@container');
        expect(screen.queryByText('Open')).not.toBeInTheDocument();
        expect(screen.queryByRole('button')).not.toBeInTheDocument();
    });

    it('keeps compact back and action controls keyboard-visible and touch-sized', () => {
        render(
            <PageHero
                pageType="task"
                backHref="/control-room"
                title="Integration alerts"
                actions={<Button type="button">Review alert</Button>}
            />,
        );

        expect(screen.getByRole('link', { name: 'Back' })).toHaveClass(
            'frontline-focus',
            'frontline-tap',
        );
        expect(
            screen.getByRole('button', { name: 'Review alert' }).parentElement,
        ).toHaveClass(
            'w-full',
            'md:w-auto',
            '[&_[data-slot=button]]:min-h-11',
            '[&_[data-slot=button]]:min-w-11',
        );
    });
});
