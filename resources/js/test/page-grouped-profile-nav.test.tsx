import {
    GroupPillRail,
    TabSearchPalette,
    TierTwoTabs,
    useGroupedProfileSearchShortcut,
    type GroupedProfileNavGroup,
} from '@/components/page/grouped-profile-nav';
import { fireEvent, render, screen } from '@testing-library/react';
import { User } from 'lucide-react';
import { useState } from 'react';
import { describe, expect, it, vi } from 'vitest';

const groups: GroupedProfileNavGroup[] = [
    {
        key: 'overview',
        label: 'Overview',
        icon: User,
        tabs: [
            { key: 'overview', label: 'Overview', icon: User, count: 3 },
            {
                key: 'readiness',
                label: 'Readiness',
                icon: User,
                warningCount: 2,
            },
        ],
    },
    {
        key: 'safety',
        label: 'Safety',
        icon: User,
        tabs: [
            {
                key: 'hazards',
                label: 'Hazards',
                icon: User,
                warningCount: 4,
            },
            { key: 'inspections', label: 'Inspections', icon: User },
        ],
    },
];

function ShortcutHarness() {
    const [open, setOpen] = useState(false);
    useGroupedProfileSearchShortcut(() => setOpen(true));

    return <span>{open ? 'Search open' : 'Search closed'}</span>;
}

describe('grouped profile navigation', () => {
    it('uses a configurable test prefix and remembers the last tab in each group', () => {
        const onOpenGroup = vi.fn();
        const props = {
            groups,
            onOpenGroup,
            onSearch: vi.fn(),
            testIdPrefix: 'site-profile',
        };
        const { container, rerender } = render(
            <GroupPillRail
                {...props}
                openGroup="safety"
                activeTab="inspections"
            />,
        );

        rerender(
            <GroupPillRail
                {...props}
                openGroup="overview"
                activeTab="overview"
            />,
        );
        fireEvent.click(screen.getByRole('button', { name: /Safety/ }));

        expect(onOpenGroup).toHaveBeenCalledWith('safety', 'inspections');
        expect(
            container.querySelector('[data-test="site-profile-group-safety"]'),
        ).toBeInTheDocument();
    });

    it('shows group and tab warnings as text with accessible labels', () => {
        render(
            <>
                <GroupPillRail
                    groups={groups}
                    openGroup="overview"
                    activeTab="readiness"
                    onOpenGroup={vi.fn()}
                    onSearch={vi.fn()}
                    testIdPrefix="site-profile"
                />
                <TierTwoTabs
                    tabs={groups[0].tabs}
                    activeTab="readiness"
                    onTab={vi.fn()}
                    renderLink={() => null}
                    testIdPrefix="site-profile"
                />
            </>,
        );

        expect(
            screen.getByLabelText(
                'Overview group has 2 items needing attention',
            ),
        ).toHaveTextContent('2');
        expect(
            screen.getByLabelText('Readiness has 2 items needing attention'),
        ).toHaveTextContent('2');
        expect(screen.getByText('3')).toBeVisible();
    });

    it('opens search with slash outside editable controls', () => {
        render(
            <>
                <ShortcutHarness />
                <input aria-label="Editable field" />
            </>,
        );

        fireEvent.keyDown(window, { key: '/' });
        expect(screen.getByText('Search open')).toBeVisible();
    });

    it('does not capture slash while the user is typing', () => {
        render(
            <>
                <ShortcutHarness />
                <input aria-label="Editable field" />
            </>,
        );

        fireEvent.keyDown(screen.getByLabelText('Editable field'), {
            key: '/',
        });
        expect(screen.getByText('Search closed')).toBeVisible();
    });

    it('filters all groups and selects a result from the search palette', () => {
        const onTab = vi.fn();
        render(
            <TabSearchPalette
                open
                onClose={vi.fn()}
                groups={groups}
                onTab={onTab}
                testIdPrefix="site-profile"
            />,
        );

        fireEvent.change(screen.getByPlaceholderText('Jump to a section…'), {
            target: { value: 'inspect' },
        });
        fireEvent.click(screen.getByRole('button', { name: /Inspections/ }));

        expect(onTab).toHaveBeenCalledWith('inspections');
    });

    it('pins and unpins tabs without changing the active tab', () => {
        const onTab = vi.fn();
        const onPinnedTabsChange = vi.fn();
        const { rerender } = render(
            <TierTwoTabs
                tabs={groups[1].tabs}
                activeTab="hazards"
                onTab={onTab}
                renderLink={() => null}
                testIdPrefix="site-profile"
                pinnedTabs={[]}
                onPinnedTabsChange={onPinnedTabsChange}
            />,
        );

        fireEvent.click(screen.getByRole('button', { name: 'Pin Hazards' }));
        expect(onPinnedTabsChange).toHaveBeenCalledWith(['hazards']);
        expect(onTab).not.toHaveBeenCalled();

        rerender(
            <TierTwoTabs
                tabs={groups[1].tabs}
                activeTab="hazards"
                onTab={onTab}
                renderLink={() => null}
                testIdPrefix="site-profile"
                pinnedTabs={['hazards']}
                onPinnedTabsChange={onPinnedTabsChange}
            />,
        );
        fireEvent.click(screen.getByRole('button', { name: 'Unpin Hazards' }));
        expect(onPinnedTabsChange).toHaveBeenLastCalledWith([]);
    });
});
