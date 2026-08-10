import { describe, expect, it } from 'vitest';

import { shortModelType } from './audit-log';

describe('HR canonical audit model labels', () => {
    it('renders application events without an auditable model', () => {
        expect(shortModelType(null)).toBe('System');
        expect(shortModelType(undefined)).toBe('System');
    });

    it('keeps the short class name for model-backed events', () => {
        expect(shortModelType('App\\Models\\User')).toBe('User');
    });
});
