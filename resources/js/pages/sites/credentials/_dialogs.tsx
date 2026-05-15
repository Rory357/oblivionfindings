import { CredentialTotpDisplay } from '@/components/credential-totp-display';
import { Badge } from '@/components/ui/badge';
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
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Textarea } from '@/components/ui/textarea';
import { generatePassword } from '@/lib/password-generator';
import {
    checkPasswordStrength,
    emptyPasswordStrength,
    strengthBadgeClasses,
    type StrengthResult,
} from '@/lib/password-strength';
import { router, useForm } from '@inertiajs/react';
import {
    Copy,
    Eye,
    EyeOff,
    KeyRound,
    Loader2,
    Pencil,
    RefreshCcw,
    ShieldCheck,
    Trash2,
} from 'lucide-react';
import { useEffect, useState } from 'react';

const CREDENTIAL_TYPES = [
    'password',
    'api_key',
    'ssh_key',
    'pin',
    'certificate',
    'oauth',
    'other',
] as const;

export type CredentialFormValues = {
    label: string;
    username: string;
    url: string;
    credential_type: (typeof CREDENTIAL_TYPES)[number];
    value: string;
    notes: string;
    requires_reauth: boolean;
    is_shareable: boolean;
    password_strength: number | null;
    totp_secret: string;
};

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
};

function FieldError({ message }: { message?: string }) {
    if (!message) return null;
    return <p className="mt-1 text-xs text-status-critical">{message}</p>;
}

// ── Add ───────────────────────────────────────────────────────────────────

export function AddCredentialDialog({
    siteId,
    isOpen,
    onClose,
}: {
    siteId: number;
    isOpen: boolean;
    onClose: () => void;
}) {
    return (
        <Dialog open={isOpen} onOpenChange={(open) => !open && onClose()}>
            <DialogContent className="max-w-xl">
                {isOpen && (
                    <AddCredentialBody siteId={siteId} onClose={onClose} />
                )}
            </DialogContent>
        </Dialog>
    );
}

function AddCredentialBody({
    siteId,
    onClose,
}: {
    siteId: number;
    onClose: () => void;
}) {
    const form = useForm<CredentialFormValues>({
        label: '',
        username: '',
        url: '',
        credential_type: 'password',
        value: '',
        notes: '',
        requires_reauth: false,
        is_shareable: false,
        password_strength: null,
        totp_secret: '',
    });

    const [strength, setStrength] = useState<StrengthResult>(
        emptyPasswordStrength,
    );

    useEffect(() => {
        let cancelled = false;

        checkPasswordStrength(form.data.value).then((result) => {
            if (!cancelled) {
                setStrength(result);
            }
        });

        return () => {
            cancelled = true;
        };
    }, [form.data.value]);

    const handleSubmit = (e: React.FormEvent) => {
        e.preventDefault();
        // Persist the computed strength score along with the password.
        form.transform((data) => ({
            ...data,
            password_strength: data.value ? strength.score : null,
        }));
        form.post(`/sites/${siteId}/credentials`, {
            preserveScroll: true,
            preserveState: true,
            onSuccess: () => onClose(),
        });
    };

    return (
        <form onSubmit={handleSubmit}>
            <DialogHeader>
                <DialogTitle>Create Password</DialogTitle>
                <DialogDescription>
                    Credentials are encrypted at rest and every reveal is
                    audited.
                </DialogDescription>
            </DialogHeader>

            <CredentialFormFields form={form} strength={strength} />

            <DialogFooter className="mt-4">
                <Button type="button" variant="outline" onClick={onClose}>
                    Cancel
                </Button>
                <Button
                    type="submit"
                    disabled={
                        form.processing ||
                        !form.data.label.trim() ||
                        !form.data.value.trim()
                    }
                >
                    {form.processing && (
                        <Loader2 className="mr-2 h-4 w-4 animate-spin" />
                    )}
                    Save
                </Button>
            </DialogFooter>
        </form>
    );
}

// ── Edit ──────────────────────────────────────────────────────────────────

export function EditCredentialDialog({
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
    return (
        <Dialog open={isOpen} onOpenChange={(open) => !open && onClose()}>
            <DialogContent className="max-w-xl">
                {isOpen && credential && (
                    <EditCredentialBody
                        siteId={siteId}
                        credential={credential}
                        onClose={onClose}
                    />
                )}
            </DialogContent>
        </Dialog>
    );
}

function EditCredentialBody({
    siteId,
    credential,
    onClose,
}: {
    siteId: number;
    credential: CredentialRecord;
    onClose: () => void;
}) {
    const form = useForm<CredentialFormValues>({
        label: credential.label,
        username: credential.username ?? '',
        url: credential.url ?? '',
        credential_type:
            (CREDENTIAL_TYPES as readonly string[]).includes(
                credential.credential_type,
            )
                ? (credential.credential_type as CredentialFormValues['credential_type'])
                : 'password',
        value: '', // empty = keep existing password
        notes: credential.notes ?? '',
        requires_reauth: credential.requires_reauth,
        is_shareable: credential.is_shareable,
        password_strength: credential.password_strength ?? null,
        // Always starts blank: typing a new secret replaces; leaving
        // it blank keeps the existing one. Removal is via the
        // dedicated Remove authenticator button on the Show dialog.
        totp_secret: '',
    });

    const [strength, setStrength] = useState<StrengthResult>(
        emptyPasswordStrength,
    );

    useEffect(() => {
        let cancelled = false;

        checkPasswordStrength(form.data.value).then((result) => {
            if (!cancelled) {
                setStrength(result);
            }
        });

        return () => {
            cancelled = true;
        };
    }, [form.data.value]);

    const handleSubmit = (e: React.FormEvent) => {
        e.preventDefault();
        form.transform((data) => ({
            ...data,
            password_strength: data.value ? strength.score : data.password_strength,
        }));
        form.put(`/sites/${siteId}/credentials/${credential.id}`, {
            preserveScroll: true,
            preserveState: true,
            onSuccess: () => onClose(),
        });
    };

    return (
        <form onSubmit={handleSubmit}>
            <DialogHeader>
                <DialogTitle>Edit credential</DialogTitle>
                <DialogDescription>
                    Leave the password blank to keep the existing value.
                </DialogDescription>
            </DialogHeader>

            <CredentialFormFields form={form} strength={strength} edit />

            <DialogFooter className="mt-4">
                <Button type="button" variant="outline" onClick={onClose}>
                    Cancel
                </Button>
                <Button
                    type="submit"
                    disabled={form.processing || !form.data.label.trim()}
                >
                    {form.processing && (
                        <Loader2 className="mr-2 h-4 w-4 animate-spin" />
                    )}
                    Save changes
                </Button>
            </DialogFooter>
        </form>
    );
}

// ── Show / Reveal ─────────────────────────────────────────────────────────

export function ShowCredentialDialog({
    siteId,
    credential,
    isOpen,
    canManage,
    canReveal,
    onClose,
    onEdit,
    onDelete,
    onRemoveTotp,
}: {
    siteId: number;
    credential: CredentialRecord | null;
    isOpen: boolean;
    canManage: boolean;
    canReveal: boolean;
    onClose: () => void;
    onEdit?: () => void;
    onDelete?: () => void;
    onRemoveTotp?: () => void;
}) {
    const [revealedValue, setRevealedValue] = useState<string | null>(null);
    const [revealing, setRevealing] = useState(false);
    const [revealError, setRevealError] = useState<string | null>(null);

    useEffect(() => {
        if (!isOpen) {
            setRevealedValue(null);
            setRevealError(null);
        }
    }, [isOpen, credential?.id]);

    const handleReveal = async () => {
        if (!credential) return;
        setRevealing(true);
        setRevealError(null);
        try {
            const xsrf = decodeURIComponent(
                document.cookie.match(/XSRF-TOKEN=([^;]+)/)?.[1] ?? '',
            );
            const res = await fetch(
                `/sites/${siteId}/credentials/${credential.id}/reveal`,
                {
                    method: 'POST',
                    credentials: 'include',
                    headers: {
                        'Content-Type': 'application/json',
                        Accept: 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                        'X-XSRF-TOKEN': xsrf,
                    },
                },
            );
            if (!res.ok) {
                const text = await res.text();
                throw new Error(text || `HTTP ${res.status}`);
            }
            const data = (await res.json()) as { value: string };
            setRevealedValue(data.value);
            window.setTimeout(() => setRevealedValue(null), 30000);
        } catch (e) {
            setRevealError(
                e instanceof Error
                    ? e.message
                    : 'Could not reveal credential.',
            );
        } finally {
            setRevealing(false);
        }
    };

    const handleCopyPassword = async () => {
        if (!credential) return;
        try {
            const xsrf = decodeURIComponent(
                document.cookie.match(/XSRF-TOKEN=([^;]+)/)?.[1] ?? '',
            );
            await fetch(
                `/sites/${siteId}/credentials/${credential.id}/copy`,
                {
                    method: 'POST',
                    credentials: 'include',
                    headers: {
                        Accept: 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                        'X-XSRF-TOKEN': xsrf,
                    },
                },
            );
        } catch {
            // copy audit is best-effort
        }
        if (revealedValue) {
            try {
                await navigator.clipboard.writeText(revealedValue);
            } catch {
                // clipboard may be blocked
            }
        }
    };

    if (!credential) return null;
    const strengthLabel = strengthLabelFromScore(credential.password_strength);

    return (
        <Dialog open={isOpen} onOpenChange={(open) => !open && onClose()}>
            <DialogContent className="max-w-xl">
                <DialogHeader>
                    <DialogTitle>{credential.label}</DialogTitle>
                    <DialogDescription>
                        {credential.credential_type}
                        {credential.has_totp && (
                            <Badge
                                variant="outline"
                                className="ml-2 border-status-success/30 text-status-success"
                            >
                                <KeyRound className="mr-1 h-3 w-3" />
                                Authenticator
                            </Badge>
                        )}
                    </DialogDescription>
                </DialogHeader>

                <dl className="grid grid-cols-3 gap-x-4 gap-y-2 text-sm">
                    {strengthLabel && (
                        <>
                            <dt className="text-muted-foreground">
                                Password strength
                            </dt>
                            <dd className="col-span-2">
                                <Badge
                                    variant="outline"
                                    className={strengthBadgeClasses(
                                        strengthLabel.level,
                                    )}
                                >
                                    {strengthLabel.label}
                                </Badge>
                            </dd>
                        </>
                    )}
                    {credential.username && (
                        <>
                            <dt className="text-muted-foreground">Username</dt>
                            <dd className="col-span-2 flex items-center gap-2">
                                <span className="font-mono">
                                    {credential.username}
                                </span>
                                <CopyButton text={credential.username} />
                            </dd>
                        </>
                    )}
                    <dt className="text-muted-foreground">Password</dt>
                    <dd className="col-span-2">
                        {revealedValue ? (
                            <div className="flex items-center gap-2">
                                <span className="font-mono">
                                    {revealedValue}
                                </span>
                                <Button
                                    type="button"
                                    variant="ghost"
                                    size="sm"
                                    onClick={() => setRevealedValue(null)}
                                    aria-label="Hide password"
                                >
                                    <EyeOff className="h-4 w-4" />
                                </Button>
                                <Button
                                    type="button"
                                    variant="ghost"
                                    size="sm"
                                    onClick={handleCopyPassword}
                                    aria-label="Copy password"
                                >
                                    <Copy className="h-4 w-4" />
                                </Button>
                            </div>
                        ) : (
                            <Button
                                type="button"
                                variant="outline"
                                size="sm"
                                onClick={handleReveal}
                                disabled={!canReveal || revealing}
                            >
                                {revealing ? (
                                    <Loader2 className="mr-2 h-4 w-4 animate-spin" />
                                ) : (
                                    <Eye className="mr-2 h-4 w-4" />
                                )}
                                Show Password
                            </Button>
                        )}
                        {revealError && (
                            <p className="mt-1 text-xs text-status-critical">
                                {revealError}
                            </p>
                        )}
                    </dd>
                    {credential.has_totp && canReveal && (
                        <>
                            <dt className="text-muted-foreground">
                                One-time code
                            </dt>
                            <dd className="col-span-2">
                                <CredentialTotpDisplay
                                    siteId={siteId}
                                    credentialId={credential.id}
                                />
                            </dd>
                        </>
                    )}
                    {credential.url && (
                        <>
                            <dt className="text-muted-foreground">URL</dt>
                            <dd className="col-span-2 flex items-center gap-2">
                                <a
                                    href={credential.url}
                                    target="_blank"
                                    rel="noreferrer noopener"
                                    className="text-primary hover:underline"
                                >
                                    {credential.url}
                                </a>
                                <CopyButton text={credential.url} />
                            </dd>
                        </>
                    )}
                    {credential.vendor_name && (
                        <>
                            <dt className="text-muted-foreground">Vendor</dt>
                            <dd className="col-span-2">
                                {credential.vendor_name}
                            </dd>
                        </>
                    )}
                    {credential.notes && (
                        <>
                            <dt className="text-muted-foreground">Notes</dt>
                            <dd className="col-span-2 whitespace-pre-wrap">
                                {credential.notes}
                            </dd>
                        </>
                    )}
                    {credential.last_rotated_at && (
                        <>
                            <dt className="text-muted-foreground">
                                Password changed
                            </dt>
                            <dd className="col-span-2">
                                {credential.last_rotated_at}
                            </dd>
                        </>
                    )}
                </dl>

                <DialogFooter className="mt-2 flex-wrap gap-2">
                    {canManage && credential.has_totp && (
                        <Button
                            type="button"
                            variant="outline"
                            onClick={onRemoveTotp}
                        >
                            <ShieldCheck className="mr-2 h-4 w-4" />
                            Remove authenticator
                        </Button>
                    )}
                    {canManage && onDelete && (
                        <Button
                            type="button"
                            variant="outline"
                            className="text-status-critical"
                            onClick={onDelete}
                        >
                            <Trash2 className="mr-2 h-4 w-4" />
                            Delete
                        </Button>
                    )}
                    <Button type="button" variant="outline" onClick={onClose}>
                        Close
                    </Button>
                    {canManage && onEdit && (
                        <Button type="button" onClick={onEdit}>
                            <Pencil className="mr-2 h-4 w-4" />
                            Edit
                        </Button>
                    )}
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

function strengthLabelFromScore(score?: number | null) {
    if (score === null || score === undefined) return null;
    const labels = ['Very weak', 'Weak', 'Fair', 'Good', 'Very strong'] as const;
    const levels = [
        'very-weak',
        'weak',
        'fair',
        'good',
        'strong',
    ] as const;
    const idx = Math.max(0, Math.min(4, score)) as 0 | 1 | 2 | 3 | 4;
    return { label: labels[idx], level: levels[idx] };
}

function CopyButton({ text }: { text: string }) {
    const handleCopy = async () => {
        try {
            await navigator.clipboard.writeText(text);
        } catch {
            // ignore
        }
    };
    return (
        <Button
            type="button"
            variant="ghost"
            size="sm"
            onClick={handleCopy}
            aria-label="Copy"
        >
            <Copy className="h-4 w-4" />
        </Button>
    );
}

// ── Delete confirm ────────────────────────────────────────────────────────

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
    const [submitting, setSubmitting] = useState(false);

    const handleDelete = () => {
        if (!credential) return;
        setSubmitting(true);
        router.delete(`/sites/${siteId}/credentials/${credential.id}`, {
            preserveScroll: true,
            preserveState: true,
            onFinish: () => setSubmitting(false),
            onSuccess: () => onClose(),
        });
    };

    return (
        <Dialog open={isOpen} onOpenChange={(open) => !open && onClose()}>
            <DialogContent className="max-w-md">
                <DialogHeader>
                    <DialogTitle>Delete credential?</DialogTitle>
                    <DialogDescription>
                        {credential && (
                            <>
                                <span className="font-medium">
                                    {credential.label}
                                </span>{' '}
                                will be removed and all associated audit history
                                will be retained. This cannot be undone.
                            </>
                        )}
                    </DialogDescription>
                </DialogHeader>
                <DialogFooter>
                    <Button variant="outline" onClick={onClose}>
                        Cancel
                    </Button>
                    <Button
                        variant="destructive"
                        onClick={handleDelete}
                        disabled={submitting}
                    >
                        {submitting && (
                            <Loader2 className="mr-2 h-4 w-4 animate-spin" />
                        )}
                        Delete
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}


// ── Remove TOTP confirm ───────────────────────────────────────────────────

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
    const [submitting, setSubmitting] = useState(false);

    const handleRemove = () => {
        if (!credential) return;
        setSubmitting(true);
        router.delete(`/sites/${siteId}/credentials/${credential.id}/totp`, {
            preserveScroll: true,
            preserveState: true,
            onFinish: () => setSubmitting(false),
            onSuccess: () => onClose(),
        });
    };

    return (
        <Dialog open={isOpen} onOpenChange={(open) => !open && onClose()}>
            <DialogContent className="max-w-md">
                <DialogHeader>
                    <DialogTitle>Remove authenticator?</DialogTitle>
                    <DialogDescription>
                        The TOTP secret will be permanently deleted. Anyone with
                        access to this credential will no longer see a one-time
                        code.
                    </DialogDescription>
                </DialogHeader>
                <DialogFooter>
                    <Button variant="outline" onClick={onClose}>
                        Cancel
                    </Button>
                    <Button
                        variant="destructive"
                        onClick={handleRemove}
                        disabled={submitting}
                    >
                        {submitting && (
                            <Loader2 className="mr-2 h-4 w-4 animate-spin" />
                        )}
                        Remove authenticator
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

// ── Shared field group ────────────────────────────────────────────────────

function CredentialFormFields({
    form,
    strength,
    edit,
}: {
    // Both Add and Edit reach this component; their form types are structural
    // supersets/subsets of the same shape. Use a permissive type to avoid
    // generic gymnastics.
    form: any;
    strength: StrengthResult;
    edit?: boolean;
}) {
    const [showPassword, setShowPassword] = useState(false);

    const handleGenerate = () => {
        const generated = generatePassword({ length: 20 });
        form.setData('value', generated);
        setShowPassword(true);
    };

    const fmt = (k: string) => k;

    return (
        <div className="space-y-3">
            <div>
                <Label htmlFor="c-label">
                    Name <span className="text-status-critical">*</span>
                </Label>
                <Input
                    id="c-label"
                    value={(form.data as any).label}
                    onChange={(e) => form.setData(fmt('label'), e.target.value as any)}
                    required
                />
                <FieldError message={(form.errors as any).label} />
            </div>
            <div className="grid gap-3 sm:grid-cols-2">
                <div>
                    <Label htmlFor="c-type">Category</Label>
                    <Select
                        value={(form.data as any).credential_type}
                        onValueChange={(v) =>
                            form.setData(fmt('credential_type'), v as any)
                        }
                    >
                        <SelectTrigger id="c-type">
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            {CREDENTIAL_TYPES.map((t) => (
                                <SelectItem key={t} value={t}>
                                    {t}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                </div>
                <div>
                    <Label htmlFor="c-user">Username</Label>
                    <Input
                        id="c-user"
                        value={(form.data as any).username}
                        onChange={(e) =>
                            form.setData(fmt('username'), e.target.value as any)
                        }
                        autoComplete="off"
                    />
                </div>
            </div>
            <div>
                <div className="flex items-center justify-between">
                    <Label htmlFor="c-value">
                        Password{' '}
                        {!edit && (
                            <span className="text-status-critical">*</span>
                        )}
                    </Label>
                    <Button
                        type="button"
                        variant="ghost"
                        size="sm"
                        onClick={handleGenerate}
                    >
                        <RefreshCcw className="mr-1 h-3 w-3" />
                        Generate
                    </Button>
                </div>
                <div className="relative">
                    <Input
                        id="c-value"
                        type={showPassword ? 'text' : 'password'}
                        value={(form.data as any).value}
                        onChange={(e) =>
                            form.setData(fmt('value'), e.target.value as any)
                        }
                        placeholder={
                            edit ? 'Leave blank to keep existing' : ''
                        }
                        autoComplete="new-password"
                        className="pr-10"
                    />
                    <button
                        type="button"
                        className="absolute right-2 top-1/2 -translate-y-1/2 text-muted-foreground hover:text-foreground"
                        onClick={() => setShowPassword((v) => !v)}
                        aria-label={
                            showPassword ? 'Hide password' : 'Show password'
                        }
                    >
                        {showPassword ? (
                            <EyeOff className="h-4 w-4" />
                        ) : (
                            <Eye className="h-4 w-4" />
                        )}
                    </button>
                </div>
                {(form.data as any).value && (
                    <div className="mt-1 flex items-center gap-2 text-xs">
                        <Badge
                            variant="outline"
                            className={strengthBadgeClasses(strength.level)}
                        >
                            {strength.label}
                        </Badge>
                        {strength.feedback && (
                            <span className="text-muted-foreground">
                                {strength.feedback}
                            </span>
                        )}
                    </div>
                )}
                <FieldError message={(form.errors as any).value} />
            </div>
            <div className="flex flex-wrap items-center gap-x-6 gap-y-2">
                <div className="flex items-center gap-2">
                    <Checkbox
                        id="c-shareable"
                        checked={(form.data as any).is_shareable}
                        onCheckedChange={(v) =>
                            form.setData(fmt('is_shareable'), !!v as any)
                        }
                    />
                    <Label
                        htmlFor="c-shareable"
                        className="text-sm font-normal"
                    >
                        Password Sharing — allow outside this site
                    </Label>
                </div>
                <div className="flex items-center gap-2">
                    <Checkbox
                        id="c-reauth"
                        checked={(form.data as any).requires_reauth}
                        onCheckedChange={(v) =>
                            form.setData(fmt('requires_reauth'), !!v as any)
                        }
                    />
                    <Label htmlFor="c-reauth" className="text-sm font-normal">
                        Require re-auth to reveal
                    </Label>
                </div>
            </div>
            <div>
                <div className="flex items-center justify-between">
                    <Label htmlFor="c-totp">One-time Password</Label>
                    {(form.data as any).totp_secret && (
                        <Badge
                            variant="outline"
                            className="border-status-success/30 text-status-success"
                        >
                            <KeyRound className="mr-1 h-3 w-3" />
                            Authenticator ready
                        </Badge>
                    )}
                </div>
                <Input
                    id="c-totp"
                    value={(form.data as any).totp_secret}
                    onChange={(e) =>
                        form.setData(
                            fmt('totp_secret'),
                            e.target.value
                                .replace(/\s+/g, '')
                                .toUpperCase() as any,
                        )
                    }
                    placeholder={
                        edit
                            ? 'Paste secret to replace, or leave blank to keep current'
                            : 'Enter text-based secret key'
                    }
                    autoComplete="off"
                    inputMode="text"
                    className="font-mono tracking-wide"
                />
                <p className="mt-1 text-xs text-muted-foreground">
                    Paste the Base32 secret from the external service. Oblivion
                    becomes the authenticator and shows the current 6-digit
                    code when you view this credential.
                </p>
                <FieldError message={(form.errors as any).totp_secret} />
            </div>
            <div>
                <Label htmlFor="c-url">URL</Label>
                <Input
                    id="c-url"
                    type="url"
                    value={(form.data as any).url}
                    onChange={(e) => form.setData(fmt('url'), e.target.value as any)}
                    placeholder="https://"
                />
                <FieldError message={(form.errors as any).url} />
            </div>
            <div>
                <Label htmlFor="c-notes">Notes</Label>
                <Textarea
                    id="c-notes"
                    rows={3}
                    value={(form.data as any).notes}
                    onChange={(e) => form.setData(fmt('notes'), e.target.value as any)}
                />
            </div>
        </div>
    );
}
