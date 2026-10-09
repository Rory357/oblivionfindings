import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import { afterEach, beforeEach, expect, it, vi } from 'vitest';
import {
    AddSiteDialog,
    type AddSiteReferenceData,
    type SiteWizardForm,
} from './add-site-dialog';

const transport = vi.hoisted(() => ({
    post: vi.fn(),
    rejection: null as Record<string, string> | null,
}));
vi.mock('@inertiajs/react', async () => {
    const React = await import('react');
    return {
        usePage: () => ({ props: {} }),
        useForm: (initial: SiteWizardForm) => {
            const [data, setData] = React.useState(initial);
            const [errors, setErrors] = React.useState<Record<string, string>>(
                {},
            );
            return {
                data,
                errors,
                processing: false,
                isDirty: true,
                setData: (
                    key:
                        | keyof SiteWizardForm
                        | ((current: SiteWizardForm) => SiteWizardForm),
                    value: unknown,
                ) => {
                    if (typeof key === 'function') setData(key);
                    else setData((current) => ({ ...current, [key]: value }));
                },
                post: (
                    url: string,
                    options: {
                        onError?: (errors: Record<string, string>) => void;
                    },
                ) => {
                    transport.post(url, data);
                    if (transport.rejection) {
                        setErrors(transport.rejection);
                        options.onError?.(transport.rejection);
                    }
                },
                clearErrors: vi.fn(),
                reset: vi.fn(),
            };
        },
    };
});
vi.mock('@/components/address-autocomplete', () => ({
    AddressAutocomplete: () => null,
}));
vi.mock('@/components/geofence-draw-map', () => ({ default: () => null }));

const reference: AddSiteReferenceData = {
    users: [],
    regionOptions: [],
    serviceContexts: [],
    coverageRoleKeys: [],
    credentialCatalogue: [
        {
            key: 'first_aid',
            name: 'First Aid Certificate',
            default_expiry_months: 12,
        },
    ],
    copyableSites: [
        {
            id: 10,
            name: 'Source House',
            type: 'house',
            coverage: [],
            credentials: [
                {
                    source_requirement_id: 101,
                    source_revision: 'a'.repeat(64),
                    name: 'Legacy recommendation',
                    category: 'recommended',
                    expiry_period_months: null,
                    applicability_mode: null,
                    hr_compliance_requirement_id: null,
                    minimum_qualified_staff: null,
                },
                {
                    source_requirement_id: 102,
                    source_revision: 'b'.repeat(64),
                    name: 'Two qualified workers',
                    category: 'mandatory',
                    expiry_period_months: 12,
                    applicability_mode: 'minimum_staff',
                    hr_compliance_requirement_id: 1,
                    minimum_qualified_staff: 2,
                },
            ],
        },
    ],
    qualificationRequirementOptions: {
        mapping_options: [
            {
                id: 1,
                name: 'Recognised first aid',
                code: 'first_aid',
                check_type: 'credential',
            },
        ],
        house_qualification_approach: 'per_requirement',
        new_requirement_defaults: {
            applicability_mode: null,
            minimum_qualified_staff: null,
        },
    },
};
afterEach(cleanup);
beforeEach(() => {
    transport.post.mockReset();
    transport.rejection = null;
});

function openRostering() {
    render(<AddSiteDialog {...reference} isOpen onClose={vi.fn()} />);
    fireEvent.click(
        screen.getByRole('button', {
            name: /^House\s*Residential home with client bedrooms$/,
        }),
    );
    fireEvent.change(screen.getByLabelText(/Site name/), {
        target: { value: 'Copied House' },
    });
    fireEvent.click(
        screen.getByRole('button', {
            name: /^Rostering\s*Coverage & credentials$/,
        }),
    );
}
function copyPattern() {
    fireEvent.click(
        screen.getByRole('combobox', { name: 'Start from an existing site…' }),
    );
    fireEvent.click(screen.getByRole('option', { name: 'Source House' }));
}
function attemptSave() {
    fireEvent.click(
        screen.getByRole('button', {
            name: /^Review\s*Risk, safety & create$/,
        }),
    );
    fireEvent.click(screen.getByRole('button', { name: 'Create site' }));
}

it('submits copied source evidence while preserving legacy blank and explicit minimum choices', () => {
    openRostering();
    copyPattern();
    attemptSave();
    expect(transport.post).toHaveBeenCalledTimes(1);
    expect(transport.post.mock.calls[0][1].copy_from).toBe('10');
    expect(transport.post.mock.calls[0][1].credentials).toMatchObject([
        {
            source_requirement_id: 101,
            source_revision: 'a'.repeat(64),
            applicability_mode: null,
            minimum_qualified_staff: null,
            hr_compliance_requirement_id: null,
            category: 'recommended',
        },
        {
            source_requirement_id: 102,
            source_revision: 'b'.repeat(64),
            applicability_mode: 'minimum_staff',
            minimum_qualified_staff: 2,
            hr_compliance_requirement_id: 1,
            category: 'mandatory',
        },
    ]);
});

it('requires a choice when a copied legacy requirement is changed', () => {
    openRostering();
    copyPattern();
    fireEvent.change(
        screen.getByLabelText('Legacy recommendation expiry period in months'),
        { target: { value: '12' } },
    );
    attemptSave();
    expect(transport.post).not.toHaveBeenCalled();
    expect(
        screen.getByText('Choose who needs this qualification.'),
    ).toBeVisible();
});

it('shows a rejected copy on the Rostering step and retains the entered details', () => {
    const message =
        'This copied requirement changed or is no longer available. Choose an applicability option or copy it again.';
    transport.rejection = { 'credentials.0.applicability_mode': message };
    openRostering();
    copyPattern();
    attemptSave();
    expect(transport.post).toHaveBeenCalledTimes(1);
    expect(screen.getByText(message)).toBeVisible();
    expect(
        screen.getByLabelText('Legacy recommendation expiry period in months'),
    ).toHaveValue(null);
    fireEvent.click(screen.getByRole('button', { name: /^Basics/ }));
    expect(screen.getByLabelText(/Site name/)).toHaveValue('Copied House');
    expect(screen.queryByText(/site created/i)).not.toBeInTheDocument();
});

it('still requires a choice for a new requirement added beside copied rows', () => {
    openRostering();
    copyPattern();
    fireEvent.click(
        screen.getByRole('button', {
            name: 'First Aid Certificate',
        }),
    );
    attemptSave();
    expect(transport.post).not.toHaveBeenCalled();
    expect(
        screen.getByText('Choose who needs this qualification.'),
    ).toBeVisible();
});

it('requires an explicit positive count after changing a copied blank to minimum coverage', () => {
    openRostering();
    copyPattern();
    fireEvent.click(
        screen.getAllByLabelText('Who needs this qualification?')[0],
    );
    fireEvent.click(
        screen.getByRole('option', {
            name: 'Minimum number of qualified workers',
        }),
    );
    attemptSave();
    expect(transport.post).not.toHaveBeenCalled();
    expect(
        screen.getByText('Enter a positive whole number of qualified workers.'),
    ).toBeVisible();
});
