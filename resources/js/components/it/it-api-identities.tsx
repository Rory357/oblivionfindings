import { ConfirmDialog } from '@/components/confirm-dialog';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { StatusBadge } from '@/components/ui/status-badge';
import { Textarea } from '@/components/ui/textarea';
import { router, useForm } from '@inertiajs/react';
import {
    AlertTriangle,
    Check,
    Copy,
    KeyRound,
    Plus,
    ShieldCheck,
} from 'lucide-react';
import { type FormEvent, type ReactNode, useState } from 'react';

export interface ItApiIdentity {
    id: number;
    public_id: string;
    name: string;
    description: string | null;
    actor: { id: number; name: string } | null;
    creator: { id: number; name: string } | null;
    abilities: string[];
    allowed_work_types: string[];
    allowed_site_ids: number[];
    allowed_fields: { create?: string[]; read?: string[] };
    require_signature: boolean;
    rate_limit_per_minute: number;
    expires_at: string | null;
    revoked_at: string | null;
    last_used_at: string | null;
    created_at: string | null;
    is_active: boolean;
}

export interface OneTimeApiCredential {
    identity_id: number;
    name: string;
    token: string;
}

interface Option {
    id: number;
    name: string;
}

interface Props {
    identities: ItApiIdentity[];
    oneTimeCredential: OneTimeApiCredential | null;
    agents: Option[];
    sites: Option[];
}

const ABILITIES = [
    ['work:create', 'Create work items'],
    ['work:read', 'Read safe status and context'],
    ['work:comment', 'Append public evidence or comments'],
    ['work:transition', 'Send lifecycle status callbacks'],
    ['work:sensitive', 'Access explicitly sensitive work'],
    ['work:organisation-wide', 'Access explicit organisation-wide work'],
] as const;
const WORK_TYPES = [
    ['incident', 'Incidents'],
    ['service_request', 'Service requests'],
    ['security_request', 'Security requests'],
] as const;
const CREATE_FIELDS = [
    ['title', 'Title'],
    ['description', 'Description'],
    ['category', 'Category'],
    ['subcategory', 'Subcategory'],
    ['priority', 'Priority'],
    ['impact', 'Impact'],
    ['urgency', 'Urgency'],
    ['work_type', 'Work type'],
    ['site_id', 'Site link'],
    ['is_organisation_wide', 'Organisation-wide scope marker'],
    ['it_service_id', 'Service link'],
    ['asset_id', 'Asset link'],
] as const;
const READ_FIELDS = [
    ['description', 'Description'],
    ['category', 'Category'],
    ['subcategory', 'Subcategory'],
    ['impact', 'Impact'],
    ['urgency', 'Urgency'],
    ['site', 'Site context'],
    ['service', 'Service context'],
    ['asset', 'Asset context'],
    ['queue', 'Queue'],
    ['team', 'Team'],
    ['owner', 'Owner'],
    ['assignee', 'Assignee'],
    ['sla', 'SLA targets'],
    ['resolution', 'Resolution details'],
] as const;

export function ItApiIdentities({
    identities,
    oneTimeCredential,
    agents,
    sites,
}: Props) {
    const [open, setOpen] = useState(false);
    const [copied, setCopied] = useState(false);
    const [revokeTarget, setRevokeTarget] = useState<ItApiIdentity | null>(
        null,
    );
    const form = useForm({
        name: '',
        description: '',
        actor_user_id: String(agents[0]?.id ?? ''),
        abilities: ABILITIES.map(([value]) => value).filter(
            (value) =>
                value !== 'work:sensitive' &&
                value !== 'work:organisation-wide',
        ) as string[],
        allowed_work_types: ['incident'] as string[],
        allowed_site_ids: [] as number[],
        create_fields: [
            'title',
            'description',
            'category',
            'priority',
            'work_type',
            'site_id',
        ],
        read_fields: [] as string[],
        require_signature: true,
        rate_limit_per_minute: 60,
        expires_at: '',
    });

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.post('/it/setup/api-identities', {
            preserveScroll: true,
            onSuccess: () => {
                setOpen(false);
                form.reset();
            },
        });
    };

    const copyCredential = async () => {
        if (!oneTimeCredential) return;
        await navigator.clipboard.writeText(oneTimeCredential.token);
        setCopied(true);
        window.setTimeout(() => setCopied(false), 2000);
    };

    return (
        <>
            <section className="overflow-hidden rounded-2xl border border-border bg-card">
                <div className="flex flex-col justify-between gap-3 border-b border-border px-5 py-4 sm:flex-row sm:items-center">
                    <div>
                        <h2 className="font-semibold">API identities</h2>
                        <p className="text-xs text-muted-foreground">
                            Named machine credentials with explicit operations,
                            sites, fields, expiry, signatures, and request
                            limits.
                        </p>
                    </div>
                    <Button onClick={() => setOpen(true)}>
                        <Plus className="h-4 w-4" aria-hidden="true" /> New API
                        identity
                    </Button>
                </div>

                {oneTimeCredential ? (
                    <div
                        role="status"
                        className="m-4 rounded-xl border border-status-warning/35 bg-status-warning-bg p-4"
                    >
                        <div className="flex items-start gap-3">
                            <AlertTriangle
                                className="mt-0.5 h-5 w-5 flex-none text-status-warning"
                                aria-hidden="true"
                            />
                            <div className="min-w-0 flex-1">
                                <h3 className="font-semibold">
                                    Copy {oneTimeCredential.name}&apos;s
                                    credential now
                                </h3>
                                <p className="mt-1 text-sm text-muted-foreground">
                                    This reusable credential is shown once.
                                    Store it in your approved secret manager;
                                    the app keeps only its hash.
                                </p>
                                <div className="mt-3 flex flex-col gap-2 sm:flex-row">
                                    <Input
                                        aria-label="One-time API credential"
                                        value={oneTimeCredential.token}
                                        readOnly
                                        className="font-mono text-xs"
                                    />
                                    <Button
                                        type="button"
                                        variant="outline"
                                        onClick={copyCredential}
                                    >
                                        {copied ? (
                                            <Check
                                                className="h-4 w-4"
                                                aria-hidden="true"
                                            />
                                        ) : (
                                            <Copy
                                                className="h-4 w-4"
                                                aria-hidden="true"
                                            />
                                        )}{' '}
                                        {copied ? 'Copied' : 'Copy'}
                                    </Button>
                                </div>
                            </div>
                        </div>
                    </div>
                ) : null}

                {identities.length ? (
                    <div className="grid gap-4 p-4 xl:grid-cols-2">
                        {identities.map((identity) => (
                            <IdentityCard
                                key={identity.id}
                                identity={identity}
                                siteCount={identity.allowed_site_ids.length}
                                onRevoke={() => setRevokeTarget(identity)}
                            />
                        ))}
                    </div>
                ) : (
                    <div className="px-5 py-14 text-center">
                        <KeyRound
                            className="mx-auto h-8 w-8 text-muted-foreground"
                            aria-hidden="true"
                        />
                        <p className="mt-3 text-sm font-medium">
                            No API identities configured
                        </p>
                        <p className="mt-1 text-xs text-muted-foreground">
                            Create one only for an approved system that needs
                            controlled IT work access.
                        </p>
                    </div>
                )}
            </section>

            <Dialog open={open} onOpenChange={setOpen}>
                <DialogContent className="max-h-[92vh] overflow-y-auto sm:max-w-3xl">
                    <form onSubmit={submit}>
                        <DialogHeader>
                            <DialogTitle>New API identity</DialogTitle>
                            <DialogDescription>
                                Start with the least access the integration
                                needs. A bearer credential is shown once after
                                creation; signed requests are recommended and
                                enabled by default.
                            </DialogDescription>
                        </DialogHeader>
                        <Errors errors={form.errors} />
                        <div className="mt-5 grid gap-4 sm:grid-cols-2">
                            <Field label="Identity name">
                                <Input
                                    value={form.data.name}
                                    onChange={(event) =>
                                        form.setData('name', event.target.value)
                                    }
                                    placeholder="Native monitoring intake"
                                    required
                                />
                            </Field>
                            <SelectField
                                label="Execution account"
                                value={form.data.actor_user_id}
                                onChange={(value) =>
                                    form.setData('actor_user_id', value)
                                }
                                options={agents.map((agent) => ({
                                    value: String(agent.id),
                                    label: agent.name,
                                }))}
                            />
                            <Field
                                label="Purpose and owner notes"
                                className="sm:col-span-2"
                            >
                                <Textarea
                                    value={form.data.description}
                                    onChange={(event) =>
                                        form.setData(
                                            'description',
                                            event.target.value,
                                        )
                                    }
                                    rows={2}
                                />
                            </Field>

                            <Choices
                                legend="Allowed operations"
                                options={ABILITIES}
                                selected={form.data.abilities}
                                onChange={(value) =>
                                    form.setData('abilities', value)
                                }
                            />
                            <Choices
                                legend="Allowed work types"
                                options={WORK_TYPES}
                                selected={form.data.allowed_work_types}
                                onChange={(value) =>
                                    form.setData('allowed_work_types', value)
                                }
                            />
                            <Choices
                                legend="Fields this identity may send"
                                options={CREATE_FIELDS}
                                selected={form.data.create_fields}
                                onChange={(value) =>
                                    form.setData('create_fields', value)
                                }
                            />
                            <Choices
                                legend="Extra fields returned when reading"
                                options={READ_FIELDS}
                                selected={form.data.read_fields}
                                onChange={(value) =>
                                    form.setData('read_fields', value)
                                }
                                hint="Status, reference, title and priority are always returned. Linked context is returned only when selected and currently authorized."
                            />

                            <fieldset className="rounded-xl border border-border p-3 sm:col-span-2">
                                <legend className="px-1 text-sm font-medium">
                                    Allowed sites
                                </legend>
                                <p className="mb-2 text-xs text-muted-foreground">
                                    With no sites selected, this identity cannot
                                    use Site-linked work. Explicit
                                    organisation-wide work also needs its
                                    separate operation and scope marker.
                                </p>
                                <div className="grid max-h-48 gap-1 overflow-y-auto sm:grid-cols-2">
                                    {sites.map((site) => (
                                        <Choice
                                            key={site.id}
                                            label={site.name}
                                            checked={form.data.allowed_site_ids.includes(
                                                site.id,
                                            )}
                                            onChange={(checked) =>
                                                form.setData(
                                                    'allowed_site_ids',
                                                    checked
                                                        ? [
                                                              ...form.data
                                                                  .allowed_site_ids,
                                                              site.id,
                                                          ]
                                                        : form.data.allowed_site_ids.filter(
                                                              (id) =>
                                                                  id !==
                                                                  site.id,
                                                          ),
                                                )
                                            }
                                        />
                                    ))}
                                    {sites.length === 0 ? (
                                        <p className="text-xs text-muted-foreground">
                                            No active sites are available.
                                        </p>
                                    ) : null}
                                </div>
                            </fieldset>

                            <Field label="Requests per minute">
                                <Input
                                    type="number"
                                    min={1}
                                    max={300}
                                    value={form.data.rate_limit_per_minute}
                                    onChange={(event) =>
                                        form.setData(
                                            'rate_limit_per_minute',
                                            Number(event.target.value),
                                        )
                                    }
                                />
                            </Field>
                            <Field label="Expires at (optional)">
                                <Input
                                    type="datetime-local"
                                    value={form.data.expires_at}
                                    onChange={(event) =>
                                        form.setData(
                                            'expires_at',
                                            event.target.value,
                                        )
                                    }
                                />
                            </Field>
                            <label className="flex min-h-11 items-center gap-2 rounded-xl border border-border p-3 text-sm font-medium sm:col-span-2">
                                <input
                                    type="checkbox"
                                    checked={form.data.require_signature}
                                    onChange={(event) =>
                                        form.setData(
                                            'require_signature',
                                            event.target.checked,
                                        )
                                    }
                                />
                                Require a timestamped HMAC signature on every
                                request
                            </label>
                        </div>
                        <DialogFooter className="mt-6">
                            <Button
                                type="button"
                                variant="outline"
                                onClick={() => setOpen(false)}
                            >
                                Cancel
                            </Button>
                            <Button type="submit" disabled={form.processing}>
                                Create identity
                            </Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>
            <ConfirmDialog
                open={revokeTarget !== null}
                onClose={() => setRevokeTarget(null)}
                onConfirm={() => {
                    if (revokeTarget) {
                        router.post(
                            `/it/setup/api-identities/${revokeTarget.id}/revoke`,
                        );
                    }
                }}
                title="Revoke API identity?"
                description={`${revokeTarget?.name ?? 'This identity'} will stop working immediately. Existing audit history is retained.`}
                confirmText="Revoke identity"
            />
        </>
    );
}

function IdentityCard({
    identity,
    siteCount,
    onRevoke,
}: {
    identity: ItApiIdentity;
    siteCount: number;
    onRevoke: () => void;
}) {
    return (
        <article className="rounded-xl border border-border/70 p-4">
            <div className="flex items-start justify-between gap-3">
                <div>
                    <div className="flex flex-wrap items-center gap-2">
                        <h3 className="font-semibold">{identity.name}</h3>
                        <StatusBadge
                            variant={identity.is_active ? 'success' : 'neutral'}
                            size="sm"
                        >
                            {identity.is_active ? 'Active' : 'Inactive'}
                        </StatusBadge>
                        {identity.require_signature ? (
                            <StatusBadge variant="info" size="sm">
                                Signed
                            </StatusBadge>
                        ) : null}
                    </div>
                    <p className="mt-1 font-mono text-xs text-primary">
                        {identity.public_id}
                    </p>
                </div>
                {identity.is_active ? (
                    <Button size="sm" variant="outline" onClick={onRevoke}>
                        Revoke
                    </Button>
                ) : null}
            </div>
            <p className="mt-3 text-sm text-muted-foreground">
                {identity.description || 'No purpose notes recorded.'}
            </p>
            <dl className="mt-4 grid gap-2 text-xs sm:grid-cols-2">
                <Fact
                    label="Execution account"
                    value={identity.actor?.name ?? 'Unavailable'}
                />
                <Fact
                    label="Site scope"
                    value={
                        siteCount
                            ? `${siteCount} selected`
                            : 'No site-linked work'
                    }
                />
                <Fact
                    label="Limit"
                    value={`${identity.rate_limit_per_minute}/minute`}
                />
                <Fact
                    label="Last used"
                    value={formatDate(identity.last_used_at, 'Never')}
                />
                <Fact
                    label="Expires"
                    value={formatDate(identity.expires_at, 'No expiry')}
                />
                <Fact
                    label="Created"
                    value={formatDate(identity.created_at, 'Unknown')}
                />
            </dl>
            <div className="mt-3 flex flex-wrap gap-1.5">
                {identity.abilities.map((ability) => (
                    <StatusBadge key={ability} variant="neutral" size="sm">
                        {ability.replace('work:', '')}
                    </StatusBadge>
                ))}
                {identity.allowed_work_types.map((type) => (
                    <StatusBadge key={type} variant="info" size="sm">
                        {type.replace(/_/g, ' ')}
                    </StatusBadge>
                ))}
            </div>
            <p className="mt-3 flex items-start gap-2 text-xs text-muted-foreground">
                <ShieldCheck className="h-4 w-4 flex-none" aria-hidden="true" />
                The reusable secret is never available from this record.
            </p>
        </article>
    );
}

function Fact({ label, value }: { label: string; value: string }) {
    return (
        <div>
            <dt className="text-muted-foreground">{label}</dt>
            <dd className="font-medium">{value}</dd>
        </div>
    );
}

function Choices({
    legend,
    options,
    selected,
    onChange,
    hint,
}: {
    legend: string;
    options: ReadonlyArray<readonly [string, string]>;
    selected: string[];
    onChange: (values: string[]) => void;
    hint?: string;
}) {
    return (
        <fieldset className="rounded-xl border border-border p-3">
            <legend className="px-1 text-sm font-medium">{legend}</legend>
            {hint ? (
                <p className="mb-1 text-xs text-muted-foreground">{hint}</p>
            ) : null}
            <div className="space-y-1">
                {options.map(([value, label]) => (
                    <Choice
                        key={value}
                        label={label}
                        checked={selected.includes(value)}
                        onChange={(checked) =>
                            onChange(
                                checked
                                    ? [...selected, value]
                                    : selected.filter((item) => item !== value),
                            )
                        }
                    />
                ))}
            </div>
        </fieldset>
    );
}

function Choice({
    label,
    checked,
    onChange,
}: {
    label: string;
    checked: boolean;
    onChange: (checked: boolean) => void;
}) {
    return (
        <label className="flex min-h-11 items-center gap-2 rounded-md px-2 text-sm hover:bg-muted/50">
            <input
                type="checkbox"
                checked={checked}
                onChange={(event) => onChange(event.target.checked)}
            />
            {label}
        </label>
    );
}

function Field({
    label,
    className = '',
    children,
}: {
    label: string;
    className?: string;
    children: ReactNode;
}) {
    return (
        <label className={`space-y-1.5 text-sm font-medium ${className}`}>
            {label}
            {children}
        </label>
    );
}

function SelectField({
    label,
    value,
    onChange,
    options,
}: {
    label: string;
    value: string;
    onChange: (value: string) => void;
    options: Array<{ value: string; label: string }>;
}) {
    return (
        <label className="space-y-1.5 text-sm font-medium">
            {label}
            <select
                className="min-h-11 w-full rounded-md border border-input bg-background px-3 text-sm"
                value={value}
                onChange={(event) => onChange(event.target.value)}
                required
            >
                {options.map((option) => (
                    <option key={option.value} value={option.value}>
                        {option.label}
                    </option>
                ))}
            </select>
        </label>
    );
}

function Errors({ errors }: { errors: Record<string, string> }) {
    const messages = Object.values(errors);
    return messages.length ? (
        <div
            role="alert"
            className="mt-4 rounded-xl border border-status-critical/30 bg-status-critical-bg p-3 text-sm text-status-critical"
        >
            <p className="font-semibold">Check the identity settings.</p>
            <ul className="mt-1 list-disc pl-5">
                {messages.map((message) => (
                    <li key={message}>{message}</li>
                ))}
            </ul>
        </div>
    ) : null;
}

function formatDate(value: string | null, fallback: string): string {
    return value
        ? new Date(value).toLocaleString('en-NZ', {
              dateStyle: 'medium',
              timeStyle: 'short',
          })
        : fallback;
}
