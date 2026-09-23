import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import {
    ArrowUpRight,
    CalendarClock,
    FileText,
    FolderOpen,
    History,
    Landmark,
    Link2,
    ReceiptText,
    ShieldCheck,
    Upload,
} from 'lucide-react';
import { useState } from 'react';
import {
    CollectionToggle,
    RecordCollection,
    useCollectionView,
} from './collection-view';
import { dateLabel, type VehicleModel } from './operations';
import { financeRecords } from './record-model';
import { Badge, Modal, Notice } from './ui';
const money = (value: number) =>
    new Intl.NumberFormat('en-NZ', {
        style: 'currency',
        currency: 'NZD',
    }).format(value);

export function DocumentsStudio({
    model: m,
    onNav,
    onWork,
}: {
    model: VehicleModel;
    onNav: (view: string, sub?: string) => void;
    onWork: (id: string) => void;
}) {
    const layout = useCollectionView('documents');
    const [query, setQuery] = useState(''),
        [scope, setScope] = useState('Current');
    const files = m.data.documents.filter(
        (f) =>
            m.canViewFinance ||
            (!f.owner?.startsWith('FIN-') &&
                !f.finance &&
                !/invoice|finance|purchase approval|cost allocation/i.test(
                    f.category || '',
                )),
    );
    const visible = files.filter(
        (f) =>
            (scope === 'All history' ||
                (f.status || 'Current') === 'Current') &&
            `${f.name} ${f.category || ''} ${f.reference || ''} ${f.owner || ''}`
                .toLowerCase()
                .includes(query.toLowerCase()),
    );
    const count = (type: string) =>
        files.filter(
            (f) =>
                (f.category === type ||
                    (type === 'Ownership agreement' &&
                        ['Purchase agreement', 'Lease agreement'].includes(
                            f.category || '',
                        ))) &&
                (!f.status || f.status === 'Current'),
        ).length;
    const documentExpiry = (type: string) =>
        files
            .filter(
                (f) =>
                    f.category === type &&
                    (!f.status || f.status === 'Current') &&
                    f.expiry,
            )
            .map((f) => f.expiry!)
            .sort()[0];
    return (
        <div className="studio-page record-workspace">
            <div className="studio-section-heading">
                <div>
                    <span className="studio-eyebrow">
                        VEHICLE RECORD · VH-014
                    </span>
                    <h2>Documents & renewal evidence</h2>
                    <p>
                        Find the original, replace a version, or follow its
                        source.
                    </p>
                </div>
                <CollectionToggle label="Documents" {...layout} />
                <Button
                    disabled={!m.canManage}
                    onClick={() => m.uploadDocument()}
                >
                    <Upload size={16} />
                    Upload documents
                </Button>
            </div>
            <RecordCollection
                label="Required documents"
                view={layout.view}
                columns={[
                    { label: 'File status' },
                    { label: 'Renewal / expiry', width: '1.5fr' },
                    { label: 'Evidence' },
                ]}
                records={[
                    ['Insurance policy', m.data.profile.insuranceDue],
                    ['Warranty', m.data.profile.warrantyDue],
                    ['Ownership agreement', ''],
                ].map(([title, expiry]) => ({
                    id: title,
                    name: title,
                    icon: FileText,
                    alert: count(title) ? undefined : 'warning',
                    fields: [
                        <Badge tone={count(title) ? 'success' : 'warning'}>
                            {count(title)
                                ? `${count(title)} file${count(title) > 1 ? 's' : ''}`
                                : 'File missing'}
                        </Badge>,
                        <>
                            <span>
                                {documentExpiry(title)
                                    ? 'Document expiry ' +
                                      dateLabel(documentExpiry(title))
                                    : expiry
                                      ? 'Profile renewal ' + dateLabel(expiry)
                                      : 'No expiry recorded'}
                            </span>
                            {expiry &&
                                documentExpiry(title) &&
                                expiry !== documentExpiry(title) && (
                                    <Badge tone="warning">
                                        Profile date differs · review evidence
                                    </Badge>
                                )}
                        </>,
                        <div className="collection-actions">
                            <Button
                                variant="ghost"
                                size="sm"
                                onClick={() =>
                                    setQuery(
                                        title === 'Ownership agreement'
                                            ? 'agreement'
                                            : title,
                                    )
                                }
                            >
                                Find files
                            </Button>
                            <Button
                                variant="outline"
                                size="sm"
                                disabled={!m.canManage}
                                onClick={() => m.uploadDocument()}
                            >
                                Upload
                            </Button>
                        </div>,
                    ],
                    open: () =>
                        setQuery(
                            title === 'Ownership agreement'
                                ? 'agreement'
                                : title,
                        ),
                    footer: {
                        primary: count(title)
                            ? 'Supporting files available'
                            : 'Evidence needed',
                        secondary: 'Profile references are not uploaded files',
                    },
                    actions: [
                        {
                            label: 'Find matching files',
                            icon: FolderOpen,
                            onClick: () =>
                                setQuery(
                                    title === 'Ownership agreement'
                                        ? 'agreement'
                                        : title,
                                ),
                        },
                        ...(m.canManage
                            ? [
                                  {
                                      label: 'Upload document',
                                      icon: Upload,
                                      onClick: () => m.uploadDocument(),
                                  },
                              ]
                            : []),
                        {
                            label: 'Renewal reminders',
                            icon: CalendarClock,
                            onClick: () => onNav('compliance', 'reminders'),
                        },
                    ],
                }))}
            />
            <div className="record-filterbar">
                <Input
                    aria-label="Search vehicle documents"
                    placeholder="Search file, type, reference or source…"
                    value={query}
                    onChange={(e) => setQuery(e.target.value)}
                />
                <select
                    aria-label="Document versions"
                    value={scope}
                    onChange={(e) => setScope(e.target.value)}
                >
                    <option>Current</option>
                    <option>All history</option>
                </select>
                <Button
                    variant="outline"
                    onClick={() => onNav('compliance', 'reminders')}
                >
                    <CalendarClock size={16} />
                    Renewal reminders
                </Button>
            </div>
            {visible.length ? (
                <RecordCollection
                    label="Document library"
                    view={layout.view}
                    columns={[
                        { label: 'Version / expiry' },
                        { label: 'Source & reference', width: '1.2fr' },
                        { label: 'File actions', width: '1.5fr' },
                    ]}
                    records={visible.map((f) => {
                        const current = !f.status || f.status === 'Current',
                            expired =
                                current &&
                                !!f.expiry &&
                                f.expiry < '2026-09-22';
                        const open = () =>
                            m.detail(f.name, [
                                [
                                    'Category',
                                    f.category || 'Supporting evidence',
                                ],
                                ['Reference', f.reference || f.id],
                                ['Source', f.owner || 'VH-014'],
                                [
                                    'Expiry',
                                    f.expiry
                                        ? dateLabel(f.expiry)
                                        : 'Not recorded',
                                ],
                                ['Version', f.status || 'Current'],
                                [
                                    'Replaces',
                                    f.replaces || 'No previous version',
                                ],
                                ['Notes', f.note || 'None recorded'],
                            ]);
                        const source = () =>
                            f.owner?.startsWith('WO-')
                                ? onWork(f.owner)
                                : onNav(
                                      f.owner?.startsWith('CHK')
                                          ? 'checks'
                                          : 'compliance',
                                  );
                        return {
                            id: f.id,
                            name: f.name,
                            subline: f.category || 'Supporting evidence',
                            icon: FileText,
                            mark: f.type?.startsWith('image/') ? (
                                <span className="collection-file-image">
                                    <img src={f.url} alt="" />
                                </span>
                            ) : undefined,
                            alert: expired ? 'critical' : undefined,
                            fields: [
                                <>
                                    <Badge
                                        tone={
                                            expired
                                                ? 'critical'
                                                : current
                                                  ? 'info'
                                                  : 'neutral'
                                        }
                                    >
                                        {expired
                                            ? 'Expired'
                                            : f.status || 'Current'}
                                    </Badge>
                                    <small>
                                        {f.expiry
                                            ? 'Expires ' + dateLabel(f.expiry)
                                            : 'No expiry recorded'}
                                    </small>
                                    {f.replaces && (
                                        <small>Replaces {f.replaces}</small>
                                    )}
                                </>,
                                <>
                                    <strong>{f.owner || 'VH-014'}</strong>
                                    <small>{f.reference || f.id}</small>
                                    {f.note && <small>{f.note}</small>}
                                </>,
                                <div className="collection-actions">
                                    {f.url && (
                                        <a
                                            href={f.url}
                                            target="_blank"
                                            rel="noreferrer"
                                            className="report-download"
                                        >
                                            <FolderOpen size={14} />
                                            Open file
                                        </a>
                                    )}
                                    {f.owner &&
                                        f.owner !== 'VH-014' &&
                                        !f.owner.startsWith('FIN-') && (
                                            <Button
                                                variant="ghost"
                                                size="sm"
                                                onClick={source}
                                            >
                                                Open source
                                            </Button>
                                        )}
                                    {f.finance && m.canViewFinance && (
                                        <Button
                                            variant="ghost"
                                            size="sm"
                                            onClick={() =>
                                                m.setFinanceRecord(f.finance!)
                                            }
                                        >
                                            Finance
                                            <ArrowUpRight size={14} />
                                        </Button>
                                    )}
                                    {m.canManage &&
                                        f.owner === 'VH-014' &&
                                        current && (
                                            <>
                                                <Button
                                                    variant="outline"
                                                    size="sm"
                                                    onClick={() =>
                                                        m.uploadDocument(f)
                                                    }
                                                >
                                                    Replace
                                                </Button>
                                                <Button
                                                    variant="ghost"
                                                    size="sm"
                                                    onClick={() =>
                                                        m.archiveDocument(f)
                                                    }
                                                >
                                                    Archive
                                                </Button>
                                            </>
                                        )}
                                </div>,
                            ],
                            open,
                            footer: {
                                primary: f.owner || 'Vehicle record',
                                secondary:
                                    'Source document · original retained',
                            },
                            actions: [
                                {
                                    label: 'View document details',
                                    icon: FileText,
                                    onClick: open,
                                },
                                ...(f.url
                                    ? [
                                          {
                                              label: 'Open file',
                                              icon: FolderOpen,
                                              onClick: () => {
                                                  window.open(
                                                      f.url,
                                                      '_blank',
                                                      'noopener,noreferrer',
                                                  );
                                              },
                                          },
                                      ]
                                    : []),
                                ...(f.owner &&
                                f.owner !== 'VH-014' &&
                                !f.owner.startsWith('FIN-')
                                    ? [
                                          {
                                              label: 'Open source',
                                              icon: ArrowUpRight,
                                              onClick: source,
                                          },
                                      ]
                                    : []),
                                ...(f.finance && m.canViewFinance
                                    ? [
                                          {
                                              label: 'Open Finance record',
                                              icon: Landmark,
                                              onClick: () =>
                                                  m.setFinanceRecord(
                                                      f.finance!,
                                                  ),
                                          },
                                      ]
                                    : []),
                                ...(m.canManage &&
                                f.owner === 'VH-014' &&
                                current
                                    ? [
                                          {
                                              label: 'Replace document',
                                              icon: Upload,
                                              onClick: () =>
                                                  m.uploadDocument(f),
                                          },
                                          {
                                              label: 'Archive document',
                                              icon: History,
                                              onClick: () =>
                                                  m.archiveDocument(f),
                                          },
                                      ]
                                    : []),
                            ],
                        };
                    })}
                />
            ) : (
                <section className="studio-card studio-empty document-empty">
                    <FolderOpen size={34} />
                    <h3>
                        {query
                            ? 'No matching documents'
                            : 'Add the evidence behind this record'}
                    </h3>
                    <p>
                        {query
                            ? 'Try another file name, category or reference.'
                            : 'Upload policy, purchase, lease, warranty and vehicle documents. Existing references are not uploaded files.'}
                    </p>
                    {query ? (
                        <Button variant="outline" onClick={() => setQuery('')}>
                            Clear search
                        </Button>
                    ) : (
                        <Button
                            variant="outline"
                            disabled={!m.canManage}
                            onClick={() => m.uploadDocument()}
                        >
                            <Upload size={15} />
                            Upload first document
                        </Button>
                    )}
                </section>
            )}
            <p className="studio-footnote">
                Uploaded files stay in this preview session. New catalogue
                choices remain available after refresh. Archived and superseded
                files remain in All history.
            </p>
        </div>
    );
}

export function FinanceStudio({ model: m }: { model: VehicleModel }) {
    const layout = useCollectionView('finance');
    if (!m.canViewFinance)
        return (
            <Notice title="Finance access required">
                This role can report vehicle concerns. Financial records are
                available to permitted Finance viewers.
            </Notice>
        );
    const linked = financeRecords.filter(
        (r) =>
            r.id === m.data.finance.assetId ||
            m.data.finance.links.includes(r.id),
    );
    return (
        <div className="studio-page record-workspace">
            <div className="studio-section-heading">
                <div>
                    <span className="studio-eyebrow">
                        FINANCE CONNECTION · VH-014
                    </span>
                    <h2>Ownership, purchasing & costs</h2>
                    <p>
                        Linked records keep approvals and payment with Finance.
                    </p>
                </div>
                <div className="studio-inline">
                    <Button
                        variant="outline"
                        disabled={!m.canManage}
                        onClick={m.financeLink}
                    >
                        <Link2 size={16} />
                        Link records
                    </Button>
                    <Button
                        disabled={!m.canManage}
                        onClick={() => m.financeRequest()}
                    >
                        <ReceiptText size={16} />
                        Request Finance review
                    </Button>
                </div>
            </div>
            <div className="record-summary-grid">
                <section className="studio-card">
                    <Landmark size={20} />
                    <strong>Fixed asset</strong>
                    <span>{m.data.finance.assetId || 'Not linked'}</span>
                    <small>Financial asset recognition</small>
                </section>
                <section className="studio-card">
                    <Link2 size={20} />
                    <strong>Cost centre</strong>
                    <span>
                        {m.data.finance.costCentre === 'CC-KOWHAI'
                            ? 'Kōwhai House'
                            : 'Central fleet'}
                    </span>
                    <small>{m.data.finance.costCentre}</small>
                </section>
                <section className="studio-card">
                    <CalendarClock size={20} />
                    <strong>Review queue</strong>
                    <span>{m.data.finance.requests.length} pending</span>
                    <small>Finance owns the next decision</small>
                </section>
            </div>
            <section className="studio-card">
                <div className="studio-section-heading">
                    <h3>Linked Finance records</h3>
                    <CollectionToggle label="Finance records" {...layout} />
                    <Badge tone="info">NZD · synthetic</Badge>
                </div>
                <RecordCollection
                    label="Linked Finance records"
                    view={layout.view}
                    columns={[
                        { label: 'Type' },
                        { label: 'Status' },
                        { label: 'Amount (NZD)' },
                    ]}
                    records={linked.map((r) => ({
                        id: r.id,
                        name: r.name,
                        subline: r.id,
                        icon: r.kind === 'Fixed asset' ? Landmark : ReceiptText,
                        fields: [
                            <span>{r.kind}</span>,
                            <Badge
                                tone={
                                    r.status === 'Approved' ? 'success' : 'info'
                                }
                            >
                                {r.status}
                            </Badge>,
                            <strong>{money(r.amount)}</strong>,
                        ],
                        open: () => m.setFinanceRecord(r.id),
                        footer: {
                            primary: r.kind,
                            secondary: 'Approval and payment owned by Finance',
                        },
                        actions: [
                            {
                                label: 'Open Finance record',
                                icon: Landmark,
                                onClick: () => m.setFinanceRecord(r.id),
                            },
                        ],
                    }))}
                    empty="No Finance records linked yet."
                />
                <p className="studio-footnote">
                    Purchase orders and invoices represent different stages of
                    the same spend. They are not added together as vehicle
                    costs.
                </p>
            </section>
            <section className="studio-card">
                <h3>Finance review requests</h3>
                <RecordCollection
                    label="Finance review requests"
                    view={layout.view}
                    columns={[{ label: 'Source' }, { label: 'Status' }]}
                    records={m.data.finance.requests.map((r) => ({
                        id: r.id,
                        name: r.type,
                        subline: r.id,
                        icon: CalendarClock,
                        fields: [
                            <span>{r.source}</span>,
                            <Badge tone="warning">{r.status}</Badge>,
                        ],
                        open: () => m.setFinanceRecord(r.id),
                        footer: {
                            primary: 'Finance review',
                            secondary: 'Supporting records retained',
                        },
                        actions: [
                            {
                                label: 'Open review request',
                                icon: Landmark,
                                onClick: () => m.setFinanceRecord(r.id),
                            },
                        ],
                    }))}
                    empty="No requests yet. Submit a quote, invoice or ownership correction with its supporting files."
                />
            </section>
            <Notice title="Finance remains the owner">
                Vehicle edits and Maintenance completion do not approve spend,
                pay invoices, post depreciation or dispose of the fixed asset.
                This mockup shows the linked record and review queue; nothing is
                sent externally.
            </Notice>
        </div>
    );
}

export function FinanceRecordDialog({
    model: m,
    id,
    onClose,
}: {
    model: VehicleModel;
    id: string;
    onClose: () => void;
}) {
    const r = financeRecords.find((r) => r.id === id),
        request = m.data.finance.requests.find((r) => r.id === id);
    const files = m.data.documents.filter(
        (f) =>
            f.finance === id ||
            f.owner === id ||
            request?.documentIds?.includes(f.id),
    );
    return (
        <Modal
            title={
                r
                    ? r.kind + ' · ' + r.id
                    : request
                      ? 'Finance review · ' + id
                      : 'Finance record unavailable'
            }
            description="Linked Finance record · local destination preview"
            size="standard"
            icon={Landmark}
            onClose={onClose}
            footer={
                <Button variant="outline" onClick={onClose}>
                    Back to vehicle
                </Button>
            }
        >
            {r ? (
                <>
                    <div className="finance-detail-hero">
                        <span className="feature-icon">
                            <Landmark size={25} />
                        </span>
                        <div>
                            <h3>{r.name}</h3>
                            <small>{r.owner}</small>
                        </div>
                        <Badge
                            tone={r.status === 'Approved' ? 'success' : 'info'}
                        >
                            {r.status}
                        </Badge>
                    </div>
                    <dl className="facts-grid">
                        <div>
                            <dt>Recorded amount</dt>
                            <dd>{money(r.amount)}</dd>
                        </div>
                        <div>
                            <dt>Record date</dt>
                            <dd>{dateLabel(r.date)}</dd>
                        </div>
                        <div>
                            <dt>Vehicle relationship</dt>
                            <dd>VH-014 · KWH014</dd>
                        </div>
                        <div>
                            <dt>Finance workspace</dt>
                            <dd>
                                {r.kind === 'Fixed asset'
                                    ? 'Fixed assets'
                                    : r.kind === 'Purchase order'
                                      ? 'Purchasing'
                                      : 'Accounts payable'}
                            </dd>
                        </div>
                    </dl>
                    <Notice title="Record context">{r.detail}</Notice>
                    <div className="record-flow">
                        <span>
                            <FileText size={17} />
                            Source evidence
                        </span>
                        <span>
                            <Link2 size={17} />
                            Finance review
                        </span>
                        <span>
                            <ShieldCheck size={17} />
                            Separate approval / payment
                        </span>
                    </div>
                </>
            ) : request ? (
                <>
                    <div className="finance-detail-hero">
                        <ReceiptText size={25} />
                        <h3>{request.type}</h3>
                        <Badge tone="warning">{request.status}</Badge>
                    </div>
                    <dl className="facts-grid">
                        <div>
                            <dt>Source</dt>
                            <dd>{request.source}</dd>
                        </div>
                        <div>
                            <dt>Requested amount</dt>
                            <dd>
                                {request.amount
                                    ? money(+request.amount)
                                    : 'Not supplied'}
                            </dd>
                        </div>
                        <div>
                            <dt>Queue owner</dt>
                            <dd>Finance team</dd>
                        </div>
                        <div>
                            <dt>Delivery</dt>
                            <dd>Demonstration queue only</dd>
                        </div>
                    </dl>
                    <p>{request.note}</p>
                    <div className="compact-timeline">
                        {request.history.map((h, i) => (
                            <p key={i}>
                                <History size={14} />
                                {h}
                            </p>
                        ))}
                    </div>
                </>
            ) : (
                <Notice title="No accessible linked record">
                    Choose an existing record or request Finance review.
                </Notice>
            )}
            <h3>Linked documents · {files.length}</h3>
            {files.map((f) => (
                <a
                    className="attachment-item"
                    key={f.id}
                    href={f.url}
                    target="_blank"
                    rel="noreferrer"
                >
                    <FileText size={20} />
                    {f.name}
                </a>
            ))}
            {!files.length && (
                <p className="studio-footnote">
                    No file attached to this preview record.
                </p>
            )}
        </Modal>
    );
}
