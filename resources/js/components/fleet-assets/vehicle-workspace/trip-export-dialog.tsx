import { DatePicker } from '@/components/fleet-assets/maintenance/date-picker';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import {
    Download,
    FileSpreadsheet,
    FileText,
    Image as ImageIcon,
    Loader2,
} from 'lucide-react';
import { useEffect, useState } from 'react';
import {
    daysBetween,
    dispositionFilename,
    formatDistance,
    MAX_RANGE_DAYS,
    plural,
    tripHistoryUrl,
    tripQuery,
    type TripFilterState,
} from './trip-model';
import type { TripSummaryResponse, TripVehicle } from './trip-types';
import { StudioNotice } from './wizard-kit';

type Format = 'pdf' | 'excel';
type Scope =
    | { state: 'loading' }
    | { state: 'error' }
    | {
          state: 'ready';
          trips: number;
          km: number;
          personal: number;
          restricted: number;
      };

function errorMessage(body: unknown, status: number): string {
    if ([401, 403, 404, 419].includes(status))
        return "You no longer have access to this vehicle's trips. Reload the page to check what is available.";
    if (body && typeof body === 'object') {
        const errors = (body as { errors?: Record<string, unknown> }).errors;
        if (errors) {
            const first = Object.values(errors)
                .flat()
                .find((value) => typeof value === 'string');
            if (typeof first === 'string') return first;
        }
        const message = (body as { message?: unknown }).message;
        if (typeof message === 'string' && message) return message;
    }
    return 'The report could not be generated. Please retry.';
}

/** Approved "Export vehicle trips": branded PDF or Excel for one day or a range. */
export function TripExportDialog({
    vehicle,
    filters,
    defaultFrom,
    defaultTo,
    onClose,
}: {
    vehicle: TripVehicle;
    filters: TripFilterState;
    defaultFrom: string;
    defaultTo: string;
    onClose: () => void;
}) {
    const [format, setFormat] = useState<Format>('pdf');
    const [from, setFrom] = useState(defaultFrom);
    const [to, setTo] = useState(defaultTo);
    const [maps, setMaps] = useState(true);
    const [events, setEvents] = useState(true);
    const [busy, setBusy] = useState('');
    const [error, setError] = useState('');
    const [ready, setReady] = useState<{
        href: string;
        filename: string;
        count: number;
    } | null>(null);
    const [scope, setScope] = useState<Scope>({ state: 'loading' });
    // The export reads at most a year, as the trip list does.
    const tooLong =
        !!from && !!to && to >= from && daysBetween(from, to) > MAX_RANGE_DAYS;
    const invalid = !from || !to || to < from || tooLong;
    const exportFilters: TripFilterState = {
        ...filters,
        day: 'range',
        from,
        to,
    };
    const query = (extra: Record<string, string | number>) =>
        tripQuery(exportFilters, extra).toString();

    // Release a generated file when it is replaced or the dialog closes.
    useEffect(
        () => () => {
            if (ready) URL.revokeObjectURL(ready.href);
        },
        [ready],
    );

    const scopeKey = query({ summary_only: 1 });
    useEffect(() => {
        if (invalid) return;
        const controller = new AbortController();
        setScope({ state: 'loading' });
        fetch(`${tripHistoryUrl(vehicle.id)}?${scopeKey}`, {
            signal: controller.signal,
            credentials: 'same-origin',
            headers: { Accept: 'application/json' },
        })
            .then(async (response) => {
                if (!response.ok) throw new Error(String(response.status));
                const body = (await response.json()) as TripSummaryResponse;
                setScope({
                    state: 'ready',
                    trips: body.summary.business_trips,
                    km: body.summary.business_distance_km,
                    personal: body.summary.personal_trips,
                    restricted: body.summary.restricted_trips,
                });
            })
            .catch((failure: unknown) => {
                if ((failure as Error)?.name !== 'AbortError')
                    setScope({ state: 'error' });
            });
        return () => controller.abort();
    }, [vehicle.id, scopeKey, invalid]);

    const change = (apply: () => void) => {
        apply();
        setReady(null);
        setError('');
    };
    const count = scope.state === 'ready' ? scope.trips : 0;
    const label = format === 'pdf' ? 'PDF' : 'Excel workbook';

    const generate = async () => {
        setError('');
        setReady(null);
        setBusy('Preparing report…');
        try {
            const response = await fetch(
                `${tripHistoryUrl(vehicle.id, `/export/${format}`)}?${query({
                    events: events ? 1 : 0,
                    maps: maps ? 1 : 0,
                })}`,
                {
                    credentials: 'same-origin',
                    headers: {
                        Accept: 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                },
            );
            if (!response.ok) {
                const body: unknown = await response.json().catch(() => null);
                setError(errorMessage(body, response.status));
                return;
            }
            const blob = await response.blob();
            setReady({
                href: URL.createObjectURL(blob),
                filename: dispositionFilename(
                    response.headers.get('Content-Disposition'),
                    `vehicle-trips-${from}-to-${to}.${format === 'pdf' ? 'pdf' : 'xlsx'}`,
                ),
                count,
            });
        } catch {
            setError(
                'The connection was interrupted before the report arrived. Please retry.',
            );
        } finally {
            setBusy('');
        }
    };

    return (
        <Dialog open onOpenChange={(open) => !open && !busy && onClose()}>
            <DialogContent
                className="vehicle-studio max-h-[90vh] gap-0 overflow-y-auto p-0"
                style={{
                    width: 'min(92vw, 720px)',
                    maxWidth: 'min(92vw, 720px)',
                }}
            >
                <DialogHeader className="flex-row items-start gap-3 border-b p-5 text-left">
                    <span className="feature-icon small" aria-hidden="true">
                        <Download size={18} />
                    </span>
                    <div className="min-w-0">
                        <DialogTitle>Export vehicle trips</DialogTitle>
                        <DialogDescription className="mt-1.5">
                            {vehicle.registration_number ?? vehicle.name} ·
                            Branded reports for one day or a date range
                        </DialogDescription>
                    </div>
                </DialogHeader>
                <div className="trip-export-body p-5">
                    <div
                        className="report-format"
                        role="group"
                        aria-label="Report format"
                    >
                        {/* eslint-disable-next-line no-restricted-syntax -- Format tile picker from the approved design; a selector card. */}
                        <button
                            type="button"
                            disabled={!!busy}
                            aria-pressed={format === 'pdf'}
                            onClick={() => change(() => setFormat('pdf'))}
                        >
                            <FileText aria-hidden="true" />
                            <strong>PDF report</strong>
                            <small>Summary, journey maps & trip pages</small>
                        </button>
                        {/* eslint-disable-next-line no-restricted-syntax -- Format tile picker from the approved design; a selector card. */}
                        <button
                            type="button"
                            disabled={!!busy}
                            aria-pressed={format === 'excel'}
                            onClick={() => change(() => setFormat('excel'))}
                        >
                            <FileSpreadsheet aria-hidden="true" />
                            <strong>Excel workbook</strong>
                            <small>Filterable trips and events</small>
                        </button>
                    </div>
                    <fieldset disabled={!!busy} className="report-dates">
                        <legend className="sr-only">Dates to export</legend>
                        <label>
                            From
                            <DatePicker
                                id="trip-export-from"
                                label="Export from"
                                value={from}
                                invalid={invalid}
                                onChange={(value) =>
                                    change(() => setFrom(value))
                                }
                            />
                        </label>
                        <label>
                            To
                            <DatePicker
                                id="trip-export-to"
                                label="Export to"
                                value={to}
                                invalid={invalid}
                                onChange={(value) => change(() => setTo(value))}
                            />
                        </label>
                    </fieldset>
                    <div className="report-options">
                        <label>
                            <Checkbox
                                checked={maps}
                                disabled={!!busy}
                                onCheckedChange={(value) =>
                                    change(() => setMaps(value === true))
                                }
                            />
                            <ImageIcon size={16} aria-hidden="true" /> Include
                            journey maps and recorded positions
                        </label>
                        <label>
                            <Checkbox
                                checked={events}
                                disabled={!!busy}
                                onCheckedChange={(value) =>
                                    change(() => setEvents(value === true))
                                }
                            />{' '}
                            Include journey events and their details
                        </label>
                    </div>
                    <div className="report-scope" aria-live="polite">
                        <strong>
                            {scope.state === 'ready'
                                ? `${plural(scope.trips, 'trip')} · ${formatDistance(scope.km)} estimated`
                                : scope.state === 'error'
                                  ? 'Trip count unavailable'
                                  : invalid
                                    ? 'Choose the dates to export'
                                    : 'Counting trips…'}
                        </strong>
                        <p>
                            Uses the current trip filters, across all pages.
                            Includes your organisation&apos;s logo, name and
                            brand colour, coverage and source notes. Dates are
                            inclusive, in Pacific/Auckland time.
                            {scope.state === 'ready' &&
                            scope.personal + scope.restricted > 0
                                ? ` ${plural(scope.personal + scope.restricted, 'personal or consent-restricted trip')} in this range ${scope.personal + scope.restricted === 1 ? 'is' : 'are'} left out.`
                                : ''}
                        </p>
                    </div>
                    {invalid && (
                        <StudioNotice
                            title="Choose a valid date range"
                            tone="critical"
                        >
                            {tooLong
                                ? 'Export at most one year of trips at a time.'
                                : 'The end date must be on or after the start date.'}
                        </StudioNotice>
                    )}
                    {!invalid && scope.state === 'ready' && !scope.trips && (
                        <StudioNotice title="No trips in this range">
                            Change the dates or close this window and reset your
                            trip filters.
                        </StudioNotice>
                    )}
                    {error && (
                        <StudioNotice
                            title="Report not generated"
                            tone="critical"
                        >
                            {error}
                        </StudioNotice>
                    )}
                    {ready && (
                        <StudioNotice title="Your report is ready">
                            {plural(ready.count, 'trip')} included. Download the{' '}
                            {label} below.
                        </StudioNotice>
                    )}
                    <small className="text-caption">
                        Reports are generated on this server from the trips you
                        can see. Street maps use locally installed OpenStreetMap
                        data. Areas without map data are labelled as route
                        sketches. Trip locations stay on this server.
                    </small>
                </div>
                <DialogFooter className="border-t p-4">
                    <Button
                        variant="outline"
                        disabled={!!busy}
                        onClick={onClose}
                    >
                        Close
                    </Button>
                    {ready ? (
                        <Button asChild>
                            <a href={ready.href} download={ready.filename}>
                                <Download size={16} />
                                Download {format === 'pdf' ? 'PDF' : 'Excel'}
                            </a>
                        </Button>
                    ) : (
                        <Button
                            disabled={
                                !!busy ||
                                invalid ||
                                scope.state !== 'ready' ||
                                !scope.trips
                            }
                            onClick={generate}
                        >
                            {busy && (
                                <Loader2 className="size-4 animate-spin" />
                            )}
                            {busy || 'Generate report'}
                        </Button>
                    )}
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
