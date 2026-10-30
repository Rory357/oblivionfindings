import { fireEvent, render, screen } from '@testing-library/react';
import { useState } from 'react';
import { describe, expect, it } from 'vitest';
import {
    HouseQualificationFields,
    copyHouseQualification,
    houseQualificationErrors,
    houseQualificationSummary,
    newHouseQualification,
    type HouseQualificationOptions,
    type HouseQualificationValues,
} from './qualification-requirement-fields';
const options: HouseQualificationOptions = {
    mapping_options: [
        {
            id: 12,
            name: 'First aid',
            code: 'first_aid',
            check_type: 'credential',
        },
    ],
    house_qualification_approach: 'per_requirement',
    new_requirement_defaults: {
        applicability_mode: null,
        minimum_qualified_staff: null,
    },
};
function Harness({
    initial = newHouseQualification(options),
}: {
    initial?: HouseQualificationValues;
}) {
    const [value, setValue] = useState(initial);
    return (
        <>
            <HouseQualificationFields
                id="test-house"
                value={value}
                options={options.mapping_options}
                onChange={(patch) => setValue({ ...value, ...patch })}
            />
            <output data-testid="values">{JSON.stringify(value)}</output>
        </>
    );
}
describe('House qualification choices', () => {
    it('requires a per-requirement choice, then requires a positive explicit minimum', () => {
        render(<Harness />);
        expect(
            screen.getByLabelText('Who needs this qualification?'),
        ).toHaveTextContent('Choose for this requirement');
        expect(
            houseQualificationErrors(newHouseQualification(options)),
        ).toHaveProperty('applicability_mode');
        fireEvent.click(screen.getByLabelText('Who needs this qualification?'));
        fireEvent.click(
            screen.getByRole('option', {
                name: 'Minimum number of qualified workers',
            }),
        );
        const count = screen.getByLabelText('Minimum qualified workers');
        expect(count).toHaveValue(null);
        fireEvent.change(count, { target: { value: '2' } });
        expect(JSON.parse(screen.getByTestId('values').textContent!)).toEqual({
            hr_compliance_requirement_id: null,
            applicability_mode: 'minimum_staff',
            minimum_qualified_staff: '2',
        });
        expect(screen.getByText(/fill the roster in stages/)).toBeVisible();
        fireEvent.click(screen.getByLabelText('Who needs this qualification?'));
        fireEvent.click(screen.getByRole('option', { name: 'Every worker' }));
        expect(
            screen.queryByLabelText('Minimum qualified workers'),
        ).not.toBeInTheDocument();
        expect(
            JSON.parse(screen.getByTestId('values').textContent!)
                .minimum_qualified_staff,
        ).toBeNull();
    });
    it('uses defaults only for new rows and does not rewrite copied explicit or unresolved choices', () => {
        const organisation = {
            ...options,
            house_qualification_approach: 'all_workers' as const,
            new_requirement_defaults: {
                applicability_mode: 'all_workers' as const,
                minimum_qualified_staff: null,
            },
        };
        expect(newHouseQualification(organisation).applicability_mode).toBe(
            'all_workers',
        );
        expect(
            copyHouseQualification({
                applicability_mode: null,
                minimum_qualified_staff: null,
                hr_compliance_requirement_id: 12,
            }),
        ).toEqual({
            applicability_mode: null,
            minimum_qualified_staff: null,
            hr_compliance_requirement_id: 12,
        });
        expect(
            copyHouseQualification({
                applicability_mode: 'minimum_staff',
                minimum_qualified_staff: 3,
                hr_compliance_requirement_id: 12,
            }),
        ).toEqual({
            applicability_mode: 'minimum_staff',
            minimum_qualified_staff: 3,
            hr_compliance_requirement_id: 12,
        });
    });
    it.each([null, '', 0, -1, 1.5, '1e2', 4294967296])(
        'rejects invalid minimum %s without inventing coverage',
        (minimum) => {
            expect(
                houseQualificationErrors({
                    hr_compliance_requirement_id: 12,
                    applicability_mode: 'minimum_staff',
                    minimum_qualified_staff: minimum,
                }),
            ).toHaveProperty('minimum_qualified_staff');
            expect(
                houseQualificationSummary({
                    applicability_mode: 'minimum_staff',
                    minimum_qualified_staff: minimum,
                }),
            ).toContain('has not been set');
        },
    );
    it('retains an unavailable saved mapping visibly until the user chooses a replacement', () => {
        render(
            <Harness
                initial={{
                    hr_compliance_requirement_id: 88,
                    applicability_mode: 'all_workers',
                    minimum_qualified_staff: null,
                }}
            />,
        );
        expect(screen.getByText(/The existing link is unavailable/)).toBeVisible();
        expect(
            JSON.parse(screen.getByTestId('values').textContent!)
                .hr_compliance_requirement_id,
        ).toBe(88);
        fireEvent.click(
            screen.getByRole('combobox', { name: 'Recognised qualification' }),
        );
        fireEvent.change(
            screen.getByLabelText('Search recognised qualification'),
            { target: { value: 'First aid' } },
        );
        fireEvent.click(screen.getByRole('option', { name: /First aid/ }));
        expect(
            JSON.parse(screen.getByTestId('values').textContent!)
                .hr_compliance_requirement_id,
        ).toBe(12);
        expect(screen.queryByText(/The existing link is unavailable/)).not.toBeInTheDocument();
    });
});
