import { fireEvent, render, screen, within } from '@testing-library/react';
import type { AnchorHTMLAttributes } from 'react';
import { describe, expect, it, vi } from 'vitest';
import { BlockLibraryPicker, blockSetupIssue } from './_block-picker';
import { createBlock } from './_layout';
import { hubData, hubPerson, hubStory } from './_test-fixtures';
import type { HubSection } from './_types';

vi.mock('@inertiajs/react', () => ({
    Link: (props: AnchorHTMLAttributes<HTMLAnchorElement>) => <a {...props} />,
    router: { on: () => vi.fn() },
}));
vi.mock('./_media-picker', () => ({
    MediaUpload: () => <p>Choose page media</p>,
}));
const section: HubSection = {
    id: 'intro',
    title: 'Welcome',
    columns: 2,
    blocks: [],
};
function picker(patch: Partial<Parameters<typeof BlockLibraryPicker>[0]> = {}) {
    const props = {
        section,
        data: hubData(),
        pageId: 4,
        onMedia: vi.fn(),
        onAdd: vi.fn(),
        onClose: vi.fn(),
        onDirtyChange: vi.fn(),
        ...patch,
    };
    return { ...render(<BlockLibraryPicker {...props} />), props };
}
const choose = (name: string) =>
    fireEvent.click(screen.getByRole('button', { name: `Configure ${name}` }));

describe('block setup workspace', () => {
    it('rejects invalid field bounds even for unfinished insertion while permitting missing content', () => {
        const data = hubData();
        for (const incomplete of [false, true]) {
            expect(
                blockSetupIssue(
                    {
                        ...createBlock('links'),
                        links: [
                            { id: 'a', title: 'x'.repeat(101), url: '/my-day' },
                        ],
                    },
                    data,
                    undefined,
                    incomplete,
                ),
            ).toMatch(/100 characters/);
            expect(
                blockSetupIssue(
                    {
                        ...createBlock('links'),
                        links: Array.from({ length: 25 }, (_, id) => ({
                            id: String(id),
                            title: 'Link',
                            url: '/my-day',
                        })),
                    },
                    data,
                    undefined,
                    incomplete,
                ),
            ).toMatch(/24 links/);
            expect(
                blockSetupIssue(
                    { ...createBlock('text'), body: 'x'.repeat(20001) },
                    data,
                    undefined,
                    incomplete,
                ),
            ).toMatch(/20,000/);
            expect(
                blockSetupIssue(
                    { ...createBlock('image'), alt: 'x'.repeat(301) },
                    data,
                    undefined,
                    incomplete,
                ),
            ).toMatch(/300 characters/);
        }
        expect(
            blockSetupIssue(createBlock('text'), data, undefined, true),
        ).toBeNull();
    });
    it.each([{ sourceTeamIds: [] }, { sourceTeamIds: [3] }])(
        'keeps People previews inside the department teams %j',
        async ({ sourceTeamIds }) => {
            picker({
                sourceTeamIds,
                data: hubData({
                    people: [
                        hubPerson,
                        {
                            ...hubPerson,
                            id: 22,
                            user_id: 12,
                            name: 'Another team colleague',
                            department_id: 9,
                        },
                    ],
                    interests: {
                        actor_id: 1,
                        version: 1,
                        topics: [],
                        teams: [],
                        available_topics: [],
                        available_teams: [
                            { id: 3, name: 'Learning' },
                            { id: 9, name: 'Other' },
                        ],
                    },
                }),
            });
            choose('Staff directory');
            fireEvent.keyDown(
                screen.getByRole('combobox', { name: 'Directory display' }),
                { key: 'ArrowDown' },
            );
            fireEvent.click(
                await screen.findByRole('option', { name: 'People cards' }),
            );
            const preview = within(screen.getByLabelText('Block preview'));
            expect(
                preview.queryByText('Another team colleague'),
            ).not.toBeInTheDocument();
            if (sourceTeamIds.length)
                expect(preview.getByText(hubPerson.name)).toBeVisible();
            else
                expect(
                    preview.queryByText(hubPerson.name),
                ).not.toBeInTheDocument();
        },
    );
    it('searches names and purposes, combines categories and clears empty results', () => {
        picker();
        expect(
            screen.getByRole('region', { name: 'Block library' }),
        ).toBeVisible();
        expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
        fireEvent.change(
            screen.getByRole('textbox', { name: 'Search blocks' }),
            { target: { value: 'photograph' } },
        );
        expect(
            screen.getByRole('button', { name: 'Configure Image' }),
        ).toBeVisible();
        expect(
            screen.queryByRole('button', { name: 'Configure Text & story' }),
        ).not.toBeInTheDocument();
        fireEvent.click(
            screen.getByRole('button', { name: 'Everyday essentials' }),
        );
        expect(
            screen.getByRole('heading', { name: 'No blocks match' }),
        ).toBeVisible();
        fireEvent.click(screen.getByRole('button', { name: 'Clear filters' }));
        expect(
            screen.getByRole('button', { name: 'Configure Text & story' }),
        ).toBeVisible();
    });

    it('configures authored content with a live preview and inserts only on explicit Add', () => {
        const { props } = picker();
        choose('Text & story');
        expect(props.onAdd).not.toHaveBeenCalled();
        expect(
            screen.getByRole('heading', { name: 'Configure Text & story' }),
        ).toHaveFocus();
        fireEvent.change(screen.getByRole('textbox', { name: 'Heading' }), {
            target: { value: 'A warm welcome' },
        });
        const story = screen.getByRole('textbox', { name: 'Story' });
        story.textContent = 'Here is what our team is planning.';
        fireEvent.input(story);
        const preview = screen.getByLabelText('Block preview');
        expect(within(preview).getByText('A warm welcome')).toBeVisible();
        expect(
            within(preview).getByText('Here is what our team is planning.'),
        ).toBeVisible();
        expect(props.onDirtyChange).toHaveBeenLastCalledWith(true);
        fireEvent.click(screen.getByRole('button', { name: 'Add to page' }));
        fireEvent.click(screen.getByRole('button', { name: 'Add to page' }));
        expect(props.onAdd).toHaveBeenCalledOnce();
        expect(props.onAdd).toHaveBeenCalledWith(
            expect.objectContaining({
                type: 'text',
                title: 'A warm welcome',
                body: 'Here is what our team is planning.',
            }),
        );
    });

    it('explains incomplete content while retaining an explicit unfinished insertion path', () => {
        const { props } = picker();
        choose('Text & story');
        fireEvent.click(screen.getByRole('button', { name: 'Add to page' }));
        expect(screen.getByRole('alert')).toHaveTextContent(
            'Add the words you want staff to read.',
        );
        expect(props.onAdd).not.toHaveBeenCalled();
        fireEvent.click(
            screen.getByRole('button', { name: 'Add unconfigured block' }),
        );
        expect(props.onAdd).toHaveBeenCalledWith(
            expect.objectContaining({ type: 'text', body: '' }),
        );
    });

    it('protects configured writing on return and cancel, without changing the existing page', () => {
        const { props } = picker();
        choose('Custom callout');
        fireEvent.change(screen.getByLabelText('Message'), {
            target: { value: 'Please keep this draft.' },
        });
        fireEvent.click(screen.getByRole('button', { name: 'All blocks' }));
        expect(screen.getByRole('alert')).toHaveTextContent(
            'Discard this block setup?',
        );
        expect(
            screen.getByRole('button', { name: 'Add to page' }),
        ).toBeDisabled();
        fireEvent.click(
            screen.getByRole('button', { name: 'Keep configuring' }),
        );
        expect(screen.getByLabelText('Message')).toHaveValue(
            'Please keep this draft.',
        );
        fireEvent.click(screen.getByRole('button', { name: 'Close library' }));
        fireEvent.click(screen.getByRole('button', { name: 'Discard setup' }));
        expect(props.onClose).toHaveBeenCalledOnce();
        expect(props.onDirtyChange).toHaveBeenLastCalledWith(false);
        expect(props.onAdd).not.toHaveBeenCalled();
        expect(section.blocks).toEqual([]);
    });

    it('uses department-scoped published stories and rejects a withdrawn configured selection', async () => {
        const data = hubData({
            pages: [
                hubStory,
                { ...hubStory, id: 51, title: 'Another team', team_ids: [9] },
            ],
            interests: {
                actor_id: 1,
                version: 1,
                topics: [],
                teams: [],
                available_topics: [],
                available_teams: [
                    { id: 3, name: 'Learning' },
                    { id: 9, name: 'Other' },
                ],
            },
        });
        const view = picker({ data, sourceTeamIds: [3] });
        choose('Featured stories');
        fireEvent.keyDown(
            screen.getByRole('combobox', { name: 'Featured story' }),
            { key: 'ArrowDown' },
        );
        expect(
            await screen.findByRole('option', { name: hubStory.title }),
        ).toBeInTheDocument();
        expect(
            screen.queryByRole('option', { name: 'Another team' }),
        ).not.toBeInTheDocument();
        fireEvent.click(screen.getByRole('option', { name: hubStory.title }));
        view.rerender(
            <BlockLibraryPicker
                {...view.props}
                data={{ ...data, pages: [] }}
            />,
        );
        fireEvent.click(screen.getByRole('button', { name: 'Add to page' }));
        expect(screen.getByRole('alert')).toHaveTextContent(
            'Choose a currently available published story',
        );
        expect(view.props.onAdd).not.toHaveBeenCalled();
    });

    it('keeps preview destinations inactive and allows an empty authorised automatic feed', () => {
        const { props } = picker();
        choose('Staff services');
        expect(
            screen.getByLabelText('Block preview').querySelector('[inert]'),
        ).not.toBeNull();
        fireEvent.click(screen.getByRole('button', { name: 'Add to page' }));
        expect(props.onAdd).toHaveBeenCalledWith(
            expect.objectContaining({ type: 'services' }),
        );
    });

    it('stops insertion when the target section reaches its capacity', () => {
        const view = picker();
        choose('Important notices');
        view.rerender(
            <BlockLibraryPicker
                {...view.props}
                section={{
                    ...section,
                    blocks: Array.from({ length: 12 }, () =>
                        createBlock('text'),
                    ),
                }}
            />,
        );
        expect(screen.getByRole('status')).toHaveTextContent(
            'This section has 12 blocks.',
        );
        expect(
            screen.getByRole('button', { name: 'Add to page' }),
        ).toBeDisabled();
        expect(
            screen.getByRole('button', { name: 'Add unconfigured block' }),
        ).toBeDisabled();
        expect(view.props.onAdd).not.toHaveBeenCalled();
    });

    it('checks poll choices, media and unsafe destinations without mistaking empty feeds for missing setup', () => {
        const data = hubData();
        expect(
            blockSetupIssue(
                { ...createBlock('poll'), options: ['Team', ' team '] },
                data,
            ),
        ).toMatch(/different answers/);
        expect(blockSetupIssue(createBlock('image'), data)).toMatch(
            /Choose an image/,
        );
        expect(
            blockSetupIssue(
                { ...createBlock('button'), url: 'javascript:alert(1)' },
                data,
            ),
        ).toMatch(/valid HTTPS/);
        expect(
            blockSetupIssue(
                {
                    ...createBlock('button'),
                    url: 'https://name:pass@example.org/',
                },
                data,
            ),
        ).toMatch(/valid HTTPS/);
        expect(
            blockSetupIssue(
                { ...createBlock('video'), url: 'https://example.org/video' },
                data,
            ),
        ).toMatch(/YouTube|youtube/);
        expect(
            blockSetupIssue(
                {
                    ...createBlock('links'),
                    links: [{ id: 'a', title: 'Info', url: '//other.example' }],
                },
                data,
            ),
        ).toMatch(/valid HTTPS/);
        expect(blockSetupIssue(createBlock('news'), data)).toBeNull();
        expect(blockSetupIssue(createBlock('featured'), data)).toBeNull();
    });
});
