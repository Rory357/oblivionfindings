import { cleanup, fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import axios from 'axios';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { SetupAlertRules } from './_alerts';
import { SettingsSave, useSettingsDraft } from './_settings';
import type { SettingRow } from './_types';

vi.mock('@inertiajs/react', async () => {
    const actual = await vi.importActual<typeof import('@inertiajs/react')>('@inertiajs/react');
    return { ...actual, router: { ...actual.router, reload: vi.fn(), visit: vi.fn(), on: () => () => undefined } };
});

/** Setup › Alerts & checks as it comes from the server: snoozing off, escalation after 2 and 5 minutes. */
function settings(overrides: Partial<Record<string, SettingRow>> = {}): Record<string, SettingRow> {
    return {
        'alerts.safety.snooze': { type: 'bool', on: false, default: false, changed: false, loosens_when_on: true, loosens_when_off: false, can_change: true },
        'alerts.safety.snooze_max': { type: 'minutes', value: 15, default: 15, choices: [5, 10, 15, 30, 60], changed: false, can_change: true, loosens_when_up: true },
        'alerts.escalate.urgent': { type: 'minutes', value: 2, default: 2, choices: [1, 2, 3, 5], changed: false, can_change: true, loosens_when_up: true },
        'alerts.escalate.care': { type: 'minutes', value: 5, default: 5, choices: [2, 3, 5, 10, 15], changed: false, can_change: true, loosens_when_up: true },
        ...overrides,
    } as Record<string, SettingRow>;
}

function AlertsSetup({ rows }: { rows: Record<string, SettingRow> }) {
    const draft = useSettingsDraft(rows);
    return (
        <>
            <SetupAlertRules data={{ rules: 0 }} draft={draft} />
            <SettingsSave draft={draft} what="Alerts & checks" />
        </>
    );
}

afterEach(() => {
    cleanup();
    vi.restoreAllMocks();
});

describe('Setup › Alerts & checks: if nobody says they’re on it (safety fix round)', () => {
    it('shows when the on-call manager is told, and that safety and care alerts can’t be snoozed', () => {
        render(<AlertsSetup rows={settings()} />);

        expect(screen.getByText('If nobody says they’re on it')).toBeInTheDocument();
        expect(screen.getByRole('combobox', { name: 'Panic, duress, falls, alarms and forced doors' })).toHaveTextContent('2 minutes');
        expect(screen.getByRole('combobox', { name: 'Care rules, water leaks and hot water' })).toHaveTextContent('5 minutes');
        expect(screen.getByRole('switch', { name: 'Allow snoozing safety and care alerts' })).not.toBeChecked();
        expect(screen.getByText('Not snoozable')).toBeInTheDocument();
        expect(screen.queryByRole('combobox', { name: 'Longest snooze' })).not.toBeInTheDocument();
    });

    it('treats allowing snoozes as loosening a safety check: it asks why before saving', async () => {
        const patch = vi.spyOn(axios, 'patch').mockResolvedValue({ data: {} });
        render(<AlertsSetup rows={settings()} />);

        fireEvent.click(screen.getByRole('switch', { name: 'Allow snoozing safety and care alerts' }));
        expect(screen.getByRole('combobox', { name: 'Longest snooze' })).toHaveTextContent('15 minutes');
        fireEvent.click(screen.getByRole('button', { name: 'Review changes' }));

        const dialog = await screen.findByRole('dialog');
        expect(within(dialog).getByText('This loosens a safety check.')).toBeInTheDocument();
        expect(within(dialog).getByText(/People could hide a panic button, alarm, fall or care alert/)).toBeInTheDocument();
        fireEvent.click(within(dialog).getByRole('button', { name: 'Save, including the loosened check' }));
        expect(await within(dialog).findByRole('alert')).toHaveTextContent('Say why you’re loosening this safety check.');
        expect(patch).not.toHaveBeenCalled();

        fireEvent.change(within(dialog).getByLabelText('Why loosen it?'), { target: { value: 'Night staff need a moment to settle the person' } });
        fireEvent.click(within(dialog).getByRole('button', { name: 'Save, including the loosened check' }));

        await waitFor(() =>
            expect(patch).toHaveBeenCalledWith('/security-devices/setup/settings', {
                changes: { 'alerts.safety.snooze': true },
                reason: 'Night staff need a moment to settle the person',
            }),
        );
    });

    it('says a person who can’t change these settings can’t, and offers no save', () => {
        const locked = settings();
        for (const row of Object.values(locked)) row.can_change = false;
        render(<AlertsSetup rows={locked} />);

        expect(screen.getByRole('switch', { name: 'Allow snoozing safety and care alerts' })).toBeDisabled();
        expect(screen.getByRole('combobox', { name: 'Panic, duress, falls, alarms and forced doors' })).toBeDisabled();
        expect(screen.queryByRole('button', { name: 'Review changes' })).not.toBeInTheDocument();
    });
});
