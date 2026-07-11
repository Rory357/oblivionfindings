import PortalMessages from '@/pages/portal/messages';
import { fireEvent, render } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';

vi.mock('@inertiajs/react', async () => {
    const actual =
        await vi.importActual<typeof import('@inertiajs/react')>(
            '@inertiajs/react',
        );

    return {
        ...actual,
        Head: () => null,
        router: {
            delete: vi.fn(),
            get: vi.fn(),
            post: vi.fn(),
        },
    };
});

vi.mock('@/layouts/app-layout', () => ({
    default: ({ children }: { children: React.ReactNode }) => children,
}));

describe('portal message attachment safety', () => {
    beforeEach(() => {
        vi.clearAllMocks();
    });

    function renderOpenUploadDialog() {
        const result = render(
            <PortalMessages
                client={{ id: 42, first_name: 'Aroha', last_name: 'Ngata' }}
                conversations={[
                    {
                        id: 7,
                        title: 'Care team',
                        participants: [],
                        updated_at: '2026-07-10T08:00:00Z',
                    },
                ]}
                supportWorkers={[]}
                currentUserId={5}
                activeConversation={{
                    id: 7,
                    title: 'Care team',
                    participants: [],
                }}
                activeMessages={[]}
                pinnedMessages={[]}
            />,
        );

        fireEvent.click(result.getByTitle('Attach file'));

        return result;
    }

    it('offers only passive raster formats in the photo picker', () => {
        renderOpenUploadDialog();

        const photoInput =
            document.querySelector<HTMLInputElement>('input[type="file"]');
        expect(photoInput).not.toBeNull();
        expect(photoInput?.accept).toBe(
            'image/jpeg,image/png,image/gif,image/webp',
        );
        expect(photoInput?.accept).not.toContain('image/*');
        expect(photoInput?.accept).not.toContain('svg');
    });

    it('describes the safe upload formats to assistive technology', () => {
        const { getByText } = renderOpenUploadDialog();

        expect(
            getByText(
                'Share a JPG, PNG, GIF, WEBP or supported document with the care team.',
            ),
        ).toBeInTheDocument();
    });
});
