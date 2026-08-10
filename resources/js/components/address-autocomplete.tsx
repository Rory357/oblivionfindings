import { Input } from '@/components/ui/input';
import { Loader2, MapPin } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';

/**
 * A single geocoded address suggestion, as returned by the Nominatim proxy
 * (Sites\SiteGeocodingController@search) behind `/clients/geocode/search` and
 * `/sites/geocode/search`.
 */
export type GeocodeResult = {
    display_name: string;
    lat: number | null;
    lng: number | null;
    address_line_1: string | null;
    suburb: string | null;
    city: string | null;
    postcode: string | null;
    country: string | null;
    region: string | null;
};

/**
 * Address line input with OpenStreetMap (Nominatim) autocomplete — type a few
 * characters, pick a suggestion, and the parent fills the structured fields.
 * Mirrors the Sites "Edit location" address search. Free-typing still works if
 * the address isn't found.
 */
export function AddressAutocomplete({
    value,
    onChange,
    onSelect,
    placeholder = 'Start typing an address…',
    endpoint = '/clients/geocode/search',
    error = false,
    id,
}: {
    /** Current text in the field (typically the street address). */
    value: string;
    /** Fires on every keystroke so the parent can keep its own state in sync. */
    onChange: (value: string) => void;
    /** Fires when the user picks a suggestion — parent fills the address fields. */
    onSelect: (result: GeocodeResult) => void;
    placeholder?: string;
    /** Geocoding endpoint. Defaults to the clients-scoped Nominatim proxy. */
    endpoint?: string;
    error?: boolean;
    id?: string;
}) {
    const [results, setResults] = useState<GeocodeResult[]>([]);
    const [searching, setSearching] = useState(false);
    const [open, setOpen] = useState(false);
    const debounceRef = useRef<ReturnType<typeof setTimeout> | null>(null);
    const wrapRef = useRef<HTMLDivElement>(null);
    // Don't re-search the exact text we just filled in from a picked result.
    const lastPicked = useRef<string>('');

    // Click outside closes the suggestions panel.
    useEffect(() => {
        if (!open) return;
        const onDocClick = (e: MouseEvent) => {
            if (!wrapRef.current?.contains(e.target as Node)) setOpen(false);
        };
        document.addEventListener('mousedown', onDocClick);
        return () => document.removeEventListener('mousedown', onDocClick);
    }, [open]);

    // Debounced search as the user types.
    useEffect(() => {
        if (debounceRef.current) clearTimeout(debounceRef.current);
        const q = value.trim();
        if (q.length < 3 || q === lastPicked.current) {
            setResults([]);
            return;
        }
        debounceRef.current = setTimeout(async () => {
            setSearching(true);
            try {
                const res = await fetch(
                    `${endpoint}?q=${encodeURIComponent(q)}`,
                    { headers: { Accept: 'application/json' } },
                );
                if (res.ok) {
                    const json = await res.json();
                    setResults(json.results ?? []);
                    setOpen(true);
                }
            } catch {
                // Silent — manual entry below still works.
            } finally {
                setSearching(false);
            }
        }, 400);
        return () => {
            if (debounceRef.current) clearTimeout(debounceRef.current);
        };
    }, [value, endpoint]);

    const pick = (r: GeocodeResult) => {
        lastPicked.current = r.address_line_1 ?? r.display_name;
        onSelect(r);
        setResults([]);
        setOpen(false);
    };

    return (
        <div ref={wrapRef} className="relative">
            <Input
                id={id}
                value={value}
                onChange={(e) => onChange(e.target.value)}
                onFocus={() => results.length > 0 && setOpen(true)}
                placeholder={placeholder}
                autoComplete="off"
                aria-invalid={error}
            />
            {searching ? (
                <Loader2 className="absolute top-2.5 right-2.5 h-4 w-4 animate-spin text-muted-foreground" />
            ) : null}
            {open ? (
                <div className="absolute z-50 mt-1 max-h-64 w-full overflow-y-auto rounded-md border border-border bg-popover shadow-lg">
                    {results.length === 0 ? (
                        <div className="p-3 text-center text-xs text-muted-foreground">
                            No matches. Add a suburb, city or postcode — or just
                            type the address manually.
                        </div>
                    ) : (
                        results.map((r, i) => (
                            <button
                                type="button"
                                key={i}
                                // mousedown fires before the input blurs, so the
                                // pick lands even as focus shifts away.
                                onMouseDown={(e) => {
                                    e.preventDefault();
                                    pick(r);
                                }}
                                className="flex w-full items-start gap-2 border-b border-border/40 p-2 text-left text-sm last:border-0 hover:bg-accent"
                            >
                                <MapPin className="mt-0.5 h-3.5 w-3.5 shrink-0 text-primary" />
                                <span className="leading-snug">
                                    {r.display_name}
                                </span>
                            </button>
                        ))
                    )}
                </div>
            ) : null}
        </div>
    );
}
