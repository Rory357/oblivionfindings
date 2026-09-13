import { fireEvent, render, screen, within } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import {
    AddVendorDialog,
    EditVendorDialog,
    type VendorRecord,
} from './_dialogs';

const transport = vi.hoisted(() => ({
    send: vi.fn(),
    errors: {} as Record<string, string>,
}));
vi.mock('@inertiajs/react', async () => {
    const React = await vi.importActual<typeof import('react')>('react');
    return {
        router: { delete: vi.fn() },
        useForm: (initial: Record<string, unknown>) => {
            const [original] = React.useState(initial);
            const [data, setData] = React.useState(initial);
            const [errors, setErrors] = React.useState<Record<string, string>>(
                {},
            );
            const submit = (
                method: string,
                url: string,
                options: {
                    onSuccess: () => void;
                    onError: (errors: Record<string, string>) => void;
                },
            ) => {
                transport.send(method, url, data);
                if (Object.keys(transport.errors).length) {
                    setErrors(transport.errors);
                    options.onError(transport.errors);
                } else options.onSuccess();
            };
            return {
                data,
                errors,
                processing: false,
                isDirty: JSON.stringify(data) !== JSON.stringify(original),
                setData: (key: string, value: unknown) =>
                    setData((current) => ({ ...current, [key]: value })),
                setError: (key: string, message: string) =>
                    setErrors((current) => ({ ...current, [key]: message })),
                clearErrors: (...keys: string[]) =>
                    setErrors((current) =>
                        Object.fromEntries(
                            Object.entries(current).filter(
                                ([key]) => keys.length && !keys.includes(key),
                            ),
                        ),
                    ),
                reset: () => setData(original),
                post: (url: string, options: Parameters<typeof submit>[2]) =>
                    submit('POST', url, options),
                put: (url: string, options: Parameters<typeof submit>[2]) =>
                    submit('PUT', url, options),
            };
        },
    };
});

const site = { id: 7, name: 'Test house', type: 'house' };
const identity = () => {
    fireEvent.change(screen.getByLabelText(/Company name/), {
        target: { value: 'Synthetic vendor' },
    });
    fireEvent.change(screen.getByLabelText(/Category \/ Service type/), {
        target: { value: 'IT support' },
    });
};
const next = () =>
    fireEvent.click(screen.getByRole('button', { name: 'Continue' }));

describe('vendor entity wizard', () => {
    beforeEach(() => {
        transport.send.mockClear();
        transport.errors = {};
    });

    it('keeps contact and compliance entries across steps and creates only after review', () => {
        const close = vi.fn();
        render(
            <AddVendorDialog
                isOpen
                siteId={7}
                lockedSite={site}
                onClose={close}
            />,
        );
        expect(screen.queryByLabelText('Contact name')).toBeNull();
        identity();
        next();
        fireEvent.change(screen.getByLabelText('Contact name'), {
            target: { value: 'Test contact' },
        });
        next();
        fireEvent.click(screen.getByLabelText('Insurance verified'));
        next();
        expect(screen.getByText('Test contact')).toBeInTheDocument();
        expect(transport.send).not.toHaveBeenCalled();
        fireEvent.click(screen.getByRole('button', { name: 'Add vendor' }));
        expect(transport.send).toHaveBeenCalledWith(
            'POST',
            '/sites/7/vendors',
            expect.objectContaining({
                company_name: 'Synthetic vendor',
                contact_name: 'Test contact',
                insurance_verified: true,
            }),
        );
        expect(
            screen.getByRole('heading', { name: 'Vendor added' }),
        ).toBeInTheDocument();
        expect(close).not.toHaveBeenCalled();
        fireEvent.click(screen.getByRole('button', { name: 'Add another' }));
        expect(screen.getByLabelText(/Company name/)).toHaveValue('');
    });

    it('prevents skipped required site and identity fields from being submitted', () => {
        render(<AddVendorDialog isOpen sites={[site]} onClose={vi.fn()} />);
        fireEvent.click(
            screen.getByRole('button', {
                name: /Review\s*Check before saving/,
            }),
        );
        fireEvent.click(screen.getByRole('button', { name: 'Add vendor' }));
        expect(
            screen.getByText('Select a site for this vendor.'),
        ).toBeInTheDocument();
        expect(screen.getByText('Enter the company name.')).toBeInTheDocument();
        expect(transport.send).not.toHaveBeenCalled();
    });

    it('preserves a dirty draft when cancelling discard and closes only on confirmation', () => {
        const close = vi.fn();
        render(<AddVendorDialog isOpen siteId={7} onClose={close} />);
        identity();
        fireEvent.click(screen.getByRole('button', { name: 'Cancel' }));
        const confirm = screen.getByRole('alertdialog');
        fireEvent.click(
            within(confirm).getByRole('button', { name: 'Cancel' }),
        );
        expect(screen.getByLabelText(/Company name/)).toHaveValue(
            'Synthetic vendor',
        );
        expect(close).not.toHaveBeenCalled();
        fireEvent.click(screen.getByRole('button', { name: 'Cancel' }));
        fireEvent.click(screen.getByRole('button', { name: 'Discard draft' }));
        expect(close).toHaveBeenCalledOnce();
    });

    it('returns to the field with a server error without losing the draft', () => {
        transport.errors = { email: 'This email is invalid.' };
        render(<AddVendorDialog isOpen siteId={7} onClose={vi.fn()} />);
        identity();
        fireEvent.click(
            screen.getByRole('button', {
                name: /Review\s*Check before saving/,
            }),
        );
        fireEvent.click(screen.getByRole('button', { name: 'Add vendor' }));
        expect(screen.getByLabelText('Email')).toBeInTheDocument();
        expect(screen.getByText('This email is invalid.')).toBeInTheDocument();
        expect(
            screen.queryByRole('heading', { name: 'Vendor added' }),
        ).toBeNull();
    });

    it('edits the same wizard with a fixed site and preserves untouched compliance fields', () => {
        const close = vi.fn();
        const vendor = {
            id: 9,
            company_name: 'Existing vendor',
            service_type: 'IT',
            insurance_provider: 'Test insurer',
            preferred_contact_method: 'phone',
        } as VendorRecord;
        render(
            <EditVendorDialog
                isOpen
                siteId={7}
                lockedSite={site}
                vendor={vendor}
                onClose={close}
            />,
        );
        expect(screen.getByLabelText(/Company name/)).toHaveValue(
            'Existing vendor',
        );
        expect(screen.queryByRole('combobox')).toBeNull();
        fireEvent.click(
            screen.getByRole('button', {
                name: /Review\s*Check before saving/,
            }),
        );
        fireEvent.click(screen.getByRole('button', { name: 'Save changes' }));
        expect(transport.send).toHaveBeenCalledWith(
            'PUT',
            '/sites/7/vendors/9',
            expect.objectContaining({ insurance_provider: 'Test insurer' }),
        );
        expect(close).toHaveBeenCalledOnce();
    });
});
