import {
    act,
    fireEvent,
    render,
    screen,
    waitFor,
    within,
} from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { EditHubTabs } from './_edit-tabs';
import {
    HubNavigationRail,
    SharedNavigationDesigner,
    moveNavigation,
    type NavigationItem,
    type NavigationWorkspace,
} from './_navigation';

const { request, reload, visit } = vi.hoisted(() => ({
    request: vi.fn(),
    reload: vi.fn(),
    visit: vi.fn(),
}));
vi.mock('./_api', () => ({ hubRequest: request }));
vi.mock('@inertiajs/react', () => ({
    router: { reload, visit, on: () => () => {} },
}));
vi.mock('@/components/page/page-header', () => ({
    PageHeaderRail: ({
        items,
        onSelect,
    }: {
        items: NavigationItem[];
        onSelect: (key: string) => void;
    }) => (
        <div>
            {items.map((item: NavigationItem & { key?: string }) => (
                // eslint-disable-next-line no-restricted-syntax -- Minimal rail test double; real rail styling is verified in the browser.
                <button
                    role="tab"
                    key={item.key}
                    onClick={() => onSelect(item.key!)}
                >
                    {item.label}
                </button>
            ))}
        </div>
    ),
}));
const home: NavigationItem = {
    id: 'home',
    type: 'view',
    label: 'Home',
    target: 'home',
    parent: '',
    hidden: false,
    url: '/company-hub?view=home',
};
const news: NavigationItem = {
    id: 'news',
    type: 'view',
    label: 'News',
    target: 'news',
    parent: '',
    hidden: false,
    url: '/company-hub?view=news',
};
const resources: NavigationItem = {
    id: 'resources',
    type: 'view',
    label: 'Resources',
    target: 'resources',
    parent: '',
    hidden: false,
    url: '/company-hub?view=resources',
};
const group: NavigationItem = {
    id: 'help',
    type: 'group',
    label: 'Everyday help',
    target: '',
    parent: '',
    hidden: false,
};
const items = [home, news, resources].map(({ url: _url, ...item }) => item);
const workspace: NavigationWorkspace = {
    version: 3,
    has_draft: false,
    published_at: null,
    draft: items,
    published: items,
    pages: [],
    views: { home: 'Home', news: 'News', resources: 'Resources' },
    can_publish: true,
};
afterEach(() => Reflect.deleteProperty(window, 'navigation'));

describe('Shared Company Hub navigation', () => {
    beforeEach(() => {
        vi.clearAllMocks();
        request.mockResolvedValue(workspace);
    });
    it('opens grouped links and uses the resolved published destination', async () => {
        render(
            <HubNavigationRail
                items={[home, news, group, { ...resources, parent: 'help' }]}
                current="view:resources"
            />,
        );
        expect(
            screen.queryByRole('tab', { name: 'Resources' }),
        ).not.toBeInTheDocument();
        fireEvent.click(screen.getByRole('tab', { name: 'Everyday help' }));
        fireEvent.click(
            await screen.findByRole('button', { name: /^Resources/ }),
        );
        expect(visit).toHaveBeenCalledWith('/company-hub?view=resources');
    });
    it('keeps hidden groups and preview clicks out of staff navigation', () => {
        const preview = vi.fn();
        render(
            <HubNavigationRail
                items={[
                    home,
                    { ...group, hidden: true },
                    { ...resources, parent: 'help' },
                ]}
                current="view:home"
                onPreview={preview}
            />,
        );
        expect(
            screen.queryByRole('tab', { name: 'Everyday help' }),
        ).not.toBeInTheDocument();
        fireEvent.click(screen.getByRole('tab', { name: 'Home' }));
        expect(preview).toHaveBeenCalledWith(home);
        expect(visit).not.toHaveBeenCalled();
    });
    it('protects Home and keeps reorders inside the same menu', () => {
        const grouped = [home, news, group, { ...resources, parent: 'help' }];
        expect(moveNavigation(grouped, 'home', 'news')).toBe(grouped);
        expect(moveNavigation(grouped, 'news', 'home')).toBe(grouped);
        expect(moveNavigation(grouped, 'resources', 'news')).toBe(grouped);
        expect(
            moveNavigation(items, 'resources', 'news').map((item) => item.id),
        ).toEqual(['home', 'resources', 'news']);
        expect(items.map((item) => item.id)).toEqual([
            'home',
            'news',
            'resources',
        ]);
    });
    it('saves a versioned draft before allowing separate publication', async () => {
        render(<SharedNavigationDesigner onDirtyChange={() => {}} />);
        fireEvent.click(
            await screen.findByRole('button', { name: 'Edit News' }),
        );
        fireEvent.change(screen.getByLabelText('Navigation label'), {
            target: { value: 'Company stories' },
        });
        expect(
            screen.getByRole('button', { name: 'Publish navigation' }),
        ).toBeDisabled();
        const draft = items.map((item) =>
            item.id === 'news' ? { ...item, label: 'Company stories' } : item,
        );
        request.mockResolvedValue({
            ...workspace,
            version: 4,
            has_draft: true,
            draft,
        });
        fireEvent.click(screen.getByRole('button', { name: 'Save draft' }));
        await screen.findByText(
            'Draft saved. Staff still see the published menu.',
        );
        expect(request).toHaveBeenLastCalledWith(
            '/company-hub/editor/shared-navigation',
            'PUT',
            { action: 'save', version: 3, items: draft },
        );
        fireEvent.click(
            screen.getByRole('button', { name: 'Publish navigation' }),
        );
        expect(
            screen.getByText(/page drafts stay unchanged/),
        ).toBeInTheDocument();
        request.mockResolvedValue({
            ...workspace,
            version: 5,
            draft,
            published: draft,
        });
        fireEvent.click(
            screen.getByRole('button', { name: 'Publish shared menu' }),
        );
        await screen.findByText(
            'Shared navigation published. Staff will see this menu.',
        );
        expect(request).toHaveBeenLastCalledWith(
            '/company-hub/editor/shared-navigation',
            'PUT',
            { action: 'publish', version: 4 },
        );
        expect(reload).toHaveBeenCalledWith({
            only: ['sharedNavigation'],
            preserveScroll: true,
        });
    });
    it('blocks native Back while tab edits or a save are pending and releases it once saved', async () => {
        const browser = Object.assign(new EventTarget(), {
            currentEntry: { key: 'tabs' },
            traverseTo: vi.fn(() => ({ finished: Promise.resolve() })),
        });
        Object.defineProperty(window, 'navigation', {
            configurable: true,
            value: browser,
        });
        const back = () => {
            const event = Object.assign(
                new Event('navigate', { cancelable: true }),
                {
                    navigationType: 'traverse',
                    destination: { key: 'previous', sameDocument: true },
                },
            );
            act(() => {
                browser.dispatchEvent(event);
            });
            return event;
        };
        const view = render(
            <SharedNavigationDesigner onDirtyChange={() => {}} />,
        );
        fireEvent.click(
            await screen.findByRole('button', { name: 'Edit News' }),
        );
        expect(back().defaultPrevented).toBe(false);
        fireEvent.change(screen.getByLabelText('Navigation label'), {
            target: { value: 'Company stories' },
        });
        expect(back().defaultPrevented).toBe(true);
        expect(screen.getByRole('alert')).toHaveTextContent(
            'Save your menu draft or discard local changes',
        );
        expect(screen.getByLabelText('Navigation label')).toHaveValue(
            'Company stories',
        );
        let finish!: (value: NavigationWorkspace) => void;
        request.mockImplementationOnce(
            () =>
                new Promise<NavigationWorkspace>((resolve) => {
                    finish = resolve;
                }),
        );
        fireEvent.click(screen.getByRole('button', { name: 'Save draft' }));
        expect(back().defaultPrevented).toBe(true);
        expect(screen.getByRole('alert')).toHaveTextContent(
            'Wait for the menu change to finish',
        );
        await act(async () => {
            finish({
                ...workspace,
                version: 4,
                has_draft: true,
                draft: items.map((item) =>
                    item.id === 'news'
                        ? { ...item, label: 'Company stories' }
                        : item,
                ),
            });
        });
        expect(back().defaultPrevented).toBe(false);
        expect(browser.traverseTo).not.toHaveBeenCalled();
        view.unmount();
    });
    it('retains local edits after a conflict and requires confirmation before replacing them', async () => {
        render(<SharedNavigationDesigner onDirtyChange={() => {}} />);
        fireEvent.click(
            await screen.findByRole('button', { name: 'Edit News' }),
        );
        fireEvent.change(screen.getByLabelText('Navigation label'), {
            target: { value: 'My edited label' },
        });
        request.mockRejectedValue(
            new Error('Another editor changed the menu.'),
        );
        fireEvent.click(screen.getByRole('button', { name: 'Save draft' }));
        expect(await screen.findByRole('alert')).toHaveTextContent(
            'Another editor',
        );
        expect(screen.getByLabelText('Navigation label')).toHaveValue(
            'My edited label',
        );
        fireEvent.click(
            screen.getByRole('button', { name: 'Reload latest menu' }),
        );
        fireEvent.click(screen.getByRole('button', { name: 'Keep editing' }));
        expect(screen.getByLabelText('Navigation label')).toHaveValue(
            'My edited label',
        );
        expect(reload).not.toHaveBeenCalled();
    });
    it('adds groups, reparents a link and restores the previous layout with undo', async () => {
        render(<SharedNavigationDesigner onDirtyChange={() => {}} />);
        await screen.findByRole('button', { name: 'Edit News' });
        fireEvent.click(screen.getByRole('button', { name: 'Add group' }));
        fireEvent.change(screen.getByLabelText('Navigation label'), {
            target: { value: 'Useful things' },
        });
        const groupOption = screen.getByRole('button', {
            name: 'Edit Useful things',
        });
        expect(groupOption).toBeInTheDocument();
        fireEvent.click(screen.getByRole('button', { name: 'Edit Resources' }));
        fireEvent.keyDown(
            screen.getByRole('combobox', { name: 'Parent menu' }),
            { key: 'ArrowDown' },
        );
        fireEvent.click(
            await screen.findByRole('option', { name: 'Useful things' }),
        );
        expect(
            screen.getByRole('tab', { name: 'Useful things' }),
        ).toBeInTheDocument();
        fireEvent.click(screen.getByRole('button', { name: 'Undo' }));
        expect(
            screen.getByRole('combobox', { name: 'Parent menu' }),
        ).toHaveTextContent('Top level');
    });
    it('shows a draft-only editor no publishing action', async () => {
        request.mockResolvedValue({
            ...workspace,
            can_publish: false,
            has_draft: true,
        });
        render(<SharedNavigationDesigner onDirtyChange={() => {}} />);
        await waitFor(() =>
            expect(
                screen.getByRole('button', { name: 'Save draft' }),
            ).toBeInTheDocument(),
        );
        expect(
            screen.queryByRole('button', { name: 'Publish navigation' }),
        ).not.toBeInTheDocument();
        expect(
            screen.getByText(/person with publishing permission/),
        ).toBeInTheDocument();
    });
    it('adds and removes a page tab locally, keeps Home fixed and supports undo', async () => {
        request.mockResolvedValue({
            ...workspace,
            pages: [
                {
                    id: '12',
                    title: 'Our handbook',
                    url: '/company-hub/read/handbook',
                },
            ],
        });
        render(<SharedNavigationDesigner onDirtyChange={() => {}} />);
        await screen.findByRole('button', { name: 'Edit Home' });
        expect(
            screen.queryByRole('button', { name: 'Remove tab' }),
        ).not.toBeInTheDocument();
        expect(
            screen.getByRole('button', { name: 'Drag Home' }),
        ).toBeDisabled();
        fireEvent.click(screen.getByRole('button', { name: 'Add tab' }));
        fireEvent.change(screen.getByLabelText('Navigation label'), {
            target: { value: 'Handbook' },
        });
        fireEvent.keyDown(
            screen.getByRole('combobox', { name: 'Destination type' }),
            { key: 'ArrowDown' },
        );
        fireEvent.click(
            await screen.findByRole('option', { name: 'Published page' }),
        );
        fireEvent.keyDown(
            screen.getByRole('combobox', { name: 'Published page' }),
            { key: 'ArrowDown' },
        );
        fireEvent.click(
            await screen.findByRole('option', { name: 'Our handbook' }),
        );
        expect(screen.getByRole('tab', { name: 'Handbook' })).toBeVisible();
        fireEvent.click(screen.getByRole('button', { name: 'Remove tab' }));
        expect(
            screen.queryByRole('tab', { name: 'Handbook' }),
        ).not.toBeInTheDocument();
        fireEvent.click(screen.getByRole('button', { name: 'Undo' }));
        expect(screen.getByRole('tab', { name: 'Handbook' })).toBeVisible();
        expect(request).toHaveBeenCalledTimes(1);
    });
    it('previews unpublished group links on the page without navigating or saving', async () => {
        request.mockResolvedValue({
            ...workspace,
            draft: [
                home,
                group,
                { ...news, parent: group.id },
                { ...resources, parent: group.id, hidden: true },
            ],
        });
        render(
            <>
                <div data-testid="hero-rail" />
                <aside data-testid="page-panel" />
            </>,
        );
        render(
            <EditHubTabs
                onClose={vi.fn()}
                onPublished={vi.fn()}
                editorSlots={{
                    rail: screen.getByTestId('hero-rail'),
                    inspector: screen.getByTestId('page-panel'),
                }}
            />,
        );
        const rail = within(screen.getByTestId('hero-rail'));
        fireEvent.click(
            await screen.findByRole('button', { name: 'Edit News' }),
        );
        fireEvent.change(screen.getByLabelText('Navigation label'), {
            target: { value: 'Our stories' },
        });
        fireEvent.click(rail.getByRole('button', { name: 'Preview menu' }));
        fireEvent.click(rail.getByRole('tab', { name: 'Everyday help' }));
        expect(
            screen.getByRole('button', { name: /^Our stories/ }),
        ).toBeVisible();
        expect(
            screen.queryByRole('button', { name: 'Resources' }),
        ).not.toBeInTheDocument();
        fireEvent.click(screen.getByRole('button', { name: /^Our stories/ }));
        expect(
            screen.getByText(/Preview: Our stories opens News/),
        ).toBeVisible();
        expect(visit).not.toHaveBeenCalled();
        expect(request).toHaveBeenCalledTimes(1);
        fireEvent.click(rail.getByRole('button', { name: 'Edit tabs' }));
        expect(screen.getByLabelText('Navigation label')).toHaveValue(
            'Our stories',
        );
        expect(
            screen.getByRole('button', { name: 'Save tabs draft' }),
        ).toBeEnabled();
    });
    it('edits the real page rail with settings in its existing panel and no editor modal', async () => {
        render(
            <>
                <div data-testid="hero-rail" />
                <aside data-testid="page-panel" />
            </>,
        );
        render(
            <EditHubTabs
                onClose={vi.fn()}
                onPublished={vi.fn()}
                editorSlots={{
                    rail: screen.getByTestId('hero-rail'),
                    inspector: screen.getByTestId('page-panel'),
                }}
            />,
        );
        const rail = within(screen.getByTestId('hero-rail'));
        const panel = within(screen.getByTestId('page-panel'));
        fireEvent.click(await rail.findByRole('tab', { name: 'News' }));
        expect(panel.getByLabelText('Navigation label')).toHaveValue('News');
        expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
        expect(
            screen.queryByText('Live navigation preview'),
        ).not.toBeInTheDocument();
        fireEvent.change(panel.getByLabelText('Navigation label'), {
            target: { value: 'Our stories' },
        });
        expect(rail.getByRole('tab', { name: 'Our stories' })).toBeVisible();
        fireEvent.click(panel.getByRole('button', { name: 'Remove tab' }));
        expect(
            rail.queryByRole('tab', { name: 'Our stories' }),
        ).not.toBeInTheDocument();
        fireEvent.click(screen.getByRole('button', { name: 'Undo' }));
        expect(rail.getByRole('tab', { name: 'Our stories' })).toBeVisible();
        fireEvent.click(rail.getByRole('button', { name: 'Add group' }));
        expect(panel.getByLabelText('Navigation label')).toHaveValue(
            'New menu',
        );
        fireEvent.click(rail.getByRole('tab', { name: 'Home' }));
        expect(
            panel.queryByRole('button', { name: 'Remove tab' }),
        ).not.toBeInTheDocument();
        fireEvent.click(rail.getByRole('tab', { name: 'New menu' }));
        expect(panel.getByLabelText('Navigation label')).toHaveValue(
            'New menu',
        );
        expect(request).toHaveBeenCalledTimes(1);
    });
    it('confirms local tab changes before closing without altering the saved navigation', async () => {
        const close = vi.fn();
        render(<EditHubTabs onClose={close} onPublished={vi.fn()} />);
        fireEvent.click(
            await screen.findByRole('button', { name: 'Edit News' }),
        );
        fireEvent.change(screen.getByLabelText('Navigation label'), {
            target: { value: 'Stories' },
        });
        fireEvent.click(
            screen.getByRole('button', { name: 'Done editing tabs' }),
        );
        const confirmation = screen.getByRole('region', {
            name: 'Unsaved tab changes',
        });
        expect(
            within(confirmation).getByText(
                /Your page content and saved navigation drafts/,
            ),
        ).toBeVisible();
        fireEvent.click(
            within(confirmation).getByRole('button', {
                name: 'Keep editing tabs',
            }),
        );
        expect(close).not.toHaveBeenCalled();
        expect(screen.getByLabelText('Navigation label')).toHaveValue(
            'Stories',
        );
        fireEvent.click(
            screen.getByRole('button', { name: 'Done editing tabs' }),
        );
        fireEvent.click(
            screen.getByRole('button', { name: 'Leave without saving' }),
        );
        expect(close).toHaveBeenCalledOnce();
        expect(request).toHaveBeenCalledTimes(1);
    });
    it('blocks closing during a save and retains edits when the save fails', async () => {
        const close = vi.fn();
        render(<EditHubTabs onClose={close} onPublished={vi.fn()} />);
        fireEvent.click(
            await screen.findByRole('button', { name: 'Edit News' }),
        );
        fireEvent.change(screen.getByLabelText('Navigation label'), {
            target: { value: 'Stories' },
        });
        let fail!: (error: Error) => void;
        request.mockImplementation(
            () =>
                new Promise((_resolve, reject) => {
                    fail = reject;
                }),
        );
        fireEvent.click(screen.getByRole('button', { name: 'Save draft' }));
        fireEvent.click(screen.getByRole('button', { name: 'Save draft' }));
        fireEvent.click(
            screen.getByRole('button', { name: 'Done editing tabs' }),
        );
        expect(close).not.toHaveBeenCalled();
        expect(
            screen.getByRole('button', { name: 'Done editing tabs' }),
        ).toBeDisabled();
        expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
        expect(request).toHaveBeenCalledTimes(2);
        await act(async () =>
            fail(new Error('Another editor changed the menu.')),
        );
        expect(screen.getByLabelText('Navigation label')).toHaveValue(
            'Stories',
        );
        expect(screen.getByRole('alert')).toHaveTextContent('Another editor');
        expect(
            screen.getByRole('button', { name: 'Done editing tabs' }),
        ).toBeEnabled();
    });
    it('updates an embedded preview after publication without refreshing the page editor', async () => {
        const published = vi.fn();
        request.mockResolvedValue({ ...workspace, has_draft: true });
        render(<EditHubTabs onClose={vi.fn()} onPublished={published} />);
        fireEvent.click(
            await screen.findByRole('button', { name: 'Publish navigation' }),
        );
        const result = { ...workspace, version: 4 };
        request.mockResolvedValue(result);
        fireEvent.click(
            screen.getByRole('button', { name: 'Publish shared menu' }),
        );
        await waitFor(() => expect(published).toHaveBeenCalledWith(result));
        expect(reload).not.toHaveBeenCalled();
        expect(visit).not.toHaveBeenCalled();
    });
});
