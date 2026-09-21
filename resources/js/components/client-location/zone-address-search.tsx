import type { GeocodeResult } from '@/components/address-autocomplete';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { LoaderCircle, MapPin, Search } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { privateHeaders, readJson, type Coordinate } from './types';

export default function ZoneAddressSearch({
    url,
    fingerprint,
    onSelect,
    onAccessEnded,
}: {
    url: string;
    fingerprint: string;
    onSelect: (place: { point: Coordinate; label: string }) => void;
    onAccessEnded: () => void;
}) {
    const [query, setQuery] = useState('');
    const [results, setResults] = useState<GeocodeResult[]>([]);
    const [busy, setBusy] = useState(false);
    const [message, setMessage] = useState('');
    const [error, setError] = useState('');
    const active = useRef<AbortController | null>(null);
    const resultList = useRef<HTMLDivElement>(null);
    useEffect(() => () => active.current?.abort(), [url, fingerprint]);
    const clear = () => {
        active.current?.abort();
        active.current = null;
        setBusy(false);
        setResults([]);
        setMessage('');
        setError('');
    };
    const search = async () => {
        if (query.trim().length < 3 || active.current) return;
        const abort = new AbortController();
        active.current = abort;
        setBusy(true);
        setResults([]);
        setError('');
        setMessage('Searching addresses…');
        const csrf = document.querySelector<HTMLMetaElement>(
            'meta[name="csrf-token"]',
        )?.content;
        const xsrf = document.cookie
            .split('; ')
            .find((part) => part.startsWith('XSRF-TOKEN='))
            ?.slice(11);
        try {
            const response = await fetch(`${url}/address-search`, {
                method: 'POST',
                credentials: 'same-origin',
                cache: 'no-store',
                signal: abort.signal,
                headers: {
                    ...privateHeaders,
                    'Content-Type': 'application/json',
                    ...(csrf
                        ? { 'X-CSRF-TOKEN': csrf }
                        : xsrf
                          ? { 'X-XSRF-TOKEN': decodeURIComponent(xsrf) }
                          : {}),
                },
                body: JSON.stringify({
                    q: query.trim(),
                    access_fingerprint: fingerprint,
                }),
            });
            if (abort.signal.aborted) return;
            if ([401, 403, 404, 419].includes(response.status)) {
                clear();
                onAccessEnded();
                return;
            }
            const data = await readJson<{ results: GeocodeResult[] }>(response);
            if (abort.signal.aborted) return;
            const places = data.results.filter(
                (place) =>
                    Number.isFinite(place.lat) &&
                    Number.isFinite(place.lng) &&
                    Math.abs(place.lat!) <= 90 &&
                    Math.abs(place.lng!) <= 180,
            );
            setResults(places);
            setMessage(
                places.length
                    ? `${places.length} matching places. Choose one to show it on the map.`
                    : 'No addresses found. Add a suburb or town, or move the map manually.',
            );
        } catch (failure) {
            if (!abort.signal.aborted) {
                setMessage('');
                setError(
                    failure instanceof Error
                        ? failure.message
                        : 'Address search failed. Try again.',
                );
            }
        } finally {
            if (!abort.signal.aborted) {
                active.current = null;
                setBusy(false);
            }
        }
    };
    return (
        <div className="zone-address-search">
            <Label htmlFor="zone-address">Find an address or place</Label>
            <div className="zone-address-inputs">
                <Input
                    id="zone-address"
                    value={query}
                    maxLength={200}
                    autoComplete="off"
                    placeholder="Street address, park or community centre"
                    aria-describedby="zone-address-help"
                    onChange={(event) => {
                        clear();
                        setQuery(event.target.value);
                    }}
                    onKeyDown={(event) => {
                        if (event.key === 'Enter') {
                            event.preventDefault();
                            void search();
                        }
                        if (event.key === 'ArrowDown') {
                            event.preventDefault();
                            resultList.current
                                ?.querySelector('button')
                                ?.focus();
                        }
                        if (
                            event.key === 'Escape' &&
                            (results.length || busy)
                        ) {
                            event.preventDefault();
                            event.stopPropagation();
                            clear();
                        }
                    }}
                />
                <Button
                    type="button"
                    variant="outline"
                    disabled={query.trim().length < 3 || busy}
                    onClick={() => void search()}
                >
                    {busy ? (
                        <LoaderCircle className="animate-spin motion-reduce:animate-none" />
                    ) : (
                        <Search />
                    )}
                    Search
                </Button>
            </div>
            <p id="zone-address-help" className="zone-address-help">
                Search with OpenStreetMap. Choosing a result moves the map; your
                boundary stays in place.
            </p>
            {message && (
                <p role="status" className="zone-address-help">
                    {message}
                </p>
            )}
            {error && (
                <p role="alert" className="location-error">
                    {error}
                </p>
            )}
            {!!results.length && (
                <div
                    ref={resultList}
                    className="zone-address-results"
                    aria-label="Address search results"
                >
                    {results.map((place, index) => (
                        <Button
                            key={`${place.lat}:${place.lng}:${index}`}
                            variant="ghost"
                            type="button"
                            onClick={() => {
                                clear();
                                setQuery(place.display_name);
                                onSelect({
                                    point: { lat: place.lat!, lng: place.lng! },
                                    label: place.display_name,
                                });
                            }}
                        >
                            <MapPin />
                            <span>{place.display_name}</span>
                        </Button>
                    ))}
                </div>
            )}
        </div>
    );
}
