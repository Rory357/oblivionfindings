import { DatePicker } from '@/components/fleet-assets/maintenance/date-picker';
import { Button } from '@/components/ui/button';
import {
    Download,
    FileSpreadsheet,
    FileText,
    Image as ImageIcon,
} from 'lucide-react';
import { useState } from 'react';
import type { DrivingState } from './driving-workflows';
import type { Journey } from './trip-data';
import { createTripReport } from './trip-report';
import { Modal, Notice } from './ui';

export function TripExport({
    trips,
    state,
    onClose,
}: {
    trips: Journey[];
    state: DrivingState;
    onClose: () => void;
}) {
    const days = trips.map((t) => t.day).sort();
    const [from, setFrom] = useState(days[0] || ''),
        [to, setTo] = useState(days.at(-1) || ''),
        [format, setFormat] = useState<'pdf' | 'xlsx'>('pdf');
    const [maps, setMaps] = useState(true),
        [events, setEvents] = useState(true),
        [busy, setBusy] = useState(''),
        [error, setError] = useState('');
    const [ready, setReady] = useState<Awaited<
        ReturnType<typeof createTripReport>
    > | null>(null);
    const selected = trips.filter((t) => t.day >= from && t.day <= to),
        invalid = !from || !to || to < from;
    const change = (fn: () => void) => {
        fn();
        setReady(null);
        setError('');
    };
    const generate = async () => {
        setError('');
        setReady(null);
        setBusy('Preparing report…');
        try {
            setReady(
                await createTripReport(
                    { trips: selected, state, from, to, maps, events },
                    format,
                    setBusy,
                ),
            );
        } catch (e) {
            setError(
                e instanceof Error
                    ? e.message
                    : 'Report could not be generated. Please retry.',
            );
        } finally {
            setBusy('');
        }
    };
    return (
        <Modal
            title="Export vehicle trips"
            description="KWH014 · Branded reports for one day or a date range"
            size="standard"
            icon={Download}
            onClose={() => !busy && onClose()}
            footer={
                <>
                    <Button
                        variant="outline"
                        disabled={!!busy}
                        onClick={onClose}
                    >
                        Close
                    </Button>
                    {ready ? (
                        <a
                            className="report-download"
                            href={ready.href}
                            download={ready.filename}
                        >
                            <Download size={16} />
                            Download {format === 'pdf' ? 'PDF' : 'Excel'}
                        </a>
                    ) : (
                        <Button
                            disabled={!!busy || invalid || !selected.length}
                            onClick={generate}
                        >
                            {busy || 'Generate report'}
                        </Button>
                    )}
                </>
            }
        >
            <div className="trip-export-body">
                <div
                    className="report-format"
                    role="group"
                    aria-label="Report format"
                >
                    <button
                        disabled={!!busy}
                        aria-pressed={format === 'pdf'}
                        onClick={() => change(() => setFormat('pdf'))}
                    >
                        <FileText />
                        <strong>PDF report</strong>
                        <small>Summary, route maps & trip pages</small>
                    </button>
                    <button
                        disabled={!!busy}
                        aria-pressed={format === 'xlsx'}
                        onClick={() => change(() => setFormat('xlsx'))}
                    >
                        <FileSpreadsheet />
                        <strong>Excel workbook</strong>
                        <small>Filterable trips, events & map images</small>
                    </button>
                </div>
                <fieldset disabled={!!busy} className="report-dates">
                    <label>
                        From
                        <DatePicker
                            id="export-from"
                            label="Export from"
                            value={from}
                            onChange={(v) => change(() => setFrom(v))}
                        />
                    </label>
                    <label>
                        To
                        <DatePicker
                            id="export-to"
                            label="Export to"
                            value={to}
                            onChange={(v) => change(() => setTo(v))}
                        />
                    </label>
                </fieldset>
                <div className="report-options">
                    <label>
                        <input
                            type="checkbox"
                            checked={maps}
                            disabled={!!busy}
                            onChange={(e) =>
                                change(() => setMaps(e.target.checked))
                            }
                        />
                        <ImageIcon size={16} /> Include greyscale route maps
                    </label>
                    <label>
                        <input
                            type="checkbox"
                            checked={events}
                            disabled={!!busy}
                            onChange={(e) =>
                                change(() => setEvents(e.target.checked))
                            }
                        />{' '}
                        Include journey events and review outcomes
                    </label>
                </div>
                <div className="report-scope">
                    <strong>
                        {selected.length}{' '}
                        {selected.length === 1 ? 'trip' : 'trips'} ·{' '}
                        {selected
                            .reduce((s, t) => s + t.distance, 0)
                            .toFixed(1)}{' '}
                        km estimated
                    </strong>
                    <p>
                        Uses the current trip filters, across all pages.
                        Includes the organisation logo, purple branding,
                        coverage and source records. Dates are inclusive.
                    </p>
                </div>
                {invalid && (
                    <Notice title="Choose a valid date range" tone="critical">
                        The end date must be on or after the start date.
                    </Notice>
                )}
                {!invalid && !selected.length && (
                    <Notice title="No trips in this range">
                        Change the dates or close this window and reset your
                        trip filters.
                    </Notice>
                )}
                {error && (
                    <Notice title="Report not generated" tone="critical">
                        {error}
                    </Notice>
                )}
                {ready && (
                    <Notice title="Your report is ready">
                        {ready.count} {ready.count === 1 ? 'trip' : 'trips'}{' '}
                        included. Download the{' '}
                        {format === 'pdf' ? 'PDF' : 'Excel workbook'} below.
                    </Notice>
                )}
                <small className="muted">
                    Synthetic preview records. Map pictures use OpenStreetMap
                    imagery and include attribution. No operational data is sent
                    to a report service.
                </small>
            </div>
        </Modal>
    );
}
