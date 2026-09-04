import { useState } from 'react';

/**
 * Returns the previous reference whenever the next value is deep-equal to
 * it (compared by JSON serialization). Inertia hands back fresh prop
 * object identities on every visit, which invalidates useMemo/useEffect
 * deps even when nothing actually changed — this keeps references stable
 * for values that are cheap to serialize (permission maps, label maps,
 * small lists), so expensive derived structures like the sidebar nav tree
 * are not rebuilt on every navigation.
 *
 * Uses the React-documented "storing information from previous renders"
 * pattern (setState during render) rather than a ref, which the
 * react-compiler lint rules disallow touching mid-render.
 */
export function useStableValue<T>(value: T): T {
    const key = JSON.stringify(value) ?? 'undefined';
    const [stable, setStable] = useState({ value, key });

    if (stable.key !== key) {
        setStable({ value, key });

        return value;
    }

    return stable.value;
}
