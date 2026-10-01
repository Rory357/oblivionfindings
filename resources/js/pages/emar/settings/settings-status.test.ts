import { act, renderHook } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import { useStatusMessage } from './_status';

describe('the page status message', () => {
    it('shows a saved message straight away when no dialog confirms it', () => {
        const { result } = renderHook(() => useStatusMessage());
        act(() => result.current.show('1 change saved.'));
        expect(result.current.message).toBe('1 change saved.');
        act(() => result.current.show(null));
        expect(result.current.message).toBeNull();
    });

    it('waits while the walkthrough’s success pane is open, then shows once it closes', () => {
        const { result } = renderHook(() => useStatusMessage());
        act(() => result.current.hold());
        act(() =>
            result.current.show(
                '2 values kept. They now show as reviewed, and each is in the change history.',
            ),
        );
        // The success pane already says it: nothing on the page yet.
        expect(result.current.message).toBeNull();
        act(() => result.current.release());
        expect(result.current.message).toBe(
            '2 values kept. They now show as reviewed, and each is in the change history.',
        );
    });

    it('shows nothing extra when the dialog closes without news', () => {
        const { result } = renderHook(() => useStatusMessage());
        act(() => result.current.hold());
        act(() => result.current.release());
        expect(result.current.message).toBeNull();
        act(() => result.current.show('Unsaved changes discarded.'));
        expect(result.current.message).toBe('Unsaved changes discarded.');
    });
});
