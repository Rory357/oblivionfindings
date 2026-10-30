import { DatePicker } from '@/components/fleet-assets/maintenance/date-picker';
import {
    reportAgeLabel,
    reportAgeOptions,
} from '@/components/referrals/report-ages';
import type {
    ReferralReport,
    ReportFilters,
} from '@/components/referrals/report-types';
import {
    reportClassificationLabel,
    reportClassificationLabels,
} from '@/components/referrals/report-types';
import { label } from '@/components/referrals/types';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Field, SelectInput } from '@/components/wizard/primitives';
import { formatDateOnly, toDateInput } from '@/lib/datetime';
import axios from 'axios';
import { useState } from 'react';

export function ReportDates({
    filters,
    onApply,
    onClose,
}: {
    filters: ReportFilters;
    onApply: (from: string, to: string) => void;
    onClose: () => void;
}) {
    const [from, setFrom] = useState(filters.from);
    const [to, setTo] = useState(filters.to);
    const invalid =
        !from ||
        !to ||
        to < from ||
        to > toDateInput(new Date()) ||
        (Date.parse(to) - Date.parse(from)) / 86400000 > 365;
    return (
        <Dialog open onOpenChange={(open) => !open && onClose()}>
            <DialogContent
                className="max-h-[90vh] overflow-y-auto"
                style={{
                    width: 'min(92vw, 720px)',
                    maxWidth: 'min(92vw, 720px)',
                }}
            >
                <DialogHeader>
                    <DialogTitle>Received-date range</DialogTitle>
                    <DialogDescription>
                        Choose up to 366 inclusive New Zealand calendar days.
                        Outcomes are current at report generation.
                    </DialogDescription>
                </DialogHeader>
                <div className="grid gap-4 sm:grid-cols-2">
                    <Field label="Received from">
                        <DatePicker
                            compact
                            id="report-from"
                            label="Received from"
                            value={from}
                            onChange={setFrom}
                        />
                    </Field>
                    <Field label="Received through">
                        <DatePicker
                            compact
                            id="report-to"
                            label="Received through"
                            value={to}
                            onChange={setTo}
                        />
                    </Field>
                </div>
                {invalid && (
                    <p role="alert" className="text-destructive">
                        Choose an end date on or after the start, within 366
                        days, ending today or earlier.
                    </p>
                )}
                <DialogFooter>
                    <Button variant="ghost" onClick={onClose}>
                        Cancel
                    </Button>
                    <Button
                        disabled={invalid}
                        onClick={() => onApply(from, to)}
                    >
                        Apply dates
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

export function ReportExport({
    report,
    sites = [],
    recordCount,
    onClose,
    onDenied,
}: {
    report: ReferralReport;
    sites?: { id: number; name: string }[];
    recordCount: number;
    onClose: () => void;
    onDenied: () => void;
}) {
    const [format, setFormat] = useState('summary');
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState('');
    const [saved, setSaved] = useState(false);
    const siteLabel =
        report.filters.site === 'all'
            ? 'All accessible sites'
            : report.filters.site === 'central'
              ? 'Central intake'
              : (sites.find((site) => String(site.id) === report.filters.site)
                    ?.name ?? 'Selected site');
    const ownerLabel =
        report.filters.owner === 'all'
            ? 'All owners'
            : report.filters.owner === 'unassigned'
              ? 'Unassigned'
              : (report.groups.owner?.find(
                    (owner) => owner.key === report.filters.owner,
                )?.label ?? 'Selected owner');
    const run = async () => {
        if (busy) return;
        setBusy(true);
        setError('');
        try {
            const response = await axios.post(
                '/operations/referrals/reports/export',
                { ...report.filters, format },
                { responseType: 'blob' },
            );
            const url = URL.createObjectURL(response.data);
            const anchor = document.createElement('a');
            anchor.href = url;
            anchor.download = `referrals-${format}.csv`;
            anchor.click();
            window.setTimeout(() => URL.revokeObjectURL(url), 1000);
            setSaved(true);
        } catch (failure) {
            if (
                axios.isAxiosError(failure) &&
                [401, 403].includes(failure.response?.status ?? 0)
            ) {
                onDenied();
                return;
            }
            let message =
                'The export could not be prepared. Try again; the report remains available.';
            if (
                axios.isAxiosError(failure) &&
                failure.response?.data instanceof Blob
            ) {
                try {
                    const data = JSON.parse(await failure.response.data.text());
                    if (typeof data.message === 'string')
                        message = data.message;
                } catch {
                    /* Keep the safe fallback for non-JSON responses. */
                }
            }
            setError(message);
        } finally {
            setBusy(false);
        }
    };
    return (
        <Dialog open onOpenChange={(open) => !open && !busy && onClose()}>
            <DialogContent
                className="max-h-[90vh] overflow-y-auto"
                style={{
                    width: 'min(92vw, 720px)',
                    maxWidth: 'min(92vw, 720px)',
                }}
            >
                <DialogHeader>
                    <DialogTitle>Export referral report</DialogTitle>
                    <DialogDescription>
                        Access is checked again when preparing the file.
                        Preparation is audited; a downloaded file cannot be
                        recalled.
                    </DialogDescription>
                </DialogHeader>
                {saved ? (
                    <p role="status">
                        The file was prepared and your browser was asked to
                        download it.
                    </p>
                ) : (
                    <>
                        <Field label="What to export">
                            <SelectInput
                                placeholder="Choose export contents"
                                value={format}
                                onChange={setFormat}
                                options={[
                                    {
                                        value: 'summary',
                                        label: 'Report measures and chart values',
                                    },
                                    {
                                        value: 'records',
                                        label: 'Matching referral records',
                                    },
                                ]}
                            />
                        </Field>
                        <dl className="space-y-3 text-sm">
                            <div>
                                <dt className="font-medium">Active filters</dt>
                                <dd>
                                    Classification:{' '}
                                    {reportClassificationLabel(
                                        report.filters.classification,
                                    )}{' '}
                                    · Site: {siteLabel} · Source:{' '}
                                    {report.filters.source
                                        ? label(report.filters.source)
                                        : 'All'}{' '}
                                    · Owner: {ownerLabel} · Stage:{' '}
                                    {report.filters.stage
                                        ? label(report.filters.stage)
                                        : 'All'}{' '}
                                    · Stage age:{' '}
                                    {reportAgeLabel(report.filters.stage_age)} ·
                                    Pending funding age:{' '}
                                    {reportAgeLabel(report.filters.funding_age)}{' '}
                                    · Funding kind:{' '}
                                    {report.filters.funding_kind
                                        ? label(report.filters.funding_kind)
                                        : 'All'}{' '}
                                    · Reported region:{' '}
                                    {report.filters.region
                                        ? (report.groups.region?.find(
                                              (row) =>
                                                  row.key ===
                                                  report.filters.region,
                                          )?.label ?? 'Selected region')
                                        : 'All'}
                                </dd>
                            </div>
                            <div>
                                <dt className="font-medium">
                                    Measure selection
                                </dt>
                                <dd>
                                    {format === 'summary'
                                        ? 'All measures for the report scope'
                                        : report.metrics.find(
                                              (metric) =>
                                                  metric.key ===
                                                  report.filters.metric,
                                          )?.label}
                                </dd>
                            </div>
                            <div>
                                <dt className="font-medium">Scope</dt>
                                <dd>{report.definitions.scope}</dd>
                            </div>
                            {report.filters.scope === 'received' && (
                                <div>
                                    <dt className="font-medium">
                                        Received dates
                                    </dt>
                                    <dd>
                                        {formatDateOnly(report.filters.from)} –{' '}
                                        {formatDateOnly(report.filters.to)}
                                    </dd>
                                </div>
                            )}
                            <div>
                                <dt className="font-medium">Contents</dt>
                                <dd>
                                    {format === 'summary'
                                        ? `Measures, definitions and chart values as this page shows them${report.funding ? ', including funding case counts' : ''}. ${report.privacy?.active ? `Groups of fewer than ${report.privacy.threshold} stay hidden. ` : ''}No referral names or narratives.`
                                        : `${recordCount} currently matching records: reference, name, stage, current stage age, source, site, owner, received and next-due dates. No assessment or funding narratives.`}
                                </dd>
                            </div>
                        </dl>
                        <p className="text-caption">
                            Data is refreshed when the file is prepared, so
                            changes since this page loaded can affect the
                            totals.
                        </p>
                        {format === 'records' && recordCount > 10000 && (
                            <p role="alert" className="text-destructive">
                                Narrow the dates, site or other filters to
                                10,000 matching referrals or fewer, or export
                                the report measures instead.
                            </p>
                        )}
                    </>
                )}
                {error && (
                    <Alert variant="destructive">
                        <AlertDescription>{error}</AlertDescription>
                    </Alert>
                )}
                <DialogFooter>
                    <Button variant="ghost" disabled={busy} onClick={onClose}>
                        {saved ? 'Done' : 'Cancel'}
                    </Button>
                    {!saved && (
                        <Button
                            disabled={
                                busy ||
                                (format === 'records' && recordCount > 10000)
                            }
                            onClick={() => void run()}
                        >
                            {busy ? 'Preparing…' : 'Prepare download'}
                        </Button>
                    )}
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

/** The less-used report filters, kept out of the header row. */
export function ReportMoreFilters({
    filters,
    canFunding,
    onApply,
    onClose,
}: {
    filters: ReportFilters;
    canFunding: boolean;
    onApply: (patch: Partial<ReportFilters>) => void;
    onClose: () => void;
}) {
    const [classification, setClassification] = useState(
        filters.classification || 'all',
    );
    const [stageAge, setStageAge] = useState(filters.stage_age || 'all');
    const [fundingAge, setFundingAge] = useState(filters.funding_age || 'all');
    const [fundingKind, setFundingKind] = useState(
        filters.funding_kind || 'all',
    );
    const value = (choice: string) => (choice === 'all' ? '' : choice);
    return (
        <Dialog open onOpenChange={(open) => !open && onClose()}>
            <DialogContent
                style={{
                    width: 'min(92vw, 480px)',
                    maxWidth: 'min(92vw, 480px)',
                }}
            >
                <DialogHeader>
                    <DialogTitle>More report filters</DialogTitle>
                    <DialogDescription>
                        These narrow every measure, chart and list on the page.
                    </DialogDescription>
                </DialogHeader>
                <div className="grid gap-4">
                    <Field label="Record classification">
                        <SelectInput
                            placeholder="All classifications"
                            value={classification}
                            onChange={setClassification}
                            options={[
                                { value: 'all', label: 'All classifications' },
                                ...Object.keys(reportClassificationLabels).map(
                                    (key) => ({
                                        value: key,
                                        label: reportClassificationLabel(key),
                                    }),
                                ),
                            ]}
                        />
                    </Field>
                    <Field label="Time in current stage">
                        <SelectInput
                            placeholder="Any stage age"
                            value={stageAge}
                            onChange={setStageAge}
                            options={[
                                { value: 'all', label: 'Any stage age' },
                                ...reportAgeOptions,
                            ]}
                        />
                    </Field>
                    {canFunding && (
                        <div className="grid gap-4 sm:grid-cols-2">
                            <Field label="Pending funding age">
                                <SelectInput
                                    placeholder="Any funding age"
                                    value={fundingAge}
                                    onChange={setFundingAge}
                                    options={[
                                        {
                                            value: 'all',
                                            label: 'Any funding age',
                                        },
                                        ...reportAgeOptions,
                                    ]}
                                />
                            </Field>
                            <Field label="Pending funding kind">
                                <SelectInput
                                    placeholder="Any funding kind"
                                    value={fundingKind}
                                    onChange={setFundingKind}
                                    options={[
                                        {
                                            value: 'all',
                                            label: 'Any funding kind',
                                        },
                                        {
                                            value: 'provider',
                                            label: 'Provider funding',
                                        },
                                        {
                                            value: 'income',
                                            label: 'Personal income',
                                        },
                                    ]}
                                />
                            </Field>
                        </div>
                    )}
                </div>
                <DialogFooter>
                    <Button
                        variant="ghost"
                        onClick={() =>
                            onApply({
                                classification: '',
                                stage_age: '',
                                funding_age: '',
                                funding_kind: '',
                            })
                        }
                    >
                        Clear these filters
                    </Button>
                    <Button variant="outline" onClick={onClose}>
                        Cancel
                    </Button>
                    <Button
                        onClick={() =>
                            onApply({
                                classification: value(classification),
                                stage_age: value(stageAge),
                                ...(canFunding
                                    ? {
                                          funding_age: value(fundingAge),
                                          funding_kind: value(fundingKind),
                                      }
                                    : {}),
                            })
                        }
                    >
                        Apply filters
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
