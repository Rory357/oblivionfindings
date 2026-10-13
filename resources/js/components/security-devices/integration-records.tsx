import { EntityChip } from '@/components/lists';
import type { EntityCardMetric } from '@/components/lists/entity-card';
import {
    EvidenceRecords,
    type EvidenceRecordsView,
} from '@/components/lists/evidence-records';
import { StatusBadge, type StatusVariant } from '@/components/ui/status-badge';
import { ReviewRow } from '@/components/wizard/shell';
import { formatDateTimeLong } from '@/lib/datetime';
import { Link, router } from '@inertiajs/react';
import { Activity, Database, Plug } from 'lucide-react';
import type { HTMLAttributes, ReactNode } from 'react';
import type { IntegrationsProps, Provider } from './integration-types';
export type IntegrationView =
    | 'providers'
    | 'connection'
    | 'sites'
    | 'health'
    | 'runtime'
    | 'actions';
export type IntegrationPermissions = { manage: boolean; devices: boolean };
export const integrationViews: Array<{ key: IntegrationView; label: string }> =
    [
        { key: 'providers', label: 'Providers' },
        { key: 'connection', label: 'Connections' },
        { key: 'sites', label: 'Sites & sync' },
        { key: 'health', label: 'Collected health' },
        { key: 'runtime', label: 'Capabilities' },
        { key: 'actions', label: 'Required action' },
    ];
export const integrationLabel = (s: string) => {
    const label = s.replaceAll('_', ' ');
    return label ? label[0].toUpperCase() + label.slice(1) : 'Not recorded';
};
const date = (s: string | null | undefined) =>
    formatDateTimeLong(s, 'Not recorded');
const list = (s: string[]) =>
    s.length ? s.map(integrationLabel).join(', ') : 'None recorded';
export const connectionLabel = (p: Provider) =>
    p.connection_status === 'unavailable'
        ? 'Cloud API unavailable'
        : integrationLabel(p.connection_status);
export const healthLabel = (p: Provider) =>
    ({
        current: 'Current collection',
        not_run: 'Not collected',
        collecting: 'Collecting',
        stale: 'Old collection',
        partial: 'Partial collection',
        failed: 'Failed collection',
        unsupported: 'Not supported',
    })[p.health.state];
const status = (state: string): StatusVariant =>
    ['error', 'failed'].includes(state)
        ? 'critical'
        : ['stale', 'partial'].includes(state)
          ? 'warning'
          : state === 'current' || state === 'connected'
            ? 'info'
            : 'neutral';
export function integrationDestination(
    href: string | null,
    permission: IntegrationPermissions,
): string | null {
    if (!href) return null;
    if (href.startsWith('/security-devices/integrations/'))
        return permission.manage ? href : null;
    if (
        href === '/security-devices/devices' ||
        href.startsWith('/security-devices/devices?')
    )
        return permission.devices ? href : null;
    return null;
}
export function integrationForViewer(
    p: Provider,
    permission: IntegrationPermissions,
): Provider {
    const { credential, ...safe } = p;
    return {
        ...safe,
        ...(permission.manage && credential ? { credential } : {}),
        docs_href: integrationDestination(p.docs_href, permission),
        health: {
            ...p.health,
            href: integrationDestination(p.health.href, permission),
        },
        runtime: {
            ...p.runtime,
            disconnect_ready: permission.manage && p.runtime.disconnect_ready,
            revoke_ready: permission.manage && p.runtime.revoke_ready,
        },
        exceptions: p.exceptions.map((e) => ({
            ...e,
            href: integrationDestination(e.href, permission),
        })),
    };
}
type Fact = [string, ReactNode];
const destination = (href: string | null, label: string) =>
    href ? (
        <Link
            href={href}
            className="frontline-focus text-primary underline-offset-4 hover:underline"
        >
            {label}
        </Link>
    ) : (
        'Access to this destination is required. Ask an administrator.'
    );
export function integrationSections(
    p: Provider,
    data: IntegrationsProps,
    permission: IntegrationPermissions,
): Array<{ key: string; label: string; facts: Fact[] }> {
    const credential = p.credential;
    return [
        {
            key: 'providers',
            label: 'Provider record',
            facts: [
                ['Provider', p.name],
                ['Provider key', p.slug],
                ['Vendor', p.vendor],
                ['Summary', p.summary],
                ['Device scope', list(p.device_scope)],
                ['Imported devices', p.device_count],
                ['Events in past 24 hours', p.events_24h],
                ['Exceptions', p.exception_count],
                ['Read started', date(data.as_of)],
                [
                    'Provider diagnostics',
                    destination(p.docs_href, 'Open ' + p.name + ' diagnostics'),
                ],
            ],
        },
        {
            key: 'connection',
            label: 'Connection & credentials',
            facts: [
                ['Connection', connectionLabel(p)],
                ['Connected', p.connected ? 'Yes' : 'No'],
                ['Last connection test', date(p.last_tested_at)],
                ['Last provider sync', date(p.last_synced_at)],
                ...(permission.manage && credential
                    ? ([
                          [
                              'Credentials configured',
                              credential.configured ? 'Yes' : 'No',
                          ],
                          [
                              'Credential reference',
                              credential.reference || 'Not recorded',
                          ],
                          [
                              'Credential label',
                              credential.reference_label || 'Not recorded',
                          ],
                          [
                              'Credential source',
                              integrationLabel(credential.display_state),
                          ],
                          [
                              'Rotation review',
                              <CredentialRotationStatus
                                  state={credential.rotation_state}
                                  cadenceDays={credential.rotation_cadence_days}
                              />,
                          ],
                          [
                              'Review cadence',
                              `${credential.rotation_cadence_days} days`,
                          ],
                          ['Rotated', date(credential.rotated_at)],
                          ['Credential created', date(credential.created_at)],
                          [
                              'Credential last tested',
                              date(credential.last_tested_at),
                          ],
                          [
                              'Site credentials',
                              credential.site_credentials.total,
                          ],
                          [
                              'Enabled Site credentials',
                              credential.site_credentials.enabled,
                          ],
                          [
                              'Site credentials needing attention',
                              credential.site_credentials.needs_attention,
                          ],
                          [
                              'Site credential capabilities',
                              list(credential.site_credentials.capabilities),
                          ],
                      ] as Fact[])
                    : [
                          [
                              'Credential details',
                              permission.manage
                                  ? 'Not supplied'
                                  : 'Integration management access required',
                          ] as Fact,
                      ]),
            ],
        },
        {
            key: 'sites',
            label: 'Sites & sync',
            facts: [
                ['Provider Site mappings', p.site_mapping.total],
                ['Mapped', p.site_mapping.mapped],
                ['Unmapped', p.site_mapping.unmapped],
                [
                    'Site records shown',
                    `${p.site_mapping.sites.length} of ${p.site_mapping.total}`,
                ],
                ...p.site_mapping.sites.flatMap((site, index): Fact[] => [
                    [`Site ${index + 1}`, site.name || 'Name not supplied'],
                    [`Site ${index + 1} record ID`, site.id],
                    [
                        `Site ${index + 1} mapping state`,
                        integrationLabel(site.state),
                    ],
                ]),
                ['Sync state', integrationLabel(p.sync.status)],
                ['Sync freshness', integrationLabel(p.sync.freshness)],
                ['Last sync', date(p.sync.last_synced_at)],
                ['Items processed', p.sync.items_processed],
                ['Items errored', p.sync.items_errored],
                ['Sites with old sync', p.sync.stale_site_count],
                ['Sites affected by sync', p.sync.affected_site_count],
                ['Sync summary', p.sync.summary || 'Not recorded'],
            ],
        },
        {
            key: 'health',
            label: 'Collected health',
            facts: [
                ['Collection state', healthLabel(p)],
                ['Collection freshness', integrationLabel(p.health.freshness)],
                ['Last attempted', date(p.health.last_attempted_at)],
                ['Last collected', date(p.health.last_collected_at)],
                ['Collection summary', p.health.summary],
                ['Next health action', p.health.action],
                [
                    'Health destination',
                    p.health.state === 'current' && !p.health.href
                        ? 'No action required for this collection'
                        : destination(p.health.href, p.health.action),
                ],
                [
                    'Monitoring support',
                    p.monitoring_support.state === 'supported'
                        ? 'Capability declared'
                        : 'Capability absent',
                ],
                ['Support scope', integrationLabel(p.monitoring_support.scope)],
                ['Support note', p.monitoring_support.note],
            ],
        },
        {
            key: 'runtime',
            label: 'Runtime capabilities',
            facts: [
                ['Contract version', p.runtime.version],
                ['Contract state', integrationLabel(p.runtime.contract_state)],
                ['Contract label', p.runtime.contract_label],
                ['Contract note', p.runtime.contract_note],
                ['Capabilities', list(p.runtime.capabilities)],
                ['Page limit', p.runtime.page_limit],
                [
                    'Minimum interval',
                    `${p.runtime.minimum_interval_seconds} seconds`,
                ],
                ['Backfill limit', p.runtime.backfill_limit],
                ['Cursor scopes', p.runtime.cursor_scopes],
                ['Partial scopes', p.runtime.partial_scopes],
                ['Runtime exceptions', p.runtime.exception_count],
                ['Latest completion', date(p.runtime.latest_completed_at)],
                ['Latest exception', date(p.runtime.latest_exception_at)],
                ['Exception codes', list(p.runtime.exception_codes)],
                [
                    'Disconnect availability',
                    permission.manage
                        ? p.runtime.disconnect_ready
                            ? 'Available in provider workspace'
                            : 'Not available'
                        : 'Integration management access required',
                ],
                [
                    'Revoke availability',
                    permission.manage
                        ? p.runtime.revoke_ready
                            ? 'Available in provider workspace'
                            : 'Not available'
                        : 'Integration management access required',
                ],
            ],
        },
        {
            key: 'reconciliation',
            label: 'Device reconciliation',
            facts: [
                ['Imported devices', p.reconciliation.imported_devices],
                ['Unassigned devices', p.reconciliation.unassigned_devices],
                ['Duplicate candidates', p.reconciliation.duplicate_candidates],
                ['Unsupported checks', p.reconciliation.unsupported_checks],
            ],
        },
        {
            key: 'actions',
            label: 'Required action',
            facts: p.exceptions.length
                ? p.exceptions.flatMap((e, index): Fact[] => [
                      [`Action ${index + 1} type`, integrationLabel(e.type)],
                      [`Action ${index + 1} summary`, e.summary],
                      [`Action ${index + 1} affected count`, e.count],
                      [`Action ${index + 1} next step`, e.action],
                      [
                          `Action ${index + 1} destination`,
                          destination(e.href, e.action),
                      ],
                  ])
                : [
                      [
                          'Reconciliation exceptions',
                          'No current reconciliation exceptions. Device health remains a separate reading.',
                      ],
                  ],
        },
        {
            key: 'policy',
            label: 'Catalogue boundaries',
            facts: [
                ['Read started', date(data.as_of)],
                [
                    'Sync becomes old after',
                    `${data.boundaries.sync_stale_after_hours} hours`,
                ],
                [
                    'Credential review cadence',
                    `${data.boundaries.credential_rotation_cadence_days} days`,
                ],
                ['Correlated alert owner', data.boundaries.alert_owner],
                ['Providers in catalogue', data.stats.providers_total],
                ['Connected providers', data.stats.providers_connected],
                ['Connection errors', data.stats.providers_errored],
                [
                    'Imported devices across providers',
                    data.stats.imported_devices,
                ],
                [
                    'Events in past 24 hours across providers',
                    data.stats.events_24h,
                ],
                [
                    'Reconciliation exceptions across providers',
                    data.stats.exceptions,
                ],
                [
                    'Mapped provider Sites across providers',
                    data.providers.reduce(
                        (n, row) => n + row.site_mapping.mapped,
                        0,
                    ),
                ],
                [
                    'Reading meaning',
                    'Connection, collection freshness and configured capability are separate readings. Counts can change between refreshes.',
                ],
            ],
        },
    ];
}
function presentation(
    p: Provider,
    view: IntegrationView,
): {
    subline: string;
    state: ReactNode;
    detail: string;
    metric?: EntityCardMetric;
} {
    const connection = (
            <StatusBadge variant={status(p.connection_status)}>
                {connectionLabel(p)}
            </StatusBadge>
        ),
        health = (
            <StatusBadge variant={status(p.health.state)}>
                {healthLabel(p)}
            </StatusBadge>
        );
    switch (view) {
        case 'connection':
            return {
                subline: `Last tested ${date(p.last_tested_at)}`,
                state: connection,
                detail:
                    p.credential?.reference_label ||
                    'Provider connection and credentials have separate records.',
            };
        case 'sites':
            return {
                subline: `${p.site_mapping.mapped} mapped · ${p.site_mapping.unmapped} unmapped`,
                state: (
                    <StatusBadge
                        variant={status(
                            p.sync.status === 'failed'
                                ? 'failed'
                                : p.sync.freshness,
                        )}
                    >
                        {integrationLabel(p.sync.status)} ·{' '}
                        {integrationLabel(p.sync.freshness)}
                    </StatusBadge>
                ),
                detail: `Last sync ${date(p.sync.last_synced_at)}`,
                metric: {
                    label: 'Provider Site mapping',
                    value: p.site_mapping.total
                        ? `${p.site_mapping.mapped} of ${p.site_mapping.total}`
                        : 'Not measured',
                    percent: p.site_mapping.total
                        ? (p.site_mapping.mapped / p.site_mapping.total) * 100
                        : null,
                },
            };
        case 'health':
            return {
                subline: `Last collected ${date(p.health.last_collected_at)}`,
                state: health,
                detail: p.health.summary,
            };
        case 'runtime':
            return {
                subline: p.runtime.contract_label,
                state: (
                    <StatusBadge variant="neutral">
                        {p.monitoring_support.state === 'supported'
                            ? 'Capability declared'
                            : 'Capability absent'}
                    </StatusBadge>
                ),
                detail: p.runtime.contract_note,
            };
        case 'actions':
            return {
                subline: `${p.exception_count} ${p.exception_count === 1 ? 'exception' : 'exceptions'}`,
                state: (
                    <StatusBadge
                        variant={p.exception_count ? 'warning' : 'neutral'}
                    >
                        {p.exception_count
                            ? 'Review required'
                            : 'No exceptions'}
                    </StatusBadge>
                ),
                detail:
                    p.exceptions[0]?.summary ||
                    'No current reconciliation exceptions; health remains a separate reading.',
            };
        default:
            return {
                subline: p.vendor,
                state: (
                    <>
                        {connection}
                        {health}
                        <EntityChip>{p.device_count} imported</EntityChip>
                    </>
                ),
                detail: p.summary,
            };
    }
}
export function IntegrationRecords({
    rows,
    data,
    permission,
    scope,
    layout,
    searching,
}: {
    rows: Provider[];
    data: IntegrationsProps;
    permission: IntegrationPermissions;
    scope: IntegrationView;
    layout: EvidenceRecordsView;
    searching: boolean;
}) {
    const actions = (p: Provider) => [
        {
            label: 'Open ' + p.name + ' diagnostics',
            icon: Plug,
            disabled: p.docs_href
                ? undefined
                : 'Integration management access is required for provider diagnostics.',
            onClick: () => {
                if (p.docs_href) router.visit(p.docs_href);
            },
        },
        ...(p.health.href
            ? [
                  {
                      label: p.health.action,
                      icon: Activity,
                      onClick: () => router.visit(p.health.href!),
                  },
              ]
            : []),
        ...p.exceptions.map((e, index) => ({
            label: `${e.action} (${index + 1})`,
            icon: Database,
            disabled: e.href
                ? undefined
                : 'Access to this destination is required. Ask an administrator.',
            onClick: () => {
                if (e.href) router.visit(e.href);
            },
        })),
    ];
    const sections = rows[0]
        ? integrationSections(rows[0], data, permission)
        : [];
    return (
        <EvidenceRecords<Provider>
            title={
                integrationViews.find((v) => v.key === scope)?.label ||
                'Providers'
            }
            description="Stored provider readings for your approved Sites. Connection status, collected health and declared capabilities describe different things."
            rows={rows}
            view={layout}
            rowKey={(p) => p.slug}
            icon={Plug}
            identity={(p) => ({
                name: p.name,
                subline: presentation(p, scope).subline,
                icon: Plug,
            })}
            articleLabel={(p) => 'Provider record: ' + p.name}
            actionsFor={actions}
            cardFor={(p) => {
                const record = presentation(p, scope);
                return {
                    name: p.name,
                    subline: record.subline,
                    icon: Plug,
                    meridian:
                        p.connection_status === 'error' ||
                        p.health.state === 'failed'
                            ? 'critical'
                            : p.exception_count ||
                                ['stale', 'partial'].includes(p.health.state)
                              ? 'warning'
                              : 'neutral',
                    chips: record.state,
                    metric: record.metric,
                    footer: { primary: record.detail },
                };
            }}
            compactColumns={[
                {
                    key: 'state',
                    label: 'Recorded state',
                    width: '280px',
                    cell: (p) => presentation(p, scope).state,
                },
                {
                    key: 'detail',
                    label: 'Recorded detail',
                    width: '380px',
                    cell: (p) => presentation(p, scope).detail,
                },
            ]}
            columns={sections.map((section) => ({
                key: section.key,
                label: section.label,
                width: '100%',
                cell: (p: Provider) =>
                    integrationSections(p, data, permission)
                        .find((s) => s.key === section.key)
                        ?.facts.map(([label, value]) => (
                            <ReviewRow
                                key={label}
                                label={label}
                                value={value}
                            />
                        )),
            }))}
            initialDetailSection={scope}
            empty={
                searching
                    ? 'No matching providers'
                    : 'No provider records returned; connection and health are not measured'
            }
        />
    );
}

export function CredentialRotationStatus({
    state,
    cadenceDays,
    ...props
}: {
    state: string;
    cadenceDays: number;
} & HTMLAttributes<HTMLParagraphElement>) {
    const presentation =
        state === 'rotation_due'
            ? {
                  label: `Rotation due (${cadenceDays}-day cadence)`,
                  variant: 'warning',
                  className: 'text-status-warning',
              }
            : state === 'current'
              ? {
                    label: 'Rotation current',
                    variant: 'default',
                    className: 'text-muted-foreground',
                }
              : state === 'not_configured'
                ? {
                      label: 'Credential not configured',
                      variant: 'secondary',
                      className: 'text-muted-foreground',
                  }
                : {
                      label: 'Rotation status unknown',
                      variant: 'secondary',
                      className: 'text-muted-foreground',
                  };

    return (
        <p
            {...props}
            className={`${presentation.className} ${props.className ?? ''}`.trim()}
            data-variant={presentation.variant}
        >
            {presentation.label}
        </p>
    );
}
