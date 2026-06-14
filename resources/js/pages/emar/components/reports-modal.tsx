/* Reports & exports — BUILD-NEW modal on the shared Add-Client wizard chrome.
 * Single-purpose 2-step picker that builds a GET download URL for the existing
 * eMAR export/PDF routes and triggers the download (window.location.href). */
import { MedsWizardDialog, SummaryRow } from '@/components/meds/wizard-shell';
import {
    Field,
    InfoCard,
    SelectInput,
    StepHead,
    TilePicker,
} from '@/components/wizard/primitives';
import { Button } from '@/components/ui/button';
import { FileText, Info, Printer } from 'lucide-react';
import { useMemo, useState } from 'react';

import type { ClientOption } from './report-error-modal';

type ReportDef = {
    key: string;
    label: string;
    description: string;
    url: string;
    format: 'PDF' | 'CSV';
    client: 'required' | 'optional' | 'none';
    range: boolean;
    single?: boolean;
    reportType?: boolean;
};

const REPORTS: ReportDef[] = [
    { key: 'mar_pdf', label: 'MAR chart (PDF)', description: 'Full medication chart for one client', url: '/emar/pdf/mar-chart', format: 'PDF', client: 'required', range: true },
    { key: 'cd_pdf', label: 'CD register (PDF)', description: 'Controlled-drug register for one client', url: '/emar/pdf/controlled-register', format: 'PDF', client: 'required', range: true },
    { key: 'round_pdf', label: 'Round sheet (PDF)', description: 'Printable round sheet for a day', url: '/emar/pdf/round-sheet', format: 'PDF', client: 'none', range: false, single: true },
    { key: 'mar_csv', label: 'MAR administrations (CSV)', description: 'Administration records export', url: '/emar/reports/export-mar', format: 'CSV', client: 'optional', range: true },
    { key: 'cd_csv', label: 'CD discrepancies (CSV)', description: 'Controlled-drug discrepancies export', url: '/emar/reports/export-controlled-discrepancies', format: 'CSV', client: 'optional', range: true },
    { key: 'activity_csv', label: 'eMAR activity report (CSV)', description: 'Administrations · PRN · controlled · rounds · errors', url: '/emar/reports/export', format: 'CSV', client: 'optional', range: true, reportType: true },
];

const ACTIVITY_TYPES = [
    { value: 'administration', label: 'Administrations' },
    { value: 'prn', label: 'PRN' },
    { value: 'controlled', label: 'Controlled drugs' },
    { value: 'rounds', label: 'Rounds' },
    { value: 'errors', label: 'Medication errors' },
];

function ymdMinusDays(base: string, days: number): string {
    const d = new Date(`${base}T00:00:00`);
    if (Number.isNaN(d.getTime())) return base;
    d.setDate(d.getDate() - days);
    return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
}

export function ReportsModal({
    open,
    onClose,
    clients,
    defaultDate,
}: {
    open: boolean;
    onClose: () => void;
    clients: ClientOption[];
    defaultDate: string;
}) {
    const [step, setStep] = useState(0);
    const [reportKey, setReportKey] = useState('');
    const [clientId, setClientId] = useState('');
    const [dateFrom, setDateFrom] = useState(ymdMinusDays(defaultDate, 30));
    const [dateTo, setDateTo] = useState(defaultDate);
    const [singleDate, setSingleDate] = useState(defaultDate);
    const [reportType, setReportType] = useState('administration');

    const report = useMemo(() => REPORTS.find((r) => r.key === reportKey), [reportKey]);

    const reset = () => {
        setStep(0);
        setReportKey('');
        setClientId('');
        setDateFrom(ymdMinusDays(defaultDate, 30));
        setDateTo(defaultDate);
        setSingleDate(defaultDate);
        setReportType('administration');
    };

    const close = () => {
        reset();
        onClose();
    };

    const canGenerate = !!report && (report.client !== 'required' || clientId !== '');

    const generate = () => {
        if (!report) return;
        const params = new URLSearchParams();
        if (report.single) {
            params.set('date', singleDate);
        } else if (report.range) {
            params.set('date_from', dateFrom);
            params.set('date_to', dateTo);
        }
        if (report.client !== 'none' && clientId) params.set('client_id', clientId);
        if (report.reportType) params.set('report_type', reportType);
        // GET download — leave the SPA in place, let the browser fetch the file.
        window.location.href = `${report.url}?${params.toString()}`;
        close();
    };

    const clientName = clients.find((c) => String(c.id) === clientId)?.name ?? 'All clients';

    const footer = (
        <>
            <Button variant="ghost" onClick={step === 0 ? close : () => setStep(0)}>
                {step === 0 ? 'Cancel' : 'Back'}
            </Button>
            {step === 0 ? (
                <Button onClick={() => setStep(1)} disabled={!report}>
                    Continue
                </Button>
            ) : (
                <Button onClick={generate} disabled={!canGenerate}>
                    <Printer className="h-4 w-4" />
                    Generate &amp; download
                </Button>
            )}
        </>
    );

    return (
        <MedsWizardDialog
            open={open}
            onClose={close}
            title="Reports & exports"
            description="Generate and download an eMAR report or register."
            railIcon={Printer}
            railTitle="Reports & exports"
            railSubtitle="MAR · CD · activity"
            steps={[
                { key: 'choose', label: 'Choose report', blurb: 'What to export', icon: FileText },
                { key: 'range', label: 'Range & format', blurb: 'Scope & generate', icon: Printer },
            ]}
            stepIndex={step}
            onStepClick={(i) => i < step && setStep(i)}
            footer={footer}
        >
            {step === 0 ? (
                <div>
                    <StepHead icon={FileText} title="Choose a report" blurb="Pick the register or export to generate." />
                    <TilePicker
                        value={reportKey}
                        onChange={setReportKey}
                        cols={2}
                        options={REPORTS.map((r) => ({
                            key: r.key,
                            label: r.label,
                            description: r.description,
                            meta: r.format,
                        }))}
                    />
                </div>
            ) : (
                <div className="grid gap-5 sm:grid-cols-2">
                    <StepHead icon={Printer} title="Range & scope" blurb="Set the period and client, then generate." />
                    {report?.single ? (
                        <Field label="Date" required span>
                            {/* eslint-disable-next-line no-restricted-syntax -- native date input; no shadcn date control in wizard primitives. */}
                            <input
                                type="date"
                                value={singleDate}
                                onChange={(e) => setSingleDate(e.target.value)}
                                className="h-10 w-full rounded-md border border-border bg-background px-3 text-sm outline-none focus:ring-2 focus:ring-primary/40"
                            />
                        </Field>
                    ) : (
                        <>
                            <Field label="From" required>
                                {/* eslint-disable-next-line no-restricted-syntax -- native date input. */}
                                <input
                                    type="date"
                                    value={dateFrom}
                                    onChange={(e) => setDateFrom(e.target.value)}
                                    className="h-10 w-full rounded-md border border-border bg-background px-3 text-sm outline-none focus:ring-2 focus:ring-primary/40"
                                />
                            </Field>
                            <Field label="To" required>
                                {/* eslint-disable-next-line no-restricted-syntax -- native date input. */}
                                <input
                                    type="date"
                                    value={dateTo}
                                    onChange={(e) => setDateTo(e.target.value)}
                                    className="h-10 w-full rounded-md border border-border bg-background px-3 text-sm outline-none focus:ring-2 focus:ring-primary/40"
                                />
                            </Field>
                        </>
                    )}
                    {report && report.client !== 'none' ? (
                        <Field label="Client" required={report.client === 'required'} span>
                            <SelectInput
                                value={clientId}
                                onChange={setClientId}
                                placeholder={report.client === 'required' ? 'Select client' : 'All clients'}
                                options={[
                                    ...(report.client === 'optional' ? [{ value: '', label: 'All clients' }] : []),
                                    ...clients.map((c) => ({ value: String(c.id), label: c.name })),
                                ]}
                            />
                        </Field>
                    ) : null}
                    {report?.reportType ? (
                        <Field label="Activity type" span>
                            <SelectInput value={reportType} onChange={setReportType} placeholder="Select activity" options={ACTIVITY_TYPES} />
                        </Field>
                    ) : null}
                    <div className="col-span-full rounded-lg border border-border">
                        <div className="px-4">
                            <SummaryRow label="Report" value={report?.label ?? '—'} />
                            <SummaryRow label="Format" value={report?.format ?? '—'} />
                            <SummaryRow label="Scope" value={report?.single ? singleDate : `${dateFrom} → ${dateTo}`} />
                            {report && report.client !== 'none' ? <SummaryRow label="Client" value={clientName} /> : null}
                        </div>
                    </div>
                    <InfoCard icon={Info}>
                        The file downloads in your browser. PDF charts and the CD register cover one client;
                        CSV exports can span all clients.
                    </InfoCard>
                </div>
            )}
        </MedsWizardDialog>
    );
}

export default ReportsModal;
