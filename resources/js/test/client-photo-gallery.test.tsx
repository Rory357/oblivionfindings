import '@testing-library/jest-dom/vitest';
import { fireEvent, render, screen } from '@testing-library/react';
import { beforeEach, expect, it, vi } from 'vitest';

import {
    PersonalAssetsTab,
    PhotoGalleryTab,
} from '@/pages/operations/clients/tabs/legacy-profile-sections';

const inertiaMocks = vi.hoisted(() => ({
    delete: vi.fn(),
    post: vi.fn(),
}));

vi.mock('@inertiajs/react', () => ({
    router: { delete: inertiaMocks.delete },
    useForm: () => ({
        data: { photo: null, caption: '', visibility: 'family' },
        errors: {},
        processing: false,
        setData: vi.fn(),
        post: inertiaMocks.post,
        reset: vi.fn(),
    }),
}));

beforeEach(() => {
    inertiaMocks.delete.mockClear();
});

it('confirms photo deletion in an accessible dialog before deleting', () => {
    render(
        <PhotoGalleryTab
            clientId={81}
            canEdit
            photos={[
                {
                    id: 19,
                    url: '/operations/clients/81/gallery-photos/19/media',
                    original_name: 'garden.jpg',
                    caption: 'Garden visit',
                    visibility: 'family',
                    status: 'approved',
                    uploaded_by: 'Support Worker',
                    created_at: '2026-07-12T10:00:00+12:00',
                },
            ]}
        />,
    );

    fireEvent.click(screen.getByRole('button', { name: 'Delete photo' }));

    expect(
        screen.getByRole('alertdialog', { name: 'Delete photo?' }),
    ).toBeVisible();
    expect(inertiaMocks.delete).not.toHaveBeenCalled();

    fireEvent.click(screen.getByRole('button', { name: 'Delete photo' }));

    expect(inertiaMocks.delete).toHaveBeenCalledWith(
        '/operations/clients/81/gallery-photos/19',
        { preserveScroll: true },
    );
});

it('labels personal asset actions and confirms removal before deleting', () => {
    render(
        <PersonalAssetsTab
            clientId={81}
            canEdit
            firstName="Aroha"
            clientSiteId={null}
            locations={[]}
            availableTrackers={[]}
            assets={[
                {
                    id: 27,
                    name: 'Blue wheelchair',
                    category: 'mobility_aid',
                    status: 'active',
                    ownership: 'client',
                    created_at: '2026-07-12T10:00:00+12:00',
                },
            ]}
        />,
    );

    expect(
        screen.getByRole('button', { name: 'Edit Blue wheelchair' }),
    ).toBeVisible();
    fireEvent.click(
        screen.getByRole('button', { name: 'Remove Blue wheelchair' }),
    );

    expect(
        screen.getByRole('alertdialog', { name: 'Remove personal asset?' }),
    ).toBeVisible();
    expect(inertiaMocks.delete).not.toHaveBeenCalled();

    fireEvent.click(screen.getByRole('button', { name: 'Remove asset' }));

    expect(inertiaMocks.delete).toHaveBeenCalledWith(
        '/operations/clients/81/personal-assets/27',
        { preserveScroll: true },
    );
});
