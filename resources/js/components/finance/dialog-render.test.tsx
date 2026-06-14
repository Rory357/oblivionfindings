import { readFileSync } from 'node:fs';

import { cleanup, render } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';

// Mock Inertia so the dialogs render in isolation (no Inertia app/page context).
vi.mock('@inertiajs/react', () => ({
    useForm: (initial: Record<string, unknown>) => ({
        data: initial,
        setData: vi.fn(),
        post: vi.fn(),
        processing: false,
        errors: {},
        reset: vi.fn(),
        clearErrors: vi.fn(),
    }),
    usePage: () => ({ props: { auth: { can: {} } } }),
    router: { visit: vi.fn() },
}));

import { NewAccountDialog } from './new-account-dialog';
import { NewJournalDialog } from './new-journal-dialog';

afterEach(cleanup);

/**
 * Regression guard for the production crash where opening these modals blanked
 * the page: Radix `<Select.Item />` throws when given an empty-string value, and
 * the optional pickers (cost centre / funding stream / parent account / tax rate)
 * were passing `{ value: '', label: 'None' }`.
 *
 * NOTE: a render test does NOT catch this — Radix renders Select items lazily
 * (only when the dropdown opens), which jsdom never triggers, so the closed
 * dialog mounts fine. The source-pattern assertion below is what actually guards
 * the regression; the render smoke tests catch other mount-time crashes.
 */
describe('finance modals', () => {
    it('NewAccountDialog mounts (open) without throwing', () => {
        expect(() =>
            render(
                <NewAccountDialog
                    open
                    onClose={() => {}}
                    parentAccounts={[{ id: 1, code: '1000', name: 'Bank', type: 'asset' }]}
                    taxRates={[{ id: 1, code: 'GST', name: 'GST', rate: '15' }]}
                    fundingStreams={[{ id: 1, code: 'F1', name: 'Fund' }]}
                />,
            ),
        ).not.toThrow();
    });

    it('NewJournalDialog mounts (open) without throwing', () => {
        expect(() =>
            render(
                <NewJournalDialog
                    open
                    onClose={() => {}}
                    accounts={[{ id: 1, code: '1000', name: 'Bank' }]}
                    costCentres={[{ id: 1, code: 'CC', name: 'Centre' }]}
                    fundingStreams={[{ id: 1, code: 'F1', name: 'Fund' }]}
                />,
            ),
        ).not.toThrow();
    });

    it('never pass an empty-string Select option value (Radix forbids it — the crash regression)', () => {
        for (const file of ['new-account-dialog.tsx', 'new-journal-dialog.tsx']) {
            const src = readFileSync(`resources/js/components/finance/${file}`, 'utf8');
            // The crash pattern: an options-array entry like `{ value: '', label: … }`.
            expect(src, `${file} reintroduced an empty-string Select option`).not.toMatch(
                /value:\s*(['"])\1\s*,/,
            );
        }
    });
});
