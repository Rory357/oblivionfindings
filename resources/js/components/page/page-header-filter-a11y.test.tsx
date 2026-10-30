import { render, screen } from '@testing-library/react';
import { Calendar, Home } from 'lucide-react';
import type { ReactNode } from 'react';
import { describe, expect, it, vi } from 'vitest';

import { TooltipProvider } from '@/components/ui/tooltip';
import {
    PageHeader,
    PageHeaderFilterButton,
    PageHeaderFilterCheck,
    PageHeaderFilterSelect,
    PageHeaderMeterBlock,
    PageHeaderMeterContacts,
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
    router: { get: vi.fn(), visit: vi.fn() },
}));

const options = [
    { value: 'all', label: 'All types' },
    { value: 'home', label: 'Residential home' },
];

const ON_GLASS = [
    'focus-visible:ring-2',
    'focus-visible:ring-inset',
    'focus-visible:ring-band-foreground',
];
const ON_WHITE = [
    'focus-visible:ring-2',
    'focus-visible:ring-inset',
    'focus-visible:ring-primary-strong',
];

function Header({
    frontline = false,
    filters,
}: {
    frontline?: boolean;
    filters: ReactNode;
}) {
    return (
        <TooltipProvider>
            <PageHeader
                title="Sites"
                icon={Home}
                frontline={frontline}
                meters={
                    <>
                        <PageHeaderMeterBlock label="Occupancy" href="/sites">
                            12
                        </PageHeaderMeterBlock>
                        <PageHeaderMeterBlock label="Contacts" href="/contacts">
                            <PageHeaderMeterContacts
                                contacts={[{ label: 'Manager', name: 'Kiri' }]}
                            />
                        </PageHeaderMeterBlock>
                    </>
                }
                filters={filters}
            />
        </TooltipProvider>
    );
}

describe('header filter focus indicators', () => {
    it('draws the dropdown focus ring on the trigger, not the unfocusable wrapper', () => {
        render(
            <Header
                filters={
                    <PageHeaderFilterSelect
                        testId="type"
                        label="All types"
                        value="all"
                        options={options}
                        onChange={() => {}}
                    />
                }
            />,
        );
        const trigger = screen.getByRole('button', { name: 'All types' });
        for (const cls of ON_GLASS) expect(trigger).toHaveClass(cls);
        const chip = trigger.parentElement!;
        expect(chip).toHaveClass('h-[23px]');
        expect(chip.className).not.toMatch(/focus-visible:/);
    });

    it('switches the ring to brand text on the white active chip and keeps the 38px comfortable chip', () => {
        render(
            <Header
                filters={
                    <PageHeaderFilterSelect
                        size="comfortable"
                        label="All types"
                        value="home"
                        options={options}
                        onChange={() => {}}
                    />
                }
            />,
        );
        const trigger = screen.getByRole('button', {
            name: 'Residential home',
        });
        for (const cls of ON_WHITE) expect(trigger).toHaveClass(cls);
        expect(trigger.parentElement).toHaveClass('h-[38px]');
    });

    it('gives the 14px clear ✕ an invisible 44px target without growing it', () => {
        const { unmount } = render(
            <Header
                filters={
                    <PageHeaderFilterSelect
                        label="All types"
                        value="home"
                        options={options}
                        onChange={() => {}}
                    />
                }
            />,
        );
        const clear = screen.getByRole('button', { name: 'Clear All types' });
        expect(clear).toHaveClass('frontline-hit', 'size-3.5');
        expect(clear).toHaveClass('focus-visible:ring-primary-strong');
        expect(clear).not.toHaveClass('frontline-tap');
        // The trigger sits above the ✕'s hit area where they meet.
        expect(
            screen.getByRole('button', { name: 'Residential home' }),
        ).toHaveClass('relative', 'z-[1]');
        unmount();

        render(
            <Header
                frontline
                filters={
                    <PageHeaderFilterSelect
                        label="All types"
                        value="home"
                        options={options}
                        onChange={() => {}}
                    />
                }
            />,
        );
        const frontlineClear = screen.getByRole('button', {
            name: 'Clear All types',
        });
        expect(frontlineClear).toHaveClass('frontline-tap');
        expect(frontlineClear).not.toHaveClass('frontline-hit');
    });

    it('tones the ring of checks, buttons and view segments by their fill', () => {
        render(
            <Header
                filters={
                    <>
                        <PageHeaderFilterCheck
                            label="Archived"
                            checked
                            onChange={() => {}}
                        />
                        <PageHeaderFilterCheck
                            label="Mine"
                            checked={false}
                            onChange={() => {}}
                        />
                        <PageHeaderFilterButton icon={Calendar} active>
                            Today
                        </PageHeaderFilterButton>
                        <PageHeaderFilterButton icon={Calendar}>
                            Week
                        </PageHeaderFilterButton>
                        <PageHeaderViewToggle
                            value="cards"
                            onChange={() => {}}
                            ariaLabel="Layout"
                            options={[
                                { value: 'cards', label: 'Cards' },
                                { value: 'table', label: 'Table' },
                            ]}
                        />
                    </>
                }
            />,
        );
        for (const name of ['Archived'])
            for (const cls of ON_WHITE)
                expect(screen.getByRole('checkbox', { name })).toHaveClass(cls);
        for (const cls of ON_GLASS)
            expect(screen.getByRole('checkbox', { name: 'Mine' })).toHaveClass(
                cls,
            );
        for (const cls of ON_WHITE)
            expect(screen.getByRole('button', { name: 'Today' })).toHaveClass(
                cls,
            );
        for (const cls of ON_GLASS)
            expect(screen.getByRole('button', { name: 'Week' })).toHaveClass(
                cls,
            );
        for (const cls of ON_WHITE)
            expect(screen.getByRole('radio', { name: 'Cards' })).toHaveClass(
                cls,
            );
        for (const cls of ON_GLASS)
            expect(screen.getByRole('radio', { name: 'Table' })).toHaveClass(
                cls,
            );
        // The segmented control's wrapper can't take focus, so carries no ring.
        expect(
            screen.getByRole('radiogroup', { name: 'Layout' }).className,
        ).not.toMatch(/focus-visible:/);
    });
});

describe('header meter labels', () => {
    it('size labels in rem so they follow the text-size preference', () => {
        const { container } = render(<Header filters={null} />);
        const labels = container.querySelectorAll('.eh-meter-label');
        expect(labels.length).toBeGreaterThanOrEqual(3);
        for (const label of labels) {
            expect(label).toHaveClass('text-xs');
            expect(label.className).not.toMatch(/text-\[\d+(\.\d+)?px\]/);
        }
    });
});
