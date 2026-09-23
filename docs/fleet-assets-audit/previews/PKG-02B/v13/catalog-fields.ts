import type { Field } from './operations';
const catalogs: Record<string, string[]> = {
    Purpose: [
        'Community transport',
        'Client appointment',
        'Service appointment',
        'Equipment delivery',
        'Staff travel',
        'Training',
    ],
    'Pickup / return and keys': [
        'Kōwhai House · key safe',
        'Fleet office · key desk',
        'Workshop reception',
    ],
    'Reminder title': [
        'Service booking follow-up',
        'Insurance renewal',
        'Registration renewal',
        'Evidence review',
        'Inspection follow-up',
    ],
    'Days before due': ['0', '3', '7', '14', '30'],
    'Kilometres before due': ['250', '500', '1000', '2000'],
};
export function catalogField(field: Field): Field {
    const options = catalogs[field.label];
    return options
        ? {
              ...field,
              type: field.type === 'number' ? 'catalog-number' : 'catalog',
              options,
          }
        : field;
}
