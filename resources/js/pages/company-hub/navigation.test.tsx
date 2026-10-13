import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
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
            await screen.findByRole('button', { name: 'Resources' }),
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
        const target = (
            screen.getByRole('option', {
                name: 'Useful things',
            }) as HTMLOptionElement
        ).value;
        fireEvent.change(screen.getByLabelText('Parent menu'), {
            target: { value: target },
        });
        expect(
            screen.getByRole('tab', { name: 'Useful things' }),
        ).toBeInTheDocument();
        fireEvent.click(screen.getByRole('button', { name: 'Undo' }));
        expect(screen.getByLabelText('Parent menu')).toHaveValue('');
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
});
