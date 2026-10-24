import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react';
import type { AnchorHTMLAttributes, ReactNode } from 'react';
import { afterEach, expect, it, vi } from 'vitest';
const io = vi.hoisted(() => ({ get: vi.fn(), replace: vi.fn(), request: vi.fn(), read: vi.fn(), url: '/health-safety/substances/9?section=overview' }));
vi.mock('axios', () => ({ default: { request: io.request, get: io.read, isAxiosError: (value: unknown) => !!value && typeof value === 'object' && 'response' in value } }));
vi.mock('@inertiajs/react', () => ({ Head: () => null,
    Link: ({ href, preserveState: _state, preserveScroll: _scroll, ...props }: AnchorHTMLAttributes<HTMLAnchorElement> & { href: string; preserveState?: boolean; preserveScroll?: boolean }) => <a href={href} {...props} />,
    usePage: () => ({ url: io.url, props: { auth: { user: { id: 7 }, can: { governance: { view: false } } } } }), router: { get: io.get, replace: io.replace } }));
vi.mock('@/layouts/app-layout', () => ({ default: ({ children }: { children: ReactNode }) => <main>{children}</main> }));
vi.mock('@/hooks/use-safety-workspace-drafts', async () => {
    const React = await import('react');
    return { SafetyFormDraftContext: React.createContext(null), useSafetyDraftState: vi.fn(), useSafetyWorkspaceDrafts: () => ({ register: vi.fn(), request: (callback: () => void) => callback(), confirmation: null }) };
});
import type { ChemicalDetail, ChemicalIndexProps, ChemicalSdsRecord } from '@/lib/health-safety/chemical-contract';
import ChemicalRegister from './_register-page';
import ChemicalShow from './show';
import { ChemicalCommandForm, chemicalFormPayload } from './_command-form';

function detail(): ChemicalDetail {
    return { id: 9, name: 'Recorded chemical', common_name: null, un_number: null, hsno_approval: null, hsno_classification: null, hazard_classifications: [], ghs_pictograms: [],
        signal_word: null, hazard_statements: null, precautionary_statements: null, physical_form: 'liquid', requires_tracking: false, is_controlled_substance: false,
        status: 'active', status_reason: null, ppe_required: 'Recorded gloves', storage_requirements: null, handling_precautions: null, first_aid_measures: null,
        firefighting_measures: null, spill_procedures: null, exposure_limit_type: null, exposure_limit_value: null,
        source_integrity: 'valid', attribution_state: 'recorded', source_revision: 4, expected_version: 4, parent_expected_version: null, source_version_id: 13,
        sds_state: 'missing', current_sds: { state: 'none', id: null, available: false, currency: 'unknown' }, current_sds_id: null, inventory_state: 'unknown',
        sds_records: [], storage_locations: [], history: { source_integrity: 'valid', items: [] }, counts: { sds: 0, storage: 0, exposures: null },
        physical_counts: { sds: 0, storage: 0 }, repair_counts: { sds: 0, storage: 0 }, truncated: { sds: false, storage: false },
        archived_storage: { items: [], recorded_count: 0, physical_record_count: 0, repair_count: 0, truncated: false }, exposure_access: 'restricted', exposure_records: null,
        created_by: 'Synthetic recorder', created_at: '2026-09-03T00:00:00Z', updated_at: '2026-09-19T00:00:00Z', can: { create: true, manage: true } };
}
function index(): ChemicalIndexProps {
    const d = detail(); return { filters: { q: 'cleaning', tab: 'all', site_id: 3, physical_form: null, status: null, controlled: null, sds_state: null, period: 90 }, tab: 'all',
        tabCounts: { all: 1, active: 1, controlled: 0, sds_expiring: 0, sds_missing: 1, inactive: 0 }, rows: { data: [{ ...d, storage_count: 0, physical_storage_count: 0, storage_repair_count: 0 }], current_page: 1, per_page: 20, total: 1, last_page: 1 },
        source_integrity: 'valid', physical_record_count: 1, repair_counts: { catalogue: 0, sds: 0, storage: 0 }, hero: { live: { active: 1, controlled: 0, sds_current: 0, storage_locations: 0, inventory_state: 'unknown' },
            attention: { sds_expiring: 0, sds_missing: 1, sds_unknown: 0, awaiting_review: null, exposures: null } }, badges: { sds_to_action: 1, worksafe_awaiting: null },
        exposure_access: 'restricted', sites: [{ id: 3, name: 'Kauri House' }], can: { create: true, manage: true }, detail: null };
}
afterEach(() => { cleanup(); vi.resetAllMocks(); io.url = '/health-safety/substances/9?section=overview'; });
it('opens permanent chemical records with exact filtered Back and source-backed Site meter destinations', () => {
    render(<ChemicalRegister {...index()} />);
    // The whole row opens the record (drawing pg-hsn-125); its kebab and right-click carry the same action.
    fireEvent.click(screen.getByText('Recorded chemical'));
    const href = io.get.mock.calls[0][0] as string;
    expect(new URL(href, 'https://example.invalid').pathname).toBe('/health-safety/substances/9');
    const back = new URL(href, 'https://example.invalid').searchParams.get('return_to')!;
    expect(new URL(back, 'https://example.invalid').searchParams.get('site_id')).toBe('3');
    expect(new URL(back, 'https://example.invalid').searchParams.get('q')).toBe('cleaning');
    const current = new URL(screen.getByRole('link', { name: 'View current safety data sheets' }).getAttribute('href')!, 'https://example.invalid');
    expect(current.searchParams.get('tab')).toBe('active'); expect(current.searchParams.get('sds_state')).toBe('current'); expect(current.searchParams.get('site_id')).toBe('3');
    expect(current.searchParams.has('q')).toBe(false); expect(screen.queryByRole('button', { name: 'Reports' })).not.toBeInTheDocument();
});
it('withholds repair identities and keeps unknown header measures separate from zero or exposure counts', () => {
    const props = index(); props.source_integrity = 'repair_required'; props.rows.data[0] = { ...props.rows.data[0], source_integrity: 'repair_required', name: 'STALE-CHEMICAL-NAME', common_name: 'STALE-COMMON-NAME', storage_count: null };
    props.hero.live.active = null; props.hero.live.controlled = null; props.hero.live.sds_current = null; props.hero.attention.sds_expiring = null; props.hero.attention.sds_missing = null;
    render(<ChemicalRegister {...props} />);
    expect(screen.queryByText('STALE-CHEMICAL-NAME')).not.toBeInTheDocument(); expect(screen.queryByText('STALE-COMMON-NAME')).not.toBeInTheDocument();
    // The one header number (current sheets) and the row's storage count stay unconfirmed, never zero.
    expect(screen.getAllByText('Unconfirmed').length).toBeGreaterThanOrEqual(2); expect(screen.getByText(/not counted here/)).toBeInTheDocument();
});
it('uses in-page record sections and refuses unproved child action bookmarks', () => {
    io.url = '/health-safety/substances/9?section=sds&action=edit_sds&sds=999';
    render(<ChemicalShow detail={detail()} return_to="/my-day" chemical_confirmation={null} chemical_href={null} />);
    expect(screen.queryByRole('dialog')).not.toBeInTheDocument(); expect(screen.getByRole('alert')).toHaveTextContent('unavailable');
    fireEvent.click(screen.getByRole('tab', { name: /^Storage/ }));
    expect(io.replace).toHaveBeenCalledWith(expect.objectContaining({ preserveState: true, preserveScroll: true }));
    expect(io.replace.mock.calls[0][0].url).toContain('section=storage'); expect(io.get).not.toHaveBeenCalled();
});
it('withholds withdrawn child details while keeping an explicitly retained unsaved sheet correction', () => {
    const d = detail(); const sheet: ChemicalSdsRecord = { id: 11, version: 'v2', issue_date: '2026-09-02', review_date: '2027-09-02', supplier_name: 'Recorded supplier', supplier_contact: null,
        source_integrity: 'valid', attribution_state: 'recorded', source_revision: 4, expected_version: 4, parent_expected_version: null, source_version_id: 13, status: 'current', state: 'current', available: true,
        file_name: 'sheet.pdf', uploaded_by: 'Synthetic recorder', created_at: '2026-09-02T00:00:00Z', download_url: '/health-safety/substances/9/sds/11/download' };
    const props = { action: 'edit_sds' as const, actorId: 7, detail: d, sheet, active: true, onCancel: vi.fn(), onDone: vi.fn() };
    const { rerender } = render(<ChemicalCommandForm {...props} />);
    fireEvent.change(screen.getByRole('textbox', { name: 'Supplier name' }), { target: { value: 'Retained unsaved supplier' } });
    rerender(<ChemicalCommandForm {...props} sourceAvailable={false} />);
    expect(screen.queryByRole('textbox', { name: 'Supplier name' })).not.toBeInTheDocument(); expect(screen.getByRole('alert')).toHaveTextContent('withheld');
    rerender(<ChemicalCommandForm {...props} sourceAvailable />);
    expect(screen.getByRole('textbox', { name: 'Supplier name' })).toHaveValue('Retained unsaved supplier');
});
it('requires explicit current-facts review after a definite version rejection and preserves entered facts', async () => {
    const d = detail();
    io.request.mockRejectedValueOnce({ response: { status: 422, data: { errors: { expected_version: ['The record has changed.'] } } } });
    render(<ChemicalCommandForm action="edit" actorId={7} detail={d} active onCancel={vi.fn()} onDone={vi.fn()} />);
    fireEvent.change(screen.getByRole('textbox', { name: /^Chemical name/ }), { target: { value: 'Retained entered name' } });
    fireEvent.click(screen.getByRole('button', { name: 'Review' }));
    fireEvent.click(screen.getByRole('button', { name: 'Review' }));
    fireEvent.click(screen.getByRole('button', { name: 'Save reviewed details' }));
    await waitFor(() => expect(screen.getByRole('button', { name: 'Review current record' })).toBeEnabled());
    const original = io.request.mock.calls[0][0].data;
    io.read.mockResolvedValueOnce({ data: { detail: { ...d, name: 'Current recorded name', expected_version: 5, source_revision: 5 } } });
    fireEvent.click(screen.getByRole('button', { name: 'Review current record' }));
    await waitFor(() => expect(screen.getByText('Current recorded name')).toBeInTheDocument());
    expect(io.request).toHaveBeenCalledTimes(1);
    fireEvent.click(screen.getByRole('button', { name: 'Use reviewed revision' }));
    expect(screen.getByText('Retained entered name')).toBeInTheDocument();
    io.request.mockRejectedValueOnce(new Error('New response lost'));
    fireEvent.click(screen.getByRole('button', { name: 'Save reviewed details' }));
    await waitFor(() => expect(io.request).toHaveBeenCalledTimes(2));
    expect(io.request.mock.calls[1][0].data).toMatchObject({ name: 'Retained entered name', expected_version: 5 });
    expect(io.request.mock.calls[1][0].data.idempotency_key).not.toBe(original.idempotency_key);
});
it('keeps current-facts review unavailable while the original write remains uncertain', async () => {
    const d = detail();
    io.request.mockRejectedValueOnce(new Error('Write response lost'));
    const props = { action: 'edit' as const, actorId: 7, detail: d, active: true, onCancel: vi.fn(), onDone: vi.fn() };
    const { rerender } = render(<ChemicalCommandForm {...props} />);
    fireEvent.change(screen.getByRole('textbox', { name: /^Chemical name/ }), { target: { value: 'Retained original entries' } });
    fireEvent.click(screen.getByRole('button', { name: 'Review' }));
    fireEvent.click(screen.getByRole('button', { name: 'Review' }));
    fireEvent.click(screen.getByRole('button', { name: 'Save reviewed details' }));
    await waitFor(() => expect(screen.getByRole('button', { name: 'Retry exact submission' })).toBeEnabled());
    const original = io.request.mock.calls[0][0].data;
    rerender(<ChemicalCommandForm {...props} detail={{ ...d, expected_version: 5, source_revision: 5 }} />);
    expect(screen.getByRole('button', { name: 'Review current record' })).toBeDisabled();
    fireEvent.click(screen.getByRole('button', { name: 'Review current record' }));
    expect(io.read).not.toHaveBeenCalled();
    io.request.mockRejectedValueOnce(new Error('Retry response lost'));
    fireEvent.click(screen.getByRole('button', { name: 'Retry exact submission' }));
    await waitFor(() => expect(io.request).toHaveBeenCalledTimes(2));
    expect(io.request.mock.calls[1][0].data).toEqual(original);
});
it('records an unknown audit quantity as null and preserves actual negative checks rather than replacing them with defaults', () => {
    const payload = chemicalFormPayload('audit_storage', { current_quantity: '', properly_labelled: '0', segregation_compliant: '1', last_audit_date: '2026-09-19', storage_notes: '' }, null);
    expect(payload).toMatchObject({ current_quantity: null, properly_labelled: false, segregation_compliant: true, last_audit_date: '2026-09-19', storage_notes: null });
});
