import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import { afterEach, expect, it, vi } from 'vitest';
import { OverrideConfirmationDialog } from './override-confirmation-dialog';

afterEach(cleanup);
const base = {
    open: true,
    warnings: [
        {
            rule: 'qualification',
            message: 'Review the unmapped requirement.',
            overrideable: true,
        },
    ],
    onOpenChange: vi.fn(),
    onConfirm: vi.fn(),
};

it('requires a reason and passes only the explicit trimmed acknowledgement', () => {
    const onConfirm = vi.fn();
    render(<OverrideConfirmationDialog {...base} onConfirm={onConfirm} />);
    const confirm = screen.getByRole('button', { name: 'Override & Assign' });
    expect(confirm).toBeDisabled();
    fireEvent.change(screen.getByLabelText(/Reason for override/), {
        target: { value: '   ' },
    });
    expect(confirm).toBeDisabled();
    fireEvent.change(screen.getByLabelText(/Reason for override/), {
        target: { value: '  Checked the support plan  ' },
    });
    fireEvent.click(confirm);
    expect(onConfirm).toHaveBeenCalledExactlyOnceWith(
        'Checked the support plan',
    );
});

it('retains the reason and prevents dismissal, edits or another confirmation during processing', () => {
    const onOpenChange = vi.fn(),
        onConfirm = vi.fn();
    const props = { ...base, onOpenChange, onConfirm };
    const view = render(<OverrideConfirmationDialog {...props} />);
    fireEvent.change(screen.getByLabelText(/Reason for override/), {
        target: { value: 'Current review reason' },
    });
    view.rerender(<OverrideConfirmationDialog {...props} processing />);
    expect(screen.getByLabelText(/Reason for override/)).toBeDisabled();
    expect(screen.getByLabelText(/Reason for override/)).toHaveValue(
        'Current review reason',
    );
    expect(screen.getByRole('button', { name: 'Cancel' })).toBeDisabled();
    expect(screen.getByRole('button', { name: 'Assigning...' })).toBeDisabled();
    expect(
        screen.queryByRole('button', { name: 'Close' }),
    ).not.toBeInTheDocument();
    fireEvent.keyDown(screen.getByRole('dialog'), { key: 'Escape' });
    expect(onOpenChange).not.toHaveBeenCalled();
    expect(onConfirm).not.toHaveBeenCalled();
    view.rerender(<OverrideConfirmationDialog {...props} />);
    expect(screen.getByLabelText(/Reason for override/)).toHaveValue(
        'Current review reason',
    );
});

it('starts a later review without a reason from a parent-confirmed previous close', () => {
    const view = render(<OverrideConfirmationDialog {...base} />);
    fireEvent.change(screen.getByLabelText(/Reason for override/), {
        target: { value: 'Previous assignment reason' },
    });
    view.rerender(<OverrideConfirmationDialog {...base} open={false} />);
    view.rerender(
        <OverrideConfirmationDialog {...base} staffName="Another worker" />,
    );
    expect(screen.getByLabelText(/Reason for override/)).toHaveValue('');
    expect(
        screen.getByRole('button', { name: 'Override & Assign' }),
    ).toBeDisabled();
});
