import {
    allocationIssues,
    businessDate,
    clone,
    extraConfig,
    fundingIssues,
    knownNumber,
    stamp,
    today,
    uid,
} from './model';
export const TODAY = today();
export function businessDue(days = 1) {
    const d = new Date(TODAY + 'T12:00:00');
    let count = 0;
    while (count < Number(days)) {
        d.setDate(d.getDate() + 1);
        if (![0, 6].includes(d.getDay())) count++;
    }
    return (
        d.getFullYear() +
        '-' +
        String(d.getMonth() + 1).padStart(2, '0') +
        '-' +
        String(d.getDate()).padStart(2, '0')
    );
}
export const stages = [
    'New',
    'Triage',
    'Assessment',
    'Funding assistance',
    'Ready for handover',
    'On hold',
    'Converted',
    'Closed',
];
export const sites = ['Central intake', 'Auckland', 'Waikato'];
export const staff = ['Intake team', 'Emma Taylor', 'Sam Patel', 'Olivia Chen'];
export const scopeDefaults = {
    'Referral manager': {
        sites: ['Auckland', 'Waikato'],
        ownership: 'All permitted referrals',
        person: 'Emma Taylor',
    },
    Receptionist: {
        sites: ['Auckland'],
        ownership: 'All permitted referrals',
        person: 'Emma Taylor',
    },
    Coordinator: {
        sites: ['Auckland'],
        ownership: 'Assigned referrals',
        person: 'Emma Taylor',
    },
    'Funding navigator': {
        sites: ['Auckland'],
        ownership: 'Assigned referrals or funding cases',
        person: 'Sam Patel',
    },
    'Finance reviewer': {
        sites: ['Auckland', 'Waikato'],
        ownership: 'All permitted referrals',
        person: 'Olivia Chen',
    },
    'Support worker': {
        sites: ['Auckland'],
        ownership: 'Assigned referrals',
        person: 'Emma Taylor',
    },
    'Settings administrator': {
        sites: [],
        ownership: 'All permitted referrals',
        person: 'Emma Taylor',
    },
};
export const pathways = [
    'DSS / NASC',
    'EGL',
    'Individualised Funding',
    'ACC',
    'Private payment',
    'Other grant / contract',
    'Personal income assistance',
];
export const permissions = {
    contact: 'Record contacts',
    tasks: 'Manage follow-ups',
    transition: 'Progress referral stages',
    close: 'Close referrals',
    reopen: 'Reopen referrals',
    disclose: 'Request and disclose information',
    documents: 'Manage permitted evidence',
    fundingRead: 'View financial information',
    duplicates: 'Resolve duplicate identities',
    reports: 'View operational reports',
    personRead: 'View linked person handover',
    settingsDraft: 'Draft referral settings',
    governance: 'Approve privacy and retention changes',
    view: 'View referrals',
    create: 'Create referrals',
    edit: 'Edit assigned referrals',
    assign: 'Assign referrals',
    sensitive: 'View assessments & evidence',
    funding: 'Manage funding assistance',
    verify: 'Verify funding decisions',
    convert: 'Create / link supported person',
    activate: 'Approve service start',
    export: 'Export referral reports',
    settings: 'Publish settings',
    audit: 'View audit history',
    central: 'View central intake',
    allSites: 'View all approved sites',
    restricted: 'View privacy-restricted referrals',
};
const grants = (...names) =>
    Object.fromEntries(
        Object.keys(permissions).map((k) => [k, names.includes(k)]),
    );
export const roles = {
    'Referral manager': grants(...Object.keys(permissions)),
    Receptionist: grants('view', 'create', 'central', 'contact', 'tasks'),
    Coordinator: grants(
        'view',
        'create',
        'edit',
        'sensitive',
        'convert',
        'contact',
        'tasks',
        'transition',
        'close',
        'reopen',
        'disclose',
        'documents',
        'fundingRead',
        'personRead',
    ),
    'Funding navigator': grants(
        'view',
        'edit',
        'funding',
        'sensitive',
        'central',
        'contact',
        'tasks',
        'fundingRead',
        'documents',
        'disclose',
    ),
    'Finance reviewer': grants(
        'view',
        'fundingRead',
        'verify',
        'allSites',
        'reports',
    ),
    'Support worker': grants(),
    'Settings administrator': grants('settings', 'settingsDraft'),
};
export const requirements = {
    'DSS / NASC': [
        'Authority to assist recorded',
        'NASC contact confirmed',
        'Assessment information ready',
    ],
    EGL: [
        'Authority to assist recorded',
        'Local EGL access confirmed',
        'Connector / planning contact confirmed',
    ],
    'Individualised Funding': [
        'Authority to assist recorded',
        'Host and arrangement confirmed',
        'Allocation and purchasing scope checked',
    ],
    ACC: [
        'Authority to assist recorded',
        'ACC contact / claim reference recorded',
        'Provider and service approval checked',
    ],
    'Private payment': [
        'Payer identity confirmed',
        'Quote understood',
        'Payer acceptance recorded',
    ],
    'Other grant / contract': [
        'Authority to assist recorded',
        'Funder rules and contact recorded',
        'Application requirements checked',
    ],
    'Personal income assistance': [
        'Permission to assist recorded',
        'Work and Income appointment arranged',
        'Income support kept separate from service budget',
    ],
};
export function makeFunding(path, version = 1, checklist = requirements[path]) {
    return {
        id: crypto.randomUUID(),
        path,
        version,
        status: 'Preparing',
        navigator: 'Sam Patel',
        next: 'Review funding pathway and preparation',
        due: businessDate(2),
        checks: checklist.map((label) => ({
            id: uid('requirement'),
            label,
            done: false,
        })),
        reference: '',
        evidence: '',
        amount: null,
        requested: null,
        awarded: null,
        allocated: null,
        unit: 'NZD',
        period: 'Week',
        validFrom: '',
        validTo: '',
        service: 'Supported living',
        conditionsMet: false,
        providerConfirmed: false,
        decisions: [],
        decision: '',
        verified: false,
        history: [],
        appointment: '',
        income: path === 'Personal income assistance',
    };
}
export function makeReferral(fields = {}) {
    return {
        id: uid('REF'),
        createdAt: stamp(),
        stageAt: stamp(),
        generalSummary: 'Support enquiry',
        needsAudience: 'Restricted assessment',
        fundingSituation: 'To clarify',
        authorityState: 'To clarify',
        authorityScopes: { contact: true, disclose: false, service: false },
        requests: [],
        appointments: [],
        corrections: [],
        closureHistory: [],
        name: '',
        preferred: '',
        dob: '',
        phone: '',
        email: '',
        source: 'Phone',
        site: 'Central intake',
        owner: 'Intake team',
        team: 'Central intake',
        stage: 'New',
        priority: 'Standard',
        due: businessDate(1),
        next: 'Make safe first contact',
        safe: 'Phone during the day',
        relationship: 'Self',
        authority: 'To clarify',
        choice: false,
        consent: false,
        identity: false,
        suitability: false,
        capacity: false,
        agreement: false,
        safety: false,
        needs: 'Help to live more independently',
        service: 'Supported living',
        restricted: false,
        version: 1,
        notes: [],
        contacts: [],
        tasks: [],
        documents: [],
        funding: [],
        activity: [
            {
                at: stamp(),
                text: 'Referral received in central intake',
                by: 'Intake team',
            },
        ],
        proposal: {
            weekly: null,
            payer: 'Not confirmed',
            approved: false,
            accepted: false,
            privateAmount: 0,
            version: 1,
            history: [],
            lines: [],
            start: TODAY,
        },
        ...fields,
        authorityState:
            fields.authorityState ||
            (fields.consent ? 'Current' : 'To clarify'),
        authorityScopes: fields.authorityScopes || {
            contact: true,
            disclose: !!fields.consent,
            service: !!fields.agreement,
        },
    };
}
export function seed() {
    const names = [
        'Maia Bennett',
        'Noah Clarke',
        'Hana Reed',
        'Leo Morgan',
        'Aria Wilson',
        'Theo Brooks',
        'Isla James',
        'Eli Carter',
    ];
    const paths = [
        'DSS / NASC',
        'ACC',
        'Individualised Funding',
        'Private payment',
        'EGL',
        'Other grant / contract',
        'Personal income assistance',
        'DSS / NASC',
    ];
    const records = names.map((name, i) => {
        const f = makeFunding(paths[i]);
        f.requested = 1200;
        if (i === 5) f.navigator = 'Olivia Chen';
        if (i === 1) {
            f.status = 'Verified';
            f.decision = 'Full approval';
            f.amount = 1200;
            Object.assign(f, {
                awarded: 1200,
                allocated: 1200,
                validFrom: TODAY,
                validTo: businessDate(260),
                conditionsMet: true,
                providerConfirmed: true,
            });
            f.verified = true;
            f.reference = 'SAMPLE-ACC-109';
            f.evidence = 'Synthetic support approval';
            f.checks.forEach((c) => (c.done = true));
        }
        if (i === 2) {
            f.status = 'Decision recorded';
            f.decision = 'Partial approval';
            f.amount = 800;
            Object.assign(f, {
                awarded: 800,
                allocated: 800,
                validFrom: TODAY,
                validTo: businessDate(260),
                conditionsMet: true,
                providerConfirmed: true,
            });
            f.reference = 'SAMPLE-IF-302';
            f.evidence = 'Sample host allocation';
            f.checks.forEach((c) => (c.done = true));
        }
        return makeReferral({
            id: 'REF-' + (1042 + i),
            name,
            proposal: {
                weekly: 1200,
                payer: 'Not confirmed',
                approved: false,
                accepted: false,
                privateAmount: 0,
                version: 1,
                history: [],
                start: TODAY,
                lines: [
                    {
                        id: 'support',
                        service: 'Supported living',
                        unit: 'Weekly package',
                        quantity: 1,
                        weekly: 1200,
                    },
                ],
            },
            preferred: name.split(' ')[0],
            dob: '1997-04-12',
            phone: '021 000 00' + (10 + i),
            email: name.split(' ')[0].toLowerCase() + '@example.invalid',
            source: ['Website', 'Professional', 'Phone', 'Website'][i % 4],
            site: i === 0 ? 'Central intake' : i === 5 ? 'Waikato' : 'Auckland',
            owner:
                i === 0
                    ? 'Intake team'
                    : i === 5
                      ? 'Olivia Chen'
                      : 'Emma Taylor',
            stage: [
                'New',
                'Ready for handover',
                'Funding assistance',
                'Assessment',
                'On hold',
                'Funding assistance',
                'Triage',
                'Assessment',
            ][i],
            due: i === 2 ? '2026-10-14' : '2026-10-16',
            next: [
                'Confirm safe contact and authority',
                'Review handover into supported living',
                'Discuss the funding gap with Hana',
                'Review private payer proposal',
                'Review capacity waitlist',
                'Prepare grant application',
                'Arrange benefit appointment',
                'Review additional support',
            ][i],
            authority: i === 0 ? 'To clarify' : 'Confirmed',
            consent: i !== 0,
            choice: i !== 0,
            identity: i !== 0,
            suitability: i !== 0,
            capacity: i !== 4,
            holdReason: i === 4 ? 'Capacity' : null,
            agreement: i === 1,
            safety: i === 1,
            restricted: i === 5,
            funding: [f],
            existingPerson: i === 7 ? 'SP-0204' : null,
            tasks: [
                {
                    id: 'task' + i,
                    label:
                        i === 2
                            ? 'Confirm host purchasing scope'
                            : 'Arrange introductory conversation',
                    due: '2026-10-16',
                    owner: 'Emma Taylor',
                    done: false,
                },
            ],
            documents:
                i === 1
                    ? [
                          {
                              id: 'doc1',
                              name: 'Synthetic support approval.txt',
                              state: 'Reviewed',
                              text: 'FICTIONAL PROTOTYPE EVIDENCE\nNoah Clarke\nApproved service: supported living\nWeekly service budget: NZD 1,200\nReference: SAMPLE-ACC-109\nNo real agency decision or entitlement.',
                          },
                      ]
                    : [],
        });
    });
    return {
        schema: 1,
        records,
        people: [
            {
                id: 'SP-0204',
                name: 'Eli Carter',
                status: 'Active',
                site: 'Auckland',
                services: ['Community participation'],
            },
        ],
        roles: structuredClone(roles),
        config: {
            ...clone(extraConfig),
            version: 1,
            scopeRules: structuredClone(scopeDefaults),
            messageTemplate:
                'Kia ora {name}, please contact our intake team at a time that works for you to discuss the support you are seeking.',
            acknowledgement: true,
            firstContact: 1,
            reviewDays: 5,
            routing: 'Central intake',
            requireInterpreter: false,
            customField: 'What would a good week look like for you?',
            allowPending: true,
            stages: [...stages],
            requirements: structuredClone(requirements),
            notifications: {
                assignment: true,
                overdue: true,
                assignmentEmail: false,
                overdueEmail: false,
                email: false,
            },
            retention: 7,
        },
        versions: [
            {
                version: 1,
                at: '15 Oct 2026',
                reason: 'Initial sample configuration',
            },
        ],
        drafts: [],
        audit: [],
    };
}
export const money = (n) =>
    !knownNumber(n)
        ? 'Unknown'
        : new Intl.NumberFormat('en-NZ', {
              style: 'currency',
              currency: 'NZD',
              maximumFractionDigits: 0,
          }).format(Number(n) || 0);
export const date = (v) =>
    v
        ? new Intl.DateTimeFormat('en-NZ', {
              day: 'numeric',
              month: 'short',
              year: 'numeric',
          }).format(new Date(v + 'T12:00:00'))
        : 'Not set';
export function conversionBlocks(r) {
    return [
        r.stage === 'Closed' &&
            'Reopen this referral before preparing onboarding',
        r.generalOnly &&
            'Identify the person before creating an onboarding record',
        r.authorityState === 'Withdrawn' && 'Authority was withdrawn',
        !r.identity && 'Identity checked',
        !r.consent && 'Authority and sharing recorded',
        !r.choice && 'Person chooses this provider',
        !r.suitability && 'Support suitability assessed',
        r.site === 'Central intake' && 'Receiving site selected',
    ].filter(Boolean);
}
export function coverage(r) {
    const services = new Set(
        r.proposal.lines?.length
            ? r.proposal.lines.map((l) => l.service)
            : [r.service],
    );
    return r.funding
        .filter(
            (f) =>
                services.has(f.service) &&
                !fundingIssues(f, { ...r, service: f.service }).length,
        )
        .reduce((n, f) => n + Number(f.amount), 0);
}
export function activationBlocks(r) {
    return [
        ...conversionBlocks(r),
        ...allocationIssues(r),
        !knownNumber(r.proposal.weekly) && 'Support proposal must be costed',
        !r.proposal.lines?.length && 'Support service lines required',
        !r.capacity && 'Capacity confirmed',
        !r.agreement && 'Service agreement accepted',
        !r.safety && 'Safe start plan agreed',
        !r.proposal.approved && 'Proposal internally approved',
        !r.proposal.accepted && 'Person / payer accepts proposal',
        coverage(r) < Number(r.proposal.weekly) &&
            'Verified service funding covers proposal',
    ].filter(Boolean);
}
