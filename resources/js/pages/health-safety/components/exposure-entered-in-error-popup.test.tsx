import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import { afterEach, beforeEach, expect, it, vi } from 'vitest';
import type { ExposureWorkspaceDetail } from '@/lib/health-safety/chemical-exposure-read-contract';
import { confirmsExposureReceipt, type ExposureCommandTarget } from './use-exposure-command';
import { ExposureEnteredInErrorPopup } from './exposure-entered-in-error-popup';

// Entered in error (decided 6 Nov 2026): a kit pop-up with a required reason, sent through the exposure command protocol.
const hook = vi.hoisted(() => ({ value: {} as Record<string, unknown>, target: null as unknown }));
vi.mock('./use-exposure-command', async (original) => ({
    ...(await original<typeof import('./use-exposure-command')>()),
    useExposureCommand: (target: unknown) => { hook.target = target; return hook.value; },
}));
const command = (overrides: Record<string, unknown> = {}) => ({ receipt: null, busy: false, uncertain: false, rejected: false, errors: {}, message: null,
    submit: vi.fn(), check: vi.fn(), retry: vi.fn(), locked: false, canCheck: false, canEditRejected: false, editRejected: vi.fn(), ...overrides });
const detail = { id: 22, command_context: { root_type: 'exposure', root_id: 22, expected_version: 4, source_version_id: 9 },
    record: { worker: { id: 3, name: 'Aroha Ngata' }, substance: { id: 5, name: 'Bleach' }, site: { id: 4, name: 'Kauri' } } } as unknown as ExposureWorkspaceDetail;
beforeEach(() => { hook.value = command(); });
afterEach(cleanup);

it('needs a reason and sends it as one exposure command for the reviewed revision', () => {
    render(<ExposureEnteredInErrorPopup open detail={detail} actorId={12} allowed onClose={vi.fn()} onDone={vi.fn()} />);
    expect(screen.getByRole('dialog')).toHaveTextContent('Mark as entered in error?');
    expect(screen.getByText('Aroha Ngata · Bleach')).toBeInTheDocument();
    expect(hook.target).toMatchObject({ command: 'mark_entered_in_error', rootId: 22, sourceType: 'review', expectedVersion: 4,
        method: 'post', path: '/health-safety/chemical-exposures/22/entered-in-error', allowed: true });
    const submit = screen.getByRole('button', { name: 'Mark as entered in error' });
    expect(submit).toBeDisabled();
    fireEvent.change(screen.getByLabelText(/Why was it entered in error/), { target: { value: 'Wrong worker' } });
    fireEvent.click(submit);
    expect(hook.value.submit).toHaveBeenCalledWith({ reason: 'Wrong worker' });
});

it('closes once the exact receipt is confirmed, and offers a check when the save is unconfirmed', () => {
    const onDone = vi.fn();
    hook.value = command({ uncertain: true, locked: true, canCheck: true, message: 'This submission is unconfirmed.' });
    const { rerender } = render(<ExposureEnteredInErrorPopup open detail={detail} actorId={12} allowed onClose={vi.fn()} onDone={onDone} />);
    expect(screen.getByRole('dialog')).toHaveTextContent('This submission is unconfirmed.');
    fireEvent.click(screen.getByRole('button', { name: 'Check if it saved' }));
    expect(hook.value.check).toHaveBeenCalled();
    hook.value = command({ receipt: { accepted: true } });
    rerender(<ExposureEnteredInErrorPopup open detail={detail} actorId={12} allowed onClose={vi.fn()} onDone={onDone} />);
    expect(onDone).toHaveBeenCalled();
});

it('accepts only a review-type receipt for this command', () => {
    const target: ExposureCommandTarget = { actorId: 12, command: 'mark_entered_in_error', rootId: 22, substanceId: null, sourceType: 'review',
        sourceId: null, expectedVersion: 4, method: 'post', path: '/x', allowed: true, canRecover: true };
    const receipt = { accepted: true, actor_id: 12, command: 'mark_entered_in_error', idempotency_key: 'k', root_type: 'exposure', root_id: 22,
        source_type: 'review', source_id: 31, expected_version: 4, version: 5, source_version_id: 40, changed: true };
    expect(confirmsExposureReceipt(receipt, target, 'k')).toBe(true);
    expect(confirmsExposureReceipt({ ...receipt, source_type: 'exposure' }, target, 'k')).toBe(false);
    expect(confirmsExposureReceipt({ ...receipt, changed: false, source_version_id: null, version: 4 }, target, 'k')).toBe(false);
});
