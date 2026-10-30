import { actualEventInstant } from '@/components/clinical/actual-event-time-field';
import { DrillTimeField } from '@/components/health-safety/drills/drill-time-field';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';
import { Field, SelectInput, StepHead } from '@/components/wizard/primitives';
import {
    ReviewCard,
    ReviewRow,
    WizardShell,
    WizardStepPane,
    WizardSuccessPane,
    type WizardStep,
} from '@/components/wizard/shell';
import { useSafetyDraftState } from '@/hooks/use-safety-workspace-drafts';
import {
    formatDateTimeLong,
    toDatetimeLocal,
    workerTimeOffset,
} from '@/lib/datetime';
import {
    FIRST_AID_REGISTER,
    firstAidHref,
} from '@/lib/health-safety/first-aid-navigation';
import {
    injuryLabel,
    outcomeLabel,
    personTypeLabel,
} from '@/pages/health-safety/first-aid/options';
import { Link } from '@inertiajs/react';
import axios from 'axios';
import {
    Activity,
    ClipboardCheck,
    HeartPulse,
    Link2,
    MapPin,
    Stethoscope,
} from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { confirmedFirstAidCommand } from './command-form';
import type { FirstAidPickers, FirstAidRecord } from './types';

const STEPS: WizardStep[] = [
    {
        key: 'who',
        label: 'Who and where',
        blurb: 'Person, site and time',
        icon: MapPin,
    },
    {
        key: 'injury',
        label: 'Injury or illness',
        blurb: 'Record what happened',
        icon: Activity,
    },
    {
        key: 'treatment',
        label: 'Treatment',
        blurb: 'Care given and outcome',
        icon: Stethoscope,
    },
    {
        key: 'incident',
        label: 'Incident and notes',
        blurb: 'Related report and notes',
        icon: Link2,
    },
    {
        key: 'review',
        label: 'Review',
        blurb: 'Check and save',
        icon: ClipboardCheck,
    },
];
/** The five steps, for a page that mirrors the wizard's progress in its header. */
export const FIRST_AID_TREATMENT_STEPS: readonly WizardStep[] = STEPS;

export function firstAidTreatmentDraft(record?: FirstAidRecord) {
    const instant = record?.treatment_date ?? new Date().toISOString();
    return {
        site_id: String(record?.site_id ?? ''),
        treated_person_type: record?.treated_person_type ?? 'staff',
        treated_person_id: String(record?.treated_person_id ?? ''),
        client_id: String(record?.client_id ?? ''),
        treated_person_name: record?.treated_person_name ?? '',
        treatment_date: record
            ? toDatetimeLocal(record.treatment_date)
            : toDatetimeLocal(instant),
        time_offset: record?.treatment_date
            ? workerTimeOffset(record.treatment_date)
            : workerTimeOffset(instant),
        first_aider_id: String(record?.first_aider_id ?? ''),
        injury_illness_type: record?.injury_illness_type ?? '',
        injury_illness_description: record?.injury_illness_description ?? '',
        body_part: record?.body_part ?? '',
        treatment_given: record?.treatment_given ?? '',
        treatment_outcome: record?.treatment_outcome ?? '',
        ambulance_called: record?.ambulance_called ?? false,
        first_aider_notes: record?.first_aider_notes ?? '',
        incident_reported: record?.incident_reported ?? false,
        related_incident_id: String(record?.related_incident?.id ?? ''),
    };
}
type TreatmentDraft = ReturnType<typeof firstAidTreatmentDraft>;

/** An unchanged historical value is omitted, including the original treatment's seconds. */
export function firstAidTreatmentPayload(
    data: TreatmentDraft,
    initial: TreatmentDraft,
    record?: FirstAidRecord,
) {
    const result: Record<string, string | number | boolean | null> = {};
    for (const [key, value] of Object.entries(data)) {
        if (key === 'time_offset') continue;
        const field = key as keyof TreatmentDraft;
        const changed =
            value !== initial[field] ||
            (field === 'treatment_date' &&
                data.time_offset !== initial.time_offset);
        if (record && !changed) continue;
        result[key] =
            key === 'treatment_date'
                ? actualEventInstant(data.treatment_date, data.time_offset)
                : key.endsWith('_id')
                  ? value
                      ? Number(value)
                      : null
                  : value;
    }
    return result;
}

function validPickers(value: unknown): value is FirstAidPickers {
    return (
        !!value &&
        typeof value === 'object' &&
        [
            'sites',
            'clients',
            'staff',
            'first_aiders',
            'followup_assignees',
            'incidents',
            'person_types',
            'injury_types',
            'outcomes',
            'attachment_kinds',
        ].every((key) => Array.isArray((value as Record<string, unknown>)[key]))
    );
}

export function FirstAidTreatmentWizard({
    pickers,
    detail,
    returnTo,
    onClose,
    onSaved,
    onStepChange,
}: {
    pickers: FirstAidPickers;
    detail?: FirstAidRecord;
    returnTo: string;
    onClose: () => void;
    onSaved?: () => void;
    /** Reports the current step (0-based) and whether the record is saved — display only. */
    onStepChange?: (step: number, saved: boolean) => void;
}) {
    const [generation, setGeneration] = useState(0);
    const [freshPickers, setFreshPickers] = useState<FirstAidPickers | null>(
        null,
    );
    const [refreshing, setRefreshing] = useState(false);
    const [refreshError, setRefreshError] = useState<string | null>(null);
    const request = useRef<AbortController | null>(null);
    useEffect(() => () => request.current?.abort(), []);
    return (
        <TreatmentEntry
            key={generation}
            pickers={freshPickers ?? pickers}
            detail={detail}
            returnTo={returnTo}
            onClose={onClose}
            onSaved={onSaved}
            onStepChange={onStepChange}
            refreshing={refreshing}
            refreshError={refreshError}
            onAnother={async () => {
                if (refreshing) return;
                const controller = new AbortController();
                request.current = controller;
                setRefreshing(true);
                setRefreshError(null);
                try {
                    const response = await axios.get(
                        `${FIRST_AID_REGISTER}/options`,
                        {
                            signal: controller.signal,
                            headers: { Accept: 'application/json' },
                        },
                    );
                    if (controller.signal.aborted) return;
                    if (!validPickers(response.data))
                        throw new Error('Options unavailable');
                    setFreshPickers(response.data);
                    setGeneration((value) => value + 1);
                } catch {
                    if (!controller.signal.aborted)
                        setRefreshError(
                            'The next form could not be loaded. Your treatment record is saved. Try Record another again.',
                        );
                } finally {
                    if (!controller.signal.aborted) setRefreshing(false);
                }
            }}
        />
    );
}

function TreatmentEntry({
    pickers: initialPickers,
    detail,
    returnTo,
    onClose,
    onSaved,
    onStepChange,
    refreshing,
    refreshError,
    onAnother,
}: {
    pickers: FirstAidPickers;
    detail?: FirstAidRecord;
    returnTo: string;
    onClose: () => void;
    onSaved?: () => void;
    onStepChange?: (step: number, saved: boolean) => void;
    refreshing: boolean;
    refreshError: string | null;
    onAnother: () => void;
}) {
    const [reviewed] = useState(detail);
    const [initial] = useState(() => firstAidTreatmentDraft(reviewed));
    const [data, setData] = useState(initial);
    const [key] = useState(() => crypto.randomUUID());
    const [step, setStep] = useState(0);
    const [busy, setBusy] = useState(false);
    const [saved, setSaved] = useState<{
        id: number;
        href: string | null;
    } | null>(null);
    useEffect(() => onStepChange?.(step, saved !== null), [onStepChange, step, saved]);
    const [failure, setFailure] = useState<string | null>(null);
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [pickers, setPickers] = useState(initialPickers);
    const [loading, setLoading] = useState(false);
    const [optionsError, setOptionsError] = useState<string | null>(null);
    const [reload, setReload] = useState(0);
    const firstOptions = useRef(true);
    const body = useRef<HTMLDivElement>(null);
    const dirty = JSON.stringify(initial) !== JSON.stringify(data);
    const allowed = !detail || detail.can.edit;
    useSafetyDraftState({ dirty: !saved && dirty, busy });
    const clientId =
        data.treated_person_type === 'client' ? data.client_id : '';
    useEffect(() => {
        if (firstOptions.current) {
            firstOptions.current = false;
            return;
        }
        const controller = new AbortController();
        setLoading(true);
        setOptionsError(null);
        axios
            .get(`${FIRST_AID_REGISTER}/options`, {
                signal: controller.signal,
                headers: { Accept: 'application/json' },
                params: {
                    site_id: data.site_id || undefined,
                    client_id: clientId || undefined,
                    record_id: reviewed?.id,
                },
            })
            .then((response) => {
                if (controller.signal.aborted) return;
                if (!validPickers(response.data))
                    throw new Error('Options unavailable');
                setPickers(response.data);
            })
            .catch(() => {
                if (!controller.signal.aborted)
                    setOptionsError(
                        'We couldn’t load the latest options. Your entries are still here. Try again before saving.',
                    );
            })
            .finally(() => {
                if (!controller.signal.aborted) setLoading(false);
            });
        return () => controller.abort();
    }, [data.site_id, clientId, reload, reviewed?.id]);
    const set = <K extends keyof TreatmentDraft>(
        field: K,
        value: TreatmentDraft[K],
    ) => setData((current) => ({ ...current, [field]: value }));
    const move = (next: number) => {
        setStep(next);
        body.current?.scrollTo?.({ top: 0 });
    };
    const changed = (field: keyof TreatmentDraft) =>
        !reviewed || data[field] !== initial[field];
    const validate = (index: number) => {
        const next: Record<string, string> = {};
        const required = (field: keyof TreatmentDraft, message: string) => {
            if (changed(field) && !String(data[field]).trim())
                next[field] = message;
        };
        if (index === 0) {
            required('site_id', 'Choose the site where treatment was given.');
            required(
                'treated_person_name',
                'Enter the name of the person treated.',
            );
            if (
                data.treated_person_type === 'client' &&
                (!reviewed ||
                    changed('treated_person_type') ||
                    changed('client_id')) &&
                !data.client_id
            )
                next.client_id = 'Choose the client who was treated.';
            required(
                'first_aider_id',
                'Choose the first aider who gave treatment.',
            );
            if (
                !reviewed ||
                changed('treatment_date') ||
                changed('time_offset')
            ) {
                const instant = actualEventInstant(
                    data.treatment_date,
                    data.time_offset,
                );
                if (!instant || new Date(instant).getTime() > Date.now())
                    next.treatment_date =
                        'Enter when treatment was given (it can’t be in the future).';
            }
        }
        if (index === 1) {
            required('injury_illness_type', 'Choose the injury or illness.');
            required('injury_illness_description', 'Describe what happened.');
        }
        if (index === 2) {
            required('treatment_given', 'Record the treatment given.');
            required('treatment_outcome', 'Choose the recorded outcome.');
        }
        setErrors(next);
        return Object.keys(next).length === 0;
    };
    const nameOptions = (
        items: { id: number; name: string }[],
        id: string,
        name?: string | null,
    ) => {
        const options = items.map((item) => ({
            value: String(item.id),
            label: item.name,
        }));
        if (id && !options.some((item) => item.value === id))
            options.unshift({
                value: id,
                label: `${name || 'Person'} (no longer in the list)`,
            });
        return options;
    };
    const firstAiderOptions = nameOptions(
        pickers.first_aiders,
        data.first_aider_id,
        data.first_aider_id === initial.first_aider_id
            ? reviewed?.first_aider?.name
            : null,
    );
    const siteOptions = nameOptions(
        pickers.sites,
        data.site_id,
        reviewed?.site?.name,
    );
    const clientOptions = nameOptions(
        pickers.clients.filter(
            (item) => !data.site_id || item.site_id === Number(data.site_id),
        ),
        data.client_id,
        reviewed?.client?.name,
    );
    const staffOptions = nameOptions(
        pickers.staff,
        data.treated_person_id,
        reviewed?.treated_person?.name,
    );
    const selectedIncident = pickers.incidents.find(
        (item) => String(item.id) === data.related_incident_id,
    );
    const submit = async () => {
        if (busy || loading || optionsError || !allowed) return;
        for (let index = 0; index < 4; index++) {
            if (!validate(index)) {
                move(index);
                return;
            }
        }
        setFailure(null);
        setBusy(true);
        const payload = {
            ...firstAidTreatmentPayload(data, initial, reviewed),
            idempotency_key: key,
            return_to: returnTo,
            ...(reviewed
                ? { expected_version: reviewed.expected_version }
                : {}),
        };
        try {
            const response = await axios.request({
                method: reviewed ? 'put' : 'post',
                url: reviewed
                    ? `${FIRST_AID_REGISTER}/${reviewed.id}`
                    : FIRST_AID_REGISTER,
                data: payload,
                headers: { Accept: 'application/json' },
            });
            if (
                !confirmedFirstAidCommand(
                    response.data?.first_aid_confirmation,
                    {
                        id: reviewed?.id,
                        version: reviewed?.expected_version,
                        key,
                        command: reviewed ? 'edit' : 'create',
                    },
                )
            ) {
                setFailure(
                    'We can’t tell if this saved. Your entries are still here. Check the record before trying again.',
                );
                return;
            }
            const receipt = response.data.first_aid_confirmation;
            // The server independently authorizes this link. A receipt alone gives no read permission.
            const href = response.data.record_href;
            setSaved({
                id: receipt.id,
                href:
                    typeof href === 'string' &&
                    href.split('?')[0] === `${FIRST_AID_REGISTER}/${receipt.id}`
                        ? firstAidHref(receipt.id, { returnTo })
                        : null,
            });
        } catch (error) {
            const response = axios.isAxiosError(error) ? error.response : null;
            const messages = response?.data?.errors;
            if (messages && typeof messages === 'object')
                setErrors(
                    Object.fromEntries(
                        Object.entries(messages).map(([field, value]) => [
                            field,
                            Array.isArray(value)
                                ? String(value[0])
                                : String(value),
                        ]),
                    ),
                );
            setFailure(
                response?.status === 409
                    ? 'Someone else changed this record while you were editing. Your entries are still here. Check the latest record before trying again.'
                    : response?.status === 403
                      ? 'You no longer have access to save this. Ask your health and safety manager. Your entries are still here.'
                      : 'We can’t tell if this saved. Your entries are still here. You can try saving the same report again.',
            );
        } finally {
            setBusy(false);
        }
    };
    const finish = saved && reviewed && onSaved ? onSaved : onClose;
    return (
        <WizardShell
            open
            onClose={finish}
            title={reviewed ? 'Edit treatment record' : 'Record first aid'}
            description="Record what happened and the treatment given, then save."
            railIcon={HeartPulse}
            railTitle={
                reviewed ? 'Correct treatment details' : 'Record first aid'
            }
            railSub="Treatment record"
            steps={STEPS.map((item) => ({ ...item, disabled: busy }))}
            stepIndex={step}
            onStepClick={(next) => {
                if (!busy && (next < step || validate(step))) move(next);
            }}
            frontline
            bodyRef={body}
            pct={null}
            footerStart={
                <Button
                    className="frontline-tap"
                    variant="outline"
                    onClick={finish}
                    disabled={busy}
                >
                    Cancel
                </Button>
            }
            footerEnd={
                <div className="flex flex-wrap gap-2">
                    {step > 0 && (
                        <Button
                            className="frontline-tap"
                            variant="outline"
                            disabled={busy}
                            onClick={() => move(step - 1)}
                        >
                            Back
                        </Button>
                    )}
                    {step < 4 ? (
                        <Button
                            className="frontline-tap"
                            disabled={busy || loading || !!optionsError}
                            onClick={() => {
                                if (validate(step)) move(step + 1);
                            }}
                        >
                            Continue
                        </Button>
                    ) : (
                        <Button
                            className="frontline-tap"
                            disabled={
                                busy ||
                                loading ||
                                !!optionsError ||
                                !allowed ||
                                (!!reviewed && !dirty)
                            }
                            onClick={() => void submit()}
                        >
                            {busy
                                ? 'Saving…'
                                : reviewed
                                  ? 'Save correction'
                                  : 'Save treatment record'}
                        </Button>
                    )}
                </div>
            }
            success={
                saved ? (
                    <div>
                        <WizardSuccessPane
                            title={
                                reviewed
                                    ? 'Treatment record updated'
                                    : 'Treatment record saved'
                            }
                            blurb={
                                saved.href
                                    ? 'The treatment record is saved. Open it to see follow-ups, files and linked records.'
                                    : 'Your report is saved. Health and safety staff look after the full record, so you may not be able to open it.'
                            }
                            actions={
                                <div className="flex flex-wrap justify-center gap-3">
                                    {saved.href &&
                                        (reviewed && onSaved ? (
                                            <Button
                                                className="frontline-tap"
                                                onClick={onSaved}
                                            >
                                                Back to treatment record
                                            </Button>
                                        ) : (
                                            <Button
                                                className="frontline-tap"
                                                asChild
                                            >
                                                <Link href={saved.href}>
                                                    Open treatment record
                                                </Link>
                                            </Button>
                                        ))}
                                    {!reviewed && (
                                        <Button
                                            className="frontline-tap"
                                            variant="outline"
                                            disabled={refreshing}
                                            onClick={onAnother}
                                        >
                                            {refreshing
                                                ? 'Loading next form…'
                                                : 'Record another'}
                                        </Button>
                                    )}
                                    <Button
                                        className="frontline-tap"
                                        variant="outline"
                                        onClick={finish}
                                    >
                                        Done
                                    </Button>
                                </div>
                            }
                        />
                        {refreshError && (
                            <p
                                role="alert"
                                className="px-6 pb-5 text-sm text-status-critical"
                            >
                                {refreshError}
                            </p>
                        )}
                    </div>
                ) : undefined
            }
        >
            <WizardStepPane>
                <div className="space-y-5">
                    {failure && (
                        <p
                            role="alert"
                            className="text-sm text-status-critical"
                        >
                            {failure}
                        </p>
                    )}
                    {!!Object.keys(errors).length && (
                        <div
                            role="alert"
                            className="space-y-1 text-sm text-status-critical"
                        >
                            {Object.entries(errors).map(([field, message]) => (
                                <p key={field}>{message}</p>
                            ))}
                        </div>
                    )}
                    {loading && (
                        <p role="status" className="text-subtle">
                            Loading options…
                        </p>
                    )}
                    {optionsError && (
                        <div role="alert" className="space-y-2">
                            <p className="text-sm text-status-critical">
                                {optionsError}
                            </p>
                            <Button
                                className="frontline-tap"
                                variant="outline"
                                onClick={() => setReload((value) => value + 1)}
                            >
                                Try again
                            </Button>
                        </div>
                    )}
                    {!allowed && (
                        <p
                            role="alert"
                            className="text-sm text-status-critical"
                        >
                            Editing is no longer available. Your entries are
                            still here.
                        </p>
                    )}
                    {step === 0 && (
                        <>
                            <StepHead
                                title="Who received treatment?"
                                icon={MapPin}
                                blurb="Record the person, site, first aider and when treatment was given."
                            />
                            <Field label="Site" required error={errors.site_id}>
                                <SelectInput
                                    frontline
                                    value={data.site_id}
                                    placeholder="Choose a site"
                                    options={siteOptions}
                                    onChange={(value) =>
                                        setData((current) => ({
                                            ...current,
                                            site_id: value,
                                            client_id: '',
                                            treated_person_id: '',
                                            first_aider_id: '',
                                            related_incident_id: '',
                                            ...(current.treated_person_type ===
                                                'client' ||
                                            current.treated_person_type ===
                                                'staff'
                                                ? { treated_person_name: '' }
                                                : {}),
                                        }))
                                    }
                                />
                            </Field>
                            <Field label="Person type" required>
                                <SelectInput
                                    frontline
                                    value={data.treated_person_type}
                                    placeholder="Choose a person type"
                                    options={pickers.person_types}
                                    onChange={(value) =>
                                        setData((current) => ({
                                            ...current,
                                            treated_person_type: value,
                                            treated_person_id: '',
                                            client_id: '',
                                            treated_person_name: '',
                                            related_incident_id: '',
                                        }))
                                    }
                                />
                            </Field>
                            {data.treated_person_type === 'client' ? (
                                <Field
                                    label="Client treated"
                                    required
                                    error={errors.client_id}
                                >
                                    <SelectInput
                                        frontline
                                        value={data.client_id}
                                        placeholder="Choose a client"
                                        options={clientOptions}
                                        onChange={(value) =>
                                            setData((current) => ({
                                                ...current,
                                                client_id: value,
                                                treated_person_name:
                                                    pickers.clients.find(
                                                        (item) =>
                                                            item.id ===
                                                            Number(value),
                                                    )?.name ?? '',
                                                related_incident_id: '',
                                            }))
                                        }
                                    />
                                </Field>
                            ) : (
                                <>
                                    {data.treated_person_type === 'staff' && (
                                        <Field label="Staff member">
                                            <SelectInput
                                                frontline
                                                placeholder="Choose a staff member or type a name"
                                                value={
                                                    data.treated_person_id ||
                                                    'manual'
                                                }
                                                options={[
                                                    {
                                                        value: 'manual',
                                                        label: 'Enter a name',
                                                    },
                                                    ...staffOptions,
                                                ]}
                                                onChange={(value) =>
                                                    setData((current) => ({
                                                        ...current,
                                                        treated_person_id:
                                                            value === 'manual'
                                                                ? ''
                                                                : value,
                                                        treated_person_name:
                                                            value === 'manual'
                                                                ? ''
                                                                : (pickers.staff.find(
                                                                      (item) =>
                                                                          item.id ===
                                                                          Number(
                                                                              value,
                                                                          ),
                                                                  )?.name ??
                                                                  current.treated_person_name),
                                                    }))
                                                }
                                            />
                                        </Field>
                                    )}
                                    <Field
                                        label="Person's name"
                                        required
                                        error={errors.treated_person_name}
                                    >
                                        <Input
                                            className="frontline-tap"
                                            maxLength={255}
                                            value={data.treated_person_name}
                                            onChange={(event) =>
                                                set(
                                                    'treated_person_name',
                                                    event.target.value,
                                                )
                                            }
                                        />
                                    </Field>
                                </>
                            )}
                            <Field
                                label="First aider"
                                required
                                error={errors.first_aider_id}
                                hint="This records who gave treatment."
                            >
                                <SelectInput
                                    frontline
                                    value={data.first_aider_id}
                                    placeholder="Choose the first aider"
                                    options={firstAiderOptions}
                                    onChange={(value) =>
                                        set('first_aider_id', value)
                                    }
                                />
                            </Field>
                            <DrillTimeField
                                label="Treatment time"
                                value={data.treatment_date}
                                offset={data.time_offset}
                                onChange={(value, offset) =>
                                    setData((current) => ({
                                        ...current,
                                        treatment_date: value,
                                        time_offset: offset,
                                    }))
                                }
                            />
                        </>
                    )}
                    {step === 1 && (
                        <>
                            <StepHead
                                title="Injury or illness"
                                icon={Activity}
                                blurb="Describe what you know."
                            />
                            <Field
                                label="Injury or illness"
                                required
                                error={errors.injury_illness_type}
                            >
                                <SelectInput
                                    frontline
                                    value={data.injury_illness_type}
                                    placeholder="Choose an injury or illness"
                                    options={pickers.injury_types}
                                    onChange={(value) =>
                                        set('injury_illness_type', value)
                                    }
                                />
                            </Field>
                            <Field label="Body part">
                                <Input
                                    className="frontline-tap"
                                    maxLength={255}
                                    value={data.body_part}
                                    onChange={(event) =>
                                        set('body_part', event.target.value)
                                    }
                                />
                            </Field>
                            <Field
                                label="What happened"
                                required
                                error={errors.injury_illness_description}
                            >
                                <Textarea
                                    rows={5}
                                    value={data.injury_illness_description}
                                    onChange={(event) =>
                                        set(
                                            'injury_illness_description',
                                            event.target.value,
                                        )
                                    }
                                />
                            </Field>
                        </>
                    )}
                    {step === 2 && (
                        <>
                            <StepHead
                                title="Treatment and recorded outcome"
                                icon={Stethoscope}
                                blurb="Record the care given and what happened next."
                            />
                            <Field
                                label="Treatment given"
                                required
                                error={errors.treatment_given}
                            >
                                <Textarea
                                    rows={5}
                                    value={data.treatment_given}
                                    onChange={(event) =>
                                        set(
                                            'treatment_given',
                                            event.target.value,
                                        )
                                    }
                                />
                            </Field>
                            <Field
                                label="Recorded outcome"
                                required
                                error={errors.treatment_outcome}
                            >
                                <SelectInput
                                    frontline
                                    value={data.treatment_outcome}
                                    placeholder="Choose an outcome"
                                    options={pickers.outcomes}
                                    onChange={(value) =>
                                        set('treatment_outcome', value)
                                    }
                                />
                            </Field>
                            <Field label="Ambulance called">
                                <Checkbox
                                    className="size-11"
                                    checked={data.ambulance_called}
                                    onCheckedChange={(value) =>
                                        set('ambulance_called', value === true)
                                    }
                                />
                            </Field>
                            <p className="text-subtle">
                                This records what you were told happened next.
                                It doesn’t confirm a hospital stay or that the
                                person is medically cleared.
                            </p>
                        </>
                    )}
                    {step === 3 && (
                        <>
                            <StepHead
                                title="Incident and first aider notes"
                                icon={Link2}
                                blurb="Link an existing client incident if there is one, or record that a report was made elsewhere."
                            />
                            <Field label="An incident report has been made">
                                <Checkbox
                                    className="size-11"
                                    checked={data.incident_reported}
                                    onCheckedChange={(value) =>
                                        set('incident_reported', value === true)
                                    }
                                />
                            </Field>
                            {data.treated_person_type === 'client' && (
                                <Field
                                    label="Existing incident"
                                    hint="A linked incident keeps its own owner and follow-up work."
                                >
                                    <SelectInput
                                        frontline
                                        value={
                                            data.related_incident_id || 'none'
                                        }
                                        placeholder="Choose an existing incident"
                                        options={[
                                            {
                                                value: 'none',
                                                label:
                                                    reviewed?.incident_link_state ===
                                                        'unavailable' &&
                                                    !data.related_incident_id
                                                        ? 'Keep the linked incident'
                                                        : 'No incident selected',
                                            },
                                            ...pickers.incidents.map(
                                                (item) => ({
                                                    value: String(item.id),
                                                    label: `${item.reference ?? `Incident ${item.id}`}${item.title ? ` · ${item.title}` : ''}`,
                                                }),
                                            ),
                                            ...(data.related_incident_id &&
                                            !selectedIncident
                                                ? [
                                                      {
                                                          value: data.related_incident_id,
                                                          label: `${reviewed?.related_incident?.reference ?? 'Linked incident'} (already linked)`,
                                                      },
                                                  ]
                                                : []),
                                        ]}
                                        onChange={(value) =>
                                            setData((current) => ({
                                                ...current,
                                                related_incident_id:
                                                    value === 'none'
                                                        ? ''
                                                        : value,
                                                incident_reported:
                                                    value === 'none'
                                                        ? current.incident_reported
                                                        : true,
                                            }))
                                        }
                                    />
                                </Field>
                            )}
                            {!reviewed && (
                                <p className="text-subtle">
                                    After saving, people with the appropriate
                                    access can add follow-ups and evidence or
                                    create a linked client incident from the
                                    treatment page.
                                </p>
                            )}
                            <Field label="First aider notes">
                                <Textarea
                                    rows={5}
                                    value={data.first_aider_notes}
                                    onChange={(event) =>
                                        set(
                                            'first_aider_notes',
                                            event.target.value,
                                        )
                                    }
                                />
                            </Field>
                        </>
                    )}
                    {step === 4 && (
                        <>
                            <StepHead
                                title="Review the treatment record"
                                icon={ClipboardCheck}
                                blurb="Check the facts before saving."
                            />
                            <div className="grid gap-4 sm:grid-cols-2">
                                <ReviewCard
                                    icon={MapPin}
                                    title="Person and site"
                                    onEdit={() => move(0)}
                                >
                                    <ReviewRow
                                        label="Person"
                                        value={data.treated_person_name}
                                    />
                                    <ReviewRow
                                        label="Person type"
                                        value={personTypeLabel(
                                            data.treated_person_type,
                                        )}
                                    />
                                    <ReviewRow
                                        label="Site"
                                        value={
                                            siteOptions.find(
                                                (item) =>
                                                    item.value === data.site_id,
                                            )?.label
                                        }
                                    />
                                    <ReviewRow
                                        label="First aider"
                                        value={
                                            firstAiderOptions.find(
                                                (item) =>
                                                    item.value ===
                                                    data.first_aider_id,
                                            )?.label
                                        }
                                    />
                                    <ReviewRow
                                        label="Treatment time"
                                        value={formatDateTimeLong(
                                            reviewed &&
                                                data.treatment_date ===
                                                    initial.treatment_date &&
                                                data.time_offset ===
                                                    initial.time_offset
                                                ? reviewed.treatment_date
                                                : actualEventInstant(
                                                      data.treatment_date,
                                                      data.time_offset,
                                                  ),
                                            'Not recorded',
                                        )}
                                    />
                                </ReviewCard>
                                <ReviewCard
                                    icon={Activity}
                                    title="Injury or illness"
                                    onEdit={() => move(1)}
                                >
                                    <ReviewRow
                                        label="Injury or illness"
                                        value={injuryLabel(
                                            data.injury_illness_type,
                                        )}
                                    />
                                    <ReviewRow
                                        label="Body part"
                                        value={data.body_part}
                                    />
                                    <ReviewRow
                                        label="What happened"
                                        value={data.injury_illness_description}
                                    />
                                </ReviewCard>
                                <ReviewCard
                                    icon={Stethoscope}
                                    title="Treatment"
                                    onEdit={() => move(2)}
                                >
                                    <ReviewRow
                                        label="Care given"
                                        value={data.treatment_given}
                                    />
                                    <ReviewRow
                                        label="Recorded outcome"
                                        value={outcomeLabel(
                                            data.treatment_outcome,
                                        )}
                                    />
                                    <ReviewRow
                                        label="Ambulance called"
                                        value={
                                            data.ambulance_called ? 'Yes' : 'No'
                                        }
                                    />
                                </ReviewCard>
                                <ReviewCard
                                    icon={Link2}
                                    title="Incident and notes"
                                    onEdit={() => move(3)}
                                >
                                    <ReviewRow
                                        label="Incident reported"
                                        value={
                                            data.incident_reported
                                                ? 'Yes'
                                                : 'No'
                                        }
                                    />
                                    <ReviewRow
                                        label="Linked incident"
                                        value={
                                            selectedIncident?.reference ??
                                            reviewed?.related_incident
                                                ?.reference ??
                                            (reviewed?.incident_link_state ===
                                            'unavailable'
                                                ? 'Linked incident kept'
                                                : data.related_incident_id
                                                  ? `Incident · Ref ${data.related_incident_id}`
                                                  : 'No incident selected')
                                        }
                                    />
                                    <ReviewRow
                                        label="Notes"
                                        value={data.first_aider_notes}
                                    />
                                </ReviewCard>
                            </div>
                        </>
                    )}
                </div>
            </WizardStepPane>
        </WizardShell>
    );
}
