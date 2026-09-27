import { useLayoutEffect, useState, type RefObject } from 'react';

/** Keep the full picker beside the field only when either side has room. */
export function usePickerPlacement(
    open: boolean,
    width: number,
    trigger: RefObject<HTMLButtonElement | null>,
) {
    const [side, setSide] = useState<'right' | 'bottom'>('bottom');

    useLayoutEffect(() => {
        if (!open) return;
        const place = () => {
            const rect = trigger.current?.getBoundingClientRect();
            // Match the pickers' 16px collision padding and 8px side offset.
            const horizontalRoom = rect
                ? Math.max(rect.left, window.innerWidth - rect.right)
                : 0;
            setSide(horizontalRoom >= width + 24 ? 'right' : 'bottom');
        };
        place();
        window.addEventListener('resize', place);
        return () => window.removeEventListener('resize', place);
    }, [open, width, trigger]);

    return side;
}
