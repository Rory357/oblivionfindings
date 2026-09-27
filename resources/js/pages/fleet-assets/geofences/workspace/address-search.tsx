import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { MapPin, Search } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { base, request, useDebounced } from './api';
import type { AddressCapabilities } from './data';
type Hit = { display_name: string; lat: number; lng: number };
export function AddressSearch({
    capabilities,
    onSelect,
}: {
    capabilities: AddressCapabilities;
    onSelect: (hit: Hit) => void;
}) {
    const [text, setText] = useState(''),
        [submitted, setSubmitted] = useState(''),
        [hits, setHits] = useState<Hit[]>([]),
        [busy, setBusy] = useState(false),
        [error, setError] = useState(''),
        [searched, setSearched] = useState(false),
        [retry, setRetry] = useState(0);
    const debounced = useDebounced(text, 350),
        generation = useRef(0);
    const pending = useRef<AbortController | null>(null);
    const q = capabilities.autocomplete ? debounced : submitted;
    useEffect(() => {
        const n = ++generation.current;
        setHits([]);
        setError('');
        setSearched(false);
        if (!capabilities.enabled || q.trim().length < 3) {
            setBusy(false);
            return;
        }
        const c = new AbortController();
        pending.current = c;
        setBusy(true);
        request<{ results: Hit[] }>(
            base + '/address-search',
            'POST',
            { q: q.trim() },
            c.signal,
        )
            .then((data) => {
                if (n === generation.current && !c.signal.aborted) {
                    setHits(
                        data.results.filter(
                            (h) =>
                                Number.isFinite(h.lat) &&
                                Number.isFinite(h.lng) &&
                                Math.abs(h.lat) <= 90 &&
                                Math.abs(h.lng) <= 180,
                        ),
                    );
                    setSearched(true);
                }
            })
            .catch((e) => {
                if (n === generation.current && !c.signal.aborted)
                    setError(e.message);
            })
            .finally(() => {
                if (n === generation.current && !c.signal.aborted)
                    setBusy(false);
            });
        return () => c.abort();
    }, [q, retry, capabilities.enabled]);
    return (
        <div className="flow-stack compact">
            <label className="field">
                Search an address
                <Input
                    disabled={!capabilities.enabled}
                    placeholder={
                        capabilities.enabled
                            ? 'Type an address or place…'
                            : 'Address search is not connected'
                    }
                    value={text}
                    onChange={(e) => {
                        pending.current?.abort();
                        generation.current++;
                        setSearched(false);
                        setError('');
                        setBusy(false);
                        setText(e.target.value);
                        setHits([]);
                    }}
                    onKeyDown={(e) => {
                        if (e.key === 'Escape' && (hits.length || busy)) {
                            e.preventDefault();
                            e.stopPropagation();
                            pending.current?.abort();
                            generation.current++;
                            setHits([]);
                            setBusy(false);
                            setSearched(false);
                        }
                        if (e.key === 'Enter' && !capabilities.autocomplete) {
                            e.preventDefault();
                            setSubmitted(text);
                            setRetry((n) => n + 1);
                        }
                    }}
                />
            </label>
            {!capabilities.enabled ? (
                <p className="text-sm text-muted-foreground">
                    Choose a site to fill its saved address, or enter a place
                    and coordinates below. Searching other addresses needs a
                    connected address service.
                </p>
            ) : (
                <>
                    {!capabilities.autocomplete && (
                        <>
                            <p className="text-sm text-muted-foreground">
                                Search, then choose a result to fill the address
                                and map position.
                            </p>
                            <Button
                                variant="outline"
                                disabled={text.trim().length < 3 || busy}
                                onClick={() => {
                                    setSubmitted(text);
                                    setRetry((n) => n + 1);
                                }}
                            >
                                <Search />
                                Search addresses
                            </Button>
                        </>
                    )}
                    {busy && <p role="status">Searching addresses…</p>}
                    {error && (
                        <div role="alert">
                            {error}
                            <Button
                                variant="outline"
                                onClick={() => setRetry((n) => n + 1)}
                            >
                                Retry
                            </Button>
                        </div>
                    )}
                    {searched && !hits.length && (
                        <p role="status">
                            No address matches. Refine the search or enter a
                            position manually.
                        </p>
                    )}
                    <div className="place-results">
                        {hits.map((h, i) => (
                            // eslint-disable-next-line no-restricted-syntax -- Multiline place-result selector uses the workspace list layout.
                            <button
                                type="button"
                                className="place-result"
                                key={i}
                                onClick={() => {
                                    onSelect(h);
                                    if (!capabilities.autocomplete)
                                        setText(h.display_name);
                                    setHits([]);
                                    setSearched(false);
                                }}
                            >
                                <MapPin />
                                <span>{h.display_name}</span>
                            </button>
                        ))}
                    </div>
                    <small className="text-muted-foreground">
                        {capabilities.attribution_url ? (
                            <a
                                href={capabilities.attribution_url}
                                target="_blank"
                                rel="noreferrer"
                                className="underline"
                            >
                                {capabilities.attribution}
                            </a>
                        ) : (
                            capabilities.attribution
                        )}
                    </small>
                </>
            )}
        </div>
    );
}
