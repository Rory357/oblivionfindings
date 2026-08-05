import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Textarea } from '@/components/ui/textarea';
import axios from 'axios';
import { Check, Copy, KeyRound, ShieldX } from 'lucide-react';
import { useEffect, useState, type FormEvent } from 'react';

export type CollectorManagementSite = { id: number; name: string };

export type CollectorLifecycleTarget = {
    id: number;
    name: string;
    site: CollectorManagementSite | null;
    revoke_url: string | null;
    re_enrol_url: string | null;
};

type EnrollmentResult = {
    id: number;
    purpose: 'new_collector' | 'collector_re_enrolment';
    token: string;
    expires_at: string;
};

function failureMessage(error: unknown): string {
    if (axios.isAxiosError(error)) {
        const validation = error.response?.data?.errors;
        if (validation && typeof validation === 'object') {
            const messages = Object.values(
                validation as Record<string, unknown>,
            ).flatMap((value) => (Array.isArray(value) ? value : [value]));
            const first = messages.find(
                (value): value is string =>
                    typeof value === 'string' && value.length > 0,
            );
            if (typeof first === 'string') return first;
        }

        if (typeof error.response?.data?.message === 'string') {
            return error.response.data.message;
        }
    }

    return 'The collector workflow could not be completed. Review access and try again.';
}

export function CollectorEnrollmentDialog({
    open,
    onOpenChange,
    issueUrl,
    sites,
    replacement,
    onIssued,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    issueUrl: string;
    sites: CollectorManagementSite[];
    replacement: CollectorLifecycleTarget | null;
    onIssued: () => void;
}) {
    const [siteId, setSiteId] = useState('');
    const [result, setResult] = useState<EnrollmentResult | null>(null);
    const [submitting, setSubmitting] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const [copied, setCopied] = useState(false);

    useEffect(() => {
        if (!open) {
            setResult(null);
            setError(null);
            setCopied(false);
            setSiteId('');
            return;
        }

        setSiteId(
            replacement?.site?.id.toString() ??
                (sites.length === 1 ? sites[0].id.toString() : ''),
        );
    }, [open, replacement, sites]);

    const close = () => {
        setResult(null);
        setError(null);
        setCopied(false);
        onOpenChange(false);
    };

    const submit = async (event: FormEvent) => {
        event.preventDefault();
        const url = replacement?.re_enrol_url ?? issueUrl;
        if (!url || (!replacement && !siteId)) return;

        setSubmitting(true);
        setError(null);
        try {
            const response = await axios.post<{ enrollment: EnrollmentResult }>(
                url,
                replacement ? {} : { site_id: Number(siteId) },
            );
            setResult(response.data.enrollment);
            onIssued();
        } catch (requestError) {
            setError(failureMessage(requestError));
        } finally {
            setSubmitting(false);
        }
    };

    const copyToken = async () => {
        if (!result) return;

        try {
            await navigator.clipboard.writeText(result.token);
            setCopied(true);
        } catch {
            setError(
                'Copy was blocked by the browser. Select the token and copy it before closing.',
            );
        }
    };

    return (
        <Dialog
            open={open}
            onOpenChange={(next) => {
                if (!next) close();
            }}
        >
            <DialogContent className="sm:max-w-xl">
                <DialogHeader>
                    <DialogTitle>
                        {replacement
                            ? `Re-enrol ${replacement.name}`
                            : 'Enrol a remote collector'}
                    </DialogTitle>
                    <DialogDescription>
                        {replacement
                            ? 'Creates new one-time enrolment material for this revoked collector. Its former certificate and signing key remain revoked.'
                            : 'Use a collector only where the Site cannot be monitored reliably over the main SD-WAN path.'}
                    </DialogDescription>
                </DialogHeader>

                {result ? (
                    <div className="space-y-4">
                        <div
                            className="rounded-lg border border-status-warning/30 bg-status-warning-bg p-4"
                            role="status"
                        >
                            <div className="flex items-start gap-3">
                                <KeyRound className="mt-0.5 h-5 w-5 shrink-0" />
                                <div>
                                    <p className="font-semibold">
                                        Copy this token now
                                    </p>
                                    <p className="mt-1 text-sm">
                                        It is returned once, expires at{' '}
                                        {new Date(
                                            result.expires_at,
                                        ).toLocaleString('en-NZ')}
                                        . It is not retained after this dialog
                                        closes and cannot be requested again.
                                    </p>
                                </div>
                            </div>
                        </div>
                        <div className="space-y-2">
                            <Label htmlFor="collector-enrolment-token">
                                One-time enrolment token
                            </Label>
                            <Textarea
                                id="collector-enrolment-token"
                                value={result.token}
                                readOnly
                                rows={3}
                                className="font-mono text-xs"
                                onFocus={(event) =>
                                    event.currentTarget.select()
                                }
                            />
                            <p className="text-xs text-muted-foreground">
                                Put the token in a root-readable token file for
                                the database-free collector. Never place it in
                                shell history, a ticket, screenshot, or log.
                            </p>
                        </div>
                        {error ? (
                            <p
                                className="text-sm text-destructive"
                                role="alert"
                            >
                                {error}
                            </p>
                        ) : null}
                        <DialogFooter>
                            <Button
                                type="button"
                                variant="outline"
                                onClick={copyToken}
                            >
                                {copied ? (
                                    <Check className="mr-2 h-4 w-4" />
                                ) : (
                                    <Copy className="mr-2 h-4 w-4" />
                                )}
                                {copied ? 'Copied' : 'Copy token'}
                            </Button>
                            <Button type="button" onClick={close}>
                                Close and clear token
                            </Button>
                        </DialogFooter>
                    </div>
                ) : (
                    <form className="space-y-4" onSubmit={submit}>
                        {replacement ? (
                            <div className="rounded-lg border p-4 text-sm">
                                <p className="font-semibold">
                                    {replacement.site?.name ??
                                        'Site unavailable'}
                                </p>
                                <p className="mt-1 text-muted-foreground">
                                    The collector must enrol with fresh identity
                                    material. Reusing its revoked certificate or
                                    signing key will fail.
                                </p>
                            </div>
                        ) : (
                            <div className="space-y-2">
                                <Label htmlFor="collector-site">Site</Label>
                                <Select
                                    value={siteId}
                                    onValueChange={setSiteId}
                                >
                                    <SelectTrigger id="collector-site">
                                        <SelectValue placeholder="Select an approved Site" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {sites.map((site) => (
                                            <SelectItem
                                                key={site.id}
                                                value={site.id.toString()}
                                            >
                                                {site.name}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                                <p className="text-xs text-muted-foreground">
                                    The resulting collector is scoped to this
                                    canonical Site. It cannot enumerate other
                                    Sites or access the application database.
                                </p>
                            </div>
                        )}
                        {error ? (
                            <p
                                className="text-sm text-destructive"
                                role="alert"
                            >
                                {error}
                            </p>
                        ) : null}
                        <DialogFooter>
                            <Button
                                type="button"
                                variant="outline"
                                onClick={close}
                            >
                                Cancel
                            </Button>
                            <Button
                                type="submit"
                                disabled={
                                    submitting || (!replacement && !siteId)
                                }
                            >
                                {submitting
                                    ? 'Issuing…'
                                    : 'Issue one-time token'}
                            </Button>
                        </DialogFooter>
                    </form>
                )}
            </DialogContent>
        </Dialog>
    );
}

export function CollectorRevocationDialog({
    open,
    onOpenChange,
    collector,
    onRevoked,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    collector: CollectorLifecycleTarget | null;
    onRevoked: () => void;
}) {
    const [reason, setReason] = useState('');
    const [submitting, setSubmitting] = useState(false);
    const [error, setError] = useState<string | null>(null);

    useEffect(() => {
        if (!open) {
            setReason('');
            setError(null);
        }
    }, [open]);

    const close = () => onOpenChange(false);
    const submit = async (event: FormEvent) => {
        event.preventDefault();
        if (!collector?.revoke_url || reason.trim().length < 10) return;

        setSubmitting(true);
        setError(null);
        try {
            await axios.post(collector.revoke_url, { reason: reason.trim() });
            onRevoked();
            close();
        } catch (requestError) {
            setError(failureMessage(requestError));
        } finally {
            setSubmitting(false);
        }
    };

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="sm:max-w-lg">
                <DialogHeader>
                    <DialogTitle>
                        Revoke {collector?.name ?? 'collector'}
                    </DialogTitle>
                    <DialogDescription>
                        Revocation immediately rejects its certificate and
                        signing key. Preserve buffered evidence and issue fresh
                        enrolment material only after the outage or compromise
                        is reviewed.
                    </DialogDescription>
                </DialogHeader>
                <form className="space-y-4" onSubmit={submit}>
                    <div className="space-y-2">
                        <Label htmlFor="collector-revocation-reason">
                            Operational reason
                        </Label>
                        <Textarea
                            id="collector-revocation-reason"
                            value={reason}
                            onChange={(event) => setReason(event.target.value)}
                            minLength={10}
                            maxLength={500}
                            rows={4}
                            required
                            placeholder="Describe the outage, replacement, or compromise decision. Do not include credentials."
                        />
                        <p className="text-xs text-muted-foreground">
                            This reason is written to immutable audit history.
                            Do not include a token, key, certificate, endpoint,
                            or other secret.
                        </p>
                    </div>
                    {error ? (
                        <p className="text-sm text-destructive" role="alert">
                            {error}
                        </p>
                    ) : null}
                    <DialogFooter>
                        <Button type="button" variant="outline" onClick={close}>
                            Cancel
                        </Button>
                        <Button
                            type="submit"
                            variant="destructive"
                            disabled={submitting || reason.trim().length < 10}
                        >
                            <ShieldX className="mr-2 h-4 w-4" />
                            {submitting ? 'Revoking…' : 'Revoke collector'}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
