import { render } from '@testing-library/react';
import { beforeEach, expect, it, vi } from 'vitest';
import FlashToaster from './flash-toaster';
const { page, success, error, warning } = vi.hoisted(() => ({
    page: {
        component: 'operations/shift-notes/Index',
        props: {
            flash: {} as Record<string, unknown>,
            errors: {} as Record<string, unknown>,
        },
    },
    success: vi.fn(),
    error: vi.fn(),
    warning: vi.fn(),
}));
vi.mock('@inertiajs/react', () => ({ usePage: () => page }));
vi.mock('sonner', () => ({
    toast: Object.assign(vi.fn(), { success, error, warning }),
}));
beforeEach(() => {
    vi.clearAllMocks();
    page.component = 'operations/shift-notes/Index';
    page.props = { flash: {}, errors: {} };
});
it.each([undefined, { action: 'create', note_id: 7 }])(
    'leaves Notes success to its receipt-owning dialog',
    (receipt) => {
        page.props.flash = {
            success: 'Shift note added.',
            shift_note_result: receipt,
        };
        render(<FlashToaster />);
        expect(success).not.toHaveBeenCalled();
    },
);
it('preserves validation and error feedback on Notes', () => {
    page.props = {
        flash: { error: 'Access changed' },
        errors: { body: 'Write the note' },
    };
    render(<FlashToaster />);
    expect(error).toHaveBeenCalledWith('Access changed');
    expect(error).toHaveBeenCalledWith('Write the note');
});
it('preserves success notifications on other modules', () => {
    page.component = 'sites/Index';
    page.props.flash = { success: 'Site saved.' };
    render(<FlashToaster />);
    expect(success).toHaveBeenCalledWith('Site saved.');
});
