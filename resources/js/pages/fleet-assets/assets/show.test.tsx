import { fireEvent, render, screen } from '@testing-library/react';
import type { ReactNode } from 'react';
import { describe, expect, it, vi } from 'vitest';

import {
    AssetDeviceStatusCard,
    AssetDocumentsCard,
    UploadAssetDocumentWizard,
} from './show';

vi.mock('@inertiajs/react', async (importOriginal) => ({
    ...(await importOriginal<typeof import('@inertiajs/react')>()),
    Link: ({ href, children }: { href: string; children: ReactNode }) => (
        <a href={href}>{children}</a>
    ),
}));

const documentRow = {
    type: 'Insurance policy',
    uploaded_at: '2026-09-01T00:00:00Z',
    status: null,
};

describe('AssetDocumentsCard', () => {
    it('links only files that can be opened and shows why the rest cannot', () => {
        const onUpload = vi.fn();
        render(
            <AssetDocumentsCard
                documents={[
                    {
                        ...documentRow,
                        id: 1,
                        name: 'Policy schedule',
                        url: '/assets/7/documents/1/download',
                    },
                    {
                        ...documentRow,
                        id: 2,
                        name: 'Workshop invoice',
                        url: null,
                        status: {
                            label: 'Blocked: failed virus check',
                            tone: 'critical',
                        },
                    },
                    {
                        ...documentRow,
                        id: 3,
                        name: 'Old policy',
                        url: '/assets/7/documents/3/download',
                        status: { label: 'Archived', tone: 'neutral' },
                    },
                ]}
                vehicleDocuments={null}
                onUpload={onUpload}
            />,
        );

        expect(
            screen.getByRole('link', { name: 'Download Policy schedule' }),
        ).toHaveAttribute('href', '/assets/7/documents/1/download');
        expect(screen.getByText('Blocked: failed virus check')).toBeVisible();
        expect(
            screen.queryByRole('link', { name: 'Download Workshop invoice' }),
        ).not.toBeInTheDocument();
        expect(screen.getByText('Archived')).toBeVisible();
        expect(
            screen.getByRole('link', { name: 'Download Old policy' }),
        ).toBeVisible();

        fireEvent.click(screen.getByRole('button', { name: 'Upload' }));
        expect(onUpload).toHaveBeenCalledTimes(1);
    });

    it('sends vehicle uploads to the vehicle profile instead of the register', () => {
        const { rerender } = render(
            <AssetDocumentsCard
                documents={[]}
                vehicleDocuments={{
                    url: '/fleet-assets/vehicles/7?view=documents',
                }}
                onUpload={vi.fn()}
            />,
        );

        expect(
            screen.queryByRole('button', { name: 'Upload' }),
        ).not.toBeInTheDocument();
        expect(
            screen.getByRole('link', { name: 'Manage in vehicle profile' }),
        ).toHaveAttribute('href', '/fleet-assets/vehicles/7?view=documents');
        expect(
            screen.getByText(/added and archived in the vehicle profile/),
        ).toBeVisible();

        // Register-only viewers can't open the vehicle profile: no dead link.
        rerender(
            <AssetDocumentsCard
                documents={[]}
                vehicleDocuments={{ url: null }}
                onUpload={vi.fn()}
            />,
        );

        expect(
            screen.queryByRole('link', { name: 'Manage in vehicle profile' }),
        ).not.toBeInTheDocument();
        expect(
            screen.queryByRole('button', { name: 'Upload' }),
        ).not.toBeInTheDocument();
    });
});

describe('AssetDeviceStatusCard', () => {
    it('distinguishes restricted technology from an authorised empty registry', () => {
        const { rerender } = render(
            <AssetDeviceStatusCard
                linkedDevices={[]}
                canViewTechnology={false}
                devicesHref={null}
            />,
        );

        expect(screen.getByText('Technology access restricted')).toBeVisible();
        expect(screen.queryByText('No linked devices')).not.toBeInTheDocument();
        expect(
            screen.queryByRole('link', { name: /security & devices/i }),
        ).not.toBeInTheDocument();

        rerender(
            <AssetDeviceStatusCard
                linkedDevices={[]}
                canViewTechnology
                devicesHref="/security-devices/devices"
            />,
        );

        expect(screen.getByText('No linked devices')).toBeVisible();
        expect(
            screen.getByRole('link', { name: 'Open Security & Devices' }),
        ).toHaveAttribute('href', '/security-devices/devices');
    });
});

describe('UploadAssetDocumentWizard', () => {
    it('labels file metadata, requires a file and title, reviews, and cancels', () => {
        const file = new File(['manual'], 'vehicle-manual.pdf', {
            type: 'application/pdf',
        });
        const onClose = vi.fn();

        const { rerender } = render(
            <UploadAssetDocumentWizard
                open
                file={null}
                title=""
                category="manual"
                error=""
                submitting={false}
                onFileChange={vi.fn()}
                onTitleChange={vi.fn()}
                onCategoryChange={vi.fn()}
                onClose={onClose}
                onSubmit={vi.fn()}
            />,
        );

        expect(
            screen.getByRole('dialog', { name: 'Upload asset document' }),
        ).toHaveAccessibleDescription(
            'Attach a file to this Fleet asset and review its document details before uploading.',
        );
        expect(screen.getByLabelText('File')).toBeVisible();
        expect(screen.getByLabelText('Title')).toBeVisible();
        expect(screen.getByLabelText('Category')).toBeVisible();
        expect(screen.getByRole('button', { name: 'Continue' })).toBeDisabled();

        rerender(
            <UploadAssetDocumentWizard
                open
                file={file}
                title="Vehicle manual"
                category="manual"
                error=""
                submitting={false}
                onFileChange={vi.fn()}
                onTitleChange={vi.fn()}
                onCategoryChange={vi.fn()}
                onClose={onClose}
                onSubmit={vi.fn()}
            />,
        );

        fireEvent.click(screen.getByRole('button', { name: 'Continue' }));
        expect(screen.getByText('vehicle-manual.pdf')).toBeVisible();
        expect(screen.getByText('Vehicle manual')).toBeVisible();
        expect(
            screen.getByRole('button', { name: 'Upload document' }),
        ).toBeVisible();

        fireEvent.click(screen.getByRole('button', { name: 'Cancel' }));
        expect(onClose).toHaveBeenCalledTimes(1);
    });
});
