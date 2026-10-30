import { fireEvent, render, screen } from '@testing-library/react';
import { expect, it, vi } from 'vitest';
import { PaperEntryDialog } from './_dialogs';

function choose(label: string, option: string) {
    fireEvent.pointerDown(screen.getByRole('combobox', { name: label }), {
        button: 0,
        ctrlKey: false,
        pointerType: 'mouse',
    });
    fireEvent.click(screen.getByRole('option', { name: option }));
}

it('retains the chosen paper medicine and outcome while leaving actual clinical facts blank', () => {
    render(
        <PaperEntryDialog
            downtimeId={1}
            dose={null}
            prnOrders={[
                {
                    id: 17,
                    person: 'Synthetic person',
                    medicine: 'Synthetic tablet',
                    dosage: '1 tablet',
                    dose_unit: 'tablet',
                },
            ]}
            staff={[{ id: 2, name: 'Synthetic worker' }]}
            recoveryStock={{}}
            actorId={2}
            onClose={vi.fn()}
        />,
    );
    choose(
        'As-needed medicine on paper',
        'Synthetic person · Synthetic tablet',
    );
    expect(
        screen.getByRole('combobox', { name: 'As-needed medicine on paper' }),
    ).toHaveTextContent('Synthetic person · Synthetic tablet');
    choose('Outcome written on paper', 'Given');
    expect(
        screen.getByRole('combobox', { name: 'As-needed medicine on paper' }),
    ).toHaveTextContent('Synthetic person · Synthetic tablet');
    expect(
        screen.getByRole('combobox', { name: 'Outcome written on paper' }),
    ).toHaveTextContent('Given');
    expect(
        screen.getByRole('button', {
            name: 'The actual time on paper date: Choose date',
        }),
    ).toBeInTheDocument();
    expect(
        screen.getByRole('combobox', {
            name: 'Who actually gave it / recorded the outcome on paper',
        }),
    ).toHaveTextContent('Choose from the paper');
    fireEvent.click(screen.getByRole('button', { name: 'Continue' }));
    expect(
        screen.getByRole('spinbutton', {
            name: 'Actual amount given (tablet)',
        }),
    ).toHaveValue(null);
    fireEvent.click(screen.getByRole('button', { name: 'Back' }));
    expect(
        screen.getByRole('combobox', { name: 'As-needed medicine on paper' }),
    ).toHaveTextContent('Synthetic person · Synthetic tablet');
});
