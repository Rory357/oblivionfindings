import { Field } from '@/components/wizard/primitives';
import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';

import { FileDropzone } from './file-dropzone';

afterEach(cleanup);

describe('FileDropzone label and keyboard compatibility', () => {
    it('preserves its default title and Enter/Space picker activation', () => {
        const { container } = render(
            <FileDropzone title="Drop evidence here" onFiles={vi.fn()} />,
        );
        const input =
            container.querySelector<HTMLInputElement>('input[type="file"]')!;
        const picked = vi.fn();
        input.addEventListener('click', picked);
        const zone = screen.getByRole('button', { name: /Drop evidence here/ });
        zone.focus();
        for (const key of ['Enter', ' ']) {
            expect(fireEvent.keyDown(zone, { key })).toBe(false);
        }
        expect(picked).toHaveBeenCalledTimes(2);
        expect(zone).toHaveFocus();
    });

    it.each([false, true])(
        'associates the native picker and named zone with a label and error when disabled=%s',
        (disabled) => {
            render(
                <Field
                    label="Photos or files"
                    htmlFor="photos-input"
                    labelId="photos-label"
                    errorId="photos-error"
                    error="Choose a smaller file."
                >
                    <>
                        <FileDropzone
                            id="photos"
                            aria-labelledby="photos-label photos-title"
                            aria-describedby="photos-error"
                            aria-invalid
                            disabled={disabled}
                            title="Drop photos here"
                            onFiles={vi.fn()}
                        />
                    </>
                </Field>,
            );
            const label = screen.getByText(
                'Photos or files',
            ) as HTMLLabelElement;
            const input = label.control as HTMLInputElement;
            const zone = screen.getByRole('button', {
                name: 'Photos or files Drop photos here',
            });
            expect(input).toHaveAttribute('type', 'file');
            expect(zone).toHaveAccessibleDescription('Choose a smaller file.');
            expect(input).toHaveAccessibleDescription('Choose a smaller file.');
            expect(zone).toHaveAttribute('aria-invalid', 'true');
            const picked = vi.fn();
            input.addEventListener('click', picked);
            fireEvent.click(label);
            fireEvent.keyDown(zone, { key: 'Enter' });
            fireEvent.keyDown(zone, { key: ' ' });
            expect(picked).toHaveBeenCalledTimes(disabled ? 0 : 3);
            expect(input.disabled).toBe(disabled);
        },
    );
});
