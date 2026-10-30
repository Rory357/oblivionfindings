import { fireEvent, render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import { SelectInput } from './primitives';

describe('SelectInput controlled-state contract', () => {
    it('disables the trigger while preserving the current selection', () => {
        const onChange = vi.fn();
        render(
            <SelectInput
                disabled
                value="known"
                onChange={onChange}
                placeholder="Choose"
                ariaLabel="Pending example"
                options={[{ value: 'known', label: 'Known selection' }]}
            />,
        );
        const trigger = screen.getByRole('combobox', {
            name: 'Pending example',
        });
        expect(trigger).toBeDisabled();
        expect(trigger).toHaveTextContent('Known selection');
        fireEvent.click(trigger);
        expect(screen.queryByRole('listbox')).not.toBeInTheDocument();
        expect(onChange).not.toHaveBeenCalled();
    });
    it('is controlled from the first empty render through a persisted rerender', () => {
        const error = vi
            .spyOn(console, 'error')
            .mockImplementation(() => undefined);
        const onChange = vi.fn();
        const { rerender } = render(
            <SelectInput
                value=""
                onChange={onChange}
                placeholder="Choose a person"
                options={[
                    { value: '8', label: 'Moana Rangi' },
                    { value: '9', label: 'Tama Lewis' },
                ]}
            />,
        );

        rerender(
            <SelectInput
                value="9"
                onChange={onChange}
                placeholder="Choose a person"
                options={[
                    { value: '8', label: 'Moana Rangi' },
                    { value: '9', label: 'Tama Lewis' },
                ]}
            />,
        );

        expect(error.mock.calls.flat().join(' ')).not.toContain(
            'changing an uncontrolled input to be controlled',
        );
        error.mockRestore();
    });
});
