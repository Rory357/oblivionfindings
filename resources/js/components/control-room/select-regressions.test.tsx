import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { useState } from 'react';
import { describe, expect, it } from 'vitest';
import { TaskAssigneeSelect } from './alert-workspace-dialog';
import { AlertClientSelect } from './new-alert-wizard';

const people = [
    { id: 8, name: 'Moana Rangi' },
    { id: 9, name: 'Tama Lewis' },
    { id: 10, name: 'Ari Patel' },
];

function ClientHarness() {
    const [value, setValue] = useState('');

    return (
        <>
            <AlertClientSelect
                value={value}
                onChange={setValue}
                clients={people}
            />
            <output data-testid="client-value">{value}</output>
        </>
    );
}

function AssigneeHarness() {
    const [value, setValue] = useState('');

    return (
        <>
            <TaskAssigneeSelect
                value={value}
                onChange={setValue}
                staff={people}
            />
            <output data-testid="assignee-value">{value}</output>
        </>
    );
}

describe('Control Room client and task-assignee Select regressions', () => {
    it('commits the client clicked with the mouse and shows it when reopened', () => {
        render(<ClientHarness />);

        const trigger = screen.getByRole('combobox', {
            name: 'No client linked',
        });
        fireEvent.click(trigger);
        fireEvent.click(screen.getByRole('option', { name: 'Tama Lewis' }));

        expect(screen.getByTestId('client-value')).toHaveTextContent('9');
        expect(trigger).toHaveTextContent('Tama Lewis');

        fireEvent.click(trigger);
        expect(
            screen.getByRole('option', { name: 'Tama Lewis' }),
        ).toHaveAttribute('data-state', 'checked');
    });

    it('commits the client highlighted and confirmed with the keyboard', async () => {
        render(<ClientHarness />);

        const trigger = screen.getByRole('combobox', {
            name: 'No client linked',
        });
        trigger.focus();
        fireEvent.keyDown(trigger, { key: 'ArrowDown' });
        await waitFor(() =>
            expect(document.activeElement).toHaveTextContent('Moana Rangi'),
        );
        fireEvent.keyDown(document.activeElement as Element, {
            key: 'ArrowDown',
        });
        await waitFor(() =>
            expect(document.activeElement).toHaveTextContent('Tama Lewis'),
        );
        fireEvent.keyDown(document.activeElement as Element, { key: 'Enter' });

        await waitFor(() =>
            expect(screen.getByTestId('client-value')).toHaveTextContent('9'),
        );
        expect(trigger).toHaveTextContent('Tama Lewis');
    });

    it('commits exactly the task assignee clicked instead of another highlighted user', () => {
        render(<AssigneeHarness />);

        const trigger = screen.getByRole('combobox', { name: 'Unassigned' });
        fireEvent.click(trigger);
        fireEvent.pointerMove(
            screen.getByRole('option', { name: 'Moana Rangi' }),
        );
        fireEvent.click(screen.getByRole('option', { name: 'Ari Patel' }));

        expect(screen.getByTestId('assignee-value')).toHaveTextContent('10');
        expect(trigger).toHaveTextContent('Ari Patel');
        expect(trigger).not.toHaveTextContent('Moana Rangi');
    });
});
