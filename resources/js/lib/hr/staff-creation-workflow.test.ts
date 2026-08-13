import { describe, expect, it } from 'vitest';

import {
    employeeCreationIsComplete,
    STAFF_CREATION_INTENT,
} from './staff-creation-workflow';

describe('canonical staff creation workflow contract', () => {
    const complete = {
        name: 'Casey Clinical',
        email: 'casey.clinical@example.test',
        role: 'clinical_lead',
        primarySiteId: '42',
    };

    it('pins the staff creation intent consumed by System Users and HR People', () => {
        expect(STAFF_CREATION_INTENT).toBe('staff');
    });

    it('requires name email role and Primary site, while allowing other fields to stay optional', () => {
        expect(employeeCreationIsComplete(complete)).toBe(true);

        for (const field of [
            'name',
            'email',
            'role',
            'primarySiteId',
        ] as const) {
            expect(
                employeeCreationIsComplete({ ...complete, [field]: '' }),
            ).toBe(false);
        }
    });
});
