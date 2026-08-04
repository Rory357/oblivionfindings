import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

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
});
