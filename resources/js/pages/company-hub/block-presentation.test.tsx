import { fireEvent, render, screen } from '@testing-library/react';
import { useState, type AnchorHTMLAttributes } from 'react';
import { describe, expect, it, vi } from 'vitest';
import { BlockContentSettings } from './_block-content-settings';
import { BlockEditor } from './_block-editor';
import { BlockContent } from './_blocks';
import { hubData, hubStory } from './_test-fixtures';
import type { HubBlock } from './_types';
vi.mock('@inertiajs/react', () => ({
    Link: (props: AnchorHTMLAttributes<HTMLAnchorElement>) => <a {...props} />,
    router: { on: () => vi.fn() },
}));
vi.mock('./_media-picker', () => ({ MediaUpload: () => null }));
describe('authored block presentation', () => {
    it('retains an unavailable featured selection until explicitly returning to automatic stories', async () => {
        const change = vi.fn();
        render(
            <BlockContentSettings
                block={{
                    id: 'feature',
                    type: 'featured',
                    title: 'Saved heading',
                    story_id: 70,
                    body: 'Saved message',
                    media_id: 9,
                }}
                data={hubData()}
                pageId={4}
                onChange={change}
                onMedia={vi.fn()}
            />,
        );
        const control = screen.getByRole('combobox', {
            name: 'Featured story',
        });
        expect(control).toHaveTextContent('Selected story is unavailable');
        expect(change).not.toHaveBeenCalled();
        fireEvent.keyDown(control, { key: 'ArrowDown' });
        expect(
            await screen.findByRole('option', {
                name: 'Selected story is unavailable',
            }),
        ).toHaveAttribute('aria-disabled', 'true');
        fireEvent.click(
            screen.getByRole('option', { name: 'Latest available story' }),
        );
        expect(change).toHaveBeenCalledExactlyOnceWith({
            story_id: null,
            title: '',
            body: '',
            media_id: null,
            alt: '',
        });
    });
    it('fills an exact company-page link from its numeric identity without persisting the chooser action', async () => {
        const change = vi.fn();
        const page = {
            ...hubStory,
            id: 72,
            title: 'P'.repeat(120),
            summary: 'S'.repeat(220),
        };
        render(
            <BlockEditor
                block={{
                    id: 'links',
                    type: 'links',
                    title: 'Places',
                    links: [{ id: 'link', title: 'My label', url: '/my-day' }],
                }}
                data={hubData({ pages: [page] })}
                pageId={4}
                onChange={change}
                onMedia={vi.fn()}
                onMove={vi.fn()}
                onRemove={vi.fn()}
                first
                last
            />,
        );
        const control = screen.getByRole('combobox', {
            name: 'Link 1 company page',
        });
        expect(control).toHaveTextContent('Choose a company page…');
        expect(change).not.toHaveBeenCalled();
        fireEvent.keyDown(control, { key: 'ArrowDown' });
        fireEvent.click(
            await screen.findByRole('option', { name: page.title }),
        );
        expect(change).toHaveBeenCalledExactlyOnceWith({
            links: [
                {
                    id: 'link',
                    title: 'P'.repeat(100),
                    description: 'S'.repeat(200),
                    url: '/company-hub/read/learning-week',
                },
            ],
        });
        expect(control).toHaveTextContent('Choose a company page…');
    });
    it('switches resources between list and cards without widening the authorised selection or changing destinations', () => {
        const data = hubData({
            pages: [{ ...hubStory, id: 90, title: 'Outside selection' }],
            blockData: {
                resources: {
                    resources: [
                        {
                            ...hubStory,
                            kind: 'knowledge',
                            resource_url: '/company-hub/read/learning-week',
                        },
                    ],
                },
            },
        });
        function Designer() {
            const [block, setBlock] = useState<HubBlock>({
                id: 'resources',
                type: 'resources',
                title: 'Resources',
            });
            return (
                <>
                    <BlockContentSettings
                        block={block}
                        data={data}
                        pageId={4}
                        onChange={(patch) =>
                            setBlock((old) => ({ ...old, ...patch }))
                        }
                        onMedia={vi.fn()}
                    />
                    <BlockContent block={block} data={data} onEvent={vi.fn()} />
                </>
            );
        }
        render(<Designer />);
        expect(
            screen.getByRole('table', { name: 'Company resources' }),
        ).toBeVisible();
        fireEvent.keyDown(
            screen.getByRole('combobox', { name: 'Presentation' }),
            { key: 'ArrowDown' },
        );
        fireEvent.click(screen.getByRole('option', { name: 'Cards' }));
        expect(screen.queryByRole('table')).not.toBeInTheDocument();
        expect(
            screen.getByRole('link', { name: /Learning week/ }),
        ).toHaveAttribute('href', '/company-hub/read/learning-week');
        expect(screen.queryByText('Outside selection')).not.toBeInTheDocument();
    });
    it.each(['file', 'video'])(
        'renders the authored %s description with its canonical destination',
        (type) => {
            const block = {
                id: type,
                type,
                title: 'Welcome',
                body: 'Read this before your first shift.',
                media_id: 12,
                url: 'https://example.org/welcome',
            };
            render(
                <BlockContent
                    block={block}
                    data={hubData()}
                    onEvent={vi.fn()}
                />,
            );
            expect(screen.getByText(block.body)).toBeVisible();
            expect(screen.getByRole('link')).toHaveAttribute(
                'href',
                type === 'file'
                    ? '/company-hub/media/12?download=1'
                    : block.url,
            );
        },
    );
    it('opens the stable document reader and exact current edition from a news attachment', () => {
        render(
            <BlockContent
                block={{
                    id: 'file',
                    type: 'file',
                    title: 'Team briefing',
                    media_id: 12,
                }}
                data={hubData({
                    resourceItems: [
                        {
                            ...hubStory,
                            kind: 'news',
                            title: 'Team briefing',
                            media_id: 12,
                            document_id: 7,
                            reader_url: '/company-hub/documents/7',
                            filename: 'briefing.pdf',
                            size: 1024,
                            document_file_version: 3,
                        },
                    ],
                })}
                onEvent={vi.fn()}
            />,
        );
        expect(
            screen.getByRole('link', { name: 'Read document' }),
        ).toHaveAttribute('href', '/company-hub/documents/7');
        expect(
            screen.getByRole('link', { name: /Download Team briefing/ }),
        ).toHaveAttribute('href', '/company-hub/media/12?download=1');
        expect(screen.getByText(/briefing.pdf.*Version 3/)).toBeVisible();
    });
    it('limits the notice block without losing acknowledgement links', () => {
        const data = hubData({
            announcements: [1, 2, 3].map((id) => ({
                id,
                title: `Notice ${id}`,
                content: 'An important update.',
                priority: 'normal',
                requires_acknowledgement: true,
            })),
        });
        render(
            <BlockContent
                block={{
                    id: 'notices',
                    type: 'notices',
                    title: 'Notices',
                    limit: 2,
                }}
                data={data}
                onEvent={vi.fn()}
            />,
        );
        expect(screen.getAllByRole('link')).toHaveLength(2);
        expect(screen.getByRole('link', { name: /Notice 2/ })).toHaveAttribute(
            'href',
            '/company-hub?view=updates&announcement=2',
        );
        expect(screen.queryByText('Notice 3')).not.toBeInTheDocument();
    });
});
