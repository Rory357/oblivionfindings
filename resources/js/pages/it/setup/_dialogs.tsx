import { ConfirmDialog } from '@/components/confirm-dialog';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';
import { StepHead, TilePicker } from '@/components/wizard/primitives';
import {
    ReviewCard,
    ReviewRow,
    WizardShell,
    WizardStepPane,
    WizardSuccessPane,
} from '@/components/wizard/shell';
import { router, useForm, usePage } from '@inertiajs/react';
import { Boxes, ClipboardCheck, FileText, UsersRound } from 'lucide-react';
import { useEffect, useRef, useState, type FormEvent } from 'react';
import { SetupCreateRecovery } from './_create-recovery';
import { SetupMemoryRecovery } from './_memory-recovery';
import type { Agent, Service, Team } from './_types';
import {
    useSetupCreateCommand,
    type SetupCommandOutcome,
    type SetupCreated,
} from './use-setup-create-command';
import { useSetupMemory, type SetupFields } from './use-setup-memory';

type RecordKind = 'team' | 'service';
type SetupRecord = Team | Service;
type Values = {
    configuration_version: string;
    name: string;
    key: string;
    description: string;
    person: string;
    is_active: boolean;
    members: Array<{ user_id: number; role: string }>;
    status: string;
    criticality: string;
};
const label = (value: string) =>
    value.replace(/_/g, ' ').replace(/^\w/, (c) => c.toUpperCase());
const valuesFor = (record?: SetupRecord): Values => ({
    configuration_version: record?.configuration_version ?? '',
    name: record?.name ?? '',
    key: record && 'key' in record ? record.key : '',
    description: record?.description ?? '',
    person: String(
        record && 'members' in record
            ? (record.manager?.id ?? '')
            : (record?.owner?.id ?? ''),
    ),
    is_active: record?.is_active ?? true,
    members:
        record && 'members' in record
            ? record.members.map((member) => ({
                  user_id: member.id,
                  role: member.role,
              }))
            : [],
    status: record && 'status' in record ? record.status : 'operational',
    criticality:
        record && 'criticality' in record ? record.criticality : 'medium',
});
const steps = [
    {
        key: 'identity',
        label: 'Identity',
        blurb: 'Name and purpose',
        icon: FileText,
    },
    {
        key: 'accountability',
        label: 'Accountability',
        blurb: 'People and operating status',
        icon: UsersRound,
    },
    {
        key: 'review',
        label: 'Review',
        blurb: 'Check before saving',
        icon: ClipboardCheck,
    },
];
function validRecord(value: unknown, kind: RecordKind): value is SetupRecord {
    if (!value || typeof value !== 'object') return false;
    const record = value as SetupRecord;
    const person = (value: unknown) =>
        value === null ||
        (!!value &&
            typeof value === 'object' &&
            typeof (value as Agent).id === 'number' &&
            typeof (value as Agent).name === 'string');
    return (
        Number.isSafeInteger(record.id) &&
        typeof record.name === 'string' &&
        (record.description === null ||
            typeof record.description === 'string') &&
        typeof record.is_active === 'boolean' &&
        typeof record.configuration_version === 'string' &&
        /^[a-f0-9]{64}$/.test(record.configuration_version) &&
        (kind === 'team'
            ? 'members' in record &&
              person(record.manager) &&
              Array.isArray(record.members) &&
              record.members.every(
                  (member) =>
                      person(member) &&
                      member !== null &&
                      ['member', 'lead', 'manager'].includes(member.role),
              )
            : 'key' in record &&
              typeof record.key === 'string' &&
              person(record.owner) &&
              [
                  'operational',
                  'degraded',
                  'outage',
                  'maintenance',
                  'retired',
              ].includes(record.status) &&
              ['low', 'medium', 'high', 'critical'].includes(
                  record.criticality,
              ))
    );
}
export function SetupRecordWizard({
    kind,
    record,
    agents,
    onClose,
}: {
    kind: RecordKind;
    record?: SetupRecord;
    agents: Agent[];
    onClose: () => void;
}) {
    const page = usePage();
    const actorId = (page.props.auth as { user?: { id: number } } | undefined)
        ?.user?.id;
    const originalActor = useRef(actorId);
    const actorChanged = originalActor.current !== actorId;
    const currentActor = useRef(actorId);
    currentActor.current = actorId;
    const initial = useRef(valuesFor(record));
    const form = useForm<Values>(initial.current);
    const [step, setStep] = useState(0);
    const [discard, setDiscard] = useState(false);
    const [saved, setSaved] = useState(false);
    const [message, setMessage] = useState<string | null>(null);
    const [unknown, setUnknown] = useState(false);
    const [reviewed, setReviewed] = useState<SetupRecord | null>(null);
    const [reviewing, setReviewing] = useState(false);
    const [settledToken, setSettledToken] = useState(0);
    const [accessBlocked, setAccessBlocked] = useState<
        'expired' | 'denied' | null
    >(null);
    const reviewEpoch = useRef(0);
    const controller = useRef<AbortController | null>(null);
    const formElement = useRef<HTMLFormElement>(null);
    const base = useRef(initial.current);
    const submitted = useRef<Values | null>(null);
    useEffect(
        () => () => {
            reviewEpoch.current++;
            controller.current?.abort();
        },
        [],
    );
    const plural = kind === 'team' ? 'teams' : 'services';
    const createCommand = useSetupCreateCommand({
        active: !record && !saved && !actorChanged,
        actorId,
        resource: plural,
    });
    const processing = form.processing || createCommand.busy;
    const [createdResult, setCreatedResult] = useState<SetupCreated | null>(
        null,
    );
    const title = `${record ? 'Edit' : 'New'} ${kind}`;
    const dirty = JSON.stringify(form.data) !== JSON.stringify(initial.current);
    const conflict =
        !!form.errors.configuration_version ||
        unknown ||
        (!record && !createCommand.canEdit);
    const focusField = (key: string) => {
        setStep(['name', 'key', 'description'].includes(key) ? 0 : 1);
        requestAnimationFrame(() =>
            formElement.current
                ?.querySelector<HTMLElement>(
                    `[name="${key === 'manager_user_id' || key === 'owner_user_id' ? 'person' : key}"], [data-field="${key}"] button`,
                )
                ?.focus(),
        );
    };
    const validate = () => {
        form.clearErrors('name', 'key', 'description');
        if (!form.data.name.trim() || form.data.name.length > 255) {
            form.setError('name', 'Enter a name of up to 255 characters.');
            focusField('name');
            return false;
        }
        if (
            kind === 'service' &&
            !/^[a-z0-9]+(?:-[a-z0-9]+)*$/.test(form.data.key)
        ) {
            form.setError(
                'key',
                'Use a stable key with lowercase letters, numbers and single hyphens.',
            );
            focusField('key');
            return false;
        }
        if (form.data.description.length > 5000) {
            form.setError('description', 'Use up to 5,000 characters.');
            focusField('description');
            return false;
        }
        return true;
    };
    const advance = (next: number) => {
        if (processing) return;
        if (next > step && !validate()) return;
        setMessage(null);
        setStep(next);
    };
    const close = () => {
        if (processing) return;
        if (!saved && (dirty || unknown || createCommand.pending))
            setDiscard(true);
        else onClose();
    };
    const payloadFor = (data: Values) => ({
        name: data.name,
        description: data.description || null,
        is_active: data.is_active,
        ...(kind === 'team'
            ? {
                  manager_user_id: data.person ? Number(data.person) : null,
                  members: data.members,
              }
            : {
                  key: data.key,
                  owner_user_id: data.person ? Number(data.person) : null,
                  status: data.status,
                  criticality: data.criticality,
              }),
    });
    const fromFields = (
        fields: SetupFields,
        version: string | null,
    ): Values => ({
        ...valuesFor(),
        configuration_version: version ?? '',
        name: String(fields.name ?? ''),
        key: String(fields.key ?? ''),
        description: String(fields.description ?? ''),
        person: String(
            (kind === 'team' ? fields.manager_user_id : fields.owner_user_id) ??
                '',
        ),
        is_active: fields.is_active !== false,
        members: Array.isArray(fields.members)
            ? (fields.members as Values['members'])
            : [],
        status: String(fields.status ?? 'operational'),
        criticality: String(fields.criticality ?? 'medium'),
    });
    const memory = useSetupMemory({
        active: !saved && !actorChanged && accessBlocked !== 'denied',
        actorId,
        resource: plural,
        recordId: record?.id ?? null,
        dirty,
        settledToken: settledToken + createCommand.settledToken,
        acceptsWork: (work) =>
            !!record || createCommand.accepts(work.command_uuid),
        canRecover: !processing,
        commandPendingOnly:
            !record && createCommand.pending && !submitted.current,
        work: {
            configuration_version: form.data.configuration_version || null,
            base_fields: payloadFor(base.current),
            fields: payloadFor(form.data),
            step_index: step,
            outcomeUnknown:
                unknown || processing || (!record && createCommand.pending),
            submitted: submitted.current ? payloadFor(submitted.current) : null,
            command_uuid: record ? null : createCommand.requestUuid,
        },
    });
    const handleCreateOutcome = (outcome: SetupCommandOutcome | null) => {
        if (outcome) {
            memory.acknowledgeCommand(outcome.request_uuid);
            if ('cancelled' in outcome) {
                setUnknown(false);
                submitted.current = null;
                return;
            }
            memory.clearOwned();
            setCreatedResult(outcome);
            setSaved(true);
            setUnknown(false);
            return;
        }
        const current = createCommand.getSnapshot();
        if (current.state === 'expired') {
            setReviewed(null);
            setAccessBlocked('expired');
        }
        if (current.state === 'denied') {
            memory.clearOwned();
            setReviewed(null);
            setAccessBlocked('denied');
            const empty = valuesFor();
            form.setData(empty);
            initial.current = empty;
            base.current = empty;
            submitted.current = null;
        }
        if (current.state === 'validation') {
            form.clearErrors();
            for (const [key, error] of Object.entries(current.errors)) {
                const field =
                    key === 'manager_user_id' || key === 'owner_user_id'
                        ? 'person'
                        : key;
                if (field in form.data)
                    form.setError(field as keyof Values, error);
            }
            const first = Object.keys(current.errors)
                .map((key) =>
                    key === 'manager_user_id' || key === 'owner_user_id'
                        ? 'person'
                        : key,
                )
                .find((key) => key in form.data);
            if (first) focusField(first);
        }
    };
    const submit = (event: FormEvent) => {
        event.preventDefault();
        if (processing || conflict || actorChanged || accessBlocked) return;
        if (step < 2) {
            advance(step + 1);
            return;
        }
        if (!validate()) return;
        if (record && !form.data.configuration_version) {
            form.setError(
                'configuration_version',
                'Review current setup before saving this older page.',
            );
            return;
        }
        setMessage(null);
        submitted.current = structuredClone(form.data);
        if (!record) {
            form.clearErrors();
            void createCommand
                .submit(payloadFor(form.data))
                .then(handleCreateOutcome);
            return;
        }
        form.transform((data) => {
            const payload = payloadFor(data);
            if (!record)
                return { ...payload, actor_user_id: originalActor.current };
            const original = payloadFor(base.current);
            return {
                ...Object.fromEntries(
                    Object.entries(payload).filter(
                        ([key, value]) =>
                            JSON.stringify(value) !==
                            JSON.stringify(
                                original[key as keyof typeof original],
                            ),
                    ),
                ),
                configuration_version: data.configuration_version,
                actor_user_id: originalActor.current,
            };
        });
        let answered = false;
        const options = {
            preserveScroll: true,
            onSuccess: (page: { props: Record<string, unknown> }) => {
                answered = true;
                const flash = page.props.flash as
                    | { success?: unknown; error?: unknown }
                    | undefined;
                if (typeof flash?.error === 'string' && flash.error) {
                    setUnknown(false);
                    setSettledToken((value) => value + 1);
                    setMessage(flash.error);
                    return;
                }
                const rows = page.props[plural];
                const result = Array.isArray(rows)
                    ? rows.find(
                          (row: unknown) =>
                              validRecord(row, kind) &&
                              (record
                                  ? row.id === record.id
                                  : kind === 'service'
                                    ? 'key' in row &&
                                      row.key === submitted.current?.key
                                    : row.name === submitted.current?.name),
                      )
                    : undefined;
                if (
                    !flash?.error &&
                    typeof flash?.success === 'string' &&
                    flash.success &&
                    result
                ) {
                    memory.clearOwned();
                    setSettledToken((value) => value + 1);
                    setSaved(true);
                    setUnknown(false);
                } else {
                    setUnknown(true);
                    setMessage(
                        'The save was not confirmed. Your draft is retained. Review current setup before trying again.',
                    );
                }
            },
            onError: (errors: Record<string, string>) => {
                answered = true;
                setSettledToken((value) => value + 1);
                setMessage(
                    'The setup was not saved. Review the highlighted details.',
                );
                const key = Object.keys(errors).find(
                    (key) => key !== 'configuration_version',
                );
                if (key) focusField(key.split('.')[0]);
            },
            onCancel: () => {
                answered = true;
                setUnknown(true);
                setMessage(
                    'The wait was cancelled. Saving may still have completed. Review current setup before trying again.',
                );
            },
            onFinish: () => {
                if (!answered) {
                    setUnknown(true);
                    setMessage(
                        'The response could not be confirmed. Your draft is retained. Review current setup after signing in again.',
                    );
                }
            },
        };
        if (record) form.patch(`/it/setup/${plural}/${record.id}`, options);
        else form.post(`/it/setup/${plural}`, options);
    };
    const review = async () => {
        controller.current?.abort();
        const operation = ++reviewEpoch.current;
        const abort = new AbortController();
        controller.current = abort;
        setReviewing(true);
        setReviewed(null);
        setMessage(null);
        try {
            const response = await fetch(
                `/it/setup?review_resource=${plural}&actor_user_id=${originalActor.current}`,
                {
                    credentials: 'same-origin',
                    cache: 'no-store',
                    signal: abort.signal,
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest',
                        Accept: 'application/json',
                    },
                },
            );
            if (
                abort.signal.aborted ||
                operation !== reviewEpoch.current ||
                currentActor.current !== originalActor.current
            )
                return;
            if ([401, 419].includes(response.status))
                setAccessBlocked('expired');
            if ([403, 404].includes(response.status)) {
                setAccessBlocked('denied');
                memory.clearOwned();
                const empty = valuesFor();
                form.setData(empty);
                base.current = empty;
                initial.current = empty;
                submitted.current = null;
            }
            if (
                !response.ok ||
                !response.headers
                    .get('content-type')
                    ?.includes('application/json')
            )
                throw new Error(
                    'Current setup is unavailable. Sign in again if your session expired, then retry this review.',
                );
            const page = (await response.json()) as {
                resource?: string;
                viewer_user_id?: number;
                records?: unknown[];
            };
            if (
                abort.signal.aborted ||
                operation !== reviewEpoch.current ||
                currentActor.current !== originalActor.current
            )
                return;
            if (
                page.resource !== plural ||
                page.viewer_user_id !== originalActor.current ||
                !Array.isArray(page.records)
            )
                throw new Error(
                    'Current setup could not be verified. Your draft is retained.',
                );
            const rows = page.records;
            const match = rows.find(
                (row) =>
                    validRecord(row, kind) &&
                    (record
                        ? row.id === record.id
                        : kind === 'service'
                          ? 'key' in row && row.key === submitted.current?.key
                          : row.name === submitted.current?.name),
            );
            if (!match || !validRecord(match, kind))
                throw new Error(
                    record
                        ? 'This record is no longer available. Your draft is retained; no changes have been applied.'
                        : 'No matching saved record is visible. Your draft is retained. Confirm the outcome with an administrator before another create attempt.',
                );
            setReviewed(match);
        } catch (error) {
            if (!abort.signal.aborted && operation === reviewEpoch.current)
                setMessage(
                    error instanceof Error
                        ? error.message
                        : 'Review failed. Try again.',
                );
        } finally {
            if (!abort.signal.aborted && operation === reviewEpoch.current)
                setReviewing(false);
        }
    };
    const personName = (value: string) =>
        agents.find((person) => String(person.id) === value)?.name ??
        (value ? 'Unavailable person — change or remove' : 'Unassigned');
    const allMembers = [
        ...agents,
        ...(record && 'members' in record
            ? record.members.filter(
                  (member) => !agents.some((agent) => agent.id === member.id),
              )
            : []),
    ];
    const reviewRows = (data: Values) => (
        <>
            <ReviewRow label="Name" value={data.name} />
            {kind === 'service' && (
                <ReviewRow label="Stable key" value={data.key} />
            )}
            <ReviewRow label="Description" value={data.description || 'None'} />
            <ReviewRow
                label={kind === 'team' ? 'Manager' : 'Service owner'}
                value={personName(data.person)}
            />
            <ReviewRow
                label="Availability"
                value={data.is_active ? 'Active' : 'Inactive'}
            />
            {kind === 'team' ? (
                <ReviewRow
                    label="Members"
                    value={
                        data.members.length
                            ? data.members
                                  .map(
                                      (member) =>
                                          `${allMembers.find((person) => person.id === member.user_id)?.name ?? 'Unavailable person'} (${label(member.role)})`,
                                  )
                                  .join(', ')
                            : 'No members'
                    }
                />
            ) : (
                <>
                    <ReviewRow
                        label="Health status"
                        value={label(data.status)}
                    />
                    <ReviewRow
                        label="Criticality"
                        value={label(data.criticality)}
                    />
                </>
            )}
        </>
    );
    return (
        <>
            <WizardShell
                open
                onClose={close}
                title={title}
                description="Review identity and accountable ownership before saving."
                railIcon={kind === 'team' ? UsersRound : Boxes}
                railTitle={title}
                railSub="IT setup"
                steps={steps}
                stepIndex={step}
                onStepClick={advance}
                pct={Math.round(
                    ([
                        !!form.data.name.trim(),
                        kind === 'team' || !!form.data.key,
                        !!form.data.person,
                        step === 2,
                    ].filter(Boolean).length /
                        4) *
                        100,
                )}
                success={
                    saved ? (
                        <WizardSuccessPane
                            title={`${label(kind)} saved`}
                            blurb={
                                createdResult
                                    ? `${label(kind)} #${createdResult.id} is saved in IT setup.`
                                    : `${form.data.name} is available in IT setup.`
                            }
                            actions={
                                <Button
                                    onClick={() => {
                                        onClose();
                                        if (createdResult)
                                            router.reload({
                                                only: [plural],
                                                preserveScroll: true,
                                            });
                                    }}
                                >
                                    Done
                                </Button>
                            }
                        />
                    ) : undefined
                }
                footerStart={
                    <Button
                        variant="outline"
                        onClick={close}
                        disabled={processing}
                    >
                        Cancel
                    </Button>
                }
                footerEnd={
                    <>
                        {record && processing ? (
                            <Button
                                type="button"
                                variant="outline"
                                onClick={() =>
                                    record
                                        ? form.cancel()
                                        : createCommand.cancelWait()
                                }
                            >
                                Cancel wait
                            </Button>
                        ) : null}
                        {step > 0 && (
                            <Button
                                type="button"
                                variant="outline"
                                disabled={processing}
                                onClick={() => advance(step - 1)}
                            >
                                Back
                            </Button>
                        )}
                        {step < 2 ? (
                            <Button
                                key="setup-continue"
                                type="button"
                                disabled={processing}
                                onClick={(event) => {
                                    event.preventDefault();
                                    advance(step + 1);
                                }}
                            >
                                Continue
                            </Button>
                        ) : (
                            <Button
                                key="setup-save"
                                type="submit"
                                form="setup-record-form"
                                disabled={processing || conflict}
                            >
                                {processing ? 'Saving…' : `Save ${kind}`}
                            </Button>
                        )}
                    </>
                }
            >
                {actorChanged || accessBlocked ? (
                    <div role="alert">
                        {actorChanged || accessBlocked === 'denied'
                            ? 'This form is no longer available to your current account. Reopen Setup to continue.'
                            : 'Your session expired. Sign in again, then check access before showing the entered values.'}
                        {accessBlocked === 'expired' && !actorChanged && (
                            <Button
                                type="button"
                                variant="outline"
                                disabled={memory.busy}
                                onClick={async () => {
                                    const proof =
                                        await memory.checkCurrentAccess();
                                    if (proof === 'denied') {
                                        setAccessBlocked('denied');
                                        const empty = valuesFor();
                                        form.setData(empty);
                                        base.current = empty;
                                        initial.current = empty;
                                        submitted.current = null;
                                    } else if (proof) {
                                        setAccessBlocked(null);
                                        if (!proof.capabilities.submit)
                                            form.setError(
                                                'configuration_version',
                                                'Review current setup before saving.',
                                            );
                                    }
                                }}
                            >
                                Check access and resume
                            </Button>
                        )}
                        {memory.warning && (
                            <span className="block">{memory.warning}</span>
                        )}
                        {memory.busy && (
                            <Button
                                type="button"
                                variant="outline"
                                onClick={memory.cancel}
                            >
                                Cancel wait
                            </Button>
                        )}
                        {!record &&
                            !actorChanged &&
                            accessBlocked === 'expired' && (
                                <SetupCreateRecovery
                                    command={createCommand}
                                    onOutcome={handleCreateOutcome}
                                />
                            )}
                    </div>
                ) : (
                    <form
                        id="setup-record-form"
                        ref={formElement}
                        onSubmit={submit}
                        noValidate
                        className="space-y-5"
                    >
                        <SetupMemoryRecovery
                            memory={memory}
                            onResume={({ work, proof }) => {
                                if (
                                    !record &&
                                    (!work.command_uuid ||
                                        !createCommand.restore(
                                            work.command_uuid,
                                            work.submitted ?? null,
                                            work.outcomeUnknown,
                                        ))
                                )
                                    return;
                                const restoredBase = fromFields(
                                    work.base_fields,
                                    work.configuration_version,
                                );
                                base.current = restoredBase;
                                initial.current = structuredClone(restoredBase);
                                form.setData(
                                    fromFields(
                                        work.fields,
                                        work.configuration_version,
                                    ),
                                );
                                submitted.current = work.submitted
                                    ? fromFields(
                                          work.submitted,
                                          work.configuration_version,
                                      )
                                    : null;
                                setStep(work.step_index);
                                setUnknown(
                                    record ? work.outcomeUnknown : false,
                                );
                                form.clearErrors();
                                if (!proof.capabilities.submit)
                                    form.setError(
                                        'configuration_version',
                                        'Review current setup before applying this retained form.',
                                    );
                                setMessage(
                                    'Retained work restored. Review the proposed details before saving.',
                                );
                            }}
                        />
                        {!record && (
                            <SetupCreateRecovery
                                command={createCommand}
                                onOutcome={handleCreateOutcome}
                            />
                        )}
                        {actorChanged && (
                            <p role="alert">
                                Your signed-in account changed. Reopen Setup
                                from your current account.
                            </p>
                        )}
                        {(message || Object.keys(form.errors).length > 0) && (
                            <div
                                role="alert"
                                className="space-y-2 rounded-lg border border-status-warning/30 bg-status-warning-bg p-3 text-sm"
                            >
                                <p>{message}</p>
                                {Object.entries(form.errors).map(
                                    ([key, error]) => (
                                        <p key={key}>
                                            {key === 'configuration_version' ? (
                                                error
                                            ) : (
                                                <Button
                                                    variant="link"
                                                    type="button"
                                                    className="h-auto p-0 text-left whitespace-normal"
                                                    onClick={() =>
                                                        focusField(
                                                            key.split('.')[0],
                                                        )
                                                    }
                                                >
                                                    {error}
                                                </Button>
                                            )}
                                        </p>
                                    ),
                                )}
                            </div>
                        )}
                        {conflict && record && (
                            <div className="space-y-3 rounded-lg border border-border p-4">
                                <p className="text-sm">
                                    Your proposed changes remain here. Compare
                                    them with the saved record before another
                                    save. Only fields you changed will be
                                    applied; other current saved values will be
                                    preserved.
                                </p>
                                <Button
                                    type="button"
                                    variant="outline"
                                    disabled={reviewing}
                                    onClick={() => void review()}
                                >
                                    {reviewing
                                        ? 'Loading current setup…'
                                        : 'Review current setup'}
                                </Button>
                                {reviewing && (
                                    <Button
                                        type="button"
                                        variant="ghost"
                                        onClick={() => {
                                            controller.current?.abort();
                                            reviewEpoch.current++;
                                            setReviewing(false);
                                        }}
                                    >
                                        Cancel review
                                    </Button>
                                )}
                                {reviewed && (
                                    <>
                                        <div className="grid grid-cols-2 gap-3">
                                            <ReviewCard
                                                icon={FileText}
                                                title="Current saved record"
                                            >
                                                {reviewRows(
                                                    valuesFor(reviewed),
                                                )}
                                            </ReviewCard>
                                            <ReviewCard
                                                icon={ClipboardCheck}
                                                title="Your proposed changes"
                                            >
                                                {reviewRows(form.data)}
                                            </ReviewCard>
                                        </div>
                                        {record ? (
                                            <Button
                                                type="button"
                                                onClick={() => {
                                                    form.setData(
                                                        'configuration_version',
                                                        reviewed.configuration_version!,
                                                    );
                                                    form.clearErrors(
                                                        'configuration_version',
                                                    );
                                                    setUnknown(false);
                                                    setSettledToken(
                                                        (value) => value + 1,
                                                    );
                                                    setReviewed(null);
                                                    setMessage(
                                                        'Current version reviewed. Choose Save to apply the fields you changed. Other fields keep their current saved values.',
                                                    );
                                                    setStep(2);
                                                }}
                                            >
                                                Use reviewed version
                                            </Button>
                                        ) : (
                                            <p className="text-sm text-muted-foreground">
                                                A record with this name or key
                                                exists. This does not establish
                                                which request created it. Close
                                                this draft and open that record
                                                to continue editing.
                                            </p>
                                        )}
                                    </>
                                )}
                            </div>
                        )}
                        {!actorChanged && (
                            <fieldset
                                disabled={
                                    processing ||
                                    unknown ||
                                    (!record && !createCommand.canEdit)
                                }
                                className="space-y-4"
                            >
                                {step === 0 && (
                                    <WizardStepPane>
                                        <StepHead
                                            icon={FileText}
                                            title={`${label(kind)} identity`}
                                            blurb="Give this record a clear, recognisable name."
                                        />
                                        <div className="grid grid-cols-2 gap-4">
                                            <label className="space-y-1.5 text-sm font-medium">
                                                {label(kind)} name
                                                <Input
                                                    name="name"
                                                    value={form.data.name}
                                                    onChange={(event) =>
                                                        form.setData(
                                                            'name',
                                                            event.target.value,
                                                        )
                                                    }
                                                    aria-invalid={
                                                        !!form.errors.name
                                                    }
                                                />
                                            </label>
                                            {kind === 'service' && (
                                                <label className="space-y-1.5 text-sm font-medium">
                                                    Stable key
                                                    <Input
                                                        name="key"
                                                        value={form.data.key}
                                                        onChange={(event) =>
                                                            form.setData(
                                                                'key',
                                                                event.target
                                                                    .value,
                                                            )
                                                        }
                                                        aria-invalid={
                                                            !!form.errors.key
                                                        }
                                                    />
                                                </label>
                                            )}
                                            <label className="col-span-2 space-y-1.5 text-sm font-medium">
                                                Description
                                                <Textarea
                                                    name="description"
                                                    rows={4}
                                                    value={
                                                        form.data.description
                                                    }
                                                    onChange={(event) =>
                                                        form.setData(
                                                            'description',
                                                            event.target.value,
                                                        )
                                                    }
                                                />
                                            </label>
                                        </div>
                                    </WizardStepPane>
                                )}
                                {step === 1 && (
                                    <WizardStepPane>
                                        <StepHead
                                            icon={UsersRound}
                                            title="Accountability"
                                            blurb="Choose eligible people and the current operating status."
                                        />
                                        <div className="space-y-5">
                                            <label className="block space-y-1.5 text-sm font-medium">
                                                {kind === 'team'
                                                    ? 'Team manager'
                                                    : 'Service owner'}
                                                <select
                                                    name="person"
                                                    className="h-10 w-full rounded-md border border-input bg-background px-3"
                                                    value={form.data.person}
                                                    onChange={(event) =>
                                                        form.setData(
                                                            'person',
                                                            event.target.value,
                                                        )
                                                    }
                                                >
                                                    <option value="">
                                                        Unassigned
                                                    </option>
                                                    {form.data.person &&
                                                        !agents.some(
                                                            (agent) =>
                                                                String(
                                                                    agent.id,
                                                                ) ===
                                                                form.data
                                                                    .person,
                                                        ) && (
                                                            <option
                                                                value={
                                                                    form.data
                                                                        .person
                                                                }
                                                                disabled
                                                            >
                                                                Unavailable —
                                                                choose another
                                                                person
                                                            </option>
                                                        )}
                                                    {agents.map((agent) => (
                                                        <option
                                                            key={agent.id}
                                                            value={agent.id}
                                                        >
                                                            {agent.name}
                                                        </option>
                                                    ))}
                                                </select>
                                            </label>
                                            <label className="flex items-center gap-2 text-sm">
                                                <Checkbox
                                                    checked={
                                                        form.data.is_active
                                                    }
                                                    onCheckedChange={(value) =>
                                                        form.setData(
                                                            'is_active',
                                                            value === true,
                                                        )
                                                    }
                                                />
                                                Active
                                            </label>
                                            {kind === 'team' ? (
                                                <fieldset
                                                    className="space-y-3"
                                                    data-field="members"
                                                >
                                                    <legend className="mb-2 text-sm font-semibold">
                                                        Team membership
                                                    </legend>
                                                    {allMembers.length ===
                                                        0 && (
                                                        <p className="text-sm text-muted-foreground">
                                                            No eligible IT
                                                            agents are
                                                            available.
                                                        </p>
                                                    )}
                                                    {allMembers.map((agent) => {
                                                        const member =
                                                            form.data.members.find(
                                                                (member) =>
                                                                    member.user_id ===
                                                                    agent.id,
                                                            );
                                                        return (
                                                            <div
                                                                key={agent.id}
                                                                className="space-y-2 rounded-lg border border-border p-3"
                                                            >
                                                                <label className="flex items-center gap-2 text-sm">
                                                                    <Checkbox
                                                                        disabled={
                                                                            !member &&
                                                                            !agents.some(
                                                                                (
                                                                                    person,
                                                                                ) =>
                                                                                    person.id ===
                                                                                    agent.id,
                                                                            )
                                                                        }
                                                                        checked={
                                                                            !!member
                                                                        }
                                                                        onCheckedChange={(
                                                                            checked,
                                                                        ) =>
                                                                            form.setData(
                                                                                'members',
                                                                                checked
                                                                                    ? [
                                                                                          ...form
                                                                                              .data
                                                                                              .members,
                                                                                          {
                                                                                              user_id:
                                                                                                  agent.id,
                                                                                              role: 'member',
                                                                                          },
                                                                                      ]
                                                                                    : form.data.members.filter(
                                                                                          (
                                                                                              member,
                                                                                          ) =>
                                                                                              member.user_id !==
                                                                                              agent.id,
                                                                                      ),
                                                                            )
                                                                        }
                                                                    />
                                                                    {agent.name}
                                                                    {!agents.some(
                                                                        (
                                                                            person,
                                                                        ) =>
                                                                            person.id ===
                                                                            agent.id,
                                                                    ) &&
                                                                        ' · No longer eligible'}
                                                                </label>
                                                                {member && (
                                                                    <TilePicker
                                                                        value={
                                                                            member.role
                                                                        }
                                                                        onChange={(
                                                                            role,
                                                                        ) =>
                                                                            form.setData(
                                                                                'members',
                                                                                form.data.members.map(
                                                                                    (
                                                                                        item,
                                                                                    ) =>
                                                                                        item.user_id ===
                                                                                        agent.id
                                                                                            ? {
                                                                                                  ...item,
                                                                                                  role,
                                                                                              }
                                                                                            : item,
                                                                                ),
                                                                            )
                                                                        }
                                                                        cols={3}
                                                                        options={[
                                                                            'member',
                                                                            'lead',
                                                                            'manager',
                                                                        ].map(
                                                                            (
                                                                                role,
                                                                            ) => ({
                                                                                key: role,
                                                                                label: label(
                                                                                    role,
                                                                                ),
                                                                            }),
                                                                        )}
                                                                    />
                                                                )}
                                                            </div>
                                                        );
                                                    })}
                                                </fieldset>
                                            ) : (
                                                <>
                                                    <fieldset>
                                                        <legend className="mb-2 text-sm font-semibold">
                                                            Health status
                                                        </legend>
                                                        <TilePicker
                                                            value={
                                                                form.data.status
                                                            }
                                                            onChange={(value) =>
                                                                form.setData(
                                                                    'status',
                                                                    value,
                                                                )
                                                            }
                                                            options={[
                                                                'operational',
                                                                'degraded',
                                                                'outage',
                                                                'maintenance',
                                                                'retired',
                                                            ].map((value) => ({
                                                                key: value,
                                                                label: label(
                                                                    value,
                                                                ),
                                                            }))}
                                                            cols={3}
                                                        />
                                                    </fieldset>
                                                    <fieldset>
                                                        <legend className="mb-2 text-sm font-semibold">
                                                            Criticality
                                                        </legend>
                                                        <TilePicker
                                                            value={
                                                                form.data
                                                                    .criticality
                                                            }
                                                            onChange={(value) =>
                                                                form.setData(
                                                                    'criticality',
                                                                    value,
                                                                )
                                                            }
                                                            options={[
                                                                'low',
                                                                'medium',
                                                                'high',
                                                                'critical',
                                                            ].map((value) => ({
                                                                key: value,
                                                                label: label(
                                                                    value,
                                                                ),
                                                            }))}
                                                        />
                                                    </fieldset>
                                                </>
                                            )}
                                        </div>
                                    </WizardStepPane>
                                )}
                                {step === 2 && (
                                    <WizardStepPane>
                                        <StepHead
                                            icon={ClipboardCheck}
                                            title="Review setup"
                                            blurb="Saving applies these changes to the shared setup."
                                        />
                                        <ReviewCard
                                            icon={
                                                kind === 'team'
                                                    ? UsersRound
                                                    : Boxes
                                            }
                                            title={form.data.name}
                                            onEdit={() => advance(0)}
                                        >
                                            {reviewRows(form.data)}
                                        </ReviewCard>
                                        {!form.data.person && (
                                            <p className="mt-3 text-sm text-status-warning">
                                                No accountable{' '}
                                                {kind === 'team'
                                                    ? 'manager'
                                                    : 'owner'}{' '}
                                                is selected. This configuration
                                                gap will remain visible after
                                                saving.
                                            </p>
                                        )}
                                    </WizardStepPane>
                                )}
                            </fieldset>
                        )}
                    </form>
                )}
            </WizardShell>
            <ConfirmDialog
                open={discard}
                onClose={() => setDiscard(false)}
                title={`Discard this ${kind} draft?`}
                description={
                    unknown || createCommand.pending
                        ? 'Saving may already have completed. Closing this draft does not undo a saved record.'
                        : 'Your proposed changes will be discarded.'
                }
                confirmText="Discard draft"
                onConfirm={() => {
                    controller.current?.abort();
                    memory.clearOwned();
                    onClose();
                }}
            />
        </>
    );
}
