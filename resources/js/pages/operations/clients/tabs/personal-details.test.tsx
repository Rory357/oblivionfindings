import { fireEvent, render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';

import { PersonalDetailsTab } from './personal-details';

describe('Personal details gender', () => {
    it('shows the readable label', () => {
        render(
            <PersonalDetailsTab
                client={{
                    id: 1,
                    gender: 'another_gender',
                    gender_description: 'Takatāpui',
                    gender_label: 'Another gender: Takatāpui',
                    gender_recorded_as: null,
                }}
            />,
        );

        expect(
            screen.getByText('Another gender: Takatāpui'),
        ).toBeInTheDocument();
        expect(screen.queryByRole('alert')).not.toBeInTheDocument();
    });

    it('asks someone to choose when an old typed answer could not be mapped', () => {
        const onEdit = vi.fn();
        render(
            <PersonalDetailsTab
                client={{
                    id: 2,
                    gender: null,
                    gender_label: null,
                    gender_recorded_as: 'see notes',
                }}
                onEdit={onEdit}
            />,
        );

        expect(screen.getByRole('alert')).toHaveTextContent(
            'Gender was recorded as ‘see notes’. Please choose an option.',
        );
        expect(screen.getByText('Recorded as ‘see notes’')).toBeInTheDocument();

        fireEvent.click(
            screen.getByRole('button', { name: 'Choose an option' }),
        );
        expect(onEdit).toHaveBeenCalledTimes(1);
    });
});
