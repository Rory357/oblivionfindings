import { cleanup, fireEvent, render, renderHook } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { useListKeys } from './use-list-keys';

afterEach(() => cleanup());

function setup(selectedId: number | null = 2) {
    const onSelect = vi.fn();
    const onOpen = vi.fn();
    const onAcknowledge = vi.fn((id: number) => (id === 3 ? false : undefined));
    renderHook(() =>
        useListKeys({ ids: [1, 2, 3], selectedId, onSelect, onOpen, onAcknowledge }),
    );
    return { onSelect, onOpen, onAcknowledge };
}

describe('useListKeys', () => {
    it('moves the selection with the arrow keys and J / K', () => {
        const { onSelect } = setup(2);
        fireEvent.keyDown(window, { key: 'ArrowDown' });
        expect(onSelect).toHaveBeenLastCalledWith(3);
        fireEvent.keyDown(window, { key: 'k' });
        expect(onSelect).toHaveBeenLastCalledWith(1);
    });

    it('opens the record with Enter and acknowledges with A', () => {
        const { onOpen, onAcknowledge } = setup(2);
        fireEvent.keyDown(window, { key: 'Enter' });
        expect(onOpen).toHaveBeenCalledWith(2);
        fireEvent.keyDown(window, { key: 'a' });
        expect(onAcknowledge).toHaveBeenCalledWith(2);
    });

    it('stays out of the way while typing, in a pop-up, or with a modifier key', () => {
        const { onSelect, onAcknowledge } = setup(2);
        const { container } = render(<input aria-label="Search alerts" />);
        const input = container.querySelector('input')!;
        fireEvent.keyDown(input, { key: 'a' });
        fireEvent.keyDown(window, { key: 'ArrowDown', ctrlKey: true });
        expect(onAcknowledge).not.toHaveBeenCalled();
        expect(onSelect).not.toHaveBeenCalled();

        const dialog = document.createElement('div');
        dialog.setAttribute('role', 'dialog');
        document.body.appendChild(dialog);
        fireEvent.keyDown(window, { key: 'ArrowDown' });
        expect(onSelect).not.toHaveBeenCalled();
        dialog.remove();
    });
});
