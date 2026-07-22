import {
    GroupPillRail,
    TabSearchPalette,
    TierTwoTabs,
    type GroupedProfileNavGroup,
} from '@/components/page/grouped-profile-nav';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { User } from 'lucide-react';
import { readdirSync, readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { useState } from 'react';
import { describe, expect, it, vi } from 'vitest';

const root = process.cwd();
const tabsPath = resolve(root, 'resources/js/pages/sites/tabs');
const tabSources = readdirSync(tabsPath)
    .filter((file) => file.endsWith('.tsx'))
    .map((file) => ({
        file,
        source: readFileSync(resolve(tabsPath, file), 'utf8'),
    }));
const navSource = readFileSync(
    resolve(root, 'resources/js/components/page/grouped-profile-nav.tsx'),
    'utf8',
);

const groups: GroupedProfileNavGroup[] = [
    {
        key: 'overview',
        label: 'Overview',
        icon: User,
        tabs: [
            { key: 'overview', label: 'Overview', icon: User },
            { key: 'readiness', label: 'Readiness', icon: User },
        ],
    },
    {
        key: 'people',
        label: 'People',
        icon: User,
        tabs: [{ key: 'contacts', label: 'Contacts', icon: User }],
    },
];

function PaletteHarness() {
    const [open, setOpen] = useState(false);

    return (
        <>
            <button type="button" onClick={() => setOpen(true)}>
                Find sections
            </button>
            <TabSearchPalette
                open={open}
                onClose={() => setOpen(false)}
                groups={groups}
                onTab={vi.fn()}
                testIdPrefix="site-profile"
                searchLabel="Find a Site Profile section"
            />
        </>
    );
}

describe('site profile accessibility and responsive contract', () => {
    it('uses visible focus and a 44px pin target', () => {
        expect(navSource).toContain('role="tablist"');
        expect(navSource).toContain('focus-visible:ring-2');
        expect(navSource).toContain('h-11 w-11');
    });

    it('supports arrow-key navigation for groups and tabs', () => {
        const onOpenGroup = vi.fn();
        const onTab = vi.fn();
        render(
            <>
                <GroupPillRail
                    groups={groups}
                    openGroup="overview"
                    activeTab="overview"
                    onOpenGroup={onOpenGroup}
                    onSearch={vi.fn()}
                    testIdPrefix="site-profile"
                />
                <TierTwoTabs
                    tabs={groups[0].tabs}
                    activeTab="overview"
                    onTab={onTab}
                    renderLink={() => null}
                    testIdPrefix="site-profile"
                />
            </>,
        );

        const overviewGroup = screen.getByRole('button', {
            name: 'Overview',
        });
        const peopleGroup = screen.getByRole('button', { name: 'People' });
        overviewGroup.focus();
        fireEvent.keyDown(overviewGroup, { key: 'ArrowRight' });
        expect(peopleGroup).toHaveFocus();

        fireEvent.keyDown(screen.getByRole('tab', { name: 'Overview' }), {
            key: 'ArrowRight',
        });
        expect(onTab).toHaveBeenCalledWith('readiness');
    });

    it('applies tab semantics and keyboard navigation to link-backed tabs', () => {
        const onTab = vi.fn();
        const linkTabs = groups[0].tabs.map((tab) => ({
            ...tab,
            href: `/sites/1?tab=${tab.key}`,
        }));

        render(
            <TierTwoTabs
                tabs={linkTabs}
                activeTab="overview"
                onTab={onTab}
                renderLink={(tab, className, inner, tabProps) => (
                    <a href={tab.href} className={className} {...tabProps}>
                        {inner}
                    </a>
                )}
                testIdPrefix="site-profile"
                panelId="site-profile-tab-panel"
            />,
        );

        expect(screen.getByRole('tab', { name: 'Overview' })).toHaveAttribute(
            'href',
            '/sites/1?tab=overview',
        );
    });

    it('labels search, closes it with Escape, and restores a usable page', async () => {
        render(<PaletteHarness />);

        const opener = screen.getByRole('button', { name: 'Find sections' });
        opener.focus();
        fireEvent.click(opener);

        const search = screen.getByLabelText('Find a Site Profile section');
        expect(search).toBeVisible();
        await waitFor(() => expect(search).toHaveFocus());
        fireEvent.keyDown(document, { key: 'Escape' });
        expect(
            screen.queryByRole('dialog', { name: 'Jump to a section' }),
        ).not.toBeInTheDocument();
        expect(opener).toHaveFocus();
    });

    it('keeps every Site Profile tab action at least 44px high', () => {
        for (const { file, source } of tabSources) {
            const controls =
                source
                    .replaceAll('=>', 'ARROW')
                    .match(/<(?:Button|button)\b[\s\S]*?>/g) ?? [];
            for (const control of controls) {
                expect(control, `${file}: ${control}`).toContain('min-h-11');
            }
        }
    });

    it('uses text or accessible labels for status and responsive wrapping', () => {
        const readiness = readFileSync(
            resolve(tabsPath, 'readiness.tsx'),
            'utf8',
        );
        const attention = readFileSync(
            resolve(tabsPath, 'attention-panel.tsx'),
            'utf8',
        );
        const show = readFileSync(
            resolve(root, 'resources/js/pages/sites/show.tsx'),
            'utf8',
        );
        const responsiveSources = [
            show,
            navSource,
            ...tabSources.map(({ source }) => source),
        ].join('\n');

        expect(readiness).toContain("? 'Complete'");
        expect(readiness).toContain(": 'Incomplete'");
        expect(attention).toContain('aria-label="Critical"');
        expect(attention).toContain('aria-label="Warning"');
        expect(responsiveSources).toContain('flex-wrap');
        expect(responsiveSources).toContain('overflow-x-auto');
    });

    it('does not use browser-native confirmation APIs', () => {
        const profileSources = [
            readFileSync(
                resolve(root, 'resources/js/pages/sites/show.tsx'),
                'utf8',
            ),
            ...tabSources.map(({ source }) => source),
        ].join('\n');

        expect(profileSources).not.toMatch(
            /window\.(?:confirm|alert|prompt)|\bconfirm\s*\(/,
        );
    });

    it('keeps primary Site-owned dialogs described and the meal chart shrinkable', () => {
        const overviewDialogs = readFileSync(
            resolve(root, 'resources/js/pages/sites/_overview-dialogs.tsx'),
            'utf8',
        );
        const mealDialogs = readFileSync(
            resolve(root, 'resources/js/pages/sites/meal-planner/_dialogs.tsx'),
            'utf8',
        );
        const mealCalendar = readFileSync(
            resolve(
                root,
                'resources/js/pages/sites/meal-planner/_calendar-grid.tsx',
            ),
            'utf8',
        );

        expect(overviewDialogs).toContain('<DialogDescription>');
        expect(mealDialogs).toContain('<DialogDescription>');
        expect(mealCalendar).toContain(
            'flex w-full min-w-0 flex-1 items-end justify-between gap-1 sm:gap-2',
        );
    });

    it('keeps Documents and Meal Planner controls inside narrow viewports', () => {
        const documents = readFileSync(
            resolve(root, 'resources/js/pages/sites/documents.tsx'),
            'utf8',
        );
        const mealToolbar = readFileSync(
            resolve(root, 'resources/js/pages/sites/meal-planner/_hero.tsx'),
            'utf8',
        );

        expect(documents).toContain(
            'grid min-w-0 grid-cols-1 gap-2 sm:grid-cols-2',
        );
        expect(documents).toContain(
            'flex min-w-0 items-center justify-between gap-3',
        );
        expect(mealToolbar).toContain(
            'flex min-w-0 flex-wrap items-center gap-2',
        );
        expect(mealToolbar).toContain(
            'flex min-w-0 flex-col gap-2 sm:flex-row sm:items-center sm:justify-between md:contents',
        );
    });
});
