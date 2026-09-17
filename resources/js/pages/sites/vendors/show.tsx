import { ConfirmDialog } from '@/components/confirm-dialog';
import {
    KnowledgeRecordSearch,
    KnowledgeRelatedRecords,
    type KnowledgeRecord,
} from '@/components/it/knowledge-related-records';
import {
    PageHeader,
    PageHeaderMeterBig,
    PageHeaderMeterBlock,
    PageHeaderMeterCaption,
    PageHeaderPrimaryButton,
    PageHeaderRail,
    PageHeaderSearch,
    PageLayout,
} from '@/components/page';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import { StatusBadge } from '@/components/ui/status-badge';
import { Textarea } from '@/components/ui/textarea';
import AppLayout from '@/layouts/app-layout';
import { Head, Link, router } from '@inertiajs/react';
import { FileText, Phone, Truck, UserRound } from 'lucide-react';
import { useEffect, useState } from 'react';
import {
    AgreementEditor,
    AgreementTable,
    AgreementViewer,
    commercialRequest,
    type Agreement,
    type Choice,
} from './_commercial';
import { EditVendorDialog, type VendorRecord } from './_dialogs';

type Vendor = VendorRecord & {
    site_id: number;
    owner_user_id: number | null;
    owner_name: string | null;
    visibility: string;
    supplied_services: string | null;
    lock_version: number;
};
export default function VendorShow({
    vendor: v,
    financeLink,
    financeOptions,
    agreements,
    owners,
    commercialOwners,
    assets,
    can,
    ready,
    relatedRecords,
    documentationHref,
    selectedAgreementId,
}: {
    vendor: Vendor;
    financeLink: Choice | null;
    financeOptions: Choice[];
    agreements: Agreement[];
    owners: Choice[];
    commercialOwners: Choice[];
    assets: Choice[];
    can: { manage: boolean; contracts: boolean; contractsManage: boolean };
    ready: boolean;
    relatedRecords: KnowledgeRecord[];
    documentationHref: string | null;
    selectedAgreementId: number | null;
}) {
    const [tab, setTab] = useState(
        selectedAgreementId && can.contracts ? 'commercial' : 'details',
    );
    const [search, setSearch] = useState('');
    const [view, setView] = useState<Agreement | null>(
        agreements.find((a) => a.id === selectedAgreementId) ?? null,
    );
    const [edit, setEdit] = useState<Agreement | 'new' | null>(null);
    const [details, setDetails] = useState(false);
    const [contacts, setContacts] = useState(false);
    const [links, setLinks] = useState(false);
    const [financeEdit, setFinanceEdit] = useState(false);
    const filtered = agreements.filter((a) =>
        a.title.toLowerCase().includes(search.toLowerCase()),
    );
    const due = agreements.filter((a) => a.followup?.status === 'due');
    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Home', href: '/dashboard' },
                { title: 'Vendors', href: '/vendors' },
                { title: v.company_name, href: '/vendors/' + v.id },
            ]}
        >
            <Head title={v.company_name} />
            <PageLayout
                hero={
                    <PageHeader
                        variant="profile"
                        backHref="/vendors"
                        icon={Truck}
                        title={v.company_name}
                        subline={[
                            v.service_type,
                            v.site_name,
                            v.visibility === 'all_approved_sites'
                                ? 'Shared across approved sites'
                                : 'Site only',
                        ]
                            .filter(Boolean)
                            .join(' · ')}
                        actions={
                            <>
                                {can.contracts && (
                                    <PageHeaderSearch
                                        value={search}
                                        onChange={setSearch}
                                        placeholder="Find an agreement"
                                    />
                                )}
                                {can.contractsManage && ready ? (
                                    <PageHeaderPrimaryButton
                                        onClick={() => setEdit('new')}
                                    >
                                        Add agreement
                                    </PageHeaderPrimaryButton>
                                ) : null}
                            </>
                        }
                        meters={
                            <>
                                <PageHeaderMeterBlock
                                    label="Owner"
                                    onClick={() => setTab('details')}
                                >
                                    <PageHeaderMeterBig>
                                        {v.owner_name
                                            ? 'Assigned'
                                            : 'Unassigned'}
                                    </PageHeaderMeterBig>
                                    <PageHeaderMeterCaption>
                                        {v.owner_name ||
                                            'Choose an accountable owner'}
                                    </PageHeaderMeterCaption>
                                </PageHeaderMeterBlock>
                                <PageHeaderMeterBlock
                                    label="Support"
                                    onClick={() => setTab('details')}
                                >
                                    <PageHeaderMeterBig>
                                        {
                                            [
                                                v.phone,
                                                v.after_hours_phone,
                                                v.email,
                                            ].filter(Boolean).length
                                        }
                                    </PageHeaderMeterBig>
                                    <PageHeaderMeterCaption>
                                        Contact channels recorded
                                    </PageHeaderMeterCaption>
                                </PageHeaderMeterBlock>
                                {can.contracts && (
                                    <>
                                        <PageHeaderMeterBlock
                                            label="Agreements"
                                            onClick={() => setTab('commercial')}
                                        >
                                            <PageHeaderMeterBig>
                                                {agreements.length}
                                            </PageHeaderMeterBig>
                                            <PageHeaderMeterCaption>
                                                Protected commercial records
                                            </PageHeaderMeterCaption>
                                        </PageHeaderMeterBlock>
                                        <PageHeaderMeterBlock
                                            label="Due follow-ups"
                                            onClick={() => setTab('commercial')}
                                        >
                                            <PageHeaderMeterBig>
                                                {due.length}
                                            </PageHeaderMeterBig>
                                            <PageHeaderMeterCaption>
                                                Assigned renewal reviews
                                            </PageHeaderMeterCaption>
                                        </PageHeaderMeterBlock>
                                    </>
                                )}
                            </>
                        }
                        rail={
                            <PageHeaderRail
                                items={[
                                    {
                                        key: 'details',
                                        label: 'Vendor details',
                                        icon: Truck,
                                    },
                                    ...(can.contracts
                                        ? [
                                              {
                                                  key: 'commercial',
                                                  label: 'Agreements and renewals',
                                                  icon: FileText,
                                                  count: agreements.length,
                                              },
                                          ]
                                        : []),
                                ]}
                                value={tab}
                                onSelect={setTab}
                            />
                        }
                    />
                }
            >
                {!ready && (
                    <p role="status" className="rounded-lg border bg-card p-4">
                        Commercial records and shared visibility need the
                        reviewed database update before use.
                    </p>
                )}
                {tab === 'details' ? (
                    <div className="grid gap-5 lg:grid-cols-2">
                        <Card>
                            <CardHeader>
                                <CardTitle>Support and contacts</CardTitle>
                            </CardHeader>
                            <CardContent className="space-y-3">
                                <StatusBadge
                                    variant={
                                        v.is_active ? 'success' : 'neutral'
                                    }
                                >
                                    {v.is_active ? 'Active' : 'Retired'}
                                </StatusBadge>
                                <dl className="grid grid-cols-2 gap-3">
                                    <dt>Contact</dt>
                                    <dd>{v.contact_name || 'Not recorded'}</dd>
                                    <dt>Daytime</dt>
                                    <dd>{v.phone || 'Not recorded'}</dd>
                                    <dt>After hours</dt>
                                    <dd>
                                        {v.after_hours_phone || 'Not recorded'}
                                    </dd>
                                    <dt>Email</dt>
                                    <dd>{v.email || 'Not recorded'}</dd>
                                </dl>
                                {can.manage && (
                                    <Button
                                        variant="outline"
                                        onClick={() => setContacts(true)}
                                    >
                                        <Phone className="size-4" />
                                        Edit contacts
                                    </Button>
                                )}
                            </CardContent>
                        </Card>
                        <Card>
                            <CardHeader>
                                <CardTitle>
                                    Responsibility and supplied services
                                </CardTitle>
                            </CardHeader>
                            <CardContent className="space-y-3">
                                <p>
                                    {v.owner_name ||
                                        'No responsible owner assigned'}
                                </p>
                                <p className="whitespace-pre-wrap">
                                    {v.supplied_services ||
                                        'No supplied services recorded'}
                                </p>
                                {financeLink && (
                                    <p>
                                        Canonical Finance supplier:{' '}
                                        {financeLink.name}
                                    </p>
                                )}
                                {can.manage && ready && (
                                    <Button
                                        variant="outline"
                                        onClick={() => setDetails(true)}
                                    >
                                        <UserRound className="size-4" />
                                        Edit responsibility and visibility
                                    </Button>
                                )}
                            </CardContent>
                        </Card>
                    </div>
                ) : (
                    <div className="space-y-5">
                        {can.contractsManage && ready && (
                            <Button
                                variant="outline"
                                onClick={() => setFinanceEdit(true)}
                            >
                                Link Finance supplier
                            </Button>
                        )}
                        <p className="text-subtle">
                            {filtered.length} of {agreements.length} agreements
                            shown · Finance and Management access
                        </p>
                        <AgreementTable agreements={filtered} open={setView} />
                    </div>
                )}
                {tab === 'details' && (
                    <Card className="mt-5">
                        <CardHeader>
                            <CardTitle>
                                Sites, systems, assets and documentation
                            </CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-4">
                            <div className="flex flex-wrap gap-3">
                                <Button asChild variant="outline">
                                    <Link href={'/sites/' + v.site_id}>
                                        Open owning site
                                    </Link>
                                </Button>
                                {documentationHref && (
                                    <Button asChild variant="outline">
                                        <Link href={documentationHref}>
                                            Related Knowledge
                                        </Link>
                                    </Button>
                                )}
                                {can.manage && ready && (
                                    <Button
                                        variant="outline"
                                        onClick={() => setLinks(true)}
                                    >
                                        Edit related records
                                    </Button>
                                )}
                            </div>
                            <KnowledgeRelatedRecords records={relatedRecords} />
                        </CardContent>
                    </Card>
                )}
            </PageLayout>
            {edit && (
                <AgreementEditor
                    key={edit === 'new' ? 'new' : edit.id}
                    vendorId={v.id}
                    agreement={edit === 'new' ? undefined : edit}
                    owners={commercialOwners}
                    assets={assets}
                    close={() => setEdit(null)}
                />
            )}
            {view && (
                <AgreementViewer
                    agreement={view}
                    canManage={can.contractsManage}
                    close={() => setView(null)}
                    edit={() => {
                        setEdit(view);
                        setView(null);
                    }}
                />
            )}
            {details && (
                <VendorResponsibility
                    vendor={v}
                    owners={owners}
                    financeOptions={financeOptions}
                    financeId={financeLink?.id}
                    commercial={can.contractsManage}
                    close={() => setDetails(false)}
                />
            )}
            <EditVendorDialog
                siteId={v.site_id}
                vendor={v}
                isOpen={contacts}
                onClose={() => setContacts(false)}
            />
            {links && (
                <VendorLinks
                    vendor={v}
                    records={relatedRecords}
                    close={() => setLinks(false)}
                />
            )}
            {financeEdit && (
                <FinanceLinkEditor
                    vendor={v}
                    options={financeOptions}
                    current={financeLink?.id}
                    close={() => setFinanceEdit(false)}
                />
            )}
        </AppLayout>
    );
}

function VendorResponsibility({
    vendor,
    owners,
    financeOptions,
    financeId,
    commercial,
    close,
}: {
    vendor: Vendor;
    owners: Choice[];
    financeOptions: Choice[];
    financeId?: number;
    commercial: boolean;
    close: () => void;
}) {
    const [owner, setOwner] = useState(String(vendor.owner_user_id ?? ''));
    const [visibility, setVisibility] = useState(vendor.visibility || 'site');
    const [services, setServices] = useState(vendor.supplied_services || '');
    const [finance, setFinance] = useState(String(financeId ?? ''));
    const [error, setError] = useState('');
    const [busy, setBusy] = useState(false);
    const [discard, setDiscard] = useState(false);
    const dirty =
        owner !== String(vendor.owner_user_id ?? '') ||
        visibility !== (vendor.visibility || 'site') ||
        services !== (vendor.supplied_services || '') ||
        finance !== String(financeId ?? '');
    const requestClose = () => !busy && (dirty ? setDiscard(true) : close());
    useEffect(() => {
        const guard = (event: BeforeUnloadEvent) => {
            if (dirty) event.preventDefault();
        };
        window.addEventListener('beforeunload', guard);
        return () => window.removeEventListener('beforeunload', guard);
    }, [dirty]);
    return (
        <Dialog open onOpenChange={(v) => !v && requestClose()}>
            <DialogContent style={{ maxWidth: 'min(92vw, 720px)' }}>
                <DialogHeader>
                    <DialogTitle>Responsibility and visibility</DialogTitle>
                    <DialogDescription>
                        Keep one vendor record and link its canonical Finance
                        supplier.
                    </DialogDescription>
                </DialogHeader>
                {error && (
                    <p role="alert" className="text-status-critical">
                        {error}
                    </p>
                )}
                <Label>
                    Responsible owner
                    <select
                        className="select mt-1 w-full"
                        value={owner}
                        onChange={(e) => setOwner(e.target.value)}
                    >
                        <option value="">Choose owner</option>
                        {owners.map((o) => (
                            <option key={o.id} value={o.id}>
                                {o.name}
                            </option>
                        ))}
                    </select>
                </Label>
                <Label>
                    Visibility
                    <select
                        className="select mt-1 w-full"
                        value={visibility}
                        onChange={(e) => setVisibility(e.target.value)}
                    >
                        <option value="site">This site only</option>
                        <option value="all_approved_sites">
                            All approved sites
                        </option>
                    </select>
                </Label>
                <Label>
                    Supplied services
                    <Textarea
                        value={services}
                        onChange={(e) => setServices(e.target.value)}
                    />
                </Label>
                {commercial && (
                    <Label>
                        Finance supplier
                        <select
                            className="select mt-1 w-full"
                            value={finance}
                            onChange={(e) => setFinance(e.target.value)}
                        >
                            <option value="">No Finance link</option>
                            {financeOptions.map((o) => (
                                <option key={o.id} value={o.id}>
                                    {o.name}
                                </option>
                            ))}
                        </select>
                    </Label>
                )}
                <div className="flex justify-end gap-3">
                    <Button variant="outline" onClick={requestClose}>
                        Cancel
                    </Button>
                    <Button
                        disabled={busy || !owner}
                        onClick={async () => {
                            setBusy(true);
                            setError('');
                            try {
                                await commercialRequest(
                                    '/vendors/' + vendor.id + '/details',
                                    {
                                        owner_user_id: Number(owner),
                                        visibility,
                                        supplied_services: services,
                                        lock_version: vendor.lock_version,
                                        ...(commercial
                                            ? {
                                                  finance_vendor_id: finance
                                                      ? Number(finance)
                                                      : null,
                                              }
                                            : {}),
                                    },
                                    'PATCH',
                                );
                                close();
                                router.reload();
                            } catch (e) {
                                setError((e as Error).message);
                            } finally {
                                setBusy(false);
                            }
                        }}
                    >
                        {busy ? 'Saving…' : 'Save changes'}
                    </Button>
                </div>
                <ConfirmDialog
                    open={discard}
                    onClose={() => setDiscard(false)}
                    title="Discard responsibility changes?"
                    description="Your unsaved changes will be discarded."
                    confirmText="Discard changes"
                    onConfirm={close}
                    variant="destructive"
                />
            </DialogContent>
        </Dialog>
    );
}

function VendorLinks({
    vendor,
    records,
    close,
}: {
    vendor: Vendor;
    records: KnowledgeRecord[];
    close: () => void;
}) {
    const [selected, setSelected] = useState(records);
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState('');
    const [discard, setDiscard] = useState(false);
    const dirty = JSON.stringify(selected) !== JSON.stringify(records);
    const requestClose = () => !busy && (dirty ? setDiscard(true) : close());
    return (
        <Dialog open onOpenChange={(open) => !open && requestClose()}>
            <DialogContent
                style={{ maxWidth: 'min(92vw, 900px)' }}
                className="max-h-[88vh] overflow-y-auto"
            >
                <DialogHeader>
                    <DialogTitle>Related vendor records</DialogTitle>
                    <DialogDescription>
                        Link existing systems, assets and documents. Current
                        access is checked when each link opens.
                    </DialogDescription>
                </DialogHeader>
                {error && (
                    <p role="alert" className="text-status-critical">
                        {error}
                    </p>
                )}
                <KnowledgeRecordSearch
                    endpoint={'/vendors/' + vendor.id + '/record-options'}
                    allowedTypes={['service', 'asset', 'article']}
                    selected={selected}
                    disabled={busy}
                    onSelect={(record) =>
                        setSelected((current) => [
                            ...current,
                            { ...record, relation: 'supports' },
                        ])
                    }
                />
                <KnowledgeRelatedRecords records={selected} />
                {selected.map((record) => (
                    <Button
                        key={record.type + ':' + record.id}
                        variant="outline"
                        disabled={busy}
                        onClick={() =>
                            setSelected((current) =>
                                current.filter(
                                    (item) =>
                                        item.type !== record.type ||
                                        item.id !== record.id,
                                ),
                            )
                        }
                    >
                        Unlink {record.label}
                    </Button>
                ))}
                <div className="flex justify-end gap-3">
                    <Button variant="outline" onClick={requestClose}>
                        Cancel
                    </Button>
                    <Button
                        disabled={busy || !dirty}
                        onClick={async () => {
                            setBusy(true);
                            setError('');
                            try {
                                await commercialRequest(
                                    '/vendors/' + vendor.id + '/details',
                                    {
                                        lock_version: vendor.lock_version,
                                        related_records: selected.map(
                                            ({ type, id, relation }) => ({
                                                type,
                                                id,
                                                relation,
                                            }),
                                        ),
                                    },
                                    'PATCH',
                                );
                                close();
                                router.reload();
                            } catch (cause) {
                                setError((cause as Error).message);
                            } finally {
                                setBusy(false);
                            }
                        }}
                    >
                        {busy ? 'Saving…' : 'Save related records'}
                    </Button>
                </div>
                <ConfirmDialog
                    open={discard}
                    onClose={() => setDiscard(false)}
                    onConfirm={close}
                    title="Discard unsaved links?"
                    description="Your unsaved relationship changes will be removed."
                    confirmText="Discard changes"
                />
            </DialogContent>
        </Dialog>
    );
}

function FinanceLinkEditor({
    vendor,
    options,
    current,
    close,
}: {
    vendor: Vendor;
    options: Choice[];
    current?: number;
    close: () => void;
}) {
    const [selected, setSelected] = useState(String(current ?? ''));
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState('');
    return (
        <Dialog open onOpenChange={(open) => !open && !busy && close()}>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>Link Finance supplier</DialogTitle>
                    <DialogDescription>
                        Choose the existing Finance supplier master for this
                        vendor.
                    </DialogDescription>
                </DialogHeader>
                {error && (
                    <p role="alert" className="text-status-critical">
                        {error}
                    </p>
                )}
                <Label>
                    Finance supplier
                    <select
                        className="select mt-1 w-full"
                        value={selected}
                        onChange={(event) => setSelected(event.target.value)}
                    >
                        <option value="">No supplier link</option>
                        {options.map((option) => (
                            <option key={option.id} value={option.id}>
                                {option.name}
                            </option>
                        ))}
                    </select>
                </Label>
                <div className="flex justify-end gap-3">
                    <Button variant="outline" onClick={close}>
                        Cancel
                    </Button>
                    <Button
                        disabled={busy}
                        onClick={async () => {
                            setBusy(true);
                            setError('');
                            try {
                                await commercialRequest(
                                    '/vendors/' + vendor.id + '/finance-link',
                                    {
                                        lock_version: vendor.lock_version,
                                        finance_vendor_id: selected
                                            ? Number(selected)
                                            : null,
                                    },
                                    'PATCH',
                                );
                                close();
                                router.reload();
                            } catch (cause) {
                                setError((cause as Error).message);
                            } finally {
                                setBusy(false);
                            }
                        }}
                    >
                        {busy ? 'Saving…' : 'Save supplier link'}
                    </Button>
                </div>
            </DialogContent>
        </Dialog>
    );
}
