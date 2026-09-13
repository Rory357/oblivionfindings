import type { InertiaFormProps } from '@inertiajs/react';
import { useEffect, useRef } from 'react';

/** Open against the current page; preserve the buffer through validation and reloads. */
export function useSpecialistFormDefaults<T extends object>(
    open: boolean,
    form: InertiaFormProps<T>,
    defaults: T,
) {
    const wasOpen = useRef(false);
    useEffect(() => {
        if (open && !wasOpen.current) {
            form.setDefaults(defaults);
            form.setData(defaults);
            form.clearErrors();
        }
        wasOpen.current = open;
    }, [open, form, defaults]);
}
