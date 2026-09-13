import {
    act,
    cleanup,
    fireEvent,
    render,
    screen,
    waitFor,
} from '@testing-library/react';
import axios from 'axios';
import { afterEach, expect, it, vi } from 'vitest';
import {
    CatalogueEntityPicker,
    readCatalogueOptionPage,
} from '../catalogue-entity-picker';

const props = {
    actorId: 3,
    itemId: 8,
    schemaVersion: 2,
    fieldKey: 'asset',
    label: 'Equipment',
    id: 'equipment',
    value: null,
    onChange: vi.fn(),
};
const item = {
    id: 241,
    name: 'Permitted laptop after page 200',
    detail: 'Site A',
};
function response(
    body: unknown,
    options = [item],
    next: number | null = null,
    selected: typeof item | null = null,
) {
    const input = body as Record<string, unknown>;
    return {
        data: {
            viewer_user_id: input.actor_user_id,
            query_uuid: input.query_uuid,
            catalog_item_id: 8,
            schema_version: 2,
            field_key: 'asset',
            options,
            next_cursor: next,
            selected_id: input.selected_id,
            selected,
        },
    };
}
afterEach(() => {
    cleanup();
    vi.restoreAllMocks();
    props.onChange.mockClear();
});

it('loads bound permitted pages and preserves the selected label after closing', async () => {
    const post = vi
        .spyOn(axios, 'post')
        .mockImplementation(async (_url, body) =>
            response(
                body,
                (body as { after: number | null }).after
                    ? [item]
                    : [{ ...item, id: 1, name: 'First laptop' }],
                (body as { after: number | null }).after ? null : 50,
            ),
        );
    const view = render(<CatalogueEntityPicker {...props} />);
    expect(post).not.toHaveBeenCalled();
    fireEvent.click(screen.getByRole('combobox', { name: 'Equipment' }));
    await screen.findByRole('option', { name: /First laptop/ });
    const more = screen.getByRole('button', { name: 'Load more choices' });
    more.focus();
    fireEvent.keyDown(more, { key: 'Enter' });
    expect(props.onChange).not.toHaveBeenCalled();
    fireEvent.click(more);
    await screen.findByRole('option', { name: /after page 200/ });
    expect(
        screen.getByRole('button', { name: 'No more choices' }),
    ).toHaveFocus();
    expect(
        screen.getByRole('button', { name: 'No more choices' }),
    ).toHaveAttribute('aria-disabled', 'true');
    fireEvent.click(
        await screen.findByRole('option', { name: /after page 200/ }),
    );
    expect(props.onChange).toHaveBeenCalledWith(241);
    expect(post).toHaveBeenLastCalledWith(
        '/it/catalog/8/fields/asset/options',
        expect.objectContaining({
            actor_user_id: 3,
            schema_version: 2,
            after: 50,
        }),
        expect.objectContaining({ timeout: 15000 }),
    );
    expect(screen.queryByRole('option')).not.toBeInTheDocument();
    view.rerender(<CatalogueEntityPicker {...props} value={241} />);
    expect(
        screen.getByRole('combobox', { name: 'Equipment' }),
    ).toHaveTextContent(item.name);
});

it('ignores superseded search responses and cancels without selecting', async () => {
    let finish: (value: unknown) => void = () => {};
    let firstBody: unknown;
    vi.spyOn(axios, 'post')
        .mockImplementationOnce((_url, body) => {
            firstBody = body;
            return new Promise((resolve) => {
                finish = resolve;
            });
        })
        .mockImplementation(async (_url, body) =>
            response(body, [{ ...item, name: 'Latest match' }]),
        );
    render(<CatalogueEntityPicker {...props} />);
    fireEvent.click(screen.getByRole('combobox', { name: 'Equipment' }));
    fireEvent.change(
        screen.getByRole('combobox', { name: 'Search equipment' }),
        { target: { value: 'Latest' } },
    );
    await screen.findByRole('option', { name: /Latest match/ });
    await act(async () => finish(response(firstBody)));
    expect(screen.queryByText(item.name)).not.toBeInTheDocument();
    fireEvent.click(screen.getByRole('button', { name: 'Close choices' }));
    expect(props.onChange).not.toHaveBeenCalled();
});

it('distinguishes failure from empty results and retries the read', async () => {
    vi.spyOn(axios, 'post')
        .mockRejectedValueOnce(new Error('offline'))
        .mockImplementation(async (_url, body) => response(body, []));
    render(<CatalogueEntityPicker {...props} />);
    fireEvent.click(screen.getByRole('combobox', { name: 'Equipment' }));
    await screen.findByText(/Choices could not be loaded/);
    expect(
        screen.queryByText('No matching permitted choices.'),
    ).not.toBeInTheDocument();
    fireEvent.click(screen.getByRole('button', { name: 'Retry search' }));
    await screen.findByText('No matching permitted choices.');
    expect(props.onChange).not.toHaveBeenCalled();
});

it.each([403, 409, 419])(
    'conceals previous selected labels after context response %s',
    async (status) => {
        vi.spyOn(axios, 'isAxiosError').mockReturnValue(true);
        vi.spyOn(axios, 'post').mockRejectedValue({ response: { status } });
        render(
            <CatalogueEntityPicker
                {...props}
                value={241}
                initialSelected={item}
            />,
        );
        expect(screen.getByText(item.name)).toBeVisible();
        fireEvent.click(screen.getByRole('combobox', { name: 'Equipment' }));
        await waitFor(() =>
            expect(screen.queryByText(item.name)).not.toBeInTheDocument(),
        );
        expect(props.onChange).not.toHaveBeenCalled();
        expect(screen.getByRole('alert')).toBeVisible();
    },
);

it('stops pending reads and ignores their late results after account changes', async () => {
    let finish: (value: unknown) => void = () => {};
    let body: unknown;
    const post = vi.spyOn(axios, 'post').mockImplementation((_url, input) => {
        body = input;
        return new Promise((resolve) => {
            finish = resolve;
        });
    });
    const view = render(<CatalogueEntityPicker {...props} />);
    fireEvent.click(screen.getByRole('combobox', { name: 'Equipment' }));
    fireEvent.click(screen.getByRole('button', { name: 'Stop searching' }));
    expect((post.mock.calls[0][2]?.signal as AbortSignal).aborted).toBe(true);
    view.rerender(<CatalogueEntityPicker {...props} actorId={7} />);
    await act(async () => finish(response(body)));
    expect(screen.queryByText(item.name)).not.toBeInTheDocument();
    expect(screen.getByRole('combobox', { name: 'Equipment' })).toHaveAttribute(
        'aria-expanded',
        'false',
    );
});

it('rejects mismatched context, duplicate records and malformed pages', () => {
    const valid = response({
        actor_user_id: 3,
        query_uuid: 'query',
        selected_id: null,
    }).data;
    expect(readCatalogueOptionPage(valid, props, 'query')).not.toBeNull();
    for (const changed of [
        { viewer_user_id: 7 },
        { schema_version: 3 },
        { field_key: 'private' },
        { options: [item, item] },
        { next_cursor: -1 },
        { selected: item },
    ]) {
        expect(
            readCatalogueOptionPage({ ...valid, ...changed }, props, 'query'),
        ).toBeNull();
    }
});
