import {
    PageHeaderGlassButton,
    PageHeaderPrimaryButton,
} from '@/components/page/page-header';
import { SettingsNotice } from '@/components/settings/settings-notice';
import { Input } from '@/components/ui/input';
import { Field } from '@/components/wizard/primitives';
import type { SharedData } from '@/types';
import { Link, router, usePage } from '@inertiajs/react';
import { useState } from 'react';
import { Toggle } from '../connected/_forms';
import {
    BoundedTable,
    ConnectedHeader,
    RecordPicker,
    ReviewWizard,
    useCommand,
} from '../connected/_shared';
export type Connection = {
    id: number;
    name: string;
    partner_key: string;
    site_ids: number[];
    enabled: boolean;
    version: number;
    protocol_label: string;
};
type Props = {
    enabled: boolean;
    installed: boolean;
    connections: Connection[];
    partners: {
        key: string;
        label: string;
        protocol_label: string;
        site_ids: number[];
    }[];
    sites: { id: number; name: string }[];
    can_manage: boolean;
    unavailable_reason: string | null;
};
export default function PharmacyConnections(props: Props) {
    const { auth } = usePage<SharedData>().props;
    const [q, setQ] = useState('');
    const [edit, setEdit] = useState<Connection | 'new' | null>(null);
    return (
        <ConnectedHeader
            title="Pharmacy connections"
            subline="Approved house connections and tracked supply-order delivery"
            view="connections"
            tabs={[{ key: 'connections', label: 'Connections' }]}
            onView={() => {}}
            query={q}
            onQuery={setQ}
            actions={
                <>
                    {auth.can?.medications?.stockUpdate && (
                        <PageHeaderGlassButton asChild>
                            <Link href="/emar/stock?view=orders">
                                Pharmacy orders
                            </Link>
                        </PageHeaderGlassButton>
                    )}
                    {props.can_manage && (
                        <PageHeaderPrimaryButton
                            disabled={!props.partners.length}
                            onClick={() => setEdit('new')}
                        >
                            Add connection
                        </PageHeaderPrimaryButton>
                    )}
                </>
            }
            meters={[
                {
                    label: 'Connections',
                    value: props.connections.length,
                    caption: 'Configured records',
                    view: 'connections',
                },
                {
                    label: 'Enabled',
                    value: props.connections.filter((c) => c.enabled).length,
                    caption: 'Connection setting',
                    view: 'connections',
                },
                {
                    label: 'Approved partners',
                    value: props.partners.length,
                    caption: 'Configured by the organisation',
                    view: 'connections',
                },
                {
                    label: 'Houses',
                    value: new Set(props.connections.flatMap((c) => c.site_ids))
                        .size,
                    caption: 'Covered by a connection',
                    view: 'connections',
                },
            ]}
        >
            {props.unavailable_reason && (
                <SettingsNotice>
                    {(
                        {
                            installation_required:
                                'Pharmacy connections are waiting for installation.',
                            connection_disabled:
                                'Connected ordering is switched off.',
                            partner_not_ready:
                                'An approved pharmacy supplier has not been configured.',
                        } as Record<string, string>
                    )[props.unavailable_reason] ??
                        'The connection is not ready. Ask the organisation to review the supplier setup.'}
                </SettingsNotice>
            )}
            <SettingsNotice>
                Send from a pharmacy supply order after checking its person,
                medicine and quantity. Delivery, pharmacy acceptance and
                physical stock receipt are separate steps.
            </SettingsNotice>
            <BoundedTable
                rows={props.connections.filter((c) =>
                    c.name.toLowerCase().includes(q.toLowerCase()),
                )}
                identity={(c) => ({ name: c.name, subline: c.protocol_label })}
                columns={[
                    {
                        key: 'houses',
                        label: 'Houses',
                        width: '2fr',
                        cell: (c) =>
                            props.sites
                                .filter((s) => c.site_ids.includes(s.id))
                                .map((s) => s.name)
                                .join(', '),
                    },
                    {
                        key: 'enabled',
                        label: 'Connection',
                        width: '1fr',
                        cell: (c) => (c.enabled ? 'Enabled' : 'Off'),
                    },
                    {
                        key: 'version',
                        label: 'Revision',
                        width: '100px',
                        cell: (c) => c.version,
                    },
                ]}
                open={props.can_manage ? setEdit : undefined}
                empty="No pharmacy connections are configured. An approved supplier endpoint must be configured before a connection can be added."
            />
            {edit && (
                <ConnectionWizard
                    props={props}
                    connection={edit === 'new' ? null : edit}
                    onClose={() => setEdit(null)}
                />
            )}
        </ConnectedHeader>
    );
}
function ConnectionWizard({
    props,
    connection: c,
    onClose,
}: {
    props: Props;
    connection: Connection | null;
    onClose: () => void;
}) {
    const command = useCommand();
    const [saved, setSaved] = useState(false);
    const [name, setName] = useState(c?.name ?? '');
    const [partner, setPartner] = useState(c?.partner_key ?? '');
    const [sites, setSites] = useState<number[]>(c?.site_ids ?? []);
    const [enabled, setEnabled] = useState(c?.enabled ?? false);
    const selected = props.partners.find((p) => p.key === partner);
    return (
        <ReviewWizard
            title={c ? 'Edit pharmacy connection' : 'Add pharmacy connection'}
            description="Use an organisation-approved supplier connection"
            onClose={onClose}
            saved={saved}
            busy={command.busy}
            error={command.error}
            disabled={command.uncertain}
            onSave={async () => {
                if (
                    await command.run(
                        '/emar/pharmacy-connections' + (c ? '/' + c.id : ''),
                        {
                            name,
                            partner_key: partner,
                            site_ids: sites,
                            enabled,
                            expected_version: c?.version,
                        },
                        c ? 'put' : 'post',
                    )
                ) {
                    setSaved(true);
                    router.reload();
                }
            }}
            steps={[
                {
                    label: 'Connection',
                    valid: !!name && !!partner,
                    content: (
                        <div className="space-y-4">
                            <Field label="Connection name" required>
                                <Input
                                    value={name}
                                    onChange={(e) => setName(e.target.value)}
                                />
                            </Field>
                            <RecordPicker
                                label="Approved supplier"
                                value={partner}
                                disabled={!!c}
                                options={props.partners.map((p) => ({
                                    value: p.key,
                                    label: p.label,
                                    description: p.protocol_label,
                                }))}
                                onChange={(v) => {
                                    setPartner(v);
                                    setSites([]);
                                }}
                            />
                            <Toggle
                                label="Enable this connection"
                                checked={enabled}
                                onChange={setEnabled}
                            />
                        </div>
                    ),
                },
                {
                    label: 'Approved houses',
                    valid: sites.length > 0,
                    content: (
                        <div className="space-y-3">
                            {props.sites
                                .filter((s) =>
                                    selected?.site_ids.includes(s.id),
                                )
                                .map((s) => (
                                    <Toggle
                                        key={s.id}
                                        label={s.name}
                                        checked={sites.includes(s.id)}
                                        onChange={(v) =>
                                            setSites(
                                                v
                                                    ? [...sites, s.id]
                                                    : sites.filter(
                                                          (id) => id !== s.id,
                                                      ),
                                            )
                                        }
                                    />
                                ))}
                            <SettingsNotice>
                                Only houses approved for this supplier can be
                                connected. Endpoint details and credentials are
                                managed separately by the organisation.
                            </SettingsNotice>
                        </div>
                    ),
                },
            ]}
            review={[
                { label: 'Name', value: name },
                { label: 'Supplier', value: selected?.label },
                {
                    label: 'Houses',
                    value: props.sites
                        .filter((s) => sites.includes(s.id))
                        .map((s) => s.name)
                        .join(', '),
                },
                { label: 'Connection', value: enabled ? 'Enabled' : 'Off' },
            ]}
        />
    );
}
