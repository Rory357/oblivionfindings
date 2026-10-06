import {
    cleanup,
    fireEvent,
    render,
    screen,
    waitFor,
    within,
} from '@testing-library/react';
import type { ReactNode } from 'react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import {
    PageHeader,
    PageHeaderFilterButton,
    PageHeaderFilterCheck,
    PageHeaderFilterSelect,
    PageHeaderGlassButton,
    PageHeaderMeterBlock,
    PageHeaderPrimaryButton,
    PageHeaderRail,
    PageHeaderSearch,
    PageHeaderSearchTrigger,
    PageHeaderViewToggle,
} from './page-header';

vi.mock('@inertiajs/react', () => ({
    Link: ({
        href,
        children,
        className,
        ...props
    }: {
        href: string;
        children: ReactNode;
        className?: string;
    }) => (
        <a href={href} className={className} {...props}>
            {children}
        </a>
    ),
    router: { get: vi.fn() },
}));

const views = [
    { key: 'schedule', label: 'Schedule' },
    { key: 'as-needed', label: 'As-needed' },
    { key: 'needs-help', label: 'Needs help' },
    { key: 'activity', label: 'Activity' },
];

describe('compact frontline summary', () => {
    it('exposes expandable details without removing filters from the header', () => {
        render(
            <PageHeader
                frontline
                title="Meds today"
                mobileSummary="5 due now · 12 late"
                meters={<span>17 need recording</span>}
                filters={
                    <PageHeaderSearch
                        value=""
                        onChange={() => {}}
                        placeholder="Find a medicine"
                    />
                }
            />,
        );
        const toggle = screen.getByRole('button', {
            name: /5 due now.*12 late/,
        });
        expect(toggle).toHaveAttribute('aria-expanded', 'false');
        fireEvent.click(toggle);
        expect(toggle).toHaveAttribute('aria-expanded', 'true');
        expect(
            document.getElementById(toggle.getAttribute('aria-controls')!),
        ).toContainElement(screen.getByPlaceholderText('Find a medicine'));
        fireEvent.click(toggle);
        expect(toggle).toHaveAttribute('aria-expanded', 'false');
    });
});

function Header({
    frontline,
    onFilter = () => {},
}: {
    frontline?: boolean;
    onFilter?: (value: string) => void;
}) {
    return (
        <PageHeader
            frontline={frontline}
            variant="profile"
            backHref="/my-day"
            title="Meds today"
            actions={
                <>
                    <PageHeaderSearch
                        value=""
                        onChange={() => {}}
                        placeholder="Search medicines"
                    />
                    <PageHeaderSearchTrigger
                        placeholder="Search this person"
                        onOpen={() => {}}
                    />
                    <PageHeaderGlassButton>
                        Secondary action
                    </PageHeaderGlassButton>
                    <PageHeaderPrimaryButton>
                        Give as-needed medicine
                    </PageHeaderPrimaryButton>
                </>
            }
            meters={
                <PageHeaderMeterBlock label="Due now" href="/meds/today">
                    3 doses
                </PageHeaderMeterBlock>
            }
            filters={
                <>
                    <PageHeaderFilterSelect
                        label="All states"
                        value="open"
                        options={[
                            { value: 'all', label: 'All states' },
                            { value: 'open', label: 'Due or late' },
                        ]}
                        onChange={onFilter}
                    />
                    <PageHeaderFilterButton aria-label="Refresh">
                        Updated just now
                    </PageHeaderFilterButton>
                    <PageHeaderFilterCheck
                        label="My people"
                        checked
                        onChange={() => {}}
                    />
                    <PageHeaderViewToggle
                        value="time"
                        onChange={() => {}}
                        ariaLabel="Group by"
                        options={[
                            { value: 'time', label: 'By time' },
                            { value: 'person', label: 'By person' },
                        ]}
                    />
                </>
            }
            rail={
                <PageHeaderRail
                    items={views}
                    value="activity"
                    onSelect={() => {}}
                />
            }
        />
    );
}

afterEach(() => {
    cleanup();
    vi.restoreAllMocks();
    document.documentElement.style.removeProperty('--base-font-size');
    delete document.documentElement.dataset.density;
});

describe('PageHeader frontline opt-in', () => {
    it.each([13, 14, 16])(
        'keeps all real controls opted in at a %ipx font and compact density',
        (fontSize) => {
            document.documentElement.style.setProperty(
                '--base-font-size',
                `${fontSize}px`,
            );
            document.documentElement.dataset.density = 'compact';
            const { container } = render(<Header frontline />);
            const header = container.querySelector('header')!;
            expect(header).toHaveClass('eh-header-frontline');
            // Fields keep real 44px boxes. Compact connected tabs retain Rory's
            // drawn geometry and the shared 44px hit area.
            for (const control of header.querySelectorAll('input, button, a')) {
                expect(
                    control.classList.contains('frontline-tap') ||
                        control.classList.contains('frontline-hit'),
                ).toBe(true);
            }
            expect(
                screen.getByRole('radiogroup', { name: 'Group by' }),
            ).toHaveClass('h-auto');
            expect(
                screen.getByRole('button', { name: 'Clear All states' })
                    .parentElement,
            ).toHaveClass('h-auto');
            expect(screen.getByRole('tab', { name: 'Activity' })).toHaveClass(
                'h-[40px]',
                'frontline-hit',
            );
        },
    );

    it('keeps compact back-office sizing when the page does not opt in', () => {
        const { container } = render(<Header />);
        expect(container.querySelector('header')).not.toHaveClass(
            'eh-header-frontline',
        );
        for (const control of container.querySelectorAll('input, button, a')) {
            expect(control).not.toHaveClass('frontline-tap');
        }
        expect(
            screen.getByRole('radiogroup', { name: 'Group by' }),
        ).toHaveClass('h-[23px]');
    });

    it('carries frontline sizing into the filter portal and keeps clearing separate from opening', async () => {
        const change = vi.fn();
        render(<Header frontline onFilter={change} />);
        fireEvent.click(
            screen.getByRole('button', { name: 'Clear All states' }),
        );
        expect(change).toHaveBeenCalledExactlyOnceWith('all');
        expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
        fireEvent.click(screen.getByRole('button', { name: 'Due or late' }));
        const menu = await screen.findByRole('dialog');
        expect(menu.closest('header')).toBeNull();
        for (const option of within(menu).getAllByRole('button'))
            expect(option).toHaveClass('frontline-tap');
    });

    it('keeps the active view visible with accessible hit areas and measures More and Find before collapsing tabs', () => {
        vi.spyOn(HTMLElement.prototype, 'clientWidth', 'get').mockReturnValue(
            250,
        );
        vi.spyOn(
            HTMLElement.prototype,
            'offsetWidth',
            'get',
        ).mockImplementation(function (this: HTMLElement) {
            if (this.textContent === 'More') return 80;
            if (this.textContent === 'Find') return 44;
            return this.textContent === 'Activity' ? 100 : 104;
        });
        const { container } = render(<Header frontline />);
        expect(screen.getAllByRole('tab')).toHaveLength(1);
        expect(screen.getByRole('tab', { name: 'Activity' })).toHaveAttribute(
            'aria-selected',
            'true',
        );
        expect(
            screen.getByRole('button', { name: 'More views (3)' }),
        ).toHaveClass('frontline-hit');
        expect(screen.getByRole('button', { name: 'Find a view' })).toHaveClass(
            'frontline-hit',
        );
        const measured = container.querySelector(
            '[aria-hidden="true"].invisible',
        )!;
        expect(measured).toHaveClass('gap-[4px]');
        expect(measured.querySelectorAll('button')).toHaveLength(
            views.length + 2,
        );
        for (const control of measured.querySelectorAll('button'))
            expect(control).toHaveClass('frontline-hit');
    });

    it('carries sizing into Find and preserves keyboard cancellation focus', async () => {
        render(<Header frontline />);
        const find = screen.getByRole('button', { name: 'Find a view' });
        fireEvent.click(find);
        const palette = await screen.findByRole('dialog');
        expect(within(palette).getByRole('textbox')).toHaveClass(
            'frontline-tap',
        );
        for (const control of within(palette)
            .getAllByRole('button')
            .filter((button) => button.textContent !== 'Close')) {
            expect(control).toHaveClass('frontline-tap');
        }
        fireEvent.keyDown(within(palette).getByRole('textbox'), {
            key: 'Escape',
        });
        await waitFor(() =>
            expect(screen.queryByRole('dialog')).not.toBeInTheDocument(),
        );
        await waitFor(() => expect(find).toHaveFocus());
    });
});
