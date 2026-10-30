import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import { useState } from 'react';
import { afterEach, expect, it } from 'vitest';
import {
    PlannedLocationFields,
    plannedLocationDraft,
} from './planned-location-fields';
import { SessionStartForm } from './session-start-form';
import type { SessionPickers } from './types';

afterEach(cleanup);
it('opens the actual shared location selector and can clear a previously chosen source', async () => {
    function Form() {
        const [value, setValue] = useState(plannedLocationDraft());
        return (
            <>
                <PlannedLocationFields
                    value={value}
                    onChange={setValue}
                    canGeocode={false}
                />
                <output aria-label="Selected method">
                    {value.method || 'none'}
                </output>
            </>
        );
    }
    render(<Form />);
    const select = screen.getByRole('combobox', {
        name: 'Choose how to set the planned location',
    });
    fireEvent.keyDown(select, { key: 'ArrowDown' });
    fireEvent.click(
        await screen.findByRole('option', { name: 'Enter coordinates' }),
    );
    expect(screen.getByLabelText('Selected method')).toHaveTextContent(
        'manual',
    );
    fireEvent.keyDown(select, { key: 'ArrowDown' });
    fireEvent.click(
        await screen.findByRole('option', { name: 'No planned location' }),
    );
    expect(screen.getByLabelText('Selected method')).toHaveTextContent('none');
    expect(screen.queryByRole('spinbutton')).not.toBeInTheDocument();
});

it('opens the real standalone and optional Client choices without empty Radix values', async () => {
    const pickers: SessionPickers = {
        sites: [],
        workers: [],
        clients: [],
        shifts: [],
        check_in_statuses: ['ok'],
        check_in_intervals: [30],
    };
    render(
        <SessionStartForm
            pickers={pickers}
            returnTo="/health-safety/lone-workers"
            onCancel={() => {}}
        />,
    );
    fireEvent.keyDown(
        screen.getByRole('combobox', { name: 'Choose a current shift' }),
        { key: 'ArrowDown' },
    );
    fireEvent.click(
        await screen.findByRole('option', { name: 'Not linked to a shift' }),
    );
    fireEvent.keyDown(
        screen.getByRole('combobox', { name: 'Choose a client if relevant' }),
        { key: 'ArrowDown' },
    );
    fireEvent.click(await screen.findByRole('option', { name: 'No client' }));
    expect(
        screen.getByRole('button', { name: 'Start working alone' }),
    ).toBeInTheDocument();
});
