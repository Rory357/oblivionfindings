import { fireEvent, render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';

import { AssetDeviceStatusCard, UploadAssetDocumentWizard } from './show';

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
