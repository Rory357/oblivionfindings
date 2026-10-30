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
                <Button
                    disabled={!m.canManage}
                    onClick={() => m.uploadDocument()}
                >
                    <Upload size={16} />
                    Upload documents
                </Button>
            </div>
            <div className="record-summary-grid">
                {[
                    ['Insurance policy', m.data.profile.insuranceDue],
                    ['Warranty', m.data.profile.warrantyDue],
                    ['Ownership agreement', ''],
                ].map(([title, expiry]) => (
                    <section className="studio-card" key={title}>
                        <FileText size={19} />
                        <strong>{title}</strong>
                        <Badge tone={count(title) ? 'success' : 'warning'}>
                            {count(title)
                                ? `${count(title)} file${count(title) > 1 ? 's' : ''}`
                                : 'File missing'}
                        </Badge>
                        <small>
                            {documentExpiry(title)
                                ? 'Document expiry ' +
                                  dateLabel(documentExpiry(title))
                                : expiry
                                  ? 'Profile renewal ' + dateLabel(expiry)
                                  : 'No expiry recorded'}
                        </small>
                        {expiry &&
                            documentExpiry(title) &&
                            expiry !== documentExpiry(title) && (
                                <Badge tone="warning">
                                    Profile date differs · review evidence
                                </Badge>
                            )}
                    </section>
                ))}
            </div>
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
                <div className="document-record-list">
                    {visible.map((f) => (
                        <article
                            className="studio-card document-record"
                            key={f.id}
                        >
                            <span className="document-thumbnail">
                                {f.type?.startsWith('image/') ? (
                                    <img src={f.url} alt="" />
                                ) : (
                                    <FileText size={27} />
                                )}
                            </span>
                            <div className="document-record-main">
                                <strong>{f.name}</strong>
                                <small>
                                    {f.category || 'Supporting evidence'} ·{' '}
                                    {f.reference || f.id}
                                </small>
                                <p>
                                    {f.owner || 'VH-014'}
                                    {f.expiry
                                        ? ' · Expires ' + dateLabel(f.expiry)
                                        : ''}
                                    {f.replaces
                                        ? ' · Replaces ' + f.replaces
                                        : ''}
                                </p>
                                {f.note && <p>{f.note}</p>}
                            </div>
                            <Badge
                                tone={
                                    f.status === 'Archived' ||
                                    f.status === 'Superseded'
                                        ? 'neutral'
                                        : f.expiry && f.expiry < '2026-09-22'
                                          ? 'critical'
                                          : 'info'
                                }
                            >
                                {f.status === 'Archived' ||
                                f.status === 'Superseded'
                                    ? f.status
                                    : f.expiry && f.expiry < '2026-09-22'
                                      ? 'Expired'
                                      : f.status || 'Current'}
                            </Badge>
                            <div className="document-record-actions">
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
                                            onClick={() =>
                                                f.owner!.startsWith('WO-')
                                                    ? onWork(f.owner!)
                                                    : onNav(
                                                          f.owner!.startsWith(
                                                              'CHK',
                                                          )
                                                              ? 'checks'
                                                              : 'compliance',
                                                      )
                                            }
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
                                        Finance <ArrowUpRight size={14} />
                                    </Button>
                                )}
                                {m.canManage &&
                                    f.owner === 'VH-014' &&
                                    (!f.status || f.status === 'Current') && (
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
                            </div>
                        </article>
                    ))}
                </div>
            ) : (
                <section className="studio-card studio-empty">
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
                    <Badge tone="info">NZD · synthetic</Badge>
                </div>
                <div className="finance-record-list">
                    {linked.map((r) => (
                        <button
                            key={r.id}
                            onClick={() => m.setFinanceRecord(r.id)}
                            className="finance-record-row"
                        >
                            <span className="feature-icon">
                                {r.kind === 'Fixed asset' ? (
                                    <Landmark size={19} />
                                ) : (
                                    <ReceiptText size={19} />
                                )}
                            </span>
                            <span>
                                <strong>{r.kind}</strong>
                                <small>
                                    {r.id} · {r.name}
                                </small>
                            </span>
                            <Badge
                                tone={
                                    r.status === 'Approved' ? 'success' : 'info'
                                }
                            >
                                {r.status}
                            </Badge>
                            <strong>{money(r.amount)}</strong>
                            <ArrowUpRight size={16} />
                        </button>
                    ))}
                </div>
                <p className="studio-footnote">
                    Purchase orders and invoices represent different stages of
                    the same spend. They are not added together as vehicle
                    costs.
                </p>
            </section>
            <section className="studio-card">
                <h3>Finance review requests</h3>
                {m.data.finance.requests.length ? (
                    m.data.finance.requests.map((r) => (
                        <button
                            className="finance-record-row"
                            key={r.id}
                            onClick={() => m.setFinanceRecord(r.id)}
                        >
                            <CalendarClock size={20} />
                            <span>
                                <strong>{r.type}</strong>
                                <small>
                                    {r.id} · {r.source}
                                </small>
                            </span>
                            <Badge tone="warning">{r.status}</Badge>
                            <ArrowUpRight size={16} />
                        </button>
                    ))
                ) : (
                    <div className="evidence-empty">
                        <ShieldCheck size={23} />
                        <span>
                            No requests yet. Submit a quote, invoice or
                            ownership correction with its supporting files.
                        </span>
                    </div>
                )}
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
