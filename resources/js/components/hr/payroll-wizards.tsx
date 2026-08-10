/* Payroll wizards — Create-pay-run and Export-profile (create/edit) stepper
 * modals, built on the shared HR wizard kit (WizardShell + primitives) so they
 * match the Add-Client / asset lifecycle modals. Both submit to the existing
 * payroll endpoints with the exact same payload contracts as the old plain
 * dialogs (POST /hr/payroll/runs, POST/PUT /hr/payroll/export-profiles). */
import { useForm } from '@inertiajs/react';
import {
    Banknote,
    CalendarRange,
    CheckCircle2,
    ClipboardCheck,
    Columns3,
    FileText,
    Plus,
    Settings2,
    Trash2,
} from 'lucide-react';
import { useState } from 'react';
import { toast } from 'sonner';

import {
    Field,
    FieldErr,
    ReviewCard,
    ReviewRow,
    Segmented,
    SelectInput,
    StepHead,
    useWizard,
    WizardShell,
    WizardStepPane,
    WizardSuccessPane,
    type WizardStep,
} from '@/components/hr/wizard';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { fireConfetti } from '@/lib/confetti';

/* ------------------------------------------------------------------ */
/*  Shared types (imported by the Payroll index page)                  */
/* ------------------------------------------------------------------ */

export interface PayrollExportProfile {
    id: number;
    name: string;
    provider_key: string | null;
    description: string | null;
    delimiter: string;
    enclosure: string;
    line_ending: string;
    include_headers: boolean;
    is_default: boolean;
    mappings: Array<{ header: string; source: string; value?: unknown }>;
}

export interface ExportFieldOption {
    value: string;
    label: string;
}

/** Flash error carried by an Inertia redirect — `back()->with('error')` fires
 *  onSuccess, not onError (see reference_inertia_flash_error). */
function pageFlashError(page: {
    props: Record<string, unknown>;
}): string | null {
    const flash = page.props.flash as { error?: string } | undefined;
    return flash?.error ?? null;
}

function toDateInputValue(date: Date): string {
    const offsetDate = new Date(
        date.getTime() - date.getTimezoneOffset() * 60000,
    );
    return offsetDate.toISOString().slice(0, 10);
}

function fdate(value: string): string {
    if (!value) return '—';
    const date = new Date(`${value}T00:00:00`);
    if (Number.isNaN(date.getTime())) return value;
    return new Intl.DateTimeFormat('en-NZ', {
        day: '2-digit',
        month: 'short',
        year: 'numeric',
    }).format(date);
}

/* ================================================================== */
/*  Create pay run                                                     */
/* ================================================================== */

const RUN_STEPS: readonly WizardStep[] = [
    {
        key: 'period',
        label: 'Pay period',
        blurb: 'Dates & notes',
        icon: CalendarRange,
    },
    {
        key: 'review',
        label: 'Review',
        blurb: 'Confirm & create',
        icon: CheckCircle2,
    },
];

export function CreateRunWizard({ onClose }: { onClose: () => void }) {
    const wizard = useWizard(RUN_STEPS.length);
    const [done, setDone] = useState(false);

    const defaultStart = new Date();
    const defaultEnd = new Date(defaultStart);
    defaultEnd.setDate(defaultEnd.getDate() + 13);

    const form = useForm({
        period_start: toDateInputValue(defaultStart),
        period_end: toDateInputValue(defaultEnd),
        notes: '',
    });
    const serverErrors = form.errors as Record<string, string | undefined>;

    const periodValid =
        form.data.period_start !== '' &&
        form.data.period_end !== '' &&
        form.data.period_end > form.data.period_start;

    const periodDays = periodValid
        ? Math.round(
              (new Date(`${form.data.period_end}T00:00:00`).getTime() -
                  new Date(`${form.data.period_start}T00:00:00`).getTime()) /
                  86400000,
          ) + 1
        : null;

    const submit = () => {
        form.post('/hr/payroll/runs', {
            preserveScroll: true,
            onSuccess: (page) => {
                const err = pageFlashError(page);
                if (err) {
                    toast.error(err);
                    return;
                }
                setDone(true);
                fireConfetti();
            },
        });
    };

    return (
        <WizardShell
            open
            onClose={onClose}
            title="Create payroll run"
            description="Enter the pay period dates to generate a draft run."
            railIcon={Banknote}
            railTitle="Create pay run"
            railSub="Draft payroll period"
            steps={RUN_STEPS}
            stepIndex={wizard.index}
            onStepClick={wizard.goTo}
            pct={wizard.progress}
            success={
                done ? (
                    <WizardSuccessPane
                        title="Pay run created"
                        blurb={
                            <>
                                A draft run for {fdate(form.data.period_start)}{' '}
                                – {fdate(form.data.period_end)} is ready. Review
                                its items, then lock and export it to your
                                payroll provider.
                            </>
                        }
                        actions={<Button onClick={onClose}>Done</Button>}
                    />
                ) : undefined
            }
            footerStart={
                wizard.isFirst ? null : (
                    <Button variant="outline" onClick={wizard.back}>
                        Back
                    </Button>
                )
            }
            footerEnd={
                <>
                    <Button variant="ghost" onClick={onClose}>
                        Cancel
                    </Button>
                    {wizard.isLast ? (
                        <Button
                            onClick={submit}
                            disabled={form.processing || !periodValid}
                        >
                            {form.processing ? 'Creating…' : 'Create run'}
                        </Button>
                    ) : (
                        <Button onClick={wizard.next} disabled={!periodValid}>
                            Continue
                        </Button>
                    )}
                </>
            }
        >
            {wizard.index === 0 && (
                <WizardStepPane>
                    <StepHead
                        icon={CalendarRange}
                        title="Which pay period?"
                        blurb="The draft run gathers approved time into gross pay for this window — PAYE and KiwiSaver are applied by your payroll provider after export."
                    />
                    <div className="grid gap-3.5 sm:grid-cols-2">
                        <Field
                            label="Period start"
                            required
                            error={serverErrors.period_start}
                        >
                            <Input
                                type="date"
                                value={form.data.period_start}
                                onChange={(e) =>
                                    form.setData('period_start', e.target.value)
                                }
                            />
                        </Field>
                        <Field
                            label="Period end"
                            required
                            error={serverErrors.period_end}
                        >
                            <Input
                                type="date"
                                min={form.data.period_start || undefined}
                                value={form.data.period_end}
                                onChange={(e) =>
                                    form.setData('period_end', e.target.value)
                                }
                            />
                        </Field>
                    </div>
                    {form.data.period_end !== '' &&
                    form.data.period_start !== '' &&
                    form.data.period_end <= form.data.period_start ? (
                        <FieldErr>
                            Period end must be after the period start.
                        </FieldErr>
                    ) : null}
                    <FieldErr>{serverErrors.period}</FieldErr>
                    <div className="mt-3.5">
                        <Field
                            label="Notes"
                            hint="optional"
                            error={serverErrors.notes}
                        >
                            <Input
                                value={form.data.notes}
                                onChange={(e) =>
                                    form.setData('notes', e.target.value)
                                }
                                placeholder="Optional payroll notes"
                            />
                        </Field>
                    </div>
                </WizardStepPane>
            )}

            {wizard.index === 1 && (
                <WizardStepPane>
                    <StepHead
                        icon={ClipboardCheck}
                        title="Confirm the pay run"
                        blurb="Check the period, then create the draft."
                    />
                    <div className="grid gap-3 sm:grid-cols-2">
                        <ReviewCard
                            icon={CalendarRange}
                            title="Pay period"
                            onEdit={() => wizard.goTo(0)}
                            span
                        >
                            <ReviewRow
                                label="Start"
                                value={fdate(form.data.period_start)}
                            />
                            <ReviewRow
                                label="End"
                                value={fdate(form.data.period_end)}
                            />
                            <ReviewRow
                                label="Length"
                                value={
                                    periodDays != null
                                        ? `${periodDays} days`
                                        : undefined
                                }
                            />
                            <ReviewRow
                                label="Notes"
                                value={form.data.notes || undefined}
                            />
                        </ReviewCard>
                    </div>
                    <FieldErr>{serverErrors.period}</FieldErr>
                </WizardStepPane>
            )}
        </WizardShell>
    );
}

/* ================================================================== */
/*  Export profile (create / edit)                                     */
/* ================================================================== */

const PROFILE_STEPS: readonly WizardStep[] = [
    {
        key: 'basics',
        label: 'Basics',
        blurb: 'Name & provider',
        icon: FileText,
    },
    {
        key: 'format',
        label: 'File format',
        blurb: 'Delimiters & headers',
        icon: Settings2,
    },
    {
        key: 'columns',
        label: 'Columns',
        blurb: 'Export mappings',
        icon: Columns3,
    },
    {
        key: 'review',
        label: 'Review',
        blurb: 'Confirm & save',
        icon: CheckCircle2,
    },
];

const LINE_ENDING_OPTIONS = [
    { value: '\\n', label: 'LF (\\n)' },
    { value: '\\r\\n', label: 'CRLF (\\r\\n)' },
    { value: '\\r', label: 'CR (\\r)' },
];

type MappingRow = { header: string; source: string; value: string };

const DEFAULT_MAPPINGS: MappingRow[] = [
    { header: 'Employee ID', source: 'employee_number', value: '' },
    { header: 'Employee Name', source: 'name', value: '' },
    { header: 'Regular Hours', source: 'regular_hours', value: '' },
    { header: 'Overtime Hours', source: 'overtime_hours', value: '' },
    { header: 'Gross Pay', source: 'gross_pay', value: '' },
];

function normalizeLineEnding(value: string): string {
    if (value === '\r\n' || value === '\\r\\n') return '\\r\\n';
    if (value === '\r' || value === '\\r') return '\\r';
    return '\\n';
}

export function ExportProfileWizard({
    profile,
    fieldOptions,
    isFirstProfile,
    onClose,
}: {
    /** Existing profile = edit mode; null = create. */
    profile: PayrollExportProfile | null;
    fieldOptions: ExportFieldOption[];
    /** Pre-tick "default" when the tenant has no profiles yet. */
    isFirstProfile: boolean;
    onClose: () => void;
}) {
    const isEdit = profile !== null;
    const wizard = useWizard(PROFILE_STEPS.length);
    const [done, setDone] = useState(false);

    const form = useForm({
        name: profile?.name ?? '',
        provider_key: profile?.provider_key ?? '',
        description: profile?.description ?? '',
        delimiter: profile?.delimiter || ',',
        enclosure: profile?.enclosure || '"',
        line_ending: normalizeLineEnding(profile?.line_ending ?? '\\n'),
        include_headers: profile?.include_headers ?? true,
        is_default: profile?.is_default ?? isFirstProfile,
        mappings: profile
            ? profile.mappings.map((m) => ({
                  header: m.header,
                  source: m.source,
                  value: m.value == null ? '' : String(m.value),
              }))
            : DEFAULT_MAPPINGS,
    });
    const serverErrors = form.errors as Record<string, string | undefined>;

    const sourceOptions = [
        ...fieldOptions,
        { value: 'static', label: 'Static value' },
    ];

    const validMappings = form.data.mappings.filter(
        (m) => m.header.trim() !== '' && m.source !== '',
    );

    const setMapping = (index: number, patch: Partial<MappingRow>) => {
        form.setData(
            'mappings',
            form.data.mappings.map((m, i) =>
                i === index ? { ...m, ...patch } : m,
            ),
        );
    };

    const submit = () => {
        form.transform((data) => ({
            name: data.name,
            provider_key: data.provider_key || null,
            description: data.description || null,
            delimiter: data.delimiter || ',',
            enclosure: data.enclosure || '"',
            line_ending: data.line_ending || '\\n',
            include_headers: data.include_headers,
            is_default: data.is_default,
            mappings: data.mappings
                .filter((m) => m.header.trim() !== '' && m.source !== '')
                .map((m) =>
                    m.source === 'static'
                        ? {
                              header: m.header.trim(),
                              source: m.source,
                              value: m.value,
                          }
                        : { header: m.header.trim(), source: m.source },
                ),
        }));

        const options = {
            preserveScroll: true,
            onSuccess: (page: { props: Record<string, unknown> }) => {
                const err = pageFlashError(page);
                if (err) {
                    toast.error(err);
                    return;
                }
                setDone(true);
                if (!isEdit) fireConfetti();
            },
        };

        if (isEdit) {
            form.put(`/hr/payroll/export-profiles/${profile.id}`, options);
        } else {
            form.post('/hr/payroll/export-profiles', options);
        }
    };

    return (
        <WizardShell
            open
            onClose={onClose}
            title={isEdit ? 'Edit export profile' : 'Create export profile'}
            description="Configure payroll export columns and separators for your payroll provider."
            railIcon={FileText}
            railTitle={isEdit ? 'Edit profile' : 'Export profile'}
            railSub="Provider CSV mapping"
            steps={PROFILE_STEPS}
            stepIndex={wizard.index}
            onStepClick={wizard.goTo}
            pct={wizard.progress}
            success={
                done ? (
                    <WizardSuccessPane
                        title={isEdit ? 'Profile updated' : 'Profile created'}
                        blurb={
                            <>
                                “{form.data.name}” is ready with{' '}
                                {validMappings.length} column
                                {validMappings.length === 1 ? '' : 's'}. Pick it
                                when exporting a locked pay run.
                            </>
                        }
                        actions={<Button onClick={onClose}>Done</Button>}
                    />
                ) : undefined
            }
            footerStart={
                wizard.isFirst ? null : (
                    <Button variant="outline" onClick={wizard.back}>
                        Back
                    </Button>
                )
            }
            footerEnd={
                <>
                    <Button variant="ghost" onClick={onClose}>
                        Cancel
                    </Button>
                    {wizard.isLast ? (
                        <Button
                            onClick={submit}
                            disabled={
                                form.processing ||
                                form.data.name.trim() === '' ||
                                validMappings.length === 0
                            }
                        >
                            {form.processing
                                ? 'Saving…'
                                : isEdit
                                  ? 'Update profile'
                                  : 'Create profile'}
                        </Button>
                    ) : (
                        <Button
                            onClick={wizard.next}
                            disabled={
                                (wizard.index === 0 &&
                                    form.data.name.trim() === '') ||
                                (wizard.index === 2 &&
                                    validMappings.length === 0)
                            }
                        >
                            Continue
                        </Button>
                    )}
                </>
            }
        >
            {wizard.index === 0 && (
                <WizardStepPane>
                    <StepHead
                        icon={FileText}
                        title="Name the profile"
                        blurb="A profile describes the CSV layout one payroll provider expects."
                    />
                    <div className="grid gap-3.5 sm:grid-cols-2">
                        <Field
                            label="Profile name"
                            required
                            error={serverErrors.name}
                        >
                            <Input
                                value={form.data.name}
                                onChange={(e) =>
                                    form.setData('name', e.target.value)
                                }
                                placeholder="MYOB payroll export"
                            />
                        </Field>
                        <Field
                            label="Provider key"
                            hint="optional"
                            error={serverErrors.provider_key}
                        >
                            <Input
                                value={form.data.provider_key}
                                onChange={(e) =>
                                    form.setData('provider_key', e.target.value)
                                }
                                placeholder="myob, xero, custom"
                            />
                        </Field>
                        <Field
                            label="Description"
                            hint="optional"
                            span
                            error={serverErrors.description}
                        >
                            <Input
                                value={form.data.description}
                                onChange={(e) =>
                                    form.setData('description', e.target.value)
                                }
                                placeholder="What this layout is for…"
                            />
                        </Field>
                    </div>
                </WizardStepPane>
            )}

            {wizard.index === 1 && (
                <WizardStepPane>
                    <StepHead
                        icon={Settings2}
                        title="File format"
                        blurb="Match the separators and headers your provider's import expects."
                    />
                    <div className="grid gap-3.5 sm:grid-cols-2">
                        <Field label="Delimiter" error={serverErrors.delimiter}>
                            <Input
                                value={form.data.delimiter}
                                onChange={(e) =>
                                    form.setData('delimiter', e.target.value)
                                }
                            />
                        </Field>
                        <Field
                            label="Text enclosure"
                            error={serverErrors.enclosure}
                        >
                            <Input
                                value={form.data.enclosure}
                                onChange={(e) =>
                                    form.setData('enclosure', e.target.value)
                                }
                            />
                        </Field>
                    </div>
                    <div className="mt-4">
                        <Field
                            label="Line ending"
                            error={serverErrors.line_ending}
                        >
                            <Segmented
                                value={form.data.line_ending}
                                onChange={(v) => form.setData('line_ending', v)}
                                options={LINE_ENDING_OPTIONS}
                            />
                        </Field>
                    </div>
                    {/* eslint-disable-next-line no-restricted-syntax -- checkbox cluster panel inside the wizard body, not a content Card */}
                    <div className="mt-4 flex flex-wrap items-center gap-6 rounded-xl border border-border bg-card p-4">
                        <label className="flex cursor-pointer items-center gap-2 text-sm">
                            <Checkbox
                                checked={form.data.include_headers}
                                onCheckedChange={(checked) =>
                                    form.setData(
                                        'include_headers',
                                        Boolean(checked),
                                    )
                                }
                            />
                            <span>Include header row</span>
                        </label>
                        <label className="flex cursor-pointer items-center gap-2 text-sm">
                            <Checkbox
                                checked={form.data.is_default}
                                onCheckedChange={(checked) =>
                                    form.setData('is_default', Boolean(checked))
                                }
                            />
                            <span>Set as default profile</span>
                        </label>
                    </div>
                </WizardStepPane>
            )}

            {wizard.index === 2 && (
                <WizardStepPane>
                    <StepHead
                        icon={Columns3}
                        title="Export columns"
                        blurb="Each row becomes one CSV column — pick the payroll field it draws from, or a static value."
                    />
                    <div className="flex flex-col gap-2">
                        {form.data.mappings.map((mapping, index) => (
                            // eslint-disable-next-line no-restricted-syntax -- repeating editor row surface, not a content Card
                            <div
                                key={index}
                                className="grid items-start gap-2 rounded-xl border border-border bg-card p-3 sm:grid-cols-[1fr_1fr_auto]"
                            >
                                <Input
                                    value={mapping.header}
                                    onChange={(e) =>
                                        setMapping(index, {
                                            header: e.target.value,
                                        })
                                    }
                                    placeholder="Column header"
                                    aria-label={`Column ${index + 1} header`}
                                />
                                <div className="flex flex-col gap-2">
                                    <SelectInput
                                        value={mapping.source}
                                        onChange={(v) =>
                                            setMapping(index, { source: v })
                                        }
                                        placeholder="Field source"
                                        options={sourceOptions}
                                        ariaLabel={`Column ${index + 1} source`}
                                    />
                                    {mapping.source === 'static' ? (
                                        <Input
                                            value={mapping.value}
                                            onChange={(e) =>
                                                setMapping(index, {
                                                    value: e.target.value,
                                                })
                                            }
                                            placeholder="Static value"
                                            aria-label={`Column ${index + 1} static value`}
                                        />
                                    ) : null}
                                </div>
                                <Button
                                    type="button"
                                    variant="ghost"
                                    size="icon"
                                    aria-label={`Remove column ${index + 1}`}
                                    onClick={() =>
                                        form.setData(
                                            'mappings',
                                            form.data.mappings.filter(
                                                (_, i) => i !== index,
                                            ),
                                        )
                                    }
                                >
                                    <Trash2 className="h-4 w-4 text-muted-foreground" />
                                </Button>
                            </div>
                        ))}
                    </div>
                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        className="mt-3"
                        onClick={() =>
                            form.setData('mappings', [
                                ...form.data.mappings,
                                { header: '', source: '', value: '' },
                            ])
                        }
                    >
                        <Plus className="mr-1.5 h-3.5 w-3.5" />
                        Add column
                    </Button>
                    {validMappings.length === 0 ? (
                        <FieldErr>
                            At least one column with a header and source is
                            required.
                        </FieldErr>
                    ) : null}
                    <FieldErr>{serverErrors.mappings}</FieldErr>
                </WizardStepPane>
            )}

            {wizard.index === 3 && (
                <WizardStepPane>
                    <StepHead
                        icon={ClipboardCheck}
                        title="Confirm the profile"
                        blurb="Check the layout, then save."
                    />
                    <div className="grid gap-3 sm:grid-cols-2">
                        <ReviewCard
                            icon={FileText}
                            title="Basics"
                            onEdit={() => wizard.goTo(0)}
                        >
                            <ReviewRow label="Name" value={form.data.name} />
                            <ReviewRow
                                label="Provider"
                                value={form.data.provider_key || undefined}
                            />
                            <ReviewRow
                                label="Description"
                                value={form.data.description || undefined}
                            />
                        </ReviewCard>
                        <ReviewCard
                            icon={Settings2}
                            title="File format"
                            onEdit={() => wizard.goTo(1)}
                        >
                            <ReviewRow
                                label="Delimiter"
                                value={form.data.delimiter}
                            />
                            <ReviewRow
                                label="Enclosure"
                                value={form.data.enclosure}
                            />
                            <ReviewRow
                                label="Line ending"
                                value={
                                    LINE_ENDING_OPTIONS.find(
                                        (o) =>
                                            o.value === form.data.line_ending,
                                    )?.label
                                }
                            />
                            <ReviewRow
                                label="Headers"
                                value={
                                    form.data.include_headers
                                        ? 'Included'
                                        : 'Omitted'
                                }
                            />
                            <ReviewRow
                                label="Default"
                                value={form.data.is_default ? 'Yes' : 'No'}
                            />
                        </ReviewCard>
                        <ReviewCard
                            icon={Columns3}
                            title={`Columns (${validMappings.length})`}
                            onEdit={() => wizard.goTo(2)}
                            span
                        >
                            {validMappings.map((m, i) => (
                                <ReviewRow
                                    key={i}
                                    label={m.header}
                                    value={
                                        m.source === 'static'
                                            ? `Static: ${m.value || '—'}`
                                            : (sourceOptions.find(
                                                  (o) => o.value === m.source,
                                              )?.label ?? m.source)
                                    }
                                />
                            ))}
                        </ReviewCard>
                    </div>
                    <FieldErr>{serverErrors.mappings}</FieldErr>
                    {form.hasErrors ? (
                        <FieldErr>
                            Some fields need attention — use Edit to jump back
                            to the highlighted step.
                        </FieldErr>
                    ) : null}
                </WizardStepPane>
            )}
        </WizardShell>
    );
}
