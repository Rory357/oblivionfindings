import { ConfirmDialog } from '@/components/confirm-dialog';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { WizardShell } from '@/components/wizard/shell';
import { formatDateOnly } from '@/lib/datetime';
import { generatePassword } from '@/lib/password-generator';
import { checkPasswordStrength } from '@/lib/password-strength';
import { router } from '@inertiajs/react';
import {
    Check,
    Copy,
    Eye,
    EyeOff,
    History,
    KeyRound,
    Lock,
    Pencil,
    RefreshCcw,
} from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import type { CredentialPickerOption, SiteOption } from '../_dialog-shared';

export type CredentialRecord = {
    id: number;
    label: string;
    username?: string | null;
    url?: string | null;
    credential_type: string;
    vendor_id?: number | null;
    vendor_name?: string | null;
    notes?: string | null;
    requires_reauth: boolean;
    is_shareable: boolean;
    password_strength?: number | null;
    has_totp: boolean;
    last_rotated_at?: string | null;
    site_id?: number;
    site_name?: string | null;
    site_type?: string | null;
    lock_version?: number;
    visibility?: string;
    house_staff_access?: boolean;
    retired_at?: string | null;
    rotation_kind?: string | null;
    rotation_verified_by?: string | null;
    can_reveal?: boolean;
    can_copy?: boolean;
    can_manage?: boolean;
    can_audit?: boolean;
};
export type CredentialVendorOption = {
    id: number;
    site_id: number;
    company_name: string;
    service_type?: string | null;
};
export type CredentialFormValues = {
    label: string;
    username: string;
    url: string;
    credential_type: string;
    value: string;
    notes: string;
    vendor_id: number | null;
    requires_reauth: boolean;
    is_shareable: boolean;
    password_strength: number | null;
    totp_secret: string;
    visibility?: string;
    house_staff_access?: boolean;
    lock_version?: number;
};
type EditorProps = {
    siteId?: number;
    lockedSite?: SiteOption | null;
    sites?: SiteOption[];
    vendors?: CredentialVendorOption[];
    typeOptions?: CredentialPickerOption[];
    isOpen: boolean;
    onClose: () => void;
    credential?: CredentialRecord | null;
};

async function vaultRequest(
    url: string,
    body?: Record<string, unknown>,
    method = 'POST',
): Promise<Record<string, unknown>> {
    const response = await fetch(url, {
        method,
        credentials: 'same-origin',
        cache: 'no-store',
        headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            'X-XSRF-TOKEN': decodeURIComponent(
                document.cookie.match(/XSRF-TOKEN=([^;]+)/)?.[1] ?? '',
            ),
        },
        ...(method === 'GET' ? {} : { body: JSON.stringify(body ?? {}) }),
    });
    if (
        response.redirected ||
        !response.headers.get('content-type')?.includes('application/json')
    ) {
        throw new Error(
            'The result could not be confirmed. Sign in again and check the saved record before retrying.',
        );
    }
    if (!response.ok) {
        const data = (await response.json().catch(() => ({}))) as {
            errors?: Record<string, string[]>;
            message?: string;
        };
        throw new Error(
            Object.values(data.errors ?? {})
                .flat()
                .join(' ') ||
                (response.status === 401 || response.status === 419
                    ? 'Your session expired. Sign in and reopen this credential.'
                    : response.status === 404
                      ? 'This credential is no longer available with your current access.'
                      : data.message || 'The request could not be completed.'),
        );
    }
    return response.json();
}
const base = (siteId: number, credential: CredentialRecord) =>
    '/sites/' + siteId + '/credentials/' + credential.id;

export function AddCredentialDialog(props: EditorProps) {
    return props.isOpen ? <CredentialEditor {...props} /> : null;
}
export function EditCredentialDialog(props: EditorProps) {
    return props.isOpen && props.credential ? (
        <CredentialEditor {...props} />
    ) : null;
}
function CredentialEditor({
    credential: c,
    siteId,
    lockedSite,
    sites = [],
    vendors = [],
    typeOptions = [],
    onClose,
}: EditorProps) {
    const [creationKey] = useState(() => crypto.randomUUID());
    const [site, setSite] = useState(String(siteId ?? c?.site_id ?? ''));
    const [data, setData] = useState<CredentialFormValues>({
        label: c?.label ?? '',
        username: c?.username ?? '',
        url: c?.url ?? '',
        credential_type: c?.credential_type ?? 'password',
        value: '',
        notes: c?.notes ?? '',
        vendor_id: c?.vendor_id ?? null,
        requires_reauth: true,
        is_shareable: c?.is_shareable ?? false,
        password_strength: c?.password_strength ?? null,
        totp_secret: '',
        visibility: c?.visibility ?? 'site',
        house_staff_access: c?.house_staff_access ?? false,
        lock_version: c?.lock_version ?? 1,
    });
    const [initial] = useState(JSON.stringify(data));
    const dirty = JSON.stringify(data) !== initial;
    const [step, setStep] = useState(0);
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState('');
    const [discard, setDiscard] = useState(false);
    const [concealed, setConcealed] = useState(false);
    const [strength, setStrength] = useState('');
    const selectedSite =
        lockedSite ?? sites.find((item) => item.id === Number(site));
    useEffect(() => {
        let current = true;
        if (!data.value) {
            setStrength('');
            return;
        }
        void checkPasswordStrength(data.value).then((result) => {
            if (!current) return;
            setStrength(result.label);
            setData((value) => ({ ...value, password_strength: result.score }));
        });
        return () => {
            current = false;
        };
    }, [data.value]);
    const clearSecrets = () =>
        setData((d) => ({ ...d, value: '', totp_secret: '' }));
    useEffect(() => {
        const leave = (event: BeforeUnloadEvent) => {
            if (dirty) event.preventDefault();
        };
        const hide = () => {
            if (document.hidden) {
                clearSecrets();
                setConcealed(true);
            }
        };
        window.addEventListener('beforeunload', leave);
        document.addEventListener('visibilitychange', hide);
        return () => {
            window.removeEventListener('beforeunload', leave);
            document.removeEventListener('visibilitychange', hide);
        };
    }, [dirty]);
    const save = async () => {
        setBusy(true);
        setError('');
        try {
            await vaultRequest(
                c ? base(Number(site), c) : '/sites/' + site + '/credentials',
                { ...data, ...(!c ? { creation_key: creationKey } : {}) },
                c ? 'PUT' : 'POST',
            );
            clearSecrets();
            onClose();
            router.reload();
        } catch (e) {
            setError((e as Error).message);
        } finally {
            setBusy(false);
        }
    };
    const field = (
        key: 'label' | 'username' | 'url' | 'value' | 'totp_secret',
        label: string,
        type = 'text',
    ) => (
        <div className="space-y-1">
            <Label htmlFor={'vault-' + key}>{label}</Label>
            <Input
                id={'vault-' + key}
                type={type}
                autoComplete="off"
                value={data[key]}
                onChange={(e) => setData({ ...data, [key]: e.target.value })}
            />
        </div>
    );
    return (
        <>
            <WizardShell
                open
                onClose={() => !busy && (dirty ? setDiscard(true) : onClose())}
                title={c ? 'Edit credential' : 'Create credential'}
                description="Save one encrypted credential for Sites and IT."
                railIcon={Lock}
                railTitle="Shared access vault"
                railSub="Secrets stay out of drafts and history previews"
                steps={[
                    {
                        key: 'details',
                        label: 'Details',
                        blurb: 'Identity and site',
                        icon: KeyRound,
                    },
                    {
                        key: 'access',
                        label: 'Secret and access',
                        blurb: 'Explicit sharing and protection',
                        icon: Lock,
                    },
                    {
                        key: 'review',
                        label: 'Review',
                        blurb: 'Confirm without showing secrets',
                        icon: Check,
                    },
                ]}
                stepIndex={step}
                onStepClick={setStep}
                pct={Math.round(
                    ([site, data.label.trim(), c || data.value].filter(Boolean)
                        .length /
                        3) *
                        100,
                )}
                maxWidth="min(92vw, 900px)"
                footerEnd={
                    <>
                        <Button
                            variant="outline"
                            onClick={() =>
                                dirty ? setDiscard(true) : onClose()
                            }
                        >
                            Cancel
                        </Button>
                        {step < 2 ? (
                            <Button
                                type="button"
                                onClick={() => setStep(step + 1)}
                            >
                                Continue
                            </Button>
                        ) : (
                            <Button
                                disabled={
                                    busy ||
                                    !site ||
                                    !data.label.trim() ||
                                    (!c && !data.value)
                                }
                                onClick={save}
                            >
                                {busy ? 'Saving…' : 'Save credential'}
                            </Button>
                        )}
                    </>
                }
            >
                <div className="space-y-5 p-5">
                    {error && (
                        <p role="alert" className="text-status-critical">
                            {error}
                        </p>
                    )}
                    {concealed && (
                        <p role="status">
                            Secret input was cleared when you left this tab.
                            Re-enter it if needed.
                        </p>
                    )}
                    {step === 0 && (
                        <>
                            {c || siteId ? (
                                <p>
                                    Owning site:{' '}
                                    {c?.site_name ||
                                        sites.find((s) => String(s.id) === site)
                                            ?.name ||
                                        site}
                                </p>
                            ) : (
                                <Label>
                                    Owning site
                                    <select
                                        className="select mt-1 w-full"
                                        value={site}
                                        onChange={(e) => {
                                            setSite(e.target.value);
                                            setData((d) => ({
                                                ...d,
                                                vendor_id: null,
                                                house_staff_access: false,
                                            }));
                                        }}
                                    >
                                        <option value="">Choose site</option>
                                        {sites.map((s) => (
                                            <option key={s.id} value={s.id}>
                                                {s.name}
                                            </option>
                                        ))}
                                    </select>
                                </Label>
                            )}
                            {field('label', 'Credential label')}
                            {field('username', 'Username')}
                            {field('url', 'Service URL')}
                            <Label>
                                Type
                                <select
                                    className="select mt-1 w-full"
                                    value={data.credential_type}
                                    onChange={(e) =>
                                        setData({
                                            ...data,
                                            credential_type: e.target.value,
                                        })
                                    }
                                >
                                    {(typeOptions.length
                                        ? typeOptions
                                        : [
                                              {
                                                  key: 'password',
                                                  label: 'Password',
                                              },
                                              { key: 'pin', label: 'PIN' },
                                              {
                                                  key: 'api_key',
                                                  label: 'API key',
                                              },
                                          ]
                                    ).map((t) => (
                                        <option key={t.key} value={t.key}>
                                            {t.label}
                                        </option>
                                    ))}
                                </select>
                            </Label>
                            <Label>
                                Vendor
                                <select
                                    className="select mt-1 w-full"
                                    value={data.vendor_id ?? ''}
                                    onChange={(e) =>
                                        setData({
                                            ...data,
                                            vendor_id: e.target.value
                                                ? Number(e.target.value)
                                                : null,
                                        })
                                    }
                                >
                                    <option value="">No vendor link</option>
                                    {vendors
                                        .filter(
                                            (v) => v.site_id === Number(site),
                                        )
                                        .map((v) => (
                                            <option key={v.id} value={v.id}>
                                                {v.company_name}
                                            </option>
                                        ))}
                                </select>
                            </Label>
                        </>
                    )}
                    {step === 1 && (
                        <>
                            {field(
                                'value',
                                c
                                    ? 'Replacement secret (blank keeps current)'
                                    : 'Secret',
                                'password',
                            )}
                            {field(
                                'totp_secret',
                                'Existing service authenticator secret (optional)',
                                'password',
                            )}
                            {data.credential_type === 'password' && (
                                <div className="flex items-center gap-3">
                                    <Button
                                        type="button"
                                        variant="outline"
                                        onClick={() => {
                                            try {
                                                setData((current) => ({
                                                    ...current,
                                                    value: generatePassword(),
                                                }));
                                            } catch {
                                                setError(
                                                    'A password could not be generated. Enter a replacement manually.',
                                                );
                                            }
                                        }}
                                    >
                                        Generate password
                                    </Button>
                                    {data.value && (
                                        <span className="text-subtle">
                                            Estimated strength: {strength}
                                        </span>
                                    )}
                                </div>
                            )}
                            <Label>
                                Visibility
                                <select
                                    className="select mt-1 w-full"
                                    value={data.visibility}
                                    onChange={(e) =>
                                        setData({
                                            ...data,
                                            visibility: e.target.value,
                                        })
                                    }
                                >
                                    <option value="site">This site only</option>
                                    <option value="all_approved_sites">
                                        All approved sites
                                    </option>
                                </select>
                            </Label>
                            {selectedSite?.type === 'house' && (
                                <Label className="flex items-start gap-3">
                                    <Checkbox
                                        checked={data.house_staff_access}
                                        onCheckedChange={(v) =>
                                            setData({
                                                ...data,
                                                house_staff_access: v === true,
                                            })
                                        }
                                    />
                                    Allow current staff assigned to this house
                                    to reveal and copy after identity
                                    confirmation
                                </Label>
                            )}
                            <p className="text-subtle">
                                Historical shareable markers grant no access.
                                Contract access is separate. Identity
                                confirmation is always required. Service
                                authenticator secrets must come from the
                                external service.
                            </p>
                            <Label>
                                Usage notes (no secrets)
                                <Textarea
                                    value={data.notes}
                                    onChange={(e) =>
                                        setData({
                                            ...data,
                                            notes: e.target.value,
                                        })
                                    }
                                />
                            </Label>
                        </>
                    )}
                    {step === 2 && (
                        <dl className="grid grid-cols-2 gap-3">
                            <dt>Label</dt>
                            <dd>{data.label}</dd>
                            <dt>Secret</dt>
                            <dd>
                                {data.value
                                    ? 'New encrypted value supplied'
                                    : 'Existing value retained'}
                            </dd>
                            <dt>Visibility</dt>
                            <dd>
                                {data.visibility === 'site'
                                    ? 'Site only'
                                    : 'All approved sites'}
                            </dd>
                            <dt>House staff access</dt>
                            <dd>
                                {data.house_staff_access
                                    ? 'Explicitly enabled for assigned house staff'
                                    : 'Not enabled'}
                            </dd>
                            <dt>External rotation</dt>
                            <dd>Not attested by saving this form</dd>
                        </dl>
                    )}
                </div>
            </WizardShell>
            <ConfirmDialog
                open={discard}
                onClose={() => setDiscard(false)}
                onConfirm={() => {
                    clearSecrets();
                    onClose();
                }}
                title="Discard unsaved credential?"
                description="Secret input is not saved in a draft and will be removed."
                confirmText="Discard changes"
            />
        </>
    );
}

type ShowProps = {
    siteId: number;
    credential: CredentialRecord | null;
    isOpen: boolean;
    canManage: boolean;
    canReveal: boolean;
    onClose: () => void;
    onEdit?: () => void;
    onDelete?: () => void;
    onRemoveTotp?: () => void;
    onHistory?: () => void;
};
export function ShowCredentialDialog(props: ShowProps) {
    return props.isOpen && props.credential ? (
        <CredentialViewer
            key={props.credential.id}
            {...props}
            credential={props.credential}
        />
    ) : null;
}
function CredentialViewer({
    siteId,
    credential: initial,
    onClose,
    onEdit,
    canManage,
    canReveal,
}: ShowProps & { credential: CredentialRecord }) {
    const [c, setC] = useState(initial);
    const [value, setValue] = useState<string | null>(null);
    const [code, setCode] = useState<string | null>(null);
    const [password, setPassword] = useState('');
    const [verification, setVerification] = useState('');
    const [message, setMessage] = useState('');
    const [error, setError] = useState('');
    const [busy, setBusy] = useState(false);
    const [available, setAvailable] = useState(false);
    const [action, setAction] = useState('');
    const [history, setHistory] = useState<
        { id: number; version: number; action: string }[]
    >([]);
    const [historyLoaded, setHistoryLoaded] = useState(false);
    const [historyBusy, setHistoryBusy] = useState(false);
    const epoch = useRef(0);
    const timer = useRef<ReturnType<typeof setTimeout> | null>(null);
    const conceal = () => {
        epoch.current++;
        setValue(null);
        setCode(null);
        setPassword('');
        setVerification('');
        if (timer.current) clearTimeout(timer.current);
    };
    useEffect(() => {
        const hide = () => conceal();
        const check = async () => {
            try {
                const fresh = (await vaultRequest(
                    base(siteId, initial) + '/status',
                    undefined,
                    'GET',
                )) as unknown as CredentialRecord;
                if (
                    fresh.lock_version !== initial.lock_version ||
                    fresh.retired_at ||
                    fresh.can_reveal !== initial.can_reveal ||
                    fresh.can_copy !== initial.can_copy ||
                    fresh.can_manage !== initial.can_manage ||
                    fresh.can_audit !== initial.can_audit
                ) {
                    conceal();
                    if (!fresh.can_audit && !fresh.can_manage) setHistory([]);
                    if (!fresh.can_manage) setAction('');
                }
                setC(fresh);
                setAvailable(true);
            } catch {
                conceal();
                setAvailable(false);
                setHistory([]);
                setAction('');
                setError(
                    'Access or session changed. Close and reopen the credential after signing in.',
                );
            }
        };
        const poll = setInterval(() => void check(), 10000);
        void check();
        document.addEventListener('visibilitychange', hide);
        window.addEventListener('blur', hide);
        window.addEventListener('pagehide', hide);
        const off = router.on('before', hide);
        return () => {
            epoch.current++;
            clearInterval(poll);
            if (timer.current) clearTimeout(timer.current);
            off();
            document.removeEventListener('visibilitychange', hide);
            window.removeEventListener('blur', hide);
            window.removeEventListener('pagehide', hide);
        };
    }, [siteId, initial]);
    const auth = () => ({ password, verification_code: verification });
    const disclose = async (kind: 'reveal' | 'copy' | 'totp/code') => {
        setBusy(true);
        setError('');
        setMessage('');
        const generation = ++epoch.current;
        try {
            const result = await vaultRequest(
                base(siteId, c) + '/' + kind,
                auth(),
            );
            setPassword('');
            setVerification('');
            if (generation !== epoch.current || document.hidden) return;
            if (kind !== 'totp/code' && typeof result.value !== 'string')
                throw new Error('No credential value was returned.');
            if (
                kind === 'totp/code' &&
                (typeof result.code !== 'string' ||
                    !/^\d{6}$/.test(result.code))
            )
                throw new Error('No valid one-time code was returned.');
            if (kind === 'copy') {
                let outcome = 'failed';
                try {
                    await navigator.clipboard.writeText(String(result.value));
                    outcome = 'succeeded';
                    setMessage('Copied to clipboard.');
                } catch {
                    setError(
                        'Clipboard access failed. Nothing was copied by this action.',
                    );
                }
                try {
                    await vaultRequest(base(siteId, c) + '/copy-result', {
                        intent_id: result.intent_id,
                        outcome,
                    });
                } catch {
                    setError(
                        outcome === 'succeeded'
                            ? 'Copied, but the browser outcome could not be recorded. The authorized copy intent is retained.'
                            : 'Copy failed. The browser outcome could not be recorded.',
                    );
                }
            } else {
                if (kind === 'reveal') setValue(String(result.value));
                else setCode(String(result.code));
                if (timer.current) clearTimeout(timer.current);
                timer.current = setTimeout(
                    conceal,
                    kind === 'reveal'
                        ? 30000
                        : Math.min(30, Number(result.seconds_remaining)) * 1000,
                );
            }
        } catch (e) {
            conceal();
            setError((e as Error).message);
        } finally {
            setBusy(false);
        }
    };
    const showHistory = async () => {
        setError('');
        setHistoryBusy(true);
        try {
            const result = await vaultRequest(
                base(siteId, c) + (c.can_audit ? '/audit' : '/versions'),
                undefined,
                'GET',
            );
            setHistory((result.versions ?? []) as typeof history);
            setHistoryLoaded(true);
        } catch (e) {
            setError((e as Error).message);
        } finally {
            setHistoryBusy(false);
        }
    };
    const manage = c.can_manage ?? canManage;
    const reveal = c.can_reveal ?? canReveal;
    return (
        <Dialog open onOpenChange={(open) => !open && onClose()}>
            <DialogContent
                style={{ maxWidth: 'min(92vw, 720px)' }}
                className="max-h-[88vh] overflow-y-auto"
            >
                <DialogHeader>
                    <DialogTitle>{c.label}</DialogTitle>
                    <DialogDescription>
                        {c.site_name} · {c.credential_type} ·{' '}
                        {c.retired_at ? 'Retired' : 'Active'}
                    </DialogDescription>
                </DialogHeader>
                {error && (
                    <p role="alert" className="text-status-critical">
                        {error}
                    </p>
                )}
                {message && <p role="status">{message}</p>}
                <dl className="grid grid-cols-2 gap-3 text-sm">
                    <dt>Username</dt>
                    <dd>{c.username || '—'}</dd>
                    <dt>Vendor</dt>
                    <dd>{c.vendor_name || '—'}</dd>
                    <dt>Visibility</dt>
                    <dd>
                        {c.visibility === 'all_approved_sites'
                            ? 'All approved sites'
                            : 'Site only'}
                    </dd>
                    <dt>Last external attestation</dt>
                    <dd>
                        {formatDateOnly(c.last_rotated_at?.slice(0, 10))} ·{' '}
                        {c.rotation_kind?.replaceAll('_', ' ') ||
                            'Not recorded'}
                        {c.rotation_verified_by
                            ? ' · ' + c.rotation_verified_by
                            : ''}
                    </dd>
                </dl>
                <p className="text-subtle whitespace-pre-wrap">{c.notes}</p>
                {available && !c.retired_at && (reveal || c.can_copy) && (
                    <>
                        <StepUpFields
                            password={password}
                            verification={verification}
                            setPassword={setPassword}
                            setVerification={setVerification}
                        />
                        <div className="flex flex-wrap gap-3">
                            {reveal && (
                                <Button
                                    disabled={busy}
                                    onClick={() => void disclose('reveal')}
                                >
                                    <Eye className="size-4" />
                                    Reveal for 30 seconds
                                </Button>
                            )}
                            {c.can_copy && (
                                <Button
                                    variant="outline"
                                    disabled={busy}
                                    onClick={() => void disclose('copy')}
                                >
                                    <Copy className="size-4" />
                                    Copy secret
                                </Button>
                            )}
                            {c.has_totp && reveal && (
                                <Button
                                    variant="outline"
                                    disabled={busy}
                                    onClick={() => void disclose('totp/code')}
                                >
                                    <KeyRound className="size-4" />
                                    Show one-time code
                                </Button>
                            )}
                        </div>
                    </>
                )}
                {value !== null && (
                    <div className="rounded-lg border bg-muted p-4">
                        <p className="font-mono break-all">{value}</p>
                        <Button variant="ghost" onClick={conceal}>
                            <EyeOff className="size-4" />
                            Conceal now
                        </Button>
                    </div>
                )}
                {code !== null && (
                    <p className="rounded-lg border bg-muted p-4 font-mono">
                        {code}
                    </p>
                )}
                <div className="flex flex-wrap gap-3">
                    {available && manage && (
                        <>
                            {!c.retired_at && (
                                <Button
                                    variant="outline"
                                    onClick={() => {
                                        conceal();
                                        onEdit?.();
                                    }}
                                >
                                    <Pencil className="size-4" />
                                    Edit
                                </Button>
                            )}
                            <Button
                                variant="outline"
                                onClick={() => {
                                    conceal();
                                    setAction(
                                        c.retired_at ? 'restore' : 'retire',
                                    );
                                }}
                            >
                                {c.retired_at
                                    ? 'Restore credential'
                                    : 'Retire credential'}
                            </Button>
                            {!c.retired_at && (
                                <>
                                    <Button
                                        variant="outline"
                                        onClick={() => {
                                            conceal();
                                            setAction('rotate');
                                        }}
                                    >
                                        <RefreshCcw className="size-4" />
                                        Record external rotation
                                    </Button>
                                    {c.has_totp && (
                                        <Button
                                            variant="outline"
                                            onClick={() => {
                                                conceal();
                                                setAction('totp');
                                            }}
                                        >
                                            Remove authenticator
                                        </Button>
                                    )}
                                </>
                            )}
                        </>
                    )}
                    {(c.can_audit || manage) && (
                        <Button
                            variant="outline"
                            onClick={() => void showHistory()}
                            disabled={historyBusy}
                        >
                            <History className="size-4" />
                            {historyBusy
                                ? 'Loading history…'
                                : 'Encrypted version history'}
                        </Button>
                    )}
                    <Button variant="outline" onClick={onClose}>
                        Close
                    </Button>
                </div>
                {historyLoaded &&
                    history.length === 0 &&
                    (c.can_audit || manage) && (
                        <p className="text-subtle">
                            No retained versions yet. The next saved change will
                            preserve the current encrypted version.
                        </p>
                    )}
                {history.length > 0 && (
                    <ul className="space-y-2">
                        {history.map((v) => (
                            <li
                                key={v.id}
                                className="flex items-center justify-between gap-3"
                            >
                                <span>
                                    Version {v.version} ·{' '}
                                    {v.action.replaceAll('_', ' ')}
                                </span>
                                {manage && (
                                    <Button
                                        variant="outline"
                                        onClick={() =>
                                            setAction('recover:' + v.id)
                                        }
                                    >
                                        Recover stored value
                                    </Button>
                                )}
                            </li>
                        ))}
                    </ul>
                )}
                {action && (
                    <CredentialLifecycle
                        siteId={siteId}
                        credential={c}
                        action={action}
                        close={() => setAction('')}
                        done={onClose}
                    />
                )}
            </DialogContent>
        </Dialog>
    );
}
function StepUpFields({
    password,
    verification,
    setPassword,
    setVerification,
}: {
    password: string;
    verification: string;
    setPassword: (v: string) => void;
    setVerification: (v: string) => void;
}) {
    return (
        <div className="space-y-3 rounded-lg border p-4">
            <p className="text-subtle">
                Confirm your identity with your account password or a fresh code
                from your personal authenticator. SSO-only accounts use their
                personal authenticator, separate from this shared credential.
            </p>
            <Label>
                Account password
                <Input
                    type="password"
                    autoComplete="current-password"
                    value={password}
                    onChange={(e) => setPassword(e.target.value)}
                />
            </Label>
            <Label>
                Personal authenticator code
                <Input
                    inputMode="numeric"
                    autoComplete="one-time-code"
                    maxLength={6}
                    value={verification}
                    onChange={(e) => setVerification(e.target.value)}
                />
            </Label>
        </div>
    );
}
function CredentialLifecycle({
    siteId,
    credential: c,
    action,
    close,
    done,
}: {
    siteId: number;
    credential: CredentialRecord;
    action: string;
    close: () => void;
    done: () => void;
}) {
    const [evidence, setEvidence] = useState('');
    const [password, setPassword] = useState('');
    const [verification, setVerification] = useState('');
    const [changed, setChanged] = useState('');
    const [value, setValue] = useState('');
    const [kind, setKind] = useState('external_attestation');
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState('');
    const [discard, setDiscard] = useState(false);
    const dirty = Boolean(
        evidence || password || verification || changed || value,
    );
    const requestClose = () => !busy && (dirty ? setDiscard(true) : close());
    useEffect(() => {
        const hide = () => {
            setValue('');
            setPassword('');
            setVerification('');
        };
        const leave = (event: BeforeUnloadEvent) => {
            if (dirty) event.preventDefault();
        };
        window.addEventListener('blur', hide);
        window.addEventListener('pagehide', hide);
        document.addEventListener('visibilitychange', hide);
        window.addEventListener('beforeunload', leave);
        return () => {
            window.removeEventListener('blur', hide);
            window.removeEventListener('pagehide', hide);
            document.removeEventListener('visibilitychange', hide);
            window.removeEventListener('beforeunload', leave);
        };
    }, [dirty]);
    const title =
        action === 'rotate'
            ? 'Record external rotation'
            : action.startsWith('recover:')
              ? 'Recover an encrypted version'
              : action === 'totp'
                ? 'Remove service authenticator'
                : action === 'retire'
                  ? 'Retire credential'
                  : 'Restore credential';
    const save = async () => {
        setBusy(true);
        setError('');
        try {
            const suffix =
                action === 'retire'
                    ? ''
                    : action.startsWith('recover:')
                      ? '/recover'
                      : '/' + action;
            await vaultRequest(
                base(siteId, c) + suffix,
                {
                    lock_version: c.lock_version,
                    evidence,
                    password,
                    verification_code: verification,
                    changed_at: changed,
                    rotation_kind: kind,
                    value: value || null,
                    version_id: Number(action.split(':')[1]) || undefined,
                },
                action === 'retire' || action === 'totp' ? 'DELETE' : 'POST',
            );
            setValue('');
            setPassword('');
            setVerification('');
            done();
            router.reload();
        } catch (e) {
            setError((e as Error).message);
        } finally {
            setBusy(false);
        }
    };
    return (
        <Dialog open onOpenChange={(v) => !v && requestClose()}>
            <DialogContent style={{ maxWidth: 'min(92vw, 720px)' }}>
                <DialogHeader>
                    <DialogTitle>{title}</DialogTitle>
                    <DialogDescription>
                        Retained encrypted history stays protected. This action
                        does not change the external service automatically.
                    </DialogDescription>
                </DialogHeader>
                {error && (
                    <p role="alert" className="text-status-critical">
                        {error}
                    </p>
                )}
                {action === 'rotate' && (
                    <>
                        <Label>
                            Rotation evidence type
                            <select
                                className="select mt-1 w-full"
                                value={kind}
                                onChange={(e) => setKind(e.target.value)}
                            >
                                <option value="external_attestation">
                                    Attest an external change already completed
                                </option>
                                <option value="external_replacement">
                                    Store the replacement and attest the
                                    external change
                                </option>
                            </select>
                        </Label>
                        <Label>
                            When the external credential changed
                            <Input
                                type="datetime-local"
                                value={changed}
                                onChange={(e) => setChanged(e.target.value)}
                            />
                        </Label>
                        {kind === 'external_replacement' && (
                            <Label>
                                Replacement secret
                                <Input
                                    type="password"
                                    autoComplete="off"
                                    value={value}
                                    onChange={(e) => setValue(e.target.value)}
                                />
                            </Label>
                        )}
                    </>
                )}
                <Label>
                    Evidence and reason
                    <Textarea
                        value={evidence}
                        onChange={(e) => setEvidence(e.target.value)}
                        placeholder="Record the approved work or evidence reference. Do not enter a secret."
                    />
                </Label>
                <StepUpFields
                    password={password}
                    verification={verification}
                    setPassword={setPassword}
                    setVerification={setVerification}
                />
                <div className="flex justify-end gap-3">
                    <Button variant="outline" onClick={requestClose}>
                        Cancel
                    </Button>
                    <Button
                        disabled={busy || evidence.trim().length < 10}
                        onClick={save}
                    >
                        {busy ? 'Saving…' : title}
                    </Button>
                </div>
                <ConfirmDialog
                    open={discard}
                    onClose={() => setDiscard(false)}
                    onConfirm={close}
                    title="Discard unsaved credential action?"
                    description="Unsaved evidence and secret input will be removed."
                    confirmText="Discard changes"
                />
            </DialogContent>
        </Dialog>
    );
}
export function DeleteCredentialDialog({
    siteId,
    credential,
    isOpen,
    onClose,
}: {
    siteId: number;
    credential: CredentialRecord | null;
    isOpen: boolean;
    onClose: () => void;
}) {
    return isOpen && credential ? (
        <CredentialLifecycle
            siteId={siteId}
            credential={credential}
            action={credential.retired_at ? 'restore' : 'retire'}
            close={onClose}
            done={onClose}
        />
    ) : null;
}
export function RemoveTotpDialog({
    siteId,
    credential,
    isOpen,
    onClose,
}: {
    siteId: number;
    credential: CredentialRecord | null;
    isOpen: boolean;
    onClose: () => void;
}) {
    return isOpen && credential ? (
        <CredentialLifecycle
            siteId={siteId}
            credential={credential}
            action="totp"
            close={onClose}
            done={onClose}
        />
    ) : null;
}
