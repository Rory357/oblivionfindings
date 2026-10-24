import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import type { ReactNode } from 'react';
import { afterEach, expect, it, vi } from 'vitest';
import { PpeRecordPanel } from './_record-panels';
import type { PpeRecord } from './_workspace-types';

vi.mock('@inertiajs/react', () => ({ Link: ({ children, href, ...props }: { children: ReactNode; href: string }) => <a href={href} {...props}>{children}</a> }));
vi.mock('@/components/files/file-preview-dialog', () => ({ FilePreviewDialog: ({ file }: { file: { name: string } | null }) => file && <div role="dialog">{file.name}</div> }));
afterEach(cleanup);
const context = { root_kind: 'inventory', root_id: 9, source_kind: 'inventory', source_id: 9, site_id: 4, user_id: null, expected_version: 4, source_schema_version: 1 };
const inventory = () => ({ kind: 'inventory', detail: { id: 9, ppe_type: { name: 'Ear defenders', category: null }, site: { id: 4, name: 'Kauri' },
    brand: null, model: null, serial_number: null, quantity: 1, condition: 'good', status: 'available', attachments: [], allocations: [], inspections: [],
    history: [], command_history: [], can: { manage: true, evidence: true }, command_context: context, active_allocation_state: 'none' } }) as unknown as PpeRecord;
const props = (record: PpeRecord, section: 'overview' | 'evidence' | 'allocations' | 'inspections' | 'fit-training' | 'history') =>
    ({ record, section, sourceHref: '/health-safety/ppe/inventory/9', open: vi.fn(), page: vi.fn() });

it('does not invent Other as the category of an unknown saved reference', () => {
    render(<PpeRecordPanel {...props(inventory(), 'overview')} />);
    // The category is a story chip (drawing pg-hsn-121); an unknown saved category reads "not recorded", never Other.
    expect(screen.getByText('Category not recorded')).toBeInTheDocument();
    expect(screen.queryByText('Other')).not.toBeInTheDocument();
    expect(screen.getByRole('link', { name: 'Kauri' })).toHaveAttribute('href', '/sites/4?tab=ppe&return_to=%2Fhealth-safety%2Fppe%2Finventory%2F9');
});
it.each(['allocations', 'inspections'] as const)('retains repair truth for withheld %s rows and their physical pagination', (section) => {
    const record = inventory();
    const d = { ...record.detail, child_integrity: { allocations: 'repair_required', inspections: 'repair_required' },
        pages: { [section]: { current_page: 1, last_page: 2, total: 26 } } };
    const p = props({ ...record, detail: d } as PpeRecord, section);
    render(<PpeRecordPanel {...p} />);
    expect(screen.getByRole('status')).toHaveTextContent('Page counts include those saved rows');
    expect(screen.queryByText(/No (issues|inspections) are recorded/)).not.toBeInTheDocument();
    fireEvent.click(screen.getByRole('button', { name: 'Next' }));
    expect(p.page).toHaveBeenCalledWith(section === 'allocations' ? 'allocations_page' : 'inspections_page', 2);
});
it('keeps own issue fields and navigation separate even if an overbroad payload includes private facts', () => {
    const record = { kind: 'allocations', detail: { id: 13, read_scope: 'own_allocation', user: { id: 12, name: 'Own worker' },
        ppe_type: null, inventory_item: { id: 9, site: { name: 'Kauri' } }, allocated_at: '2026-10-10T01:00:00Z', returned_at: null,
        acknowledged: false, notes: 'Private supervisor note', issued_by: { name: 'Private issuer' }, fit_test_result: 'Private test result',
        attachments: [{ original_name: 'Private evidence' }], catalogue_integrity: 'repair_required', can: { manage: false, acknowledge_own: true },
        command_context: { ...context, source_kind: 'allocations', source_id: 13, user_id: 12 } } } as unknown as PpeRecord;
    const { rerender } = render(<PpeRecordPanel {...props(record, 'overview')} />);
    expect(screen.getByRole('status')).toHaveTextContent('type reference needs repair');
    expect(screen.queryByText('Private supervisor note')).not.toBeInTheDocument();
    expect(screen.queryByText('Private issuer')).not.toBeInTheDocument();
    expect(screen.queryByRole('link', { name: 'Open equipment record' })).not.toBeInTheDocument();
    rerender(<PpeRecordPanel {...props(record, 'evidence')} />);
    expect(screen.getByText('Evidence requires separate record access.')).toBeInTheDocument();
    expect(screen.queryByText('Private evidence')).not.toBeInTheDocument();
    rerender(<PpeRecordPanel {...props(record, 'fit-training')} />);
    expect(screen.queryByText('Private test result')).not.toBeInTheDocument();
    expect(screen.getByText('Currency not established')).toBeInTheDocument();
});
it('shows unavailable historical evidence without a public URL and opens only its eligible exact transfer', () => {
    const record = inventory();
    const d = { ...record.detail, attachments: [{ id: 8, original_name: 'Legacy inspection.pdf', size: 50, mime: 'application/pdf',
        created_at: '2026-10-10T01:00:00Z', download_url: null, url: null, can_relocate: true },
        { id: 10, original_name: 'Unconfirmed.pdf', size: 40, mime: 'application/pdf', download_url: null, url: null, can_relocate: false,
            relocate_blocked_reason: 'The retained file path cannot be confirmed.' }] };
    const p = props({ ...record, detail: d } as unknown as PpeRecord, 'evidence');
    render(<PpeRecordPanel {...p} />);
    expect(screen.queryByRole('link', { name: 'Download' })).not.toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'Preview evidence' })).not.toBeInTheDocument();
    expect(screen.getAllByText(/does not confirm available bytes/)).toHaveLength(2);
    // The transfer lives in the row's menu (kebab and right-click, drawing pg-hsn-121d).
    fireEvent.contextMenu(screen.getAllByRole('row')[1], { clientX: 60, clientY: 80 });
    expect(screen.queryByRole('menuitem', { name: /Preview|Download/ })).not.toBeInTheDocument();
    fireEvent.click(screen.getByRole('menuitem', { name: 'Move evidence to private storage' }));
    expect(p.open).toHaveBeenCalledWith('relocate_evidence', 8);
    expect(screen.getByText('The retained file path cannot be confirmed.')).toBeInTheDocument();
});
it('uses the private verified evidence endpoints and keeps its original metadata visible', () => {
    const record = inventory();
    const d = { ...record.detail, attachments: [{ id: 8, original_name: 'Verified inspection.pdf', size: 50, mime: 'application/pdf',
        created_at: '2026-10-10T01:00:00Z', uploaded_by: { name: 'Original uploader' },
        notes: 'Observed evidence', download_url: '/health-safety/ppe/inventory/9/attachments/8/download', url: '/health-safety/ppe/inventory/9/attachments/8/download?preview=1' }] };
    render(<PpeRecordPanel {...props({ ...record, detail: d } as unknown as PpeRecord, 'evidence')} />);
    // The file name downloads the verified original; preview and download also sit in the row's menu.
    expect(screen.getByRole('link', { name: 'Verified inspection.pdf' })).toHaveAttribute('href', d.attachments[0].download_url);
    expect(screen.getByText(/Original uploader/)).toBeInTheDocument();
    fireEvent.contextMenu(screen.getAllByRole('row')[1], { clientX: 60, clientY: 80 });
    expect(screen.getByRole('menuitem', { name: 'Download' })).toBeInTheDocument();
    fireEvent.click(screen.getByRole('menuitem', { name: 'Preview evidence' }));
    expect(screen.getByRole('dialog')).toHaveTextContent('Verified inspection.pdf');
});
it('separates unconfirmed command history from the earlier reconstructed facts', () => {
    const record = inventory();
    const d = { ...record.detail, command_history_integrity: 'repair_required', pages: { history: { current_page: 1, last_page: 2, total: 30 } } };
    render(<PpeRecordPanel {...props({ ...record, detail: d } as PpeRecord, 'history')} />);
    expect(screen.getByRole('status')).toHaveTextContent('withheld');
    expect(screen.getByText(/not a complete audit certificate/)).toBeInTheDocument();
    expect(screen.getByText('Page 1 of 2 · 30 records')).toBeInTheDocument();
});
