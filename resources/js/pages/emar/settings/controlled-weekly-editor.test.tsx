import { fireEvent, render, screen } from '@testing-library/react';
import { useState } from 'react';
import { describe, expect, it, vi } from 'vitest';
import { SettingsCtx, type SettingsContext } from './_context';
import { ControlledProductSettings } from './_controlled-product';
import {
    validateView,
    type Draft,
    type SettingDefinition,
    type SettingsPayload,
} from './_model';

const anchor: SettingDefinition = {
    group: 'controlled_counts',
    key: 'weekly_anchor',
    scope: 'organisation',
    section: 'controlled_product',
    label: 'Weekly count day and time',
    options: [],
    default: 'off',
    range: null,
    unit: null,
    paired_with: null,
    rank: null,
    numeric: null,
    kind: 'weekly_anchor',
    timezone: 'Pacific/Auckland',
    weekday_options: [{ value: '1', label: 'Monday' }],
    when_not_configured: 'Choose a weekly count time.',
};
const settings: SettingsPayload = {
    groups: {
        controlled_counts: {
            key: 'controlled_counts',
            view: 'rules',
            effect: '',
            audit_event: '',
            keys: ['cadence', 'weekly_anchor'],
        },
    },
    definitions: {
        controlled_counts: {
            weekly_anchor: anchor,
            cadence: {
                ...anchor,
                key: 'cadence',
                kind: undefined,
                options: [
                    { value: 'shift', label: 'Every shift change' },
                    { value: 'week', label: 'Weekly' },
                ],
                default: '',
            },
        },
    },
    values: { controlled_counts: { cadence: 'shift', weekly_anchor: 'off' } },
    reviewed: {},
    site_values: {},
    site_reviewed: {},
    history: [],
    can_manage_organisation: true,
};

function Editor({ initial, show = 'all' }: { initial: Draft; show?: string }) {
    const [draft, setDraft] = useState(initial);
    const context: SettingsContext = {
        s: settings,
        draft,
        setDraft,
        canEdit: () => true,
        go: vi.fn(),
        open: vi.fn(),
        close: vi.fn(),
        flash: vi.fn(),
        freshAfter: 0,
        leave: vi.fn(),
        errors: {},
        clearError: vi.fn(),
    };
    return (
        <SettingsCtx.Provider value={context}>
            <ControlledProductSettings
                q=""
                show={show}
                clear={vi.fn()}
                houses={[]}
                houseIds={[]}
            />
            <output aria-label="Review errors">
                {JSON.stringify(validateView(settings, draft, 'rules'))}
            </output>
        </SettingsCtx.Provider>
    );
}

describe('weekly count editor recovery', () => {
    it('keeps required timing visible when the changed-only filter shows a new weekly cadence', () => {
        render(
            <Editor
                show="changed"
                initial={{ controlled_counts: { cadence: 'week' } }}
            />,
        );
        expect(
            screen.getByRole('combobox', { name: 'Weekly count day' }),
        ).toBeVisible();
        expect(screen.getByText('Timing not configured')).toBeVisible();
    });

    it('discards only incomplete local timing when switching back to a shift schedule', () => {
        render(
            <Editor
                initial={{
                    controlled_counts: {
                        cadence: 'week',
                        weekly_anchor: '{"day":1,"time":""}',
                    },
                }}
            />,
        );
        expect(screen.getByText('Timing not configured')).toBeVisible();
        fireEvent.click(
            screen.getByRole('button', { name: 'Every shift change' }),
        );
        expect(
            screen.queryByRole('combobox', { name: 'Weekly count day' }),
        ).not.toBeInTheDocument();
        expect(screen.getByLabelText('Review errors')).toHaveTextContent('{}');
    });
});
