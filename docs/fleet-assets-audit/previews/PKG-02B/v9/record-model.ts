import { readFiles, type EvidenceFile } from './evidence-field';
import type { Action, Store } from './operations';

export const financeRecords = [
    {
        id: 'FA-DEMO-14',
        kind: 'Fixed asset',
        name: 'KWH014 · Toyota Hiace',
        status: 'Active',
        amount: 42000,
        date: '2024-01-12',
        owner: 'Finance assets',
        route: '/finance/fixed-assets/14',
        detail: 'Acquisition value · NZD · linked operational vehicle VH-014',
    },
    {
        id: 'PO-DEMO-188',
        kind: 'Purchase order',
        name: 'Harbour Workshop · routine service',
        status: 'Approved',
        amount: 408.25,
        date: '2026-03-20',
        owner: 'Finance purchasing',
        route: '/finance/purchase-orders/188',
        detail: 'WO-0188 · Approved order including GST. Approval does not establish payment.',
    },
    {
        id: 'BILL-DEMO-188',
        kind: 'Supplier invoice',
        name: 'Harbour Workshop · service invoice',
        status: 'Awaiting payment',
        amount: 408.25,
        date: '2026-03-24',
        owner: 'Accounts payable',
        route: '/finance/bills/188',
        detail: 'WO-0188 · $355 net + $53.25 GST. Invoice approval and payment remain Finance-owned.',
    },
    {
        id: 'PO-DEMO-268',
        kind: 'Purchase order',
        name: 'Harbour Workshop · next service',
        status: 'Draft',
        amount: 575,
        date: '2026-09-22',
        owner: 'Finance purchasing',
        route: '/finance/purchase-orders/268',
        detail: 'WO-0268 · Estimate including GST. Not an approved commitment or actual cost.',
    },
];
export type FinanceState = {
    assetId: string;
    costCentre: string;
    links: string[];
    requests: {
        id: string;
        documentIds?: string[];
        type: string;
        source: string;
        amount: string;
        note: string;
        status: string;
        history: string[];
    }[];
};
export const financeSeed = (): FinanceState => ({
    assetId: 'FA-DEMO-14',
    costCentre: 'CC-KOWHAI',
    links: ['PO-DEMO-188', 'BILL-DEMO-188'],
    requests: [],
});
const choices = (items: typeof financeRecords) =>
    items.map((x) => ({
        id: x.id,
        name: x.id + ' · ' + x.name,
        detail: x.kind + ' · ' + x.status,
    }));
const fields = {
    file: {
        key: 'files',
        label: 'Upload documents',
        type: 'files' as const,
        required: true,
    },
    reason: {
        key: 'reason',
        label: 'Reason / supporting details',
        type: 'textarea' as const,
        required: true,
    },
};
const cats = [
    'Insurance policy',
    'Warranty',
    'Purchase agreement',
    'Lease agreement',
    'Registration',
    'Inspection certificate',
    'Service report',
    'Invoice',
    'Vehicle manual',
    'Other supporting document',
];
export function recordActions(
    data: Store,
    open: (a: Action) => void,
    patch: (fn: (d: Store) => Store, message: string) => void,
) {
    const append = (d: Store, files: EvidenceFile[], category: string) =>
        files.map((f) => ({
            ...f,
            owner: 'VH-014',
            category,
            status: 'Current',
            issued: '2026-09-22',
        }));
    const editProfile = () =>
        open({
            title: 'Edit vehicle record',
            verb: 'Save vehicle record',
            values: {
                ...data.profile,
                history: '',
                vin:
                    data.profile.vin === 'Not recorded' ? '' : data.profile.vin,
                insuranceFiles: '[]',
                warrantyFiles: '[]',
                ownershipFiles: '[]',
                reason: '',
            },
            sections: [
                {
                    title: 'Vehicle identity',
                    description:
                        'Choose reusable vehicle specifications. Unique identifiers stay attached to this vehicle.',
                    fields: [
                        {
                            key: 'vin',
                            label: 'VIN / chassis reference',
                            type: 'text',
                            required: false,
                        },
                        {
                            key: 'category',
                            label: 'Vehicle category',
                            type: 'catalog',
                            options: [
                                'Passenger van',
                                'Car',
                                'Minibus',
                                'Utility vehicle',
                                'Wheelchair accessible van',
                            ],
                            required: true,
                        },
                        {
                            key: 'make',
                            label: 'Manufacturer',
                            type: 'catalog',
                            options: [
                                'Toyota',
                                'Ford',
                                'Hyundai',
                                'Mercedes-Benz',
                            ],
                            required: true,
                        },
                        {
                            key: 'model',
                            label: 'Vehicle model',
                            type: 'catalog',
                            options: ['Hiace', 'Transit', 'Staria', 'Sprinter'],
                            required: true,
                        },
                        {
                            key: 'fuel',
                            label: 'Fuel / energy type',
                            type: 'catalog',
                            options: ['Diesel', 'Petrol', 'Electric', 'Hybrid'],
                            required: true,
                        },
                        {
                            key: 'seats',
                            label: 'Seats',
                            type: 'catalog-number',
                            options: ['2', '5', '7', '8', '10', '12'],
                            required: true,
                        },
                    ],
                },
                {
                    title: 'Ownership & responsibility',
                    description:
                        'Operational ownership is separate from Finance recognition and approval.',
                    fields: [
                        {
                            key: 'lease',
                            label: 'Ownership arrangement',
                            type: 'catalog',
                            options: [
                                'Owned',
                                'Leased',
                                'Hired',
                                'Loan vehicle',
                                'Donated',
                            ],
                            required: true,
                        },
                        {
                            key: 'owner',
                            label: 'Responsible person or role',
                            type: 'person',
                            required: true,
                        },
                        {
                            key: 'life',
                            label: 'Lifecycle status',
                            type: 'select',
                            options: ['In service', 'Storage', 'Retired'],
                            required: true,
                        },
                        {
                            key: 'ownershipFiles',
                            label: 'Purchase / lease documents',
                            type: 'files',
                            required: false,
                        },
                    ],
                },
                {
                    title: 'Cover & documents',
                    description:
                        'Attach policy and warranty evidence here. Files appear in Documents after saving.',
                    fields: [
                        {
                            key: 'insurer',
                            label: 'Insurance provider',
                            type: 'record',
                            records: [
                                'Example Fleet Insurance',
                                'Example Mutual',
                            ].map((name) => ({
                                id: name,
                                name,
                                detail: 'Approved insurer record · demonstration',
                            })),
                            required: false,
                        },
                        {
                            key: 'insurance',
                            label: 'Insurance policy reference',
                            type: 'text',
                            required: false,
                        },
                        {
                            key: 'insuranceDue',
                            label: 'Insurance expiry',
                            type: 'date',
                            required: false,
                        },
                        {
                            key: 'insuranceFiles',
                            label: 'Insurance documents',
                            type: 'files',
                            required: false,
                        },
                        {
                            key: 'warranty',
                            label: 'Warranty reference',
                            type: 'text',
                            required: false,
                        },
                        {
                            key: 'warrantyDue',
                            label: 'Warranty expiry',
                            type: 'date',
                            required: false,
                        },
                        {
                            key: 'warrantyFiles',
                            label: 'Warranty documents',
                            type: 'files',
                            required: false,
                        },
                    ],
                },
                {
                    title: 'Change record',
                    description:
                        'Review identity, ownership and attached evidence before saving.',
                    fields: [fields.reason],
                },
            ],
            validate: (v) =>
                v.life === 'Retired' &&
                (data.bookings.some(
                    (b) => !['Returned', 'Cancelled'].includes(b.status),
                ) ||
                    data.works.some(
                        (w) =>
                            ![
                                'Completed',
                                'Cancelled',
                                'Completed · awaiting release',
                            ].includes(w.status),
                    ))
                    ? 'Resolve active bookings and open Maintenance work before retiring this vehicle.'
                    : '',
            save: (v) =>
                patch(
                    (d) => ({
                        ...d,
                        profile: {
                            ...d.profile,
                            ...Object.fromEntries(
                                [
                                    'vin',
                                    'category',
                                    'make',
                                    'model',
                                    'fuel',
                                    'seats',
                                    'lease',
                                    'owner',
                                    'life',
                                    'insurer',
                                    'insurance',
                                    'insuranceDue',
                                    'warranty',
                                    'warrantyDue',
                                ].map((k) => [k, v[k]]),
                            ),
                            history: [
                                ...d.profile.history,
                                '22 Sep · Coordinator · ' + v.reason,
                            ],
                        },
                        documents: [
                            ...d.documents,
                            ...append(
                                d,
                                readFiles(v.ownershipFiles),
                                'Ownership agreement',
                            ),
                            ...append(
                                d,
                                readFiles(v.insuranceFiles),
                                'Insurance policy',
                            ).map((f) => ({
                                ...f,
                                expiry: v.insuranceDue,
                                reference: v.insurance,
                            })),
                            ...append(
                                d,
                                readFiles(v.warrantyFiles),
                                'Warranty',
                            ).map((f) => ({
                                ...f,
                                expiry: v.warrantyDue,
                                reference: v.warranty,
                            })),
                        ],
                    }),
                    'Vehicle record saved with its documents and change history.',
                ),
            success:
                'Vehicle details and uploaded documents are linked. Use Finance to view or request changes to the financial record.',
        });
    const uploadDocument = (previous?: EvidenceFile) =>
        open({
            title: previous
                ? 'Replace vehicle document'
                : 'Upload vehicle documents',
            verb: previous ? 'Save new version' : 'Save vehicle documents',
            values: {
                category: previous?.category || '',
                reference: previous?.reference || '',
                issued: '2026-09-22',
                expiry: '',
                finance: '',
                files: '[]',
                reason: '',
                remind: 'false',
            },
            sections: [
                {
                    title: 'Document details',
                    description: previous
                        ? `A new version will replace ${previous.name}. The original remains in history.`
                        : 'Classify the evidence so it can be found from this vehicle and its owning record.',
                    fields: [
                        {
                            key: 'category',
                            label: 'Document type',
                            type: 'catalog',
                            options: cats,
                            required: true,
                        },
                        {
                            key: 'reference',
                            label: 'Document / policy reference',
                            type: 'text',
                            required: false,
                        },
                        {
                            key: 'issued',
                            label: 'Document date',
                            type: 'date',
                            required: true,
                        },
                        {
                            key: 'expiry',
                            label: 'Expiry / renewal date',
                            type: 'date',
                            required: false,
                        },
                        {
                            key: 'finance',
                            label: 'Linked Finance record',
                            type: 'record',
                            records: [
                                {
                                    id: 'none',
                                    name: 'No Finance link',
                                    detail: 'Vehicle evidence only',
                                },
                                ...choices(financeRecords),
                            ],
                            required: false,
                        },
                    ],
                },
                {
                    title: 'Files & follow-up',
                    description:
                        'PDF, PNG or JPEG. A reminder is optional; the document itself does not establish compliance.',
                    fields: [
                        fields.file,
                        fields.reason,
                        {
                            key: 'remind',
                            label: 'Create expiry reminder for the vehicle owner',
                            type: 'check',
                            required: false,
                        },
                    ],
                },
            ],
            validate: (v) =>
                v.expiry && v.expiry < v.issued
                    ? 'Expiry must be on or after the document date.'
                    : v.remind === 'true' && !v.expiry
                      ? 'Choose an expiry date to create the reminder.'
                      : '',
            save: (v) =>
                patch((d) => {
                    const files = readFiles(v.files).map((f) => ({
                        ...f,
                        owner: 'VH-014',
                        category: v.category,
                        reference: v.reference,
                        issued: v.issued,
                        expiry: v.expiry,
                        finance: v.finance === 'none' ? '' : v.finance,
                        status: 'Current',
                        replaces: previous?.id,
                        note: v.reason,
                    }));
                    return {
                        ...d,
                        documents: [
                            ...d.documents.map((f) =>
                                f.id === previous?.id
                                    ? { ...f, status: 'Superseded' }
                                    : f,
                            ),
                            ...files,
                        ],
                        profile: {
                            ...d.profile,
                            history: [
                                ...d.profile.history,
                                `22 Sep · Coordinator · ${previous ? 'Replaced' : 'Added'} ${v.category}: ${v.reason}`,
                            ],
                        },
                        followups:
                            v.remind === 'true'
                                ? [
                                      ...d.followups,
                                      {
                                          id: 'REM-DOC-' + Date.now(),
                                          title: v.category + ' renewal',
                                          source: files[0].id,
                                          at: v.expiry + 'T09:00',
                                          owner: d.profile.owner,
                                          backup: 'Operations Manager',
                                          repeat: 0,
                                          notes: v.reason,
                                          status: 'Scheduled',
                                          history: [
                                              'Created from vehicle document ' +
                                                  files[0].id,
                                          ],
                                      },
                                  ]
                                : d.followups,
                    };
                }, 'Documents saved. Any renewal reminder is linked to the calendar.'),
        });
    const archiveDocument = (file: EvidenceFile) =>
        open({
            title: 'Archive vehicle document',
            verb: 'Archive document',
            values: { reason: '' },
            sections: [
                {
                    title: 'Archive reason',
                    description: `${file.name} remains in history. Archiving does not close a renewal obligation.`,
                    fields: [fields.reason],
                },
            ],
            save: (v) =>
                patch(
                    (d) => ({
                        ...d,
                        documents: d.documents.map((f) =>
                            f.id === file.id
                                ? { ...f, status: 'Archived', note: v.reason }
                                : f,
                        ),
                        profile: {
                            ...d.profile,
                            history: [
                                ...d.profile.history,
                                '22 Sep · Archived ' +
                                    file.name +
                                    ': ' +
                                    v.reason,
                            ],
                        },
                    }),
                    'Document archived; original evidence retained.',
                ),
        });
    const financeLink = () =>
        open({
            title: 'Link Finance records',
            verb: 'Save Finance links',
            values: {
                asset: data.finance.assetId,
                centre: data.finance.costCentre,
                record: 'none',
                reason: '',
            },
            sections: [
                {
                    title: 'Finance ownership',
                    description:
                        'Select existing records. Fleet cannot create approvals or post accounting entries.',
                    fields: [
                        {
                            key: 'asset',
                            label: 'Fixed asset record',
                            type: 'record',
                            records: [
                                {
                                    id: 'none',
                                    name: 'Not linked',
                                    detail: 'Requires Finance review',
                                },
                                ...choices(
                                    financeRecords.filter(
                                        (r) => r.kind === 'Fixed asset',
                                    ),
                                ),
                            ],
                            required: true,
                        },
                        {
                            key: 'centre',
                            label: 'Cost centre',
                            type: 'record',
                            records: [
                                {
                                    id: 'CC-KOWHAI',
                                    name: 'Kōwhai House',
                                    detail: 'CC-KOWHAI · approved site',
                                },
                                {
                                    id: 'CC-FLEET',
                                    name: 'Central fleet',
                                    detail: 'CC-FLEET · central operations',
                                },
                            ],
                            required: true,
                        },
                        {
                            key: 'record',
                            label: 'Purchase order or supplier invoice',
                            type: 'record',
                            records: [
                                {
                                    id: 'none',
                                    name: 'No additional record',
                                    detail: 'Keep existing links',
                                },
                                ...choices(
                                    financeRecords.filter(
                                        (r) => r.kind !== 'Fixed asset',
                                    ),
                                ),
                            ],
                            required: true,
                        },
                        fields.reason,
                    ],
                },
            ],
            save: (v) =>
                patch(
                    (d) => ({
                        ...d,
                        finance: {
                            ...d.finance,
                            assetId: v.asset === 'none' ? '' : v.asset,
                            costCentre: v.centre,
                            links:
                                v.record === 'none'
                                    ? d.finance.links
                                    : Array.from(
                                          new Set([
                                              ...d.finance.links,
                                              v.record,
                                          ]),
                                      ),
                        },
                        profile: {
                            ...d.profile,
                            history: [
                                ...d.profile.history,
                                '22 Sep · Finance links updated: ' + v.reason,
                            ],
                        },
                    }),
                    'Finance links saved. Finance approval and payment statuses are unchanged.',
                ),
        });
    const financeRequest = (source = 'VH-014') =>
        open({
            title: 'Request Finance review',
            verb: 'Create Finance review request',
            values: {
                type: 'Supplier invoice review',
                source,
                amount: '',
                notes: '',
                files: '[]',
                existing: 'none',
            },
            sections: [
                {
                    title: 'Request & source',
                    description:
                        'Route supporting evidence to Finance. An estimate is not an approved cost.',
                    fields: [
                        {
                            key: 'existing',
                            label: 'Use an existing document',
                            type: 'record',
                            required: false,
                            records: [
                                {
                                    id: 'none',
                                    name: 'Upload new evidence instead',
                                    detail: 'Choose a file below',
                                },
                                ...data.documents
                                    .filter(
                                        (f) =>
                                            f.status !== 'Archived' &&
                                            f.status !== 'Superseded',
                                    )
                                    .map((f) => ({
                                        id: f.id,
                                        name: f.name,
                                        detail:
                                            (f.category || 'Evidence') +
                                            ' · ' +
                                            (f.owner || 'VH-014'),
                                    })),
                            ],
                        },
                        {
                            key: 'type',
                            label: 'Finance request type',
                            type: 'select',
                            options: [
                                'Supplier invoice review',
                                'Purchase approval',
                                'Fixed asset / ownership update',
                                'Cost allocation correction',
                            ],
                            required: true,
                        },
                        {
                            key: 'source',
                            label: 'Source record',
                            type: 'record',
                            records: [
                                {
                                    id: 'VH-014',
                                    name: 'KWH014 · Vehicle record',
                                    detail: 'VH-014',
                                },
                                ...data.works.map((w) => ({
                                    id: w.id,
                                    name: w.id + ' · ' + w.title,
                                    detail: w.status,
                                })),
                            ],
                            required: true,
                        },
                        {
                            key: 'amount',
                            label: 'Amount including GST · NZD',
                            type: 'number',
                            required: false,
                        },
                        {
                            key: 'notes',
                            label: 'What Finance needs to review',
                            type: 'textarea',
                            required: true,
                        },
                        {
                            key: 'files',
                            label: 'Quote / invoice / supporting files',
                            type: 'files',
                            required: false,
                        },
                    ],
                },
            ],
            extraCompletion: (v) => [
                readFiles(v.files).length > 0 ||
                    data.documents.some((f) => f.id === v.existing),
            ],
            validate: (v) =>
                !readFiles(v.files).length &&
                !data.documents.some((f) => f.id === v.existing)
                    ? 'Upload supporting files or select an existing document.'
                    : data.finance.requests.some(
                            (r) =>
                                r.source === v.source &&
                                r.type === v.type &&
                                r.status === 'Pending Finance review',
                        )
                      ? 'An open request already exists for this source and request type. Open the existing request in Finance.'
                      : '',
            save: (v) =>
                patch((d) => {
                    const id = 'FIN-REQ-' + (d.finance.requests.length + 1);
                    return {
                        ...d,
                        finance: {
                            ...d.finance,
                            requests: [
                                ...d.finance.requests,
                                {
                                    id,
                                    documentIds:
                                        v.existing && v.existing !== 'none'
                                            ? [v.existing]
                                            : [],
                                    type: v.type,
                                    source: v.source,
                                    amount: v.amount,
                                    note: v.notes,
                                    status: 'Pending Finance review',
                                    history: [
                                        '22 Sep · Coordinator · Evidence submitted to the demonstration Finance queue',
                                    ],
                                },
                            ],
                        },
                        documents: [
                            ...d.documents,
                            ...readFiles(v.files).map((f) => ({
                                ...f,
                                owner: id,
                                category: v.type,
                                status: 'Current',
                                finance: id,
                            })),
                        ],
                    };
                }, 'Finance review request created in the mockup queue; no payment or accounting entry was made.'),
            success:
                'The Finance queue now contains a linked request and its attachments. Return to Finance to open the request. No external notification is sent in this preview.',
        });
    return {
        editProfile,
        uploadDocument,
        archiveDocument,
        financeLink,
        financeRequest,
    };
}
