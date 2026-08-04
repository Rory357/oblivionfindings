import { PageHero } from '@/components/page';
import PageShell from '@/components/page-shell';
import {
    CredentialReferenceManagement,
    type CredentialReferenceWorkspace,
} from '@/components/security-devices/credential-reference-management';
import { Badge } from '@/components/ui/badge';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import AppLayout from '@/layouts/app-layout';
import { Head, Link } from '@inertiajs/react';
import { ArrowRight, FileClock, Settings2 } from 'lucide-react';

export type AuditEntry = {
    id: number;
    action: string;
    actor: string;
    record_type: string;
    record_reference: string | null;
    fields: string[];
    created_at: string | null;
};
type SettingsArea = { title: string; description: string; href: string };
type Profile = {
    id: number;
    name: string;
    description: string | null;
    interval_seconds: number;
    failure_confirmations: number;
    recovery_confirmations: number;
    stale_after_seconds: number;
    state: string;
};
type RetentionPolicy = {
    id: number;
    name: string;
    scope: string;
    site_id: number | null;
    device_id: number | null;
    data_class: string | null;
    privacy_class: string | null;
    raw_days: number;
    hourly_days: number;
    daily_days: number;
    legal_hold: boolean;
};
type RuntimeQueue = {
    state: string;
    pending: number | null;
    oldest_age_seconds: number | null;
    dead_letters: number | null;
    worker_state: string;
    heartbeat_age_seconds: number | null;
    dispatch_lag_seconds: number | null;
};

export function AuditEvidence({
    visible,
    entries,
}: {
    visible: boolean;
    entries: AuditEntry[];
}) {
    if (!visible)
        return (
            <p className="text-sm text-muted-foreground">
                Audit evidence requires Security & Devices report permission.
            </p>
        );
    if (entries.length === 0)
        return (
            <p className="text-sm text-muted-foreground">
                No Security & Devices audit evidence is available in the current
                scope.
            </p>
        );
    return (
        <div className="divide-y rounded-xl border">
            {entries.map((entry) => (
                <article key={entry.id} className="space-y-1 p-4 text-sm">
                    <div className="flex flex-wrap items-center gap-2">
                        <span className="font-medium">{entry.action}</span>
                        <Badge variant="outline">
                            {entry.record_type} {entry.record_reference}
                        </Badge>
                    </div>
                    <p className="text-muted-foreground">
                        {entry.actor} ·{' '}
                        {entry.created_at
                            ? new Date(entry.created_at).toLocaleString()
                            : 'Time unavailable'}
                    </p>
                    <p>
                        {entry.fields.length
                            ? `Changed: ${entry.fields.join(', ')}`
                            : 'No safe changed-field names recorded.'}
                    </p>
                </article>
            ))}
        </div>
    );
}

export default function SettingsAudit(props: {
    summary: { device_groups: number; audit_entries: number };
    areas: SettingsArea[];
    classificationDefaults: {
        state: string;
        values: Record<string, unknown>;
        note: string;
    };
    providerOperationalDefaults: Array<{
        provider: string;
        state: string;
        values: Record<string, unknown>;
    }>;
    credentialReferences: CredentialReferenceWorkspace;
    monitoringProfiles: Profile[];
    monitoringRetention: {
        policies: RetentionPolicy[];
        application_defaults: {
            raw_days: number;
            hourly_days: number;
            daily_days: number;
        };
        rule: string;
    };
    monitoringRuntime: {
        state: string;
        workers: {
            state: string;
            available: number;
            total: number;
            attention: number;
            not_observed: number;
            note: string;
        };
        queues: Record<string, RuntimeQueue>;
        observed_at: string;
    };
    dataQuality: {
        visible_devices: number;
        unassigned_devices: number;
        duplicate_candidates: number;
        note: string;
    };
    featureSupport: Record<string, { state: string; note: string }>;
    audit: {
        visible: boolean;
        evidence_state: string;
        entries: AuditEntry[];
        limit: number;
    };
}) {
    const {
        summary,
        areas,
        classificationDefaults,
        providerOperationalDefaults,
        credentialReferences,
        monitoringProfiles,
        monitoringRetention,
        monitoringRuntime,
        dataQuality,
        featureSupport,
        audit,
    } = props;
    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Security & Devices', href: '/security-devices' },
                {
                    title: 'Settings & audit',
                    href: '/security-devices/settings',
                },
            ]}
        >
            <Head title="Settings & audit - Security & Devices" />
            <PageShell>
                <PageHero
                    variant="compact"
                    icon={Settings2}
                    title="Settings & audit"
                    description="Current defaults, monitoring profiles, data-quality exceptions, feature support, and safe read-only audit evidence."
                    stats={[
                        {
                            label: 'Device groups',
                            value: summary.device_groups,
                        },
                        {
                            label: 'Scoped audit entries',
                            value: summary.audit_entries,
                        },
                        {
                            label: 'Unassigned devices',
                            value: dataQuality.unassigned_devices,
                        },
                        {
                            label: 'Duplicate candidates',
                            value: dataQuality.duplicate_candidates,
                        },
                    ]}
                />
                <nav
                    aria-label="Settings destinations"
                    className="grid gap-3 md:grid-cols-3"
                >
                    {areas.map((area) => (
                        <Link
                            key={area.href}
                            href={area.href}
                            className="frontline-focus flex min-h-11 items-center justify-between rounded-xl border p-4"
                        >
                            <span>
                                <strong className="block">{area.title}</strong>
                                <span className="text-sm text-muted-foreground">
                                    {area.description}
                                </span>
                            </span>
                            <ArrowRight className="h-4 w-4" />
                        </Link>
                    ))}
                </nav>
                <div className="grid gap-4 lg:grid-cols-2">
                    <Card>
                        <CardHeader>
                            <CardTitle>Classification defaults</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <Badge variant="secondary">
                                {classificationDefaults.state.replaceAll(
                                    '_',
                                    ' ',
                                )}
                            </Badge>
                            <p className="mt-3 text-sm text-muted-foreground">
                                {classificationDefaults.note}
                            </p>
                        </CardContent>
                    </Card>
                    <Card>
                        <CardHeader>
                            <CardTitle>Assignment and data quality</CardTitle>
                        </CardHeader>
                        <CardContent className="grid grid-cols-3 gap-3 text-sm">
                            <div>
                                <strong className="text-xl">
                                    {dataQuality.visible_devices}
                                </strong>
                                <p>Visible devices</p>
                            </div>
                            <div>
                                <strong className="text-xl">
                                    {dataQuality.unassigned_devices}
                                </strong>
                                <p>Unassigned</p>
                            </div>
                            <div>
                                <strong className="text-xl">
                                    {dataQuality.duplicate_candidates}
                                </strong>
                                <p>Duplicates</p>
                            </div>
                            <p className="col-span-3 text-muted-foreground">
                                {dataQuality.note}
                            </p>
                        </CardContent>
                    </Card>
                </div>
                <Card>
                    <CardHeader>
                        <CardTitle>Provider operational defaults</CardTitle>
                    </CardHeader>
                    <CardContent className="space-y-3">
                        {providerOperationalDefaults.length === 0 ? (
                            <p className="text-sm text-muted-foreground">
                                No authorised provider operational defaults are
                                configured.
                            </p>
                        ) : (
                            providerOperationalDefaults.map((row) => (
                                <div
                                    key={row.provider}
                                    className="rounded-lg border p-3"
                                >
                                    <strong className="capitalize">
                                        {row.provider}
                                    </strong>
                                    <dl className="mt-2 grid gap-2 sm:grid-cols-2">
                                        {Object.entries(row.values).map(
                                            ([key, value]) => (
                                                <div
                                                    key={key}
                                                    className="text-sm"
                                                >
                                                    <dt className="text-muted-foreground">
                                                        {key.replaceAll(
                                                            '_',
                                                            ' ',
                                                        )}
                                                    </dt>
                                                    <dd>{String(value)}</dd>
                                                </div>
                                            ),
                                        )}
                                    </dl>
                                </div>
                            ))
                        )}
                    </CardContent>
                </Card>
                <CredentialReferenceManagement
                    workspace={credentialReferences}
                />
                <Card>
                    <CardHeader>
                        <CardTitle>Monitoring retention</CardTitle>
                    </CardHeader>
                    <CardContent className="space-y-3">
                        <p className="text-sm text-muted-foreground">
                            {monitoringRetention.rule}
                        </p>
                        {monitoringRetention.policies.length ? (
                            monitoringRetention.policies.map((policy) => (
                                <article
                                    key={policy.id}
                                    className="rounded-lg border p-4 text-sm"
                                >
                                    <div className="flex flex-wrap items-center justify-between gap-2">
                                        <strong>{policy.name}</strong>
                                        <div className="flex gap-2">
                                            <Badge variant="outline">
                                                {policy.scope.replaceAll(
                                                    '_',
                                                    ' ',
                                                )}
                                            </Badge>
                                            {policy.legal_hold ? (
                                                <Badge variant="secondary">
                                                    Legal hold
                                                </Badge>
                                            ) : null}
                                        </div>
                                    </div>
                                    <p className="mt-2 text-muted-foreground">
                                        Raw {policy.raw_days} days · hourly{' '}
                                        {policy.hourly_days} days · daily{' '}
                                        {policy.daily_days} days
                                    </p>
                                    {policy.data_class ||
                                    policy.privacy_class ? (
                                        <p className="mt-1 text-xs text-muted-foreground">
                                            {policy.data_class ?? 'All data'} ·{' '}
                                            {policy.privacy_class ??
                                                'All privacy classes'}
                                        </p>
                                    ) : null}
                                </article>
                            ))
                        ) : (
                            <p className="text-sm text-muted-foreground">
                                No active retention policies are visible.
                            </p>
                        )}
                    </CardContent>
                </Card>
                <Card>
                    <CardHeader>
                        <CardTitle>Monitoring profiles</CardTitle>
                    </CardHeader>
                    <CardContent className="grid gap-3 md:grid-cols-2">
                        {monitoringProfiles.length === 0 ? (
                            <p className="text-sm text-muted-foreground">
                                No monitoring profiles are configured.
                            </p>
                        ) : (
                            monitoringProfiles.map((profile) => (
                                <article
                                    key={profile.id}
                                    className="rounded-lg border p-4 text-sm"
                                >
                                    <div className="flex justify-between gap-3">
                                        <strong>{profile.name}</strong>
                                        <Badge variant="outline">
                                            {profile.state}
                                        </Badge>
                                    </div>
                                    <p className="mt-1 text-muted-foreground">
                                        {profile.description ??
                                            'No description.'}
                                    </p>
                                    <p className="mt-2">
                                        Every {profile.interval_seconds}s ·
                                        stale after{' '}
                                        {profile.stale_after_seconds}s ·
                                        fail/recover{' '}
                                        {profile.failure_confirmations}/
                                        {profile.recovery_confirmations}
                                    </p>
                                </article>
                            ))
                        )}
                    </CardContent>
                </Card>
                <Card>
                    <CardHeader>
                        <CardTitle>
                            Runtime workers, queues and dead letters
                        </CardTitle>
                    </CardHeader>
                    <CardContent className="space-y-3">
                        <div className="flex flex-wrap items-center gap-2 text-sm">
                            <Badge variant="outline">
                                {monitoringRuntime.state.replaceAll('_', ' ')}
                            </Badge>
                            <span className="text-muted-foreground">
                                {monitoringRuntime.workers.note}
                            </span>
                        </div>
                        <div className="grid gap-3 md:grid-cols-2 xl:grid-cols-3">
                            {Object.entries(monitoringRuntime.queues).map(
                                ([name, queue]) => (
                                    <article
                                        key={name}
                                        className="rounded-lg border p-3 text-sm"
                                    >
                                        <div className="flex items-center justify-between gap-2">
                                            <strong className="capitalize">
                                                {name.replaceAll('_', ' ')}
                                            </strong>
                                            <Badge variant="outline">
                                                {queue.state.replaceAll(
                                                    '_',
                                                    ' ',
                                                )}
                                            </Badge>
                                        </div>
                                        <p className="mt-2 text-muted-foreground">
                                            {queue.pending === null
                                                ? 'Counts restricted to authorised operators'
                                                : `${queue.pending} pending · ${queue.dead_letters ?? 0} dead letters`}
                                        </p>
                                        <p className="mt-1 text-muted-foreground">
                                            Worker{' '}
                                            {queue.worker_state.replaceAll(
                                                '_',
                                                ' ',
                                            )}
                                            {queue.heartbeat_age_seconds ===
                                            null
                                                ? ' · no heartbeat consumed'
                                                : ` · heartbeat ${queue.heartbeat_age_seconds}s ago`}
                                        </p>
                                    </article>
                                ),
                            )}
                        </div>
                    </CardContent>
                </Card>
                <Card>
                    <CardHeader>
                        <CardTitle>Feature support</CardTitle>
                    </CardHeader>
                    <CardContent className="grid gap-3 md:grid-cols-2">
                        {Object.entries(featureSupport).map(
                            ([key, feature]) => (
                                <div
                                    key={key}
                                    className="rounded-lg border p-3 text-sm"
                                >
                                    <div className="flex flex-wrap justify-between gap-2">
                                        <strong>
                                            {key.replaceAll('_', ' ')}
                                        </strong>
                                        <Badge
                                            variant={
                                                feature.state === 'supported'
                                                    ? 'outline'
                                                    : 'secondary'
                                            }
                                        >
                                            {feature.state}
                                        </Badge>
                                    </div>
                                    <p className="mt-1 text-muted-foreground">
                                        {feature.note}
                                    </p>
                                </div>
                            ),
                        )}
                    </CardContent>
                </Card>
                <Card>
                    <CardHeader>
                        <CardTitle className="flex items-center gap-2">
                            <FileClock className="h-4 w-4" />
                            Audit evidence
                        </CardTitle>
                    </CardHeader>
                    <CardContent>
                        <p className="mb-3 text-sm text-muted-foreground">
                            Newest-first, bounded to {audit.limit} safe Security
                            & Devices records. Application evidence is presented
                            as read-only and append-only; database immutability
                            is not claimed.
                        </p>
                        <AuditEvidence
                            visible={audit.visible}
                            entries={audit.entries}
                        />
                    </CardContent>
                </Card>
            </PageShell>
        </AppLayout>
    );
}
