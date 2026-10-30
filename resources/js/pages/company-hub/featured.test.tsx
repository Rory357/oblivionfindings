import { render, screen } from '@testing-library/react';
import type { AnchorHTMLAttributes } from 'react';
import { describe, expect, it, vi } from 'vitest';
import { HubBlocks } from './_blocks';
import { hubData, hubStory } from './_test-fixtures';
import type { HubSection } from './_types';

vi.mock('@inertiajs/react', () => ({
    Link: (props: AnchorHTMLAttributes<HTMLAnchorElement>) => <a {...props} />,
}));
const sections: HubSection[] = [
    {
        id: 'editorial',
        columns: 1,
        blocks: [
            {
                id: 'lead',
                type: 'featured',
                title: 'Retained private title',
                body: 'Retained private summary',
                story_id: 99,
                media_id: 99,
            },
            { id: 'news', type: 'news', title: 'Latest news' },
        ],
    },
];
describe('published editorial projection', () => {
    it('uses only the server-projected lead and respects a projected empty news pool', () => {
        render(
            <HubBlocks
                sections={sections}
                data={hubData({
                    pages: [{ ...hubStory, title: 'Unfiltered pool story' }],
                    blockData: {
                        lead: { featured: { lead: hubStory, supporting: [] } },
                        news: { news: [] },
                    },
                })}
                onEvent={() => {}}
            />,
        );
        expect(
            screen.getByRole('heading', { name: hubStory.title }),
        ).toBeVisible();
        expect(
            screen.getByRole('link', { name: new RegExp(hubStory.title) }),
        ).toHaveAttribute('href', `/company-hub/read/${hubStory.slug}`);
        expect(screen.queryByText(/Retained private/)).not.toBeInTheDocument();
        expect(
            screen.queryByText('Unfiltered pool story'),
        ).not.toBeInTheDocument();
        expect(
            screen.getByText(
                'Company stories will appear here as they are published.',
            ),
        ).toBeVisible();
    });
    it('does not rebuild an unavailable feature from raw snapshots or broad page pools', () => {
        render(
            <HubBlocks
                sections={[{ ...sections[0], blocks: [sections[0].blocks[0]] }]}
                data={hubData({
                    pages: [hubStory],
                    blockData: {
                        lead: { featured: { lead: null, supporting: [] } },
                    },
                })}
                onEvent={() => {}}
            />,
        );
        expect(
            screen.getByText('Publish a news story to feature it here.'),
        ).toBeVisible();
        expect(screen.queryByRole('link')).not.toBeInTheDocument();
        expect(screen.queryByText(/Retained private/)).not.toBeInTheDocument();
    });
});
