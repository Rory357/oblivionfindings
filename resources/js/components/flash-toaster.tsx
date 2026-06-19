import { usePage } from '@inertiajs/react';
import { useEffect, useRef } from 'react';
import { toast } from 'sonner';

type Flash = {
    success?: unknown;
    error?: unknown;
    warning?: unknown;
    info?: unknown;
};

/**
 * Flash values are *supposed* to be strings, but they cross the server↔client
 * boundary untyped. If a controller ever flashes a non-string (e.g. an object
 * like `{due, title}`), handing it straight to sonner makes React throw
 * "Objects are not valid as a React child" (#31) and blanks the toast region.
 * Coerce defensively: keep strings, pull a human field from objects, else skip.
 */
function asToastText(value: unknown): string | null {
    if (typeof value === 'string') return value.trim() || null;
    if (value == null) return null;
    if (typeof value === 'number' || typeof value === 'boolean') {
        return String(value);
    }
    if (typeof value === 'object') {
        const o = value as Record<string, unknown>;
        const pick = o.message ?? o.title ?? o.body ?? o.text;
        if (typeof pick === 'string' && pick.trim()) return pick;
        return null;
    }
    return null;
}

export default function FlashToaster() {
    const props = usePage().props as any;
    const flash = props.flash as Flash | undefined;
    const errors = props.errors as Record<string, any> | undefined;
    const success = asToastText(flash?.success);
    const error = asToastText(flash?.error);
    const warning = asToastText(flash?.warning);
    const info = asToastText(flash?.info);
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
