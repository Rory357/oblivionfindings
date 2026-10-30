import { fireEvent, render, screen, within } from '@testing-library/react';
import type { AnchorHTMLAttributes } from 'react';
import { describe, expect, it, vi } from 'vitest';
import { HubBlocks } from './_blocks';
import { CampaignBlock } from './_campaign-block';
import { DynamicBlock } from './_dynamic-blocks';
import { KnowledgeReader } from './_knowledge';
import { hubContent, hubData, hubPerson, hubStory } from './_test-fixtures';
vi.mock('@inertiajs/react', () => ({
    Link: (props: AnchorHTMLAttributes<HTMLAnchorElement>) => <a {...props} />,
    router: { post: vi.fn() },
    useForm: () => ({ data: { body: '' }, errors: {} }),
}));

describe('dynamic designer blocks', () => {
    it('renders canonical shared milestones and never widens an empty or absent campaign projection', () => {
        const page = {
            ...hubStory,
            kind: 'campaign',
            campaign_progress: { total: 5, done: 2, ends_on: '2026-10-30' },
        };
        const block = {
            id: 'campaign',
            type: 'campaign',
            title: 'Our initiatives',
        };
        const { rerender } = render(
            <CampaignBlock
                block={block}
                data={hubData({
                    pages: [page],
                    blockData: { campaign: { campaigns: [page] } },
                })}
            />,
        );
        expect(screen.getByRole('progressbar')).toHaveAttribute(
            'aria-valuenow',
            '2',
        );
        expect(screen.getByText('2 of 5 milestones complete')).toBeVisible();
        expect(
            screen.getByRole('link', { name: 'Learning week' }),
        ).toHaveAttribute('href', '/company-hub/read/learning-week');
        rerender(
            <CampaignBlock
                block={block}
                data={hubData({
                    pages: [page],
                    blockData: { campaign: { campaigns: [] } },
                })}
            />,
        );
        expect(screen.queryByText('Learning week')).not.toBeInTheDocument();
        rerender(
            <CampaignBlock block={block} data={hubData({ pages: [page] })} />,
        );
        expect(
            screen.getByText(
                'No published campaigns match this selection yet.',
            ),
        ).toBeVisible();
    });
    it('removes hidden blocks and empty sections from preview without mutating the full design', () => {
        const sections = [
            {
                id: 'private',
                columns: 1,
                blocks: [
                    {
                        id: 'secret',
                        type: 'text',
                        title: 'Private draft',
                        body: 'Not for staff',
                        hidden: true,
                    },
                ],
            },
            {
                ...hubContent.sections[0],
                blocks: [
                    { ...hubContent.sections[0].blocks[0], min_height: 340 },
                ],
            },
        ];
        const original = structuredClone(sections);
        render(
            <KnowledgeReader content={{ ...hubContent, sections }} preview>
                <HubBlocks
                    sections={sections}
                    data={hubData()}
                    onEvent={vi.fn()}
                />
            </KnowledgeReader>,
        );
        expect(screen.queryByText('Not for staff')).not.toBeInTheDocument();
        expect(
            within(
                screen.getByRole('navigation', { name: 'Guide contents' }),
            ).queryByRole('link', { name: 'Private draft' }),
        ).not.toBeInTheDocument();
        expect(
            screen.getByRole('region', { name: 'About this initiative' }),
        ).toHaveStyle({ minHeight: '340px' });
        expect(sections).toEqual(original);
    });
    it('uses the server authorised people pool and searches it without broadening an empty result', () => {
        const block = {
            id: 'finder',
            type: 'people',
            title: 'Find a colleague',
            mode: 'finder' as const,
        };
        const { rerender } = render(
            <DynamicBlock
                block={block}
                data={hubData({
                    people: [
                        { ...hubPerson, id: 22, name: 'Outside colleague' },
                    ],
                    blockData: { finder: { people: [hubPerson] } },
                })}
            />,
        );
        expect(screen.queryByText('Outside colleague')).not.toBeInTheDocument();
        fireEvent.change(screen.getByRole('textbox'), {
            target: { value: 'Learning' },
        });
        expect(screen.getByText('Aroha Bennett')).toBeVisible();
        rerender(
            <DynamicBlock
                block={block}
                data={hubData({
                    people: [hubPerson],
                    blockData: { finder: { people: [] } },
                })}
            />,
        );
        expect(screen.queryByText('Aroha Bennett')).not.toBeInTheDocument();
    });
    it('uses canonical service destinations and applies team choices when previewing unsaved blocks', () => {
        const data = hubData({
            searchServices: [
                {
                    id: 1,
                    name: 'IT help',
                    category: 'Support',
                    description: 'Get help',
                    url: '/it/requests/create',
                },
            ],
            pages: [
                { ...hubStory, kind: 'department' },
                {
                    ...hubStory,
                    id: 52,
                    title: 'Another team',
                    kind: 'department',
                    team_ids: [4],
                },
            ],
        });
        const { rerender } = render(
            <DynamicBlock
                block={{ id: 's', type: 'services', title: 'Help' }}
                data={data}
            />,
        );
        expect(screen.getByRole('link', { name: /IT help/ })).toHaveAttribute(
            'href',
            '/it/requests/create',
        );
        rerender(
            <DynamicBlock
                block={{
                    id: 't',
                    type: 'teams',
                    title: 'Teams',
                    team_ids: [3],
                }}
                data={data}
            />,
        );
        expect(screen.queryByText('Another team')).not.toBeInTheDocument();
        expect(screen.getByText('Learning week')).toBeVisible();
    });
    it('keeps the finder compact and searches the full authorised pool before limiting results', () => {
        render(
            <DynamicBlock
                block={{
                    id: 'finder',
                    type: 'people',
                    title: 'People',
                    mode: 'finder',
                    limit: 1,
                }}
                data={hubData({
                    people: [
                        hubPerson,
                        {
                            ...hubPerson,
                            id: 22,
                            name: 'Mia Chen',
                            position: 'Safety adviser',
                            department: 'Operations',
                        },
                    ],
                })}
            />,
        );
        expect(screen.queryByText('Aroha Bennett')).not.toBeInTheDocument();
        expect(screen.queryByText('Mia Chen')).not.toBeInTheDocument();
        fireEvent.change(
            screen.getByRole('textbox', { name: 'Search colleagues' }),
            { target: { value: 'Safety' } },
        );
        expect(screen.getByRole('link', { name: /Mia Chen/ })).toHaveAttribute(
            'href',
            '/company-hub/people/22',
        );
        expect(screen.getByRole('status')).toHaveTextContent('1 colleague');
        expect(
            screen.getByRole('link', { name: /Explore the directory/ }),
        ).toHaveAttribute('href', '/company-hub?view=people');
    });
    it('renders limited people rows inside a published block with canonical profile links', () => {
        render(
            <HubBlocks
                sections={[
                    {
                        id: 'people',
                        columns: 1,
                        blocks: [
                            {
                                id: 'colleagues',
                                type: 'people',
                                title: 'Our colleagues',
                                limit: 1,
                            },
                        ],
                    },
                ]}
                data={hubData({
                    people: [
                        hubPerson,
                        { ...hubPerson, id: 22, name: 'Mia Chen' },
                    ],
                })}
                onEvent={vi.fn()}
            />,
        );
        expect(
            screen.getByRole('link', { name: /Aroha Bennett/ }),
        ).toHaveAttribute('href', '/company-hub/people/21');
        expect(screen.queryByText('Mia Chen')).not.toBeInTheDocument();
        expect(screen.getByRole('status')).toHaveTextContent(
            'Showing 1 of 2 colleagues',
        );
        expect(screen.queryByRole('textbox')).not.toBeInTheDocument();
    });
    it('uses only currently projected team photographs and honours an explicitly empty team pool', () => {
        const block = { id: 'teams', type: 'teams', title: 'Our teams' };
        const page = {
            ...hubStory,
            kind: 'department',
            cover_url: '/company-hub/media/91',
        };
        const { container, rerender } = render(
            <DynamicBlock
                block={block}
                data={hubData({
                    pages: [page],
                    blockData: { teams: { teams: [page] } },
                })}
            />,
        );
        expect(container.querySelector('img')).toHaveAttribute(
            'src',
            '/company-hub/media/91',
        );
        expect(
            screen.getByRole('link', { name: /Learning week/ }),
        ).toHaveAttribute('href', '/company-hub/read/learning-week');
        rerender(
            <DynamicBlock
                block={block}
                data={hubData({
                    pages: [page],
                    blockData: { teams: { teams: [] } },
                })}
            />,
        );
        expect(container.querySelector('img')).not.toBeInTheDocument();
        expect(screen.queryByText('Learning week')).not.toBeInTheDocument();
        expect(
            screen.getByText('No published team spaces match this selection.'),
        ).toBeVisible();
    });
});
