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
    const flash = (usePage().props as any).flash as Flash | undefined;

    const errors = (usePage().props as any).errors as Record<string, any> | undefined;
    const last = useRef<string>('');
    const seq = useRef(0);

    useEffect(() => {
        if (!flash) return;

        const entries: Array<[keyof Flash, string]> = [];
        if (flash.success) entries.push(['success', flash.success]);
        if (flash.error) entries.push(['error', flash.error]);
        if (flash.warning) entries.push(['warning', flash.warning]);
        if (flash.info) entries.push(['info', flash.info]);

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
    }, [flash?.success, flash?.error, flash?.warning, flash?.info]);



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
