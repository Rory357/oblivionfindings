import { WitnessPinInput } from '@/components/medications/witness-pin-input';
import { SettingsNotice } from '@/components/settings/settings-notice';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Switch } from '@/components/ui/switch';
import { Textarea } from '@/components/ui/textarea';
import { Field } from '@/components/wizard/primitives';
import { ReviewCard, ReviewRow } from '@/components/wizard/shell';
import { formatDateTime } from '@/lib/datetime';
import { ClipboardCheck } from 'lucide-react';
import { useState } from 'react';
import {
    NzDateTime as DateTimeField,
    multipart,
    RecordPicker,
    ReviewWizard,
    SingleFile,
    useCommand,
} from './_shared';
import type { ConnectedProps, Proposal, Transfer } from './_types';

export function Toggle({
    label,
    checked,
    onChange,
    disabled,
}: {
    label: string;
    checked: boolean;
    onChange: (v: boolean) => void;
    disabled?: boolean;
}) {
    return (
        <div className="flex items-center justify-between gap-4 rounded-lg border border-border p-3">
            <span className="text-sm">{label}</span>
            <div className="flex items-center gap-2">
                <Switch
                    aria-label={label}
                    checked={checked}
                    disabled={disabled}
                    onCheckedChange={onChange}
                />
                <span className="text-caption w-6">
                    {checked ? 'On' : 'Off'}
                </span>
            </div>
        </div>
    );
}

export function AccessWizard({
    kind,
    props,
    onClose,
    onSaved,
}: {
    kind: 'clinician' | 'grant';
    props: ConnectedProps;
    onClose: () => void;
    onSaved: () => void;
}) {
    const command = useCommand();
    const [saved, setSaved] = useState(false);
    const [form, setForm] = useState({
        name: '',
        email: '',
        provider_name: '',
        registration_authority: '',
        registration_number: '',
        identity_evidence: '',
        identity_confirmed: false,
        expires_at: '',
        clinician_id: '',
        purpose: '',
        can_propose: false,
        include_controlled: false,
    });
    const set = <K extends keyof typeof form>(k: K, v: (typeof form)[K]) =>
        setForm((f) => ({ ...f, [k]: v }));
    const clinician = props.clinicians.find(
        (c) => String(c.id) === form.clinician_id,
    );
    const save = async () => {
        const result = await command.run(
            '/emar/connected-care/' +
                (kind === 'clinician' ? 'clinicians' : 'grants'),
            { ...form, client_id: props.selected_client?.id },
        );
        if (result) {
            setSaved(true);
            onSaved();
        }
    };
    return (
        <ReviewWizard
            title={
                kind === 'clinician'
                    ? 'Verify a prescriber'
                    : 'Grant named access'
            }
            description={props.selected_client?.name ?? ''}
            onClose={onClose}
            onSave={() => void save()}
            busy={command.busy}
            error={command.error}
            disabled={command.uncertain}
            saved={saved}
            success={
                kind === 'clinician'
                    ? 'Prescriber identity recorded'
                    : 'Named access granted'
            }
            successDetail={
                kind === 'clinician'
                    ? 'The prescriber can request password setup through the clinical portal sign-in. Verify their mailbox and two-factor setup before granting chart access. No invitation was sent.'
                    : 'Access applies only to this person, at their current house, until the recorded expiry.'
            }
            steps={[
                {
                    label:
                        kind === 'clinician'
                            ? 'Identity'
                            : 'Person and purpose',
                    valid:
                        kind === 'clinician'
                            ? !!(
                                  form.name &&
                                  form.email &&
                                  form.provider_name &&
                                  form.registration_authority &&
                                  form.registration_number &&
                                  form.identity_evidence &&
                                  form.identity_confirmed
                              )
                            : !!(form.clinician_id && form.purpose),
                    content: (
                        <div className="space-y-4">
                            {kind === 'clinician' ? (
                                <>
                                    <div className="grid grid-cols-2 gap-4">
                                        {(
                                            [
                                                ['name', 'Full name'],
                                                [
                                                    'email',
                                                    'Verified work email',
                                                ],
                                                [
                                                    'provider_name',
                                                    'Provider / practice',
                                                ],
                                                [
                                                    'registration_authority',
                                                    'Registration authority',
                                                ],
                                                [
                                                    'registration_number',
                                                    'Registration number',
                                                ],
                                            ] as const
                                        ).map(([k, label]) => (
                                            <Field
                                                key={k}
                                                label={label}
                                                required
                                            >
                                                <Input
                                                    type={
                                                        k === 'email'
                                                            ? 'email'
                                                            : 'text'
                                                    }
                                                    value={form[k]}
                                                    onChange={(e) =>
                                                        set(k, e.target.value)
                                                    }
                                                />
                                            </Field>
                                        ))}
                                    </div>
                                    <Field
                                        label="Identity and registration evidence"
                                        required
                                    >
                                        <Textarea
                                            value={form.identity_evidence}
                                            onChange={(e) =>
                                                set(
                                                    'identity_evidence',
                                                    e.target.value,
                                                )
                                            }
                                        />
                                    </Field>
                                    <Toggle
                                        label="I checked the identity and registration evidence"
                                        checked={form.identity_confirmed}
                                        onChange={(v) =>
                                            set('identity_confirmed', v)
                                        }
                                    />
                                </>
                            ) : (
                                <>
                                    <RecordPicker
                                        label="Prescriber"
                                        value={form.clinician_id}
                                        options={props.clinicians
                                            .filter(
                                                (c) =>
                                                    !c.revoked_at &&
                                                    Date.parse(c.expires_at) >
                                                        Date.now(),
                                            )
                                            .map((c) => ({
                                                value: String(c.id),
                                                label: c.name,
                                                description: c.provider_name,
                                            }))}
                                        onChange={(v) => set('clinician_id', v)}
                                    />
                                    <Field
                                        label="Why this prescriber needs access"
                                        required
                                    >
                                        <Textarea
                                            value={form.purpose}
                                            onChange={(e) =>
                                                set('purpose', e.target.value)
                                            }
                                        />
                                    </Field>
                                </>
                            )}
                        </div>
                    ),
                },
                {
                    label: 'Limits',
                    valid: !!form.expires_at,
                    content: (
                        <div className="space-y-4">
                            <DateTimeField
                                compact
                                id="access-expiry"
                                label={
                                    kind === 'clinician'
                                        ? 'Identity approval ends'
                                        : 'Access ends'
                                }
                                value={form.expires_at}
                                onChange={(v) => set('expires_at', v)}
                            />
                            {kind === 'grant' && (
                                <>
                                    <Toggle
                                        label="Allow medication requests for review"
                                        checked={form.can_propose}
                                        onChange={(v) => set('can_propose', v)}
                                    />
                                    <Toggle
                                        label="Include controlled medicines"
                                        checked={form.include_controlled}
                                        onChange={(v) =>
                                            set('include_controlled', v)
                                        }
                                    />
                                </>
                            )}
                            <SettingsNotice>
                                Access needs a current approved identity,
                                verified mailbox and two-factor authentication.
                                It ends if the person moves house or the
                                approval expires or is revoked.
                            </SettingsNotice>
                        </div>
                    ),
                },
            ]}
            review={[
                { label: 'Person', value: props.selected_client?.name },
                {
                    label: 'Prescriber',
                    value: kind === 'clinician' ? form.name : clinician?.name,
                },
                {
                    label: 'Evidence / purpose',
                    value:
                        kind === 'clinician'
                            ? form.identity_evidence
                            : form.purpose,
                },
                {
                    label: 'Ends',
                    value:
                        form.expires_at.replace('T', ' ') +
                        ' · Pacific/Auckland',
                },
                {
                    label: 'Controlled medicines',
                    value: form.include_controlled
                        ? 'Included with current authority'
                        : 'Not included',
                },
            ]}
        />
    );
}

export function RevokeAccess({
    kind,
    id,
    name,
    clientId,
    onClose,
    onSaved,
}: {
    kind: 'clinicians' | 'grants';
    id: number;
    name: string;
    clientId: number;
    onClose: () => void;
    onSaved: () => void;
}) {
    const command = useCommand();
    const [reason, setReason] = useState('');
    const [saved, setSaved] = useState(false);
    return (
        <ReviewWizard
            title={
                kind === 'clinicians'
                    ? 'Withdraw prescriber identity'
                    : 'Revoke access'
            }
            description={kind === 'clinicians' ? name + ' · All houses' : name}
            onClose={onClose}
            busy={command.busy}
            error={command.error}
            disabled={command.uncertain}
            saved={saved}
            saveLabel={
                kind === 'clinicians' ? 'Withdraw identity' : 'Revoke access'
            }
            onSave={async () => {
                if (
                    await command.run(
                        '/emar/connected-care/' + kind + '/' + id + '/revoke',
                        { client_id: clientId, reason },
                    )
                ) {
                    setSaved(true);
                    onSaved();
                }
            }}
            steps={[
                {
                    label: 'Reason',
                    valid: !!reason.trim(),
                    content: (
                        <Field label="Reason" required>
                            <Textarea
                                value={reason}
                                onChange={(e) => setReason(e.target.value)}
                            />
                        </Field>
                    ),
                },
            ]}
            review={[
                { label: 'Prescriber', value: name },
                {
                    label: 'Effect',
                    value:
                        kind === 'clinicians'
                            ? 'End this clinician’s access to every granted person'
                            : 'End this named-person grant',
                },
                { label: 'Reason', value: reason },
            ]}
        />
    );
}

export function ProposalDecision({
    proposal: p,
    canManage,
    witnesses,
    onClose,
    onSaved,
}: {
    proposal: Proposal;
    canManage: boolean;
    witnesses: { id: number; name: string }[];
    onClose: () => void;
    onSaved: () => void;
}) {
    const command = useCommand();
    const [saved, setSaved] = useState(false);
    const [orderUrl, setOrderUrl] = useState<string | null>(
        p.order_url ?? null,
    );
    const [decision, setDecision] = useState('');
    const [note, setNote] = useState('');
    // EA-084: kept inside the organisation; never shown in the portal.
    const [internalNote, setInternalNote] = useState('');
    const [confirmed, setConfirmed] = useState(false);
    const [file, setFile] = useState<File | null>(null);
    const [source, setSource] = useState({
        type: 'written',
        prescriber: p.clinician_name,
        received_at: new Date().toISOString(),
        description: '',
        read_back_confirmed: false,
        witness_id: '',
        witness_pin: '',
    });
    const [stop, setStop] = useState({
        source_reference: '',
        ceased_version: '',
    });
    const set = <K extends keyof typeof source>(k: K, v: (typeof source)[K]) =>
        setSource((s) => ({ ...s, [k]: v }));
    const valid =
        !!note &&
        !!decision &&
        (decision === 'reject' ||
            (confirmed &&
                (decision === 'link_stop'
                    ? !!stop.source_reference &&
                      Number(stop.ceased_version) > Number(p.expected_version)
                    : !!source.prescriber &&
                      !!source.received_at &&
                      !!source.description &&
                      (source.type === 'written'
                          ? !!file || p.has_source_file
                          : source.read_back_confirmed &&
                            !!source.witness_id &&
                            !!source.witness_pin))));
    return (
        <ReviewWizard
            title="Review prescriber request"
            description={p.client_name + ' · ' + p.clinician_name}
            onClose={onClose}
            saved={saved}
            busy={command.busy}
            error={command.error}
            disabled={
                !canManage || p.status !== 'submitted' || command.uncertain
            }
            saveLabel="Record decision"
            onSave={async () => {
                const result = await command.run<{ order_url?: string | null }>(
                    '/emar/connected-care/proposals/' + p.id + '/decision',
                    multipart({
                        decision,
                        decision_note: note,
                        internal_note: internalNote,
                        source_confirmed: confirmed,
                        source,
                        source_file: file,
                        ...stop,
                    }),
                );
                if (result) {
                    setOrderUrl(result.order_url ?? null);
                    setSaved(true);
                    onSaved();
                }
            }}
            success="Decision recorded"
            successDetail={
                <>
                    <span>
                        Accepted requests enter medication-order checking. They
                        are ready to give only after the required checks pass.
                    </span>
                    {orderUrl && (
                        <Button asChild variant="outline">
                            <a href={orderUrl}>Open the medication order</a>
                        </Button>
                    )}
                </>
            }
            steps={[
                {
                    label: 'Request',
                    content: (
                        <div className="space-y-4">
                            {orderUrl && (
                                <Button asChild variant="outline">
                                    <a href={orderUrl}>
                                        Open the medication order
                                    </a>
                                </Button>
                            )}
                            <ReviewCard
                                icon={ClipboardCheck}
                                title={p.kind + ' request'}
                            >
                                <ReviewRow
                                    label="Medicine"
                                    value={
                                        p.prescription?.name ??
                                        'Existing medication #' +
                                            p.medication_id
                                    }
                                />
                                <ReviewRow
                                    label="Dose"
                                    value={p.prescription?.dosage}
                                />
                                <ReviewRow
                                    label="Route / frequency"
                                    value={[
                                        p.prescription?.route,
                                        p.prescription?.frequency,
                                    ]
                                        .filter(Boolean)
                                        .join(' · ')}
                                />
                                <ReviewRow
                                    label="Scheduled times"
                                    value={p.prescription?.dose_times.join(
                                        ', ',
                                    )}
                                />
                                <ReviewRow
                                    label="Instructions"
                                    value={p.prescription?.instructions}
                                />
                                {p.prescription && (
                                    <>
                                        <ReviewRow
                                            label="Form / indication"
                                            value={[
                                                p.prescription.form,
                                                p.prescription.indication,
                                            ]
                                                .filter(Boolean)
                                                .join(' · ')}
                                        />
                                        <ReviewRow
                                            label="Dates"
                                            value={
                                                p.prescription.start_date +
                                                ' to ' +
                                                (p.prescription.end_date ||
                                                    'Ongoing')
                                            }
                                        />
                                        <ReviewRow
                                            label="Numeric dose"
                                            value={[
                                                p.prescription.dose_amount,
                                                p.prescription.dose_unit,
                                            ]
                                                .filter((v) => v !== null)
                                                .join(' ')}
                                        />
                                        <ReviewRow
                                            label="As needed"
                                            value={
                                                p.prescription.is_prn
                                                    ? 'Yes · ' +
                                                      p.prescription
                                                          .prn_reason +
                                                      ' · maximum ' +
                                                      p.prescription
                                                          .max_per_day +
                                                      ' per day · at least ' +
                                                      p.prescription
                                                          .min_hours_between_doses +
                                                      ' hours apart'
                                                    : 'No'
                                            }
                                        />
                                        <ReviewRow
                                            label="Safety flags"
                                            value={
                                                [
                                                    p.prescription
                                                        .controlled_drug &&
                                                        'Controlled medicine',
                                                    p.prescription.high_risk &&
                                                        'High risk',
                                                    p.prescription
                                                        .witness_required &&
                                                        'Witness required',
                                                ]
                                                    .filter(Boolean)
                                                    .join(' · ') ||
                                                'None specified'
                                            }
                                        />
                                        <ReviewRow
                                            label="Prescriber / pharmacy"
                                            value={[
                                                p.prescription.prescriber,
                                                p.prescription.pharmacy,
                                            ]
                                                .filter(Boolean)
                                                .join(' · ')}
                                        />
                                    </>
                                )}
                                <ReviewRow label="Reason" value={p.reason} />
                                <ReviewRow
                                    label="Submitted"
                                    value={formatDateTime(p.submitted_at)}
                                />
                                <ReviewRow label="State" value={p.status} />
                            </ReviewCard>
                            {p.has_source_file && (
                                <Button variant="outline" asChild>
                                    <a
                                        href={
                                            '/emar/connected-care/proposals/' +
                                            p.id +
                                            '/source'
                                        }
                                        target="_blank"
                                        rel="noreferrer"
                                    >
                                        Open source evidence
                                    </a>
                                </Button>
                            )}
                            <SettingsNotice>
                                Check the full prescription, allergies, source
                                and current version. A stop request must be
                                recorded through Medication orders first, then
                                linked here.
                            </SettingsNotice>
                        </div>
                    ),
                },
                {
                    label: 'Decision and evidence',
                    valid,
                    content: (
                        <div className="space-y-4">
                            <RecordPicker
                                label="Decision"
                                value={decision}
                                options={[
                                    {
                                        value: 'reject',
                                        label: 'Return / reject with reason',
                                    },
                                    {
                                        value:
                                            p.kind === 'stop'
                                                ? 'link_stop'
                                                : 'accept',
                                        label:
                                            p.kind === 'stop'
                                                ? 'Link recorded stop'
                                                : 'Accept for independent order check',
                                    },
                                ]}
                                onChange={setDecision}
                            />
                            <Field
                                label="Reply to the prescriber (they will see this)"
                                required
                            >
                                <Textarea
                                    value={note}
                                    onChange={(e) => setNote(e.target.value)}
                                />
                            </Field>
                            <p className="text-caption">
                                The prescriber reads this word for word in their
                                portal. Keep family, staff and incident details
                                out of it.
                            </p>
                            <Field label="Internal note (staff only)">
                                <Textarea
                                    value={internalNote}
                                    onChange={(e) =>
                                        setInternalNote(e.target.value)
                                    }
                                />
                            </Field>
                            {decision === 'link_stop' && (
                                <>
                                    <Field
                                        label="Recorded ceased version"
                                        required
                                    >
                                        <Input
                                            type="number"
                                            value={stop.ceased_version}
                                            onChange={(e) =>
                                                setStop({
                                                    ...stop,
                                                    ceased_version:
                                                        e.target.value,
                                                })
                                            }
                                        />
                                    </Field>
                                    <Field
                                        label="Source evidence reference"
                                        required
                                    >
                                        <Input
                                            value={stop.source_reference}
                                            onChange={(e) =>
                                                setStop({
                                                    ...stop,
                                                    source_reference:
                                                        e.target.value,
                                                })
                                            }
                                        />
                                    </Field>
                                </>
                            )}
                            {decision === 'accept' && (
                                <>
                                    <RecordPicker
                                        label="Source type"
                                        value={source.type}
                                        options={[
                                            {
                                                value: 'written',
                                                label: 'Written prescription',
                                            },
                                            {
                                                value: 'phone',
                                                label: 'Phone instruction',
                                            },
                                            {
                                                value: 'verbal',
                                                label: 'Verbal instruction',
                                            },
                                        ]}
                                        onChange={(v) => set('type', v)}
                                    />
                                    <Field label="Prescriber" required>
                                        <Input
                                            value={source.prescriber}
                                            onChange={(e) =>
                                                set(
                                                    'prescriber',
                                                    e.target.value,
                                                )
                                            }
                                        />
                                    </Field>
                                    <DateTimeField
                                        compact
                                        id="proposal-source-received"
                                        label="Instruction received"
                                        value={source.received_at}
                                        onChange={(v) => set('received_at', v)}
                                    />
                                    <Field
                                        label="Source evidence description"
                                        required
                                    >
                                        <Textarea
                                            value={source.description}
                                            onChange={(e) =>
                                                set(
                                                    'description',
                                                    e.target.value,
                                                )
                                            }
                                        />
                                    </Field>
                                    {source.type === 'written' ? (
                                        <>
                                            {p.has_source_file && (
                                                <p className="text-caption">
                                                    The attached prescription
                                                    will be used unless you
                                                    supply a replacement.
                                                </p>
                                            )}
                                            <SingleFile
                                                file={file}
                                                onChange={setFile}
                                            />
                                        </>
                                    ) : (
                                        <>
                                            <RecordPicker
                                                label="Read-back witness"
                                                value={source.witness_id}
                                                options={witnesses.map((w) => ({
                                                    value: String(w.id),
                                                    label: w.name,
                                                }))}
                                                onChange={(v) =>
                                                    set('witness_id', v)
                                                }
                                            />
                                            <Field label="Witness PIN">
                                                <WitnessPinInput
                                                    value={source.witness_pin}
                                                    onChange={(v) =>
                                                        set('witness_pin', v)
                                                    }
                                                />
                                            </Field>
                                            <Toggle
                                                label="The instruction was read back and confirmed"
                                                checked={
                                                    source.read_back_confirmed
                                                }
                                                onChange={(v) =>
                                                    set(
                                                        'read_back_confirmed',
                                                        v,
                                                    )
                                                }
                                            />
                                        </>
                                    )}
                                </>
                            )}
                            {decision && decision !== 'reject' && (
                                <Toggle
                                    label="I checked the source evidence"
                                    checked={confirmed}
                                    onChange={setConfirmed}
                                />
                            )}
                        </div>
                    ),
                },
            ]}
            review={[
                { label: 'Person', value: p.client_name },
                {
                    label: 'Medicine',
                    value:
                        p.prescription?.name ??
                        'Existing medication #' + p.medication_id,
                },
                { label: 'Decision', value: decision.replace('_', ' ') },
                { label: 'Reply to the prescriber', value: note },
                { label: 'Internal note', value: internalNote || 'None' },
                { label: 'Source confirmed', value: confirmed ? 'Yes' : 'No' },
            ]}
        />
    );
}

export function TransferWizard({
    props,
    onClose,
    onSaved,
}: {
    props: ConnectedProps;
    onClose: () => void;
    onSaved: () => void;
}) {
    const command = useCommand();
    const [saved, setSaved] = useState(false);
    const [key] = useState(() => crypto.randomUUID());
    const [file, setFile] = useState<File | null>(null);
    const [snapshot, setSnapshot] = useState<unknown>(null);
    const [fileError, setFileError] = useState('');
    const [form, setForm] = useState({
        direction: 'outgoing',
        provider_name: '',
        recipient_name: '',
        purpose: '',
        disclosure_basis: '',
        identity_evidence: '',
        source_reference: '',
        identity_confirmed: false,
    });
    const set = <K extends keyof typeof form>(k: K, v: (typeof form)[K]) =>
        setForm((f) => ({ ...f, [k]: v }));
    const read = async (f: File | null) => {
        setFile(f);
        setSnapshot(null);
        setFileError('');
        if (!f) return;
        if (f.size > 2 * 1024 * 1024) {
            setFileError('Choose a JSON packet no larger than 2 MB.');
            return;
        }
        try {
            const data = JSON.parse(await f.text());
            if (
                data.format !== 'oblivion-medication-handover' ||
                data.format_version !== 1 ||
                !data.snapshot?.person ||
                !Array.isArray(data.snapshot.medications)
            )
                throw Error();
            setSnapshot(data.snapshot);
        } catch {
            setFileError(
                'This is not a supported version 1 medication handover packet.',
            );
        }
    };
    return (
        <ReviewWizard
            title="New provider handover"
            description={props.selected_client?.name ?? ''}
            onClose={onClose}
            saved={saved}
            busy={command.busy}
            error={command.error || fileError}
            disabled={command.uncertain}
            saveLabel="Save draft handover"
            onSave={async () => {
                if (
                    await command.run('/emar/connected-care/transfers', {
                        ...form,
                        client_id: props.selected_client?.id,
                        source_snapshot: snapshot,
                        request_key: key,
                    })
                ) {
                    setSaved(true);
                    onSaved();
                }
            }}
            success="Handover draft saved"
            successDetail="Open the saved handover to review identity, medicines and recipient before releasing a packet or beginning reconciliation."
            steps={[
                {
                    label: 'Provider and person',
                    valid: !!(
                        form.provider_name &&
                        form.recipient_name &&
                        form.identity_evidence
                    ),
                    content: (
                        <div className="space-y-4">
                            <RecordPicker
                                label="Direction"
                                value={form.direction}
                                options={[
                                    {
                                        value: 'outgoing',
                                        label: 'Outgoing to another provider',
                                    },
                                    {
                                        value: 'incoming',
                                        label: 'Incoming from another provider',
                                    },
                                ]}
                                onChange={(v) => set('direction', v)}
                            />
                            <Field label="Provider name" required>
                                <Input
                                    value={form.provider_name}
                                    onChange={(e) =>
                                        set('provider_name', e.target.value)
                                    }
                                />
                            </Field>
                            <Field label="Named recipient / sender" required>
                                <Input
                                    value={form.recipient_name}
                                    onChange={(e) =>
                                        set('recipient_name', e.target.value)
                                    }
                                />
                            </Field>
                            <Field
                                label="How the person’s identity was checked"
                                required
                            >
                                <Textarea
                                    value={form.identity_evidence}
                                    onChange={(e) =>
                                        set('identity_evidence', e.target.value)
                                    }
                                />
                            </Field>
                        </div>
                    ),
                },
                {
                    label: 'Sharing and source',
                    valid:
                        !!form.purpose &&
                        !!form.disclosure_basis &&
                        (form.direction === 'outgoing' ||
                            (!!snapshot &&
                                !!form.source_reference &&
                                form.identity_confirmed)),
                    content: (
                        <div className="space-y-4">
                            <Field label="Purpose" required>
                                <Textarea
                                    value={form.purpose}
                                    onChange={(e) =>
                                        set('purpose', e.target.value)
                                    }
                                />
                            </Field>
                            <Field
                                label="Authority / consent for sharing"
                                required
                            >
                                <Textarea
                                    value={form.disclosure_basis}
                                    onChange={(e) =>
                                        set('disclosure_basis', e.target.value)
                                    }
                                />
                            </Field>
                            {form.direction === 'incoming' && (
                                <>
                                    <SingleFile
                                        accept=".json,application/json"
                                        hint="Reviewed medication handover JSON · version 1 · up to 2 MB"
                                        file={file}
                                        onChange={(f) => void read(f)}
                                    />
                                    <Field label="Source reference" required>
                                        <Input
                                            value={form.source_reference}
                                            onChange={(e) =>
                                                set(
                                                    'source_reference',
                                                    e.target.value,
                                                )
                                            }
                                        />
                                    </Field>
                                    <Toggle
                                        label="I matched the incoming record to this person"
                                        checked={form.identity_confirmed}
                                        onChange={(v) =>
                                            set('identity_confirmed', v)
                                        }
                                    />
                                </>
                            )}
                            <SettingsNotice>
                                The draft is reviewed before release. Incoming
                                medicines and allergies are evidence for
                                reconciliation; they are not activated
                                automatically.
                            </SettingsNotice>
                        </div>
                    ),
                },
            ]}
            review={[
                { label: 'Person', value: props.selected_client?.name },
                { label: 'Direction', value: form.direction },
                { label: 'Provider', value: form.provider_name },
                { label: 'Recipient / sender', value: form.recipient_name },
                { label: 'Purpose', value: form.purpose },
                { label: 'Sharing basis', value: form.disclosure_basis },
                {
                    label: 'Source packet',
                    value: file?.name ?? 'Current chart snapshot',
                },
            ]}
        />
    );
}

export function TransferAction({
    transfer: t,
    action,
    onClose,
    onSaved,
}: {
    transfer: Transfer;
    action: string;
    onClose: () => void;
    onSaved: () => void;
}) {
    const command = useCommand();
    const [saved, setSaved] = useState(false);
    const [key] = useState(() => crypto.randomUUID());
    const [note, setNote] = useState('');
    const [receipt, setReceipt] = useState('');
    const [identity, setIdentity] = useState(false);
    const [facts, setFacts] = useState(false);
    const [recipient, setRecipient] = useState(false);
    const [allergies, setAllergies] = useState(false);
    const [allergyReference, setAllergyReference] = useState('');
    const labels: Record<string, string> = {
        review: 'Approve handover',
        receipt: 'Record receipt',
        start_reconciliation: 'Start reconciliation',
        complete: 'Confirm reconciliation complete',
        cancel: 'Cancel handover',
    };
    return (
        <ReviewWizard
            title={labels[action] ?? 'Update handover'}
            description={t.client_name + ' · ' + t.provider_name}
            onClose={onClose}
            saved={saved}
            busy={command.busy}
            error={command.error}
            disabled={command.uncertain}
            saveLabel={labels[action]}
            onSave={async () => {
                if (
                    await command.run(
                        '/emar/connected-care/transfers/' +
                            t.id +
                            '/transition',
                        {
                            action,
                            expected_version: t.version,
                            request_key: key,
                            note,
                            identity_confirmed: identity,
                            facts_checked: facts,
                            recipient_confirmed: recipient,
                            receipt_reference: receipt,
                            allergies_reviewed: allergies,
                            allergy_review_reference: allergyReference,
                        },
                    )
                ) {
                    setSaved(true);
                    onSaved();
                }
            }}
            steps={[
                {
                    label: 'Evidence',
                    valid:
                        !!note &&
                        (action !== 'review' ||
                            (identity && facts && recipient)) &&
                        (action !== 'receipt' || !!receipt) &&
                        (action !== 'start_reconciliation' || identity) &&
                        (action !== 'complete' ||
                            (allergies && !!allergyReference)),
                    content: (
                        <div className="space-y-4">
                            <Field label="Note / evidence" required>
                                <Textarea
                                    value={note}
                                    onChange={(e) => setNote(e.target.value)}
                                />
                            </Field>
                            {(action === 'review' ||
                                action === 'start_reconciliation') && (
                                <Toggle
                                    label="I confirmed this person’s identity"
                                    checked={identity}
                                    onChange={setIdentity}
                                />
                            )}{' '}
                            {action === 'review' && (
                                <>
                                    <Toggle
                                        label="I checked the medicines, allergies and source facts"
                                        checked={facts}
                                        onChange={setFacts}
                                    />
                                    <Toggle
                                        label="I confirmed the recipient and sharing authority"
                                        checked={recipient}
                                        onChange={setRecipient}
                                    />
                                </>
                            )}
                            {action === 'receipt' && (
                                <Field
                                    label="Receipt evidence reference"
                                    required
                                >
                                    <Input
                                        value={receipt}
                                        onChange={(e) =>
                                            setReceipt(e.target.value)
                                        }
                                    />
                                </Field>
                            )}
                            {action === 'complete' && (
                                <>
                                    <Toggle
                                        label="I reviewed incoming allergy evidence in the clinical record"
                                        checked={allergies}
                                        onChange={setAllergies}
                                    />
                                    <Field
                                        label="Allergy review reference"
                                        required
                                    >
                                        <Input
                                            value={allergyReference}
                                            onChange={(e) =>
                                                setAllergyReference(
                                                    e.target.value,
                                                )
                                            }
                                        />
                                    </Field>
                                    <SettingsNotice>
                                        The linked medication reconciliation
                                        must already be signed off. Any
                                        unresolved medicine or allergy evidence
                                        must be handled through its normal
                                        clinical workflow.
                                    </SettingsNotice>
                                </>
                            )}
                        </div>
                    ),
                },
            ]}
            review={[
                { label: 'Person', value: t.client_name },
                { label: 'Provider', value: t.provider_name },
                { label: 'Action', value: labels[action] },
                { label: 'Note', value: note },
            ]}
        />
    );
}
