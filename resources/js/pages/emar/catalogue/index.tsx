import {
    PageHeaderGlassButton,
    PageHeaderPrimaryButton,
} from '@/components/page/page-header';
import { SettingsModal } from '@/components/settings/settings-modal';
import { SettingsNotice } from '@/components/settings/settings-notice';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { StatusBadge } from '@/components/ui/status-badge';
import { Field } from '@/components/wizard/primitives';
import { ReviewRow } from '@/components/wizard/shell';
import { formatDateTime } from '@/lib/datetime';
import { Link, router } from '@inertiajs/react';
import { useState } from 'react';
import { Toggle } from '../connected/_forms';
import {
    BoundedTable,
    ConnectedHeader,
    NzDateTime,
    ReviewWizard,
    ServerPages,
    SingleFile,
    multipart,
    useCommand,
} from '../connected/_shared';
export type Product = {
    id: number;
    code_system: string;
    code: string;
    name: string;
    strength: string;
    form: string;
    has_photo: boolean;
    photo_url: string | null;
};
export type Source = {
    id: number;
    supplier: string;
    source_name: string;
    source_version: string;
    attribution: string;
    licence_reference: string;
    status: string;
    version: number;
    reviewed_at: string | null;
    expires_at: string | null;
    product_count: number;
    products: Product[];
};
type Props = {
    sources: Source[];
    can_manage: boolean;
    notice: string;
    source_meta?: { current_page: number; last_page: number; total: number };
};
export default function Catalogue(props: Props) {
    const [q, setQ] = useState('');
    const [source, setSource] = useState<Source | null>(null);
    const [create, setCreate] = useState(false);
    const [action, setAction] = useState<
        'import' | 'review' | 'revoke' | Product | null
    >(null);
    const [productQuery, setProductQuery] = useState('');
    const close = () => {
        setAction(null);
        setSource(null);
        router.reload();
    };
    return (
        <ConnectedHeader
            title="Medicine picture library"
            subline="Licensed product images with exact matching, source review and expiry"
            view="sources"
            tabs={[{ key: 'sources', label: 'Source versions' }]}
            onView={() => {}}
            query={q}
            onQuery={setQ}
            actions={
                <>
                    <PageHeaderGlassButton asChild>
                        <Link href="/emar/settings#rules/medicines">
                            Medication settings
                        </Link>
                    </PageHeaderGlassButton>
                    {props.can_manage && (
                        <PageHeaderPrimaryButton
                            onClick={() => setCreate(true)}
                        >
                            Add licensed source
                        </PageHeaderPrimaryButton>
                    )}
                </>
            }
            meters={[
                {
                    label: 'Source versions',
                    value: props.sources.length,
                    caption: 'In this batch',
                    view: 'sources',
                },
                {
                    label: 'Reviewed',
                    value: props.sources.filter((s) => s.status === 'reviewed')
                        .length,
                    caption: 'Current source reviews',
                    view: 'sources',
                },
                {
                    label: 'Products',
                    value: props.sources.reduce(
                        (n, s) => n + s.product_count,
                        0,
                    ),
                    caption: 'In these sources',
                    view: 'sources',
                },
                {
                    label: 'Need review',
                    value: props.sources.filter((s) =>
                        ['draft', 'expired'].includes(s.status),
                    ).length,
                    caption: 'Draft or expired sources',
                    view: 'sources',
                },
            ]}
        >
            <SettingsNotice>{props.notice}</SettingsNotice>
            <BoundedTable
                rows={props.sources.filter((s) =>
                    (s.supplier + ' ' + s.source_name + ' ' + s.source_version)
                        .toLowerCase()
                        .includes(q.toLowerCase()),
                )}
                identity={(s) => ({
                    name: s.source_name,
                    subline: s.supplier + ' · ' + s.source_version,
                })}
                columns={[
                    {
                        key: 'count',
                        label: 'Products',
                        width: '100px',
                        cell: (s) => s.product_count,
                    },
                    {
                        key: 'state',
                        label: 'State',
                        width: '1fr',
                        cell: (s) => <StatusBadge status={s.status} />,
                    },
                    {
                        key: 'expiry',
                        label: 'Review expires',
                        width: '1.5fr',
                        cell: (s) =>
                            s.expires_at
                                ? formatDateTime(s.expires_at)
                                : 'Not reviewed',
                    },
                ]}
                open={(s) => {
                    setSource(s);
                    setProductQuery('');
                }}
                empty="No licensed source has been added. Add an approved dataset and its licensed images, then review the source before it can be used."
            />
            <ServerPages
                name="source"
                path="/emar/catalogue"
                meta={props.source_meta}
            />
            {create && (
                <SourceWizard
                    onClose={() => {
                        setCreate(false);
                        router.reload();
                    }}
                />
            )}
            {source && !action && (
                <SettingsModal
                    width={900}
                    title={source.source_name + ' · ' + source.source_version}
                    description={source.supplier}
                    onClose={close}
                    footer={
                        <>
                            <Button variant="outline" onClick={close}>
                                Close
                            </Button>
                            {props.can_manage && source.status === 'draft' && (
                                <>
                                    <Button
                                        variant="outline"
                                        disabled={source.product_count > 0}
                                        onClick={() => setAction('import')}
                                    >
                                        Import product list
                                    </Button>
                                    <Button
                                        disabled={!source.product_count}
                                        onClick={() => setAction('review')}
                                    >
                                        Review source
                                    </Button>
                                </>
                            )}
                            {props.can_manage &&
                                source.status !== 'revoked' && (
                                    <Button
                                        variant="outline"
                                        onClick={() => setAction('revoke')}
                                    >
                                        Revoke source
                                    </Button>
                                )}
                        </>
                    }
                >
                    <ReviewRow
                        label="Licence reference"
                        value={source.licence_reference}
                    />
                    <ReviewRow label="Attribution" value={source.attribution} />
                    <ReviewRow
                        label="State"
                        value={<StatusBadge status={source.status} />}
                    />
                    <Input
                        aria-label="Search source products"
                        placeholder="Search product name, code or strength"
                        value={productQuery}
                        onChange={(e) => setProductQuery(e.target.value)}
                    />
                    <BoundedTable
                        rows={source.products.filter((p) =>
                            (p.name + ' ' + p.code + ' ' + p.strength)
                                .toLowerCase()
                                .includes(productQuery.toLowerCase()),
                        )}
                        identity={(p) => ({
                            name: p.name,
                            subline: p.strength + ' · ' + p.form,
                        })}
                        columns={[
                            {
                                key: 'code',
                                label: 'Product identifier',
                                width: '1fr',
                                cell: (p) => p.code_system + ' · ' + p.code,
                            },
                            {
                                key: 'photo',
                                label: 'Image',
                                width: '130px',
                                cell: (p) =>
                                    p.photo_url ? (
                                        <img
                                            alt={
                                                p.name +
                                                ' ' +
                                                p.strength +
                                                ' ' +
                                                p.form
                                            }
                                            src={p.photo_url}
                                            className="h-12 w-24 object-contain"
                                        />
                                    ) : (
                                        'Not supplied'
                                    ),
                            },
                        ]}
                        open={
                            props.can_manage && source.status === 'draft'
                                ? (p) => setAction(p)
                                : undefined
                        }
                    />
                    <SettingsNotice>
                        Published source versions are immutable. Add a new
                        source version for replacement products or images. An
                        image supports identification; always check the current
                        chart and packaging.
                    </SettingsNotice>
                </SettingsModal>
            )}
            {source && action && (
                <SourceAction source={source} action={action} onClose={close} />
            )}
        </ConnectedHeader>
    );
}
function SourceWizard({ onClose }: { onClose: () => void }) {
    const command = useCommand();
    const [saved, setSaved] = useState(false);
    const [form, setForm] = useState({
        supplier: '',
        source_name: '',
        source_version: '',
        attribution: '',
        licence_reference: '',
        licence_attested: false,
    });
    return (
        <ReviewWizard
            title="Add licensed image source"
            description="Record provenance before importing product images"
            onClose={onClose}
            saved={saved}
            busy={command.busy}
            error={command.error}
            disabled={command.uncertain}
            saveLabel="Save source draft"
            onSave={async () => {
                if (await command.run('/emar/catalogue/sources', form)) {
                    setSaved(true);
                }
            }}
            success="Source draft saved"
            successDetail="Open the source to import its product list and images, then complete publication review."
            steps={[
                {
                    label: 'Source and licence',
                    valid: Object.entries(form).every(([, v]) => !!v),
                    content: (
                        <div className="space-y-4">
                            {(
                                [
                                    ['supplier', 'Supplier / owner'],
                                    ['source_name', 'Catalogue name'],
                                    ['source_version', 'Supplier version'],
                                    [
                                        'attribution',
                                        'Required image attribution',
                                    ],
                                    [
                                        'licence_reference',
                                        'Licence / permission reference',
                                    ],
                                ] as const
                            ).map(([k, label]) => (
                                <Field key={k} label={label} required>
                                    <Input
                                        value={form[k]}
                                        onChange={(e) =>
                                            setForm({
                                                ...form,
                                                [k]: e.target.value,
                                            })
                                        }
                                    />
                                </Field>
                            ))}
                            <Toggle
                                label="I verified permission to use and display these images"
                                checked={form.licence_attested}
                                onChange={(v) =>
                                    setForm({ ...form, licence_attested: v })
                                }
                            />
                        </div>
                    ),
                },
            ]}
            review={Object.entries(form)
                .filter(([k]) => k !== 'licence_attested')
                .map(([k, v]) => ({
                    label: k.replaceAll('_', ' '),
                    value: String(v),
                }))}
        />
    );
}
function SourceAction({
    source: s,
    action,
    onClose,
}: {
    source: Source;
    action: 'import' | 'review' | 'revoke' | Product;
    onClose: () => void;
}) {
    const command = useCommand();
    const [saved, setSaved] = useState(false);
    const [file, setFile] = useState<File | null>(null);
    const [expiry, setExpiry] = useState('');
    const [confirmed, setConfirmed] = useState(false);
    const [key] = useState(() => crypto.randomUUID());
    const photo = typeof action !== 'string';
    const title = photo
        ? 'Add product image'
        : action === 'import'
          ? 'Import product list'
          : action === 'review'
            ? 'Publish reviewed source'
            : 'Revoke source';
    const save = async () => {
        const suffix = photo ? 'products/' + action.id + '/photo' : action;
        const data = photo
            ? multipart({ photo: file, version: s.version, request_uuid: key })
            : action === 'import'
              ? multipart({ dataset: file, version: s.version })
              : { version: s.version, expires_at: expiry };
        if (
            await command.run(
                '/emar/catalogue/sources/' + s.id + '/' + suffix,
                data,
            )
        ) {
            setSaved(true);
        }
    };
    return (
        <ReviewWizard
            title={title}
            description={s.source_name + ' · ' + s.source_version}
            onClose={onClose}
            saved={saved}
            busy={command.busy}
            error={command.error}
            disabled={command.uncertain}
            onSave={() => void save()}
            saveLabel={title}
            steps={[
                {
                    label: 'Evidence',
                    valid:
                        photo || action === 'import'
                            ? !!file && file.size <= 2 * 1024 * 1024
                            : action === 'review'
                              ? !!expiry && confirmed
                              : confirmed,
                    content: (
                        <div className="space-y-4">
                            {photo ? (
                                <>
                                    <p className="text-sm font-semibold">
                                        {action.name} · {action.strength} ·{' '}
                                        {action.form} · {action.code_system}:
                                        {action.code}
                                    </p>
                                    <SingleFile
                                        file={file}
                                        onChange={setFile}
                                        accept="image/jpeg,image/png,image/webp"
                                        hint="Licensed JPEG, PNG or WebP · 2 MB maximum · up to 4096 × 4096 pixels"
                                    />
                                </>
                            ) : action === 'import' ? (
                                <>
                                    <SingleFile
                                        file={file}
                                        onChange={setFile}
                                        accept=".json,application/json"
                                        hint="Supplier product list · JSON · up to 1,000 products and 2 MB"
                                    />
                                    <SettingsNotice>
                                        The supplier file must contain a JSON
                                        array. Each product needs code_system,
                                        code, name, strength and form. Strength
                                        describes the product, not the
                                        prescribed dose. Images are added to
                                        individual products after import.
                                    </SettingsNotice>
                                </>
                            ) : action === 'review' ? (
                                <>
                                    <NzDateTime
                                        id="catalogue-expiry"
                                        label="Source review expires"
                                        value={expiry}
                                        onChange={setExpiry}
                                    />
                                    <Toggle
                                        label="I checked the licence, exact product identities and images"
                                        checked={confirmed}
                                        onChange={setConfirmed}
                                    />
                                    <SettingsNotice>
                                        Only reviewed, unexpired exact matches
                                        can appear beside a medication. A
                                        product without a supplied image will
                                        show as unavailable.
                                    </SettingsNotice>
                                </>
                            ) : (
                                <>
                                    <Toggle
                                        label="I understand these images will stop appearing in medication workflows"
                                        checked={confirmed}
                                        onChange={setConfirmed}
                                    />
                                    <SettingsNotice>
                                        Source history will be retained. Add a
                                        new reviewed source version to provide
                                        replacement images.
                                    </SettingsNotice>
                                </>
                            )}
                        </div>
                    ),
                },
            ]}
            review={[
                {
                    label: 'Source',
                    value: s.source_name + ' · ' + s.source_version,
                },
                { label: 'Action', value: title },
                { label: 'File', value: file?.name ?? 'Not applicable' },
                {
                    label: 'Expiry',
                    value: expiry ? formatDateTime(expiry) : 'Not applicable',
                },
            ]}
        />
    );
}
