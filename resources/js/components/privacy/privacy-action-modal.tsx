/* eslint-disable no-restricted-syntax -- Small lifecycle action modal; styled
 * native checkbox for the irreversible-delete confirm. Semantic tokens only. */
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';
import {
    Field,
    SelectInput,
    type IconType,
} from '@/components/wizard/primitives';
import { cn } from '@/lib/utils';
import { useForm } from '@inertiajs/react';
import {
    Ban,
    Check,
    Clock,
    Download,
    Fingerprint,
    Loader2,
    Mail,
    Send,
    ShieldAlert,
    Trash2,
} from 'lucide-react';
import { useState } from 'react';

type ActionField = {
    type: 'select' | 'text' | 'textarea' | 'date' | 'checkbox';
    name: string;
    label: string;
    required?: boolean;
    options?: string[];
    placeholder?: string;
    hint?: string;
};

type ActionConfig = {
    title: string;
    blurb: string;
    icon: IconType;
    tone: 'primary' | 'critical';
    confirm: string;
    method: 'post' | 'get';
    path: (id: number) => string;
    fields: ActionField[];
    extraInitial?: (id: number) => Record<string, unknown>;
};

export type PrivacyActionKind =
    | 'verify'
    | 'extend'
    | 'complete'
    | 'refuse'
    | 'export'
    | 'notify-opc'
    | 'notify-subjects'
    | 'resolve'
    | 'release'
    | 'approve'
    | 'review'
    | 'execute';

const ACTIONS: Record<PrivacyActionKind, ActionConfig> = {
    verify: {
        title: 'Verify identity',
        blurb: 'Confirm the requester’s identity before processing (IPP 6).',
        icon: Fingerprint,
        tone: 'primary',
        confirm: 'Confirm verification',
        method: 'post',
        path: (id) => `/privacy/requests/${id}/verify-identity`,
        fields: [
            {
                type: 'select',
                name: 'verification_method',
                label: 'Verification method',
                required: true,
                options: [
                    'RealMe verified',
                    'Drivers licence',
                    'Passport',
                    'In person',
                    'Known to organisation',
                ],
            },
        ],
    },
    extend: {
        title: 'Extend deadline',
        blurb: 'Extend the statutory due date with a recorded reason.',
        icon: Clock,
        tone: 'primary',
        confirm: 'Extend deadline',
        method: 'post',
        path: (id) => `/privacy/requests/${id}/extend`,
        fields: [
            {
                type: 'date',
                name: 'extended_due_date',
                label: 'New due date',
                required: true,
            },
            {
                type: 'textarea',
                name: 'extension_reason',
                label: 'Reason for extension',
                required: true,
                placeholder: 'Why the statutory deadline is being extended…',
            },
        ],
    },
    complete: {
        title: 'Mark complete',
        blurb: 'Close the request and record completion notes.',
        icon: Check,
        tone: 'primary',
        confirm: 'Mark complete',
        method: 'post',
        path: (id) => `/privacy/requests/${id}/complete`,
        fields: [
            {
                type: 'textarea',
                name: 'completion_notes',
                label: 'Completion notes',
                placeholder: 'How the request was fulfilled…',
            },
        ],
    },
    refuse: {
        title: 'Refuse request',
        blurb: 'Record the reason and legal basis for refusal.',
        icon: Ban,
        tone: 'critical',
        confirm: 'Refuse request',
        method: 'post',
        path: (id) => `/privacy/requests/${id}/refuse`,
        fields: [
            {
                type: 'textarea',
                name: 'rejection_reason',
                label: 'Reason',
                required: true,
                placeholder: 'Why the request is being refused…',
            },
            {
                type: 'text',
                name: 'rejection_legal_basis',
                label: 'Legal basis',
                required: true,
                placeholder: 'e.g. Privacy Act 2020 s 49 — evaluative material',
            },
        ],
    },
    export: {
        title: 'Export data package',
        blurb: 'Generate a JSON data package assembling the subject’s information (IPP 6).',
        icon: Download,
        tone: 'primary',
        confirm: 'Generate package',
        method: 'get',
        path: (id) => `/privacy/requests/${id}/export`,
        fields: [],
    },
    'notify-opc': {
        title: 'Notify OPC',
        blurb: 'Record notification to the Privacy Commissioner (notify as soon as practicable).',
        icon: ShieldAlert,
        tone: 'primary',
        confirm: 'Record OPC notification',
        method: 'post',
        path: (id) => `/privacy/breaches/${id}/notify-opc`,
        fields: [
            {
                type: 'text',
                name: 'authority_reference',
                label: 'OPC / NotifyUs reference',
                hint: 'optional',
                placeholder: 'Reference from NotifyUs',
            },
        ],
    },
    'notify-subjects': {
        title: 'Notify affected individuals',
        blurb: 'Record how the affected individuals were told.',
        icon: Mail,
        tone: 'primary',
        confirm: 'Record notification',
        method: 'post',
        path: (id) => `/privacy/breaches/${id}/notify-subjects`,
        fields: [
            {
                type: 'select',
                name: 'notification_method',
                label: 'Notification method',
                required: true,
                options: [
                    'Email',
                    'Letter',
                    'Phone',
                    'In person',
                    'Public notice',
                ],
            },
        ],
    },
    resolve: {
        title: 'Resolve breach',
        blurb: 'Close the breach and record the resolution.',
        icon: Check,
        tone: 'primary',
        confirm: 'Resolve breach',
        method: 'post',
        path: (id) => `/privacy/breaches/${id}/resolve`,
        fields: [
            {
                type: 'textarea',
                name: 'resolution_notes',
                label: 'Resolution notes',
                required: true,
                placeholder: 'How the breach was contained and resolved…',
            },
        ],
    },
    release: {
        title: 'Release hold',
        blurb: 'Lift the preservation order and record why.',
        icon: Ban,
        tone: 'critical',
        confirm: 'Release hold',
        method: 'post',
        path: (id) => `/privacy/legal-holds/${id}/release`,
        fields: [
            {
                type: 'textarea',
                name: 'release_reason',
                label: 'Release reason',
                required: true,
                placeholder: 'Why the hold is no longer required…',
            },
        ],
    },
    approve: {
        title: 'Approve DPIA',
        blurb: 'Approve the assessment outcome.',
        icon: Check,
        tone: 'primary',
        confirm: 'Approve DPIA',
        method: 'post',
        path: (id) => `/privacy/pia/${id}/approve`,
        fields: [],
    },
    review: {
        title: 'Send for Privacy Officer review',
        blurb: 'Record review notes and flag for the Privacy Officer.',
        icon: Send,
        tone: 'primary',
        confirm: 'Send for review',
        method: 'post',
        path: (id) => `/privacy/pia/${id}/review`,
        fields: [
            {
                type: 'textarea',
                name: 'review_notes',
                label: 'Review notes',
                required: true,
                placeholder: 'What needs the Privacy Officer’s attention…',
            },
        ],
    },
    execute: {
        title: 'Execute approved retention',
        blurb: 'Run the independently approved preview now. The same legal holds, exemptions and native record-owner contract used by scheduled execution are applied again before every outcome.',
        icon: Trash2,
        tone: 'critical',
        confirm: 'Execute approved retention',
        method: 'post',
        path: () => '/privacy/deletion/execute',
        extraInitial: (id) => ({ policy_id: id }),
        fields: [
            {
                type: 'checkbox',
                name: 'confirm',
                label: 'I understand approved outcomes may permanently anonymise matching records and cannot be undone.',
                required: true,
            },
        ],
    },
};

export function PrivacyActionModal({
    kind,
    recordId,
    open,
    onClose,
}: {
    kind: PrivacyActionKind;
    recordId: number;
    open: boolean;
    onClose: () => void;
}) {
    const action = ACTIONS[kind];
    const initial: Record<string, unknown> = {
        ...(action.extraInitial?.(recordId) ?? {}),
    };
    for (const f of action.fields)
        initial[f.name] = f.type === 'checkbox' ? false : '';

    // eslint-disable-next-line @typescript-eslint/no-explicit-any -- dynamic action form shape
    const form = useForm<Record<string, any>>(initial);
    const { data, setData, processing } = form;
    const [errors, setErrors] = useState<Record<string, string>>({});

    const err = (name: string) =>
        errors[name] ?? (form.errors as Record<string, string>)[name];

    const submit = () => {
        const e: Record<string, string> = {};
        for (const f of action.fields) {
            if (f.required && !isFilled(data[f.name]))
                e[f.name] = `${f.label} is required`;
        }
        setErrors(e);
        if (Object.keys(e).length) return;

        const opts = {
            preserveScroll: true,
            preserveState: true,
            onSuccess: () => onClose(),
        };
        if (action.method === 'get') form.get(action.path(recordId), opts);
        else form.post(action.path(recordId), opts);
    };

    const critical = action.tone === 'critical';
    const Icon = action.icon;

    return (
        <Dialog open={open} onOpenChange={(o) => !o && onClose()}>
            <DialogContent className="max-w-md gap-0 overflow-hidden p-0">
                <DialogTitle className="sr-only">{action.title}</DialogTitle>
                <DialogDescription className="sr-only">
                    {action.blurb}
                </DialogDescription>

                <div className="flex items-start gap-3 border-b border-border p-5">
                    <span
                        className={cn(
                            'grid h-11 w-11 shrink-0 place-items-center rounded-xl',
                            critical
                                ? 'bg-status-critical-bg text-status-critical'
                                : 'bg-primary/10 text-primary',
                        )}
                    >
                        <Icon className="h-5 w-5" />
                    </span>
                    <div>
                        <h2 className="text-base font-bold">{action.title}</h2>
                        <p className="mt-0.5 text-[13px] leading-relaxed text-muted-foreground">
                            {action.blurb}
                        </p>
                    </div>
                </div>

                {action.fields.length ? (
                    <div className="flex flex-col gap-4 p-5">
                        {action.fields.map((f) => (
                            <ActionFieldRow
                                key={f.name}
                                field={f}
                                value={data[f.name]}
                                error={err(f.name)}
                                onChange={(v) => setData(f.name, v)}
                            />
                        ))}
                    </div>
                ) : (
                    <div className="px-5 pt-5" />
                )}

                <div className="flex items-center justify-end gap-2.5 border-t border-border bg-muted/30 px-5 py-3.5">
                    <Button type="button" variant="outline" onClick={onClose}>
                        Cancel
                    </Button>
                    <Button
                        type="button"
                        variant={critical ? 'destructive' : 'default'}
                        onClick={submit}
                        disabled={processing}
                    >
                        {processing ? (
                            <Loader2 className="h-4 w-4 animate-spin" />
                        ) : (
                            <Icon className="h-4 w-4" />
                        )}
                        {action.confirm}
                    </Button>
                </div>
            </DialogContent>
        </Dialog>
    );
}

function ActionFieldRow({
    field: f,
    value,
    error,
    onChange,
}: {
    field: ActionField;
    value: unknown;
    error?: string;
    onChange: (v: unknown) => void;
}) {
    if (f.type === 'checkbox') {
        return (
            <label className="flex cursor-pointer items-start gap-2.5 rounded-lg border border-status-critical/30 bg-status-critical-bg/40 p-3 text-[13px]">
                <input
                    type="checkbox"
                    checked={Boolean(value)}
                    onChange={(e) => onChange(e.target.checked)}
                    className="mt-0.5 h-4 w-4 shrink-0 rounded border-border"
                />
                <span className="font-medium text-foreground">{f.label}</span>
                {error ? <span className="sr-only">{error}</span> : null}
            </label>
        );
    }
    if (f.type === 'select') {
        return (
            <Field
                label={f.label}
                required={f.required}
                hint={f.hint}
                error={error}
            >
                <SelectInput
                    value={String(value ?? '')}
                    onChange={onChange}
                    placeholder="Select…"
                    options={(f.options ?? []).map((o) => ({
                        value: o,
                        label: o,
                    }))}
                />
            </Field>
        );
    }
    if (f.type === 'textarea') {
        return (
            <Field
                label={f.label}
                required={f.required}
                hint={f.hint}
                error={error}
            >
                <Textarea
                    rows={3}
                    value={String(value ?? '')}
                    onChange={(e) => onChange(e.target.value)}
                    placeholder={f.placeholder}
                />
            </Field>
        );
    }
    return (
        <Field
            label={f.label}
            required={f.required}
            hint={f.hint}
            error={error}
        >
            <Input
                type={f.type === 'date' ? 'date' : 'text'}
                value={String(value ?? '')}
                onChange={(e) => onChange(e.target.value)}
                placeholder={f.placeholder}
                aria-invalid={!!error}
            />
        </Field>
    );
}

function isFilled(v: unknown): boolean {
    if (typeof v === 'boolean') return v;
    return v !== '' && v != null;
}
