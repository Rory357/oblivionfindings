import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { beforeEach, expect, it, vi } from 'vitest';
import { SectionPresets, type SectionPreset } from './_presets';
import type { HubSection } from './_types';

const { request } = vi.hoisted(() => ({ request: vi.fn() }));
vi.mock('./_api', () => ({ hubRequest: request }));
const section: HubSection = {
    id: 'welcome',
    title: 'Welcome',
    columns: 1,
    blocks: [
        {
            id: 'text',
            type: 'text',
            title: 'Welcome',
            body: 'Useful starting points.',
        },
    ],
};
const shared: SectionPreset = {
    id: 1,
    name: 'Team introduction',
    scope: 'shared',
    section,
    version: 4,
    can_remove: true,
};
const personal: SectionPreset = {
    ...shared,
    id: 2,
    name: 'My layout',
    scope: 'personal',
};

beforeEach(() => request.mockReset());
function choose(label: string, option: string) {
    fireEvent.keyDown(screen.getByRole('combobox', { name: label }), {
        key: 'ArrowDown',
    });
    fireEvent.click(screen.getByRole('option', { name: option }));
}
async function open(
    props: Partial<React.ComponentProps<typeof SectionPresets>> = {},
) {
    const onInsert = vi.fn();
    render(
        <SectionPresets
            pageId={5}
            selected={section}
            full={false}
            onInsert={onInsert}
            {...props}
        />,
    );
    fireEvent.click(screen.getByText('Section library', { exact: true }));
    await waitFor(() =>
        expect(
            screen.queryByText('Loading your section library…'),
        ).not.toBeInTheDocument(),
    );
    return onInsert;
}

it('rechecks access before inserting a fresh independent copy of a shared section', async () => {
    request
        .mockResolvedValueOnce({ presets: [shared], can_share: true })
        .mockResolvedValueOnce({ section });
    const onInsert = await open();
    fireEvent.click(
        screen.getByRole('button', { name: 'Insert Team introduction' }),
    );
    await waitFor(() => expect(onInsert).toHaveBeenCalledOnce());
    expect(request).toHaveBeenLastCalledWith(
        '/company-hub/manage/5/presets/1/use',
        'POST',
        { version: 4 },
    );
    const copy = onInsert.mock.calls[0][0];
    expect(copy.id).not.toBe(section.id);
    expect(copy.blocks[0].id).not.toBe(section.blocks[0].id);
    copy.blocks[0].body = 'Changed';
    expect(section.blocks[0].body).toBe('Useful starting points.');
});

it('keeps the canvas unchanged when section access has changed', async () => {
    request
        .mockResolvedValueOnce({ presets: [shared], can_share: true })
        .mockRejectedValueOnce(
            new Error('This section is no longer available.'),
        );
    const onInsert = await open();
    fireEvent.click(
        screen.getByRole('button', { name: 'Insert Team introduction' }),
    );
    expect(await screen.findByRole('alert')).toHaveTextContent(
        'no longer available',
    );
    expect(onInsert).not.toHaveBeenCalled();
    expect(
        screen.getByRole('button', { name: 'Refresh section library' }),
    ).toBeEnabled();
});

it('inserts into the latest canvas after the access check finishes', async () => {
    let finish!: (value: { section: HubSection }) => void;
    request
        .mockResolvedValueOnce({ presets: [shared], can_share: true })
        .mockImplementationOnce(
            () =>
                new Promise((resolve) => {
                    finish = resolve;
                }),
        );
    const original = vi.fn(),
        latest = vi.fn();
    const view = render(
        <SectionPresets
            pageId={5}
            selected={section}
            full={false}
            onInsert={original}
        />,
    );
    fireEvent.click(screen.getByText('Section library', { exact: true }));
    fireEvent.click(
        await screen.findByRole('button', { name: 'Insert Team introduction' }),
    );
    view.rerender(
        <SectionPresets
            pageId={5}
            selected={section}
            full={false}
            onInsert={latest}
        />,
    );
    finish({ section });
    await waitFor(() => expect(latest).toHaveBeenCalledOnce());
    expect(original).not.toHaveBeenCalled();
});

it('does not overfill a canvas that reaches capacity while checking a saved section', async () => {
    let finish!: (value: { section: HubSection }) => void;
    request
        .mockResolvedValueOnce({ presets: [shared], can_share: true })
        .mockImplementationOnce(
            () =>
                new Promise((resolve) => {
                    finish = resolve;
                }),
        );
    const onInsert = vi.fn();
    const view = render(
        <SectionPresets
            pageId={5}
            selected={section}
            full={false}
            onInsert={onInsert}
        />,
    );
    fireEvent.click(screen.getByText('Section library', { exact: true }));
    fireEvent.click(
        await screen.findByRole('button', { name: 'Insert Team introduction' }),
    );
    view.rerender(
        <SectionPresets
            pageId={5}
            selected={section}
            full
            onInsert={onInsert}
        />,
    );
    finish({ section });
    expect(await screen.findByRole('alert')).toHaveTextContent(
        'now has 12 sections',
    );
    expect(onInsert).not.toHaveBeenCalled();
});

it('saves the selected layout to the chosen library and refreshes its contents', async () => {
    request
        .mockResolvedValueOnce({ presets: [], can_share: true })
        .mockResolvedValueOnce({ saved: true })
        .mockResolvedValue({ presets: [shared], can_share: true });
    await open();
    fireEvent.change(screen.getByLabelText('Save selected section as'), {
        target: { value: 'Team introduction' },
    });
    choose('Section library destination', 'Shared with editors');
    fireEvent.click(screen.getByRole('button', { name: 'Save section' }));
    expect(
        await screen.findByRole('button', { name: 'Insert Team introduction' }),
    ).toBeEnabled();
    expect(request).toHaveBeenCalledWith(
        '/company-hub/manage/5/presets',
        'POST',
        { name: 'Team introduction', section, scope: 'shared' },
    );
    expect(screen.getByLabelText('Save selected section as')).toHaveValue('');
});

it('removes a section with its version and offers undo using the new version', async () => {
    request
        .mockResolvedValueOnce({ presets: [shared], can_share: true })
        .mockResolvedValueOnce({ version: 5 })
        .mockResolvedValueOnce({ version: 6 })
        .mockResolvedValue({
            presets: [{ ...shared, version: 6 }],
            can_share: true,
        });
    await open();
    fireEvent.click(
        screen.getByRole('button', {
            name: 'Remove Team introduction from library',
        }),
    );
    fireEvent.click(
        await screen.findByRole('button', { name: 'Undo removal' }),
    );
    await waitFor(() =>
        expect(
            screen.getByRole('button', { name: 'Insert Team introduction' }),
        ).toBeEnabled(),
    );
    expect(request).toHaveBeenCalledWith(
        '/company-hub/manage/5/presets/1',
        'DELETE',
        { version: 4 },
    );
    expect(request).toHaveBeenCalledWith(
        '/company-hub/manage/5/presets/1/restore',
        'POST',
        { version: 5 },
    );
});

it('filters shared and personal layouts while respecting capacity and management permissions', async () => {
    request.mockResolvedValue({
        presets: [{ ...shared, can_remove: false }, personal],
        can_share: false,
    });
    await open({ full: true });
    fireEvent.keyDown(
        screen.getByRole('combobox', { name: 'Section library destination' }),
        { key: 'ArrowDown' },
    );
    expect(
        screen.queryByRole('option', { name: 'Shared with editors' }),
    ).not.toBeInTheDocument();
    fireEvent.keyDown(screen.getByRole('listbox'), { key: 'Escape' });
    expect(
        screen.queryByRole('button', {
            name: 'Remove Team introduction from library',
        }),
    ).not.toBeInTheDocument();
    expect(
        screen.getByRole('button', { name: 'Insert Team introduction' }),
    ).toBeDisabled();
    choose('Section library filter', 'Shared');
    expect(
        screen.queryByRole('button', { name: 'Insert My layout' }),
    ).not.toBeInTheDocument();
    fireEvent.change(
        screen.getByRole('textbox', { name: 'Search section library' }),
        { target: { value: 'missing' } },
    );
    expect(
        screen.getByText('No sections match your search.'),
    ).toBeInTheDocument();
});

it('restores a removed section after reopening the editor', async () => {
    request
        .mockResolvedValueOnce({
            presets: [],
            can_share: true,
            removed_presets: [
                {
                    id: 1,
                    name: 'Team introduction',
                    scope: 'shared',
                    version: 5,
                },
            ],
        })
        .mockResolvedValueOnce({ version: 6 })
        .mockResolvedValue({
            presets: [{ ...shared, version: 6 }],
            can_share: true,
            removed_presets: [],
        });
    await open();
    fireEvent.click(screen.getByText('Removed sections · 1'));
    fireEvent.click(
        screen.getByRole('button', { name: 'Restore Team introduction' }),
    );
    await waitFor(() =>
        expect(
            screen.getByRole('button', { name: 'Insert Team introduction' }),
        ).toBeEnabled(),
    );
    expect(request).toHaveBeenCalledWith(
        '/company-hub/manage/5/presets/1/restore',
        'POST',
        { version: 5 },
    );
    expect(screen.queryByText('Removed sections · 1')).not.toBeInTheDocument();
});
