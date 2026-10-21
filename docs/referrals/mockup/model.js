// Local, synthetic prototype rules. No transport or production authorization.
export const today = () =>
    new Intl.DateTimeFormat('en-CA', {
        timeZone: 'Pacific/Auckland',
        year: 'numeric',
        month: '2-digit',
        day: '2-digit',
    }).format(new Date());
export const stamp = () => new Date().toISOString();
export const displayStamp = (value) =>
    value && !Number.isNaN(Date.parse(value))
        ? new Intl.DateTimeFormat('en-NZ', {
              timeZone: 'Pacific/Auckland',
              dateStyle: 'medium',
              timeStyle: 'short',
          }).format(new Date(value))
        : value || 'Not recorded';
export const uid = (prefix = 'id') =>
    prefix + '-' + crypto.randomUUID().slice(0, 8);
export const clone = (v) => structuredClone(v);
export const knownNumber = (v) =>
    v !== null && v !== undefined && v !== '' && Number.isFinite(Number(v));
export function handoverFields(r) {
    const answers = (r.intakeAnswers || []).filter(
        (a) => a.sensitivity === 'General intake',
    );
    const goals = answers
        .filter((a) => a.destination === 'Goals' && a.value)
        .map((a) => String(a.value))
        .join('\n');
    return {
        name: r.name,
        preferred: r.preferred || '',
        dob: r.dob || '',
        phone: r.phone,
        email: r.email,
        safe: r.safe,
        safeRecipientName: r.safeContactPerson || r.safeRecipientName || r.name,
        safeContactPhone: r.safeContactPhone,
        safeContactEmail: r.safeContactEmail,
        preferredContact: r.preferredContact || r.channel || '',
        leaveMessage: r.leaveMessage || 'Ask before leaving a message',
        interpreter: r.interpreter || '',
        contacts: clone(r.contacts || []),
        referrer: clone(r.referrerRecord || null),
        goals: goals || r.generalSummary,
        intakeAnswers: clone(answers),
        authority: {
            state: r.authorityState,
            basis: r.authority,
            scopes: clone(r.authorityScopes),
            noticeVersion: r.noticeVersion,
        },
        assessmentRef: r.id,
        evidenceRefs: r.documents.map((d) => d.id),
    };
}
export function openInWorkspace(r, route) {
    if (r.stage === 'Closed') return false;
    if (r.stage !== 'Converted') return true;
    if (route === 'Follow-ups') return followupsFor(r).length > 0;
    if (route === 'Funding assistance')
        return r.funding.some((f) => f.status !== 'Withdrawn');
    return false;
}
export function serviceStartUpdate(r, person, values, actor, day = today()) {
    const scheduled = values.start > day;
    const receipt = {
        referralId: r.id,
        personId: person.id,
        date: values.start,
        status: scheduled ? 'Scheduled' : 'Started',
        note: values.note,
        approvedBy: actor,
        approvedAt: stamp(),
        proposalVersion: r.proposal.version,
        fundingCaseIds: r.funding
            .filter((f) => f.verified && !f.income)
            .map((f) => f.id),
    };
    return {
        record: {
            serviceStarted: !scheduled,
            serviceStart: receipt,
            tasks: [
                ...(r.tasks || []).filter(
                    (t) => t.id !== r.id + '-commencement',
                ),
                {
                    id: r.id + '-commencement',
                    label: 'Confirm first support visit and service commencement',
                    owner: r.owner,
                    due: values.start,
                    done: !scheduled,
                    status: scheduled ? 'Open' : 'Completed',
                },
            ],
            serviceStartHistory: [...(r.serviceStartHistory || []), receipt],
        },
        person: {
            ...person,
            status: scheduled ? person.status : 'Active',
            start: scheduled ? person.start : person.start || values.start,
            services: scheduled
                ? person.services
                : [
                      ...new Set([
                          ...person.services,
                          ...r.proposal.lines.map((l) => l.service),
                      ]),
                  ],
            proposedServices: scheduled
                ? (person.proposedServices || []).map((p) =>
                      p.referralId === r.id
                          ? {
                                ...p,
                                status: 'Scheduled — commencement to confirm',
                                start: values.start,
                            }
                          : p,
                  )
                : (person.proposedServices || []).filter(
                      (p) => p.referralId !== r.id,
                  ),
            serviceStarts: [
                ...(person.serviceStarts || []).filter(
                    (s) => s.referralId !== r.id,
                ),
                receipt,
            ],
        },
    };
}
export const staffDirectory = [
    {
        name: 'Intake team',
        id: 1,
        sites: ['Central intake', 'Auckland', 'Waikato'],
        central: true,
    },
    { name: 'Emma Taylor', id: 2, sites: ['Auckland'], central: true },
    { name: 'Sam Patel', id: 3, sites: ['Auckland'], central: true },
    {
        name: 'Olivia Chen',
        id: 4,
        sites: ['Auckland', 'Waikato'],
        central: false,
    },
];
export const actorFor = (data, role) =>
    data.config.scopeRules[role]?.person || role;
export function allowedSites(data, role, create = false) {
    const grants = data.roles[role] || {};
    return [
        ...(grants.central || create ? ['Central intake'] : []),
        ...(grants.allSites
            ? data.config.regions
            : data.config.scopeRules[role]?.sites || []),
    ];
}
export function eligibleOwners(data, site) {
    return (data.config.staffDirectory || staffDirectory)
        .filter(
            (p) =>
                (site === 'Central intake'
                    ? p.central
                    : p.sites.includes(site)) &&
                !(data.config.absentStaff || []).includes(p.name),
        )
        .map((p) => p.name);
}
export function canReadRecord(data, role, r) {
    const grant = data.roles[role],
        scope = data.config.scopeRules[role];
    return (
        !!grant?.view &&
        !!scope &&
        (!r.restricted || grant.restricted) &&
        (r.site === 'Central intake'
            ? grant.central
            : grant.allSites || scope.sites.includes(r.site)) &&
        (scope.ownership === 'All permitted referrals' ||
            r.owner === scope.person ||
            (scope.ownership === 'Assigned referrals or funding cases' &&
                r.funding.some((f) => f.navigator === scope.person)))
    );
}
export function businessDate(days, from = today(), config = {}) {
    const d = new Date(from + 'T12:00:00');
    let n = 0;
    const key = () =>
        `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
    while (n < Math.max(0, Number(days))) {
        d.setDate(d.getDate() + 1);
        if (
            ![0, 6].includes(d.getDay()) &&
            !(config.holidays || []).includes(key())
        )
            n++;
    }
    return key();
}
export const extraConfig = {
    staffDirectory,
    services: [
        'Supported living',
        'Community participation',
        'Additional supported living',
    ],
    regions: ['Auckland', 'Waikato'],
    hours: 'Monday–Friday, 9 am–5 pm',
    contactAlternative: 'Sample intake team · request a safe callback',
    noticeVersion: 'privacy-v1',
    requestDays: 7,
    approvalLimit: 2500,
    separateReviewer: true,
    absentStaff: [],
    holidays: [],
    closureReasons: [
        'Person chose to stop',
        'Another provider',
        'Duplicate enquiry',
        'Outside service scope',
        'Unable to contact',
    ],
    holdReasons: [
        'Capacity',
        'Awaiting information',
        'Person requested time',
        'External decision',
    ],
    routingRules: [
        {
            id: 'route-auckland',
            region: 'Auckland',
            service: 'Any service',
            pathway: 'Any pathway',
            team: 'Supported living intake',
            site: 'Auckland',
            owner: 'Emma Taylor',
        },
    ],
    forms: [
        {
            id: 'goal',
            label: 'What would a good week look like for you?',
            type: 'textarea',
            required: false,
            condition: 'Always',
            sensitivity: 'General intake',
            destination: 'Goals',
        },
    ],
    stageRules: [
        {
            id: 'ready',
            stage: 'Ready for handover',
            requires: 'Identity and consent',
            role: 'Coordinator',
        },
    ],
    pathwayDetails: {},
    onboardingTemplate: 'Supported living onboarding',
};
export function routeIntake(values, config) {
    const rule = (config.routingRules || []).find(
        (x) =>
            (x.region === 'Any region' || x.region === values.region) &&
            (x.service === 'Any service' || x.service === values.service) &&
            (x.pathway === 'Any pathway' || x.pathway === values.path),
    );
    const available =
        rule &&
        (config.staffDirectory || staffDirectory).some(
            (p) => p.name === rule.owner && p.sites.includes(rule.site),
        ) &&
        !(config.absentStaff || []).includes(rule.owner);
    return {
        site: available ? rule.site : 'Central intake',
        owner: available ? rule.owner : 'Intake team',
        team: available ? rule.team : config.routing,
        explanation: available
            ? `Rule ${rule.id}: ${rule.region} · ${rule.service} → ${rule.owner}`
            : 'No eligible available match; central intake is the accountable fallback.',
    };
}
export function pinnedConfig(data, record) {
    return (
        data.versions.find((v) => v.version === record.version)?.config ||
        data.config
    );
}
export function policyWarnings(before, after, oldRoles, newRoles) {
    const warnings = [];
    if (!before.allowPending && after.allowPending)
        warnings.push('Allow onboarding while funding is pending');
    if (
        (before.forms || []).some(
            (f) =>
                f.required &&
                !after.forms?.some(
                    (n) =>
                        n.id === f.id &&
                        n.required &&
                        n.condition === f.condition,
                ),
        )
    )
        warnings.push('Remove or narrow a required intake answer');
    if (after.retention < before.retention)
        warnings.push(
            'Shorten the retention review period — governance review required',
        );
    for (const [role, values] of Object.entries(newRoles)) {
        if (Object.keys(values).some((k) => values[k] && !oldRoles[role]?.[k]))
            warnings.push(`Expand ${role} permissions`);
        const a = before.scopeRules[role],
            b = after.scopeRules[role];
        if (
            a &&
            b &&
            (b.sites.some((s) => !a.sites.includes(s)) ||
                (a.ownership !== b.ownership &&
                    (b.ownership === 'All permitted referrals' ||
                        a.ownership === 'Assigned referrals')))
        )
            warnings.push(`Broaden ${role} record scope`);
    }
    for (const [path, list] of Object.entries(before.requirements))
        if (list.some((label) => !after.requirements[path]?.includes(label)))
            warnings.push(`Remove ${path} preparation requirements`);
    if (
        after.approvalLimit > before.approvalLimit ||
        (before.separateReviewer && !after.separateReviewer)
    )
        warnings.push('Loosen proposal approval delegation');
    if (
        (before.stageRules || []).some(
            (x) =>
                !after.stageRules?.some(
                    (y) => JSON.stringify(x) === JSON.stringify(y),
                ),
        )
    )
        warnings.push('Change or remove a stage requirement');
    return warnings;
}
export function validateConfig(config) {
    if (!config.services.length || !config.regions.length)
        return 'Keep at least one service and region.';
    if (!config.hours.trim() || !config.contactAlternative.trim())
        return 'Supply staffed hours and a contact fallback.';
    if (
        config.forms.some(
            (f) =>
                !f.label.trim() || (f.type === 'select' && !f.options?.trim()),
        )
    )
        return 'Every form field needs a label and choice fields need options.';
    if (new Set(config.forms.map((f) => f.id)).size !== config.forms.length)
        return 'Form field IDs must be unique.';
    if (config.stageRules.some((r) => !config.stages.includes(r.stage)))
        return 'A transition rule refers to a stage that is not available.';
    if (
        config.routingRules.some(
            (r) =>
                !config.regions.includes(r.site) ||
                !(config.staffDirectory || staffDirectory).some(
                    (p) => p.name === r.owner && p.sites.includes(r.site),
                ),
        )
    )
        return 'Every routing rule needs an eligible owner at its receiving site.';
    return '';
}
export function normalise(data, defaults) {
    const previousSchema = data.schema || 1;
    data.config = { ...clone(extraConfig), ...data.config };
    data.roles = Object.fromEntries(
        Object.entries(data.roles).map(([role, grants]) => [
            role,
            { ...defaults.roles[role], ...grants },
        ]),
    );
    if (previousSchema < 2) {
        // Finance receives financial-read access, rather than clinical access, in this revision.
        data.roles['Finance reviewer'].sensitive = false;
    }
    data.config.notifications = {
        ...defaults.config.notifications,
        ...data.config.notifications,
    };
    data.records = data.records.map((r) => ({
        ...r,
        createdAt: r.createdAt || '2026-10-15T20:00:00.000Z',
        stageAt: r.stageAt || r.createdAt || '2026-10-15T20:00:00.000Z',
        generalSummary:
            r.generalSummary ??
            (r.source === 'Website'
                ? 'Website enquiry awaiting staff review'
                : 'Support enquiry'),
        needsAudience: r.needsAudience || 'Restricted assessment',
        fundingSituation: r.fundingSituation || r.fundingHelp || 'To clarify',
        authorityState:
            r.authorityState || (r.consent ? 'Current' : 'To clarify'),
        authorityScopes: r.authorityScopes || {
            contact: true,
            disclose: !!r.consent,
            service: !!r.agreement,
        },
        requests: r.requests || [],
        appointments: r.appointments || [],
        corrections: r.corrections || [],
        closureHistory: r.closureHistory || [],
        referrerRecord:
            r.referrerRecord ||
            (r.referrer
                ? {
                      name: r.referrer,
                      phone: r.phone,
                      email: r.email,
                      relationship: r.relationship,
                  }
                : null),
        safeContactPerson:
            r.safeContactPerson || (r.referrer ? r.referrer : r.name),
        proposal: {
            ...r.proposal,
            version: r.proposal.version || 1,
            history: r.proposal.history || [],
            lines:
                r.proposal.lines ||
                (knownNumber(r.proposal.weekly)
                    ? [
                          {
                              id: 'support',
                              service: r.service,
                              quantity: 1,
                              unit: 'Weekly package',
                              weekly: Number(r.proposal.weekly),
                          },
                      ]
                    : []),
            start: r.proposal.start || today(),
        },
        documents: r.documents.map((d) => ({
            ...d,
            classification: d.classification || 'Restricted assessment',
            version: d.version || 1,
            uploadedAt: d.uploadedAt || stamp(),
            source: d.source || 'Sample evidence',
            history: d.history || [],
        })),
        funding: r.funding.map((f) => ({
            ...f,
            next: f.next || 'Review funding pathway and preparation',
            due: f.due || businessDate(2),
            decisions: f.decisions || [],
            unit: f.unit || 'NZD',
            period: f.period || 'Week',
            service: f.service || r.service,
            validFrom: f.validFrom || '',
            validTo: f.validTo || '',
            conditionsMet: f.conditionsMet || false,
            providerConfirmed: f.providerConfirmed || false,
            checks: f.checks.map((c, i) => ({
                ...c,
                id: c.id || `${f.path}-${i}`,
            })),
        })),
    }));
    data.versions = data.versions.map((v) => ({
        ...v,
        config:
            v.config ||
            (v.version === data.config.version ? clone(data.config) : null),
        roles:
            v.roles ||
            (v.version === data.config.version ? clone(data.roles) : null),
    }));
    data.savedViews = data.savedViews || [];
    data.receipts = data.receipts || {};
    data.reviewRequests = data.reviewRequests || [];
    data.schema = 2;
    return data;
}
export const decisionKeys = [
    'decision',
    'reference',
    'evidence',
    'amount',
    'unit',
    'period',
    'awarded',
    'allocated',
    'validFrom',
    'validTo',
    'service',
    'conditions',
    'conditionsMet',
    'providerConfirmed',
    'weeklyBasis',
];
export function decisionChanged(a, b) {
    return decisionKeys.some((k) => String(a[k] ?? '') !== String(b[k] ?? ''));
}
export function fundingIssues(f, r, start = r.proposal.start || today()) {
    const issues = [];
    if (f.income) return ['Personal income assistance is not provider funding'];
    if (f.status === 'Withdrawn')
        issues.push('This funding arrangement was withdrawn');
    if (!f.verified) issues.push('Decision needs verification');
    if (['Declined', 'Not received', ''].includes(f.decision || ''))
        issues.push('No approved purchasing arrangement');
    if (!f.reference || !f.evidence)
        issues.push('Decision evidence and reference required');
    if (!f.validFrom || !f.validTo)
        issues.push('Funding validity dates required');
    else if (f.validFrom > start || f.validTo < start)
        issues.push('Funding is not valid on the service start date');
    if (f.validFrom && f.validTo && f.validFrom > f.validTo)
        issues.push('Validity end precedes start');
    if (!f.conditionsMet) issues.push('Conditions need resolution');
    if (!f.providerConfirmed || f.service !== r.service)
        issues.push('Provider and service scope need confirmation');
    if (!knownNumber(f.amount) || Number(f.amount) < 0)
        issues.push('Weekly provider contribution is unknown');
    if (
        !knownNumber(f.awarded) ||
        !knownNumber(f.allocated) ||
        Number(f.allocated) > Number(f.awarded)
    )
        issues.push('Confirm provider allocation within the total award');
    if ((f.unit !== 'NZD' || f.period !== 'Week') && !f.weeklyBasis)
        issues.push('Record an approved basis for the weekly cost equivalent');
    if (
        f.unit === 'NZD' &&
        f.period === 'Week' &&
        Number(f.amount) > Number(f.allocated)
    )
        issues.push('Contribution exceeds this provider’s allocation');
    const overlaps = r.funding.some(
        (other) =>
            other.id !== f.id &&
            other.verified &&
            !other.income &&
            other.reference === f.reference &&
            other.service === f.service &&
            other.validFrom <= f.validTo &&
            other.validTo >= f.validFrom,
    );
    if (overlaps)
        issues.push(
            'Overlapping allocation under the same authorization — resolve before use',
        );
    return issues;
}
export function followupsFor(r, includeDone = false) {
    const primary = r.next
        ? [
              {
                  id: r.id + '-next',
                  primary: true,
                  label: r.next,
                  owner: r.owner,
                  due: r.due,
                  time: r.nextTime || '',
                  done: !!r.nextDone,
                  status: r.nextDone ? 'Completed' : 'Open',
              },
          ]
        : [];
    const funding = r.funding
        .filter((f) => f.next && f.status !== 'Withdrawn')
        .map((f) => ({
            id: f.id + '-next',
            fundingId: f.id,
            label: f.next,
            owner: f.navigator,
            due: f.due,
            time: f.nextTime || '',
            done: !!f.nextDone,
            status: f.nextDone ? f.nextStatus || 'Completed' : 'Open',
            financial: true,
        }));
    return [...primary, ...r.tasks, ...funding].filter(
        (t) => includeDone || (!t.done && t.status !== 'Cancelled'),
    );
}
export function stageIssue(r, stage, config, role, { reopening = false } = {}) {
    if (r.personId || r.stage === 'Converted')
        return 'Converted referrals keep their canonical person link. Manage additional work without changing the intake lifecycle.';
    if (r.stage === 'Closed' && stage !== 'Closed' && !reopening)
        return 'Use Reopen referral with a reason and a renewed next step.';
    const rule = config.stageRules?.find((x) => x.stage === stage);
    if (rule && role !== 'Referral manager' && rule.role !== role)
        return `This stage requires ${rule.role} review.`;
    if (
        rule?.requires === 'Identity and consent' &&
        (!r.identity || !r.consent)
    )
        return 'Identity and sharing authority must be reviewed first.';
    if (
        rule?.requires === 'Verified funding' &&
        !r.funding.some((f) => !fundingIssues(f, r).length)
    )
        return 'This stage requires valid verified funding.';
    if (rule?.requires === 'Named owner' && r.owner === 'Intake team')
        return 'Assign a named owner first.';
    return '';
}

export function allocationIssues(r) {
    const p = r.proposal,
        issues = [];
    if (!p.lines?.length) return ['Add the proposed support lines'];
    for (const line of p.lines) {
        const allocations = (p.allocations || []).filter(
            (a) => a.lineId === line.id,
        );
        let sum = 0;
        for (const a of allocations) {
            const f = r.funding.find((f) => f.id === a.fundingId);
            if (!f || fundingIssues(f, { ...r, service: line.service }).length)
                issues.push(
                    `${line.service}: allocation needs valid verified funding`,
                );
            else sum += Number(a.weekly) || 0;
        }
        if (sum > Number(line.weekly) + 0.005)
            issues.push(
                `${line.service}: coverage exceeds the service line price`,
            );
        if (sum + 0.005 < Number(line.weekly))
            issues.push(
                `${line.service}: allocate coverage to this service line`,
            );
    }
    for (const f of r.funding) {
        const sum = (p.allocations || [])
            .filter((a) => a.fundingId === f.id)
            .reduce((n, a) => n + Number(a.weekly || 0), 0);
        if (sum > Number(f.amount || 0) + 0.005)
            issues.push(
                `${f.path}: allocations exceed this provider’s contribution`,
            );
    }
    return [...new Set(issues)];
}
