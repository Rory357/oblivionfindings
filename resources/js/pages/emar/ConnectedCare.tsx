import {
    PageHeaderGlassButton,
    PageHeaderPrimaryButton,
} from '@/components/page/page-header';
import { RecordPicker } from '@/components/people-locations/record-picker';
import { SettingsModal } from '@/components/settings/settings-modal';
import { SettingsNotice } from '@/components/settings/settings-notice';
import { Button } from '@/components/ui/button';
import { StatusBadge } from '@/components/ui/status-badge';
import { ReviewCard, ReviewRow } from '@/components/wizard/shell';
import { formatDateOnly, formatDateTime } from '@/lib/datetime';
import { emarScopedHref } from '@/lib/emar-navigation';
import { withMedicationReturn } from '@/lib/medication-navigation';
import { Link, router, usePage } from '@inertiajs/react';
import { ArrowLeftRight } from 'lucide-react';
import { useState } from 'react';
import {
    AccessWizard,
    ProposalDecision,
    RevokeAccess,
    TransferAction,
    TransferWizard,
} from './connected/_forms';
import { HandoverFacts } from './connected/_handover';
import {
    BoundedTable,
    ConnectedHeader,
    ServerPages,
    useWorkspaceView,
} from './connected/_shared';
import type { ConnectedProps, Transfer } from './connected/_types';

export default function ConnectedCare(props: ConnectedProps) {
    // EA-086: only the tabs this person can use, opening on the first of them.
    const tabs = [
        props.can.manage_access && {
            key: 'access',
            label: 'Prescriber access',
        },
        props.can.manage_orders && { key: 'requests', label: 'Requests' },
        props.can.transfer && {
            key: 'transfers',
            label: 'Provider handovers',
        },
    ].filter((t): t is { key: string; label: string } => !!t);
    const [view, setView] = useWorkspaceView(
        tabs[0]?.key ?? 'requests',
        tabs.map((t) => t.key),
    );
    const [q, setQ] = useState('');
    const [modal, setModal] = useState<
        'clinician' | 'grant' | 'transfer' | null
    >(null);
    const [decision, setDecision] = useState<
        ConnectedProps['proposals'][number] | null
    >(null);
    const [transfer, setTransfer] = useState<Transfer | null>(null);
    const [transition, setTransition] = useState<string | null>(null);
    const [revoke, setRevoke] = useState<{
        kind: 'clinicians' | 'grants';
        id: number;
        name: string;
    } | null>(null);
    const { url: pageUrl } = usePage();
    const matches = (s: string) => s.toLowerCase().includes(q.toLowerCase());
    const saved = () => router.reload();
    const person = props.selected_client;
    const activeGrants = props.grants.filter((g) => g.active);
    return (
        <ConnectedHeader
            title="Connected care"
            parent={{ title: 'Orders & reviews', href: '/emar/prescriptions' }}
            subline="Named prescriber access, reviewed requests and provider handovers"
            view={view}
            onView={(v) => {
                setView(v);
                setQ('');
            }}
            tabs={tabs}
            query={q}
            onQuery={setQ}
            actions={
                <>
                    <PageHeaderGlassButton asChild>
                        <Link
                            href={withMedicationReturn(
                                emarScopedHref('/emar/prescriptions', pageUrl),
                                pageUrl,
                            )}
                        >
                            Medication orders
                        </Link>
                    </PageHeaderGlassButton>
                    {person &&
                        (view === 'access' && props.can.manage_access ? (
                            <PageHeaderPrimaryButton
                                onClick={() => setModal('clinician')}
                            >
                                Add prescriber
                            </PageHeaderPrimaryButton>
                        ) : view === 'transfers' && props.can.transfer ? (
                            <PageHeaderPrimaryButton
                                onClick={() => setModal('transfer')}
                            >
                                New handover
                            </PageHeaderPrimaryButton>
                        ) : null)}
                </>
            }
            filters={
                <RecordPicker
                    variant="header"
                    label="Person"
                    value={person ? String(person.id) : ''}
                    options={props.clients.map((c) => ({
                        value: String(c.id),
                        label: c.name,
                    }))}
                    onChange={(id) =>
                        router.get(
                            '/emar/connected-care',
                            { client_id: id },
                            { preserveState: false },
                        )
                    }
                />
            }
            meters={[
                {
                    label: 'Prescribers',
                    value: props.clinicians.filter((c) => !c.revoked_at).length,
                    caption: 'Identities in this batch',
                    view: 'access',
                },
                {
                    label: 'Active access',
                    value: activeGrants.length,
                    caption: 'Current grants in this batch',
                    view: 'access',
                },
                {
                    label: 'Requests',
                    value: props.proposals.filter(
                        (p) => p.status === 'submitted',
                    ).length,
                    caption: 'Awaiting review in this batch',
                    view: 'requests',
                },
                {
                    label: 'Handovers',
                    value: props.transfers.filter(
                        (t) => !['cancelled', 'reconciled'].includes(t.status),
                    ).length,
                    caption: 'Open in this batch',
                    view: 'transfers',
                },
            ].filter((m) => tabs.some((t) => t.key === m.view))}
        >
            {view === 'requests' ? (
                <>
                    {person && (
                        <p className="text-caption">
                            {person.name} ·{' '}
                            {formatDateOnly(person.date_of_birth)}
                        </p>
                    )}
                    <BoundedTable
                        rows={props.proposals.filter((p) =>
                            matches(
                                p.client_name +
                                    ' ' +
                                    p.clinician_name +
                                    ' ' +
                                    p.reason +
                                    ' ' +
                                    (p.prescription?.name ?? ''),
                            ),
                        )}
                        identity={(p) => ({
                            name:
                                p.prescription?.name ??
                                'Stop medication request',
                            subline: person
                                ? p.clinician_name
                                : p.client_name + ' · ' + p.clinician_name,
                        })}
                        columns={[
                            {
                                key: 'kind',
                                label: 'Request',
                                width: '1fr',
                                cell: (p) => p.kind,
                            },
                            {
                                key: 'date',
                                label: 'Submitted',
                                width: '1fr',
                                cell: (p) => formatDateTime(p.submitted_at),
                            },
                            {
                                key: 'status',
                                label: 'State',
                                width: '1fr',
                                cell: (p) => <StatusBadge status={p.status} />,
                            },
                        ]}
                        open={setDecision}
                        empty={
                            person
                                ? 'No prescriber requests for this person.'
                                : 'No prescriber requests for the people you can see.'
                        }
                    />
                </>
            ) : !person ? (
                <SettingsNotice>
                    Choose a person to review access and handovers.
                </SettingsNotice>
            ) : (
                <>
                    <p className="text-caption">
                        {person.name} · {formatDateOnly(person.date_of_birth)} ·
                        Access is checked again whenever a record is opened or
                        changed.
                    </p>
                    {view === 'access' && (
                        <>
                            <div className="flex items-center justify-between">
                                <h2 className="text-section-heading">
                                    Access to {person.name}
                                </h2>
                                {props.can.manage_access && (
                                    <Button
                                        variant="outline"
                                        onClick={() => setModal('grant')}
                                    >
                                        Grant named access
                                    </Button>
                                )}
                            </div>
                            <BoundedTable
                                rows={props.grants.filter((g) =>
                                    matches(g.clinician_name + ' ' + g.purpose),
                                )}
                                identity={(g) => ({
                                    name: g.clinician_name,
                                    subline: g.purpose,
                                })}
                                columns={[
                                    {
                                        key: 'scope',
                                        label: 'Scope',
                                        width: '1fr',
                                        cell: (g) => (
                                            <>
                                                {g.can_propose
                                                    ? 'Chart and requests'
                                                    : 'Read chart'}
                                                {g.include_controlled
                                                    ? ' · controlled included'
                                                    : ''}
                                            </>
                                        ),
                                    },
                                    {
                                        key: 'expiry',
                                        label: 'Ends',
                                        width: '1fr',
                                        cell: (g) =>
                                            formatDateTime(g.expires_at),
                                    },
                                    {
                                        key: 'views',
                                        label: 'Chart opened',
                                        width: '1fr',
                                        cell: (g) =>
                                            g.views
                                                ? g.views +
                                                  (g.views === 1
                                                      ? ' time'
                                                      : ' times') +
                                                  (g.last_viewed_at
                                                      ? ' · last ' +
                                                        formatDateTime(
                                                            g.last_viewed_at,
                                                        )
                                                      : '')
                                                : 'Not yet',
                                    },
                                    {
                                        key: 'state',
                                        label: 'Access',
                                        width: '100px',
                                        cell: (g) => (
                                            <StatusBadge
                                                variant={
                                                    g.active
                                                        ? 'success'
                                                        : 'warning'
                                                }
                                            >
                                                {(
                                                    {
                                                        ready: 'Active',
                                                        account_setup_required:
                                                            'Account setup needed',
                                                        account_unavailable:
                                                            'Account unavailable',
                                                        identity_unavailable:
                                                            'Identity review needed',
                                                        site_changed:
                                                            'House changed',
                                                        expired: 'Expired',
                                                        revoked: 'Revoked',
                                                    } as Record<string, string>
                                                )[g.availability] ??
                                                    'Review needed'}
                                            </StatusBadge>
                                        ),
                                    },
                                ]}
                                open={
                                    props.can.manage_access
                                        ? (g) =>
                                              !g.revoked_at &&
                                              setRevoke({
                                                  kind: 'grants',
                                                  id: g.id,
                                                  name: g.clinician_name,
                                              })
                                        : undefined
                                }
                                empty="No named access has been granted."
                            />
                            <h2 className="text-section-heading">
                                Verified prescribers
                            </h2>
                            <BoundedTable
                                rows={props.clinicians.filter((c) =>
                                    matches(c.name + ' ' + c.provider_name),
                                )}
                                identity={(c) => ({
                                    name: c.name,
                                    subline: c.email,
                                })}
                                columns={[
                                    {
                                        key: 'provider',
                                        label: 'Provider',
                                        width: '1fr',
                                        cell: (c) => c.provider_name,
                                    },
                                    {
                                        key: 'registration',
                                        label: 'Registration',
                                        width: '1fr',
                                        cell: (c) =>
                                            c.registration_authority +
                                            ' ' +
                                            c.registration_number,
                                    },
                                    {
                                        key: 'expiry',
                                        label: 'Identity review ends',
                                        width: '1fr',
                                        cell: (c) =>
                                            c.revoked_at
                                                ? 'Revoked'
                                                : formatDateTime(c.expires_at),
                                    },
                                ]}
                                open={
                                    props.can.revoke_identity
                                        ? (c) =>
                                              !c.revoked_at &&
                                              setRevoke({
                                                  kind: 'clinicians',
                                                  id: c.id,
                                                  name: c.name,
                                              })
                                        : undefined
                                }
                            />
                            <SettingsNotice>
                                Prescribers use the separate clinical portal
                                after mailbox verification and two-factor setup.
                                Creating an identity does not send an invitation
                                or grant access to anyone’s chart. Withdrawing
                                an identity ends access across all houses and
                                requires organisation-wide authority. Use named
                                access above to stop access for one person.
                            </SettingsNotice>
                        </>
                    )}
                    {view === 'transfers' && (
                        <BoundedTable
                            rows={props.transfers.filter((t) =>
                                matches(
                                    t.provider_name + ' ' + t.recipient_name,
                                ),
                            )}
                            identity={(t) => ({
                                name: t.provider_name,
                                subline: t.recipient_name,
                            })}
                            columns={[
                                {
                                    key: 'direction',
                                    label: 'Direction',
                                    width: '1fr',
                                    cell: (t) =>
                                        t.direction === 'incoming'
                                            ? 'Incoming'
                                            : 'Outgoing',
                                },
                                {
                                    key: 'purpose',
                                    label: 'Purpose',
                                    width: '1.5fr',
                                    cell: (t) => t.purpose,
                                },
                                {
                                    key: 'status',
                                    label: 'State',
                                    width: '1fr',
                                    cell: (t) => (
                                        <StatusBadge status={t.status} />
                                    ),
                                },
                            ]}
                            open={setTransfer}
                            empty="No provider handovers for this person."
                        />
                    )}
                </>
            )}
            {(person || view === 'requests') && (
                <ServerPages
                    meta={
                        props.pagination?.[
                            view === 'requests'
                                ? 'proposals'
                                : view === 'transfers'
                                  ? 'transfers'
                                  : 'grants'
                        ]
                    }
                    name={
                        view === 'requests'
                            ? 'proposals'
                            : view === 'transfers'
                              ? 'transfers'
                              : 'grants'
                    }
                    path="/emar/connected-care"
                />
            )}
            {view === 'access' && (
                <ServerPages
                    meta={props.pagination?.clinicians}
                    name="clinicians"
                    path="/emar/connected-care"
                />
            )}
            {modal &&
                (modal === 'transfer' ? (
                    <TransferWizard
                        props={props}
                        onClose={() => setModal(null)}
                        onSaved={saved}
                    />
                ) : (
                    <AccessWizard
                        kind={modal}
                        props={props}
                        onClose={() => setModal(null)}
                        onSaved={saved}
                    />
                ))}
            {decision && (
                <ProposalDecision
                    proposal={decision}
                    canManage={props.can.manage_orders}
                    witnesses={props.witnesses}
                    onClose={() => setDecision(null)}
                    onSaved={saved}
                />
            )}
            {revoke && (
                <RevokeAccess
                    {...revoke}
                    clientId={person?.id ?? 0}
                    onClose={() => setRevoke(null)}
                    onSaved={saved}
                />
            )}
            {transfer && !transition && (
                <SettingsModal
                    width={900}
                    title={'Handover · ' + transfer.provider_name}
                    description={
                        transfer.client_name + ' · version ' + transfer.version
                    }
                    onClose={() => setTransfer(null)}
                    footer={
                        <>
                            <Button
                                variant="outline"
                                onClick={() => setTransfer(null)}
                            >
                                Close
                            </Button>
                            {props.can.export &&
                                transfer.status !== 'draft' &&
                                transfer.status !== 'cancelled' && (
                                    <Button asChild variant="outline">
                                        <a
                                            href={
                                                '/emar/connected-care/transfers/' +
                                                transfer.id +
                                                '/packet'
                                            }
                                        >
                                            Download reviewed packet
                                        </a>
                                    </Button>
                                )}
                            {props.can.transfer &&
                                transfer.allowed_actions
                                    ?.filter((a) => a !== 'cancel')
                                    .map((a) => (
                                        <Button
                                            key={a}
                                            onClick={() => setTransition(a)}
                                        >
                                            {
                                                (
                                                    {
                                                        review: 'Review handover',
                                                        receipt:
                                                            'Record receipt',
                                                        start_reconciliation:
                                                            'Start reconciliation',
                                                        complete:
                                                            'Complete reconciliation',
                                                    } as Record<string, string>
                                                )[a]
                                            }
                                        </Button>
                                    ))}
                        </>
                    }
                >
                    <ReviewCard icon={ArrowLeftRight} title="Provider handover">
                        <ReviewRow
                            label="Recipient"
                            value={transfer.recipient_name}
                        />
                        <ReviewRow label="Purpose" value={transfer.purpose} />
                        <ReviewRow
                            label="Sharing basis"
                            value={transfer.disclosure_basis}
                        />
                        <ReviewRow
                            label="Identity evidence"
                            value={transfer.identity_evidence}
                        />
                        <ReviewRow
                            label="State"
                            value={<StatusBadge status={transfer.status} />}
                        />
                    </ReviewCard>
                    <HandoverFacts transfer={transfer} />
                    {transfer.reconciliation_id && (
                        <Button asChild variant="outline">
                            <Link
                                href={withMedicationReturn(
                                    emarScopedHref(
                                        '/emar/prescriptions?view=reconciliation&client_id=' +
                                            transfer.client_id +
                                            '&reconciliation_id=' +
                                            transfer.reconciliation_id,
                                        pageUrl,
                                    ),
                                    pageUrl,
                                )}
                            >
                                Open medication reconciliation
                            </Link>
                        </Button>
                    )}
                    <SettingsNotice>
                        Review the full packet and source evidence. Incoming
                        facts remain unverified until medication reconciliation
                        is signed off. Export does not stop medication or
                        discharge the person.
                    </SettingsNotice>
                    {props.can.transfer &&
                        transfer.allowed_actions?.includes('cancel') && (
                            <Button
                                variant="outline"
                                onClick={() => setTransition('cancel')}
                            >
                                Cancel handover
                            </Button>
                        )}
                </SettingsModal>
            )}
            {transfer && transition && (
                <TransferAction
                    transfer={transfer}
                    action={transition}
                    onClose={() => {
                        setTransition(null);
                        setTransfer(null);
                    }}
                    onSaved={saved}
                />
            )}
        </ConnectedHeader>
    );
}
