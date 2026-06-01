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
    History,
    KeyRound,
    Loader2,
    Lock,
    MapPin,
    Pencil,
    RefreshCcw,
    ShieldCheck,
    Trash2,
} from 'lucide-react';
import { useEffect, useState } from 'react';
import {
    CREDENTIAL_TYPE_META,
    credentialTypeIcon,
    credentialTypeLabel,
    DetailIconHeader,
    formatDate,
    LockedSiteCard,
    RotationBadge,
    rotationStatus,
    SitePickerField,
    type SiteOption,
    TilePicker,
    type TileOption,
} from '../_dialog-shared';

const CREDENTIAL_TYPES = [
    'password',
    'api_key',
    'ssh_key',
    'pin',
    'certificate',
    'oauth',
    'other',
] as const;

const CRED_TILES: TileOption[] = (
    ['password', 'pin', 'api_key', 'oauth', 'ssh_key', 'certificate', 'other'] as const
).map((key) => ({
    key,
    label: CREDENTIAL_TYPE_META[key].label,
    description: CREDENTIAL_TYPE_META[key].description,
    icon: CREDENTIAL_TYPE_META[key].icon,
}));

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
    site_id?: number;
    site_name?: string | null;
    site_type?: string | null;
};

function FieldError({ message }: { message?: string }) {
    if (!message) return null;
    return <p className="mt-1 text-xs text-status-critical">{message}</p>;
}

function secretWord(type: string) {
    return type === 'pin' ? 'code' : 'password';
}

// ── Add ───────────────────────────────────────────────────────────────────

export function AddCredentialDialog({
    siteId,
    lockedSite,
    sites,
    isOpen,
    onClose,
}: {
    siteId?: number;
    lockedSite?: SiteOption | null;
    sites?: SiteOption[];
    isOpen: boolean;
    onClose: () => void;
}) {
    return (
        <Dialog open={isOpen} onOpenChange={(open) => !open && onClose()}>
            <DialogContent style={{ maxWidth: 'min(92vw, 720px)' }}>
                {isOpen && (
                    <AddCredentialBody
                        siteId={siteId}
                        lockedSite={lockedSite}
                        sites={sites}
                        onClose={onClose}
                    />
                )}
            </DialogContent>
        </Dialog>
    );
}

function AddCredentialBody({
    siteId,
    lockedSite,
    sites,
    onClose,
}: {
    siteId?: number;
    lockedSite?: SiteOption | null;
    sites?: SiteOption[];
    onClose: () => void;
}) {
    const [pickedSiteId, setPickedSiteId] = useState<number | ''>('');
    const targetSiteId = siteId ?? (pickedSiteId === '' ? undefined : pickedSiteId);

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

    const [strength, setStrength] = useState<StrengthResult>(emptyPasswordStrength);

    useEffect(() => {
        let cancelled = false;
        checkPasswordStrength(form.data.value).then((result) => {
            if (!cancelled) setStrength(result);
        });
        return () => {
            cancelled = true;
        };
    }, [form.data.value]);

    const handleSubmit = (e: React.FormEvent) => {
        e.preventDefault();
        if (!targetSiteId) return;
        form.transform((data) => ({
            ...data,
            password_strength: data.value ? strength.score : null,
        }));
        form.post(`/sites/${targetSiteId}/credentials`, {
            preserveScroll: true,
            preserveState: true,
            onSuccess: () => onClose(),
        });
    };

    return (
        <form onSubmit={handleSubmit}>
            <DialogHeader>
                <DialogTitle className="flex items-center gap-2">
                    <Lock className="h-4 w-4 text-primary" />
                    Create credential
                </DialogTitle>
                <DialogDescription>
                    Credentials are encrypted at rest and every reveal is recorded in the audit
                    log.
                </DialogDescription>
            </DialogHeader>

            <div className="mt-3 space-y-3">
                {siteId ? (
                    lockedSite ? (
                        <LockedSiteCard site={lockedSite} note="Locked to the site you opened this from." />
                    ) : null
                ) : (
                    <SitePickerField
                        sites={sites ?? []}
                        value={pickedSiteId}
                        onChange={setPickedSiteId}
                    />
                )}
                <CredentialFormFields form={form} strength={strength} />
            </div>

            <DialogFooter className="mt-4">
                <Button type="button" variant="outline" onClick={onClose}>
                    Cancel
                </Button>
                <Button
                    type="submit"
                    disabled={
                        form.processing ||
                        !targetSiteId ||
                        !form.data.label.trim() ||
                        !form.data.value.trim()
                    }
                >
                    {form.processing && <Loader2 className="mr-2 h-4 w-4 animate-spin" />}
                    Save credential
                </Button>
            </DialogFooter>
        </form>
    );
}

// ── Edit ──────────────────────────────────────────────────────────────────

export function EditCredentialDialog({
    siteId,
    credential,
    lockedSite,
    isOpen,
    onClose,
}: {
    siteId: number;
    credential: CredentialRecord | null;
    lockedSite?: SiteOption | null;
    isOpen: boolean;
    onClose: () => void;
}) {
    return (
        <Dialog open={isOpen} onOpenChange={(open) => !open && onClose()}>
            <DialogContent style={{ maxWidth: 'min(92vw, 720px)' }}>
                {isOpen && credential && (
                    <EditCredentialBody
                        siteId={siteId}
                        credential={credential}
                        lockedSite={lockedSite}
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
    lockedSite,
    onClose,
}: {
    siteId: number;
    credential: CredentialRecord;
    lockedSite?: SiteOption | null;
    onClose: () => void;
}) {
    const form = useForm<CredentialFormValues>({
        label: credential.label,
        username: credential.username ?? '',
        url: credential.url ?? '',
        credential_type: (CREDENTIAL_TYPES as readonly string[]).includes(credential.credential_type)
            ? (credential.credential_type as CredentialFormValues['credential_type'])
            : 'password',
        value: '',
        notes: credential.notes ?? '',
        requires_reauth: credential.requires_reauth,
        is_shareable: credential.is_shareable,
        password_strength: credential.password_strength ?? null,
        totp_secret: '',
    });

    const [strength, setStrength] = useState<StrengthResult>(emptyPasswordStrength);

    useEffect(() => {
        let cancelled = false;
        checkPasswordStrength(form.data.value).then((result) => {
            if (!cancelled) setStrength(result);
        });
        return () => {
            cancelled = true;
        };
    }, [form.data.value]);

    const effectiveLockedSite =
        lockedSite ??
        (credential.site_name
            ? { id: credential.site_id ?? siteId, name: credential.site_name, type: credential.site_type ?? '' }
            : null);

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
                <DialogTitle className="flex items-center gap-2">
                    <Pencil className="h-4 w-4 text-primary" />
                    Edit credential
                </DialogTitle>
                <DialogDescription>
                    Leave the {secretWord(form.data.credential_type)} blank to keep the existing
                    value. Every reveal is audited.
                </DialogDescription>
            </DialogHeader>

            <div className="mt-3 space-y-3">
                {effectiveLockedSite ? (
                    <LockedSiteCard
                        site={effectiveLockedSite}
                        note="A credential stays with its site — create a new one to move it."
                    />
                ) : null}
                <CredentialFormFields form={form} strength={strength} edit />
            </div>

            <DialogFooter className="mt-4">
                <Button type="button" variant="outline" onClick={onClose}>
                    Cancel
                </Button>
                <Button type="submit" disabled={form.processing || !form.data.label.trim()}>
                    {form.processing && <Loader2 className="mr-2 h-4 w-4 animate-spin" />}
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
    onHistory,
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
    onHistory?: () => void;
}) {
    const [revealedValue, setRevealedValue] = useState<string | null>(null);
    const [revealing, setRevealing] = useState(false);
    const [revealError, setRevealError] = useState<string | null>(null);
    const [reauthOpen, setReauthOpen] = useState(false);
    const [password, setPassword] = useState('');
    const [copied, setCopied] = useState(false);

    useEffect(() => {
        if (!isOpen) {
            setRevealedValue(null);
            setRevealError(null);
            setReauthOpen(false);
            setPassword('');
            setCopied(false);
        }
    }, [isOpen, credential?.id]);

    const doReveal = async (suppliedPassword?: string) => {
        if (!credential) return;
        setRevealing(true);
        setRevealError(null);
        try {
            const xsrf = decodeURIComponent(
                document.cookie.match(/XSRF-TOKEN=([^;]+)/)?.[1] ?? '',
            );
            const res = await fetch(`/sites/${siteId}/credentials/${credential.id}/reveal`, {
                method: 'POST',
                credentials: 'include',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-XSRF-TOKEN': xsrf,
                },
                body: suppliedPassword ? JSON.stringify({ password: suppliedPassword }) : undefined,
            });
            if (!res.ok) {
                let message = `HTTP ${res.status}`;
                try {
                    const body = (await res.json()) as { error?: string };
                    if (body?.error) message = body.error;
                } catch {
                    // non-JSON error body; keep the status message
                }
                throw new Error(message);
            }
            const data = (await res.json()) as { value: string };
            setRevealedValue(data.value);
            setReauthOpen(false);
            setPassword('');
            window.setTimeout(() => setRevealedValue(null), 30000);
        } catch (e) {
            setRevealError(e instanceof Error ? e.message : 'Could not reveal credential.');
        } finally {
            setRevealing(false);
        }
    };

    const handleRevealClick = () => {
        if (!credential) return;
        if (credential.requires_reauth) {
            setReauthOpen(true);
            return;
        }
        void doReveal();
    };

    const handleCopyPassword = async () => {
        if (!credential) return;
        try {
            const xsrf = decodeURIComponent(
                document.cookie.match(/XSRF-TOKEN=([^;]+)/)?.[1] ?? '',
            );
            await fetch(`/sites/${siteId}/credentials/${credential.id}/copy`, {
                method: 'POST',
                credentials: 'include',
                headers: {
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-XSRF-TOKEN': xsrf,
                },
            });
        } catch {
            // copy audit is best-effort
        }
        if (revealedValue) {
            try {
                await navigator.clipboard.writeText(revealedValue);
                setCopied(true);
                window.setTimeout(() => setCopied(false), 1500);
            } catch {
                // clipboard may be blocked
            }
        }
    };

    if (!credential) return null;
    const rot = rotationStatus(credential.last_rotated_at);
    const strengthLabel = strengthLabelFromScore(credential.password_strength);
    const word = secretWord(credential.credential_type);
    const TypeIcon = credentialTypeIcon(credential.credential_type);
    const revealLabel = credential.requires_reauth
        ? 'Re-authenticate & reveal'
        : credential.credential_type === 'pin'
          ? 'Show code'
          : 'Show password';

    return (
        <Dialog open={isOpen} onOpenChange={(open) => !open && onClose()}>
            <DialogContent style={{ maxWidth: 'min(92vw, 600px)' }}>
                <DialogHeader>
                    <DialogTitle className="sr-only">{credential.label}</DialogTitle>
                    <DialogDescription className="sr-only">
                        Stored {credentialTypeLabel(credential.credential_type)} credential details.
                    </DialogDescription>
                    <DetailIconHeader
                        icon={TypeIcon}
                        title={credential.label}
                        subtitle={
                            <>
                                <span>{credentialTypeLabel(credential.credential_type)}</span>
                                {credential.site_name ? (
                                    <>
                                        <span>·</span>
                                        <span className="inline-flex items-center gap-1">
                                            <MapPin className="h-3 w-3" />
                                            {credential.site_name}
                                        </span>
                                    </>
                                ) : null}
                            </>
                        }
                    />
                </DialogHeader>

                <div className="mt-3 flex flex-wrap gap-2">
                    {credential.requires_reauth && (
                        <Badge
                            variant="outline"
                            className="gap-1 border-status-warning/30 bg-status-warning-bg text-status-warning"
                        >
                            <ShieldCheck className="h-3 w-3" />
                            Re-auth to reveal
                        </Badge>
                    )}
                    {credential.has_totp && (
                        <Badge
                            variant="outline"
                            className="gap-1 border-status-success/30 bg-status-success-bg text-status-success"
                        >
                            <KeyRound className="h-3 w-3" />
                            Authenticator linked
                        </Badge>
                    )}
                    <RotationBadge lastRotatedAt={credential.last_rotated_at} />
                </div>

                <dl className="mt-4 grid grid-cols-3 gap-x-4 gap-y-3 text-sm">
                    {strengthLabel && (
                        <>
                            <dt className="text-muted-foreground">Strength</dt>
                            <dd className="col-span-2">
                                <Badge variant="outline" className={strengthBadgeClasses(strengthLabel.level)}>
                                    {strengthLabel.label}
                                </Badge>
                            </dd>
                        </>
                    )}
                    {credential.username && (
                        <>
                            <dt className="text-muted-foreground">Username</dt>
                            <dd className="col-span-2 flex items-center gap-2">
                                <span className="font-mono">{credential.username}</span>
                                <CopyButton text={credential.username} />
                            </dd>
                        </>
                    )}
                    <dt className="text-muted-foreground capitalize">{word}</dt>
                    <dd className="col-span-2">
                        {revealedValue ? (
                            <div className="flex items-center gap-2">
                                <span className="font-mono">{revealedValue}</span>
                                <Button
                                    type="button"
                                    variant="ghost"
                                    size="sm"
                                    onClick={() => setRevealedValue(null)}
                                    aria-label="Hide"
                                >
                                    <EyeOff className="h-4 w-4" />
                                </Button>
                                <Button
                                    type="button"
                                    variant="ghost"
                                    size="sm"
                                    onClick={handleCopyPassword}
                                    aria-label={`Copy ${word}`}
                                >
                                    <Copy className="h-4 w-4" />
                                    {copied && <span className="ml-1 text-xs">Copied</span>}
                                </Button>
                            </div>
                        ) : reauthOpen ? (
                            <div className="flex flex-col gap-2">
                                <p className="text-xs text-muted-foreground">
                                    Confirm your password to reveal this {word}.
                                </p>
                                <div className="flex items-center gap-2">
                                    <Input
                                        type="password"
                                        autoFocus
                                        value={password}
                                        onChange={(e) => setPassword(e.target.value)}
                                        onKeyDown={(e) => {
                                            if (e.key === 'Enter') {
                                                e.preventDefault();
                                                if (password) void doReveal(password);
                                            }
                                        }}
                                        placeholder="Your account password"
                                        className="max-w-[220px]"
                                        autoComplete="current-password"
                                    />
                                    <Button
                                        type="button"
                                        size="sm"
                                        disabled={!password || revealing}
                                        onClick={() => void doReveal(password)}
                                    >
                                        {revealing ? (
                                            <Loader2 className="mr-2 h-4 w-4 animate-spin" />
                                        ) : (
                                            <Eye className="mr-2 h-4 w-4" />
                                        )}
                                        Reveal
                                    </Button>
                                    <Button
                                        type="button"
                                        variant="ghost"
                                        size="sm"
                                        onClick={() => {
                                            setReauthOpen(false);
                                            setPassword('');
                                            setRevealError(null);
                                        }}
                                    >
                                        Cancel
                                    </Button>
                                </div>
                            </div>
                        ) : (
                            <Button
                                type="button"
                                variant="outline"
                                size="sm"
                                onClick={handleRevealClick}
                                disabled={!canReveal || revealing}
                            >
                                {revealing ? (
                                    <Loader2 className="mr-2 h-4 w-4 animate-spin" />
                                ) : (
                                    <Eye className="mr-2 h-4 w-4" />
                                )}
                                {revealLabel}
                            </Button>
                        )}
                        {revealError && <p className="mt-1 text-xs text-status-critical">{revealError}</p>}
                    </dd>
                    {credential.has_totp && canReveal && (
                        <>
                            <dt className="text-muted-foreground">One-time code</dt>
                            <dd className="col-span-2">
                                <CredentialTotpDisplay siteId={siteId} credentialId={credential.id} />
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
                                    className="truncate text-primary hover:underline"
                                >
                                    {credential.url}
                                </a>
                                <CopyButton text={credential.url} />
                            </dd>
                        </>
                    )}
                    {credential.vendor_name && (
                        <>
                            <dt className="text-muted-foreground">Linked vendor</dt>
                            <dd className="col-span-2">{credential.vendor_name}</dd>
                        </>
                    )}
                    <dt className="text-muted-foreground">Last rotated</dt>
                    <dd className="col-span-2">
                        {formatDate(credential.last_rotated_at)}
                        {rot.days != null && (
                            <span className="ml-1 text-xs text-muted-foreground">· {rot.days}d</span>
                        )}
                    </dd>
                    {credential.notes && (
                        <>
                            <dt className="text-muted-foreground">Notes</dt>
                            <dd className="col-span-2 whitespace-pre-wrap">{credential.notes}</dd>
                        </>
                    )}
                </dl>

                <DialogFooter className="mt-4 flex-wrap gap-2 sm:justify-between">
                    {onHistory ? (
                        <Button type="button" variant="ghost" size="sm" onClick={onHistory}>
                            <History className="mr-2 h-4 w-4" />
                            Reveal history
                        </Button>
                    ) : (
                        <span />
                    )}
                    <div className="flex flex-wrap items-center gap-2">
                        {canManage && credential.has_totp && (
                            <Button type="button" variant="outline" onClick={onRemoveTotp}>
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
                    </div>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

function strengthLabelFromScore(score?: number | null) {
    if (score === null || score === undefined) return null;
    const labels = ['Very weak', 'Weak', 'Fair', 'Good', 'Very strong'] as const;
    const levels = ['very-weak', 'weak', 'fair', 'good', 'strong'] as const;
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
        <Button type="button" variant="ghost" size="sm" onClick={handleCopy} aria-label="Copy">
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
            <DialogContent style={{ maxWidth: 'min(92vw, 460px)' }}>
                <DialogHeader>
                    <DialogTitle>Delete credential?</DialogTitle>
                    <DialogDescription>
                        {credential && (
                            <>
                                <span className="font-medium">{credential.label}</span> will be
                                removed and anyone relying on this {secretWord(credential.credential_type)} will
                                lose access. Audit history is retained. This cannot be undone.
                            </>
                        )}
                    </DialogDescription>
                </DialogHeader>
                <DialogFooter>
                    <Button variant="outline" onClick={onClose}>
                        Cancel
                    </Button>
                    <Button variant="destructive" onClick={handleDelete} disabled={submitting}>
                        {submitting && <Loader2 className="mr-2 h-4 w-4 animate-spin" />}
                        Delete credential
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
            <DialogContent style={{ maxWidth: 'min(92vw, 460px)' }}>
                <DialogHeader>
                    <DialogTitle>Remove authenticator?</DialogTitle>
                    <DialogDescription>
                        The TOTP secret will be permanently deleted. Anyone with access to this
                        credential will no longer see a one-time code.
                    </DialogDescription>
                </DialogHeader>
                <DialogFooter>
                    <Button variant="outline" onClick={onClose}>
                        Cancel
                    </Button>
                    <Button variant="destructive" onClick={handleRemove} disabled={submitting}>
                        {submitting && <Loader2 className="mr-2 h-4 w-4 animate-spin" />}
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
    const type = (form.data as CredentialFormValues).credential_type;
    const word = secretWord(type);

    const handleGenerate = () => {
        const generated = generatePassword({ length: 20 });
        form.setData('value', generated);
        setShowPassword(true);
    };

    return (
        <div className="space-y-3">
            <div>
                <Label htmlFor="c-label">
                    Name <span className="text-status-critical">*</span>
                </Label>
                <Input
                    id="c-label"
                    value={form.data.label}
                    onChange={(e) => form.setData('label', e.target.value)}
                    placeholder="e.g. Front Door Smart Lock"
                    required
                />
                <FieldError message={form.errors.label} />
            </div>

            <div>
                <Label>Category</Label>
                <div className="mt-1">
                    <TilePicker
                        options={CRED_TILES}
                        value={type}
                        onChange={(v) => form.setData('credential_type', v)}
                    />
                </div>
            </div>

            <div className="grid gap-3 sm:grid-cols-2">
                <div>
                    <Label htmlFor="c-user">Username</Label>
                    <Input
                        id="c-user"
                        value={form.data.username}
                        onChange={(e) => form.setData('username', e.target.value)}
                        autoComplete="off"
                        placeholder="optional"
                    />
                </div>
                <div>
                    <Label htmlFor="c-url">URL</Label>
                    <Input
                        id="c-url"
                        type="url"
                        value={form.data.url}
                        onChange={(e) => form.setData('url', e.target.value)}
                        placeholder="https://"
                    />
                    <FieldError message={form.errors.url} />
                </div>
            </div>

            <div>
                <div className="flex items-center justify-between">
                    <Label htmlFor="c-value" className="capitalize">
                        {word} {!edit && <span className="text-status-critical">*</span>}
                    </Label>
                    <Button type="button" variant="ghost" size="sm" onClick={handleGenerate}>
                        <RefreshCcw className="mr-1 h-3 w-3" />
                        Generate
                    </Button>
                </div>
                <div className="relative">
                    <Input
                        id="c-value"
                        type={showPassword ? 'text' : 'password'}
                        value={form.data.value}
                        onChange={(e) => form.setData('value', e.target.value)}
                        placeholder={edit ? 'Leave blank to keep existing' : ''}
                        autoComplete="new-password"
                        className="pr-10"
                    />
                    {/* eslint-disable-next-line no-restricted-syntax -- inline input affordance, not a Button */}
                    <button
                        type="button"
                        className="absolute right-2 top-1/2 -translate-y-1/2 text-muted-foreground hover:text-foreground"
                        onClick={() => setShowPassword((v) => !v)}
                        aria-label={showPassword ? 'Hide' : 'Show'}
                    >
                        {showPassword ? <EyeOff className="h-4 w-4" /> : <Eye className="h-4 w-4" />}
                    </button>
                </div>
                {form.data.value && (
                    <div className="mt-1 flex items-center gap-2 text-xs">
                        <Badge variant="outline" className={strengthBadgeClasses(strength.level)}>
                            {strength.label}
                        </Badge>
                        {strength.feedback && (
                            <span className="text-muted-foreground">{strength.feedback}</span>
                        )}
                    </div>
                )}
                <FieldError message={form.errors.value} />
            </div>

            <div>
                <div className="flex items-center justify-between">
                    <Label htmlFor="c-totp">Authenticator (TOTP)</Label>
                    {form.data.totp_secret && (
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
                    value={form.data.totp_secret}
                    onChange={(e) =>
                        form.setData('totp_secret', e.target.value.replace(/\s+/g, '').toUpperCase())
                    }
                    placeholder={
                        edit
                            ? 'Paste secret to replace, or leave blank to keep current'
                            : 'Enter Base32 secret key'
                    }
                    autoComplete="off"
                    inputMode="text"
                    className="font-mono tracking-wide"
                />
                <p className="mt-1 text-xs text-muted-foreground">
                    Paste the Base32 secret from the external service. Oblivion becomes the
                    authenticator and shows the live 6-digit code.
                </p>
                <FieldError message={form.errors.totp_secret} />
            </div>

            <div className="flex flex-wrap items-center gap-x-6 gap-y-2 pt-1">
                <div className="flex items-center gap-2">
                    <Checkbox
                        id="c-reauth"
                        checked={form.data.requires_reauth}
                        onCheckedChange={(v) => form.setData('requires_reauth', !!v)}
                    />
                    <Label htmlFor="c-reauth" className="text-sm font-normal">
                        Require re-auth to reveal
                    </Label>
                </div>
                <div className="flex items-center gap-2">
                    <Checkbox
                        id="c-shareable"
                        checked={form.data.is_shareable}
                        onCheckedChange={(v) => form.setData('is_shareable', !!v)}
                    />
                    <Label htmlFor="c-shareable" className="text-sm font-normal">
                        Allow sharing outside this site
                    </Label>
                </div>
            </div>

            <div>
                <Label htmlFor="c-notes">Notes</Label>
                <Textarea
                    id="c-notes"
                    rows={3}
                    value={form.data.notes}
                    onChange={(e) => form.setData('notes', e.target.value)}
                    placeholder="Rotation cadence, who can access, etc."
                />
            </div>
        </div>
    );
}
