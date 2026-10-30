import { fireEvent, render, screen, within } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';

// Disposing of a PPE item is final, so "Record disposal" only asks; the
// request goes from the ConfirmDialog's "Dispose item" button.

type VisitOptions = {
    onSuccess?: (page: { props: object }) => void;
    onFinish?: () => void;
};

const inertia = vi.hoisted(() => ({
    post: vi.fn(),
    flash: {} as { error?: string },
}));

vi.mock('@inertiajs/react', async () => {
    const React = await import('react');
    return {
        useForm: <T extends Record<string, string>>(initial: T) => {
            const [data, setDataState] = React.useState(initial);
            const [errors, setErrors] = React.useState<Record<string, string>>(
                {},
            );
            return {
                data,
                errors,
                processing: false,
                setData: (key: keyof T, value: string) =>
                    setDataState((current) => ({ ...current, [key]: value })),
                setError: (key: string, value: string) =>
                    setErrors((current) => ({ ...current, [key]: value })),
                clearErrors: (key: string) =>
                    setErrors((current) => {
                        const next = { ...current };
                        delete next[key];
                        return next;
                    }),
                post: (url: string, options: VisitOptions) => {
                    inertia.post(url, data);
                    options.onSuccess?.({ props: { flash: inertia.flash } });
                    options.onFinish?.();
                },
            };
        },
    };
});

vi.mock('@/components/wizard/primitives', async () => {
    const actual = await vi.importActual<
        typeof import('@/components/wizard/primitives')
    >('@/components/wizard/primitives');
    return {
        ...actual,
        // Radix Select doesn't open in jsdom; a native select keeps the contract.
        SelectInput: ({
            value,
            onChange,
            placeholder,
            options,
        }: {
            value: string;
            onChange: (v: string) => void;
            placeholder: string;
            options: { value: string; label: string }[];
        }) => (
            <select
                aria-label={placeholder}
                value={value}
                onChange={(e) => onChange(e.target.value)}
            >
                <option value="">{placeholder}</option>
                {options.map((o) => (
                    <option key={o.value} value={o.value}>
                        {o.label}
                    </option>
                ))}
            </select>
        ),
    };
});

import { DisposeDialog } from './ppe-action-dialogs';

function renderDialog(onClose = vi.fn()) {
    render(
        <DisposeDialog
            open
            onClose={onClose}
            inventoryId={42}
            itemLabel="Face shield · FS-0042"
        />,
    );
    return onClose;
}

const recordDisposal = () =>
    fireEvent.click(screen.getByRole('button', { name: 'Record disposal' }));

beforeEach(() => {
    inertia.post.mockClear();
    inertia.flash = {};
});

describe('PPE › Dispose item', () => {
    it('asks for the method first, and sends nothing without one', () => {
        renderDialog();
        recordDisposal();
        expect(
            screen.getByText('Choose how the item was disposed of.'),
        ).toBeTruthy();
        expect(screen.queryByRole('alertdialog')).toBeNull();
        expect(inertia.post).not.toHaveBeenCalled();
    });

    it('asks before disposing, then sends the same request', () => {
        const onClose = renderDialog();
        fireEvent.change(screen.getByLabelText('Choose a method'), {
            target: { value: 'Returned to supplier' },
        });
        recordDisposal();

        const confirm = screen.getByRole('alertdialog');
        expect(within(confirm).getByText('Dispose of this item?')).toBeTruthy();
        expect(within(confirm).getByText(/Face shield · FS-0042/)).toBeTruthy();
        expect(
            within(confirm).getByText(/can’t be returned to stock/),
        ).toBeTruthy();
        expect(inertia.post).not.toHaveBeenCalled();

        fireEvent.click(
            within(confirm).getByRole('button', { name: 'Dispose item' }),
        );
        expect(inertia.post).toHaveBeenCalledWith(
            '/health-safety/ppe/inventory/42/dispose',
            { disposal_method: 'Returned to supplier', reason: '' },
        );
        expect(onClose).toHaveBeenCalled();
        expect(screen.queryByRole('alertdialog')).toBeNull();
    });

    it('Cancel on the confirm sends nothing and keeps the form', () => {
        const onClose = renderDialog();
        fireEvent.change(screen.getByLabelText('Choose a method'), {
            target: { value: 'Recycled' },
        });
        recordDisposal();
        fireEvent.click(
            within(screen.getByRole('alertdialog')).getByRole('button', {
                name: 'Cancel',
            }),
        );
        expect(screen.queryByRole('alertdialog')).toBeNull();
        expect(inertia.post).not.toHaveBeenCalled();
        expect(onClose).not.toHaveBeenCalled();
        expect(
            (screen.getByLabelText('Choose a method') as HTMLSelectElement)
                .value,
        ).toBe('Recycled');
    });

    it('the confirm button uses the destructive tone', () => {
        renderDialog();
        fireEvent.change(screen.getByLabelText('Choose a method'), {
            target: { value: 'Recycled' },
        });
        recordDisposal();
        const button = within(screen.getByRole('alertdialog')).getByRole(
            'button',
            { name: 'Dispose item' },
        );
        expect(button.className).toMatch(/destructive/);
    });
});
