import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import { afterEach, beforeEach, expect, it, vi } from 'vitest';
import {
    AddSiteDialog,
    withLeadContact,
    type AddSiteReferenceData,
    type SiteEditData,
    type SiteEditLinks,
    type SiteWizardForm,
} from './add-site-dialog';

const transport = vi.hoisted(() => ({ post: vi.fn() }));
vi.mock('@inertiajs/react', async () => {
    const React = await import('react');
    return {
        usePage: () => ({ props: {} }),
        useForm: (initial: SiteWizardForm) => {
            const [data, setData] = React.useState(initial);
            const transformer = React.useRef(
                (current: SiteWizardForm): unknown => current,
            );
            return {
                data,
                errors: {},
                processing: false,
                isDirty: true,
                transform: (fn: (current: SiteWizardForm) => unknown) => {
                    transformer.current = fn;
                },
                setData: (
                    key:
                        | keyof SiteWizardForm
                        | ((current: SiteWizardForm) => SiteWizardForm),
                    value: unknown,
                ) => {
                    if (typeof key === 'function') setData(key);
                    else setData((current) => ({ ...current, [key]: value }));
                },
                post: (url: string, options: { onSuccess?: () => void }) => {
                    transport.post(url, transformer.current(data));
                    options.onSuccess?.();
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

const staff = [
    {
        id: 7,
        name: 'Aroha Ngata',
        role: 'House lead',
        phone: '09 555 0101',
        email: 'aroha.work@example.test',
    },
    { id: 8, name: 'Tama Rewi', role: null, phone: null, email: null },
];

const reference: AddSiteReferenceData = {
    users: staff,
    regionOptions: [],
    serviceContexts: [],
    copyableSites: [],
    credentialCatalogue: [],
    coverageRoleKeys: [],
};

const site: SiteEditData = {
    id: 42,
    type: 'house',
    name: 'Kowhai House',
    brand_colour: '#336699',
    phone: '09 111 2222',
    email: null,
    primary_contact_user_id: 7,
    is_active: true,
    total_capacity: 4,
    address_line_1: '1 Queen Street',
    address_line_2: null,
    suburb: null,
    city: 'Auckland',
    postcode: '1010',
    country: 'New Zealand',
    region: 'Auckland',
    latitude: '-36.84850000',
    longitude: '174.76330000',
    access_instructions: null,
    rooms: [{ id: 3, name: 'Bedroom 1', notes: null, is_assignable: true }],
    resources: [],
    zones: [],
    contacts: [
        {
            id: 11,
            type: 'site_lead',
            user_id: 7,
            name: 'Aroha Ngata',
            role: 'House lead',
            phone: '09 555 0101',
            email: 'aroha.work@example.test',
            is_primary: true,
            notes: null,
        },
        {
            id: 12,
            type: 'family',
            user_id: null,
            name: 'Mere Parata',
            role: 'Mother',
            phone: '021 777 888',
            email: null,
            is_primary: false,
            notes: 'Weekends',
        },
    ],
    medication_storage_location: 'Office cabinet',
    documents_count: 2,
    rent_amount: null,
    rent_frequency: null,
    lease_start_date: null,
    lease_end_date: null,
    landlord_name: null,
    landlord_contact: null,
    weekly_food_budget: '180.25',
    is_high_risk: false,
    is_high_needs: false,
    risk_notes: null,
    risk_review_date: null,
    emergency_plan_location: null,
    notes: 'Gate code at the office',
    boundaries: [
        {
            id: 5,
            name: 'Kowhai House Geofence',
            type: 'circle',
            radius_m: 120,
            breach_type: 'both',
            is_active: true,
        },
    ],
    coverage: [
        {
            name: 'Day cover',
            coverage_type: 'day',
            day_of_week: 'mon',
            starts_time: '07:00',
            ends_time: '15:00',
            minimum_staff: 2,
            role_requirements: [],
            allow_overstaffing: true,
            shift_type: 'standard',
            service_context_id: null,
        },
    ],
    credentials: [{ id: 1, name: 'First Aid', category: 'mandatory' }],
};
const links: SiteEditLinks = {
    shift_coverage: '/sites/42?tab=shift_coverage',
    staff_requirements: '/sites/42?tab=staff_requirements',
    documents: '/sites/42?tab=documents',
    boundaries: null,
};

afterEach(cleanup);
beforeEach(() => transport.post.mockReset());

function openEdit(onSaved = vi.fn(), onClose = vi.fn()) {
    render(
        <AddSiteDialog
            {...reference}
            isOpen
            onClose={onClose}
            onSaved={onSaved}
            edit={{ site, links }}
        />,
    );
    return { onSaved, onClose };
}
const step = (name: RegExp) =>
    fireEvent.click(screen.getByRole('button', { name }));

it('opens as Edit site with the saved details', () => {
    openEdit();
    expect(screen.getAllByText('Edit site').length).toBeGreaterThan(0);
    expect(screen.getByLabelText(/Site name/)).toHaveValue('Kowhai House');
    expect(screen.getByText('#336699')).toBeVisible();
});

it('saves through the update route and sends only what changed', () => {
    const { onSaved, onClose } = openEdit();
    fireEvent.change(screen.getByLabelText(/Site name/), {
        target: { value: 'Kowhai House (renamed)' },
    });
    step(/^Review\s*Risk, safety & save$/);
    fireEvent.click(screen.getByRole('button', { name: 'Save changes' }));

    expect(transport.post).toHaveBeenCalledTimes(1);
    const [url, payload] = transport.post.mock.calls[0];
    expect(url).toBe('/sites/42');
    expect(payload).toMatchObject({
        _method: 'put',
        _modal: true,
        name: 'Kowhai House (renamed)',
        brand_colour: '#336699',
        weekly_food_budget: '180.25',
        notes: 'Gate code at the office',
        latitude: '-36.8485',
    });
    // Unchanged lists, rostering and the saved geofence are not sent.
    for (const key of [
        'contacts',
        'rooms',
        'collections',
        'coverage',
        'credentials',
        'geofence',
        'assets',
        'checklists',
    ]) {
        expect(payload).not.toHaveProperty(key);
    }
    expect(onSaved).toHaveBeenCalled();
    expect(onClose).toHaveBeenCalled();
});

it('sends a changed contact list, with staff rows as a link only', () => {
    openEdit();
    step(/^Contacts\s*Who to call$/);
    fireEvent.click(
        screen.getAllByRole('button', { name: 'Remove contact' })[1],
    );
    step(/^Review\s*Risk, safety & save$/);
    fireEvent.click(screen.getByRole('button', { name: 'Save changes' }));

    const payload = transport.post.mock.calls[0][1];
    expect(payload.collections).toEqual(['contacts']);
    expect(payload.contacts).toEqual([
        {
            id: 11,
            type: 'site_lead',
            user_id: '7',
            is_primary: true,
            notes: '',
        },
    ]);
});

it('shows a staff contact’s work details and keeps rostering read-only', () => {
    openEdit();
    step(/^Contacts\s*Who to call$/);
    expect(screen.getByText('09 555 0101')).toBeVisible();
    expect(screen.getByText('aroha.work@example.test')).toBeVisible();
    expect(screen.getByDisplayValue('Mere Parata')).toBeVisible();

    step(/^Rostering\s*Coverage & credentials$/);
    expect(screen.getByText('Day cover')).toBeVisible();
    expect(
        screen.getByRole('link', { name: /Change coverage/ }),
    ).toHaveAttribute('href', '/sites/42?tab=shift_coverage');
    expect(screen.queryByText('Quick coverage presets')).toBeNull();

    step(/^Location\s*Address & geofence$/);
    expect(screen.getByText('Kowhai House Geofence')).toBeVisible();
    // One radius control only: the map's own (mocked here), none in the step.
    expect(screen.queryAllByRole('slider')).toHaveLength(0);
});

it('adds a Site Lead contact for the lead picked on Basics, and moves it with the lead', () => {
    const added = withLeadContact([], '', '7', staff);
    expect(added).toHaveLength(1);
    expect(added[0]).toMatchObject({
        type: 'site_lead',
        source: 'staff',
        user_id: '7',
        name: 'Aroha Ngata',
        phone: '09 555 0101',
        is_primary: true,
        lead_prefill: true,
    });

    const moved = withLeadContact(added, '7', '8', staff);
    expect(moved).toHaveLength(1);
    expect(moved[0]).toMatchObject({
        user_id: '8',
        name: 'Tama Rewi',
        phone: '',
    });

    expect(withLeadContact(moved, '8', '', staff)).toHaveLength(0);
});
