import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { Sections } from '@/pages/fleet-assets/settings/_ui';
import type { ViewKey } from './_model';
import { SET_VIEWS, settingsSectionTabs, type Built } from './_nav';

afterEach(cleanup);

const allBuilt: Built = Object.fromEntries(
    Object.entries(SET_VIEWS).map(([view, schema]) => [
        view,
        schema.secs.map(([key]) => key),
    ]),
);

describe('Medication Settings section navigation with the real shared renderer', () => {
    it('renders the initial Rules strip including Records & reporting and can select it', () => {
        const onChange = vi.fn();
        render(
            <Sections
                tabs={settingsSectionTabs(allBuilt, 'rules')}
                value="overview"
                onChange={onChange}
            />,
        );
        expect(
            screen
                .getByRole('tab', { name: 'Overview' })
                .getAttribute('aria-selected'),
        ).toBe('true');
        const records = screen.getByRole('tab', {
            name: 'Records & reporting',
        });
        expect(records.querySelector('svg')).not.toBeNull();
        fireEvent.click(records);
        expect(onChange).toHaveBeenCalledWith('records');
    });

    it('renders Error triage in the Alerts strip and can select it', () => {
        const onChange = vi.fn();
        render(
            <Sections
                tabs={settingsSectionTabs(allBuilt, 'alerts')}
                value="overview"
                onChange={onChange}
            />,
        );
        const triage = screen.getByRole('tab', { name: 'Error triage' });
        expect(triage.querySelector('svg')).not.toBeNull();
        fireEvent.click(triage);
        expect(onChange).toHaveBeenCalledWith('triage');
    });

    it.each(Object.keys(SET_VIEWS) as ViewKey[])(
        'renders every declared %s section with its icon',
        (view) => {
            const tabs = settingsSectionTabs(allBuilt, view);
            render(
                <Sections tabs={tabs} value={tabs[0].key} onChange={vi.fn()} />,
            );
            expect(screen.getAllByRole('tab')).toHaveLength(tabs.length);
            for (const tab of tabs)
                expect(
                    screen
                        .getByRole('tab', { name: tab.label })
                        .querySelector('svg'),
                ).not.toBeNull();
        },
    );
});
