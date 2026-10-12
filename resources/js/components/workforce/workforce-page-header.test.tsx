import {
    PageHeaderGlassButton,
    PageHeaderPrimaryButton,
    PageHeaderSearch,
    PageHeaderStatusChip,
} from '@/components/page/page-header';
import {
    act,
    cleanup,
    fireEvent,
    render,
    screen,
} from '@testing-library/react';
import { useState, type ReactNode } from 'react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { WorkforcePageHeader } from './workforce-page-header';

const mobile = vi.hoisted(() => ({ value: true }));
vi.mock('@/hooks/use-mobile', () => ({ useIsMobile: () => mobile.value }));
vi.mock('@inertiajs/react', () => ({
    Link: ({ children, ...props }: { children: ReactNode; href: string }) => (
        <a {...props}>{children}</a>
    ),
    router: { get: vi.fn() },
}));

afterEach(() => {
    cleanup();
    vi.unstubAllGlobals();
    mobile.value = true;
});

function Header({
    onCreate,
    onSearch,
    canCreate = true,
}: {
    onCreate: () => void;
    onSearch: () => void;
    canCreate?: boolean;
}) {
    const [query, setQuery] = useState('');
    const primary = canCreate ? (
        <PageHeaderPrimaryButton onClick={onCreate}>
            Add shift
        </PageHeaderPrimaryButton>
    ) : null;
    const secondary = (
        <>
            <PageHeaderSearch
                value={query}
                onChange={setQuery}
                placeholder="Search staff and sites"
            />
            <PageHeaderGlassButton onClick={onSearch}>
                Search
            </PageHeaderGlassButton>
        </>
    );
    return (
        <WorkforcePageHeader
            title="Shifts"
            titleChip={
                <PageHeaderStatusChip variant="warning">
                    3 need cover
                </PageHeaderStatusChip>
            }
            subline="Selected duties across approved houses"
            actions={
                <>
                    {secondary}
                    {primary}
                </>
            }
            mobilePrimaryAction={primary}
            mobileSecondaryActions={secondary}
            mobileSummary="5–11 Oct 2026"
            details={<p role="status">Saved records</p>}
        />
    );
}

describe('Workforce phone header workflow', () => {
    it('keeps the primary, warning and selected period available, with one retained secondary search', () => {
        const create = vi.fn();
        const search = vi.fn();
        const { container } = render(
            <Header onCreate={create} onSearch={search} />,
        );
        const toggle = screen.getByRole('button', {
            name: /5–11 Oct 2026.*Details & filters/,
        });
        const details = document.getElementById(
            toggle.getAttribute('aria-controls')!,
        )!;
        expect(details).toHaveClass('hidden');
        const primary = container.querySelector(
            '[data-slot="workforce-primary-actions"]',
        )!;
        expect(primary).toHaveTextContent('3 need cover');
        expect(primary).toHaveTextContent('Add shift');
        expect(primary.closest('[id]')).toBeNull();
        expect(container.querySelectorAll('input[type="search"]')).toHaveLength(
            1,
        );
        expect(screen.getByRole('status')).toHaveTextContent('Saved records');
        fireEvent.click(toggle);
        expect(details).not.toHaveClass('hidden');
        const input = screen.getByRole('searchbox', {
            name: 'Search staff and sites',
        });
        fireEvent.change(input, { target: { value: 'Lily' } });
        fireEvent.click(screen.getByRole('button', { name: 'Search' }));
        expect(search).toHaveBeenCalledOnce();
        fireEvent.click(toggle);
        expect(details).toHaveClass('hidden');
        fireEvent.click(toggle);
        expect(input).toHaveValue('Lily');
        fireEvent.click(screen.getByRole('button', { name: 'Add shift' }));
        expect(create).toHaveBeenCalledOnce();
    });

    it('opens details for the scoped-search shortcut, then focuses its single real input', () => {
        const frames: FrameRequestCallback[] = [];
        vi.stubGlobal(
            'requestAnimationFrame',
            (callback: FrameRequestCallback) => frames.push(callback),
        );
        render(<Header onCreate={vi.fn()} onSearch={vi.fn()} />);
        const toggle = screen.getByRole('button', { name: /5–11 Oct 2026/ });
        fireEvent.keyDown(window, { key: '/', ctrlKey: true });
        expect(toggle).toHaveAttribute('aria-expanded', 'false');
        fireEvent.keyDown(window, { key: '/' });
        expect(toggle).toHaveAttribute('aria-expanded', 'true');
        act(() => frames.forEach((callback) => callback(0)));
        const input = screen.getByRole('searchbox', {
            name: 'Search staff and sites',
        });
        expect(input).toHaveFocus();
        fireEvent.click(toggle);
        fireEvent.keyDown(input, { key: '/' });
        expect(toggle).toHaveAttribute('aria-expanded', 'false');
    });

    it('keeps desktop action order, subline and compact controls unchanged', () => {
        mobile.value = false;
        const { container } = render(
            <Header onCreate={vi.fn()} onSearch={vi.fn()} />,
        );
        expect(container.querySelector('header')).not.toHaveClass(
            'eh-header-frontline',
        );
        expect(
            container.querySelector('[data-slot="workforce-primary-actions"]'),
        ).toBeNull();
        expect(
            container.querySelector(
                '[data-slot="workforce-secondary-actions"]',
            ),
        ).toBeNull();
        expect(
            container.querySelector('[data-slot="page-header-subline"]'),
        ).toHaveTextContent('Selected duties across approved houses');
        const actions = container.querySelector(
            '[data-slot="page-header-top"]',
        )!;
        expect(
            Array.from(actions.querySelectorAll('input, button')).map(
                (node) => node.getAttribute('aria-label') ?? node.textContent,
            ),
        ).toEqual(['Search staff and sites', 'Search', 'Add shift']);
        expect(screen.getByRole('searchbox')).not.toHaveClass('frontline-tap');
    });

    it('does not invent a primary action when the existing capability is absent', () => {
        const { container } = render(
            <Header onCreate={vi.fn()} onSearch={vi.fn()} canCreate={false} />,
        );
        expect(
            screen.queryByRole('button', { name: 'Add shift' }),
        ).not.toBeInTheDocument();
        expect(
            container.querySelector('[data-slot="workforce-primary-actions"]'),
        ).toHaveTextContent('3 need cover');
        fireEvent.click(screen.getByRole('button', { name: /5–11 Oct 2026/ }));
        expect(screen.getByRole('searchbox')).toBeInTheDocument();
    });
});
