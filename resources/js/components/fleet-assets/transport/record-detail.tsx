import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { WizardShell, WizardStepPane } from '@/components/wizard/shell';
import { formatDateTime } from '@/lib/datetime';
import {
    ArrowRight,
    CheckCircle2,
    FileText,
    History,
    KeyRound,
    Loader2,
    Route,
    Users,
} from 'lucide-react';
import { useEffect, useState } from 'react';
import { AddEvidenceDialog } from '../vehicle-workspace/add-evidence-dialog';
import { intentTitles } from './dialogs';
import { RETURN_LABELS, exportPath, transportDay } from './model';
import type { Intent, TransportRecord } from './types';
import { Notice, Panel, Stage } from './ui';
export function availableIntents(row: TransportRecord): Intent[] {
    const out: Intent[] = [];
    if (row.can.manage && ['assessment', 'information'].includes(row.stage))
        out.push('assess', 'information');
    if (row.stage === 'information' && row.can.respond) out.push('respond');
    const source = row.source_booking?.can || row.source_actions;
    if (source?.approve) out.push('approve');
    if (source?.decline) out.push('decline');
    if (source?.checkout) out.push('out');
    if (row.can.depart) out.push('depart');
    if (row.can.observe && row.journey?.status === 'in_progress')
        out.push(
            !row.journey.arrived_at
                ? 'arrive'
                : !row.journey.accounted_at
                  ? 'account'
                  : 'complete',
        );
    if (source?.return) out.push('return');
    if (row.can.manage && row.booking?.returned_at && row.missing_items.length)
        out.push('receive_items');
    if (
        row.can.manage &&
        row.booking?.returned_at &&
        row.keys &&
        !row.keys.room_id
    )
        out.push('store_keys');
    if (row.can.note) out.push('note');
    if (source?.cancel && !row.journey && row.booking?.status !== 'checked_out')
        out.push('cancel_booking');
    if (
        row.can.respond &&
        !row.journey &&
        (!row.booking ||
            ['cancelled', 'rejected'].includes(row.booking.status)) &&
        !['cancelled', 'completed'].includes(row.stage)
    )
        out.push('cancel');
    return out;
}
export function TransportRecordContent({
    row,
    onIntent,
    onPlan,
    onFull,
    onRefresh,
    section,
}: {
    row: TransportRecord;
    onIntent: (intent: Intent) => void;
    onPlan: () => void;
    onFull?: () => void;
    onRefresh: () => void;
    section?: 'journey' | 'return' | 'sources' | 'history' | 'evidence';
}) {
    const [query, setQuery] = useState('');
    const [evidence, setEvidence] = useState(false);
    const actions = availableIntents(row);
    const planning =
        row.can.manage &&
        (row.stage === 'allocation' ||
            (row.booking &&
                ['approved', 'pending', 'rejected'].includes(
                    row.booking.status,
                ) &&
                row.stage !== 'cancelled'));
    const first = actions.find(
        (a) =>
            ![
                'note',
                'decline',
                'cancel',
                'cancel_booking',
                'information',
            ].includes(a),
    );
    const journeyStep =
        row.journey?.status === 'completed'
            ? 4
            : row.journey?.arrived_at
              ? 3
              : row.journey
                ? 2
                : row.booking?.status === 'checked_out'
                  ? 1
                  : 0;
    return (
        <div className="transport-workspace tr-stack">
            {(!section || section === 'journey') && (
                <>
                    <div className="tr-record-next">
                        <ArrowRight className="size-6 text-primary" />
                        <div>
                            <small>NEXT STEP · {row.next_owner}</small>
                            <h3>{row.next_action}</h3>
                            <Stage row={row} />
                        </div>
                        {planning &&
                        ['allocation', 'plan_review'].includes(row.stage) ? (
                            <Button onClick={onPlan}>
                                {row.booking ? 'Change plan' : 'Build plan'}
                                <ArrowRight className="size-4" />
                            </Button>
                        ) : first ? (
                            <Button onClick={() => onIntent(first)}>
                                {intentTitles[first]}
                            </Button>
                        ) : null}
                    </div>
                    <ol className="tr-steps" aria-label="Journey progress">
                        {[
                            'Prepare',
                            'Ready to depart',
                            'In progress',
                            'Returned',
                            'Completed',
                        ].map((label, index) => (
                            <li
                                key={label}
                                className="flex flex-1 items-center gap-2 text-xs"
                                aria-current={
                                    index === journeyStep ? 'step' : undefined
                                }
                            >
                                <b
                                    className={
                                        index <= journeyStep
                                            ? 'bg-primary-fill text-primary-fill-foreground'
                                            : ''
                                    }
                                >
                                    {index < journeyStep ? '✓' : index + 1}
                                </b>
                                <span
                                    className={
                                        index === journeyStep
                                            ? 'font-semibold text-primary'
                                            : ''
                                    }
                                >
                                    {label}
                                </span>
                            </li>
                        ))}
                    </ol>
                    <div className="tr-detail-grid">
                        <Panel title="Journey at a glance" icon={Route}>
                            <div className="tr-route">
                                <div>
                                    <small>From</small>
                                    <strong>{row.pickup}</strong>
                                </div>
                                <ArrowRight className="text-primary" />
                                <div>
                                    <small>To</small>
                                    <strong>{row.destination}</strong>
                                </div>
                            </div>
                            <p className="tr-caption">{row.purpose}</p>
                            <div className="tr-fact">
                                <span>Planned departure</span>
                                <strong>
                                    {formatDateTime(
                                        row.booking?.start || row.start,
                                    )}
                                </strong>
                            </div>
                            <div className="tr-fact">
                                <span>Expected return</span>
                                <strong>
                                    {row.booking?.end || row.end
                                        ? formatDateTime(
                                              (row.booking?.end || row.end)!,
                                          )
                                        : 'Needs assessment'}
                                </strong>
                            </div>
                            <div className="tr-fact">
                                <span>Vehicle</span>
                                <strong>
                                    {row.booking?.vehicle.name ||
                                        'Not allocated'}
                                </strong>
                            </div>
                            <div className="tr-fact">
                                <span>Driver / escort</span>
                                <strong>
                                    {row.booking?.driver.name ||
                                        'Not allocated'}{' '}
                                    /{' '}
                                    {row.escort?.name ||
                                        (row.escort_required
                                            ? 'Required'
                                            : 'Not required')}
                                </strong>
                            </div>
                        </Panel>
                        <Panel title="What is left to finish?" icon={Users}>
                            {[
                                ['Departure', row.journey?.departed_at],
                                ['Passenger arrival', row.journey?.arrived_at],
                                [
                                    'Passengers accounted for',
                                    row.journey?.accounted_at,
                                ],
                                [
                                    'Journey complete',
                                    row.journey?.status === 'completed'
                                        ? 'Completed'
                                        : null,
                                ],
                            ].map(([label, value]) => (
                                <div className="tr-fact" key={label}>
                                    <span className="flex items-center gap-2">
                                        <CheckCircle2
                                            className={`size-4 ${value ? 'text-status-success' : 'text-muted-foreground'}`}
                                        />
                                        {label}
                                    </span>
                                    <strong>
                                        {value
                                            ? value === 'Completed'
                                                ? value
                                                : formatDateTime(value)
                                            : 'Not recorded'}
                                    </strong>
                                </div>
                            ))}
                            <p className="tr-caption mt-4">
                                Required medication handoffs are checked in
                                their source record before completion.
                            </p>
                            {row.links.journey && (
                                <Button
                                    className="mt-3"
                                    variant="outline"
                                    asChild
                                >
                                    <a href={row.links.journey}>
                                        Open journey & handoffs
                                        <ArrowRight className="size-4" />
                                    </a>
                                </Button>
                            )}
                        </Panel>
                    </div>
                </>
            )}
            {(!section || section === 'return') && (
                <>
                    <Panel title="Vehicle return & items" icon={KeyRound}>
                        <div className="tr-plan-fields">
                            <div>
                                <h4 className="font-semibold">
                                    {RETURN_LABELS[row.return_stage]}
                                </h4>
                                <p className="tr-caption mt-2">
                                    Vehicle returned:{' '}
                                    {row.booking?.returned_at
                                        ? formatDateTime(
                                              row.booking.returned_at,
                                          )
                                        : 'Not recorded'}
                                </p>
                                <p className="tr-caption">
                                    Missing items:{' '}
                                    {row.missing_items.join(', ') ||
                                        'No required item outstanding'}
                                </p>
                            </div>
                            <div>
                                <h4 className="font-semibold">
                                    Key collection & return
                                </h4>
                                <p className="tr-caption mt-2">
                                    {row.key_delivery_arrangement ||
                                        'Arrange in Planner'}
                                </p>
                                <p className="tr-caption">
                                    Collect from:{' '}
                                    {row.rooms?.find(
                                        (room) =>
                                            room.id === row.key_pickup_room_id,
                                    )?.name || 'Not arranged'}
                                    {' · '}Return to:{' '}
                                    {row.rooms?.find(
                                        (room) =>
                                            room.id === row.key_return_room_id,
                                    )?.name || 'Not arranged'}
                                </p>
                                <p className="tr-caption">
                                    Recorded storage:{' '}
                                    {row.keys?.room_id
                                        ? row.keys.location
                                        : 'Not confirmed'}
                                </p>
                            </div>
                        </div>
                        <div className="mt-4 flex flex-wrap gap-2">
                            {actions
                                .filter((a) =>
                                    [
                                        'return',
                                        'receive_items',
                                        'store_keys',
                                    ].includes(a),
                                )
                                .map((a) => (
                                    <Button
                                        key={a}
                                        variant="outline"
                                        onClick={() => onIntent(a)}
                                    >
                                        {intentTitles[a]}
                                    </Button>
                                ))}
                            {row.can.manage &&
                                row.booking &&
                                ['checked_out', 'returned'].includes(
                                    row.booking.status,
                                ) && (
                                    <Button variant="ghost" asChild>
                                        <a
                                            href={`/fleet-assets/handovers/create?booking_id=${row.booking.id}`}
                                        >
                                            Shift handover · only if custody
                                            changes
                                            <ArrowRight className="size-4" />
                                        </a>
                                    </Button>
                                )}
                        </div>
                        {row.handovers?.map((h) => (
                            <p className="tr-caption mt-3" key={h.id}>
                                <a
                                    className="text-primary"
                                    href={`/fleet-assets/handovers/${h.id}`}
                                >
                                    Handover #{h.id} ·{' '}
                                    {h.status.replaceAll('_', ' ')}
                                </a>
                            </p>
                        ))}
                    </Panel>
                </>
            )}
            {(!section || section === 'journey') && (
                <>
                    {row.information_required && (
                        <Notice>
                            Information requested: {row.information_required}
                        </Notice>
                    )}
                    {row.booking?.decision_reason && (
                        <Notice>
                            Booking outcome: {row.booking.decision_reason}. Open
                            the Fleet booking to review its decision and
                            history.
                        </Notice>
                    )}
                </>
            )}
            {(!section || section === 'sources') && (
                <Panel title="Linked source records" icon={FileText}>
                    <div className="tr-plan-fields">
                        {Object.entries(row.links)
                            .filter(
                                ([key, value]) => value && key !== 'request',
                            )
                            .map(([key, value]) => (
                                <a
                                    className="tr-fact text-primary"
                                    key={key}
                                    href={value!}
                                >
                                    <span className="capitalize">
                                        {key === 'vehicle'
                                            ? 'Vehicle trip history'
                                            : key}
                                    </span>
                                    <ArrowRight className="size-4" />
                                </a>
                            ))}
                    </div>
                </Panel>
            )}
            {(!section || section === 'history') && (
                <Panel
                    title="History & operational notes"
                    icon={History}
                    action={
                        row.can.note ? (
                            <Button
                                size="sm"
                                variant="outline"
                                onClick={() => onIntent('note')}
                            >
                                Add note
                            </Button>
                        ) : undefined
                    }
                >
                    <Input
                        aria-label="Search transport history"
                        placeholder="Search history or notes…"
                        value={query}
                        onChange={(e) => setQuery(e.target.value)}
                    />
                    <p className="tr-caption my-4">
                        {row.operational_notes ||
                            'No operational instructions recorded.'}
                    </p>
                    <ul className="tr-history">
                        {(row.history || [])
                            .filter((e) =>
                                `${e.action} ${e.actor} ${e.message} ${e.items.join(' ')}`
                                    .toLowerCase()
                                    .includes(query.toLowerCase()),
                            )
                            .map((e) => (
                                <li key={e.id}>
                                    <small>
                                        {formatDateTime(e.at)} · {e.actor}
                                    </small>
                                    <p className="font-semibold capitalize">
                                        {e.action.replaceAll('_', ' ')}
                                    </p>
                                    <p>
                                        {e.message} {e.items.join(', ')}
                                    </p>
                                </li>
                            ))}
                    </ul>
                </Panel>
            )}
            {(!section || section === 'evidence') && row.source_booking && (
                <Panel
                    title="Supporting evidence"
                    icon={FileText}
                    action={
                        row.source_booking.can.upload ? (
                            <Button
                                size="sm"
                                variant="outline"
                                onClick={() => setEvidence(true)}
                            >
                                Add evidence
                            </Button>
                        ) : undefined
                    }
                >
                    <p className="tr-caption">
                        Files stay with the linked Fleet booking and are
                        available after the private file safety checks.
                    </p>
                    {row.source_booking.files.map((file) => (
                        <div className="tr-fact" key={file.id}>
                            <span>
                                {file.name} · {file.state}
                            </span>
                            {file.url ? (
                                <a className="text-primary" href={file.url}>
                                    Download
                                </a>
                            ) : (
                                <strong>Awaiting source checks</strong>
                            )}
                        </div>
                    ))}
                </Panel>
            )}
            {(!section || section === 'journey') && (
                <div className="flex flex-wrap justify-between gap-3">
                    <div className="flex flex-wrap gap-2">
                        {planning && row.booking && (
                            <Button variant="outline" onClick={onPlan}>
                                Change plan
                            </Button>
                        )}
                        {actions
                            .filter(
                                (a) =>
                                    ![
                                        'return',
                                        'receive_items',
                                        'store_keys',
                                        first,
                                        'note',
                                    ].includes(a),
                            )
                            .map((a) => (
                                <Button
                                    key={a}
                                    variant="outline"
                                    onClick={() => onIntent(a)}
                                >
                                    {intentTitles[a]}
                                </Button>
                            ))}
                    </div>
                    {!section && (
                        <div className="flex gap-2">
                            <Button variant="outline" asChild>
                                <a
                                    href={exportPath(
                                        {
                                            from: transportDay(row.start),
                                            to: transportDay(row.start),
                                            site: 'all',
                                            search: '',
                                        },
                                        row.id,
                                    )}
                                >
                                    <FileText className="size-4" />
                                    Export PDF
                                </a>
                            </Button>
                            {onFull && (
                                <Button variant="outline" onClick={onFull}>
                                    Open full record
                                    <ArrowRight className="size-4" />
                                </Button>
                            )}
                        </div>
                    )}
                </div>
            )}
            {evidence && row.booking && (
                <AddEvidenceDialog
                    vehicle={row.booking.vehicle}
                    title="Add transport evidence"
                    category="Transport evidence"
                    sourceType="booking"
                    sourceId={row.booking.id}
                    onClose={() => setEvidence(false)}
                    onSaved={onRefresh}
                />
            )}
        </div>
    );
}
export function TransportQuickView({
    id,
    onClose,
    onIntent,
    onPlan,
}: {
    id: number;
    onClose: () => void;
    onIntent: (row: TransportRecord, intent: Intent) => void;
    onPlan: (row: TransportRecord) => void;
}) {
    const [row, setRow] = useState<TransportRecord | null>(null),
        [error, setError] = useState(''),
        [retry, setRetry] = useState(0);
    const [section, setSection] = useState(0);
    const sections = [
        {
            key: 'journey',
            label: 'Journey',
            blurb: 'Progress and next action',
            icon: Route,
        },
        {
            key: 'return',
            label: 'Return & keys',
            blurb: 'Items, storage and handovers',
            icon: KeyRound,
        },
        {
            key: 'sources',
            label: 'Linked records',
            blurb: 'Request, vehicle and source links',
            icon: FileText,
        },
        {
            key: 'history',
            label: 'History & notes',
            blurb: 'Who recorded each change',
            icon: History,
        },
        ...(row?.source_booking
            ? [
                  {
                      key: 'evidence',
                      label: 'Evidence',
                      blurb: 'Files from the Fleet booking',
                      icon: FileText,
                  },
              ]
            : []),
    ];
    useEffect(() => {
        const controller = new AbortController();
        setRow(null);
        setError('');
        fetch(`/fleet-assets/transports/requests/${id}`, {
            signal: controller.signal,
            headers: { Accept: 'application/json' },
            cache: 'no-store',
        })
            .then(async (response) => {
                if (!response.ok)
                    throw new Error(
                        response.status === 404 || response.status === 403
                            ? 'This record is not available in your current access.'
                            : 'The transport record could not be loaded.',
                    );
                setRow(await response.json());
            })
            .catch((error) => {
                if (!controller.signal.aborted) setError(error.message);
            });
        return () => controller.abort();
    }, [id, retry]);
    return (
        <WizardShell
            open
            onClose={onClose}
            title={
                row ? `${row.person} · ${row.reference}` : 'Transport details'
            }
            description="Review the journey, return, linked records and history."
            railIcon={Route}
            railTitle={row?.person || 'Transport details'}
            railSub={
                row
                    ? `${row.reference} · ${row.site.name}`
                    : 'Loading current record'
            }
            steps={sections}
            sequential={false}
            stepIndex={section}
            onStepClick={setSection}
            headerLabel={sections[section]?.label || 'Transport details'}
            maxWidth="min(92vw, 1100px)"
            footerStart={
                <Button variant="outline" onClick={onClose}>
                    Close
                </Button>
            }
            footerEnd={
                row ? (
                    <>
                        <Button variant="outline" asChild>
                            <a
                                href={exportPath(
                                    {
                                        from: transportDay(row.start),
                                        to: transportDay(row.start),
                                        site: 'all',
                                        search: '',
                                    },
                                    row.id,
                                )}
                            >
                                <FileText className="size-4" />
                                Export PDF
                            </a>
                        </Button>
                        <Button asChild>
                            <a href={row.links.request}>
                                Open full record
                                <ArrowRight className="size-4" />
                            </a>
                        </Button>
                    </>
                ) : undefined
            }
        >
            <div className="mb-4 sm:hidden">
                <Select
                    value={String(section)}
                    onValueChange={(value) => setSection(Number(value))}
                >
                    <SelectTrigger
                        aria-label="Transport details section"
                        className="w-full"
                    >
                        <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                        {sections.map((item, index) => (
                            <SelectItem key={item.key} value={String(index)}>
                                {item.label}
                            </SelectItem>
                        ))}
                    </SelectContent>
                </Select>
            </div>
            <WizardStepPane key={section}>
                {error ? (
                    <Notice>
                        {error}
                        <Button
                            variant="outline"
                            onClick={() => setRetry((v) => v + 1)}
                        >
                            Retry
                        </Button>
                    </Notice>
                ) : row ? (
                    <TransportRecordContent
                        row={row}
                        section={
                            sections[section]?.key as
                                | 'journey'
                                | 'return'
                                | 'sources'
                                | 'history'
                                | 'evidence'
                        }
                        onIntent={(intent) => onIntent(row, intent)}
                        onPlan={() => onPlan(row)}
                        onRefresh={() => setRetry((v) => v + 1)}
                        onFull={() => window.location.assign(row.links.request)}
                    />
                ) : (
                    <div className="tr-empty">
                        <Loader2 className="mx-auto size-6 animate-spin" />
                        Loading current source records…
                    </div>
                )}
            </WizardStepPane>
        </WizardShell>
    );
}
