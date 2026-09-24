import { useEffect, useState } from 'react';
export function useSessionState<T>(key: string, initial: T) {
    const [value, setValue] = useState<T>(() => {
        try {
            const stored = sessionStorage.getItem('pkg02b-v6-' + key);
            return stored === null ? initial : JSON.parse(stored);
        } catch {
            return initial;
        }
    });
    useEffect(() => {
        sessionStorage.setItem('pkg02b-v6-' + key, JSON.stringify(value));
    }, [key, value]);
    return [value, setValue] as const;
}
