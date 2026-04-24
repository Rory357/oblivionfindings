import { usePage } from '@inertiajs/react';
import { useEffect, useRef } from 'react';
import { toast } from 'sonner';

type Flash = {
    success?: string | null;
    error?: string | null;
    warning?: string | null;
    info?: string | null;
};

export default function FlashToaster() {
    const props = usePage().props as any;
    const flash = props.flash as Flash | undefined;
    const errors = props.errors as Record<string, any> | undefined;
    const success = flash?.success;
    const error = flash?.error;
    const warning = flash?.warning;
    const info = flash?.info;
    const last = useRef<string>('');
    const seq = useRef(0);

    useEffect(() => {
        const entries: Array<[keyof Flash, string]> = [];
        if (success) entries.push(['success', success]);
        if (error) entries.push(['error', error]);
        if (warning) entries.push(['warning', warning]);
        if (info) entries.push(['info', info]);

        // Only show the most important message if multiple exist
        const [level, message] = entries[0] ?? [];
        if (!level || !message) return;

        // De-dupe within the same render cycle but allow identical
        // messages from separate server responses (incremented seq).
        seq.current += 1;
        const signature = `${level}:${message}:${seq.current}`;
        if (signature === last.current) return;
        last.current = signature;

        if (level === 'success') toast.success(message);
        else if (level === 'error') toast.error(message);
        else if (level === 'warning') toast.warning(message);
        else toast(message);
    }, [success, error, warning, info]);

    useEffect(() => {
        if (!errors) return;
        // Grab first validation error message
        const firstKey = Object.keys(errors)[0];
        const val = firstKey ? errors[firstKey] : null;
        const message =
            typeof val === 'string'
                ? val
                : Array.isArray(val) && typeof val[0] === 'string'
                  ? val[0]
                  : null;

        if (!message) return;

        const signature = `validation:${firstKey}:${message}`;
        if (signature === last.current) return;
        last.current = signature;

        toast.error(message);
    }, [errors]);

    return null;
}
