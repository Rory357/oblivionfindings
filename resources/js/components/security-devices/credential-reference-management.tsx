import { ConfirmDialog } from '@/components/confirm-dialog';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';
import { router } from '@inertiajs/react';
import { Ban, KeyRound, Plus, RefreshCw, RotateCcw } from 'lucide-react';
import { useState, type FormEvent } from 'react';

export type CredentialReferenceRow = {
    reference_uuid: string;
    reference_key: string;
    site_id: number;
    site_name: string;
    provider: string;
    purpose: string;
    capabilities: string[];
    status: 'active' | 'suspended' | 'revoked';
    rotation_status: 'current' | 'due' | 'overdue' | 'failed';
    test_status: 'untested' | 'passed' | 'failed';
    version: number;
    live_lease_count: number;
    pending_revoke_count: number;
    last_tested_at: string | null;
    last_rotated_at: string | null;
    revoked_at: string | null;
};

export type CredentialReferenceWorkspace = {
    visible: boolean;
    can_manage: boolean;
    driver_state: string;
    driver_note: string;
    sites: Array<{ id: number; name: string }>;
    rows: CredentialReferenceRow[];
};

type ReferenceForm = {
    site_id: number;
    reference_key: string;
    provider: string;
    purpose: string;
    capabilities: string;
    secret_manager_reference: string;
};

const emptyForm = (siteId = 0): ReferenceForm => ({
    site_id: siteId,
    reference_key: '',
    provider: '',
    purpose: 'device_management',
    capabilities: '',
    secret_manager_reference: '',
});

const readable = (value: string) => value.replaceAll('_', ' ');

const timestamp = (value: string | null) =>
    value ? new Date(value).toLocaleString() : 'Not yet';

const statusVariant = (status: CredentialReferenceRow['status']) => {
    if (status === 'active') return 'default' as const;
    if (status === 'revoked') return 'destructive' as const;
    return 'secondary' as const;
};

export function CredentialReferenceManagement({
    workspace,
}: {
    workspace: CredentialReferenceWorkspace;
}) {
    const [createOpen, setCreateOpen] = useState(false);
    const [rotateReference, setRotateReference] =
        useState<CredentialReferenceRow | null>(null);
    const [revokeReference, setRevokeReference] =
        useState<CredentialReferenceRow | null>(null);
    const [form, setForm] = useState<ReferenceForm>(() =>
        emptyForm(workspace.sites[0]?.id),
    );
    const [rotatePath, setRotatePath] = useState('');
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [processing, setProcessing] = useState(false);

    if (!workspace.visible) return null;

    const closeCreate = () => {
        setCreateOpen(false);
        setErrors({});
        setForm(emptyForm(workspace.sites[0]?.id));
    };

    const submit = (event: FormEvent) => {
        event.preventDefault();
        setProcessing(true);
        setErrors({});
        router.post(
            '/security-devices/settings/credential-references',
            {
                ...form,
                capabilities: form.capabilities
                    .split(/[\n,]+/)
                    .map((value) => value.trim())
                    .filter(Boolean),
            },
            {
                preserveScroll: true,
                onError: (nextErrors) => setErrors(nextErrors),
                onSuccess: closeCreate,
                onFinish: () => setProcessing(false),
            },
        );
    };

    const rotate = (event: FormEvent) => {
        event.preventDefault();
        if (!rotateReference) return;
        setProcessing(true);
        setErrors({});
        router.post(
            `/security-devices/settings/credential-references/${rotateReference.reference_uuid}/rotate`,
            { secret_manager_reference: rotatePath },
            {
                preserveScroll: true,
                onError: (nextErrors) => setErrors(nextErrors),
                onSuccess: () => {
                    setRotateReference(null);
                    setRotatePath('');
                },
                onFinish: () => setProcessing(false),
            },
        );
    };

    const runTest = (reference: CredentialReferenceRow) => {
        router.post(
            `/security-devices/settings/credential-references/${reference.reference_uuid}/test`,
            {},
            { preserveScroll: true },
        );
    };

    const revoke = () => {
        if (!revokeReference) return;
        router.post(
            `/security-devices/settings/credential-references/${revokeReference.reference_uuid}/revoke`,
            {},
            { preserveScroll: true },
        );
    };

    return (
        <>
            <Card>
                <CardHeader className="gap-3 sm:flex-row sm:items-start sm:justify-between">
                    <div className="space-y-1">
                        <CardTitle className="flex items-center gap-2">
                            <KeyRound className="h-4 w-4" />
                            Credential references
                        </CardTitle>
                        <p className="text-sm text-muted-foreground">
                            Safe aliases point to an external secret manager.
                            Oblivion Findings never displays or stores reusable
                            credential material here.
                        </p>
                    </div>
                    {workspace.can_manage ? (
                        <Button
                            type="button"
                            size="sm"
                            onClick={() => setCreateOpen(true)}
                            disabled={workspace.sites.length === 0}
                        >
                            <Plus /> Add reference
                        </Button>
                    ) : null}
                </CardHeader>
                <CardContent className="space-y-4">
                    <div className="flex flex-wrap items-center gap-2 rounded-lg border bg-muted/30 p-3 text-sm">
                        <Badge
                            variant={
                                workspace.driver_state === 'configured'
                                    ? 'default'
                                    : 'secondary'
                            }
                        >
                            Issuer {readable(workspace.driver_state)}
                        </Badge>
                        <span className="text-muted-foreground">
                            {workspace.driver_note}
                        </span>
                    </div>
                    {workspace.rows.length === 0 ? (
                        <div className="rounded-lg border border-dashed p-5 text-sm text-muted-foreground">
                            No credential references are configured in your Site
                            scope. Add a secret-manager path, then test it
                            before any monitor or device action can use it.
                        </div>
                    ) : (
                        <div className="grid gap-3 xl:grid-cols-2">
                            {workspace.rows.map((reference) => (
                                <article
                                    key={reference.reference_uuid}
                                    className="space-y-3 rounded-xl border p-4 text-sm"
                                >
                                    <div className="flex flex-wrap items-start justify-between gap-3">
                                        <div>
                                            <strong>
                                                {reference.reference_key}
                                            </strong>
                                            <p className="text-muted-foreground">
                                                {reference.site_name} ·{' '}
                                                {reference.provider} ·{' '}
                                                {readable(reference.purpose)}
                                            </p>
                                        </div>
                                        <div className="flex flex-wrap gap-2">
                                            <Badge
                                                variant={statusVariant(
                                                    reference.status,
                                                )}
                                            >
                                                {readable(reference.status)}
                                            </Badge>
                                            <Badge variant="outline">
                                                Test{' '}
                                                {readable(
                                                    reference.test_status,
                                                )}
                                            </Badge>
                                            <Badge variant="outline">
                                                Rotation{' '}
                                                {readable(
                                                    reference.rotation_status,
                                                )}
                                            </Badge>
                                        </div>
                                    </div>
                                    <div className="flex flex-wrap gap-2">
                                        {reference.capabilities.map(
                                            (capability) => (
                                                <Badge
                                                    key={capability}
                                                    variant="secondary"
                                                >
                                                    {capability}
                                                </Badge>
                                            ),
                                        )}
                                    </div>
                                    <div className="flex flex-wrap gap-2">
                                        <Badge variant="outline">
                                            {reference.live_lease_count}{' '}
                                            short-lived lease
                                            {reference.live_lease_count === 1
                                                ? ''
                                                : 's'}{' '}
                                            active
                                        </Badge>
                                        {reference.pending_revoke_count > 0 ? (
                                            <Badge variant="destructive">
                                                {reference.pending_revoke_count}{' '}
                                                revocation
                                                {reference.pending_revoke_count ===
                                                1
                                                    ? ''
                                                    : 's'}{' '}
                                                pending
                                            </Badge>
                                        ) : (
                                            <Badge variant="secondary">
                                                No revocations pending
                                            </Badge>
                                        )}
                                    </div>
                                    <dl className="grid gap-2 text-xs text-muted-foreground sm:grid-cols-3">
                                        <div>
                                            <dt>Version</dt>
                                            <dd className="text-foreground">
                                                {reference.version}
                                            </dd>
                                        </div>
                                        <div>
                                            <dt>Last tested</dt>
                                            <dd className="text-foreground">
                                                {timestamp(
                                                    reference.last_tested_at,
                                                )}
                                            </dd>
                                        </div>
                                        <div>
                                            <dt>Last rotated</dt>
                                            <dd className="text-foreground">
                                                {timestamp(
                                                    reference.last_rotated_at,
                                                )}
                                            </dd>
                                        </div>
                                    </dl>
                                    {workspace.can_manage &&
                                    reference.status !== 'revoked' ? (
                                        <div className="flex flex-wrap gap-2 border-t pt-3">
                                            <Button
                                                type="button"
                                                size="sm"
                                                variant="outline"
                                                onClick={() =>
                                                    runTest(reference)
                                                }
                                            >
                                                <RefreshCw /> Test reference
                                            </Button>
                                            <Button
                                                type="button"
                                                size="sm"
                                                variant="outline"
                                                onClick={() => {
                                                    setErrors({});
                                                    setRotatePath('');
                                                    setRotateReference(
                                                        reference,
                                                    );
                                                }}
                                            >
                                                <RotateCcw /> Rotate path
                                            </Button>
                                            <Button
                                                type="button"
                                                size="sm"
                                                variant="ghost"
                                                onClick={() =>
                                                    setRevokeReference(
                                                        reference,
                                                    )
                                                }
                                            >
                                                <Ban /> Revoke
                                            </Button>
                                        </div>
                                    ) : null}
                                </article>
                            ))}
                        </div>
                    )}
                </CardContent>
            </Card>

            <Dialog
                open={createOpen}
                onOpenChange={(open) => {
                    if (open) setCreateOpen(true);
                    else closeCreate();
                }}
            >
                <DialogContent className="sm:max-w-xl">
                    <form onSubmit={submit} className="space-y-4">
                        <DialogHeader>
                            <DialogTitle>Add credential reference</DialogTitle>
                            <DialogDescription>
                                Enter only a safe alias and external
                                secret-manager path. Never paste a password, API
                                key, token or private key into this form.
                            </DialogDescription>
                        </DialogHeader>
                        <div className="grid gap-4 sm:grid-cols-2">
                            <label className="space-y-1 text-sm">
                                <span>Site</span>
                                <select
                                    className="h-9 w-full rounded-md border bg-background px-3"
                                    value={form.site_id}
                                    onChange={(event) =>
                                        setForm({
                                            ...form,
                                            site_id: Number(event.target.value),
                                        })
                                    }
                                >
                                    {workspace.sites.map((site) => (
                                        <option key={site.id} value={site.id}>
                                            {site.name}
                                        </option>
                                    ))}
                                </select>
                            </label>
                            <label className="space-y-1 text-sm">
                                <span>Provider</span>
                                <Input
                                    value={form.provider}
                                    placeholder="unifi"
                                    onChange={(event) =>
                                        setForm({
                                            ...form,
                                            provider: event.target.value,
                                        })
                                    }
                                />
                                {errors.provider ? (
                                    <span className="text-xs text-destructive">
                                        {errors.provider}
                                    </span>
                                ) : null}
                            </label>
                            <label className="space-y-1 text-sm">
                                <span>Safe alias</span>
                                <Input
                                    value={form.reference_key}
                                    placeholder="vault:unifi/site-42"
                                    onChange={(event) =>
                                        setForm({
                                            ...form,
                                            reference_key: event.target.value,
                                        })
                                    }
                                />
                                {errors.reference_key ? (
                                    <span className="text-xs text-destructive">
                                        {errors.reference_key}
                                    </span>
                                ) : null}
                            </label>
                            <label className="space-y-1 text-sm">
                                <span>Purpose</span>
                                <select
                                    className="h-9 w-full rounded-md border bg-background px-3"
                                    value={form.purpose}
                                    onChange={(event) =>
                                        setForm({
                                            ...form,
                                            purpose: event.target.value,
                                        })
                                    }
                                >
                                    <option value="device_management">
                                        Device management
                                    </option>
                                    <option value="monitoring">
                                        Monitoring
                                    </option>
                                    <option value="provider_api">
                                        Provider API
                                    </option>
                                    <option value="collector">
                                        Remote collector
                                    </option>
                                </select>
                            </label>
                        </div>
                        <label className="block space-y-1 text-sm">
                            <span>Allowed capabilities</span>
                            <Textarea
                                value={form.capabilities}
                                placeholder={
                                    'command:network.device.reboot\ninventory:ssh:read_only'
                                }
                                onChange={(event) =>
                                    setForm({
                                        ...form,
                                        capabilities: event.target.value,
                                    })
                                }
                            />
                            <span className="text-xs text-muted-foreground">
                                One exact capability per line. Wildcards are not
                                accepted.
                            </span>
                            {errors.capabilities ? (
                                <span className="text-xs text-destructive">
                                    {errors.capabilities}
                                </span>
                            ) : null}
                        </label>
                        <label className="block space-y-1 text-sm">
                            <span>External secret-manager path</span>
                            <Input
                                autoComplete="off"
                                value={form.secret_manager_reference}
                                placeholder="secret/data/sites/42/core-switch"
                                onChange={(event) =>
                                    setForm({
                                        ...form,
                                        secret_manager_reference:
                                            event.target.value,
                                    })
                                }
                            />
                            <span className="text-xs text-muted-foreground">
                                This path is encrypted at rest and is never
                                shown again.
                            </span>
                            {errors.secret_manager_reference ? (
                                <span className="text-xs text-destructive">
                                    {errors.secret_manager_reference}
                                </span>
                            ) : null}
                        </label>
                        <DialogFooter>
                            <Button
                                type="button"
                                variant="outline"
                                onClick={closeCreate}
                            >
                                Cancel
                            </Button>
                            <Button type="submit" disabled={processing}>
                                Register suspended reference
                            </Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>

            <Dialog
                open={rotateReference !== null}
                onOpenChange={(open) => {
                    if (!open) {
                        setRotateReference(null);
                        setRotatePath('');
                        setErrors({});
                    }
                }}
            >
                <DialogContent>
                    <form onSubmit={rotate} className="space-y-4">
                        <DialogHeader>
                            <DialogTitle>
                                Rotate secret-manager path
                            </DialogTitle>
                            <DialogDescription>
                                {rotateReference?.reference_key} will keep its
                                identity and history, but remain suspended until
                                the new path passes a live test.
                            </DialogDescription>
                        </DialogHeader>
                        <label className="block space-y-1 text-sm">
                            <span>New external path</span>
                            <Input
                                autoComplete="off"
                                value={rotatePath}
                                onChange={(event) =>
                                    setRotatePath(event.target.value)
                                }
                            />
                            {errors.secret_manager_reference ? (
                                <span className="text-xs text-destructive">
                                    {errors.secret_manager_reference}
                                </span>
                            ) : null}
                        </label>
                        <DialogFooter>
                            <Button
                                type="button"
                                variant="outline"
                                onClick={() => setRotateReference(null)}
                            >
                                Cancel
                            </Button>
                            <Button type="submit" disabled={processing}>
                                Rotate and suspend
                            </Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>

            <ConfirmDialog
                open={revokeReference !== null}
                onClose={() => setRevokeReference(null)}
                onConfirm={revoke}
                title="Revoke credential reference?"
                description="New monitoring and device-management leases will be blocked immediately. Existing audit and command history remains intact."
                confirmText="Revoke reference"
            />
        </>
    );
}
