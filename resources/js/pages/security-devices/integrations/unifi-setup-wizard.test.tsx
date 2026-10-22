import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import { useState } from 'react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import {
    UnifiSetupWizard,
    type UnifiSetupChange,
    type UnifiSetupValues,
    type UnifiSetupWizardProps,
} from './unifi-setup-wizard';

const token = 'a'.repeat(64);
const secret = 'FAKE-UNIFI-PRIVATE-DRAFT-ONLY';
const values: UnifiSetupValues = {
    api_key: secret,
    site_id: '4',
    mapping_token: token,
    refresh_interval_minutes: 15,
    alert_motion_events: true,
    alert_device_offline: true,
    quiet_hours_start: '23:59',
    quiet_hours_end: null,
};
function props(
    overrides: Partial<UnifiSetupWizardProps> = {},
): UnifiSetupWizardProps {
    return {
        kind: 'key',
        values,
        onChange: vi.fn(),
        providerConnection: null,
        sites: [
            { id: 4, name: 'Harbour Site', type: 'house' },
            { id: 5, name: 'Hill Site', type: 'office' },
        ],
        discoveredSites: [
            { mapping_token: token, name: 'Harbour Network', device_count: 0 },
        ],
        pending: false,
        uncertain: false,
        error: '',
        success: null,
        onSubmit: vi.fn(),
        onClose: vi.fn(),
        ...overrides,
    };
}
function Controlled({ data }: { data: UnifiSetupWizardProps }) {
    const [current, setCurrent] = useState(data.values);
    const change: UnifiSetupChange = (field, value) => {
        data.onChange(field, value);
        setCurrent((before) => ({ ...before, [field]: value }));
    };
    return <UnifiSetupWizard {...data} values={current} onChange={change} />;
}
const continueStep = () =>
    fireEvent.click(screen.getByRole('button', { name: 'Continue' }));
afterEach(async () => {
    cleanup();
    await new Promise((resolve) => setTimeout(resolve, 0));
});

describe('Controlled UniFi setup wizard', () => {
    it('reviews masked credentials, retains containment meaning, and keeps the supplied success until Close', () => {
        const data = props({
            kind: 'rotate',
            providerConnection: {
                status: 'disabled',
                secret_last4: '1234',
                disabled_reason: 'credential_compromise',
                disabled_at: '2026-10-14T00:00:00Z',
                requires_credential_replacement: true,
            },
        });
        const ui = render(<Controlled data={data} />);
        expect(screen.getByText('Credential compromise')).toBeInTheDocument();
        expect(
            screen.getByText(/revoke that key in UniFi as well/),
        ).toBeInTheDocument();
        continueStep();
        expect(screen.getByText('New key entered')).toBeInTheDocument();
        expect(screen.getByText('••••1234')).toBeInTheDocument();
        expect(document.body.textContent).not.toContain(secret);
        fireEvent.click(
            screen.getByRole('button', { name: 'Save replacement key' }),
        );
        expect(data.onSubmit).toHaveBeenCalledTimes(1);
        expect(data.onClose).not.toHaveBeenCalled();
        const completed = {
            ...data,
            success: {
                title: 'Replacement key saved',
                description: 'The key was stored.',
            },
            providerConnection: {
                status: 'disconnected' as const,
                secret_last4: '9999',
                disabled_reason: 'credential_compromise' as const,
            },
        };
        ui.rerender(<Controlled data={completed} />);
        expect(
            screen.getByRole('heading', { name: 'Replacement key saved' }),
        ).toBeInTheDocument();
        expect(
            screen.getByText(
                /Collection remains stopped until a connection test succeeds/,
            ),
        ).toBeInTheDocument();
        expect(document.body.textContent).not.toContain(secret);
        expect(screen.queryByText('Connected')).not.toBeInTheDocument();
        expect(
            screen.getByText(/does not revoke the compromised key in UniFi/),
        ).toBeInTheDocument();
        ui.rerender(<Controlled data={completed} />);
        expect(data.onClose).not.toHaveBeenCalled();
        fireEvent.click(screen.getByRole('button', { name: 'Close' }));
        expect(data.onClose).toHaveBeenCalledTimes(1);
    });
    it.each([
        { name: 'empty', key: '' },
        { name: 'whitespace-only', key: '   ' },
        { name: 'too long', key: 'x'.repeat(4097) },
    ])(
        'blocks a $name key without dropping other controlled entries',
        ({ key }) => {
            const data = props({ values: { ...values, api_key: key } });
            render(<Controlled data={data} />);
            continueStep();
            expect(screen.getByLabelText(/^API key/)).toHaveValue(key);
            expect(
                screen.queryByRole('button', { name: 'Save key' }),
            ).not.toBeInTheDocument();
            expect(data.onSubmit).not.toHaveBeenCalled();
            fireEvent.change(screen.getByLabelText(/^API key/), {
                target: { value: 'x'.repeat(4096) },
            });
            continueStep();
            expect(
                screen.getByRole('button', { name: 'Save key' }),
            ).toBeEnabled();
            expect(document.body.textContent).not.toContain('x'.repeat(4096));
        },
    );
    it('selects existing mapping choices through search without turning query text into identity', () => {
        const data = props({
            kind: 'mapping',
            values: { ...values, site_id: '', mapping_token: '' },
        });
        render(<Controlled data={data} />);
        fireEvent.click(
            screen.getByRole('combobox', { name: 'Approved Site' }),
        );
        fireEvent.change(
            screen.getByRole('combobox', { name: 'Search Approved Site' }),
            { target: { value: 'invented ID' } },
        );
        expect(
            screen.getByText('No current choices match this search.'),
        ).toBeInTheDocument();
        expect(data.onChange).not.toHaveBeenCalled();
        fireEvent.change(
            screen.getByRole('combobox', { name: 'Search Approved Site' }),
            { target: { value: 'Harbour' } },
        );
        fireEvent.click(
            screen.getByRole('option', { name: 'Harbour Site · Site 4' }),
        );
        expect(data.onChange).toHaveBeenCalledWith('site_id', '4');
        fireEvent.click(
            screen.getByRole('combobox', { name: 'UniFi location' }),
        );
        fireEvent.change(
            screen.getByRole('combobox', { name: 'Search UniFi location' }),
            { target: { value: 'Harbour' } },
        );
        fireEvent.click(
            screen.getByRole('option', { name: 'Harbour Network' }),
        );
        expect(data.onChange).toHaveBeenCalledWith('mapping_token', token);
        continueStep();
        expect(screen.getByText('Harbour Site')).toBeInTheDocument();
        expect(screen.getByText('Harbour Network')).toBeInTheDocument();
        expect(screen.getByText('0')).toBeInTheDocument();
        expect(document.body.textContent).not.toContain(token);
        fireEvent.click(screen.getByRole('button', { name: 'Save mapping' }));
        expect(data.onSubmit).toHaveBeenCalledTimes(1);
    });
    it.each(['Site', 'provider location'])(
        'blocks a reviewed mapping when its current %s disappears',
        (removed) => {
            const data = props({ kind: 'mapping' });
            const ui = render(<Controlled data={data} />);
            continueStep();
            expect(
                screen.getByRole('button', { name: 'Save mapping' }),
            ).toBeEnabled();
            ui.rerender(
                <Controlled
                    data={{
                        ...data,
                        sites: removed === 'Site' ? [] : data.sites,
                        discoveredSites:
                            removed === 'provider location'
                                ? []
                                : data.discoveredSites,
                    }}
                />,
            );
            expect(
                screen.getByRole('button', { name: 'Save mapping' }),
            ).toBeDisabled();
            expect(screen.getByRole('alert')).toHaveTextContent(
                'missing or no longer available',
            );
            fireEvent.click(
                screen.getByRole('button', { name: 'Save mapping' }),
            );
            expect(data.onSubmit).not.toHaveBeenCalled();
            expect(document.body.textContent).not.toContain(token);
        },
    );
    it('preserves both false alert values and exact quiet minutes in New Zealand time through review', () => {
        const data = props({
            kind: 'defaults',
            values: { ...values, quiet_hours_end: '06:30' },
        });
        render(<Controlled data={data} />);
        continueStep();
        fireEvent.click(
            screen.getByRole('checkbox', { name: 'Alert on motion events' }),
        );
        fireEvent.click(
            screen.getByRole('checkbox', {
                name: 'Alert when a device goes offline',
            }),
        );
        expect(data.onChange).toHaveBeenCalledWith(
            'alert_motion_events',
            false,
        );
        expect(data.onChange).toHaveBeenCalledWith(
            'alert_device_offline',
            false,
        );
        continueStep();
        expect(screen.getAllByText('Off')).toHaveLength(2);
        expect(screen.getByText('23:59')).toBeInTheDocument();
        expect(screen.getByText('06:30')).toBeInTheDocument();
        expect(
            screen.getByText(
                'New Zealand time. Critical alerts are never held back.',
            ),
        ).toBeInTheDocument();
        fireEvent.click(screen.getByRole('button', { name: 'Save defaults' }));
        expect(data.onSubmit).toHaveBeenCalledTimes(1);
    });
    it.each([
        {
            name: 'only a start',
            start: '22:00',
            end: null,
            message:
                'Set an end time as well as a start time, or clear both to turn quiet hours off.',
        },
        {
            name: 'only an end',
            start: null,
            end: '06:00',
            message:
                'Set a start time as well as an end time, or clear both to turn quiet hours off.',
        },
        {
            name: 'the same start and end',
            start: '22:00',
            end: '22:00',
            message: 'Quiet hours must start and end at different times.',
        },
    ])('refuses quiet hours with $name', ({ start, end, message }) => {
        const data = props({
            kind: 'defaults',
            values: {
                ...values,
                quiet_hours_start: start,
                quiet_hours_end: end,
            },
        });
        render(<Controlled data={data} />);
        continueStep();
        continueStep();
        expect(screen.getByText(message)).toBeInTheDocument();
        expect(
            screen.queryByRole('button', { name: 'Save defaults' }),
        ).not.toBeInTheDocument();
        expect(data.onSubmit).not.toHaveBeenCalled();
    });
    it('shows no completeness meter for these short setup flows', () => {
        render(<Controlled data={props()} />);
        expect(screen.queryByRole('progressbar')).not.toBeInTheDocument();
    });
    it('rejects invalid interval and clock values, and explicitly clears an optional time to null', () => {
        const data = props({
            kind: 'defaults',
            values: {
                ...values,
                refresh_interval_minutes: 1441,
                quiet_hours_start: '24:00',
            },
        });
        render(<Controlled data={data} />);
        continueStep();
        expect(screen.getByLabelText(/^Refresh interval/)).toHaveAttribute(
            'aria-invalid',
            'true',
        );
        expect(data.onSubmit).not.toHaveBeenCalled();
        fireEvent.change(screen.getByLabelText(/^Refresh interval/), {
            target: { value: '1440' },
        });
        continueStep();
        continueStep();
        expect(
            screen.getByText('Choose a valid start time from 00:00 to 23:59.'),
        ).toBeInTheDocument();
        expect(
            screen.queryByRole('button', { name: 'Save defaults' }),
        ).not.toBeInTheDocument();
        fireEvent.click(
            screen.getByRole('button', { name: 'Clear quiet hours start' }),
        );
        expect(data.onChange).toHaveBeenCalledWith('quiet_hours_start', null);
        continueStep();
        expect(
            screen.getByRole('button', { name: 'Save defaults' }),
        ).toBeEnabled();
    });
    it('blocks close and repeat submission while pending and requires a current read after uncertain save', () => {
        const data = props();
        const ui = render(<Controlled data={data} />);
        continueStep();
        ui.rerender(<Controlled data={{ ...data, pending: true }} />);
        fireEvent.click(screen.getByRole('button', { name: 'Close' }));
        fireEvent.keyDown(screen.getByRole('dialog'), { key: 'Escape' });
        expect(data.onClose).not.toHaveBeenCalled();
        expect(screen.getByRole('button', { name: 'Cancel' })).toBeDisabled();
        expect(screen.getByRole('button', { name: /Saving/ })).toBeDisabled();
        ui.rerender(
            <Controlled
                data={{
                    ...data,
                    uncertain: true,
                    error: 'The save could not be confirmed.',
                }}
            />,
        );
        expect(screen.getByRole('button', { name: 'Save key' })).toBeDisabled();
        expect(screen.getByRole('button', { name: 'Back' })).toBeDisabled();
        expect(
            screen
                .getAllByRole('alert')
                .map((item) => item.textContent)
                .join(' '),
        ).toContain('Read the current saved status before trying again');
        fireEvent.click(screen.getByRole('button', { name: 'Save key' }));
        expect(data.onSubmit).not.toHaveBeenCalled();
        ui.rerender(
            <Controlled
                data={{ ...data, error: 'The secret manager is unavailable.' }}
            />,
        );
        expect(screen.getByRole('button', { name: 'Save key' })).toBeEnabled();
        fireEvent.click(screen.getByRole('button', { name: 'Back' }));
        expect(screen.getByLabelText(/^API key/)).toHaveValue(secret);
        expect(screen.getByRole('alert')).toHaveTextContent(
            'The secret manager is unavailable.',
        );
    });
});
