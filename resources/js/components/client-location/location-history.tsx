import { DatePicker } from '@/components/fleet-assets/maintenance/date-picker';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { formatDateOnly, formatDateTime } from '@/lib/datetime';
import { MapPin, RotateCcw } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import LocationAddress from './location-address';
import { privateHeaders, readJson, type HistoryPoint } from './types';

export default function LocationHistory({
    url,
    fingerprint,
    onSelect,
    onAccessEnded,
}: {
    url: string;
    fingerprint?: string | null;
    onSelect: (point: HistoryPoint) => void;
    onAccessEnded: () => void;
}) {
    const [from, setFrom] = useState('');
    const [to, setTo] = useState('');
    const [points, setPoints] = useState<HistoryPoint[]>([]);
    const [status, setStatus] = useState<
        'idle' | 'loading' | 'ready' | 'error'
    >('idle');
    const [error, setError] = useState('');
    const [range, setRange] = useState({ from: '', to: '' });
    const request = useRef<AbortController | null>(null);
    const sequence = useRef(0);
    useEffect(
        () => () => {
            request.current?.abort();
            sequence.current++;
        },
        [url, fingerprint],
    );
    const load = async (selected = { from, to }) => {
        if (selected.from && selected.to && selected.from > selected.to) {
            setError('Choose a To date on or after From.');
            return;
        }
        request.current?.abort();
        const abort = new AbortController();
        request.current = abort;
        const generation = ++sequence.current;
        setStatus('loading');
        setError('');
        setPoints([]);
        setRange(selected);
        const query = new URLSearchParams();
        if (selected.from) query.set('date_from', selected.from);
        if (selected.to) query.set('date_to', selected.to);
        try {
            const response = await fetch(`${url}?${query}`, {
                credentials: 'same-origin',
                cache: 'no-store',
                headers: privateHeaders,
                signal: abort.signal,
            });
            if (abort.signal.aborted || sequence.current !== generation) return;
            if (response.status === 403) {
                onAccessEnded();
                return;
            }
            const data = await readJson<{
                locations: HistoryPoint[];
                access_fingerprint: string;
            }>(response);
            if (abort.signal.aborted || sequence.current !== generation) return;
            if (fingerprint && data.access_fingerprint !== fingerprint) {
                onAccessEnded();
                return;
            }
            setPoints(data.locations);
            setStatus('ready');
        } catch (failure) {
            if (!abort.signal.aborted && sequence.current === generation) {
                setStatus('error');
                setError(
                    failure instanceof Error
                        ? failure.message
                        : 'History could not be loaded.',
                );
            }
        }
    };
    return (
        <section className="location-panel" aria-label="Observation history">
            <div className="location-panel-heading">
                <div>
                    <h2>Recorded observations</h2>
                    <p>
                        Device reports remain separate from plans and staff
                        responses.
                    </p>
                </div>
            </div>
            <div className="location-history-filters">
                <div>
                    <Label htmlFor="history-from">From</Label>
                    <DatePicker
                        id="history-from"
                        label="History from"
                        value={from}
                        onChange={setFrom}
                    />
                </div>
                <div>
                    <Label htmlFor="history-to">To</Label>
                    <DatePicker
                        id="history-to"
                        label="History to"
                        value={to}
                        onChange={setTo}
                    />
                </div>
                <Button
                    onClick={() => void load()}
                    disabled={status === 'loading'}
                >
                    {status === 'loading' ? 'Loading…' : 'Show observations'}
                </Button>
            </div>
            <p className="location-subtle">
                Pacific/Auckland · Only the current authorised collection and
                retention period are available.
            </p>
            {error && (
                <div role="alert" className="location-message location-error">
                    {error}
                    {status === 'error' && (
                        <Button
                            variant="outline"
                            onClick={() => void load(range)}
                        >
                            <RotateCcw />
                            Retry this range
                        </Button>
                    )}
                </div>
            )}
            {status === 'idle' && (
                <div className="location-empty">
                    <MapPin />
                    <strong>Explore recorded locations</strong>
                    <p>
                        Choose dates, or show all observations within the
                        authorised period.
                    </p>
                </div>
            )}
            {status === 'loading' && (
                <p role="status" className="location-message">
                    Loading recorded observations…
                </p>
            )}
            {status === 'ready' && (
                <>
                    <p className="location-subtle">
                        {range.from
                            ? formatDateOnly(range.from)
                            : 'Start of authorised period'}{' '}
                        → {range.to ? formatDateOnly(range.to) : 'Now'} ·{' '}
                        {points.length} observations
                    </p>
                    {points.length === 0 ? (
                        <div className="location-empty">
                            <strong>No observations in this range</strong>
                            <p>
                                Try another date range. This does not confirm
                                where the client was.
                            </p>
                        </div>
                    ) : (
                        <ul className="location-timeline">
                            {points.map((point, i) => (
                                <li key={`${point.timestamp}-${i}`}>
                                    <span className="location-timeline-dot" />
                                    <div>
                                        <strong>
                                            {formatDateTime(point.timestamp)}
                                        </strong>
                                        <LocationAddress point={point} />
                                        <small>
                                            {point.accuracy == null
                                                ? 'Accuracy not recorded'
                                                : `Reported accuracy ${point.accuracy} m`}
                                        </small>
                                    </div>
                                    <Button
                                        variant="outline"
                                        onClick={() => onSelect(point)}
                                    >
                                        View on map
                                    </Button>
                                </li>
                            ))}
                        </ul>
                    )}
                </>
            )}
        </section>
    );
}
