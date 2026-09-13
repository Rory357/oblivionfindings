import { Input } from '@/components/ui/input';
import { cleanup, render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';

import { Field } from './primitives';

afterEach(() => {
    cleanup();
    vi.restoreAllMocks();
});

describe('wizard Field control associations', () => {
    it('keeps the generated direct-input label association across rerenders', () => {
        const { rerender } = render(
            <Field label="Description">
                <Input defaultValue="Original" />
            </Field>,
        );
        const input = screen.getByLabelText('Description');
        const originalId = input.id;
        expect(originalId).not.toBe('');
        rerender(
            <Field label="Description">
                <Input defaultValue="Original" />
            </Field>,
        );
        expect(screen.getByLabelText('Description')).toBe(input);
        expect(input.id).toBe(originalId);
    });

    it('preserves and labels a direct control with an existing id', () => {
        render(
            <Field label="Name">
                <Input id="existing-name" />
            </Field>,
        );
        expect(screen.getByLabelText('Name')).toHaveAttribute(
            'id',
            'existing-name',
        );
    });

    it('does not pass DOM attributes to a Fragment and supports its explicit inner control', () => {
        const errors = vi
            .spyOn(console, 'error')
            .mockImplementation(() => undefined);
        render(
            <Field
                label="Evidence"
                htmlFor="evidence"
                labelId="evidence-label"
                errorId="evidence-error"
                error="Choose valid evidence."
            >
                <>
                    <Input id="evidence" aria-describedby="evidence-error" />
                    <p>Supporting detail</p>
                </>
            </Field>,
        );
        expect(screen.getByLabelText('Evidence')).toHaveAccessibleDescription(
            'Choose valid evidence.',
        );
        expect(screen.getByText('Evidence')).toHaveAttribute(
            'id',
            'evidence-label',
        );
        expect(errors.mock.calls.flat().join(' ')).not.toContain(
            'React.Fragment',
        );
    });
});
