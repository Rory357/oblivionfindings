import { expect, it } from 'vitest';
import { applicablePathways, reportedPathway } from './intake-funding';

const options = [
    {
        id: 'service_path',
        label: 'Service funding',
        service_ids: ['supported_living'],
        personal_income: false,
    },
    {
        id: 'income_path',
        label: 'Income assistance',
        service_ids: [],
        personal_income: true,
    },
];
it('prunes a previously selected pathway when support changes or the enquiry becomes minimal', () => {
    expect(
        reportedPathway(options, 'supported_living', 'service_path')?.id,
    ).toBe('service_path');
    expect(
        reportedPathway(options, 'other_support', 'service_path'),
    ).toBeUndefined();
    expect(
        reportedPathway(options, 'supported_living', 'service_path', true),
    ).toBeUndefined();
    expect(
        reportedPathway(options, 'supported_living', 'removed_path'),
    ).toBeUndefined();
});
it('keeps unknown-service navigation and personal income distinct without inferring an approval', () => {
    expect(applicablePathways(options, 'unsure')).toHaveLength(2);
    expect(applicablePathways(options, 'other_support')).toEqual([options[1]]);
    expect(
        reportedPathway(options, 'other_support', 'income_path')
            ?.personal_income,
    ).toBe(true);
});
