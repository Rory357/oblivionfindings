/* eMAR P00 v2 — Medication rules & states. Clickable design mockup, synthetic data only.
 * No application code, routes, schema or configuration. Every state specimen in the
 * catalogue is rendered by the same function the navigation frame uses, so wording and
 * treatment cannot drift between the two. */
(() => {
    'use strict';

    const VERSION = 'P00 v2';
    const NOW = '9:12 am';

    /* ───────────── helpers ───────────── */
    const esc = (s) => String(s).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c]);
    const ic = (n, cls = '') => `<svg class="i ${cls}" aria-hidden="true"><use href="#i-${n}"/></svg>`;
    const NC = (label = 'Not configured') => `<span class="nc" title="Organisation value not decided yet">${ic('settings', 's3')}${label}</span>`;
    const dtag = (d) => `<a class="dtag" href="#/catalogue/decisions" data-dec="${d}" title="Depends on organisation decision ${d}">${d}</a>`;
    const ftag = (f) => `<span class="ftag" title="Review finding ${f}">${f}</span>`;
    const tpl = (s, ctx) => s.replace(/\{(\w+)\}/g, (_, k) => (ctx[k] != null ? ctx[k] : ''));
    const $ = (sel, root = document) => root.querySelector(sel);
    const $$ = (sel, root = document) => [...root.querySelectorAll(sel)];

    /* ───────────── personas (seeded roles, §3 of the navigation plan) ───────────── */
    const ALL = ['view', 'administer', 'correct', 'cd.view', 'cd.record', 'cd.witness', 'orders.manage', 'orders.verify', 'stock.update', 'audit.view', 'reports.export', 'settings.manage', 'breakglass', 'override'];
    const PERSONAS = {
        sw: { id: 'sw', name: 'Priya Shah', short: 'Priya S.', initials: 'PS', role: 'Support worker', perms: ['view', 'administer', 'correct', 'cd.view', 'cd.record', 'cd.witness'] },
        lead: { id: 'lead', name: 'Jordan Tipene', short: 'Jordan T.', initials: 'JT', role: 'House lead', perms: ALL.filter((p) => p !== 'override') },
        clinical: { id: 'clinical', name: 'Hana Kereama', short: 'Hana K.', initials: 'HK', role: 'Clinical lead', perms: ['view', 'orders.manage', 'orders.verify', 'settings.manage', 'override', 'audit.view'] },
        auditor: { id: 'auditor', name: 'Alex Morgan', short: 'Alex M.', initials: 'AM', role: 'Auditor', perms: ['view', 'audit.view'] },
        finance: { id: 'finance', name: 'Kiri Thompson', short: 'Kiri T.', initials: 'KT', role: 'Finance', perms: ['view', 'reports.export', 'stock.update'] },
    };
    const has = (p, k) => PERSONAS[p].perms.includes(k);
    const leadCap = (p) => has(p, 'orders.verify') || has(p, 'orders.manage') || has(p, 'settings.manage');
    const isFrontline = (p) => !['orders.manage', 'orders.verify', 'stock.update', 'audit.view', 'reports.export', 'settings.manage', 'breakglass'].some((k) => has(p, k));

    /* ───────────── hubs (approved structure, plan §2.1–2.2) ───────────── */
    const HUBS = [
        {
            id: 'today', label: 'Meds today', icon: 'pill', show: (p) => has(p, 'administer') || leadCap(p),
            views: [
                { id: 'schedule', label: 'Schedule', icon: 'clock', url: '/meds/today', gate: 'medications.view or administer.record', pkg: 'P01', ok: () => true },
                { id: 'rounds', label: 'Rounds', icon: 'repeat', url: '/emar/rounds (guided: ?guided=)', gate: 'medications.view or administer.record', pkg: 'P01', ok: () => true },
                { id: 'asneeded', label: 'As-needed', icon: 'pill', url: '/meds/today — As-needed', gate: 'administer.record', pkg: 'P01', ok: (p) => has(p, 'administer') },
                { id: 'followups', label: 'Follow-ups', icon: 'flag', url: 'New surface over existing follow-up data', gate: 'administer.record', pkg: 'P08a', ok: (p) => has(p, 'administer') },
                { id: 'controlled', label: 'Controlled checks', icon: 'shield', url: 'Projection of /emar/controlled dialogs', gate: 'controlled.record or controlled.witness', pkg: 'P07a', ok: (p) => has(p, 'cd.record') || has(p, 'cd.witness') },
                { id: 'stockalerts', label: 'Stock alerts', icon: 'package', url: '/meds/today — Stock alerts', gate: 'as today', pkg: 'P06', ok: () => true },
                { id: 'activity', label: 'Activity', icon: 'activity', url: '/meds/today — Activity', gate: 'as today', pkg: 'P01', ok: () => true },
            ],
        },
        {
            id: 'mar', label: 'MAR & medicines', icon: 'clipboard-list', show: (p) => has(p, 'view') && (leadCap(p) || has(p, 'audit.view') || has(p, 'stock.update') === false && has(p, 'administer') === false && has(p, 'audit.view')),
            views: [
                { id: 'charts', label: 'MAR charts', icon: 'clipboard-list', url: '/emar/mar', gate: 'medications.view', pkg: 'P02', ok: () => true },
                { id: 'medicines', label: 'Medicines', icon: 'pill', url: '/emar/medications', gate: 'medications.view', pkg: 'P04', ok: () => true },
                { id: 'prnhistory', label: 'As-needed history', icon: 'history', url: '/emar/prn', gate: 'medications.view', pkg: 'P08a', ok: () => true },
                { id: 'selfadmin', label: 'Support & self-administration', icon: 'user-check', url: '/emar/self-admin', gate: 'medications.view', pkg: 'P03', ok: () => true },
            ],
        },
        {
            id: 'orders', label: 'Orders & reviews', icon: 'file-text', show: (p) => leadCap(p),
            note: 'Reconciliation (new, EM-20) joins this rail only once it is built — never as an empty tab.',
            views: [
                { id: 'prescriptions', label: 'Prescriptions', icon: 'file-text', url: '/emar/prescriptions', gate: 'medications.view (verify/countersign: orders.verify)', pkg: 'P04', ok: () => true },
                { id: 'reviews', label: 'Medication reviews', icon: 'stethoscope', url: '/emar/reviews', gate: 'medications.view', pkg: 'P05', ok: () => true },
            ],
        },
        {
            id: 'stock', label: 'Stock & controlled drugs', icon: 'package', show: (p) => has(p, 'stock.update') || (has(p, 'cd.view') && !isFrontline(p)),
            views: [
                { id: 'stock', label: 'Stock & pharmacy', icon: 'package', url: '/emar/stock', gate: 'view + stock.update', pkg: 'P06', ok: (p) => has(p, 'stock.update') },
                { id: 'register', label: 'Controlled register', icon: 'shield', url: '/emar/controlled', gate: 'view + controlled.view', pkg: 'P07b', ok: (p) => has(p, 'cd.view') },
                { id: 'loss', label: 'Loss reports', icon: 'alert-triangle', url: '/emar/controlled/loss-reports', gate: 'view + controlled.view', pkg: 'P07b', ok: (p) => has(p, 'cd.view') },
                { id: 'destructions', label: 'Destructions & returns', icon: 'ban', url: '/emar/destructions', gate: 'view + controlled.view', pkg: 'P07b', ok: (p) => has(p, 'cd.view') },
            ],
        },
        {
            id: 'safety', label: 'Safety & oversight', icon: 'shield', show: (p) => leadCap(p) || has(p, 'audit.view'),
            views: [
                { id: 'overview', label: 'Overview', icon: 'dashboard', url: '/emar', gate: 'medications.view (content per role)', pkg: 'P09', ok: (p) => leadCap(p) },
                { id: 'followups', label: 'Follow-ups', icon: 'flag', url: 'Oversight queue over the same follow-up data', gate: 'lead capability', pkg: 'P08a', ok: (p) => leadCap(p) },
                { id: 'errors', label: 'Medication errors', icon: 'alert-octagon', url: '/emar/errors', gate: 'medications.view', pkg: 'P08b', ok: () => true },
                { id: 'handovers', label: 'Handovers', icon: 'repeat', url: '/emar/handovers', gate: 'medications.view', pkg: 'P08a', ok: (p) => leadCap(p) },
                { id: 'eligibility', label: 'Staff eligibility', icon: 'user-check', url: '/emar/competency', gate: 'medications.view (who reads all assessments is open)', pkg: 'P11', ok: (p) => leadCap(p) },
                { id: 'emergency', label: 'Emergency access', icon: 'lock', url: '/emar/emergency-access', gate: 'breakglass (request) · audit.view (review, NF-12)', pkg: 'P10', ok: (p) => has(p, 'breakglass') || has(p, 'audit.view') },
            ],
        },
        {
            id: 'reports', label: 'Reports & audit', icon: 'chart', show: (p) => has(p, 'reports.export') || has(p, 'audit.view'),
            note: 'The shared report builder joins as a medication domain only after the counts reconcile (EM-01/02).',
            views: [
                { id: 'reports', label: 'Reports', icon: 'chart', url: '/emar/reports', gate: 'reports.export', pkg: 'P09', ok: (p) => has(p, 'reports.export') },
                { id: 'audit', label: 'Audit trail', icon: 'history', url: '/emar/audit', gate: 'audit.view', pkg: 'P09', ok: (p) => has(p, 'audit.view') },
                { id: 'print', label: 'Print & exports', icon: 'printer', url: '/emar/pdf/*, exports', gate: 'per export', pkg: 'P09', ok: (p) => has(p, 'reports.export') || has(p, 'audit.view') },
            ],
        },
        {
            id: 'settings', label: 'Settings', icon: 'settings', show: (p) => has(p, 'settings.manage'),
            note: 'Alert recipients joins this rail only if recipients become configurable (today they are derived).',
            views: [
                { id: 'rules', label: 'Administration rules', icon: 'settings', url: '/emar/settings', gate: 'settings.manage', pkg: 'P11', ok: () => true },
                { id: 'templates', label: 'Round templates', icon: 'repeat', url: 'moved from Rounds', gate: 'settings.manage', pkg: 'P11', ok: () => true },
                { id: 'eapolicy', label: 'Emergency access policy', icon: 'lock', url: 'PUT /emar/break-glass-policy', gate: 'settings.manage', pkg: 'P10', ok: () => true },
                { id: 'history', label: 'Change history', icon: 'history', url: 'settings change history', gate: 'settings.manage', pkg: 'P11', ok: () => true },
            ],
        },
    ];
    // MAR & medicines: anyone with medications.view except frontline and finance-only roles.
    HUBS[1].show = (p) => has(p, 'view') && !isFrontline(p) && !(has(p, 'stock.update') && !leadCap(p) && !has(p, 'audit.view'));
    const hubById = (id) => HUBS.find((h) => h.id === id);
    const visibleHubs = (p) => HUBS.filter((h) => h.show(p));
    const visibleViews = (p, hub) => hub.views.filter((v) => v.ok(p));

    /* ───────────── synthetic people and medicines ───────────── */
    const PEOPLE = {
        aroha: { id: 'aroha', pref: 'Aroha', legal: 'Aroha Mere Ngata', surname: 'Ngata', initials: 'AN', house: 'Kōwhai House', photo: true, born: '14 March 1987', nhi: 'ZAA0024', comm: 'Likes a quiet space for medicines. Te reo Māori greetings welcome.', allergy: 'recorded' },
        tama: { id: 'tama', pref: 'Tama', legal: 'Tamati James Walker', surname: 'Walker', initials: 'TW', house: 'Kōwhai House', photo: false, born: '2 July 1979', nhi: 'ZAB0033', comm: 'Tablets one at a time with water.', allergy: 'none' },
        mele: { id: 'mele', pref: 'Mele', legal: 'Mele Fifita', surname: 'Fifita', initials: 'MF', house: 'Kōwhai House', photo: false, born: '23 November 1992', nhi: 'ZAC0041', comm: 'Prefers to hold the cup.', allergy: 'recorded-pen' },
        grace: { id: 'grace', pref: 'Grace', legal: 'Grace Liu', surname: 'Liu', initials: 'GL', house: 'Kōwhai House', photo: false, born: '8 May 1951', nhi: 'ZAD0058', comm: 'Speaks Cantonese and English.', allergy: 'unavailable' },
        sam: { id: 'sam', pref: 'Sam', legal: 'Samuel Tuilagi', surname: 'Tuilagi', initials: 'ST', house: 'Kōwhai House', photo: false, born: '30 January 2001', nhi: 'ZAE0066', comm: 'Manages own morning medicines.', allergy: 'nkda' },
        ben: { id: 'ben', pref: 'Ben', legal: 'Benjamin Clarke', surname: 'Clarke', initials: 'BC', house: 'Rimu House', photo: false, born: '11 April 1968', nhi: 'ZAF0075', comm: '', allergy: 'none' },
    };
    const SUPPORT = {
        administer: { label: 'Administer', icon: 'hand', desc: 'Staff give the medicine' },
        assist: { label: 'Assist', icon: 'hand', desc: 'Staff help, the person takes it' },
        prompt: { label: 'Prompt', icon: 'message', desc: 'Staff remind, the person takes it' },
        independent: { label: 'Independent', icon: 'user-check', desc: 'The person manages it' },
    };
    const supportChip = (k) => `<span class="support-chip" title="${esc(SUPPORT[k].desc)}">${ic(SUPPORT[k].icon, 's3')}${SUPPORT[k].label}</span>`;
    const PHOTO = `<svg viewBox="0 0 64 64" role="img" aria-label="Photo on file (synthetic placeholder)"><rect width="64" height="64" style="fill: color-mix(in oklch, var(--primary) 18%, var(--card))"/><circle cx="32" cy="25" r="11" style="fill: color-mix(in oklch, var(--primary) 45%, var(--card))"/><path d="M10 60c2-12 11-18 22-18s20 6 22 18z" style="fill: color-mix(in oklch, var(--primary) 45%, var(--card))"/></svg>`;

    /* ───────────── state vocabulary (the contract) ───────────── */
    const DOSE = {
        notdue: { v: 'neutral', i: 'clock', l: 'Not yet due' },
        due: { v: 'info', i: 'clock', l: 'Due' },
        late: { v: 'warning', i: 'alert-triangle', l: 'Late' },
        notrecorded: { v: 'critical', i: 'help', l: 'Not yet recorded' },
        missed: { v: 'critical', i: 'x-circle', l: 'Missed' },
        given: { v: 'success', i: 'check', l: 'Given' },
        prompted: { v: 'success', i: 'message', l: 'Taken with prompting' },
        assisted: { v: 'success', i: 'hand', l: 'Taken with assistance' },
        selfmanaged: { v: 'neutral', i: 'user-check', l: 'Self-managed' },
        refused: { v: 'warning', i: 'x', l: 'Refused' },
        reoffered: { v: 'success', i: 'repeat', l: 'Given after re-offer' },
        withheld: { v: 'warning', i: 'pause', l: 'Withheld' },
        away: { v: 'neutral', i: 'log-out', l: 'Away' },
        sending: { v: 'info', i: null, l: 'Sending…', spin: true },
        queued: { v: 'warning', i: 'save', l: 'Saved on this device' },
        rejected: { v: 'critical', i: 'x-circle', l: 'Not recorded' },
        corrected: { v: 'neutral', i: 'history', l: 'Corrected' },
        uncertain: { v: 'warning', i: 'help', l: 'Not confirmed' },
    };
    const dbadge = (k, sm = false) => {
        const d = DOSE[k];
        return `<span class="badge b-${d.v}${sm ? ' sm' : ''}">${d.spin ? '<span class="ring-spin" aria-hidden="true"></span>' : ic(d.i)}${d.l}</span>`;
    };

    /* Blocked reasons. {p} preferred name, {med} medicine. `still`: none | notgiven | withheld | custom text. */
    const BLOCKS = {
        notClockedIn: {
            title: 'You’re not clocked in', icon: 'log-in',
            text: 'To record for {p}, you need to be clocked in on a shift that includes {p}.',
            next: ['Clock in from the top bar.', `Can’t clock in? Contact the coordinator on call: ${NC()}`],
            still: 'none', stillText: 'Nothing can be recorded until you’re clocked in. You can still read the instructions.',
            action: { label: 'Clock in', act: 'clock-in', icon: 'log-in' },
            gate: 'Covering shift: clocked in, shift includes the person (server rule kept).', depends: ['D2', 'D12'], fixes: ['NF-07'],
        },
        notOnShift: {
            title: '{p} isn’t on your shift', icon: 'users',
            text: 'You’re clocked in at Kōwhai House (7:00 am–3:00 pm), but your shift doesn’t include {p}.',
            next: ['Ask the house lead to add {p} to your shift. Jordan Tipene is on shift today.', `Coordinator on call: ${NC()}`],
            still: 'none', stillText: 'You can’t record for {p} until {p} is on your shift.',
            action: { label: 'Message Jordan Tipene', act: 'toast-outside', icon: 'message' },
            gate: 'Covering shift must list the person (shifts.client_id or shift_clients).', depends: ['D2', 'D12'], fixes: ['NF-07'], breakglass: true,
        },
        shiftEnded: {
            title: 'Your shift ended at 3:00 pm', icon: 'clock',
            text: `Recording after a shift ends: ${NC()}. Until that’s decided, you can’t record after your shift.`,
            next: ['Add this dose to the handover so the next shift can follow it up.', 'Tell the house lead what happened.'],
            still: 'none', stillText: 'Nothing can be recorded after your shift until the organisation decides the rule.',
            action: { label: 'Open handover', act: 'toast-outside', icon: 'repeat' },
            gate: 'Shift in progress or ended after the dose time (server rule kept).', depends: ['D2'], fixes: ['NF-07'],
        },
        siteNotApproved: {
            title: 'Your access doesn’t include Rimu House', icon: 'map-pin',
            text: '{p} lives at Rimu House. Your account is approved for Kōwhai House only.',
            next: ['If you work at Rimu House, ask your manager to approve it for your account.'],
            still: 'none', stillText: 'You can’t record for people at houses outside your access.',
            note: 'Shown only when the person is already visible to you (for example, they moved house during your shift). A direct link to someone outside your access shows “We can’t show this record”.',
            gate: 'Approved-site access (server rule kept).', depends: ['D2'], fixes: ['NF-07'], breakglass: true,
        },
        competencyExpired: {
            title: 'Your medication competency expired on 14 September 2026', icon: 'user-check', crit: true,
            text: 'You can’t sign doses as given until you’re reassessed.',
            next: ['Ask a competent colleague to give this dose.', 'Book a reassessment with your assessor, Hana Kereama.'],
            still: 'notgiven', action: { label: 'View my eligibility', act: 'eligibility', icon: 'user-check' },
            gate: 'MedicationAdministratorCompetencyPolicy, re-checked when you save.', depends: ['D3'], fixes: ['EM-03', 'NF-03'],
        },
        exemptionEnded: {
            title: 'Your competency exemption ended on 20 September 2026', icon: 'user-check', crit: true,
            text: 'Jordan Tipene approved an exemption until 20 September 2026. Exemptions have a fixed end date.',
            next: ['Ask a competent colleague to give this dose.', 'Ask Jordan Tipene about booking an assessment.'],
            still: 'notgiven', action: { label: 'View my eligibility', act: 'eligibility', icon: 'user-check' },
            gate: 'Finite approved exemption (server rule kept).', depends: ['D3'], fixes: ['NF-03'],
        },
        restrictedBlock: {
            title: 'You can’t sign doses as given', icon: 'user-check', crit: true,
            text: 'Your medication competency is restricted (Hana Kereama, 2 March 2026: “supervised practice until reassessed”). A competent colleague must give doses.',
            next: ['Ask a competent colleague to give this dose.', 'Ask a competency assessor to review the restriction.'],
            still: 'notgiven', action: { label: 'View my eligibility', act: 'eligibility', icon: 'user-check' },
            gate: 'Organisation safety rule “restricted competency” set to Block. Off by default; which value to use is Stephan’s NF-03 decision.', depends: ['D3', 'NF-03 setting'], fixes: ['NF-03'],
            note: 'Shown at the start of the dose and as-needed wizards, only when the organisation sets this rule. With Off, no notice shows and the restriction isn’t enforced.',
        },
        restrictedCosigner: {
            title: 'Co-signer required', icon: 'users', cosigner: true,
            text: 'Your medication competency is restricted (Hana Kereama, 2 March 2026: “supervised practice until reassessed”). A present, qualified co-signer must confirm each dose you sign as given.',
            next: ['Choose a co-signer who is on shift now and whose own competency is current and not restricted.', 'They confirm with their own password in “Co-signer password”.'],
            still: 'Refusals, withheld doses and absences don’t need a co-signer.', action: { label: 'View my eligibility', act: 'eligibility', icon: 'user-check' },
            gate: 'Organisation safety rule “restricted competency” set to Co-signer. The server rejects a co-signer whose own competency is restricted or not current.', depends: ['D3', 'NF-03 setting'], fixes: ['NF-03'],
            note: 'A warning, not a block: “given” stays available once a qualified co-signer confirms.',
        },
        areaFailed: {
            title: '“Controlled drugs” isn’t one of your passed areas', icon: 'user-check', crit: true,
            text: '{p}’s {med} is a controlled drug. Your assessment on 2 March 2026 shows “Controlled drugs” as not passed.',
            next: ['Ask a competent colleague to give this dose.', 'Ask a competency assessor to reassess you.'],
            still: 'notgiven', action: { label: 'View my eligibility', act: 'eligibility', icon: 'user-check' },
            gate: 'Organisation safety rule “controlled-drug or covert area” set to Block when failed (or failed or not seen). Insulin isn’t covered yet: orders don’t record whether a medicine is insulin.', depends: ['D3', 'NF-03 setting'], fixes: ['NF-03'],
        },
        areaNotSeen: {
            title: '“Covert administration” wasn’t assessed', icon: 'user-check', crit: true,
            text: '{p}’s {med} has an active covert authorisation. Your assessment on 2 March 2026 didn’t cover “Covert administration” (not seen at assessment).',
            next: ['Ask a competent colleague to give this dose.', 'Ask a competency assessor to assess this area.'],
            still: 'notgiven', action: { label: 'View my eligibility', act: 'eligibility', icon: 'user-check' },
            gate: 'Blocks only when the organisation rule is “Block when failed or not seen”. With “Block when failed”, a not-seen area is allowed.', depends: ['D3', 'NF-03 setting'], fixes: ['NF-03'],
        },
        noWitness: {
            title: 'No eligible witness on shift', icon: 'users',
            text: '{med} is a controlled medicine and needs a witness. A witness must be a different person, on shift at Kōwhai House now, with current controlled-medicine witness competency. No one on shift meets all three.',
            next: [`Contact the coordinator on call: ${NC()}`, 'Don’t ask someone who isn’t eligible to witness.'],
            still: 'withheld', gate: 'Witness independence, presence and competency (server rule kept).', depends: ['D8', 'D12'], fixes: ['EM-03', 'EM-25'],
        },
        awaitingVerification: {
            title: 'This order is waiting to be checked', icon: 'file-text',
            text: '{med} is a new order from Dr Lena Chen on 27 September. Someone who can check orders must verify it before it’s given.',
            next: ['Ask the house lead to check it. Jordan Tipene is on shift today.'],
            still: 'withheld', leadAction: { label: 'Open in Orders & reviews', act: 'go', href: '#/frame/{persona}/orders/prescriptions', icon: 'file-text' },
            gate: 'Order verification status (server rule kept).', depends: ['D2'], fixes: ['EM-25'],
        },
        covertMissing: {
            title: 'No current covert plan', icon: 'eye-off',
            text: '{med} for {p} is marked to be given covertly (hidden in food or drink, without {p} knowing), but there’s no current authorisation on file.',
            next: ['Don’t give it covertly.', 'Contact the clinical lead, Hana Kereama.'],
            still: 'withheld', gate: 'A missing or revoked authorisation blocks (today only an expired active one does).', depends: ['D2', 'D13*'], fixes: ['EM-25'],
        },
        covertExpired: {
            title: 'The covert plan is past its review date', icon: 'eye-off',
            text: 'The covert authorisation for {p}’s {med} was due for review on 1 September 2026.',
            next: ['Don’t give it covertly.', 'Contact the clinical lead, Hana Kereama.'],
            still: 'withheld', gate: 'Covert authorisation review date (server rule kept).', depends: ['D2', 'D13*'], fixes: ['EM-25'],
        },
        prnLimit: {
            title: 'As-needed limit reached', icon: 'ban',
            text: '{med}: the prescription allows 4 doses in 24 hours. 4 have been given in the last 24 hours — the most recent at 8:05 am.',
            next: ['Don’t give another dose.', `If {p} still needs relief, contact the on-call contact: ${NC()}`],
            still: 'Add a note to the shift notes about what {p} asked for.',
            gate: 'Order limit, counted over real elapsed time (EM-02 fix); how limits are counted is D4.', depends: ['D4', 'D12'], fixes: ['EM-08', 'EM-26', 'EM-02'],
        },
        safetyAllergy: {
            title: 'Allergy match — don’t give', icon: 'alert-octagon', safety: true,
            text: '{p} has a severe penicillin allergy in the medication allergy list, reviewed 12 August 2026. {med} is a penicillin.',
            next: ['Don’t give it.', 'Record this dose as withheld.', `Check with the prescriber. On-call contact: ${NC()}`],
            still: 'withheld-always', override: true,
            gate: 'Severe matches in the medication allergy list always block. Blocking safety checks apply to “given” only; not-given outcomes always save (NF-06).', depends: ['D5', 'D2'], fixes: ['EM-07', 'NF-06', 'NF-09'],
        },
        safetyAllergyProfile: {
            title: 'Allergy match — don’t give', icon: 'alert-octagon', safety: true, match: true,
            text: 'Your organisation blocks allergy matches, so this can’t be recorded as given.',
            next: ['Contact the prescriber, Dr Lena Chen (prescribed 27 September), before anything else.', 'Or ask someone authorised to override it. An override needs a reason and is reviewed afterwards.', 'Record this dose as withheld.'],
            still: 'withheld-always', override: true,
            gate: 'Organisation rule “When a medicine matches a recorded allergy” set to Block. Implemented today for health-profile matches (Warn / Block); severe matches in the medication allergy list always block.', depends: ['D5', 'D2'], fixes: ['EM-07', 'NF-06'],
        },
        allergyNotConfirmed: {
            title: 'Allergy match — the prescriber hasn’t confirmed it', icon: 'alert-octagon', safety: true, match: true,
            text: 'Your organisation allows this only when the prescriber has confirmed the allergy on the order. There’s no confirmation on this order.',
            next: ['Ask the prescriber, Dr Lena Chen, to confirm the allergy on the order — or change the order.', 'Or ask someone authorised to override it. An override needs a reason and is reviewed afterwards.', 'Record this dose as withheld.'],
            still: 'withheld-always', override: true,
            gate: 'Organisation rule set to “Block unless the prescriber has confirmed this allergy on the order”. New — not built: needs a prescriber confirmation (who, when, source) stored on the order.', depends: ['D5', 'D2'], fixes: ['EM-07'],
        },
        safetyContra: {
            title: 'Safety check — don’t give', icon: 'alert-octagon', safety: true,
            text: '{med} matches a contraindication recorded for {p} by Hana Kereama on 4 June 2026. The rule’s own text is shown here, from the record.',
            next: ['Don’t give it.', 'Record this dose as withheld.', `Contact the on-call contact: ${NC()}`],
            still: 'withheld-always', override: true,
            gate: 'Same rule as allergy matches (NF-06).', depends: ['D2'], fixes: ['NF-06', 'NF-09'],
        },
    };
    const STILL = {
        none: null,
        notgiven: 'You can still record a refusal, a withhold or an absence.',
        withheld: 'You can still record this dose as withheld and say why.',
        'withheld-always': 'You can always record this dose as withheld.',
    };

    function blockedPanel(key, ctx) {
        const b = BLOCKS[key];
        const c = { p: ctx.p || 'Aroha', med: ctx.med || 'this medicine', persona: ctx.persona || 'sw' };
        const still = STILL[b.still] !== undefined ? STILL[b.still] : b.still;
        const stillHtml = still ? `<div class="bl-still">${ic('check-circle', 's35')}${esc(tpl(still, c))}</div>` : b.stillText ? `<p class="bl-text"><b>${esc(tpl(b.stillText, c))}</b></p>` : '';
        const bg = b.breakglass && has(c.persona, 'breakglass')
            ? `<p class="bl-text">${ic('lock', 's35')} You can request emergency access for ${esc(c.p)} only. It lasts a limited time, is recorded and is reviewed afterwards. It never covers a whole round.</p>` : '';
        const ov = b.override && has(c.persona, 'override')
            ? `<p class="bl-text">You’re allowed to override safety checks. An override needs a reason and is reviewed.</p>` : '';
        return `<div class="blocked${b.safety ? ' safety' : b.crit ? ' crit' : ''}" role="group" aria-label="${esc(tpl(b.title, c))}">
            <div class="bl-title">${ic(b.icon)}${esc(tpl(b.title, c))}</div>
            ${b.match ? allergyMatchLine(c.med, 'penicillin', 'health profile', true) : ''}<p class="bl-text">${tpl(b.text, { p: esc(c.p), med: esc(c.med) })}</p>
            <ul class="bl-next">${b.next.map((n) => `<li>${tpl(n, { p: esc(c.p), med: esc(c.med) })}</li>`).join('')}</ul>
            ${stillHtml}${bg}${ov}
            <div class="bl-foot">Checked again when you save.</div>
        </div>`;
    }

    /* Allergy status (D5). Never a false "no known allergies". */
    function allergyBanner(state, p = 'Aroha', compact = false) {
        const P = esc(p);
        if (state === 'recorded' || state === 'recorded-pen') {
            const list = state === 'recorded' ? 'Penicillin (severe) · Latex (mild)' : 'Penicillin (health profile — severity not recorded)';
            return `<div class="banner safety-solid" role="note"><span class="b-ico">${ic('alert-octagon')}</span><div class="b-body"><div class="b-title">Allergies: ${list}</div><div class="b-text">Check the label against the allergy list before giving.${compact ? '' : ' From the medication allergy list and the health profile · reviewed 12 August 2026 by Jordan Tipene.'}</div></div></div>`;
        }
        if (state === 'nkda') {
            return `<div class="banner neutral" role="note"><span class="b-ico">${ic('check-circle')}</span><div class="b-body"><div class="b-title">No known drug allergies</div>${compact ? '' : `<div class="b-text">Recorded on the health profile by Jordan Tipene, 12 August 2026.</div>`}</div></div>`;
        }
        if (state === 'none') {
            return `<div class="banner warning" role="note"><span class="b-ico">${ic('alert-triangle')}</span><div class="b-body"><div class="b-title">No allergies recorded for ${P}</div><div class="b-text">Check the health profile before giving. This doesn’t mean ${P} has none.</div></div></div>`;
        }
        return `<div class="banner warning" role="alert"><span class="b-ico">${ic('alert-circle')}</span><div class="b-body"><div class="b-title">Allergy record couldn’t be loaded for ${P}</div><div class="b-text">Check the health profile before giving. Don’t assume ${P} has none.</div>${compact ? '' : `<div class="b-actions"><button class="btn btn-outline btn-sm" type="button" data-act="toast" data-msg="Allergy record reloaded (mockup).">${ic('refresh')}Try again</button></div>`}</div></div>`;
    }

    /* The SPECIFIC match, shown before signing in every mode (Stephan, 29 Sep): medicine — allergen — source. */
    function allergyMatchLine(med = 'Amoxicillin', allergen = 'penicillin', source = 'health profile', onSafety = false) {
        return `<div class="match-line${onSafety ? ' on-safety' : ''}" role="note">${ic('alert-octagon', 's35')}<span><b>${esc(med)}</b> — matches recorded ${esc(allergen)} allergy (${esc(source)})</span></div>`;
    }
    function allergyConfirmedNote(where = 'dose') {
        return `<div class="banner neutral" role="note"><span class="b-ico">${ic('check-circle')}</span><div class="b-body"><div class="b-title">${where === 'order' ? 'Allergy check: confirmed by the prescriber' : 'The prescriber confirmed this allergy on the order'}</div><div class="b-text">Dr Lena Chen · 27 September 2026, 2:40 pm · source: signed prescription (uploaded) · entered by Jordan Tipene</div></div></div>`;
    }
    function overrideSpecimen() {
        return `<div class="card card-pad" style="display:flex;flex-direction:column;gap:10px"><div style="font-weight:650;font-size:13.5px;display:flex;gap:8px;align-items:center">${ic('shield', 's35')}Override the allergy block</div>${allergyMatchLine()}
            <div class="field"><span class="flabel" id="ov-r">Reason <span class="req">*</span></span><select aria-labelledby="ov-r"><option>Clinical direction</option><option>Urgent clinical need</option><option>Known record discrepancy</option><option>Other</option></select></div>
            <div class="field"><span class="flabel" id="ov-n">Who advised and what they said <span class="req">*</span></span><textarea aria-labelledby="ov-n" rows="2">Dr Lena Chen confirmed by phone at 9:20 am: tolerated amoxicillin in 2024; give as prescribed.</textarea></div>
            <p class="text-caption" style="margin:0">Only people allowed to override safety checks see this. Overrides are reviewed afterwards by: ${NC()}</p>
            <div style="display:flex;justify-content:flex-end;gap:8px"><button class="btn btn-outline btn-sm" type="button" tabindex="-1">Cancel</button><button class="btn btn-destructive btn-sm" type="button" tabindex="-1">Override and allow “given”</button></div></div>`;
    }
    /* Health-profile allergy match with no severity (EM-07 fix): warns by default; blocks only if the
     * organisation's safety rule is set to Block (then it renders as the blockedPanel 'safetyAllergyProfile'). */
    function profileMatchWarning(p = 'Mele', med = 'Amoxicillin') {
        return `<div class="banner warning" role="alert"><span class="b-ico">${ic('alert-triangle')}</span><div class="b-body"><div class="b-title">Possible allergy match — check before giving</div>${allergyMatchLine(med)}<div class="b-text">Your organisation warns on allergy matches. Check with the prescriber before giving; you can still record it as given.</div></div></div>`;
    }

    /* Person identity header — used at the top of every recording dialog. */
    function identityHeader(pid, opts = {}) {
        const pp = PEOPLE[pid];
        const photo = pp.photo ? PHOTO : `<span aria-hidden="true">${pp.initials}</span>`;
        if (opts.compact) {
            return `<div class="idh compact"><div class="idh-photo">${photo}</div><div><div class="idh-name"><span class="pref">${esc(pp.pref)}</span><span class="legal">${esc(pp.legal)}</span></div><div class="who-sub">${esc(pp.house)}${pp.photo ? '' : ' · No photo on file'}</div></div>${opts.support ? supportChip(opts.support) : ''}</div>`;
        }
        const idCheck = pp.photo
            ? 'Compare with the photo on file.'
            : `No photo on file. Check identity using: ${NC()}`;
        const supportLine = opts.support === 'unknown'
            ? `${NC('Not recorded')} <span class="who-sub">Support for this medicine isn’t in the support plan — ask the house lead.</span>`
            : opts.support ? `${supportChip(opts.support)} <span class="who-sub">${esc(SUPPORT[opts.support].desc)} · from the support plan, reviewed 12 January 2026</span>` : '';
        return `<section class="idh" aria-label="Person">
            <div class="idh-photo">${photo}</div>
            <div>
                <div class="idh-name"><span class="pref">${esc(pp.pref)}</span><span class="legal">Legal name: ${esc(pp.legal)}</span></div>
                <div class="idh-facts"><span class="chipn">${ic('home', 's3')}${esc(pp.house)}</span><span class="chipn">Born ${esc(pp.born)}</span><span class="chipn">NHI ${esc(pp.nhi)} <span class="who-sub">(test)</span></span></div>
                <div class="idh-lines">
                    <div class="idh-line"><span class="k">Identity</span><span class="v">${idCheck}</span></div>
                    ${supportLine ? `<div class="idh-line"><span class="k">Support for this medicine</span><span class="v">${supportLine}</span></div>` : ''}
                    ${pp.comm ? `<div class="idh-line"><span class="k">Preferences</span><span class="v">${esc(pp.comm)}</span></div>` : ''}
                </div>
            </div>
        </section>`;
    }

    /* ───────────── Meds today schedule (synthetic, Monday 28 September 2026, 9:12 am NZDT) ───────────── */
    const MEDS = {
        r1: { slot: '8:00 am', pid: 'aroha', med: 'Metformin', str: '500 mg tablet', dose: '1 tablet', ins: 'With breakfast', support: 'administer', state: 'given', line: 'Given 8:05 am · Priya S.' },
        r2: { slot: '8:00 am', pid: 'tama', med: 'Levetiracetam', str: '500 mg tablet', dose: '1 tablet', ins: 'Morning and evening', support: 'administer', state: 'late', line: 'Due 8:00 am · 1 h 12 min ago' },
        r3: { slot: '8:00 am', pid: 'mele', med: 'Amoxicillin', str: '500 mg capsule', dose: '1 capsule', ins: 'Three times a day for 5 days', support: 'administer', state: 'late', line: 'Due 8:00 am · 1 h 12 min ago', block: 'safetyAllergy' },
        r4: { slot: '8:00 am', pid: 'grace', med: 'Sertraline', str: '50 mg tablet', dose: '1 tablet', ins: 'In the morning', support: 'administer', state: 'refused', line: 'Refused 8:10 am · Grace said no', fu: 'Follow-up · Priya S. · due 12:00 pm' },
        r5: { slot: '8:00 am', pid: 'sam', med: 'Cetirizine', str: '10 mg tablet', dose: '1 tablet', ins: 'Once a day', support: 'independent', state: 'selfmanaged', line: 'Sam manages this medicine · nothing to record' },
        r6: { slot: '9:00 am', pid: 'aroha', med: 'Vitamin D (colecalciferol)', str: '1.25 mg capsule', dose: '1 capsule', ins: 'Monthly, first Monday', support: 'prompt', state: 'due', line: 'Due now · 9:00 am' },
        r7: { slot: '9:00 am', pid: 'mele', med: 'Omeprazole', str: '20 mg capsule', dose: '1 capsule', ins: 'Before breakfast · new order', support: 'administer', state: 'due', line: 'Due now · 9:00 am', block: 'awaitingVerification' },
        r8: { slot: '9:00 am', pid: 'tama', med: 'Macrogol', str: 'sachet', dose: '1 sachet', ins: 'Mixed in water', support: 'assist', state: 'assisted', line: 'Taken with assistance 9:04 am · Priya S. mixed the sachet' },
        r9: { slot: '12:00 pm', pid: 'aroha', med: 'Metformin', str: '500 mg tablet', dose: '1 tablet', ins: 'With lunch', support: 'administer', state: 'notdue', line: 'Due 12:00 pm' },
        r10: { slot: '12:00 pm', pid: 'aroha', med: 'Methylphenidate', str: '10 mg tablet', dose: '1 tablet', ins: 'Needs a witness', support: 'administer', state: 'notdue', line: 'Due 12:00 pm · needs a witness', cd: true },
    };
    const PRN = { pid: 'aroha', med: 'Paracetamol', str: '500 mg tablet', dose: '2 tablets', ins: 'For pain · up to 4 doses in 24 hours (from the prescription)' };

    /* ───────────── router state ───────────── */
    const S = {
        mode: 'frame', persona: 'sw', hub: 'today', view: 'schedule', pid: 'aroha', ptab: 'chart', section: 'intro', scenario: 'normal',
        rows: {}, rejectedAcknowledged: false, clockedIn: true, forceCollapse: null,
        orderConfirmed: false, method: 'password', nobody: false,
        safety: { profileAllergy: 'warn', restricted: 'off', area: 'off' }, safetyDraft: { profileAllergy: 'warn', restricted: 'off', area: 'off' }, safetySetBy: {},
    };
    const SCENARIOS = [
        ['normal', 'Normal shift'],
        ['loading', 'Loading'],
        ['empty', 'No work left'],
        ['unavailable', 'Couldn’t load'],
        ['stale', 'Out of date'],
        ['offline', 'Offline'],
        ['notClockedIn', 'Not clocked in'],
        ['competencyExpired', 'Competency expired'],
        ['restrictedBlock', 'Restricted competency — Block rule'],
        ['restrictedCosigner', 'Restricted competency — Co-signer rule'],
        ['reject', 'Server refuses the next save'],
        ['uncertain', 'Save not confirmed'],
        ['offlineRefused', 'Offline item refused when sent'],
    ];

    function parseHash() {
        const raw = location.hash.replace(/^#\/?/, '');
        const [path, query] = raw.split('?');
        const seg = path.split('/').filter(Boolean);
        const q = new URLSearchParams(query || '');
        if (q.get('scenario')) S.scenario = q.get('scenario');
        S.only = q.get('only') || null;
        if (q.get('allergy')) { S.safety.profileAllergy = q.get('allergy'); S.safetyDraft.profileAllergy = q.get('allergy'); }
        if (q.get('confirmed') !== null) S.orderConfirmed = q.get('confirmed') === '1';
        if (q.get('method')) S.method = q.get('method');
        if (q.get('nobody') !== null) S.nobody = q.get('nobody') === '1';
        if (seg[0] === 'catalogue') { S.mode = 'catalogue'; S.section = seg[1] || 'intro'; return; }
        if (seg[0] === 'person') { S.mode = 'person'; S.persona = PERSONAS[seg[1]] ? seg[1] : 'sw'; S.pid = PEOPLE[seg[2]] ? seg[2] : 'aroha'; S.ptab = seg[3] || 'chart'; return; }
        if (seg[0] === 'myday') { S.mode = 'myday'; S.persona = PERSONAS[seg[1]] ? seg[1] : 'sw'; return; }
        if (seg[0] === 'handover') { S.mode = 'handover'; S.persona = PERSONAS[seg[1]] ? seg[1] : 'sw'; S.handoverAck = false; return; }
        if (seg[0] === 'record') { S.mode = 'record'; S.persona = PERSONAS[seg[1]] ? seg[1] : 'sw'; S.recordId = seg[2]; return; }
        S.mode = 'frame';
        S.persona = PERSONAS[seg[1]] ? seg[1] : 'sw';
        const hubs = visibleHubs(S.persona);
        S.hub = seg[2] || (hubs[0] ? hubs[0].id : 'today');
        const hub = hubById(S.hub);
        const views = hub ? visibleViews(S.persona, hub) : [];
        S.view = seg[3] || (views[0] ? views[0].id : 'none');
    }
    const hrefFrame = (p, hub, view) => `#/frame/${p}/${hub}${view ? '/' + view : ''}`;

    /* ───────────── viewer bar (mockup harness) ───────────── */
    function renderViewer() {
        const frameish = S.mode !== 'catalogue';
        $('#viewer').innerHTML = `
            <a class="btn-link" href="#main" data-act="skip" style="font-size:12px">Skip to page content</a>
            <span class="v-id" title="Synthetic data only · desktop web · checked at 1440, 1280 and 200 %"><span class="v-tag">Design mockup</span>${VERSION} · Medication rules &amp; states <span class="v-sub">Synthetic data only · desktop web · checked at 1440, 1280 and 200 %</span></span>
            <span class="v-spacer"></span>
            <span class="v-group" role="group" aria-label="Mockup view">
                <span class="seg">
                    <button type="button" aria-pressed="${frameish}" data-act="mode" data-mode="frame">Navigation frame</button>
                    <button type="button" aria-pressed="${!frameish}" data-act="mode" data-mode="catalogue">State catalogue</button>
                </span>
            </span>
            ${frameish ? `
            <span class="v-group"><label for="v-persona">Signed in as</label>
                <select id="v-persona" data-act="persona">${Object.values(PERSONAS).map((p) => `<option value="${p.id}"${p.id === S.persona ? ' selected' : ''}>${p.role} — ${p.name}</option>`).join('')}</select></span>
            <span class="v-group"><label for="v-scn">Scenario</label>
                <select id="v-scn" data-act="scenario">${SCENARIOS.map(([k, l]) => `<option value="${k}"${k === S.scenario ? ' selected' : ''}>${l}</option>`).join('')}</select></span>
            ${S.scenario === 'restrictedCosigner' ? `<span class="v-group"><label for="v-meth">Second person</label><select id="v-meth" data-act="method">${[['password', 'Login password (today)'], ['A', 'A — block, show who can give'], ['B', 'B — witness PIN'], ['C', 'C — confirm from own session']].map(([k, l]) => `<option value="${k}"${k === S.method ? ' selected' : ''}>${l}</option>`).join('')}</select></span>` : ''}` : ''}
        `;
    }

    /* ───────────── shell ───────────── */
    function topbar() {
        const scn = S.scenario;
        const clockedIn = scn !== 'notClockedIn' && S.clockedIn;
        const pr = PERSONAS[S.persona];
        return `<header class="topbar">
            <a class="wordmark" href="${hrefFrame(S.persona, visibleHubs(S.persona)[0]?.id || 'today')}" aria-label="Oblivion Care home" style="text-decoration:none"><span class="eh-ring" aria-hidden="true"></span><b>blivion</b><span class="care">Care</span></a>
            <div class="topdate" aria-label="Monday 28 September 2026"><span class="full"><b>Monday</b> 28 September 2026</span><span class="short"><b>Mon</b> 28 Sep</span></div>
            <div class="cmd" role="search" aria-label="Search or jump to (outside this mockup)">${ic('search')}<span class="cmd-t">Search or jump to…</span><kbd>Ctrl K</kbd></div>
            <div class="top-actions">
                <button class="top-btn solid" type="button" data-act="toast-outside">${ic('alert-triangle')}<span class="tb-l">Report incident</span></button>
                ${clockedIn
                    ? `<button class="top-btn" type="button" data-act="toast" data-msg="Clock out is outside this mockup.">${ic('clock')}<span class="tb-l">Clocked in 7:02 am</span></button>`
                    : `<button class="top-btn warn" type="button" data-act="clock-in" data-fk="clock-in">${ic('log-in')}<span class="tb-l">Clock in</span></button>`}
                <button class="top-icon" type="button" aria-label="Messages, 2 unread" data-act="toast-outside">${ic('message')}<span class="cnt">2</span></button>
                <button class="top-icon" type="button" aria-label="Notifications, alerts waiting" data-act="toast-outside">${ic('bell')}<span class="dot"></span></button>
                <button class="avatar-top" type="button" aria-label="${esc(pr.name)} — account menu" data-act="toast-outside">${pr.initials}</button>
            </div>
        </header>`;
    }

    function medsCount() {
        if (S.scenario === 'unavailable') return { text: '?', cls: 'unknown', aria: 'count unavailable' };
        if (S.scenario === 'loading') return null;
        if (S.scenario === 'empty') return null;
        const late = Object.keys(MEDS).filter((k) => rowState(k) === 'late').length;
        return late ? { text: String(late), cls: '', aria: `${late} late` } : null;
    }

    function sidebar() {
        const p = S.persona;
        const onMed = S.mode === 'frame' || S.mode === 'person' || S.mode === 'record';
        // My Day and handover are other modules: Medication is not lit there.
        const cnt = medsCount();
        const cntHtml = cnt ? `<span class="sb-count ${cnt.cls}" aria-label="${cnt.aria}">${cnt.text}</span>` : '';
        let med;
        if (isFrontline(p)) {
            med = `<a class="sb-item" href="${hrefFrame(p, 'today', 'schedule')}" ${onMed ? 'aria-current="page"' : ''}>${ic('pill')}<span class="sb-label">Meds today</span>${cntHtml}</a>`;
        } else {
            const hubs = visibleHubs(p);
            med = `<button class="sb-group${onMed ? ' on-top' : ''}" type="button" aria-expanded="true">${ic('pill')}<span class="sb-label">Medication</span>${hubs.some((h) => h.id === 'today') ? cntHtml : ''}${ic('chev-down', 'chev s35')}</button>
                ${hubs.map((h) => `<a class="sb-sub" href="${hrefFrame(p, h.id)}" ${(S.mode === 'frame' && S.hub === h.id) || (S.mode === 'person' && h.id === 'mar') ? 'aria-current="page"' : ''}>${h.label}</a>`).join('')}`;
        }
        const mod = (icon, label, count) => `<button class="sb-group" type="button" aria-expanded="false" data-act="toast-outside">${ic(icon)}<span class="sb-label">${label}</span>${count ? `<span class="sb-count" aria-label="${count} alerts">${count}</span>` : ''}${ic('chev-right', 'chev s35')}</button>`;
        return `<nav class="sidebar" aria-label="Main">
            <a class="sb-item" href="#/myday/${p}" ${S.mode === 'myday' ? 'aria-current="page"' : ''}>${ic('dashboard')}<span class="sb-label">My Day</span></a>
            <a class="sb-item" href="#" data-act="toast-outside">${ic('calendar')}<span class="sb-label">My Calendar</span></a>
            <a class="sb-item" href="#" data-act="toast-outside">${ic('list-todo')}<span class="sb-label">All Tasks</span><span class="sb-count" aria-label="3 overdue">3</span></a>
            <div class="sb-divider" role="separator"></div>
            ${mod('building', 'Sites & Locations')}
            ${mod('activity', 'Operations')}
            ${mod('briefcase', 'Workforce')}
            ${med}
            ${mod('stethoscope', 'Health & Clinical')}
            ${mod('alert-triangle', 'Incidents', 1)}
            <div class="sb-bottom"><div class="sb-divider" role="separator"></div>
            <a class="sb-item" href="#" data-act="toast-outside">${ic('settings')}<span class="sb-label">Settings</span></a></div>
            <button class="sb-handle" type="button" data-act="collapse" aria-label="${isCollapsed() ? 'Expand sidebar' : 'Collapse sidebar'}">${ic(isCollapsed() ? 'chev-right' : 'chev-left', 's3')}</button>
        </nav>`;
    }
    const isCollapsed = () => (S.forceCollapse != null ? S.forceCollapse : window.innerWidth < 1000);

    function crumbs(items) {
        return `<nav class="crumbs" aria-label="Breadcrumb">${items.map((c, i) => (i < items.length - 1 ? `<a href="${c.href}">${esc(c.label)}</a>${ic('chev-right', 's3')}` : `<span aria-current="page">${esc(c.label)}</span>`)).join('')}</nav>`;
    }

    /* ───────────── Page header pieces ───────────── */
    function meter({ label, value, big, cap, tone, href, act, aria, bar, donut, skel }) {
        const t = tone && tone !== 'brand' ? ` eh-meter--${tone}` : '';
        let vis = '';
        if (donut) {
            const r = 14, c = 2 * Math.PI * r, off = c * (1 - donut.pct);
            vis = `<span class="eh-donut"><svg viewBox="0 0 34 34" aria-hidden="true"><circle class="trk" cx="17" cy="17" r="${r}" fill="none" stroke-width="4"/><circle class="arc" cx="17" cy="17" r="${r}" fill="none" stroke-width="4" stroke-dasharray="${c.toFixed(2)}" stroke-dashoffset="${off.toFixed(2)}" stroke-linecap="round"/></svg><span class="eh-meter-big">${donut.text}</span></span>`;
        } else if (bar != null) {
            vis = `<span class="eh-meter-bar" aria-hidden="true"><i style="width:${bar}%"></i></span>`;
        } else if (big != null) {
            vis = `<span class="eh-meter-big">${big}</span>`;
        }
        const tag = href ? `a href="${href}"` : `button type="button" data-act="${act}"`;
        const end = href ? 'a' : 'button';
        return `<${tag} class="eh-meter${t}${skel ? ' skel' : ''}" aria-label="${esc(aria || label)}">${ic('arrow-up-right', 's3 eh-meter-go')}<span class="eh-meter-head"><span class="eh-meter-label">${label}</span>${value != null ? `<span class="eh-meter-value">${value}</span>` : ''}</span>${vis}<span class="eh-meter-cap">${cap || ''}</span></${end}>`;
    }

    function railHtml(items, active, opts = {}) {
        const tabs = items.map((it) => {
            const on = it.id === active;
            const cnt = it.count != null ? `<span class="rail-count${it.alert ? ' alert' : ''}" aria-label="${it.countLabel || it.count}">${it.count}</span>` : '';
            return `<a class="rail-tab${on ? ' on' : ''}" data-rail-tab="${it.id}" href="${it.href}" ${on ? 'aria-current="page"' : ''}>${ic(it.icon, 's15')}<span>${esc(it.label)}</span>${cnt}</a>`;
        }).join('');
        return `<div class="rail" data-rail>${tabs}
            <button class="rail-pill rail-more" type="button" hidden data-act="rail-more" aria-haspopup="true" aria-expanded="false">${ic('more', 's15')}<span>More</span><span class="rail-count alert" hidden></span></button>
            <button class="rail-pill rail-find" type="button" data-act="find" aria-label="Find a view">${ic('search', 's15')}<span>Find</span></button>
        </div>`;
    }

    function pageHeader({ variant = 'index', icon, mark, title, chip, sub, sub2, actions, meters, filters, rail, back }) {
        return `<header class="eh-header">
            <div class="eh-inner"><div class="eh-top">
                <div class="eh-toprow">
                    <div class="eh-identity">
                        ${back ? `<a class="eh-back" href="${back.href}" aria-label="${esc(back.label)}">${ic('chev-left')}</a>` : ''}
                        <span class="eh-mark-ring">${mark || ic(icon, 's5')}</span>
                        <div style="min-width:0">
                            <div class="eh-titlerow"><h1 class="eh-title">${esc(title)}</h1>${chip || ''}</div>
                            <p class="eh-sub">${sub}</p>${sub2 ? `<p class="eh-sub" style="margin-top:1px">${sub2}</p>` : ''}
                        </div>
                    </div>
                    <div class="eh-actions">${actions || ''}</div>
                </div>
                <div class="eh-meters">${meters || ''}</div>
                <div class="eh-filters">${filters || ''}</div>
            </div>
            <div class="eh-railrow${rail ? '' : ' none'}">${rail || ''}</div></div>
        </header>`;
    }
    const chipBadge = (v, text, icon) => `<span class="badge b-${v} chip8">${icon ? ic(icon, 's3') : ''}${text}</span>`;
    const searchBox = (ph, id) => `<div class="eh-search">${ic('search')}<label class="sr-only" for="${id}">${esc(ph)}</label><input id="${id}" type="search" placeholder="${esc(ph)}"><kbd aria-hidden="true">/</kbd></div>`;

    /* ───────────── row state (runtime overrides for the clickable flow) ───────────── */
    function rowState(k) {
        if (S.rows[k]) return S.rows[k].state;
        if (k === 'r8' && S.scenario === 'uncertain') return 'uncertain';
        return MEDS[k].state;
    }
    function rowLine(k) {
        if (S.rows[k]) return S.rows[k].line;
        if (k === 'r8' && S.scenario === 'uncertain') return 'Sent 9:04 am · no confirmation — check the chart before trying again';
        return MEDS[k].line;
    }
    function rowBlock(k) {
        if (S.scenario === 'notClockedIn') return 'notClockedIn';
        if (k === 'r3') { const m = S.safety.profileAllergy; return m === 'block' ? 'safetyAllergyProfile' : m === 'confirm' && !S.orderConfirmed ? 'allergyNotConfirmed' : null; }
        return MEDS[k].block || null;
    }
    const allergyWarnRow = (k) => k === 'r3' && (S.safety.profileAllergy === 'warn' || (S.safety.profileAllergy === 'confirm' && S.orderConfirmed));

    /* Competency state for the signed-in worker, from the scenario (applies to “given” on Administer medicines). */
    function compState() {
        return { competencyExpired: 'expired', restrictedBlock: 'restrictedBlock', restrictedCosigner: 'restrictedCosigner' }[S.scenario] || null;
    }
    const COMP_REASON = { expired: 'Your competency expired on 14 September 2026', restrictedBlock: 'You can’t sign doses as given — competency restricted', restrictedCosigner: 'A colleague needs to give this dose' };

    /* Row actions (LIST_STYLE_GUIDE §1): every row carries the ⋯ button AND the right-click menu,
     * both fed by the SAME item list, also reachable with Shift+F10 or the context-menu key.
     * Blocked actions stay listed but disabled, with the reason under them. */
    function menuItems(kind, id, persona) {
        const p = persona || S.persona;
        const items = [];
        if (kind === 'dose') {
            const r = MEDS[id], pp = PEOPLE[r.pid];
            const st = rowState(id);
            const block = ['due', 'late', 'notdue'].includes(st) ? rowBlock(id) : null;
            const openable = ['due', 'late'].includes(st);
            const canRecord = has(p, 'administer');
            const cs = r.support === 'administer' ? compState() : null;
            const expired = cs === 'expired' || cs === 'restrictedBlock';
            if (canRecord && openable) {
                if (block) {
                    items.push({ label: 'Record as given', icon: 'check', disabled: true, reason: tpl(BLOCKS[block].title, { p: pp.pref, med: r.med }) });
                    if (BLOCKS[block].still && BLOCKS[block].still !== 'none') items.push({ label: 'Record not given', icon: 'pause', act: 'record', data: { row: id } });
                    items.push({ label: 'Why can’t I record this?', icon: 'help', act: 'why', data: { row: id } });
                } else if (expired) {
                    items.push({ label: 'Record as given', icon: 'check', disabled: true, reason: COMP_REASON[cs] });
                    items.push({ label: 'Record not given', icon: 'pause', act: 'record', data: { row: id } });
                } else {
                    items.push({ label: 'Record dose', icon: 'check', act: 'record', data: { row: id } });
                }
            } else if (st === 'rejected' && canRecord) {
                items.push({ label: 'Review what happened', icon: 'x-circle', act: 'rejected', data: { row: id } });
            } else if (st === 'uncertain' && canRecord) {
                items.push({ label: 'Check the chart', icon: 'search', act: 'go', href: `#/person/${p}/${pp.id}/chart` });
            } else if (!['notdue', 'selfmanaged'].includes(st)) {
                items.push({ label: 'View dose details', icon: 'info', act: 'detail', data: { row: id } });
            }
            if (items.length) items.push({ sep: true });
            items.push({ label: `Open ${pp.pref}’s medication record`, icon: 'clipboard-list', act: 'go', href: `#/person/${p}/${pp.id}/chart` });
            items.push({ label: 'Report a medication error', icon: 'flag', act: 'toast-outside' });
            if (canRecord) items.push({ label: 'Add to shift handover', icon: 'repeat', act: 'toast-outside' });
        } else if (kind === 'fu') {
            const fu = FU[id];
            if (id === 'refusal') items.push({ label: 'Record re-offer', icon: 'repeat', act: 'fu-reoffer' });
            else if (id !== 'late') items.push({ label: 'Record result', icon: 'check', act: 'fu-record' });
            else items.push({ label: 'View follow-up', icon: 'info', act: 'toast', msg: 'Follow-up detail is designed in P08a.' });
            items.push({ sep: true });
            items.push({ label: fu.owner ? 'Reassign' : 'Assign', icon: 'user', act: 'toast', msg: 'Assign and reassign are designed in P08a.' });
            items.push({ label: 'Escalate', icon: 'bell', act: 'toast', msg: 'Escalation is designed in P08a (contacts: D12).' });
            items.push({ label: 'Open the source dose', icon: 'pill', act: 'toast', msg: 'Opens the dose this follow-up came from (P08a).' });
        } else if (kind === 'person') {
            const pp = PEOPLE[id];
            items.push({ label: `Open ${pp.pref}’s medication record`, icon: 'clipboard-list', act: 'go', href: `#/person/${p}/${pp.id}/chart` });
            items.push({ label: 'Print MAR', icon: 'printer', act: 'toast-outside' });
            items.push({ sep: true });
            items.push({ label: 'Report a medication error', icon: 'flag', act: 'toast-outside' });
        } else if (kind === 'medicine') {
            items.push({ label: 'View medicine details', icon: 'info', act: 'toast', msg: 'Medicine detail is designed in P02.' });
            items.push({ label: 'Report a medication error', icon: 'flag', act: 'toast-outside' });
        }
        return items;
    }
    function menuHtml(items, fk) {
        return items.map((it) => {
            if (it.sep) return '<div class="ctx-sep" role="separator"></div>';
            const data = Object.entries(it.data || {}).map(([k2, v]) => ` data-${k2}="${esc(v)}"`).join('');
            const attrs = it.disabled ? 'aria-disabled="true"' : `data-act="${it.act === 'go' ? 'menu-go' : it.act}"${it.href ? ` data-href="${it.href}"` : ''}${it.msg ? ` data-msg="${esc(it.msg)}"` : ''}${data}`;
            return `<button type="button" role="menuitem" class="ctx-item" ${attrs} data-fk="${fk}">${ic(it.icon, 's35')}<span><span class="ctx-l">${esc(it.label)}</span>${it.disabled ? `<span class="ctx-r">${ic('lock', 's3')} ${esc(it.reason)}</span>` : ''}</span></button>`;
        }).join('');
    }
    const kebab = (kind, id, label) => `<button class="kebab" type="button" data-act="menu" data-menu="${kind}:${id}" data-fk="keb-${kind}-${id}" aria-haspopup="menu" aria-expanded="false" aria-label="Actions for ${esc(label)}">${ic('more')}</button>`;

    function doseRow(k, persona) {
        const r = MEDS[k], pp = PEOPLE[r.pid];
        const st = rowState(k);
        const block = ['due', 'late', 'notdue'].includes(st) ? rowBlock(k) : null;
        const openable = ['due', 'late'].includes(st);
        const blockLine = block ? `<span class="state-line" style="color:${BLOCKS[block].safety ? 'var(--status-critical)' : 'var(--status-warning)'};font-weight:600">${ic('lock', 's3')} ${esc(tpl(BLOCKS[block].title, { p: pp.pref, med: r.med }))}</span>` : '';
        let actions = '';
        const canRecord = has(persona, 'administer');
        if (st === 'selfmanaged' || st === 'notdue') actions = '';
        else if (!canRecord && (openable || st === 'rejected')) actions = '';
        else if (block && openable) {
            actions = `<button class="btn btn-outline btn-sm frontline-tap" type="button" data-act="why" data-row="${k}" data-fk="why-${k}">${ic('help')}Why can’t I record this?</button>`;
            const allowNotGiven = BLOCKS[block].still && BLOCKS[block].still !== 'none';
            if (allowNotGiven) actions += `<button class="btn btn-outline btn-sm frontline-tap" type="button" data-act="record" data-row="${k}" data-fk="rec-${k}">Record not given</button>`;
        } else if (openable) {
            actions = `<button class="btn btn-primary btn-sm frontline-tap" type="button" data-act="record" data-row="${k}" data-fk="rec-${k}">Record</button>`;
        } else if (st === 'rejected') {
            actions = `<button class="btn btn-outline btn-sm frontline-tap" type="button" data-act="rejected" data-row="${k}" data-fk="rej-${k}">Review</button>`;
        } else {
            actions = `<button class="btn btn-ghost btn-sm frontline-tap" type="button" data-act="detail" data-row="${k}" data-fk="det-${k}">View</button>`;
        }
        const cs = !block && openable && r.support === 'administer' && has(persona, 'administer') ? compState() : null;
        const compLine = cs === 'expired' ? `<span class="state-line" style="color:var(--status-critical);font-weight:600">${ic('lock', 's3')} Can’t record as given — your competency expired. Refusal, withhold or absence still OK.</span>`
            : cs === 'restrictedBlock' ? `<span class="state-line" style="color:var(--status-critical);font-weight:600">${ic('lock', 's3')} Can’t sign as given — your competency is restricted. Refusal, withhold or absence still OK.</span>`
                : cs === 'restrictedCosigner' ? `<span class="state-line" style="color:var(--status-warning);font-weight:600">${ic('users', 's3')} A co-signer must confirm if you record it as given.</span>` : '';
        const allergyLine = !block && allergyWarnRow(k) && ['due', 'late'].includes(st) ? (S.safety.profileAllergy === 'warn' ? `<span class="state-line" style="color:var(--status-warning);font-weight:600">${ic('alert-triangle', 's3')} Possible allergy match — check before giving</span>` : `<span class="state-line" style="font-weight:600">${ic('check-circle', 's3')} Prescriber confirmed the allergy on the order</span>`) : '';
        const extra = r.fu && st === 'refused' ? `<span class="state-line">${ic('flag', 's3')} ${esc(r.fu)}</span>` : '';
        return `<div class="dose-row" data-row-id="${k}" data-menu="dose:${k}">
            <div class="who"><span class="disc" aria-hidden="true">${pp.initials}</span><div style="min-width:0"><a class="who-name name-link" href="#/person/${persona}/${pp.id}/chart">${esc(pp.pref)}</a><div class="who-sub">${esc(pp.surname)}</div></div></div>
            <div style="min-width:0"><div class="med-name">${esc(r.med)} <span style="font-weight:500;color:var(--muted-foreground)">${esc(r.str)}</span></div>
                <div class="med-sub">${supportChip(r.support)}${r.cd ? `<span class="chipn">${ic('shield', 's3')}Controlled</span>` : ''}<span>${esc(r.dose)} · ${esc(r.ins)}</span></div></div>
            <div class="state-cell">${dbadge(st)}<span class="state-line">${esc(rowLine(k))}</span>${blockLine}${allergyLine}${compLine}${extra}</div>
            <div class="row-actions">${actions}${kebab('dose', k, `${pp.pref}, ${r.med}`)}</div>
        </div>`;
    }

    /* ───────────── Meds today views ───────────── */
    function medsTodayHeader(view) {
        const p = S.persona, scn = S.scenario;
        const hub = hubById('today');
        const views = visibleViews(p, hub);
        const lateN = Object.keys(MEDS).filter((k) => rowState(k) === 'late').length;
        const dueN = Object.keys(MEDS).filter((k) => rowState(k) === 'due').length;
        const counts = { schedule: { count: lateN + dueN, alert: lateN > 0, label: `${lateN + dueN} due or late` }, followups: { count: 3, alert: true, label: '3 open, 1 overdue' }, controlled: { count: 1 }, stockalerts: { count: 1 } };
        const unavailable = scn === 'unavailable', loading = scn === 'loading', empty = scn === 'empty';
        const items = views.map((v) => ({ id: v.id, label: v.label, icon: v.icon, href: hrefFrame(p, 'today', v.id), ...(unavailable || loading || empty ? {} : counts[v.id] ? { count: counts[v.id].count, alert: counts[v.id].alert, countLabel: counts[v.id].label } : {}) }));
        const expired = scn === 'competencyExpired';
        let meters;
        if (loading) {
            meters = ['Due now', 'Late', 'Needs help', 'Recorded', 'Follow-ups', 'My eligibility'].map((l) => meter({ label: l, big: '00', cap: 'Loading', act: 'noop', skel: true })).join('');
        } else if (unavailable) {
            meters = ['Due now', 'Late', 'Needs help', 'Recorded', 'Follow-ups'].map((l) => meter({ label: l, big: '—', cap: 'Unavailable', act: 'noop', aria: `${l}: unavailable` })).join('') + eligibilityMeter(expired);
        } else if (empty) {
            meters = meter({ label: 'Due now', big: '0', cap: 'Next: none today', href: hrefFrame(p, 'today', 'schedule') })
                + meter({ label: 'Late', big: '0', cap: 'Nothing late', href: hrefFrame(p, 'today', 'schedule') })
                + meter({ label: 'Needs help', big: '0', cap: 'Nothing blocked', href: hrefFrame(p, 'today', 'schedule') })
                + meter({ label: 'Recorded', big: 'n/a', cap: 'No doses were due on your shift', href: hrefFrame(p, 'today', 'activity'), aria: 'Recorded: not applicable, no doses were due' })
                + meter({ label: 'Follow-ups', big: '0', cap: 'None open', href: hrefFrame(p, 'today', 'followups') })
                + eligibilityMeter(expired);
        } else {
            const recorded = Object.keys(MEDS).filter((k) => ['given', 'refused', 'assisted', 'prompted', 'withheld', 'away', 'reoffered'].includes(rowState(k))).length;
            const denom = Object.keys(MEDS).filter((k) => MEDS[k].slot !== '12:00 pm' && rowState(k) !== 'selfmanaged').length;
            meters = meter({ label: 'Due now', big: String(dueN), cap: dueN ? 'Aroha, Mele · 9:00 am' : 'Next: 12:00 pm', href: hrefFrame(p, 'today', 'schedule'), aria: `View ${dueN} doses due now` })
                + meter({ label: 'Late', big: String(lateN), cap: lateN ? 'Oldest due 8:00 am' : 'Nothing late', tone: lateN ? 'warning' : undefined, href: hrefFrame(p, 'today', 'schedule'), aria: `View ${lateN} late doses` })
                + (() => { const bl = Object.keys(MEDS).filter((k) => ['due', 'late'].includes(rowState(k)) && rowBlock(k)); const safety = bl.some((k) => BLOCKS[rowBlock(k)].safety); const cap = bl.map((k) => ({ safetyAllergyProfile: 'allergy match', allergyNotConfirmed: 'allergy not confirmed', awaitingVerification: 'order to check', notClockedIn: 'not clocked in' })[rowBlock(k)] || 'blocked').filter((v, i, arr) => arr.indexOf(v) === i).join(' · '); return meter({ label: 'Needs help', big: String(bl.length), cap: bl.length ? cap.charAt(0).toUpperCase() + cap.slice(1) : 'Nothing blocked', tone: bl.length ? (safety ? 'critical' : 'warning') : undefined, href: hrefFrame(p, 'today', 'schedule'), aria: `View ${bl.length} doses you can’t record` }); })()
                + meter({ label: 'Recorded', value: `${recorded} of ${denom}`, donut: { pct: recorded / denom, text: `${Math.round((recorded / denom) * 100)}%` }, cap: 'Due so far on your shift', href: hrefFrame(p, 'today', 'activity'), aria: `View activity, ${recorded} of ${denom} recorded` })
                + meter({ label: 'Follow-ups', value: '3', big: '1 overdue', cap: 'Oldest due 11:30 pm Sunday', tone: 'critical', href: hrefFrame(p, 'today', 'followups'), aria: 'View follow-ups, 1 overdue' })
                + eligibilityMeter(expired);
        }
        const chip = scn === 'notClockedIn' ? chipBadge('warning', 'Not clocked in', 'log-in') : chipBadge('success', 'On shift', 'check');
        const filterFor = {
            schedule: `<button type="button" class="fchip" data-act="toast" data-msg="Filter menus are designed with each page package (mockup).">${ic('home', 's3')}Kōwhai House${ic('chev-down', 's3')}</button><span class="fchip static">Your shift · 7:00 am–3:00 pm</span><button type="button" class="fchip" data-act="toast" data-msg="Filter menus are designed with each page package (mockup).">All states${ic('chev-down', 's3')}</button><span class="fseg" role="radiogroup" aria-label="Group by"><button type="button" role="radio" aria-checked="true">By time</button><button type="button" role="radio" aria-checked="false">By person</button></span>`,
            rounds: `<button type="button" class="fchip" data-act="toast" data-msg="Filter menus are designed with each page package (mockup).">${ic('home', 's3')}Kōwhai House${ic('chev-down', 's3')}</button><button type="button" class="fchip" data-act="toast" data-msg="Filter menus are designed with each page package (mockup).">Today${ic('chev-down', 's3')}</button><button type="button" class="fchip" data-act="toast" data-msg="Filter menus are designed with each page package (mockup).">All rounds${ic('chev-down', 's3')}</button>`,
            asneeded: `<button type="button" class="fchip" data-act="toast" data-msg="Filter menus are designed with each page package (mockup).">${ic('home', 's3')}Kōwhai House${ic('chev-down', 's3')}</button><button type="button" class="fchip" data-act="toast" data-msg="Filter menus are designed with each page package (mockup).">Today${ic('chev-down', 's3')}</button><button type="button" class="fchip" data-act="toast" data-msg="Filter menus are designed with each page package (mockup).">All people${ic('chev-down', 's3')}</button>`,
            followups: `<button type="button" class="fchip" data-act="toast" data-msg="Filter menus are designed with each page package (mockup).">Mine${ic('chev-down', 's3')}</button><button type="button" class="fchip" data-act="toast" data-msg="Filter menus are designed with each page package (mockup).">Open${ic('chev-down', 's3')}</button><button type="button" class="fchip" data-act="toast" data-msg="Filter menus are designed with each page package (mockup).">All types${ic('chev-down', 's3')}</button>`,
            controlled: `<button type="button" class="fchip" data-act="toast" data-msg="Filter menus are designed with each page package (mockup).">${ic('home', 's3')}Kōwhai House${ic('chev-down', 's3')}</button><button type="button" class="fchip" data-act="toast" data-msg="Filter menus are designed with each page package (mockup).">Due and requested${ic('chev-down', 's3')}</button>`,
            stockalerts: `<button type="button" class="fchip" data-act="toast" data-msg="Filter menus are designed with each page package (mockup).">${ic('home', 's3')}Kōwhai House${ic('chev-down', 's3')}</button><button type="button" class="fchip" data-act="toast" data-msg="Filter menus are designed with each page package (mockup).">All alerts${ic('chev-down', 's3')}</button>`,
            activity: `<button type="button" class="fchip" data-act="toast" data-msg="Filter menus are designed with each page package (mockup).">${ic('home', 's3')}Kōwhai House${ic('chev-down', 's3')}</button><button type="button" class="fchip" data-act="toast" data-msg="Filter menus are designed with each page package (mockup).">Today${ic('chev-down', 's3')}</button><button type="button" class="fchip" data-act="toast" data-msg="Filter menus are designed with each page package (mockup).">All outcomes${ic('chev-down', 's3')}</button>`,
        };
        const updated = scn === 'stale' ? `<button class="fchip" type="button" data-act="toast" data-msg="Refreshed (mockup)." style="border-color: color-mix(in oklch, var(--status-warning) 60%, white)">${ic('alert-triangle', 's3')}Not updated since 8:40 am NZDT</button>` : scn === 'offline' ? `<span class="fchip static">${ic('wifi-off', 's3')}Offline · last updated 9:05 am NZDT</span>` : `<button class="fchip" type="button" data-act="toast" data-msg="Refreshed at 9:12 am NZDT (mockup).">${ic('refresh', 's3')}Updated ${NOW} NZDT</button>`;
        return pageHeader({
            icon: 'pill', title: 'Meds today', chip,
            sub: `Kōwhai House · shift 7:00 am–3:00 pm · times in <abbr title="Pacific/Auckland" style="text-decoration:none">NZDT</abbr><span class="tz-long"> (Pacific/Auckland)</span>`,
            actions: searchBox('Search people or medicines…', 'hs') + `<a class="glass-btn" href="#/handover/${p}" aria-label="Shift handover — medication" title="Shift handover — medication" style="width:36px;padding:0;justify-content:center">${ic('repeat')}</a><button class="glass-btn" type="button" data-act="toast-outside" aria-label="Report a medication error" title="Report a medication error" style="width:36px;padding:0;justify-content:center">${ic('flag')}</button>${has(p, 'administer') ? `<button class="white-btn" type="button" data-act="prn" data-fk="prn">${ic('plus')}Record as-needed dose</button>` : ''}`,
            meters, filters: (filterFor[view] || '') + updated, rail: railHtml(items, view),
        });
    }
    function eligibilityMeter(expired) {
        if (!has(S.persona, 'administer')) return meter({ label: 'My eligibility', big: 'Not assessed', cap: 'You don’t record doses in this role', href: undefined, act: 'eligibility', aria: 'View my eligibility' });
        if (S.scenario === 'restrictedBlock') return meter({ label: 'My eligibility', big: 'Restricted', cap: 'Can’t sign doses as given', tone: 'critical', act: 'eligibility', aria: 'View my eligibility: restricted' });
        if (S.scenario === 'restrictedCosigner') return meter({ label: 'My eligibility', big: 'Restricted', cap: 'Co-signer needed for given', tone: 'warning', act: 'eligibility', aria: 'View my eligibility: restricted, co-signer needed' });
        return expired
            ? meter({ label: 'My eligibility', big: 'Expired', cap: 'Competency ended 14 Sep 2026', tone: 'critical', act: 'eligibility', aria: 'View my eligibility: competency expired' })
            : meter({ label: 'My eligibility', big: 'Current', cap: 'To 14 Mar 2027 · can witness', tone: 'success', act: 'eligibility', aria: 'View my eligibility: current' });
    }

    function scheduleBody() {
        const p = S.persona, scn = S.scenario;
        const out = [];
        if (scn === 'notClockedIn') {
            out.push(`<div class="banner warning" role="status"><span class="b-ico">${ic('log-in')}</span><div class="b-body"><div class="b-title">You’re not clocked in</div><div class="b-text">You can read today’s medicines, but you can’t record anything until you clock in on a shift that includes these people.</div><div class="b-actions"><button class="btn btn-primary btn-sm frontline-tap" type="button" data-act="clock-in" data-fk="clock-in-b">${ic('log-in')}Clock in</button><span class="text-caption" style="align-self:center">Can’t clock in? Coordinator on call: ${NC()}</span></div></div></div>`);
        }
        if (scn === 'offline') {
            out.push(`<div class="banner warning" role="status"><span class="b-ico">${ic('wifi-off')}</span><div class="b-body"><div class="b-title">You’re offline</div><div class="b-text">Records you make now are saved on this device only and sent when you reconnect. Other staff can’t see them yet. Offline recording depends on ${dtag('D7')} (offline expectation still open).</div><div class="b-actions"><button class="btn btn-outline btn-sm" type="button" data-act="toast-outside">${ic('printer')}Open print pack</button></div></div></div>`);
        }
        if (scn === 'stale') {
            out.push(`<div class="banner warning" role="status"><span class="b-ico">${ic('alert-triangle')}</span><div class="b-body"><div class="b-title">Not updated since 8:40 am NZDT (32 min ago)</div><div class="b-text">We couldn’t refresh. What you see may be out of date — someone may have recorded a dose since.</div><div class="b-actions"><button class="btn btn-outline btn-sm" type="button" data-act="toast" data-msg="Refreshed at 9:12 am NZDT (mockup).">${ic('refresh')}Refresh now</button><button class="btn btn-ghost btn-sm" type="button" data-act="toast-outside">${ic('printer')}Open print pack</button></div></div></div>`);
        }
        if (scn === 'loading') {
            out.push(`<div class="card" aria-busy="true" aria-label="Loading today’s doses"><div class="slot-head"><span class="skel-line" style="width:70px"></span></div>${[1, 2, 3, 4].map(() => `<div class="dose-row"><div class="who"><span class="disc muted"></span><div style="flex:1"><span class="skel-line" style="width:90px"></span></div></div><span class="skel-line" style="width:80%"></span><span class="skel-line" style="width:60%"></span><span class="skel-line" style="width:70px"></span></div>`).join('')}<span class="sr-only">Loading today’s doses…</span></div>`);
            return out.join('');
        }
        if (scn === 'unavailable') {
            out.push(`<div class="card"><div class="empty error" role="alert"><span class="e-ico">${ic('alert-triangle', 's6')}</span><h3>Couldn’t load today’s doses</h3><p>Some doses may already be recorded. Don’t give anything from memory — try again, or use the printed MAR.</p><div class="e-act"><button class="btn btn-outline" type="button" data-act="toast" data-msg="Still unavailable (mockup).">${ic('refresh')}Try again</button><button class="btn btn-ghost" type="button" data-act="toast-outside">${ic('printer')}Open print pack</button></div></div></div>`);
            return out.join('');
        }
        if (scn === 'empty') {
            out.push(`<div class="card"><div class="empty"><span class="e-ico">${ic('check-circle', 's6')}</span><h3>Nothing left to record on your shift</h3><p>Everything due so far is recorded, and no one on your shift has more medicines due today. Updated ${NOW} NZDT.</p></div></div>`);
            return out.join('');
        }
        // Private "not recorded — action needed" list (EM-26)
        const cdVisible = has(p, 'cd.view');
        const slots = {};
        Object.keys(MEDS).forEach((k) => { if (MEDS[k].cd && !cdVisible) return; (slots[MEDS[k].slot] = slots[MEDS[k].slot] || []).push(k); });
        const body = Object.entries(slots).map(([slot, ks]) => `<div class="slot-head" role="heading" aria-level="3">${ic('clock', 's3')}${slot}<span style="font-weight:500">· ${ks.length} ${ks.length === 1 ? 'medicine' : 'medicines'}</span></div>${ks.map((k) => doseRow(k, p)).join('')}`).join('');
        out.push(`<section class="card" aria-label="Today’s doses" style="overflow:hidden">${body}</section>`);
        out.push(`<p class="text-caption" style="margin:0">${cdVisible ? '' : 'Showing medicines your role can see. '}Times in NZDT. Self-managed medicines are listed for information and never count as late or missed.</p>`);
        return out.join('');
    }

    function followUpRow(f, key) {
        key = key || Object.keys(FU).find((x) => FU[x] === f);
        return `<div class="fu-row" data-menu="fu:${key}">
            <div><div class="fu-title">${esc(f.title)}</div><div class="fu-src">${f.src}</div></div>
            <div class="owner">${f.owner ? `<span class="disc sm" aria-hidden="true">${f.oi}</span><div><div>${esc(f.owner)}</div>${f.osub ? `<div class="o-sub">${f.osub}</div>` : ''}</div>` : `<span class="disc sm muted" aria-hidden="true">${ic('user', 's3')}</span><div><div style="color:var(--status-critical);font-weight:600">No owner</div><div class="o-sub">Assign someone</div></div>`}</div>
            <div class="state-cell">${f.badge}<span class="state-line">${f.line}</span>${f.line2 ? `<span class="state-line">${f.line2}</span>` : ''}</div>
            <div class="row-actions">${f.actions || ''}${kebab('fu', key, f.title)}</div>
        </div>`;
    }
    const FU = {
        due: { title: 'Check whether paracetamol helped — Aroha', src: 'As-needed dose given 8:05 am · Kōwhai House', owner: 'Priya Shah', oi: 'PS', badge: `<span class="badge b-info">${ic('clock')}Due 9:35 am</span>`, line: 'Due in 23 min · time entered by Priya S.', actions: `<button class="btn btn-primary btn-sm frontline-tap" type="button" data-act="fu-record" data-fk="fu-1">Record result</button>` },
        overdue: { title: 'Check whether ibuprofen helped — Tama', src: 'As-needed dose given 11:00 pm Sunday · night shift', owner: 'Priya Shah', oi: 'PS', osub: 'From Mere Kahu at the 7:00 am handover', badge: `<span class="badge b-critical">${ic('alert-triangle')}Overdue</span>`, line: 'Was due 11:30 pm Sunday (9 h 42 min ago)', line2: 'Carried over from the night shift', actions: `<button class="btn btn-primary btn-sm frontline-tap" type="button" data-act="fu-record" data-fk="fu-2">Record result</button>` },
        refusal: { title: 'Follow up Grace’s refusal of sertraline', src: 'Refused 8:10 am · Kōwhai House', owner: 'Priya Shah', oi: 'PS', badge: `<span class="badge b-info">${ic('clock')}Due 12:00 pm</span>`, line: 'Offer again, or record why not · re-offer rule: ' + NC(), actions: `<button class="btn btn-outline btn-sm frontline-tap" type="button" data-act="fu-reoffer" data-fk="fu-3">Record re-offer</button>` },
        escalated: { title: 'Check whether paracetamol helped — Mele', src: 'As-needed dose given 7:10 am · recorded as “not helped”', owner: 'Jordan Tipene', oi: 'JT', osub: 'Escalated by Priya S.', badge: `<span class="badge b-critical">${ic('bell')}Escalated — not acknowledged</span>`, line: 'Escalated to Jordan T. at 8:40 am · no acknowledgement after 32 min', line2: 'Next contact: ' + NC(), actions: `<button class="btn btn-outline btn-sm" type="button" data-act="toast" data-msg="Escalated again (mockup).">Escalate again</button>` },
        noowner: { title: 'Follow up missed-dose question — Tama', src: 'Handover item from Sunday evening shift', owner: null, badge: `<span class="badge b-critical">${ic('user')}No owner</span>`, line: 'Was due 8:00 am', actions: `<button class="btn btn-primary btn-sm" type="button" data-act="toast" data-msg="Assigned to Jordan Tipene (mockup).">Assign</button>` },
        late: { title: 'Check whether paracetamol helped — Grace', src: 'As-needed dose given 6:15 am', owner: 'Mere Kahu', oi: 'MK', badge: `<span class="badge b-warning">${ic('check')}Completed late</span>`, line: 'Completed 7:40 am · due 6:45 am (55 min late)', line2: 'Result: Helped', actions: `<button class="btn btn-ghost btn-sm" type="button" data-act="toast" data-msg="Detail is designed in P08a.">View</button>` },
        unable: { title: 'Check whether paracetamol helped — Sam', src: 'As-needed dose given 8:30 am', owner: 'Priya Shah', oi: 'PS', badge: `<span class="badge b-neutral">${ic('moon')}Unable to assess</span>`, line: 'Sam was asleep at 9:00 am · check again due 10:00 am', actions: `<button class="btn btn-outline btn-sm" type="button" data-act="fu-record" data-fk="fu-4">Record result</button>` },
    };

    function followUpsBody(oversight) {
        const rows = oversight ? ['overdue', 'escalated', 'noowner', 'due', 'refusal', 'unable', 'late'] : ['overdue', 'due', 'refusal', 'unable'];
        return `<section class="card" style="overflow:hidden" aria-label="Follow-ups"><div class="slot-head" role="heading" aria-level="3">${oversight ? 'All open follow-ups · Kōwhai House and Rimu House' : 'Your follow-ups'}<span style="font-weight:500">· ${rows.length}</span></div>${rows.map((r) => followUpRow(FU[r])).join('')}</section>
        <p class="text-caption" style="margin:0">Follow-ups stay open across midnight and shift changes until someone records a result. A delivered notification isn’t an acknowledgement, and an acknowledgement isn’t a result.</p>`;
    }

    function annotCard(hub, view) {
        const v = hub.views.find((x) => x.id === view);
        return `<div class="annot"><div class="a-tag">${ic('info', 's3')}Design note — frame only</div>
            <div><b>${esc(hub.label)} › ${esc(v.label)}</b> keeps its current URL <code>${esc(v.url)}</code> and server gate (<code>${esc(v.gate)}</code>). This view’s content is designed in <b>${v.pkg}</b>, using the P00 states in the catalogue.</div>
            <ul><li>Navigation is discovery only: hiding this tab is never the security boundary.</li><li>Rows open the canonical person record <code>/emar/mar?client_id=…</code>.</li>${hub.note ? `<li>${esc(hub.note)}</li>` : ''}</ul>
            <div style="margin-top:8px;display:flex;gap:8px;flex-wrap:wrap"><a class="btn btn-outline btn-sm" href="#/catalogue/reuse">See which states ${v.pkg} reuses</a></div></div>`;
    }

    function marChartsBody() {
        const p = S.persona;
        const rows = [
            { pid: 'aroha', rec: '3 of 5', nr: 0, al: 'recorded' },
            { pid: 'tama', rec: '1 of 3', nr: 1, al: 'none' },
            { pid: 'mele', rec: '0 of 2', nr: 0, al: 'recorded-pen' },
            { pid: 'grace', rec: '1 of 1', nr: 0, al: 'unavailable' },
            { pid: 'ben', rec: '2 of 2', nr: 0, al: 'none' },
        ];
        const alChip = (a) => a.startsWith('recorded') ? `<span class="badge b-critical sm">${ic('alert-octagon')}Allergies recorded</span>` : a === 'none' ? `<span class="badge b-warning sm">${ic('alert-triangle')}No allergies recorded</span>` : a === 'nkda' ? `<span class="badge b-neutral sm">${ic('check-circle')}No known drug allergies</span>` : `<span class="badge b-critical sm">${ic('alert-circle')}Allergies unavailable</span>`;
        return `<section class="card" style="overflow:hidden"><div class="tbl-wrap"><table class="etable"><caption class="sr-only">People and today’s doses</caption><thead><tr><th scope="col">Person</th><th scope="col">House</th><th scope="col">Recorded today</th><th scope="col">Not yet recorded</th><th scope="col">Allergy status</th><th scope="col"><span class="sr-only">Actions</span></th></tr></thead><tbody>
            ${rows.map((r) => { const pp = PEOPLE[r.pid]; return `<tr data-menu="person:${pp.id}"><td><div class="who"><span class="disc sm" aria-hidden="true">${pp.initials}</span><div><div style="font-weight:600">${esc(pp.pref)} ${esc(pp.surname)}</div><div class="who-sub">${esc(pp.legal)}</div></div></div></td><td>${esc(pp.house)}</td><td class="tabular">${r.rec}</td><td>${r.nr ? `<span class="cnt-pill b-critical" style="border:0">${r.nr}</span>` : '<span style="color:var(--muted-foreground)">—</span>'}</td><td>${alChip(r.al)}</td><td style="text-align:right;white-space:nowrap"><a class="btn btn-ghost btn-sm" href="#/person/${p}/${pp.id}/chart">Open medication record ${ic('arrow-right', 's3')}</a>${kebab('person', pp.id, `${pp.pref} ${pp.surname}`)}</td></tr>`; }).join('')}
        </tbody></table></div></section>
        <p class="text-caption" style="margin:0">${has(p, 'cd.view') ? '' : 'Showing medicines your role can see. Totals exclude medicines your role can’t see. '}“Not yet recorded” means no outcome is on the chart — never assume it was missed.</p>`;
    }

    function controlledBody() {
        return `<section class="card card-pad"><div class="cap-row"><h3>Witness requests</h3><span class="text-caption">1 waiting</span></div>
            <div class="fu-row" style="padding:10px 0 0;border:0"><div><div class="fu-title">Witness methylphenidate for Aroha</div><div class="fu-src">Requested by Daniel Ahn · for the 12:00 pm dose</div></div><div class="owner"><span class="disc sm" aria-hidden="true">PS</span><div>You</div></div><div class="state-cell"><span class="badge b-info">${ic('clock')}Due 12:00 pm</span><span class="state-line">You meet all three witness checks</span></div><div class="row-actions"><button class="btn btn-outline btn-sm" type="button" data-act="toast" data-msg="The witness dialog is designed in P07a.">Open</button></div></div></section>
            <section class="card card-pad"><div class="cap-row"><h3>Balance checks</h3></div><p style="margin:0">How often controlled medicines are counted at Kōwhai House: ${NC()} ${dtag('D8')}</p><p class="text-caption" style="margin:6px 0 0">No count is shown as due or overdue until the organisation sets the count cadence. Counts can still be recorded from the controlled register.</p></section>`;
    }

    function asNeededBody() {
        return `<section class="card" style="overflow:hidden"><div class="slot-head" role="heading" aria-level="3">As-needed today · Kōwhai House</div>
            <div class="dose-row"><div class="who"><span class="disc" aria-hidden="true">AN</span><div><div class="who-name">Aroha</div><div class="who-sub">Ngata</div></div></div><div><div class="med-name">Paracetamol <span style="font-weight:500;color:var(--muted-foreground)">500 mg tablet</span></div><div class="med-sub">2 tablets · for pain</div></div><div class="state-cell">${dbadge('given')}<span class="state-line">Given 8:05 am · Priya S. · effect check due 9:35 am</span></div><div class="row-actions"><button class="btn btn-ghost btn-sm frontline-tap" type="button" data-act="toast" data-msg="PRN detail is designed in P08a.">View</button></div></div>
            <div class="dose-row"><div class="who"><span class="disc" aria-hidden="true">AN</span><div><div class="who-name">Aroha</div><div class="who-sub">Ngata</div></div></div><div><div class="med-name">Paracetamol <span style="font-weight:500;color:var(--muted-foreground)">500 mg tablet</span></div><div class="med-sub">2 tablets · for pain</div></div><div class="state-cell">${dbadge('rejected')}<span class="state-line">Saved offline 8:50 am · refused when sent at 9:02 am (limit on the prescription reached) · not on the chart</span></div><div class="row-actions"><button class="btn btn-outline btn-sm frontline-tap" type="button" data-act="rejected" data-row="prn" data-fk="rej-prn2">Review</button></div></div>
        </section>`;
    }

    /* ───────────── hub page ───────────── */
    function hubPage() {
        const p = S.persona;
        const hub = hubById(S.hub);
        const crumbTrail = isFrontline(p) ? [{ label: 'Home', href: '#' }, { label: 'Meds today' }] : [{ label: 'Home', href: '#' }, { label: 'Medication', href: hrefFrame(p, visibleHubs(p)[0].id) }, { label: hub ? hub.label : 'Medication' }];
        if (!hub || !hub.show(p)) return crumbs([{ label: 'Home', href: '#' }, { label: 'Medication' }]) + noAccess(hub ? hub.label : 'this page');
        const views = visibleViews(p, hub);
        const view = hub.views.find((v) => v.id === S.view);
        if (!view || !view.ok(p)) return crumbs(crumbTrail) + noAccess(`${hub.label} › ${view ? view.label : 'this view'}`);
        let header, body;
        if (hub.id === 'today') {
            header = medsTodayHeader(view.id);
            body = view.id === 'schedule' ? scheduleBody()
                : view.id === 'followups' ? followUpsBody(false)
                    : view.id === 'controlled' ? controlledBody()
                        : view.id === 'asneeded' ? asNeededBody()
                            : annotCard(hub, view.id);
        } else {
            const items = views.map((v) => ({ id: v.id, label: v.label, icon: v.icon, href: hrefFrame(p, hub.id, v.id), ...(hub.id === 'safety' && v.id === 'followups' ? { count: 7, alert: true, countLabel: '7 open, 3 need attention' } : {}) }));
            header = pageHeader({
                icon: hub.icon, title: hub.label, chip: chipBadge('neutral', has(p, 'cd.view') || p === 'lead' ? '2 houses' : '2 houses'),
                sub: `Kōwhai House and Rimu House · your approved houses · times in NZDT (Pacific/Auckland)`,
                actions: searchBox(`Search ${hub.label.toLowerCase()}…`, 'hs'),
                meters: `<div class="eh-meter-annot">${ic('info')}<span><b>Design note:</b> this hub’s meter blocks are designed in ${view.pkg}. Each block must link to its view and use the P00 data-quality states (n/a for zero denominators, “Unavailable” not 0, CD-excluded totals).</span></div>`,
                filters: `${has(p, 'cd.view') || hub.id === 'settings' ? '' : '<span class="fchip static">Totals exclude medicines your role can’t see</span>'}<button type="button" class="fchip" data-act="toast" data-msg="Filter menus are designed with each page package (mockup).">${ic('home', 's3')}All approved houses${ic('chev-down', 's3')}</button><button type="button" class="fchip" data-act="toast" data-msg="Filter menus are designed with each page package (mockup).">${ic('calendar', 's3')}Today${ic('chev-down', 's3')}</button><span class="fchip static">${esc(view.label)} filters · ${view.pkg}</span>`,
                rail: railHtml(items, view.id),
            });
            body = hub.id === 'safety' && view.id === 'overview' ? `<div class="annot"><div class="a-tag">${ic('info', 's3')}Design note</div>The Overview page is redesigned in P09. Its counts already follow the P00 vocabulary below (implemented by the EM-01 fix, not yet accepted).</div>${leadCounts()}`
                : hub.id === 'settings' && view.id === 'rules' ? safetyRulesView()
                : hub.id === 'mar' && view.id === 'charts' ? marChartsBody()
                : hub.id === 'safety' && view.id === 'followups' ? followUpsBody(true)
                    : annotCard(hub, view.id);
        }
        return crumbs(crumbTrail) + `<div class="stack" id="main" tabindex="-1">${header}${body}</div>`;
    }

    /* Counts shared by Meds today, My Day and handover — one schedule, one set of numbers. */
    function shiftCounts() {
        const keys = Object.keys(MEDS);
        const dueN = keys.filter((k) => rowState(k) === 'due').length;
        const lateN = keys.filter((k) => rowState(k) === 'late').length;
        const blockedN = keys.filter((k) => ['due', 'late'].includes(rowState(k)) && rowBlock(k)).length;
        const recorded = keys.filter((k) => ['given', 'refused', 'assisted', 'prompted', 'withheld', 'away', 'reoffered'].includes(rowState(k))).length;
        const denom = keys.filter((k) => MEDS[k].slot !== '12:00 pm' && rowState(k) !== 'selfmanaged').length;
        return { dueN, lateN, blockedN, recorded, denom };
    }
    function myDayMedsCard() {
        const p = S.persona, scn = S.scenario;
        const head = `<div class="cap-row" style="margin-bottom:8px"><h3 style="display:flex;align-items:center;gap:8px">${ic('pill', 's35')}Medicines</h3><span class="text-caption">Kōwhai House · your shift 7:00 am–3:00 pm · same schedule as Meds today</span></div>`;
        if (scn === 'unavailable') return `<section class="card card-pad" aria-label="Medicines">${head}${B('critical', 'alert-triangle', 'Couldn’t load medicines', 'Some doses may already be recorded. Don’t give anything from memory — open Meds today or use the printed MAR.')}<div style="margin-top:10px"><a class="btn btn-primary btn-sm frontline-tap" href="${hrefFrame(p, 'today', 'schedule')}">Open meds</a></div></section>`;
        if (scn === 'loading') return `<section class="card card-pad" aria-busy="true" aria-label="Medicines">${head}<span class="skel-line" style="width:70%"></span><span class="skel-line" style="width:40%;margin-top:8px"></span></section>`;
        const c = shiftCounts();
        const chips = scn === 'empty' ? '<span class="chipn">Nothing left to record on your shift</span>'
            : `<span class="chipn">Due now <b>${c.dueN}</b></span><span class="badge b-warning sm">${ic('alert-triangle')}Late ${c.lateN}</span>${c.blockedN ? `<span class="badge b-critical sm">${ic('lock')}Needs help ${c.blockedN}</span>` : ''}<span class="chipn">Recorded <b>${c.recorded} of ${c.denom}</b></span>`;
        const fu = (k) => { const x = FU[k]; return `<li style="display:flex;justify-content:space-between;gap:10px;align-items:center;padding:6px 0;border-bottom:1px solid var(--border)"><span style="min-width:0"><span style="font-weight:600;font-size:13px">${esc(x.title)}</span><span class="who-sub" style="display:block">${x.line.replace(/<[^>]+>/g, '')}</span></span>${x.badge}</li>`; };
        return `<section class="card card-pad" aria-label="Medicines">${head}<div style="display:flex;flex-wrap:wrap;gap:6px">${chips}</div>
            ${scn === 'empty' ? '' : `<div style="margin-top:12px"><div class="nh" style="font-size:10px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;color:var(--muted-foreground)">Your follow-ups</div><ul style="list-style:none;margin:4px 0 0;padding:0">${['overdue', 'due', 'refusal'].map(fu).join('')}</ul></div>`}
            <div style="display:flex;gap:8px;margin-top:12px;flex-wrap:wrap"><a class="btn btn-primary btn-sm frontline-tap" href="${hrefFrame(p, 'today', 'schedule')}">${ic('pill')}Open meds</a><a class="btn btn-outline btn-sm frontline-tap" href="${hrefFrame(p, 'today', 'followups')}">${ic('flag')}Open follow-ups</a></div></section>`;
    }
    function myDayPage() {
        return crumbs([{ label: 'Home', href: '#' }, { label: 'My Day' }]) + `<div class="stack" id="main" tabindex="-1"><div class="annot"><div class="a-tag">${ic('info', 's3')}Reference frame — not a redesign</div>My Day keeps its approved design. This frame shows only how its medication card uses the P00 states: counts from the same schedule as Meds today, an “Open meds” deep link, and the worker’s own follow-ups. The full card is designed in <b>P01</b> (follow-ups in <b>P08a</b>).</div>
            <div class="twocol">${myDayMedsCard()}<div class="annot" style="min-height:160px;display:grid;place-items:center;text-align:center"><div><div class="a-tag">Placeholder</div>Other My Day cards — unchanged</div></div></div></div>`;
    }
    function handoverLens() {
        const p = S.persona, cd = has(p, 'cd.view');
        const ack = S.handoverAck;
        return `<section class="card" style="overflow:hidden" aria-label="Medication handover">
            <div class="card-pad" style="display:flex;justify-content:space-between;gap:12px;flex-wrap:wrap;align-items:center;border-bottom:1px solid var(--border)"><div><div style="font-weight:650;font-size:14px">Medication — night shift to day shift</div><div class="text-caption">Kōwhai House · handed over at 7:00 am by Mere Kahu · times in NZDT</div></div>
                ${ack ? `<span class="badge b-success">${ic('check')}Acknowledged 9:14 am by ${esc(PERSONAS[p].short)}</span>` : `<button class="btn btn-primary btn-sm" type="button" data-act="ho-ack" data-fk="ho-ack">${ic('check')}Acknowledge handover</button>`}</div>
            ${ack ? `<div class="card-pad" style="padding-bottom:0">${B('success', 'check-circle', 'Handover acknowledged', 'Medication work stays open: the follow-ups below are still live in Follow-ups, and the discrepancy stays open in the controlled register. Acknowledging closes nothing.')}</div>` : ''}
            <div class="slot-head" style="margin-top:${ack ? '12px' : '0'}">Open follow-ups — live items, not free text</div>${followUpRow(FU.overdue, 'overdue')}${followUpRow(FU.noowner, 'noowner')}
            <div class="slot-head">Doses with no outcome at handover</div>
            <div class="dose-row" style="grid-template-columns:minmax(0,1.2fr) minmax(0,1.4fr) minmax(0,1.4fr) 190px"><div class="who"><span class="disc" aria-hidden="true">AN</span><div><div class="who-name">Aroha</div><div class="who-sub">Ngata</div></div></div><div><div class="med-name">Metformin <span style="font-weight:500;color:var(--muted-foreground)">500 mg tablet</span></div><div class="med-sub">6:00 pm Sunday</div></div><div class="state-cell">${dbadge('notrecorded')}<span class="state-line">No outcome recorded · owner Jordan Tipene to find out</span></div><div class="row-actions"><a class="btn btn-ghost btn-sm" href="#/person/${p}/aroha/chart">Open chart</a></div></div>
            ${cd ? `<div class="slot-head">Controlled count at handover</div><div class="dose-row" style="grid-template-columns:minmax(0,1.2fr) minmax(0,1.4fr) minmax(0,1.4fr) 190px"><div class="who"><span class="disc" aria-hidden="true">AN</span><div><div class="who-name">Aroha</div><div class="who-sub">Ngata</div></div></div><div><div class="med-name">Methylphenidate <span style="font-weight:500;color:var(--muted-foreground)">10 mg tablet</span></div><div class="med-sub"><span class="chipn">${ic('shield', 's3')}Controlled</span>Counted 27 · register shows 28</div></div><div class="state-cell"><span class="badge b-critical">${ic('alert-triangle')}Discrepancy raised</span><span class="state-line">CD-2026-014 · open · owner Jordan Tipene · a real discrepancy record, not a note</span></div><div class="row-actions"><button class="btn btn-ghost btn-sm" type="button" data-act="toast" data-msg="Opens the discrepancy in the controlled register (P07a/P07b).">Open discrepancy</button></div></div>` : ''}
            <div class="card-pad" style="border-top:1px solid var(--border)"><p class="text-caption" style="margin:0">${cd ? '' : 'Showing medicines your role can see. '}Items come from the live records; outgoing staff add context, not copies. A new follow-up raised here gets an owner and a due time.</p></div>
        </section>`;
    }
    function handoverPage() {
        return crumbs([{ label: 'Home', href: '#' }, { label: 'Operations', href: '#' }, { label: 'Shift handover', href: '#' }, { label: 'Medication' }]) + `<div class="stack" id="main" tabindex="-1"><div class="annot"><div class="a-tag">${ic('info', 's3')}Reference frame — not a redesign</div>The shift handover keeps its approved design. This frame shows only the medication lens: live follow-ups with an owner and due time, acknowledging the handover doesn’t close medication work, and a controlled-drug count discrepancy raises the real discrepancy record. Designed in full in <b>P08a</b> (count and witness in <b>P07a</b>).</div>${handoverLens()}</div>`;
    }
    function touchSection() {
        return stateCard({ wide: true, id: 'touch-myday', name: 'My Day — medication card', spec: myDayMedsCard(), white: false, wording: ['Medicines', 'Due now · Late · Needs help · Recorded {x} of {y}', 'Your follow-ups', 'Open meds · Open follow-ups', 'Couldn’t load medicines'], when: 'On My Day for anyone who records medication.', treatment: 'The same counts as Meds today (one schedule, the same NZ day), the worker’s own follow-ups with owner and due time, and the P00 data-quality states when the read fails or is loading.', never: 'A second count that disagrees with Meds today; a 0 after a failed read.', reuse: ['My Day (P01)', 'Follow-ups (P08a)'], depends: ['D4'], fixes: ['EM-01', 'NF-14', 'EM-18'], link: { href: '#/myday/sw', label: 'Open the My Day reference frame' } })
            + stateCard({ wide: true, id: 'touch-handover', name: 'Shift handover — medication lens', spec: handoverLens(), white: false, wording: ['Open follow-ups — live items, not free text', 'Doses with no outcome at handover', 'Discrepancy raised · CD-2026-014 · open · owner Jordan Tipene', 'Acknowledge handover', 'Handover acknowledged — Medication work stays open…'], when: 'In the shift handover, for the outgoing and incoming staff.', treatment: 'Live follow-up rows (with the ⋯ and right-click menu), not copied text. Acknowledging records who took over and when; it closes nothing. A controlled count mismatch raises the canonical discrepancy record, and is hidden from roles without controlled-medicine access.', never: 'A medication-only handover database, or free-text items without an owner.', reuse: ['Shift handover (P08a)', 'Controlled checks (P07a)', 'Follow-ups (P08a)'], depends: ['D8', 'D12'], fixes: ['EM-21', 'EM-05'], link: { href: '#/handover/sw', label: 'Open the handover reference frame' } });
    }
    function noAccess(what) {
        return `<div class="stack" id="main" tabindex="-1"><div class="card"><div class="empty" role="alert"><span class="e-ico">${ic('lock', 's6')}</span><h3>You don’t have access to ${esc(what)}</h3><p>Ask your manager if you need it for your work.</p><div class="e-act"><a class="btn btn-outline" href="${hrefFrame(S.persona, visibleHubs(S.persona)[0]?.id || 'today')}">Go to ${esc(visibleHubs(S.persona)[0]?.label || 'Home')}</a></div></div></div>
            <div class="annot"><div class="a-tag">${ic('info', 's3')}Design note</div>Page-level “no access” (403) names the page, never its content. Records use “We can’t show this record” instead, so a hidden record and a missing one look the same.</div></div>`;
    }
    function notFound(backHref, backLabel) {
        return `<div class="card"><div class="empty" role="alert"><span class="e-ico">${ic('search', 's6')}</span><h3>We can’t show this record</h3><p>It may not exist, or it may not be available to you. Check the link, or go back.</p><div class="e-act"><a class="btn btn-outline" href="${backHref}">${esc(backLabel)}</a></div></div></div>`;
    }

    /* Organisation-wide safety rules — implemented by the NF-03 fix (commit dda3beef6) on /emar/settings.
     * P00 adds “Default — not yet reviewed” until someone deliberately saves a choice. */
    const SAFETY_RULES = [
        { key: 'profileAllergy', label: 'When a medicine matches a recorded allergy', help: 'The specific match is shown before signing in every mode. Built today: Warn and Block for health-profile matches; severe matches in the medication allergy list always block. The third option is new and not built.', opts: [['warn', 'Warn — show the match; “given” can still be recorded'], ['block', 'Block — contact the prescriber or an authorised override'], ['confirm', 'Block unless the prescriber has confirmed this allergy on the order (new)']], def: 'warn' },
        { key: 'restricted', label: 'A worker’s medication competency is marked restricted', help: 'Refusals and withheld doses can always be recorded.', opts: [['off', 'Off — no extra check'], ['block', 'Block — they can’t sign doses as given'], ['cosigner', 'Co-signer — a present, qualified colleague confirms each dose']], def: 'off' },
        { key: 'area', label: 'The controlled-drug or covert area wasn’t passed', help: 'Applies to controlled-drug orders and orders with an active covert authorisation. Insulin isn’t covered yet: orders don’t record whether a medicine is insulin.', opts: [['off', 'Off — no extra check'], ['failed', 'Block when the area was failed'], ['failed_or_not_seen', 'Block when failed or not seen at assessment']], def: 'off' },
    ];
    function safetyRulesCard(canManage, staticSpec) {
        const vals = S.safety, draft = S.safetyDraft;
        const changed = SAFETY_RULES.filter((r) => draft[r.key] !== vals[r.key]);
        return `<section class="card card-pad" aria-labelledby="sr-h" ${staticSpec ? 'inert' : ''}><div class="cap-row" style="margin-bottom:2px"><h3 id="sr-h" tabindex="-1" style="outline:none">Organisation-wide safety rules</h3></div><p class="text-subtle" style="margin:0 0 6px;font-size:12.5px">These apply at every site when a dose is signed. Changes are recorded in the audit log.</p>
            ${SAFETY_RULES.map((r) => `<div class="set-row"><div><label for="sr-${r.key}" style="font-weight:600;font-size:13px">${esc(r.label)}</label><div class="help">${esc(r.help)}</div></div><div><select id="sr-${r.key}" class="field" style="border:1px solid var(--input);border-radius:8px;min-height:36px;padding:0 8px;background:var(--card)" data-act="sr-sel" data-key="${r.key}" ${canManage ? '' : 'disabled'}>${r.opts.map(([v, l]) => `<option value="${v}"${draft[r.key] === v ? ' selected' : ''}>${esc(l)}</option>`).join('')}</select><div class="set-meta">${S.safetySetBy[r.key] ? `<span class="chipn">Set by ${esc(S.safetySetBy[r.key])}</span>` : `<span class="nc">${ic('settings', 's3')}Default — not yet reviewed</span>`}</div></div></div>`).join('')}
            <div style="display:flex;justify-content:flex-end;margin-top:12px">${canManage ? `<button class="btn btn-primary btn-sm" type="button" data-act="sr-save" data-fk="sr-save" ${changed.length ? '' : 'disabled'}>Save safety rules</button>` : '<p class="text-caption" style="margin:0">Only someone who manages medication settings for all sites can change these rules.</p>'}</div>
        </section>`;
    }
    function safetyRulesView() {
        const canManage = S.persona === 'clinical';
        return safetyRulesCard(canManage) + `<div class="annot"><div class="a-tag">${ic('info', 's3')}Design note</div>Implemented by the NF-03 fix (not yet accepted). Every rule defaults to the previous behaviour; the values themselves are organisation decisions (${dtag('D3')} and Stephan’s NF-03 choice for competency, ${dtag('D5')} for allergies). P00 adds the “Default — not yet reviewed” marker so a code default is never mistaken for an approved policy. ${canManage ? 'You’re signed in as the clinical lead, who has all-sites authority.' : 'Switch “Signed in as” to Clinical lead to edit; the house lead sees the read-only note.'} The rest of Settings is designed in P11.</div>`;
    }
    function openSafetyConfirm() {
        const changed = SAFETY_RULES.filter((r) => S.safetyDraft[r.key] !== S.safety[r.key]);
        openDialog(simpleDialog({
            title: 'Change the medication safety rules?', icon: 'shield', desc: 'From the next dose signed, at every site:',
            body: `<ul style="margin:0;padding-left:18px;font-size:13px">${changed.map((r) => `<li><b>${esc(r.label)}:</b> ${esc(r.opts.find((o) => o[0] === S.safetyDraft[r.key])[1])}</li>`).join('')}</ul><p class="text-caption" style="margin:0">Recorded in the audit log with your name and the time.</p>`,
            foot: `<button class="btn btn-outline" type="button" data-act="close">Cancel</button><button class="btn btn-primary" type="button" data-act="sr-confirm">Save rules</button>`,
        }), 'dlg-simple w480', 'dlg-t', 'dlg-d');
    }

    /* ───────────── canonical person medication record ───────────── */
    const PTABS = [
        { id: 'chart', label: 'Chart', icon: 'clipboard-list' },
        { id: 'medicines', label: 'Medicines', icon: 'pill' },
        { id: 'support', label: 'Support plan', icon: 'user-check' },
        { id: 'allergies', label: 'Allergies & alerts', icon: 'alert-octagon', warn: 1 },
        { id: 'clinical', label: 'Clinical', icon: 'stethoscope' },
        { id: 'history', label: 'History', icon: 'history' },
    ];
    function personPage() {
        const p = S.persona, pp = PEOPLE[S.pid];
        const cd = has(p, 'cd.view');
        const fromToday = isFrontline(p);
        const back = fromToday ? { href: hrefFrame(p, 'today', 'schedule'), label: 'Back to Meds today' } : { href: hrefFrame(p, 'mar', 'charts'), label: 'Back to MAR charts' };
        const trail = fromToday ? [{ label: 'Home', href: '#' }, { label: 'Meds today', href: back.href }, { label: `${pp.pref} ${pp.surname}` }] : [{ label: 'Home', href: '#' }, { label: 'Medication', href: hrefFrame(p, visibleHubs(p)[0].id) }, { label: 'MAR & medicines', href: back.href }, { label: `${pp.pref} ${pp.surname}` }];
        if (!(has(p, 'view'))) return crumbs(trail) + notFound(back.href, back.label);
        if (pp.house === 'Rimu House' && p === 'sw') return crumbs([{ label: 'Home', href: '#' }, { label: 'Meds today', href: back.href }, { label: 'Record' }]) + `<div class="stack" id="main" tabindex="-1">${notFound(back.href, back.label)}</div>`;
        const al = pp.allergy;
        const alMeter = al.startsWith('recorded') ? meter({ label: 'Allergies', value: al === 'recorded' ? '2' : '1', big: 'Recorded', cap: al === 'recorded' ? 'Penicillin (severe) · Latex' : 'Penicillin (severe)', tone: 'critical', href: `#/person/${p}/${pp.id}/allergies`, aria: 'View allergies' })
            : al === 'none' ? meter({ label: 'Allergies', big: 'Not recorded', cap: 'Status not reviewed', tone: 'warning', href: `#/person/${p}/${pp.id}/allergies`, aria: 'View allergies: none recorded' })
                : al === 'nkda' ? meter({ label: 'Allergies', big: 'None known', cap: 'Recorded 12 Aug 2026', href: `#/person/${p}/${pp.id}/allergies`, aria: 'View allergies: no known drug allergies' })
                    : meter({ label: 'Allergies', big: 'Unavailable', cap: 'Couldn’t load', tone: 'critical', href: `#/person/${p}/${pp.id}/allergies`, aria: 'View allergies: unavailable' });
        const meters = alMeter
            + meter({ label: 'Today', value: '3 of 5', donut: { pct: 0.6, text: '60%' }, cap: 'Recorded of due so far', href: `#/person/${p}/${pp.id}/chart`, aria: 'View today’s chart' })
            + meter({ label: 'Follow-ups', big: '1', cap: 'Effect check due 9:35 am', href: `#/person/${p}/${pp.id}/history`, aria: 'View follow-ups' })
            + meter({ label: 'Support plan', big: 'Current', cap: 'Review due 12 Jan 2027', href: `#/person/${p}/${pp.id}/support`, aria: 'View support plan' })
            + (cd ? meter({ label: 'Controlled', big: '1', cap: 'Needs a witness', href: `#/person/${p}/${pp.id}/medicines`, aria: 'View controlled medicines' }) : '')
            + meter({ label: 'Last dose', big: '9:04 am', cap: 'Vitamin D · taken with prompting', href: `#/person/${p}/${pp.id}/history`, aria: 'View history' });
        const header = pageHeader({
            variant: 'profile', back, mark: pp.photo ? `<span style="display:block;width:42px;height:42px;border-radius:50%;overflow:hidden">${PHOTO}</span>` : pp.initials,
            title: `${pp.pref} ${pp.surname}`, chip: chipBadge('success', 'Active'),
            sub: `${esc(pp.house)} · 12 Kōwhai Street, Newtown, Wellington 6021`,
            sub2: `Medication record · Supported living · legal name ${esc(pp.legal)}`,
            actions: searchBox(`Search ${pp.pref}’s medicines…`, 'ps') + `<button class="glass-btn" type="button" data-act="toast-outside" aria-label="Print MAR" title="Print MAR">${ic('printer')}<span class="lbl-wide">Print MAR</span></button>${has(p, 'administer') ? `<button class="white-btn" type="button" data-act="record" data-row="r6" data-fk="rec-hdr">${ic('plus')}Record a dose</button>` : ''}`,
            meters,
            filters: `${cd ? '' : '<span class="fchip static">Totals exclude medicines your role can’t see</span>'}<button type="button" class="fchip" data-act="toast" data-msg="Filter menus are designed with each page package (mockup).">${ic('calendar', 's3')}Today, Mon 28 Sep${ic('chev-down', 's3')}</button><span class="fchip static">Times in NZDT</span>`,
        });
        const tabs = `<div class="tier2" role="tablist" aria-label="Medication record sections">${PTABS.map((t, i) => { const on = t.id === S.ptab; return `<a class="t2 tone${i % 5}${on ? ' on' : ''}" role="tab" aria-selected="${on}" href="#/person/${p}/${pp.id}/${t.id}"><span class="chip">${ic(t.icon, 's35')}</span>${t.label}${t.warn && al !== 'nkda' ? `<span class="cnt alert" aria-label="needs attention">!</span>` : ''}${on ? '<span class="bar" aria-hidden="true"></span>' : ''}</a>`; }).join('')}</div>`;
        return crumbs(trail) + `<div class="stack" id="main" tabindex="-1">${header}${tabs}${personTab(pp, p)}</div>`;
    }

    function personTab(pp, p) {
        const cd = has(p, 'cd.view');
        const b = (k) => dbadge(k, true);
        if (S.ptab === 'chart') {
            const rows = [
                ['Metformin 500 mg tablet', 'administer', [b('given') + '<div class="who-sub">8:05 am</div>', '', b('notdue'), b('notdue')]],
                ['Vitamin D (colecalciferol) 1.25 mg capsule', 'prompt', ['', b('prompted') + '<div class="who-sub">9:04 am</div>', '', '']],
                ...(cd ? [['Methylphenidate 10 mg tablet · controlled', 'administer', ['', '', b('notdue') + '<div class="who-sub">needs a witness</div>', '']]] : []),
                ['Paracetamol 500 mg tablet · as needed', 'administer', [b('given') + '<div class="who-sub">8:05 am</div>', b('rejected') + '<div class="who-sub">8:50 am · not on chart</div>', '', '']],
                ['Salbutamol inhaler · as needed', 'independent', [b('selfmanaged'), '', '', '']],
            ];
            return `<section class="card" style="overflow:hidden" aria-label="Today’s chart"><div class="tbl-wrap"><table class="etable"><caption class="sr-only">Chart for Monday 28 September 2026, times in NZDT</caption><thead><tr><th scope="col">Medicine</th><th scope="col">Support</th><th scope="col">8:00 am</th><th scope="col">9:00 am</th><th scope="col">12:00 pm</th><th scope="col">6:00 pm</th></tr></thead><tbody>
                ${rows.map(([m, s, cells]) => `<tr><td style="font-weight:600">${esc(m)}</td><td>${supportChip(s)}</td>${cells.map((c) => `<td>${c || '<span style="color:var(--muted-foreground)">—</span>'}</td>`).join('')}</tr>`).join('')}
            </tbody></table></div></section><p class="text-caption" style="margin:0">— means no dose is scheduled at that time. Times in NZDT.${cd ? '' : ' Showing medicines your role can see.'}</p>
            <div class="annot"><div class="a-tag">${ic('info', 's3')}Design note</div>The full chart grid, day navigation and dose drawer are designed in <b>P02</b>. P00 fixes the cell vocabulary: every cell uses the dose-state badges, and an empty past cell reads “Not yet recorded”, never blank or 0.</div>`;
        }
        if (S.ptab === 'medicines') {
            const list = [
                ['Metformin', '500 mg tablet · 1 tablet with breakfast and lunch', 'administer', 'Active'],
                ['Vitamin D (colecalciferol)', '1.25 mg capsule · monthly', 'prompt', 'Active'],
                ...(cd ? [['Methylphenidate', '10 mg tablet · 1 at 12:00 pm', 'administer', 'Active', true]] : []),
                ['Paracetamol', '500 mg tablet · as needed', 'administer', 'Active'],
                ['Salbutamol', 'inhaler · as needed', 'independent', 'Active'],
            ];
            return `<section class="card" style="overflow:hidden"><div class="tbl-wrap"><table class="etable"><caption class="sr-only">Medicines</caption><thead><tr><th scope="col">Medicine</th><th scope="col">Support</th><th scope="col">Status</th><th scope="col"><span class="sr-only">Actions</span></th></tr></thead><tbody>
                ${list.map(([n, d, s, st, isCd]) => `<tr data-menu="medicine:${esc(n)}"><td><div style="font-weight:600">${esc(n)} ${isCd ? `<span class="chipn">${ic('shield', 's3')}Controlled</span>` : ''}</div><div class="who-sub">${esc(d)}</div></td><td>${supportChip(s)}</td><td><span class="badge b-success sm">${st}</span></td><td style="text-align:right">${kebab('medicine', n, n)}</td></tr>`).join('')}
            </tbody></table></div></section>
            <p class="text-caption" style="margin:0">${cd ? 'You can see controlled medicines.' : 'Showing medicines your role can see.'}</p>
            <div class="annot"><div class="a-tag">${ic('info', 's3')}Design note — controlled-medicine concealment</div>Switch “Signed in as” to Clinical lead, Auditor or Finance: the methylphenidate row, its meter block and its chart row disappear with no placeholder, and the constant caption “Showing medicines your role can see.” appears for every list those roles open, whether or not anything is hidden (${dtag('D9')}).</div>`;
        }
        if (S.ptab === 'support') {
            return `<section class="card card-pad"><div class="cap-row"><h3>Support for each medicine</h3><span class="text-caption">From the support plan · reviewed 12 January 2026 · next review 12 January 2027</span></div>
                <div class="tbl-wrap"><table class="etable"><thead><tr><th scope="col">Medicine</th><th scope="col">Support</th><th scope="col">What staff do</th></tr></thead><tbody>
                <tr><td>Metformin</td><td>${supportChip('administer')}</td><td>Give the medicine and record it as given.</td></tr>
                <tr><td>Vitamin D</td><td>${supportChip('prompt')}</td><td>Remind Aroha; record “taken with prompting”.</td></tr>
                <tr><td>Salbutamol</td><td>${supportChip('independent')}</td><td>Nothing to record. Never counted as late or missed.</td></tr>
                </tbody></table></div></section>
                <div class="annot"><div class="a-tag">${ic('info', 's3')}Design note</div>The four support levels are the plan’s candidate vocabulary. Their names, meaning and review triggers are organisation decision ${dtag('D6')}; the assessment and agreement flows are designed in <b>P03</b>.</div>`;
        }
        if (S.ptab === 'allergies') {
            return `<section class="card card-pad stack" style="gap:12px"><div class="cap-row" style="margin:0"><h3>Allergies</h3><span class="text-caption">Source: health profile (canonical) ${dtag('D5')}</span></div>${allergyBanner(pp.allergy, pp.pref)}</section>
                <section class="card card-pad"><div class="cap-row"><h3>Alerts</h3></div><p style="margin:0">${esc(pp.comm || 'No alerts recorded.')}</p></section>
                <div class="annot"><div class="a-tag">${ic('info', 's3')}Design note</div>Other people show the other allergy states: Tama (no allergies recorded), Grace (couldn’t load), Sam (no known drug allergies, only once ${dtag('D5')} approves the vocabulary). <a href="#/person/${p}/tama/allergies">Tama</a> · <a href="#/person/${p}/grace/allergies">Grace</a> · <a href="#/person/${p}/sam/allergies">Sam</a></div>`;
        }
        if (S.ptab === 'history') {
            return `<section class="card card-pad"><div class="cap-row"><h3>Correction lineage — sertraline, 8:00 am (example from Grace)</h3></div>${lineage()}</section>
                <section class="card" style="overflow:hidden"><div class="slot-head">Open follow-ups for ${esc(pp.pref)}</div>${followUpRow(FU.due)}</section>`;
        }
        return `<div class="annot"><div class="a-tag">${ic('info', 's3')}Design note</div>INR, syringe driver and observations are designed in <b>P02</b>. They use the P00 stale-data state: a result older than the organisation’s rule reads “Out of date” with its date (${NC()}), never as current.</div>`;
    }

    function lineage() {
        return `<ol class="lineage">
            <li><span class="dot">${ic('x', 's3')}</span><div><div class="lt"><span class="strike">Refused</span> · given time 8:05 am</div><div class="lm">Recorded 8:06 am by Priya Shah · original record kept</div></div></li>
            <li><span class="dot">${ic('pencil', 's3')}</span><div><div class="lt">Correction requested: “Wrong outcome chosen — Grace had vomited”</div><div class="lm">9:30 am by Priya Shah</div></div></li>
            <li><span class="dot">${ic('check', 's3')}</span><div><div class="lt">Correction approved</div><div class="lm">10:15 am by Jordan Tipene</div></div></li>
            <li><span class="dot cur">${ic('pause', 's3')}</span><div><div class="lt">Now: Withheld · Vomit or nausea ${dbadge('corrected', true)}</div><div class="lm">Counts once, as the corrected outcome. A correction can’t turn “not given” into “given” — use “Given after re-offer”.</div></div></li>
        </ol>`;
    }

    /* Direct record link (for concealment demo) */
    function recordPage() {
        const p = S.persona;
        const back = isFrontline(p) ? hrefFrame(p, 'today', 'schedule') : hrefFrame(p, visibleHubs(p)[0].id);
        const trail = [{ label: 'Home', href: '#' }, { label: 'Medication', href: back }, { label: 'Record' }];
        if (!has(p, 'cd.view')) return crumbs(trail) + `<div class="stack" id="main" tabindex="-1">${notFound(back, 'Go back')}<div class="annot"><div class="a-tag">${ic('info', 's3')}Design note</div>This is a direct link to a controlled register entry, opened by a role without controlled-medicine access. The page is identical to a record that doesn’t exist, so the link reveals nothing (404, not 403).</div></div>`;
        return crumbs(trail) + `<div class="stack" id="main" tabindex="-1"><div class="card card-pad"><div class="cap-row"><h3>${ic('shield', 's35')} Controlled register entry · methylphenidate 10 mg · Aroha</h3>${chipBadge('neutral', 'Administration')}</div><p class="text-caption">You can see this because your role has controlled-medicine access. Detail is designed in P07b.</p></div></div>`;
    }

    /* ───────────── catalogue ───────────── */
    const SECTIONS = [
        ['intro', 'How to read this', 'info'],
        ['navigation', 'Navigation frame', 'layers'],
        ['dose', 'Dose obligations & outcomes', 'clipboard-check'],
        ['lifecycle', 'Recording lifecycle', 'send'],
        ['blocked', 'Blocked reasons', 'lock'],
        ['second', 'Second-person confirmation (options)', 'users'],
        ['allergy', 'Allergy status', 'alert-octagon'],
        ['quality', 'Data quality', 'refresh'],
        ['controlled', 'Controlled-medicine concealment', 'eye-off'],
        ['followups', 'Follow-ups', 'flag'],
        ['touch', 'Touchpoints: My Day & handover', 'dashboard'],
        ['identity', 'Person identity header', 'user'],
        ['time', 'Time display rules', 'clock'],
        ['universal', 'Universal interaction states', 'keyboard'],
        ['decisions', 'Organisation decisions (D1–D13)', 'settings'],
        ['reuse', 'Reuse by package', 'git-branch'],
    ];

    function stateCard(s) {
        return `<article class="card sc${s.wide ? ' wide' : ''}" id="st-${s.id}" aria-labelledby="st-${s.id}-h">
            <div class="sc-spec">
                <div class="sc-name"><h3 id="st-${s.id}-h">${esc(s.name)}</h3><span class="sc-id">${s.id}</span></div>
                <div class="sc-stage${s.white ? ' white' : ''}">${s.spec}</div>
                ${s.open ? `<button class="btn btn-outline btn-sm sc-open" type="button" ${s.open.attrs}>${ic('arrow-up-right')}${esc(s.open.label)}</button>` : ''}
                ${s.link ? `<a class="btn btn-outline btn-sm sc-open" href="${s.link.href}">${ic('arrow-up-right')}${esc(s.link.label)}</a>` : ''}
            </div>
            <div class="notes">
                ${s.wording && s.wording.length ? `<div><div class="nh">Exact wording</div><div class="wording">${s.wording.map((w) => `<div><q>${w}</q></div>`).join('')}</div></div>` : ''}
                ${s.when ? `<div><div class="nh">When it shows</div><p>${s.when}</p></div>` : ''}
                ${s.next ? `<div><div class="nh">Named next step</div><p>${s.next}</p></div>` : ''}
                ${s.treatment ? `<div><div class="nh">Treatment</div><p>${s.treatment}</p></div>` : ''}
                ${s.never ? `<div><div class="nh">Never</div><p>${s.never}</p></div>` : ''}
                ${s.reuse && !SHARED_REUSE.has(s.reuse) ? `<div><div class="nh">Reused by</div><div class="tags">${s.reuse.map((r) => `<span class="chipn">${esc(r)}</span>`).join('')}</div></div>` : ''}
                <div class="tags"><span class="nh" style="align-self:center;margin:0 4px 0 0">Depends on</span>${(s.depends || []).length ? s.depends.map(dtag).join('') : '<span class="text-caption">No organisation decision</span>'}
                ${(s.fixes || []).length ? `<span class="nh" style="align-self:center;margin:0 4px 0 10px">Addresses</span>${s.fixes.map(ftag).join('')}` : ''}</div>
            </div>
        </article>`;
    }
    const R = {
        dose: ['Meds today · Schedule, Rounds, As-needed, Activity', 'RecordDoseWizard', 'PrnWizard', 'GuidedRoundDialog', 'RecordAdministrationDialog (client profile)', 'MAR dose-context-menu', 'RecordedDetailDialog', 'Person record · Chart, History', 'Client profile · Medical › MAR', 'Handover medication lens', 'Reports & audit', 'Fleet transport medication dialogs', 'Mobile API messages'],
        record: ['RecordDoseWizard', 'PrnWizard', 'GuidedRoundDialog', 'RecordAdministrationDialog', 'MAR one-click “Mark given”', 'Fleet transport medication dialogs', 'CD dialogs (BalanceCheckDialog, RecordCdEntryDialog)', 'PrnEffectDialog', 'Offline queue', 'Mobile API messages'],
        blocked: ['Meds today rows (“Why can’t I record this?”)', 'RecordDoseWizard', 'PrnWizard', 'GuidedRoundDialog', 'RecordAdministrationDialog', 'MAR dose-context-menu (disabled with reason)', 'Fleet transport medication dialogs', 'Mobile API error text'],
        allergy: ['Identity header in every recording dialog', 'Person record · Allergies & alerts + meter', 'Client profile · Medical › MAR tab', 'ClientAllergyBanner', 'NewOrderDialog (P04)', 'ConductReviewDialog (P05)'],
        quality: ['Every package (P01–P11)', 'Meter blocks', 'Sidebar badge', 'Tasks projection', 'Reports & exports'],
        cd: ['Person record (P02)', 'Controlled checks + register (P07a/b)', 'Medication errors (P08b)', 'Reports & aggregates (P09)', 'All Tasks provider (EM-12)', 'Global search / lookup', 'CSV exports'],
        fu: ['Meds today · Follow-ups (P08a)', 'Safety & oversight · Follow-ups (P08a)', 'PrnEffectDialog', 'RefusalFollowUpDialog (mount)', 'Handover medication lens', 'All Tasks projection', 'Error actions (P08b)', 'Review actions (P05)'],
        id: ['RecordDoseWizard', 'PrnWizard', 'GuidedRoundDialog', 'RecordAdministrationDialog', 'CD dialogs (P07a)', 'PrnEffectDialog / RefusalFollowUpDialog (P08a)', 'Self-administration assessment (P03)', 'Emergency access request (P10)'],
        time: ['Every page, dialog, export and printout'],
    };

    const SHARED_REUSE = new Set(Object.values(R));
    const reuseBar = (list, extra) => `<div class="card card-pad" style="padding:12px 16px"><div class="tags" style="display:flex;flex-wrap:wrap;gap:6px;align-items:center"><span class="nh" style="font-size:10px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;color:var(--muted-foreground);margin-right:4px">Reused by (every card below${extra ? ', unless a card says otherwise' : ''})</span>${list.map((x) => `<span class="chipn">${esc(x)}</span>`).join('')}</div></div>`;
    function sectionHtml(id) {
        const H = (t, p) => `<header><h2>${t}</h2>${p ? `<p>${p}</p>` : ''}</header>`;
        switch (id) {
            case 'intro': return introSection();
            case 'navigation': return navSection(H);
            case 'dose': return H('Dose obligations & outcomes', 'An obligation is a scheduled dose with no outcome yet. An outcome is what actually happened, recorded by a person. The two never share a badge. Late is amber because it can still be acted on; red is kept for things that went wrong or are unknown.') + reuseBar(R.dose) + doseCards();
            case 'lifecycle': return H('Recording lifecycle', 'What a record looks like between pressing save and the server confirming it. Nothing reads “given” until the server confirms it.') + reuseBar(R.record, true) + lifecycleCards();
            case 'blocked': return H('Blocked reasons', 'Every block says why in plain words, names a real next step and says what can still be recorded. The server rule behind each block stays exactly as it is; the screen only explains it. Refusal, withhold and absence stay recordable whenever the server allows them (NF-06).') + reuseBar(R.blocked, true) + blockedCards();
            case 'second': return H('Second-person confirmation — options for Stephan', 'Today a co-signer or controlled-drug witness types their own login password on the recorder’s screen, which Stephan calls an operational nightmare. Three alternatives, shown separately for restricted competency and controlled-drug witnessing (which needs physical presence), each with every state: success, declined, expired, locked and no eligible colleague.') + secondSection();
            case 'allergy': return H('Allergy status', 'Three states always, a fourth only when the organisation approves the vocabulary. The screen never says “no known allergies” because a list came back empty.') + reuseBar(R.allergy) + allergyCards();
            case 'quality': return H('Data quality', 'Missing, failed or not-applicable data never looks like a reassuring zero.') + reuseBar(R.quality) + qualityCards();
            case 'controlled': return H('Controlled-medicine concealment', 'For roles without controlled-medicine access, controlled records leave no trace: no row, no placeholder, no count, no search result, no dead link.') + reuseBar(R.cd) + cdCards();
            case 'followups': return H('Follow-ups', 'Every follow-up has an owner, a due time in NZ time and a state. It survives midnight and shift changes and closes only with a recorded result.') + reuseBar(R.fu) + fuCards();
            case 'touch': return H('Touchpoints: My Day and handover', 'Reference frames only — the pages keep their approved designs. They show how the shared states appear where medication work surfaces outside Meds today.') + touchSection();
            case 'identity': return H('Person identity header', 'The same header opens every recording dialog: preferred name first, photo only if one is held, house, and the support level for the medicine being recorded.') + reuseBar(R.id) + idCards();
            case 'time': return H('Time display rules', 'All medication times are the house’s local time in Pacific/Auckland, with the zone visible. Daylight saving never shifts a dose silently.') + reuseBar(R.time) + timeSection();
            case 'universal': return H('Universal interaction states', 'States every package mockup must include (plan §7.3), shown here once so they read the same everywhere.') + universalCards();
            case 'decisions': return H('Organisation decisions', 'Values nobody has approved yet display “Not configured” and fail closed. A mockup or code default never becomes policy by being displayed.') + decisionsSection();
            case 'reuse': return H('Reuse by package', 'Which state families each later package must reuse, from plan §7.3. Every package brief must cite this P00 version.') + reuseSection();
            default: return '';
        }
    }

    function introSection() {
        return `<div class="card cat-hero"><h1>Medication rules &amp; states</h1><p>P00 · version 2 · 29 September 2026. The shared state catalogue every medication page reuses, now aligned with the implemented P0 fixes (not yet accepted). Design only: no application code, routes, schema or configuration change. Synthetic people and staff; no clinical values are approved by appearing here.</p>
            <div class="rules" style="margin-top:14px">
                <div class="card rule"><b>1. Unknown is never shown as zero or “none”.</b>A failed read says it failed. A zero denominator reads “n/a”. An empty allergy list reads “No allergies recorded”.</div>
                <div class="card rule"><b>2. Nothing reads “given” until the server confirms it.</b>Sending, saved on this device and not recorded are separate, visible states.</div>
                <div class="card rule"><b>3. Every block names why and what to do next.</b>Refusal, withhold and absence stay recordable whenever the server allows them.</div>
                <div class="card rule"><b>4. Unapproved values read “Not configured”.</b>They fail closed: no “give now if safe”, no invented timing, contact or threshold.</div>
                <div class="card rule"><b>5. NZ local time, zone visible, daylight-saving safe.</b>Relative times always sit beside the clock time.</div>
                <div class="card rule"><b>6. People first.</b>Preferred names, plain NZ English, sentence case. A person’s choice is recorded, not overridden.</div>
                <div class="card rule"><b>7. Hidden records leave no trace.</b>A record you can’t see and one that doesn’t exist look the same.</div>
                <div class="card rule"><b>8. Navigation is discovery only.</b>Every server gate, approved-site check and concealment rule stays where it is.</div>
            </div></div>
            <div class="card card-pad"><div class="cap-row"><h2>What changed from v1</h2><span class="text-caption">Aligned with the P0 fixes on branch claude/friendly-greider-b40f6d (not yet accepted)</span></div><ul style="margin:0;padding-left:18px;font-size:13px;display:grid;gap:3px">
                <li>Competency: restricted is now an organisation setting — Block (“You can’t sign doses as given”) or Co-signer (“Co-signer required”, with Co-signed by / Co-signer password). Areas are Controlled drugs and Covert administration (failed or not seen); insulin is deferred.</li>
                <li>Allergies: read from the medication allergy list and the health profile. New “Possible allergy match” warning for health-profile matches (no severity), or a block if the organisation chooses.</li>
                <li>Recording: a refused save keeps you on Review; refused offline items show an app-wide banner; new “Not confirmed” state for saves with no answer.</li>
                <li>Counts for leads: “Overdue — not recorded”, “Missed”, “{n} overdue”, “All recorded”, “Given of due”, n/a.</li>
                <li>Settings: “Organisation-wide safety rules” card with a confirm dialog and a “Default — not yet reviewed” marker.</li>
                <li>Every row now has the ⋯ button and a right-click menu (also Shift+F10). Time is entered with the approved clock and manual picker. Every outcome has an optional “What happened” note.</li>
            </ul></div>
            <div class="card card-pad"><div class="cap-row"><h2>Legend</h2></div><div style="display:flex;flex-wrap:wrap;gap:14px;align-items:center;font-size:12.5px">
                <span>${dbadge('due')} product status (StatusBadge tokens)</span>
                <span>${NC()} organisation value not decided — product wording</span>
                <span>${dtag('D4')} decision the state depends on — mockup annotation only</span>
                <span>${ftag('EM-24')} review finding it addresses — mockup annotation only</span>
                <span class="annot" style="padding:4px 8px;font-size:11.5px">hatched, dashed = design note, never product UI</span>
            </div></div>`;
    }

    function sbSpecimen(persona) {
        const hubs = visibleHubs(persona);
        const front = isFrontline(persona);
        return `<div class="sb-specimen" aria-label="Sidebar for ${PERSONAS[persona].role}">
            <span class="sb-item">${ic('dashboard')}<span class="sb-label">My Day</span></span>
            <span class="sb-item">${ic('list-todo')}<span class="sb-label">All Tasks</span></span>
            ${front ? `<span class="sb-item" aria-current="page">${ic('pill')}<span class="sb-label">Meds today</span><span class="sb-count">1</span></span>` : `<span class="sb-group on-top">${ic('pill')}<span class="sb-label">Medication</span>${ic('chev-down', 'chev s35')}</span>${hubs.map((h) => `<span class="sb-sub">${h.label}</span>`).join('')}`}
            <span class="sb-group">${ic('stethoscope')}<span class="sb-label">Health & Clinical</span>${ic('chev-right', 'chev s35')}</span>
        </div>`;
    }
    function navSection(H) {
        const hubRows = HUBS.map((h, i) => `<tr><td style="font-weight:600">${i + 1}. ${h.label}</td><td>${h.views.map((v) => `${v.label} <span class="who-sub">${esc(v.url)}</span>`).join('<br>')}</td><td>${Object.values(PERSONAS).filter((p) => h.show(p.id)).map((p) => p.role).join(', ')}</td></tr>`).join('');
        return H('Navigation frame', 'The approved structure (plan §2). Support workers get one sidebar entry; leads, clinical staff, managers and auditors get seven permission-aware hubs whose pages become the hub’s header rail, each keeping its current URL. The label is “Medication”, not “eMAR”. Use the frame view and “Signed in as” to click through it.')
            + `<div class="card card-pad"><div class="cap-row"><h3>Sidebar by role</h3><span class="text-caption">Controlled record/witness alone never promotes a worker to the seven hubs (NF-01)</span></div>
                <div style="display:flex;flex-wrap:wrap;gap:20px">${['sw', 'lead', 'clinical', 'auditor', 'finance'].map((p) => `<div><div class="text-caption" style="margin-bottom:6px;font-weight:600">${PERSONAS[p].role}</div>${sbSpecimen(p)}</div>`).join('')}</div></div>`
            + `<div class="card" style="overflow:hidden"><div class="tbl-wrap"><table class="etable"><caption class="sr-only">Hubs and rail views</caption><thead><tr><th scope="col">Hub (one sidebar entry)</th><th scope="col">Rail views → current URL kept</th><th scope="col">Seen by (mockup roles)</th></tr></thead><tbody>${hubRows}</tbody></table></div></div>`
            + stateCard({ id: 'nav-hub', name: 'Hub page: header rail', spec: `<p style="margin:0">Each hub opens with the Event Horizon header. Its rail views are the sibling registers; the first view the role can open is the landing view. The rail never wraps: extra views collapse into “More”, the active view stays visible and alert counts sum onto “More”.</p>`, link: { href: hrefFrame('lead', 'safety', 'overview'), label: 'Open a hub as the house lead' }, reuse: ['All seven hubs', 'lib/emar-navigation.ts (later)', 'Command search index'], depends: [], fixes: ['NF-01', 'EM-13', 'EM-14'] })
            + stateCard({ id: 'nav-person', name: 'Canonical person medication record', spec: `<p style="margin:0">One record per person at <code>/emar/mar?client_id=…</code>, entered from the client profile, any Meds today row, MAR & medicines, Tasks, incidents and reports. Profile header (back chip, photo or initials, preferred name) and a tier-2 strip: Chart · Medicines · Support plan · Allergies & alerts · Clinical · History.</p>`, link: { href: '#/person/lead/aroha/chart', label: 'Open Aroha’s record' }, reuse: ['Every person-level entry point', 'Client profile Medical › MAR (summary + launch)'], depends: [], fixes: ['EM-13'] })
            + stateCard({ id: 'nav-contextual', name: 'Contextual actions for support workers', spec: `<ul style="margin:0;padding-left:18px"><li>Person MAR — from each row’s name</li><li>Report a medication error — header action</li><li>Shift handover medication lens — header action</li><li>“Why can’t I record this?” — on each blocked row</li><li>Request emergency access — only for emergency-access holders, only from a blocked state, one person at a time</li></ul>`, link: { href: hrefFrame('sw', 'today', 'schedule'), label: 'Open Meds today as a support worker' }, reuse: ['Meds today'], depends: ['D2'], fixes: ['NF-01', 'NF-07'] });
    }

    function leadCounts(zero) {
        if (zero) return `<div class="card card-pad"><p style="margin:0;font-size:13px"><b>0 doses due today across 2 houses</b>, and no doses due yet.</p><div style="margin-top:8px"><span class="chipn">Given of due: <b>n/a</b></span></div></div>`;
        const row = (b, label, n) => `<div style="display:flex;align-items:center;gap:8px;justify-content:space-between;padding:5px 0;border-bottom:1px solid var(--border)">${b}<span class="tabular" style="font-weight:700">${n}</span></div>`;
        return `<div class="card card-pad" style="display:flex;flex-direction:column;gap:10px">
            <p style="margin:0;font-size:13px"><b>18 doses due so far today across 2 houses</b>, and 61% of due doses given so far.</p>
            <div>${row(dbadge('given', true), 'Given', 11)}${row(`<span class="badge b-warning sm">${ic('x')}Refused or withheld</span>`, '', 2)}${row(`<span class="badge b-critical sm">${ic('help')}Overdue — not recorded</span>`, '', 4)}${row(dbadge('missed', true), 'Missed', 1)}</div>
            <div style="display:flex;flex-wrap:wrap;gap:8px"><span class="chipn"><b>Aroha</b> · 3 of 5 recorded</span><span class="badge b-critical sm">1 overdue</span><span class="chipn"><b>Grace</b> · 1 of 1</span><span class="badge b-success sm">${ic('check')}All recorded</span></div>
        </div>`;
    }
    function doseCards() {
        const P = 'Aroha';
        const row = (k, line, extra = '') => `<div style="display:flex;flex-direction:column;gap:4px">${dbadge(k)}<span class="state-line">${line}</span>${extra}</div>`;
        return [
            { id: 'dose-not-yet-due', name: 'Not yet due', spec: row('notdue', 'Due 12:00 pm'), wording: ['Not yet due', 'Due 12:00 pm'], when: 'Before the dose’s approved due window opens. The window is organisation decision D4; until it is set the dialog shows “Due window: Not configured”.', treatment: 'Neutral. No record button on the board; early recording follows the early-dose rule (D4) and records the reason.', reuse: R.dose, depends: ['D4'], fixes: ['EM-24'] },
            { id: 'dose-due', name: 'Due', spec: row('due', 'Due now · 9:00 am'), wording: ['Due', 'Due now · 9:00 am'], when: 'Inside the approved window (D4) with no outcome recorded.', treatment: 'Info (primary tint). Primary “Record” button, 44 px target.', reuse: R.dose, depends: ['D4'], fixes: ['EM-01'], link: { href: hrefFrame('sw', 'today', 'schedule'), label: 'See it on Meds today' } },
            { id: 'dose-late', name: 'Late', spec: row('late', 'Due 8:00 am · 1 h 12 min ago', `<div class="banner warning" style="margin-top:6px"><span class="b-ico">${ic('alert-triangle')}</span><div class="b-body"><div class="b-title">This dose is late</div><div class="b-text">No late-dose instruction is set for this medicine. Check with the on-call contact before giving it: ${NC()}</div></div></div>`), wording: ['Late', 'Due 8:00 am · 1 h 12 min ago', 'This dose is late', 'No late-dose instruction is set for this medicine. Check with the on-call contact before giving it: Not configured'], when: 'After the approved window closes, while the dose can still be acted on this shift. Elapsed time is real time (DST-safe).', treatment: 'Warning (amber). Replaces “Give now if safe, or tell your supervisor”. When a per-medicine late instruction exists (D4), its text is shown instead, with its source.', never: '“Give now if safe”. Calling a dose late one minute after its time when a window is configured.', reuse: R.dose, depends: ['D4', 'D12'], fixes: ['EM-24', 'EM-02'], open: { label: 'Open the late dose dialog', attrs: 'data-act="record" data-row="r2" data-fk="cat-late"' } },
            { id: 'dose-not-yet-recorded', name: 'Not yet recorded', spec: row('notrecorded', '8:00 am dose · no outcome recorded') + `<p class="text-caption" style="margin:6px 0 0">Lead view: “3 not yet recorded” — never “0 due” and never “missed”.</p>`, wording: ['Not yet recorded', '8:00 am dose · no outcome recorded', 'No one has recorded what happened with this dose. It may have been given. Find out before anyone gives it, then record the outcome.'], when: 'A past obligation with no outcome once its window has closed (D4), and in every retrospective lens: handover, previous days, dashboard, reports, exports.', treatment: 'Critical. Counted from the obligation schedule, not from stored rows, so every surface shows the same number.', never: 'Shown as “missed”, blank, or counted as 0 because no row exists.', reuse: R.dose, depends: ['D4'], fixes: ['EM-01', 'EM-18'] },
            { id: 'dose-lead-counts', name: 'Counts for leads (dashboard, client cards, handover)', spec: leadCounts() + leadCounts(true), wording: ['{n} doses due so far today across {houses}, and {n}% of due doses given so far.', '…and no doses due yet.', 'Overdue — not recorded', 'Missed', '{n} overdue', 'All recorded', 'Given of due: n/a'], when: 'Safety & oversight › Overview, client cards, handover and reports.', treatment: 'Counted from the obligation schedule on the NZ day, so the numbers match Meds today (implemented, EM-01 commit 1b9060953). “Overdue — not recorded” is the lead’s total of Late plus Not yet recorded doses; recorded “Missed” is separate. “Given of due” = given ÷ due and reads n/a when nothing is due. A person’s card says “All recorded” only when every due dose has an outcome. The main /dashboard medication widget still uses the old maths (open item).', never: '0 due while doses are late; 0 % or 100 % when nothing is due; a green “complete” with unrecorded doses.', reuse: ['Safety & oversight · Overview (/emar)', 'Client cards', 'Handover medication lens', 'Reports (P09)'], depends: ['D4'], fixes: ['EM-01', 'EM-18'], link: { href: hrefFrame('lead', 'safety', 'overview'), label: 'See it in Safety & oversight' } },
            { id: 'dose-missed', name: 'Confirmed missed', spec: row('missed', 'Recorded by Jordan T. at 11:30 am · Omitted in error'), wording: ['Missed', 'Recorded as missed — the dose was not given and won’t be given now.'], when: 'Only when a person records that the dose was not given and won’t be given, with a reason from the existing not-given list (for example “Omitted in error”).', treatment: 'Critical. A recorded outcome, distinct from “Not yet recorded”.', reuse: R.dose, depends: ['D4', 'D12'], fixes: ['EM-01'] },
            { id: 'dose-given', name: 'Given', spec: row('given', 'Given 1 tablet (500 mg) at 8:05 am · Priya S.', '<span class="state-line">Recorded 8:07 am</span>'), wording: ['Given', 'Given 1 tablet (500 mg) at 8:05 am · Priya S.', 'Recorded 8:07 am'], when: 'Staff gave the medicine (support level Administer) and the server confirmed it. For a variable order the actual amount is recorded, never the prescribed amount by default.', treatment: 'Success. The given time and the recorded time are both kept; detail views show both.', reuse: R.dose, depends: [], fixes: ['EM-08'] },
            { id: 'dose-prompted', name: 'Taken with prompting', spec: row('prompted', 'Taken 9:04 am · Priya S. reminded Aroha'), wording: ['Taken with prompting', 'Taken 9:04 am · Priya S. reminded Aroha'], when: 'The support plan says Prompt for this medicine and the person took it after a reminder.', treatment: 'Success, own icon. Offered instead of “Given” when the support level is Prompt.', reuse: R.dose, depends: ['D6'], fixes: ['EM-04'] },
            { id: 'dose-assisted', name: 'Taken with assistance', spec: row('assisted', 'Taken 9:04 am · Priya S. mixed the sachet'), wording: ['Taken with assistance', 'Taken 9:04 am · Priya S. mixed the sachet'], when: 'The support plan says Assist and staff helped (for example opened a pack); the person took it.', treatment: 'Success. Offered instead of “Given” when the support level is Assist.', reuse: R.dose, depends: ['D6'], fixes: ['EM-04'] },
            { id: 'dose-self-managed', name: 'Self-managed (independent)', spec: row('selfmanaged', 'Sam manages this medicine · nothing to record'), wording: ['Self-managed', 'Sam manages this medicine · nothing to record'], when: 'The support plan says Independent for this medicine.', treatment: 'Neutral. Listed for information. Never counts as due, late, not yet recorded or missed; never forces a refusal or withhold record. If the plan’s review date has passed: “Support plan review date passed on 1 September 2026 — ask the house lead to review.”', never: 'Recorded as “Given” by staff, or shown as overdue.', reuse: R.dose, depends: ['D6'], fixes: ['EM-04'] },
            { id: 'dose-refused', name: 'Refused', spec: row('refused', 'Refused 8:10 am · Grace said no', `<span class="state-line">${ic('flag', 's3')} Follow-up · Priya S. · due 12:00 pm</span>`), wording: ['Refused', 'Refused 8:10 am · Grace said no', 'Grace’s choice is recorded. Offer again only if the re-offer rule allows it: Not configured'], when: 'The person chose not to take it. Their choice is respected and recorded.', treatment: 'Warning (it needs a follow-up), never critical (it isn’t a failure). The follow-up has an owner and a due time entered by the person recording (no default rule until D4/D12).', never: 'Language that blames the person. A promise that “the team leader will review” unless a follow-up with an owner exists.', reuse: R.dose, depends: ['D4', 'D12'], fixes: ['NF-02', 'NF-11'] },
            { id: 'dose-reoffered', name: 'Re-offered, then given', spec: row('reoffered', 'Given 8:40 am after re-offer', `<span class="state-line">${ic('link', 's3')} First offered 8:05 am — refused</span>`), wording: ['Given after re-offer', 'Given 8:40 am after re-offer', 'First offered 8:05 am — refused'], when: 'A refused dose is offered again and taken, within the re-offer rule (D4). Two linked records; the refusal stays in the history.', treatment: 'Success with a link to the first offer. The obligation counts once, as given.', never: 'Rejected as “already recorded”, or forced through a correction.', reuse: R.dose, depends: ['D4'], fixes: ['NF-11'], open: { label: 'Open “Record re-offer”', attrs: 'data-act="fu-reoffer" data-fk="cat-reoffer"' } },
            { id: 'dose-withheld', name: 'Withheld (with reason)', spec: row('withheld', 'Withheld 8:10 am · Vomit or nausea · Priya S.'), wording: ['Withheld', 'Withheld 8:10 am · Vomit or nausea · Priya S.'], when: 'Staff didn’t give it for a reason. A reason from the existing not-given list is required. Always recordable, even while a safety check blocks “given”.', treatment: 'Warning. The reason is part of the line, not hidden in detail.', reuse: R.dose, depends: [], fixes: ['NF-06'] },
            { id: 'dose-away', name: 'Absent / away', spec: row('away', 'Away · social leave · recorded by Jordan T.', '<span class="state-line">Who gives it while away: family (from the stay record)</span>'), wording: ['Away', 'Away · social leave · recorded by Jordan T.', 'Who gives it while away: family (from the stay record)'], when: 'The person is away (social leave, hospital, respite elsewhere). Away doses aren’t counted as late or missed.', treatment: 'Neutral. If nobody recorded who gives it while away: “Who gives it while away: not recorded”.', reuse: R.dose, depends: [], fixes: ['NF-13', 'EM-20'] },
        ].map(stateCard).join('');
    }

    function lifecycleCards() {
        const aw = (warn, html) => `<div class="appwide${warn ? ' warn' : ''}" style="position:static;margin:0;border-radius:10px;border:1px solid">${html}</div>`;
        return [
            { id: 'rec-sending', name: 'Pending server confirmation', spec: `<div style="display:flex;flex-direction:column;gap:6px">${dbadge('sending')}<span class="state-line">Sending — not yet confirmed. Don’t close this window.</span><button class="btn btn-primary btn-sm" type="button" disabled><span class="ring-spin" aria-hidden="true"></span>Sending…</button></div>`, wording: ['Sending…', 'Sending — not yet confirmed. Don’t close this window.'], when: 'From pressing save until the server answers.', treatment: 'Info with the ring-only loader. The submit button is disabled so it can’t be pressed twice. It then becomes Confirmed, Not recorded or Not confirmed — never “Given” without an answer.', reuse: R.record, depends: [], fixes: ['EM-26'] },
            { id: 'rec-confirmed', name: 'Confirmed', spec: `<div class="toast success" style="box-shadow:none">${ic('check-circle')}<div><b>Recorded</b><div>Vitamin D (colecalciferol) for Aroha: taken with prompting at 9:12 am.</div></div></div>${dbadge('prompted')}`, wording: ['Recorded — Vitamin D (colecalciferol) for Aroha: taken with prompting at 9:12 am.'], when: 'The server saved the record. Only now does the row show the outcome.', treatment: 'Success toast plus the row re-read from the server. Focus returns to the row.', reuse: R.record, depends: [], fixes: ['EM-26'] },
            { id: 'rec-rejected', name: 'Rejected while online (not recorded)', spec: `<div class="banner critical"><span class="b-ico">${ic('x-circle')}</span><div class="b-body"><div class="b-title">Not recorded — this dose was not saved.</div><div class="b-text">The as-needed limit on the prescription is reached (4 doses in the last 24 hours, the most recent at 8:05 am). The chart doesn’t show this dose. What you entered is kept.</div></div></div>`, wording: ['Not recorded — this dose was not saved.', '{server reason}', 'The chart doesn’t show this dose. What you entered is kept.'], when: 'The server refused a save made online: limit reached, order awaiting verification, competency, co-signer or witness.', treatment: 'Critical card on the Review step. The dialog stays open with everything kept, so the person can fix the cause or cancel. Implemented for as-needed doses by EM-26 (commit 399a5ab98) as “Not recorded — this PRN dose was not saved.”; v2 asks for “dose” instead of “PRN dose” (plain language) and the chart line.', never: '“PRN administration recorded.” for a refused record, or a success toast.', reuse: R.record, depends: [], fixes: ['EM-26'], open: { label: 'Try it (scenario: server refuses the next save)', attrs: 'data-act="toast" data-msg="Choose Scenario: Server refuses the next save, then record Tama’s late dose."' } },
            { id: 'rec-queued', name: 'Queued offline', spec: `<div style="display:flex;flex-direction:column;gap:6px">${dbadge('queued')}<span class="state-line">Saved on this device · not sent yet</span><div class="banner warning"><span class="b-ico">${ic('wifi-off')}</span><div class="b-body"><div class="b-title">Saved on this device only</div><div class="b-text">It isn’t on Aroha’s chart yet and other staff can’t see it. It will be sent automatically when you reconnect — don’t record it again.</div></div></div></div>`, wording: ['Saved on this device', 'Saved on this device · not sent yet', 'It isn’t on Aroha’s chart yet and other staff can’t see it. It will be sent automatically when you reconnect — don’t record it again.'], when: 'Offline, if offline recording is kept (D7: screen scope decided; offline expectation still open).', treatment: 'Warning. Visible to the person who recorded it until it sends, is refused or needs a check.', reuse: R.record, depends: ['D7', 'D11'], fixes: ['EM-26'], link: { href: hrefFrame('sw', 'today', 'schedule') + '?scenario=offline', label: 'Try the offline scenario' } },
            { id: 'rec-offline-refused', name: 'Offline item refused when sent', spec: aw(false, `${ic('x-circle')}<span>A saved medication action was <b>not</b> recorded: Paracetamol for Aroha, saved offline at 8:50 am — the as-needed limit on the prescription is reached. It won’t be sent again.</span><span class="aw-actions"><button type="button" tabindex="-1">What to do</button><button type="button" tabindex="-1">Dismiss</button></span>`), wording: ['A saved medication action was not recorded: {medicine} for {person}, saved offline at {time} — {server reason}. It won’t be sent again.', 'What to do · Dismiss'], when: 'A record saved on the device is refused when it’s finally sent.', treatment: 'App-wide critical banner under the top bar on every page, until dismissed. The item stays on the device and is never re-sent automatically (implemented, EM-26). v2 asks for “not” in bold rather than “NOT” in capitals, and adds “What to do” (the implemented banner has Dismiss only).', never: 'Deleting the item with a generic toast, or re-sending it silently.', reuse: R.record, depends: ['D7', 'D12'], fixes: ['EM-26'], link: { href: hrefFrame('sw', 'today', 'schedule') + '?scenario=offlineRefused', label: 'Try the refused-offline scenario' } },
            { id: 'rec-uncertain', name: 'Not confirmed (needs a manual check)', spec: `<div style="display:flex;flex-direction:column;gap:6px">${dbadge('uncertain')}<span class="state-line">Sent 9:04 am · no confirmation — check the chart before trying again</span>${aw(true, `${ic('help')}<span>1 saved medication action needs a check: we didn’t get confirmation that Macrogol for Tama (9:04 am) was saved, so it isn’t shown as recorded. Check the chart before trying again.</span><span class="aw-actions"><button type="button" tabindex="-1">Check the chart</button><button type="button" tabindex="-1">Try again</button></span>`)}</div>`, wording: ['Not confirmed', 'We didn’t get confirmation that this was saved, so it isn’t shown as recorded. Check {person}’s chart first. Trying again won’t create a duplicate.'], when: 'A save was sent but no answer came back (lost connection, timeout). It may or may not be saved.', treatment: 'Warning, with Check the chart and Try again. A retry reuses the same request, so it can’t double-record (implemented). v2 replaces the implemented wording “a manual retry will reuse the original request ID” with plain words.', never: 'Showing it as given, or asking the worker to record it again from scratch.', reuse: R.record, depends: ['D7'], fixes: ['EM-26'], link: { href: hrefFrame('sw', 'today', 'schedule') + '?scenario=uncertain', label: 'Try the not-confirmed scenario' } },
            { id: 'rec-corrected', name: 'Corrected (with lineage)', spec: lineage(), white: true, wording: ['Corrected', 'Now: Withheld · Vomit or nausea'], when: 'An approved correction replaced an outcome. The original record is kept and shown struck through.', treatment: 'Neutral badge on the current outcome; lineage in detail views, History and the audit trail. Counts once.', never: 'Turning “not given” into “given” by correction (existing rule) — use “Given after re-offer”.', reuse: ['CorrectionsReviewDialog', 'MedicationEventDrawer', 'Person record · History', 'Audit trail (P09)'], depends: [], fixes: ['NF-11'] },
        ].map(stateCard).join('');
    }

    const SERVER_MSG = {
        restrictedBlock: 'You cannot sign this dose as given — your medication competency assessment is marked restricted (supervised practice until reassessed). Ask a competent colleague to give this dose, or a competency assessor to review the restriction.',
        restrictedCosigner: 'Your medication competency assessment is restricted (supervised practice until reassessed), so a present, qualified co-signer must confirm this dose. Choose a co-signer and ask them to enter their password.',
        areaFailed: 'You cannot sign this dose as given — your medication competency assessment does not show “Controlled drugs” as passed (not passed). Ask a competent colleague to give this dose, or a competency assessor to reassess you.',
        areaNotSeen: 'You cannot sign this dose as given — your medication competency assessment does not show “Covert administration” as passed (not seen at assessment). Ask a competent colleague to give this dose, or a competency assessor to reassess you.',
    };
    const COSIGN_SPEC = `<div class="fgrid"><div class="field"><span class="flabel" id="cs-by">Co-signed by <span class="req">*</span></span><select aria-labelledby="cs-by" aria-invalid="true" aria-describedby="cs-e"><option>Daniel Ahn · on shift</option></select><span class="ferr" id="cs-e">This co-signer cannot confirm the dose — their own medication competency is restricted or not current. Choose another co-signer.</span></div><div class="field"><span class="flabel" id="cs-pw">Co-signer password <span class="req">*</span></span><input aria-labelledby="cs-pw" type="password" value="secret-pw" readonly></div></div>`;
    function blockedCards() {
        const map = [
            ['notClockedIn', 'Aroha', 'Vitamin D'], ['notOnShift', 'Tama', 'Levetiracetam'], ['shiftEnded', 'Aroha', 'Metformin'], ['siteNotApproved', 'Ben', 'Metformin'],
            ['competencyExpired', 'Tama', 'Levetiracetam'], ['exemptionEnded', 'Tama', 'Levetiracetam'], ['restrictedBlock', 'Tama', 'Levetiracetam'], ['restrictedCosigner', 'Tama', 'Levetiracetam'], ['areaFailed', 'Aroha', 'Methylphenidate'], ['areaNotSeen', 'Grace', 'Donepezil'],
            ['noWitness', 'Aroha', 'Methylphenidate'], ['awaitingVerification', 'Mele', 'Omeprazole'], ['covertMissing', 'Grace', 'Donepezil'], ['covertExpired', 'Grace', 'donepezil'],
            ['prnLimit', 'Aroha', 'Paracetamol'], ['safetyAllergy', 'Mele', 'Amoxicillin'], ['safetyAllergyProfile', 'Mele', 'Amoxicillin'], ['safetyContra', 'Mele', 'Amoxicillin'],
        ];
        return map.map(([k, pn, med]) => {
            const b = BLOCKS[k];
            const still = STILL[b.still] !== undefined ? STILL[b.still] : b.still;
            const plain = (s) => tpl(s, { p: pn, med }).replace(/<[^>]+>/g, '').replace(/\s+/g, ' ').trim();
            return stateCard({
                id: `block-${k.replace(/[A-Z]/g, (m) => '-' + m.toLowerCase())}`, name: plain(b.title), spec: blockedPanel(k, { p: pn, med, persona: 'sw' }) + (b.cosigner ? COSIGN_SPEC : ''),
                wording: [plain(b.title), plain(b.text), ...(still ? [plain(still)] : b.stillText ? [plain(b.stillText)] : []), 'Checked again when you save.', ...(SERVER_MSG[k] ? ['If the save is refused (server message, as implemented): ' + SERVER_MSG[k]] : [])],
                next: b.next.map(plain).join(' · ') + (b.action ? ` · Button: “${b.action.label}”.` : '') + (b.breakglass ? ' Emergency-access holders also see a one-person emergency access route; nobody else is told it exists.' : '') + (b.override ? ' People allowed to override safety checks also see an override that needs a reason and is reviewed; nobody else sees it.' : ''),
                treatment: (b.safety ? 'Fixed solid critical safety surface (brand-independent). ' : b.crit ? 'Critical panel (matches the implemented competency notice). ' : b.cosigner ? 'Warning panel; the witness fields become “Co-signed by” and “Co-signer password”. ' : 'Warning panel. ') + `Rule: ${esc(b.gate)}` + (b.note ? ` ${esc(b.note)}` : ''),
                reuse: k === 'noWitness' ? [...R.blocked, 'CD dialogs (P07a)'] : k === 'awaitingVerification' ? [...R.blocked, 'VerifyOrderDialog (P04)'] : R.blocked,
                depends: b.depends, fixes: b.fixes,
                open: { label: 'Open “Why can’t I record this?”', attrs: `data-act="why-cat" data-block="${k}" data-p="${pn}" data-med="${esc(med)}" data-fk="cat-${k}"` },
            });
        }).join('') + stateCard({ id: 'block-emergency-route', name: 'Emergency access route (emergency-access holders only)', spec: `<div class="banner info"><span class="b-ico">${ic('lock')}</span><div class="b-body"><div class="b-title">Request emergency access for Ben</div><div class="b-text">It covers Ben only, lasts a limited time, is recorded and is reviewed afterwards. It never covers a whole round or a house.</div><div class="b-actions"><button class="btn btn-outline btn-sm" type="button" data-act="toast" data-msg="The request dialog is designed in P10.">Request emergency access for Ben</button></div></div></div>`, wording: ['Request emergency access for Ben', 'It covers Ben only, lasts a limited time, is recorded and is reviewed afterwards. It never covers a whole round or a house.'], when: 'Added under “not on your shift” and “access doesn’t include this house” — only for people who hold emergency access.', treatment: 'Info. Everyone else sees only the real route (clock in, ask the coordinator).', reuse: ['Blocked panels', 'Person record redirect (existing)', 'Emergency access (P10)'], depends: ['D2', 'D12'], fixes: ['EM-28', 'NF-12'] });
    }

    const B = (tone, icon, title, text) => `<div class="banner ${tone}"><span class="b-ico">${ic(icon)}</span><div class="b-body"><div class="b-title">${title}</div>${text ? `<div class="b-text">${text}</div>` : ''}</div></div>`;
    const NA = (why) => `<div class="na-state">${ic('ban', 's3')}Not applicable — ${esc(why)}</div>`;
    function stateStrip(rows) {
        return `<div class="sp-states">${rows.map(([name, html]) => `<div class="sp-state"><div class="sp-name">${name}</div><div>${html}</div></div>`).join('')}</div>`;
    }
    const NOBODY = (what) => B('warning', 'users', `No one on this shift can ${what}`, `Nobody else on shift at Kōwhai House has current, unrestricted competency${what.includes('witness') ? ' and controlled-medicine witness competency' : ''}. Contact the coordinator on call: ${NC()} You can still record a refusal, withhold or absence.`);
    function secondOption(use, opt) {
        const cd = use === 'cd';
        const who = cd ? 'witness' : 'co-sign';
        if (opt === 'A') return stateStrip([
            ['What the recorder sees', B('warning', 'users', cd ? 'You can’t record this controlled medicine without a witness' : 'A colleague needs to give this dose', `On shift now and able to ${cd ? 'witness' : 'give it'}: <b>Daniel Ahn</b> (current competency${cd ? ', controlled-medicine witness' : ', not restricted'}). ${cd ? 'Ask Daniel to come to the medicine cupboard.' : 'Ask Daniel to give and record it from their own login.'}`)],
            ['Success', cd ? B('info', 'info', 'Redirect only', 'For controlled drugs, A only shows who can witness. The witness still confirms by B, C or today’s password.') : `<div style="display:flex;flex-direction:column;gap:4px">${dbadge('given')}<span class="state-line">Given 9:20 am · Daniel Ahn</span></div>`],
            ['Declined', NA('no request is sent')], ['Expired', NA('nothing is waiting')], ['Locked', NA('no credential is typed')],
            ['No eligible colleague', NOBODY(cd ? 'witness this dose' : 'give this dose')],
        ]);
        if (opt === 'B') return stateStrip([
            ['What the recorder sees', `<div class="fgrid"><div class="field"><span class="flabel">${cd ? 'Witnessed by' : 'Co-signed by'}</span><select tabindex="-1"><option>Daniel Ahn · on shift</option></select></div><div class="field"><span class="flabel">${cd ? 'Witness' : 'Co-signer'}’s witness PIN</span><input tabindex="-1" type="password" value="1234" readonly><span class="who-sub">Their own PIN — not their login password${cd ? '. Entered here, at the medicine cupboard.' : ''}</span></div></div>`],
            ['Success', B('success', 'check-circle', `${cd ? 'Witnessed' : 'Co-signed'} by Daniel Ahn with their witness PIN at 9:13 am`, '')],
            ['Declined', NA('the colleague simply doesn’t enter their PIN; the recorder cancels or chooses someone else')],
            ['Expired', NA(`the PIN only works while it’s typed here; PIN renewal rule: not configured`)],
            ['Locked', B('critical', 'lock', 'Daniel Ahn’s witness PIN is locked', `Too many wrong attempts (limit: ${NC()}). Daniel can reset it from their own account — My profile › Witness PIN. Choose another ${cd ? 'witness' : 'co-signer'}.`) + `<div class="ferr" style="margin-top:6px">Before locking: “Incorrect PIN. Repeated wrong attempts lock the PIN.”</div>`],
            ['No eligible colleague', NOBODY(cd ? 'witness this dose' : 'co-sign')],
        ]);
        return stateStrip([
            ['What the recorder sees', B('info', 'send', 'Waiting for Daniel Ahn to confirm on their own device', `${cd ? 'Daniel must be with you and watch the dose.' : 'They must be with you.'} The request expires after the organisation’s time limit: ${NC()}`)],
            ['What the colleague sees', `<div class="card card-pad" style="padding:10px 12px"><div style="font-weight:650;font-size:13px">Priya Shah asks you to ${cd ? 'witness' : 'co-sign'} a dose</div><div class="who-sub">Levetiracetam 500 mg for Tama · 9:12 am · Kōwhai House</div><div class="who-sub" style="margin-top:4px">Only approve if you are with Priya now${cd ? ' and watched the dose' : ''}.</div><div style="display:flex;gap:6px;margin-top:8px"><button class="btn btn-outline btn-sm" type="button" tabindex="-1">Decline</button><button class="btn btn-primary btn-sm" type="button" tabindex="-1">Approve</button></div></div>`],
            ['Success', B('success', 'check-circle', `${cd ? 'Witnessed' : 'Confirmed'} by Daniel Ahn at 9:13 am from their own session`, 'Recorded in the audit trail with both sessions.')],
            ['Declined', B('critical', 'x-circle', 'Daniel Ahn declined at 9:13 am', '“I wasn’t there.” Choose someone else, or record it differently.')],
            ['Expired', B('warning', 'clock', 'The request to Daniel Ahn expired at 9:22 am', 'No answer in time. Send it again or choose someone else.')],
            ['Locked', NA('nothing is typed on the recorder’s screen')],
            ['No eligible colleague', NOBODY(cd ? 'witness this dose' : 'co-sign')],
        ]) + (cd ? B('warning', 'alert-triangle', 'Presence isn’t proven by a request alone', `For controlled drugs, C is only acceptable if the witness is clocked in at the same house and confirms they watched. Whether that is enough is part of ${dtag('D8')}.`) : '');
    }
    function secondSection() {
        const table = `<div class="card" style="overflow:hidden"><div class="tbl-wrap"><table class="etable"><caption class="sr-only">Second-person confirmation options</caption><thead><tr><th scope="col">Option</th><th scope="col">How it works</th><th scope="col">What’s typed on the recorder’s screen</th><th scope="col">Proves presence</th><th scope="col">Restricted competency</th><th scope="col">Controlled-drug witness</th><th scope="col">Built today</th></tr></thead><tbody>
            <tr><td><b>Today</b></td><td>Colleague types their login password</td><td>Their login password</td><td>Partly</td><td>Implemented</td><td>Implemented</td><td><span class="badge b-warning sm">Yes — to be replaced</span></td></tr>
            <tr><td><b>A</b> Block and show who can give it</td><td>No second person; the dose goes to an eligible colleague</td><td>Nothing</td><td>—</td><td>Works on its own</td><td>Redirect only</td><td><span class="badge b-neutral sm">No</span></td></tr>
            <tr><td><b>B</b> Personal witness PIN</td><td>Separate PIN, attempt-limited, reset from the person’s own account</td><td>Their PIN (not the login)</td><td>Yes — at the screen</td><td>Works</td><td>Works</td><td><span class="badge b-neutral sm">No</span></td></tr>
            <tr><td><b>C</b> Confirm from their own session</td><td>Request → approve or decline on their device, time-limited, audited</td><td>Nothing</td><td>Not by itself</td><td>Works</td><td>Only with a presence rule (D8)</td><td><span class="badge b-neutral sm">No</span></td></tr>
        </tbody></table></div></div>`;
        const card = (use, opt, title) => stateCard({ wide: true, id: `second-${use}-${opt.toLowerCase()}`, name: title, spec: secondOption(use, opt), white: true, wording: [], when: use === 'cd' ? 'A controlled medicine that needs a witness.' : 'A worker with restricted competency records a dose as given, under the Co-signer rule.', treatment: opt === 'A' ? 'Warning panel listing who on shift is eligible, by name. No credentials at all.' : opt === 'B' ? 'Two fields: colleague and their witness PIN. The PIN is separate from the login, attempt-limited, and reset by its owner from their own account — never by the recorder.' : 'The recorder sends a request; the colleague approves or declines from their own signed-in session. Every request, approval, decline and expiry is audited.', reuse: use === 'cd' ? ['RecordCdEntryDialog', 'BalanceCheckDialog (P07a)', 'Recording dialogs (P01)', 'Destructions (P07b)'] : ['RecordDoseWizard', 'PrnWizard', 'GuidedRoundDialog', 'RecordAdministrationDialog', 'Fleet transport dialogs'], depends: use === 'cd' ? ['D8', 'D2'] : ['D3', 'NF-03 setting'], fixes: use === 'cd' ? ['NF-08', 'EM-03'] : ['NF-03', 'NF-08'], link: use === 'cosign' && opt !== 'A' ? { href: hrefFrame('sw', 'today', 'schedule') + `?scenario=restrictedCosigner&method=${opt}&open=record:r2:1&outcome=given`, label: `Try option ${opt} in the recording dialog` } : use === 'cosign' ? { href: hrefFrame('sw', 'today', 'schedule') + '?scenario=restrictedCosigner&method=A&open=record:r2', label: 'Try option A in the recording dialog' } : null });
        return table
            + `<header><h3 class="text-section-title" style="font-size:15px;margin-top:6px">Restricted competency (co-signer)</h3></header>`
            + card('cosign', 'A', 'A — Block, and show who can give it') + card('cosign', 'B', 'B — Personal witness PIN') + card('cosign', 'C', 'C — Confirm from the colleague’s own session')
            + `<header><h3 class="text-section-title" style="font-size:15px;margin-top:6px">Controlled-drug witness (needs physical presence)</h3></header>`
            + card('cd', 'A', 'A — Block, and show who can witness') + card('cd', 'B', 'B — Witness PIN at the medicine cupboard') + card('cd', 'C', 'C — Confirm from the witness’s own session');
    }
    function allergyCards() {
        return [
            { id: 'allergy-recorded', name: 'Allergies recorded', spec: allergyBanner('recorded'), wording: ['Allergies: Penicillin (severe) · Latex (mild)', 'Check the label against the allergy list before giving.', 'From the medication allergy list and the health profile · reviewed 12 August 2026 by Jordan Tipene.'], when: 'Either source lists allergies. The EM-07 fix reads both the medication allergy list and the health profile.', treatment: 'Fixed solid critical safety surface, first thing under the identity header. A severe match in the medication allergy list blocks “given”; a health-profile match warns or blocks by organisation rule.', reuse: R.allergy, depends: ['D5'], fixes: ['EM-07'] },
            { id: 'allergy-none-recorded', name: 'No allergies recorded', spec: allergyBanner('none'), wording: ['No allergies recorded for Aroha', 'Check the health profile before giving. This doesn’t mean Aroha has none.'], when: 'Both reads succeeded and neither lists an allergy, and nobody has recorded “no known drug allergies”.', treatment: 'Fixed amber warning. The implemented notice currently uses the brand info tint; v2 asks for amber because DESIGN.md non-negotiable #6 keeps safety surfaces brand-independent.', never: '“No known allergies”, a green tick, or a brand-tinted notice for an empty list.', reuse: R.allergy, depends: ['D5'], fixes: ['EM-07'] },
            { id: 'allergy-unavailable', name: 'Allergy record couldn’t be loaded', spec: allergyBanner('unavailable'), wording: ['Allergy record couldn’t be loaded for Aroha', 'Check the health profile before giving. Don’t assume Aroha has none.'], when: 'Either allergy read failed or timed out. Whether recording “given” pauses while allergies can’t be read: Not configured (D5).', treatment: 'Amber warning with Try again (matches the implemented notice).', never: 'An empty list after an error.', reuse: R.allergy, depends: ['D5'], fixes: ['EM-07', 'EM-18'] },
            { id: 'allergy-profile-match', name: 'Health-profile allergy match (warn — the default)', spec: profileMatchWarning(), wording: ['Possible allergy match — check before giving', 'Mele has a recorded allergy to penicillin on the health profile (severity not recorded). Amoxicillin is a penicillin. Check with the prescriber before giving.'], when: 'A medicine matches a health-profile allergy (these have no severity) and the organisation safety rule is Warn, the default. With Block, the dose shows “Allergy match — don’t give” instead (Blocked reasons).', treatment: 'Amber warning before signing; “given” stays available. Replaces the implemented alert text “⚠️ ALLERGY ALERT: Client has a recorded allergy…” — no emoji, no capitals, the person’s preferred name.', reuse: R.allergy, depends: ['D5', 'NF-03 setting'], fixes: ['EM-07'] },
            { wide: true, id: 'allergy-rule-options', name: 'Allergy match rule — the organisation chooses', spec: `<div class="opt-grid">
                <div class="opt"><div class="opt-h"><span class="opt-n">1</span>Warn</div>${profileMatchWarning('Mele', 'Amoxicillin')}<p class="text-caption">“Given” stays available. The match and the warning are saved with the dose.</p></div>
                <div class="opt"><div class="opt-h"><span class="opt-n">2</span>Block</div>${blockedPanel('safetyAllergyProfile', { p: 'Mele', med: 'Amoxicillin' })}<p class="text-caption">Next step: contact the prescriber, or an authorised override with a reason.</p></div>
                <div class="opt"><div class="opt-h"><span class="opt-n">3</span>Block unless the prescriber confirmed it <span class="badge b-info sm">new</span></div>${blockedPanel('allergyNotConfirmed', { p: 'Mele', med: 'Amoxicillin' })}<div style="font-size:12px;font-weight:600;margin:4px 0">Once confirmed, at the point of dosing:</div>${allergyMatchLine()}${allergyConfirmedNote()}</div>
            </div>`, white: true, wording: ['Amoxicillin — matches recorded penicillin allergy (health profile)', 'Possible allergy match — check before giving', 'Allergy match — don’t give', 'Allergy match — the prescriber hasn’t confirmed it', 'The prescriber confirmed this allergy on the order · Dr Lena Chen · 27 September 2026, 2:40 pm · source: signed prescription (uploaded) · entered by Jordan Tipene'], when: 'A medicine matches any recorded allergy. The organisation picks one mode in Settings › Administration rules (Stephan: probably Block, but the prescriber may have prescribed it deliberately).', treatment: 'The specific match line — medicine, allergen, source — shows before signing in every mode, not just the allergy list. Blocks use the fixed solid safety surface. Mode 3 records who confirmed, when and the source, and shows it on the order and at dosing. Built today: modes 1 and 2 for health-profile matches; severe medication-list matches always block; mode 3 is new.', never: 'Only the allergy list with no match line; a silent block with no next step.', reuse: R.allergy, depends: ['D5', 'D2'], fixes: ['EM-07', 'NF-06'], link: { href: hrefFrame('sw', 'today', 'schedule') + '?allergy=confirm&confirmed=0', label: 'Try mode 3 on Meds today (Mele’s amoxicillin)' } },
            { id: 'allergy-confirm-on-order', name: 'Prescriber confirmation on the order (mode 3)', spec: `<div class="medblock"><div class="mb-top"><div class="med-name" style="font-size:14px">Amoxicillin 500 mg capsule · Mele</div><span class="badge b-success sm">Verified</span></div><dl class="kv"><dt>Prescriber</dt><dd>Dr Lena Chen · 27 September 2026</dd><dt>Allergy check</dt><dd>${allergyMatchLine()}</dd></dl></div>${allergyConfirmedNote('order')}`, wording: ['Allergy check: confirmed by the prescriber', 'Dr Lena Chen · 27 September 2026, 2:40 pm · source: signed prescription (uploaded) · entered by Jordan Tipene'], when: 'On the order (Orders & reviews) once someone records the prescriber’s confirmation, and at every dose of that order.', treatment: 'Neutral with a tick: a recorded statement with its source, not a safety pass. Entering it needs order permission; the source document is kept.', reuse: ['Order detail (P04)', 'Recording dialogs (P01)', 'Person record · Medicines (P02)'], depends: ['D5', 'D2'], fixes: ['EM-07'] },
            { id: 'allergy-override', name: 'Authorised override (modes 2 and 3)', spec: overrideSpecimen(), white: true, wording: ['Override the allergy block', 'Reason: Clinical direction · Urgent clinical need · Known record discrepancy · Other', 'Who advised and what they said', 'Overrides are reviewed afterwards by: Not configured'], when: 'Only for people allowed to override safety checks, from a blocked allergy match.', treatment: 'Destructive-tone confirm; reason and note required; the match line stays visible; the override is audited and reviewed. Uses the existing override reasons.', never: 'Offering the override to someone without override rights, or overriding without a reason.', reuse: ['Recording dialogs (P01)', 'Safety & oversight review (P08b)'], depends: ['D2'], fixes: ['NF-09'] },
            { id: 'allergy-nkda', name: 'No known drug allergies (only once D5 approves it)', spec: allergyBanner('nkda'), wording: ['No known drug allergies', 'Recorded on the health profile by Jordan Tipene, 12 August 2026.'], when: 'Only when someone explicitly recorded it on the canonical record, and only after D5 approves this status. Not implemented today.', treatment: 'Neutral with a tick — never green success, because it is a recorded statement, not a safety check.', reuse: R.allergy, depends: ['D5'], fixes: ['EM-07'] },
        ].map(stateCard).join('');
    }

    function qualityCards() {
        const skel = `<div class="card" aria-hidden="true">${[1, 2, 3].map(() => `<div class="dose-row" style="grid-template-columns:1fr 1.4fr 1fr"><div class="who"><span class="disc muted"></span><span class="skel-line" style="width:70px"></span></div><span class="skel-line"></span><span class="skel-line" style="width:60%"></span></div>`).join('')}</div>`;
        return [
            { id: 'q-loading', name: 'Loading (skeleton)', spec: skel, wording: ['Loading today’s doses… (screen readers)'], when: 'While the page or section is loading and its layout is known.', treatment: 'Matching skeleton (skeleton-card-list / skeleton-table) with aria-busy; meters show skeleton blocks. The brand loader is only for boots and unknown layouts.', reuse: R.quality, depends: [], fixes: [], link: { href: hrefFrame('sw', 'today', 'schedule') + '?scenario=loading', label: 'Try the loading scenario' } },
            { id: 'q-empty', name: 'Empty / no work', spec: `<div class="card"><div class="empty"><span class="e-ico">${ic('check-circle', 's6')}</span><h3>Nothing left to record on your shift</h3><p>Everything due so far is recorded, and no one on your shift has more medicines due today. Updated 9:12 am NZDT.</p></div></div>`, wording: ['Nothing left to record on your shift', 'Everything due so far is recorded, and no one on your shift has more medicines due today. Updated 9:12 am NZDT.'], when: 'Only after a successful read that returned no work.', treatment: 'EmptyState with the update time. Never a bare “No data”.', never: '“A quiet register is a good sign.”', reuse: R.quality, depends: [], fixes: ['EM-18'], link: { href: hrefFrame('sw', 'today', 'schedule') + '?scenario=empty', label: 'Try the empty scenario' } },
            { id: 'q-na', name: 'Not applicable (zero denominator)', spec: `<div class="eh-header" style="padding:12px;border-radius:12px"><div class="eh-inner" style="display:flex;flex-direction:row;flex-wrap:wrap;gap:8px">${meter({ label: 'Recorded', big: 'n/a', cap: 'No doses were due in this period', act: 'noop', aria: 'Recorded: not applicable' })}${meter({ label: 'Refusals', big: '0', cap: 'of 12 doses due', act: 'noop' })}</div></div>`, wording: ['n/a', 'No doses were due in this period'], when: 'A rate or share whose denominator is zero.', treatment: '“n/a” with the reason in the caption. A real zero (0 of 12) stays 0.', never: '0 % or 100 % for nothing, or a hard-coded target (“target 95 %”).', reuse: R.quality, depends: [], fixes: ['EM-18', 'EM-01'] },
            { id: 'q-unavailable', name: 'Unavailable / read failed', spec: `<div class="card"><div class="empty error" role="alert"><span class="e-ico">${ic('alert-triangle', 's6')}</span><h3>Couldn’t load today’s doses</h3><p>Some doses may already be recorded. Don’t give anything from memory — try again, or use the printed MAR.</p><div class="e-act"><button class="btn btn-outline btn-sm" type="button" data-act="toast" data-msg="Still unavailable (mockup).">${ic('refresh')}Try again</button><button class="btn btn-ghost btn-sm" type="button" data-act="toast-outside">${ic('printer')}Open print pack</button></div></div></div><div style="display:flex;gap:8px;align-items:center"><span class="sb-specimen" style="width:auto;padding:4px"><span class="sb-item" style="height:30px">${ic('pill')}<span class="sb-label">Meds today</span><span class="sb-count unknown">?</span></span></span><span class="text-caption">Sidebar count: “?” (count unavailable)</span></div>`, wording: ['Couldn’t load today’s doses', 'Some doses may already be recorded. Don’t give anything from memory — try again, or use the printed MAR.', 'Unavailable (meters)', '? (sidebar badge, “count unavailable”)'], when: 'Any read the page depends on failed. If recorded outcomes can’t be read, dose rows are withheld so given doses never look due again.', treatment: 'ErrorState with Try again and the print pack (D11). Meter blocks show “—” with “Unavailable”.', never: 'Empty lists, 0 counts or a hidden badge after a failure.', reuse: R.quality, depends: ['D11'], fixes: ['EM-18'], link: { href: hrefFrame('sw', 'today', 'schedule') + '?scenario=unavailable', label: 'Try the couldn’t-load scenario' } },
            { id: 'q-stale', name: 'Stale (refresh time and timezone)', spec: `<div class="banner warning"><span class="b-ico">${ic('alert-triangle')}</span><div class="b-body"><div class="b-title">Not updated since 8:40 am NZDT (32 min ago)</div><div class="b-text">We couldn’t refresh. What you see may be out of date — someone may have recorded a dose since.</div><div class="b-actions"><button class="btn btn-outline btn-sm" type="button" data-act="toast" data-msg="Refreshed (mockup).">${ic('refresh')}Refresh now</button></div></div></div><div class="eh-header" style="padding:10px;border-radius:12px"><span class="fchip" style="position:relative;z-index:1">${ic('refresh', 's3')}Updated 9:12 am NZDT</span></div>`, wording: ['Updated 9:12 am NZDT (always visible)', 'Not updated since 8:40 am NZDT (32 min ago)', 'We couldn’t refresh. What you see may be out of date — someone may have recorded a dose since.'], when: 'The page tried to refresh and couldn’t. Triggered by a failed refresh, not by a made-up age limit.', treatment: 'Warning banner plus the header chip turning amber. Always clock time with zone, relative time in brackets.', reuse: R.quality, depends: [], fixes: ['EM-02', 'EM-18'], link: { href: hrefFrame('sw', 'today', 'schedule') + '?scenario=stale', label: 'Try the out-of-date scenario' } },
            { id: 'q-no-access', name: 'No access (page)', spec: `<div class="card"><div class="empty"><span class="e-ico">${ic('lock', 's6')}</span><h3>You don’t have access to Stock &amp; controlled drugs</h3><p>Ask your manager if you need it for your work.</p></div></div>`, wording: ['You don’t have access to Stock & controlled drugs', 'Ask your manager if you need it for your work.'], when: 'A module page or hub view the role can’t open (403). It names the page only.', treatment: 'EmptyState with lock icon and a way back.', reuse: R.quality, depends: [], fixes: ['NF-07'], link: { href: hrefFrame('auditor', 'stock', 'register'), label: 'Open as the auditor' } },
            { id: 'q-not-found', name: 'Not found / concealed record', spec: notFound('#/catalogue/quality', 'Go back'), wording: ['We can’t show this record', 'It may not exist, or it may not be available to you. Check the link, or go back.'], when: 'A record that doesn’t exist, a controlled record opened without controlled access, or a person outside your approved houses. All three look identical.', treatment: 'Same page, same status (404) for all three — no existence leak.', never: '“You don’t have permission to view this controlled drug entry.”', reuse: R.quality, depends: ['D9'], fixes: ['EM-12'], link: { href: '#/record/clinical/cd-114', label: 'Open a CD link as the clinical lead' } },
        ].map(stateCard).join('');
    }

    function cdCards() {
        const withCd = `<div class="card" style="overflow:hidden">${doseRow('r9', 'lead')}${doseRow('r10', 'lead')}</div>`;
        const withoutCd = `<div class="card" style="overflow:hidden">${doseRow('r9', 'clinical')}</div><p class="text-caption" style="margin:0">Showing medicines your role can see.</p>`;
        return [
            { id: 'cd-visible', name: 'With controlled-medicine access', spec: withCd, wording: ['Controlled', 'Due 12:00 pm · needs a witness'], when: 'The role holds controlled-medicine view.', treatment: 'Neutral “Controlled” chip with shield; witness requirement in the line.', reuse: R.cd, depends: ['D8'], fixes: [] },
            { id: 'cd-hidden-list', name: 'Without access: lists', spec: withoutCd, wording: ['Showing medicines your role can see.'], when: 'Every medication list opened by a role without controlled-medicine view — whether or not anything is hidden, so the caption itself reveals nothing.', treatment: 'The row is absent. Meter blocks and chart rows for it are absent too.', never: 'A “1 hidden item” placeholder, a lock row, a greyed row or a count that includes it.', reuse: R.cd, depends: ['D9'], fixes: ['EM-12'], link: { href: '#/person/clinical/aroha/medicines', label: 'Open Aroha’s medicines as the clinical lead' } },
            { id: 'cd-hidden-totals', name: 'Without access: totals and reports', spec: `<div class="eh-header" style="padding:12px;border-radius:12px"><div class="eh-inner" style="display:flex;flex-direction:row;flex-wrap:wrap;gap:8px">${meter({ label: 'Doses today', big: '11', cap: 'Totals exclude medicines your role can’t see', act: 'noop' })}</div></div>`, wording: ['Totals exclude medicines your role can’t see.'], when: 'Any count, rate, chart or export for a role without controlled-medicine view. Small-number suppression for controlled aggregates is D9.', treatment: 'Constant caption on every aggregate for those roles.', reuse: R.cd, depends: ['D9'], fixes: ['EM-12'] },
            { id: 'cd-hidden-search', name: 'Without access: Tasks, search, exports', spec: `<div class="card card-pad"><div class="text-caption" style="margin-bottom:6px">All Tasks · “medication error” · 2 results</div><div class="who-sub">• Missed-dose question — Tama · Kōwhai House</div><div class="who-sub">• Wrong time recorded — Grace · Kōwhai House</div></div>`, wording: ['(no wording — the item simply isn’t there)'], when: 'All Tasks list, stats, detail, lookup (global search), CSV export and watch.', treatment: 'The Tasks provider applies the same concealment as the register: no title, no snippet, no count, no link.', never: 'A task linking to a register that then hides the row (a dead end).', reuse: R.cd, depends: ['D9'], fixes: ['EM-12'] },
            { id: 'cd-direct-link', name: 'Without access: direct link', spec: notFound('#/catalogue/controlled', 'Go back'), wording: ['We can’t show this record', 'It may not exist, or it may not be available to you. Check the link, or go back.'], when: 'A direct link to a controlled record opened by a role without controlled-medicine view.', treatment: 'Identical to “not found” (404).', reuse: R.cd, depends: ['D9'], fixes: ['EM-12'], link: { href: '#/record/auditor/cd-114', label: 'Open the link as the auditor' } },
        ].map(stateCard).join('');
    }

    function fuCards() {
        const box = (k) => `<div class="card" style="overflow:hidden">${followUpRow(FU[k])}</div>`;
        return [
            { id: 'fu-due', name: 'Due (owner and due time)', spec: box('due'), wording: ['Due 9:35 am', 'Due in 23 min · time entered by Priya S.'], when: 'Open, before its due time. Due times are entered when the follow-up is created; no default interval exists until D4/D12.', treatment: 'Info badge; owner with photo or initials; source link to the dose.', reuse: R.fu, depends: ['D4', 'D12'], fixes: ['NF-02', 'EM-05'], link: { href: hrefFrame('sw', 'today', 'followups'), label: 'Open Follow-ups on Meds today' } },
            { id: 'fu-overdue-midnight', name: 'Overdue across midnight and shift change', spec: box('overdue'), wording: ['Overdue', 'Was due 11:30 pm Sunday (9 h 42 min ago)', 'Carried over from the night shift', 'From Mere Kahu at the 7:00 am handover'], when: 'Past due and still open. It survives midnight and the handover; ownership moves with a visible trail.', treatment: 'Critical. Weekday shown because it isn’t today.', never: 'Dropping off the list at midnight or at shift change.', reuse: R.fu, depends: ['D12'], fixes: ['EM-05', 'EM-21'] },
            { id: 'fu-escalated', name: 'Escalated, no acknowledgement', spec: box('escalated'), wording: ['Escalated — not acknowledged', 'Escalated to Jordan T. at 8:40 am · no acknowledgement after 32 min', 'Next contact: Not configured'], when: 'Someone escalated and the recipient hasn’t acknowledged. Delivery isn’t acknowledgement; acknowledgement isn’t a result.', treatment: 'Critical. The next contact comes from D12; until then it reads “Not configured”.', reuse: R.fu, depends: ['D12'], fixes: ['EM-06', 'EM-22'] },
            { id: 'fu-no-owner', name: 'No owner', spec: box('noowner'), wording: ['No owner', 'Assign someone'], when: 'A follow-up without an owner (for example an unassigned handover item).', treatment: 'Critical, with Assign. Every open item has an owner or this visible exception.', reuse: R.fu, depends: ['D12'], fixes: ['NF-02', 'EM-21'] },
            { id: 'fu-unable', name: 'Unable to assess', spec: box('unable'), wording: ['Unable to assess', 'Sam was asleep at 9:00 am · check again due 10:00 am'], when: 'The check couldn’t be done. The result choices have no default (“Helped” is never preselected).', treatment: 'Neutral; stays open with a new due time.', reuse: R.fu, depends: ['D4'], fixes: ['EM-06'] },
            { id: 'fu-completed-late', name: 'Completed late', spec: box('late'), wording: ['Completed late', 'Completed 7:40 am · due 6:45 am (55 min late)', 'Result: Helped'], when: 'Closed with a result after its due time.', treatment: 'Warning badge; the lateness stays in the record and reports.', reuse: R.fu, depends: [], fixes: ['EM-22'] },
        ].map(stateCard).join('');
    }

    function idCards() {
        return [
            { id: 'id-full', name: 'Full header (photo held)', spec: identityHeader('aroha', { support: 'prompt' }) + allergyBanner('recorded', 'Aroha', true), white: true, wording: ['Aroha', 'Legal name: Aroha Mere Ngata', 'Compare with the photo on file.', 'Prompt · Staff remind, the person takes it · from the support plan, reviewed 12 January 2026'], when: 'Top of step 1 of every recording dialog, and pinned above the outcome on later steps.', treatment: 'Preferred name largest; legal name, house, date of birth and NHI as facts. The allergy status sits directly underneath.', never: 'Room or bed as identity. Initials only.', reuse: R.id, depends: ['D6'], fixes: ['EM-30', 'EM-25'], open: { label: 'Open it in a recording dialog', attrs: 'data-act="record" data-row="r6" data-fk="cat-id"' } },
            { id: 'id-no-photo', name: 'No photo on file', spec: identityHeader('tama', { support: 'administer' }), white: true, wording: ['No photo on file. Check identity using: Not configured'], when: 'No photo is held.', treatment: 'Initials disc and an honest line; the approved identification method comes from D2.', never: '“Photo + NHI match” when there is no photo.', reuse: R.id, depends: ['D2'], fixes: ['EM-30'] },
            { id: 'id-compact', name: 'Compact (simple dialogs, rows)', spec: identityHeader('grace', { compact: true, support: 'prompt' }), white: true, wording: ['Grace · Grace Liu · Kōwhai House · No photo on file'], when: 'Simple dialogs (follow-ups, witness, blocked explanations).', treatment: 'Same order, one line.', reuse: R.id, depends: [], fixes: [] },
            { id: 'id-support-unknown', name: 'Support level not recorded', spec: identityHeader('mele', { support: 'unknown' }), white: true, wording: ['Not recorded', 'Support for this medicine isn’t in the support plan — ask the house lead.'], when: 'The support plan has no entry for this medicine, or has expired.', treatment: '“Not recorded” chip; outcome choices fall back to the full set, and the gap is flagged to the house lead.', reuse: R.id, depends: ['D6'], fixes: ['EM-04'] },
        ].map(stateCard).join('');
    }

    function timeSection() {
        const rules = [
            ['Local time, zone visible', 'Every medication page says “times in NZDT (Pacific/Auckland)” once in its header — NZST in winter. Server time and UTC are never shown.'],
            ['Clock format', '“8:05 am” — 12-hour, lower-case am/pm, no leading zero. Headers use “Monday 28 September 2026”; dense rows “Mon 28 Sep”. Exports use “28/09/2026 8:05 am NZDT”.'],
            ['Relative time always has a clock time', '“32 min ago (8:40 am)” or “Due 8:00 am · 1 h 12 min ago”. Never a relative time alone.'],
            ['Today means the house’s calendar day', '12:00 am to 11:59 pm local, including the 23-hour and 25-hour days when clocks change.'],
            ['Given time and recorded time', 'Both are kept. Detail views show “Given 8:05 am · recorded 8:40 am” whenever they differ.'],
            ['Across midnight', 'Anything from a previous day shows its weekday: “Due 11:30 pm Sunday”.'],
            ['Elapsed time is real time', 'Intervals (“last dose 6 h ago”) count real hours, so the day the clocks change shows the true gap.'],
            ['Daylight saving start', 'Sunday 27 September 2026: 2:00–2:59 am doesn’t exist. A dose scheduled then says so; how it is rescheduled is D4.'],
            ['Daylight saving end', 'Sunday 4 April 2027: 2:00–2:59 am happens twice. Times in that hour always show the zone: “2:30 am NZDT”, then “2:30 am NZST”.'],
        ];
        return `<div class="rules">${rules.map(([t, d]) => `<div class="card rule"><b>${t}</b>${d}</div>`).join('')}</div>` + [
            { id: 'time-entry', name: 'Time entry in recording dialogs (clock and manual)', spec: `<div class="tp-wrap" style="min-height:430px"><button class="time-trigger" type="button" tabindex="-1" aria-hidden="true">${ic('clock')}<span>9:12 am</span><span class="tz">NZDT</span></button>${timePicker({ h: 9, m: 12, ap: 'am', mode: 'clock', face: 'minutes', hText: '9', mText: '12', err: null }, 'Time given', true)}</div>`, white: true, wording: ['Time given', 'Clock · Type time', 'Pick the hour, then the minutes. Type any exact minute.', 'Enter an hour from 1 to 12.', 'Enter minutes from 00 to 59.', 'NZDT · Pacific/Auckland', 'Use time · Cancel'], when: 'Every recorded time: given or taken time, effect-check time, witness time, correction time. The date is the dose’s own day and is shown, not picked, unless a package’s workflow needs another day.', treatment: 'The approved clock and manual time pattern (POPUP_STYLE_GUIDE, 20 September 2026): clock faces for hours then minutes, editable digits, AM/PM, a local draft applied with Use time; Cancel or Escape keeps the previous time and returns focus to the trigger; Enter applies typed values; arrow keys adjust; exact minutes are never rounded; the zone is always visible. Canonical value stays HH:mm (for example 09:12).', never: 'A plain text box for a time, rounding to five minutes, or a time without its zone.', reuse: R.time, depends: ['D4'], fixes: ['EM-02', 'EM-08'], link: { href: hrefFrame('sw', 'today', 'schedule') + '?open=record:r6:1&outcome=prompted&tp=clock', label: 'Open it in a recording dialog' } },
            { id: 'time-header', name: 'Header zone and update time', spec: `<div class="eh-header" style="padding:12px 14px;border-radius:12px"><div class="eh-inner"><p class="eh-sub" style="margin:0">Kōwhai House · your shift 7:00 am–3:00 pm · times in NZDT (Pacific/Auckland)</p><div style="margin-top:8px"><button type="button" class="fchip" data-act="toast" data-msg="Filter menus are designed with each page package (mockup).">${ic('refresh', 's3')}Updated 9:12 am NZDT</button></div></div></div>`, wording: ['times in NZDT (Pacific/Auckland)', 'Updated 9:12 am NZDT'], when: 'Every medication page header.', reuse: R.time, depends: [], fixes: ['EM-02'] },
            { id: 'time-dst-start', name: 'Dose in the skipped hour (DST start)', spec: `<div style="display:flex;flex-direction:column;gap:6px">${dbadge('due')}<span class="state-line">Scheduled 2:30 am · Sunday 27 September 2026</span><div class="banner warning"><span class="b-ico">${ic('clock')}</span><div class="b-body"><div class="b-title">This time doesn’t exist today</div><div class="b-text">Clocks went forward at 2:00 am, so 2:30 am was skipped. How this dose is rescheduled: ${NC()}</div></div></div></div>`, wording: ['This time doesn’t exist today', 'Clocks went forward at 2:00 am, so 2:30 am was skipped. How this dose is rescheduled: Not configured'], when: 'A scheduled time falls in the skipped hour.', treatment: 'Warning; never silently moved.', reuse: R.time, depends: ['D4'], fixes: ['EM-02'] },
            { id: 'time-dst-end', name: 'Repeated hour (DST end)', spec: `<div class="card" style="overflow:hidden"><div class="dose-row" style="grid-template-columns:1fr 1fr"><span>${dbadge('given', true)} 2:30 am NZDT</span><span class="state-line">first 2:30 am</span></div><div class="dose-row" style="grid-template-columns:1fr 1fr"><span>${dbadge('given', true)} 2:30 am NZST</span><span class="state-line">second 2:30 am, one hour later</span></div></div>`, wording: ['2:30 am NZDT', '2:30 am NZST'], when: 'Any time inside the repeated hour on Sunday 4 April 2027.', treatment: 'The zone abbreviation is always shown in that hour, in rows, detail and exports.', reuse: R.time, depends: [], fixes: ['EM-02'] },
            { id: 'time-interval', name: 'Interval across a clock change', spec: `<div style="display:flex;flex-direction:column;gap:4px"><span class="state-line"><b>Last given 1:30 am NZST · 6 h ago</b></span><span class="state-line">Now 8:30 am NZDT · clocks went forward overnight</span></div>`, wording: ['Last given 1:30 am NZST · 6 h ago', 'clocks went forward overnight'], when: 'As-needed history, late doses and effect checks on a clock-change day.', treatment: 'Real elapsed hours (6), not wall-clock difference (7).', reuse: R.time, depends: ['D4'], fixes: ['EM-02'] },
        ].map(stateCard).join('');
    }

    function universalCards() {
        return [
            { id: 'u-row-actions', name: 'Row actions: ⋯ button and right-click menu', spec: `<div class="card" style="overflow:hidden">${doseRow('r3', 'sw')}</div><div class="rail-more-pop ctx-menu" role="menu" aria-label="Actions (specimen)" style="position:static;align-self:flex-end" inert>${menuHtml(menuItems('dose', 'r3', 'sw'), 'spec')}</div>`, wording: ['Record as given — 🔒 Allergy match — don’t give', 'Record not given', 'Why can’t I record this?', 'Open Mele’s medication record', 'Report a medication error', 'Add to shift handover'], when: 'Every dose row, follow-up row and table row (people, medicines).', treatment: 'The ⋯ button (last in the row) and right-click open the same list; Shift+F10 or the menu key opens it from the keyboard; arrows move, Escape closes and returns focus. Blocked actions stay listed, disabled, with the reason underneath — never silently missing. Actions with no backend are left out (LIST_STYLE_GUIDE §1; DESIGN.md “Dropping approved context actions”).', never: 'Only one of the two entry points, or different items in each.', reuse: ['Meds today rows', 'Follow-up rows', 'MAR & medicines tables', 'Person record tables', 'Every later package list'], depends: [], fixes: ['NF-07'] },
            { id: 'u-offline', name: 'Offline banner', spec: `<div class="banner warning"><span class="b-ico">${ic('wifi-off')}</span><div class="b-body"><div class="b-title">You’re offline</div><div class="b-text">Records you make now are saved on this device only and sent when you reconnect. Other staff can’t see them yet.</div></div></div><div class="banner neutral"><span class="b-ico">${ic('wifi-off')}</span><div class="b-body"><div class="b-title">You’re offline — recording is paused</div><div class="b-text">Reconnect to record. If you need to record now, use the print pack.</div></div></div>`, wording: ['You’re offline', 'Records you make now are saved on this device only and sent when you reconnect. Other staff can’t see them yet.', 'You’re offline — recording is paused', 'Reconnect to record. If you need to record now, use the print pack.'], when: 'Connection lost. Which variant ships depends on the offline expectation in D7.', reuse: ['Every page (P01–P11)'], depends: ['D7', 'D11'], fixes: ['EM-26'] },
            { id: 'u-conflict', name: 'Conflict: order changed', spec: `<div class="banner warning"><span class="b-ico">${ic('refresh')}</span><div class="b-body"><div class="b-title">This order changed while you were recording</div><div class="b-text">Jordan Tipene changed the dose at 9:05 am. Check the new instructions before you record. What you entered is kept.</div><div class="b-actions"><button class="btn btn-outline btn-sm" type="button" data-act="toast" data-msg="Changes shown (mockup).">Show what changed</button><button class="btn btn-primary btn-sm" type="button" data-act="toast" data-msg="Reloaded with the new order (mockup).">Use the new order</button></div></div></div>`, wording: ['This order changed while you were recording', 'Jordan Tipene changed the dose at 9:05 am. Check the new instructions before you record. What you entered is kept.'], when: 'The order version changed between opening and saving.', reuse: [...R.record], depends: [], fixes: ['EM-23'] },
            { id: 'u-duplicate', name: 'Already recorded', spec: `<div class="banner neutral"><span class="b-ico">${ic('info')}</span><div class="b-body"><div class="b-title">Already recorded</div><div class="b-text">Daniel Ahn recorded this dose as given at 8:03 am. Nothing was changed.</div></div></div>`, wording: ['Already recorded', 'Daniel Ahn recorded this dose as given at 8:03 am. Nothing was changed.'], when: 'Someone else recorded the same dose first (duplicate-safe server guard).', reuse: [...R.record], depends: [], fixes: ['NF-11'] },
            { id: 'u-validation', name: 'Validation with values kept', spec: `<div class="field"><span class="flabel" id="u-r-l">Reason for withholding <span class="req">*</span></span><select aria-labelledby="u-r-l" aria-invalid="true"><option>Choose a reason</option></select><span class="ferr">Choose a reason for withholding.</span></div><div class="field"><label for="u-note">Note</label><textarea id="u-note" rows="2">Grace felt sick after breakfast.</textarea></div>`, wording: ['Choose a reason for withholding.'], when: 'A required field is missing on Continue or save.', treatment: 'Inline under the field, focus moves to the first error, everything else stays filled.', never: 'Validation in a toast.', reuse: ['Every dialog'], depends: [], fixes: ['EM-29'] },
            { id: 'u-resume', name: 'Interruption and resume', spec: `<div class="banner info"><span class="b-ico">${ic('history')}</span><div class="b-body"><div class="b-title">You have an unfinished record for Tama</div><div class="b-text">Started 8:52 am — levetiracetam. Nothing has been saved yet.</div><div class="b-actions"><button class="btn btn-primary btn-sm" type="button" data-act="record" data-row="r2" data-fk="cat-resume">Continue</button><button class="btn btn-ghost btn-sm" type="button" data-act="toast" data-msg="Draft discarded (mockup).">Discard</button></div></div></div>`, wording: ['You have an unfinished record for Tama', 'Started 8:52 am — levetiracetam. Nothing has been saved yet.'], when: 'A recording dialog was closed or interrupted before saving.', reuse: [...R.record], depends: [], fixes: ['EM-29'] },
            { id: 'u-focus', name: 'Focus return and keyboard path', spec: `<ul style="margin:0;padding-left:18px"><li>Tab order: viewer → top bar → sidebar → breadcrumbs → header (search, actions, meters, filters, rail) → content.</li><li>Dialogs trap focus, close on Escape and return focus to the button that opened them.</li><li>Rail tabs, meter blocks and tiles are links or buttons with visible focus rings; tiles use aria-pressed.</li><li>Every status has text and an icon, never colour alone.</li></ul>`, wording: [], when: 'Always. Checked at 1440, 1280 and 200 % zoom.', reuse: ['Every package'], depends: [], fixes: ['EM-29'], open: { label: 'Try it: open and close a dialog', attrs: 'data-act="record" data-row="r6" data-fk="cat-focus"' } },
        ].map(stateCard).join('');
    }

    const DECISIONS = [
        ['D1', 'Service classification per site (certified residential / supported living / respite) and which standards apply', 'Provider manager / quality', 'Needs decision', 'No state wording depends on it yet; no regulator is named anywhere.'],
        ['D2', 'Who may prompt, assist, administer, witness, verify, override; relief and agency staff; recording after clock-out; identification method', 'Clinical governance + operations', 'Needs decision', 'Blocked reasons (shift, site, verification, shift ended), override visibility, identity check line.'],
        ['D3', 'Competency model: areas, pass mark, restriction meaning, per-task authority, exemption limits. The NF-03 fix added settings (restricted: Off / Block / Co-signer; controlled-drug and covert areas: Off / failed / failed or not seen), all defaulting to Off.', 'Clinical governance / L&D', 'Needs decision (NF-03 value: Stephan)', 'Competency blocks, co-signer, area not passed, exemption ended, My eligibility.'],
        ['D4', 'Timing rules: due window, late/early, time-critical, PRN interval and amount counting, re-offer, DST rescheduling', 'Prescriber/pharmacist advice + clinical governance', 'Needs decision', 'Not yet due / due / late boundary, not yet recorded, re-offer, PRN limit, DST start, follow-up defaults.'],
        ['D5', 'Allergy source of truth and status vocabulary. The EM-07 fix now reads both the medication allergy list and the health profile; a health-profile match warns by default (setting: Warn / Block).', 'Health & Clinical owner', 'Needs decision', 'All allergy states, health-profile match; “No known drug allergies” hidden until approved.'],
        ['D6', 'Support levels per medicine (independent / prompt / assist / administer), consent, review triggers', 'Care planning + clinical', 'Needs decision', 'Self-managed, taken with prompting/assistance, support chip, support not recorded.'],
        ['D7', 'Viewport and device scope; offline expectation', 'Stephan', 'Decided 28 Sep 2026: desktop web only. Offline expectation still open', 'Queued offline vs recording paused.'],
        ['D8', 'Controlled drugs in supported living: register, count cadence, witness credential, destruction', 'Clinical governance + pharmacy', 'Needs decision', 'No eligible witness, controlled checks cadence.'],
        ['D9', 'Controlled-medicine need-to-know: roles, aggregates, small-number suppression', 'Privacy officer + clinical governance', 'Needs decision', 'Concealment captions, totals, Tasks/search.'],
        ['D10', 'Stock model: lots, person-owned supply, balance meaning', 'Operations + pharmacy', 'Needs decision', 'Not used in P00 (P06).'],
        ['D11', 'Downtime and paper recording; reconciliation on return', 'Operations + IT', 'Needs decision', 'Print pack link in unavailable, stale and offline states.'],
        ['D12', 'Escalation contacts and acknowledgement (replaces the hard-coded “on-call nurse”)', 'Operations', 'Needs decision', '“On-call contact: Not configured”, escalation next contact, coordinator on call.'],
        ['D13*', 'Proposed: covert administration process (listed as organisation policy but not numbered)', 'Clinical governance', 'Proposed — not yet on the decision list', 'Covert plan missing/expired blocks.'],
    ];
    function decisionsSection() {
        return stateCard({ id: 'org-setting-default', name: 'Organisation setting still on its default', spec: safetyRulesCard(false, true), white: true, wording: ['Default — not yet reviewed', 'Set by {name}, {date} {time}', 'Only someone who manages medication settings for all sites can change these rules.', 'Change the medication safety rules? · From the next dose signed, at every site: …'], when: 'A setting exists (for example the safety rules the NF-03 fix added) but nobody has deliberately chosen a value, so it still carries the code default (the previous behaviour).', treatment: 'The value shows with a dashed “Default — not yet reviewed” chip until someone saves a choice; then “Set by …”. Saving goes through a confirm dialog that states the effect. Read-only for people without all-sites authority.', never: 'A code default presented as approved policy.', reuse: ['Settings · Administration rules (P11)', 'Every future organisation setting'], depends: ['D3', 'D5', 'NF-03 setting'], fixes: ['NF-03', 'EM-17'], link: { href: hrefFrame('clinical', 'settings', 'rules'), label: 'Open Settings as the clinical lead' } })
            + stateCard({ id: 'not-configured', name: 'The “Not configured” pattern', spec: `<div style="display:flex;flex-direction:column;gap:8px"><span>On-call contact: ${NC()}</span><span>Late-dose instruction: ${NC()}</span><span>Support for this medicine: ${NC('Not recorded')}</span></div>`, wording: ['Not configured', 'Not recorded (a missing fact on a record, not a policy)'], when: 'An organisation value nobody has approved, or a record fact that is missing.', treatment: 'Neutral dashed chip with a settings icon. It fails closed: where safety depends on the value, the screen gives no instruction instead of a default. Settings managers will be able to set it once the setting exists (P11); nobody sees a “Set it” button before then.', never: 'A hard-coded default (“on-call nurse”, 95 % target, 120 min, 10/12 pass mark) shown as if approved.', reuse: ['Every package'], depends: [], fixes: ['EM-17'] })
            + `<div class="card" style="overflow:hidden"><div class="tbl-wrap"><table class="etable"><caption class="sr-only">Organisation decisions</caption><thead><tr><th scope="col">#</th><th scope="col">Decision</th><th scope="col">Suggested owner</th><th scope="col">Status</th><th scope="col">P00 states that wait on it</th></tr></thead><tbody>
                ${DECISIONS.map(([d, t, o, s, st]) => `<tr id="dec-${d.replace('*', '')}"><td>${dtag(d)}</td><td>${esc(t)}</td><td>${esc(o)}</td><td class="dec-status">${s.startsWith('Decided') ? `<span class="badge b-success sm">${esc(s)}</span>` : s.startsWith('Proposed') ? `<span class="badge b-info sm">${esc(s)}</span>` : `<span class="badge b-warning sm">${esc(s)}</span>`}</td><td>${esc(st)}</td></tr>`).join('')}
            </tbody></table></div></div>
            <div class="card card-pad"><div class="cap-row"><h3>Other open items reported by the P0 fix session</h3></div><ul style="margin:0;padding-left:18px;font-size:13px"><li>Co-signer picker on the guided round, client-profile MAR, shift card and transport: today those show only the server refusal (P01).</li><li>Enforcing “can administer unsupervised” and the insulin area: deferred (needs a data review and a medicine classification).</li><li>The main /dashboard medication widget still uses the old admin-rate maths.</li></ul></div>`;
    }

    function reuseSection() {
        const pk = ['P01', 'P02', 'P08a', 'P07a', 'P03', 'P04', 'P06', 'P07b', 'P05', 'P08b', 'P11', 'P09', 'P10'];
        const fam = [
            ['Dose obligations & outcomes', ['P01', 'P02', 'P08a', 'P03', 'P09', 'P10']],
            ['Recording lifecycle', ['P01', 'P02', 'P08a', 'P07a', 'P06', 'P07b', 'P10']],
            ['Blocked reasons', ['P01', 'P07a', 'P03', 'P04', 'P11', 'P10']],
            ['Allergy status', ['P01', 'P02', 'P04', 'P05']],
            ['Data quality', pk],
            ['Controlled-medicine concealment', ['P02', 'P07a', 'P07b', 'P08b', 'P09', 'P06']],
            ['Follow-ups', ['P08a', 'P05', 'P08b', 'P01']],
            ['Person identity header', ['P01', 'P07a', 'P08a', 'P03', 'P10', 'P02']],
            ['Time display rules', pk],
            ['Universal interaction states', pk],
        ];
        return `<div class="card" style="overflow:hidden"><div class="tbl-wrap"><table class="etable matrix"><caption class="sr-only">State families by package</caption><thead><tr><th scope="col">State family</th>${pk.map((p) => `<th scope="col">${p}</th>`).join('')}</tr></thead><tbody>${fam.map(([f, ps]) => `<tr><td style="font-weight:600">${f}</td>${pk.map((p) => `<td>${ps.includes(p) ? '<span class="dot-yes" role="img" aria-label="reused"></span>' : '<span class="sr-only">not used</span>'}</td>`).join('')}</tr>`).join('')}</tbody></table></div></div>
            <div class="annot"><div class="a-tag">${ic('info', 's3')}Design note</div>Package order after P00 (plan §7.2): P01 → P02 → P08a → P07a → P03 → P04 → P06 → P07b → P05 → P08b → P11 → P09 → P10. None starts until the P0 fixes are accepted and this exact P00 version is approved.</div>`;
    }

    function cataloguePage() {
        const secs = SECTIONS.map(([id, label, icon]) => `<a href="#/catalogue/${id}" ${S.section === id ? 'aria-current="true"' : ''} data-sec="${id}">${ic(icon, 's35')}${label}</a>`).join('');
        return `<div class="cat">
            <aside class="card cat-index" aria-label="Catalogue sections"><h2>P00 v2 catalogue</h2><nav>${secs}</nav></aside>
            <main class="cat-main" id="main" tabindex="-1">${SECTIONS.filter(([id]) => !S.only || id === S.only).map(([id]) => `<section class="cat-section" id="sec-${id}" aria-label="${esc(SECTIONS.find((s) => s[0] === id)[1])}">${sectionHtml(id)}</section>`).join('')}</main>
        </div>`;
    }

    /* ───────────── dialogs ───────────── */
    let lastFocusKey = null, lastFocusEl = null, dialogKeyHandler = null;
    function openDialog(inner, cls, labelId, descId) {
        lastFocusEl = document.activeElement;
        lastFocusKey = lastFocusEl && lastFocusEl.dataset ? lastFocusEl.dataset.fk || null : null;
        const root = $('#dialog-root');
        root.innerHTML = `<div class="dlg-backdrop" data-act="backdrop"><div class="dlg ${cls}" role="dialog" aria-modal="true" aria-labelledby="${labelId}" ${descId ? `aria-describedby="${descId}"` : ''}>${inner}</div></div>`;
        $('#app').inert = true; $('#viewer').inert = true;
        const dlg = $('.dlg', root);
        const first = $('[data-autofocus]', dlg) || $('#' + labelId + '[tabindex]', dlg) || focusables(dlg)[0];
        if (first) first.focus();
        dialogKeyHandler = (e) => {
            if (e.key === 'Escape') { e.preventDefault(); if (W && W.tp) { W.tp = null; renderWizard(false, '#w-time'); return; } if ($('#rail-more-pop')) { closeMore(); return; } closeDialog(); }
            if (e.key === 'Enter' && W && W.tp && e.target.closest && e.target.closest('.tp') && e.target.tagName === 'INPUT') { e.preventDefault(); tpApply(); return; }
            if ((e.key === 'ArrowUp' || e.key === 'ArrowDown') && W && W.tp && (e.target.id === 'tp-h' || e.target.id === 'tp-m')) { e.preventDefault(); const up = e.key === 'ArrowUp' ? 1 : -1; if (e.target.id === 'tp-h') { let h = (parseInt(W.tp.hText, 10) || W.tp.h) + up; h = h > 12 ? 1 : h < 1 ? 12 : h; W.tp.hText = String(h); W.tp.face = 'hours'; } else { let m = (parseInt(W.tp.mText, 10) || 0) + up; m = m > 59 ? 0 : m < 0 ? 59 : m; W.tp.mText = pad2(m); W.tp.face = 'minutes'; } W.tp.err = null; renderWizard(false, '#' + e.target.id); return; }
            if (e.key === 'Tab') {
                const f = focusables(dlg); if (!f.length) return;
                if (e.shiftKey && document.activeElement === f[0]) { e.preventDefault(); f[f.length - 1].focus(); }
                else if (!e.shiftKey && document.activeElement === f[f.length - 1]) { e.preventDefault(); f[0].focus(); }
            }
        };
        document.addEventListener('keydown', dialogKeyHandler);
    }
    const focusables = (el) => $$('a[href], button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])', el).filter((x) => !x.hidden && x.offsetParent !== null);
    function closeDialog(skipFocus) {
        $('#dialog-root').innerHTML = '';
        $('#app').inert = false; $('#viewer').inert = false;
        if (dialogKeyHandler) document.removeEventListener('keydown', dialogKeyHandler);
        dialogKeyHandler = null;
        if (skipFocus) return;
        restoreFocus();
    }
    function restoreFocus() {
        let el = lastFocusEl && document.contains(lastFocusEl) ? lastFocusEl : null;
        if (!el && lastFocusKey) el = $(`[data-fk="${lastFocusKey}"]`);
        if (!el && lastFocusKey && lastFocusKey.includes('-')) {
            const rowId = lastFocusKey.split('-').slice(1).join('-');
            el = $(`[data-row-id="${rowId}"] .row-actions button`) || $(`[data-row-id="${rowId}"] a`);
        }
        if (el) el.focus();
    }
    function simpleDialog({ title, icon, desc, body, foot, w480 }) {
        return `<button class="d-close" type="button" data-act="close" aria-label="Close">${ic('x', 's5')}</button>
            <div class="d-head"><h2 class="d-title" id="dlg-t" tabindex="-1">${ic(icon || 'info')}${esc(title)}</h2><p class="d-desc" id="dlg-d">${desc}</p></div>
            <div class="d-body">${body}</div><div class="d-foot">${foot}</div>`;
    }

    function openWhy(block, ctx) {
        const b = BLOCKS[block];
        const p = S.persona;
        let primary = '';
        const act = b.leadAction && leadCap(p) ? b.leadAction : b.action;
        if (act) primary = act.act === 'go' ? `<a class="btn btn-primary" href="${tpl(act.href, { persona: p })}" data-act="close-nav">${ic(act.icon)}${esc(act.label)}</a>` : `<button class="btn btn-primary" type="button" data-act="${act.act}" data-close="1">${ic(act.icon)}${esc(act.label)}</button>`;
        const notGiven = b.still && b.still !== 'none' && ctx.row ? `<button class="btn ${primary ? 'btn-outline' : 'btn-primary'}" type="button" data-act="record-notgiven" data-row="${ctx.row}">Record not given</button>` : '';
        openDialog(simpleDialog({
            title: 'Why can’t I record this?', icon: 'help', desc: `${esc(ctx.p)} · ${esc(ctx.med)}${ctx.time ? ' · ' + esc(ctx.time) : ''}`,
            body: (ctx.pid ? identityHeader(ctx.pid, { compact: true, support: ctx.support }) : '') + blockedPanel(block, { p: ctx.p, med: ctx.med, persona: p }),
            foot: `<button class="btn btn-outline" type="button" data-act="close">Close</button>${notGiven}${primary}`,
        }), 'dlg-simple', 'dlg-t', 'dlg-d');
    }

    function openEligibility() {
        const expired = S.scenario === 'competencyExpired';
        const p = S.persona;
        const rows = !has(p, 'administer')
            ? [['Medication competency', 'No assessment — your role doesn’t record doses'], ['Controlled-medicine witness', 'Not eligible'], ['Shift', 'No medication shift today']]
            : [
                ['Medication competency', expired ? `<span class="badge b-critical sm">${ic('x-circle')}Expired 14 September 2026</span>` : `<span class="badge b-success sm">${ic('check')}Current until 14 March 2027</span>`],
                ['Assessed', '14 March 2026 by Hana Kereama · declaration and acknowledgement recorded'],
                ['Areas passed', 'General administration · As-needed · Controlled medicines'],
                ['Areas not passed', 'Insulin · Covert administration'],
                ['Restrictions', S.scenario === 'restrictedBlock' ? '<span class="badge b-critical sm">Restricted</span> “supervised practice until reassessed” — organisation rule: Block' : S.scenario === 'restrictedCosigner' ? '<span class="badge b-warning sm">Restricted</span> “supervised practice until reassessed” — organisation rule: Co-signer' : 'None'],
                ['Controlled-medicine witness', expired ? 'Not eligible while competency is expired' : 'Eligible'],
                ['Shift', S.scenario === 'notClockedIn' ? 'Not clocked in' : 'Clocked in 7:02 am · Kōwhai House · 7:00 am–3:00 pm'],
                ['House access', 'Kōwhai House'],
            ];
        openDialog(simpleDialog({
            title: 'My medication eligibility', icon: 'user-check', desc: `${esc(PERSONAS[p].name)} · ${esc(PERSONAS[p].role)} · checked ${NOW} NZDT`,
            body: `<dl class="kv" style="margin:0">${rows.map(([k, v]) => `<dt>${k}</dt><dd>${v}</dd>`).join('')}</dl><div class="annot"><div class="a-tag">${ic('info', 's3')}Design note</div>Truthful labels built from the competency policy, not from permissions (EM-03). No “Medication lead”, fixed shift or “CD witness authorised” unless the witness checks pass. Full view designed in P11; areas and meaning are ${dtag('D3')}.</div>`,
            foot: `<button class="btn btn-outline" type="button" data-act="close" data-autofocus>Close</button>`,
        }), 'dlg-simple', 'dlg-t', 'dlg-d');
    }

    function openRejected(row) {
        openDialog(simpleDialog({
            title: 'A saved medication action was not recorded', icon: 'x-circle', desc: 'Aroha · Paracetamol 500 mg tablet · saved offline at 8:50 am, sent at 9:02 am',
            body: identityHeader('aroha', { compact: true, support: 'administer' }) + `<div class="banner critical" role="alert"><span class="b-ico">${ic('x-circle')}</span><div class="b-body"><div class="b-title">Not recorded — this dose was not saved.</div><div class="b-text">It was saved on this device at 8:50 am while offline. When it was sent at 9:02 am, the server refused it: the as-needed limit on the prescription is reached (4 doses in the last 24 hours, the most recent at 8:05 am). The chart doesn’t show this dose, and it won’t be sent again.</div></div></div>
                <p style="margin:0;font-size:13px"><b>If the dose was already given</b>, tell the house lead now so it can be recorded correctly and followed up. <b>If it wasn’t given</b>, you can dismiss this.</p>
                <div class="annot"><div class="a-tag">${ic('info', 's3')}Design note</div>Replaces the old false “PRN administration recorded.” (EM-26 fix, commit 399a5ab98). The refused item stays on the device and the app-wide banner stays until it is dismissed; it is never re-sent automatically. Who is told is ${dtag('D12')}. “Tell the house lead” is a P00 addition — the implemented banner offers Dismiss only.</div>`,
            foot: `<button class="btn btn-outline" type="button" data-act="close">Close</button><button class="btn btn-outline" type="button" data-act="rej-dismiss">It wasn’t given — dismiss</button><button class="btn btn-primary" type="button" data-act="rej-tell">${ic('send')}Tell the house lead</button>`,
        }), 'dlg-simple', 'dlg-t', 'dlg-d');
    }

    function openDetail(row) {
        const r = MEDS[row], pp = PEOPLE[r.pid];
        const st = rowState(row);
        openDialog(simpleDialog({
            title: `${r.med} · ${pp.pref}`, icon: 'pill', desc: `${esc(r.str)} · ${r.slot} dose · Monday 28 September 2026`,
            body: identityHeader(r.pid, { compact: true, support: r.support }) + `<div class="medblock"><div class="mb-top">${dbadge(st)}<span class="text-caption">Times in NZDT</span></div><dl class="kv"><dt>Outcome</dt><dd>${esc(rowLine(row))}</dd><dt>Recorded</dt><dd>${st === 'given' ? '8:07 am by Priya Shah' : st === 'refused' ? '8:11 am by Priya Shah' : st === 'queued' ? 'Saved on this device — not sent yet' : st === 'rejected' ? 'Not saved' : '9:05 am by Priya Shah'}</dd><dt>Order version</dt><dd>Verified 3 August 2026 by Jordan Tipene</dd></dl></div>${st === 'rejected' ? `<div class="banner critical" role="alert"><span class="b-ico">${ic('x-circle')}</span><div class="b-body"><div class="b-title">Not recorded</div><div class="b-text">Your save at 9:12 am was refused: your competency record changed at 9:10 am and no longer covers this dose. Nothing is on ${esc(pp.pref)}’s chart for it. Ask a colleague with current competency to give it, or record a refusal, withhold or absence.</div></div></div>` : ''}${st === 'refused' ? `<div class="card" style="overflow:hidden">${followUpRow(FU.refusal)}</div>` : ''}`,
            foot: `<button class="btn btn-outline" type="button" data-act="close" data-autofocus>Close</button>`,
        }), 'dlg-simple', 'dlg-t', 'dlg-d');
    }

    function openPalette() {
        const p = S.persona;
        let items = [];
        if (S.mode === 'frame') { const hub = hubById(S.hub); items = visibleViews(p, hub).map((v) => ({ label: v.label, href: hrefFrame(p, hub.id, v.id) })); }
        openDialog(`<h2 class="sr-only" id="dlg-t">Find a view</h2><label class="sr-only" for="pal-in">Find a view</label><input id="pal-in" type="search" placeholder="Find a view…" data-autofocus autocomplete="off"><ul role="list">${items.map((it) => `<li><a href="${it.href}" class="pal-item" data-act="close-nav" style="display:flex;gap:10px;padding:9px 10px;border-radius:8px;text-decoration:none;color:inherit">${ic('arrow-right', 's35')}${esc(it.label)}</a></li>`).join('')}</ul>`, 'palette', 'dlg-t');
        const input = $('#pal-in');
        input.addEventListener('input', () => { $$('.pal-item').forEach((a) => { a.parentElement.hidden = !a.textContent.toLowerCase().includes(input.value.toLowerCase()); }); });
    }

    /* Record dose (WizardShell anatomy) — P00 specimen; the full journey is P01. */
    let W = null;
    function openRecord(rowKey, opts = {}) {
        const isPrn = rowKey === 'prn';
        const r = isPrn ? { ...PRN, slot: 'As needed', support: 'administer' } : MEDS[rowKey];
        const pp = PEOPLE[r.pid];
        const block = isPrn ? 'prnLimit' : (['due', 'late', 'notdue'].includes(rowState(rowKey)) ? rowBlock(rowKey) : null);
        const reoffer = !!opts.reoffer;
        W = { time: { h: 9, m: 12, ap: 'am' }, tp: null, rowKey, r, pp, block, step: opts.notGivenOnly ? 1 : 0, outcome: null, reason: '', note: '', error: null, sending: false, rejectedMsg: null, isPrn, reoffer, notGivenOnly: !!opts.notGivenOnly };
        const shell = `<div class="wiz-grid"><aside class="wiz-rail" aria-label="Steps"></aside><div class="wiz-main"><div class="wiz-head"><span id="dlg-t" tabindex="-1" style="outline:none"></span><button class="d-close wiz-close" type="button" data-act="close" aria-label="Close">${ic('x', 's5')}</button></div><div class="wiz-prog" aria-hidden="true"><i style="width:33%"></i></div><div class="wiz-body" id="wiz-body"></div><div class="wiz-foot" id="wiz-foot"></div></div></div>`;
        openDialog(shell, 'wiz', 'dlg-t');
        renderWizard(true);
    }
    const STEPS = [
        { l: 'Safety checks', b: 'Person, allergies, instructions', i: 'shield' },
        { l: 'Record outcome', b: 'What actually happened', i: 'clipboard-check' },
        { l: 'Review & sign', b: 'Check, then save', i: 'check' },
    ];
    function outcomeOptions() {
        const s = W.r.support;
        const blocked = W.block;
        const givenBlocked = !!blocked;
        const cs = s === 'administer' ? compState() : null;
        const expired = cs === 'expired' || cs === 'restrictedBlock' || (cs === 'restrictedCosigner' && (S.method === 'A' || S.nobody));
        const givenLabel = s === 'prompt' ? 'Taken with prompting' : s === 'assist' ? 'Taken with assistance' : W.reoffer ? 'Given after re-offer' : 'Given';
        const givenKey = s === 'prompt' ? 'prompted' : s === 'assist' ? 'assisted' : W.reoffer ? 'reoffered' : 'given';
        const givenReason = blocked ? tpl(BLOCKS[blocked].title, { p: W.pp.pref, med: W.r.med }).replace(/<[^>]+>/g, '') : expired && (givenKey === 'given' || givenKey === 'reoffered') ? COMP_REASON[cs] : null;
        const opts = [
            { k: givenKey, l: givenLabel, d: s === 'prompt' ? `${W.pp.pref} took it after a reminder` : s === 'assist' ? 'You helped; the person took it' : W.reoffer ? 'Offered again and taken' : 'You gave the medicine', i: s === 'prompt' ? 'message' : s === 'assist' ? 'hand' : W.reoffer ? 'repeat' : 'check', disabled: givenBlocked || (expired && (givenKey === 'given' || givenKey === 'reoffered')) || W.notGivenOnly, why: givenReason || (W.notGivenOnly ? 'Can’t be recorded as given right now' : null) },
        ];
        if (!W.isPrn && !W.reoffer) {
            opts.push({ k: 'refused', l: 'Refused', d: `${W.pp.pref} chose not to take it`, i: 'x' });
            opts.push({ k: 'withheld', l: 'Withheld', d: 'Not given, with a reason', i: 'pause' });
            opts.push({ k: 'away', l: 'Away', d: `${W.pp.pref} isn’t here`, i: 'log-out' });
        }
        return opts;
    }
    function renderWizard(first, focusSel) {
        if (!W) return;
        const dlg = $('.dlg.wiz');
        if (!dlg) return;
        const rail = $('.wiz-rail', dlg);
        rail.innerHTML = `<div class="wiz-railhead"><span class="rtile">${ic('pill', 's5')}</span><div><div class="t">${W.isPrn ? 'Record as-needed dose' : W.reoffer ? 'Record re-offer' : 'Record dose'}</div><div class="s">${esc(W.pp.pref)} · ${esc(W.r.slot)}</div></div></div>
            ${STEPS.map((s, i) => `<button class="wiz-step${i === W.step ? ' on' : ''}${i < W.step ? ' done' : ''}" type="button" data-act="wiz-step" data-step="${i}" ${i > W.step ? 'disabled' : ''} aria-current="${i === W.step ? 'step' : 'false'}"><span class="n">${ic(i < W.step ? 'check' : s.i, 's35')}</span><span><span class="l">${s.l}</span><span class="b">${s.b}</span></span></button>`).join('')}
            <div class="wiz-railfoot"><div class="wiz-signed"><b>Signed as</b><br>${esc(PERSONAS[S.persona].name)}<br>${esc(PERSONAS[S.persona].role)}</div><div class="annot" style="padding:8px;font-size:11px"><div class="a-tag">Design note</div>P00 specimen. The full recording journey (all entry points, one contract) is P01.</div></div>`;
        $('#dlg-t').innerHTML = `Step ${W.step + 1} of 3 · <b>${STEPS[W.step].l}</b>`;
        $('.wiz-prog i', dlg).style.width = `${((W.step + 1) / 3) * 100}%`;
        const body = $('#wiz-body');
        const r = W.r, pp = W.pp;
        const idh = identityHeader(pp.id, { support: r.support });
        const al = allergyBanner(pp.allergy, pp.pref, true);
        const med = `<div class="medblock"><div class="mb-top"><div><div class="med-name" style="font-size:14px">${esc(r.med)} ${esc(r.str)}</div><div class="who-sub">${esc(r.dose)} · by mouth</div></div>${W.isPrn ? '<span class="chipn">As needed</span>' : dbadge(rowState(W.rowKey))}</div>
            <dl class="kv"><dt>Instructions</dt><dd>${esc(r.ins)} <span class="who-sub">(from the prescription)</span></dd><dt>Due</dt><dd>${W.isPrn ? 'When needed' : `${esc(r.slot)} · due window: ${NC()}`}</dd><dt>Order</dt><dd>${W.block === 'awaitingVerification' ? '<span class="badge b-warning sm">Waiting to be checked</span>' : 'Verified 3 August 2026 by Jordan Tipene'}</dd>${r.cd ? `<dt>Witness</dt><dd>Needed — a different person, on shift, with witness competency</dd>` : ''}</dl></div>`;
        const late = !W.isPrn && rowState(W.rowKey) === 'late' && !W.block ? `<div class="banner warning"><span class="b-ico">${ic('alert-triangle')}</span><div class="b-body"><div class="b-title">This dose is late</div><div class="b-text">No late-dose instruction is set for this medicine. Check with the on-call contact before giving it: ${NC()}</div></div></div>` : '';
        const blk = W.block ? blockedPanel(W.block, { p: pp.pref, med: r.med, persona: S.persona }) : '';
        const csW = r.support === 'administer' && !W.block ? compState() : null;
        const expiredB = csW === 'restrictedCosigner' && S.nobody ? NOBODY('co-sign') : csW === 'restrictedCosigner' && S.method === 'A' ? B('warning', 'users', 'A colleague needs to give this dose', 'Your medication competency is restricted. On shift now and able to give it: <b>Daniel Ahn</b> (current competency, not restricted). Ask Daniel to give and record it from their own login. You can still record a refusal, withhold or absence.') : csW ? blockedPanel(csW === 'expired' ? 'competencyExpired' : csW, { p: pp.pref, med: r.med, persona: S.persona }) : '';
        const rej = W.rejectedMsg ? `<div class="banner critical" role="alert"><span class="b-ico">${ic('x-circle')}</span><div class="b-body"><div class="b-title">Not recorded — this dose was not saved.</div><div class="b-text">${W.rejectedMsg} The chart doesn’t show this dose. What you entered is kept.</div></div></div>` : '';
        const unc = W.uncertain ? `<div class="banner warning" role="alert"><span class="b-ico">${ic('help')}</span><div class="b-body"><div class="b-title">Not confirmed — check before trying again</div><div class="b-text">We didn’t get confirmation that this was saved, so it isn’t shown as recorded. Check ${esc(pp.pref)}’s chart first. Trying again won’t create a duplicate.</div><div class="b-actions"><a class="btn btn-outline btn-sm" href="#/person/${S.persona}/${pp.id}/chart" data-act="close-nav">${ic('search')}Check the chart</a></div></div></div>` : '';
        const sending = W.sending ? `<div class="banner info" role="status"><span class="b-ico"><span class="ring-spin"></span></span><div class="b-body"><div class="b-title">Sending — not yet confirmed</div><div class="b-text">Don’t close this window. Nothing shows as recorded until it’s confirmed.</div></div></div>` : '';
        if (W.step === 0) {
            const aw = W.rowKey === 'r3' && !W.block ? (S.safety.profileAllergy === 'warn' ? profileMatchWarning(pp.pref, r.med) : S.orderConfirmed ? allergyMatchLine(r.med) + allergyConfirmedNote() : '') : '';
            body.innerHTML = `${idh}${al}${med}${late}${blk}${aw}${expiredB}`;
        } else if (W.step === 1) {
            const opts = outcomeOptions();
            const reoff = W.reoffer ? `<div class="banner neutral"><span class="b-ico">${ic('link')}</span><div class="b-body"><div class="b-title">Linked to the refusal at 8:10 am</div><div class="b-text">The refusal stays in the history. Re-offer rule: ${NC()}</div></div></div>` : '';
            body.innerHTML = `${identityHeader(pp.id, { compact: true, support: r.support })}${al}${reoff}${rej}
                <div class="field" role="group" aria-labelledby="oc-l" ${W.error === 'outcome' ? 'aria-describedby="oc-e"' : ''}><span class="flabel" id="oc-l">What happened? <span class="req">*</span></span>
                <div class="tiles">${opts.map((o) => `<button class="tile" type="button" data-act="wiz-outcome" data-k="${o.k}" aria-pressed="${W.outcome === o.k}" ${o.disabled ? `aria-disabled="true" aria-describedby="why-${o.k}"` : ''}><span class="t-i">${ic(o.i)}</span><span><span class="t-l">${o.l}</span><span class="t-d">${esc(o.d)}</span>${o.disabled ? `<span class="t-d" id="why-${o.k}" style="color:var(--status-critical);font-weight:600">${ic('lock', 's3')} ${esc(o.why)}</span>` : ''}</span></button>`).join('')}</div>
                ${W.error === 'outcome' ? '<span class="ferr" id="oc-e">Choose what happened.</span>' : ''}</div>
                ${outcomeFields()}`;
        } else {
            const o = outcomeOptions().find((x) => x.k === W.outcome) || { l: W.outcome };
            body.innerHTML = `${identityHeader(pp.id, { compact: true, support: r.support })}${al}${sending}${rej}${unc}
                ${W.rowKey === 'r3' ? allergyMatchLine(r.med) : ''}
                <div class="review-card"><h4>${ic('pill', 's35')}Medicine</h4><div class="rrow"><span>Medicine</span><span>${esc(r.med)} ${esc(r.str)}</span></div><div class="rrow"><span>Dose</span><span>${esc(r.dose)}</span></div></div>
                <div class="review-card"><h4>${ic('clipboard-check', 's35')}Outcome <button class="btn-link" type="button" data-act="wiz-step" data-step="1" style="margin-left:auto;font-size:12.5px">${ic('pencil', 's3')} Edit</button></h4><div class="rrow"><span>What happened</span><span>${esc(o.l)}</span></div>${W.reason ? `<div class="rrow"><span>Reason</span><span>${esc(W.reason)}</span></div>` : ''}<div class="rrow"><span>Time</span><span>${['given', 'prompted', 'assisted', 'reoffered'].includes(W.outcome) ? fmtTime(W.time) : NOW} NZDT · Monday 28 September 2026</span></div>${W.cosigner && needsCosign() ? `<div class="rrow"><span>Co-signed by</span><span>${esc(W.cosigner)}${S.method === 'B' ? ' (witness PIN)' : S.method === 'C' ? ' (confirmed from their own session)' : ' (password)'}</span></div>` : ''}${W.note ? `<div class="rrow"><span>Note</span><span>${esc(W.note)}</span></div>` : ''}${W.outcome === 'refused' ? `<div class="rrow"><span>Follow-up</span><span>Owner Priya Shah · due 12:00 pm</span></div>` : ''}</div>
                <div class="review-card"><h4>${ic('user', 's35')}Signed as</h4><div class="rrow"><span>Name</span><span>${esc(PERSONAS[S.persona].name)}</span></div></div>`;
        }
        const foot = $('#wiz-foot');
        const backBtn = W.step > 0 && !(W.notGivenOnly && W.step === 1) ? `<button class="btn btn-ghost" type="button" data-act="wiz-back">${ic('chev-left')}Back</button>` : `<button class="btn btn-outline" type="button" data-act="close">Cancel</button>`;
        let next;
        const expiredNoAlt = false;
        if (W.step === 0) {
            const onlyBlocked = W.isPrn && W.block;
            next = onlyBlocked ? `<button class="btn btn-primary" type="button" disabled aria-describedby="prn-why">Continue</button><span class="text-caption" id="prn-why" style="align-self:center">Can’t continue: as-needed limit reached</span>` : `<button class="btn btn-primary" type="button" data-act="wiz-next">Continue${ic('chev-right')}</button>`;
        } else if (W.step === 1) next = `<button class="btn btn-primary" type="button" data-act="wiz-next">Continue${ic('chev-right')}</button>`;
        else next = W.sending ? `<button class="btn btn-primary" type="button" disabled><span class="ring-spin" aria-hidden="true"></span>Sending…</button>` : `<button class="btn btn-primary" type="button" data-act="wiz-submit">${ic(W.uncertain || W.rejectedMsg ? 'refresh' : 'check')}${W.uncertain ? 'Try again' : 'Record outcome'}</button>`;
        const hasBack = backBtn.includes('wiz-back');
        foot.innerHTML = `<div>${backBtn}</div><div class="end">${hasBack && !expiredNoAlt ? `<button class="btn btn-outline" type="button" data-act="close">Cancel</button>` : ''}${next}</div>`;
        if (focusSel) { const el = $(focusSel); if (el) { el.focus(); if (el.select && el.tagName === 'INPUT') el.select(); } if (W.tp) { const tpEl = $('.tp'); if (tpEl) tpEl.scrollIntoView({ block: 'nearest' }); } }
        else if (!first) {
            const target = W.error ? $('#wiz-body [aria-invalid="true"], #wiz-body .tile:not([aria-disabled="true"])') : $('#wiz-body .tile:not([aria-disabled="true"]), #wiz-body select, #wiz-foot .btn-primary:not([disabled])');
            if (target) target.focus();
        }
        if (!focusSel) body.scrollTop = 0;
    }
    function outcomeFields() {
        if (W.outcome === 'withheld' || W.outcome === 'away') {
            const reasons = W.outcome === 'withheld' ? ['Doctor’s instruction', 'Fasting', 'Vomit or nausea', 'Medication unavailable', 'Safety check blocked it', 'Other'] : ['Social leave', 'Hospitalised', 'Transferred'];
            return `<div class="fgrid"><div class="field"><label for="w-reason">${W.outcome === 'withheld' ? 'Reason for withholding' : 'Where is ' + esc(W.pp.pref) + '?'} <span class="req">*</span></label><select id="w-reason" data-act="wiz-reason" ${W.error === 'reason' ? 'aria-invalid="true" aria-describedby="w-reason-e"' : ''}><option value="">Choose a reason</option>${reasons.map((x) => `<option${W.reason === x ? ' selected' : ''}>${x}</option>`).join('')}</select>${W.error === 'reason' ? `<span class="ferr" id="w-reason-e">${W.outcome === 'withheld' ? 'Choose a reason for withholding.' : 'Choose where ' + esc(W.pp.pref) + ' is.'}</span>` : ''}</div>
                <div class="field"><label for="w-note">What happened <span class="who-sub">(optional note)</span></label><textarea id="w-note" rows="2" data-act="wiz-note" maxlength="1000" placeholder="e.g. Felt sick after breakfast; will offer again at lunch if the plan allows.">${esc(W.note)}</textarea></div></div>${W.outcome === 'withheld' && W.block && BLOCKS[W.block].safety ? `<p class="text-caption" style="margin:0">Saving a withhold is always allowed while a safety check blocks “given”. Who is told: ${NC()}</p>` : ''}`;
        }
        if (W.outcome === 'refused') {
            return `<div class="fgrid"><div class="field"><label for="w-note">What happened <span class="who-sub">(optional note)</span></label><textarea id="w-note" rows="2" data-act="wiz-note" placeholder="e.g. Grace said she felt fine and didn’t want it today">${esc(W.note)}</textarea></div>
                <div class="field"><span class="flabel">Follow-up</span><div class="medblock" style="padding:10px"><div class="owner"><span class="disc sm" aria-hidden="true">PS</span><div>Owner: Priya Shah<div class="o-sub">Due 12:00 pm · entered by you · no default rule (${NC()})</div></div></div></div></div></div>`;
        }
        if (W.outcome && ['given', 'prompted', 'assisted', 'reoffered'].includes(W.outcome)) {
            const co = needsCosign() ? cosignFields() : '';
            const coLegacy = false ? `<div class="fgrid"><div class="field"><label for="w-cos">Co-signed by <span class="req">*</span></label><select id="w-cos" data-act="wiz-cos" ${W.error === 'cosign' ? 'aria-invalid="true" aria-describedby="w-cos-e"' : ''}><option value="">Choose a co-signer on shift</option>${['Daniel Ahn', 'Mere Kahu'].map((x) => `<option${W.cosigner === x ? ' selected' : ''}>${x}</option>`).join('')}</select>${W.error === 'cosign' ? '<span class="ferr" id="w-cos-e">Choose a co-signer and ask them to enter their password.</span>' : '<span class="who-sub">A present, qualified colleague whose own competency isn’t restricted</span>'}</div><div class="field"><label for="w-cpw">Co-signer password <span class="req">*</span></label><input id="w-cpw" type="password" data-act="wiz-cpw" value="${esc(W.cosignPw || '')}" autocomplete="off" ${W.error === 'cpw' ? 'aria-invalid="true" aria-describedby="w-cpw-e"' : ''}>${W.error === 'cpw' ? '<span class="ferr" id="w-cpw-e">Your co-signer needs to enter their own password.</span>' : '<span class="who-sub">Entered by the co-signer, not by you</span>'}</div></div>` : '';
            return co + `<div class="fgrid">${timeField(W.outcome === 'given' || W.outcome === 'reoffered' ? 'Time given' : 'Time taken')}<div class="field"><label for="w-dose">Amount</label><input id="w-dose" value="${esc(W.r.dose)}" readonly aria-readonly="true"><span class="who-sub">Fixed by the order</span></div></div>
                <div class="field"><label for="w-note">What happened <span class="who-sub">(optional note)</span></label><textarea id="w-note" rows="2" data-act="wiz-note" maxlength="1000" placeholder="e.g. Took it with yoghurt. Asked about side effects.">${esc(W.note)}</textarea><span class="who-sub">Saved with this dose and shown in the chart history.</span></div>`;
        }
        return '';
    }
    /* Clock + manual time entry (POPUP_STYLE_GUIDE, approved 2026-09-20): hour/minute clock faces,
     * editable digits, AM/PM, Type time / Clock switch, local draft with Use time / Cancel, exact minute
     * kept, visible timezone, Escape closes the picker first and returns focus to the trigger. */
    const pad2 = (n) => String(n).padStart(2, '0');
    const fmtTime = (t) => `${t.h}:${pad2(t.m)} ${t.ap}`;
    const canon = (t) => `${pad2((t.h % 12) + (t.ap === 'pm' ? 12 : 0))}:${pad2(t.m)}`;
    function timePicker(tp, label, staticSpec) {
        const faceVals = tp.face === 'hours' ? [12, 1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11] : [0, 5, 10, 15, 20, 25, 30, 35, 40, 45, 50, 55];
        const cur = tp.face === 'hours' ? (parseInt(tp.hText, 10) || tp.h) : (parseInt(tp.mText, 10) || 0);
        const ang = tp.face === 'hours' ? ((cur % 12) / 12) * 360 : (cur / 60) * 360;
        const face = tp.mode === 'clock' ? `<div class="tp-face" role="group" aria-label="${tp.face === 'hours' ? 'Hours' : 'Minutes'}"><span class="tp-hand" aria-hidden="true" style="width:78px;transform:rotate(${ang - 90}deg)"></span><span class="tp-pin" aria-hidden="true"></span>${faceVals.map((v, i) => { const a = (i / 12) * 2 * Math.PI; const x = 110 + 88 * Math.sin(a), y = 110 - 88 * Math.cos(a); const on = tp.face === 'hours' ? v === (cur % 12 || 12) : v === cur; return `<button type="button" data-act="tp-dial" data-val="${v}" aria-pressed="${on}" aria-label="${tp.face === 'hours' ? v + ' o’clock' : pad2(v) + ' minutes'}" style="left:${x.toFixed(1)}px;top:${y.toFixed(1)}px">${tp.face === 'hours' ? v : pad2(v)}</button>`; }).join('')}</div>` : '';
        return `<div class="tp" role="dialog" aria-label="Choose ${esc(label.toLowerCase())}" ${staticSpec ? 'inert' : ''}>
            <div class="tp-head"><span class="tp-title">${esc(label)}</span><span class="seg" role="radiogroup" aria-label="Entry mode"><button type="button" role="radio" aria-checked="${tp.mode === 'clock'}" aria-pressed="${tp.mode === 'clock'}" data-act="tp-mode" data-mode="clock">Clock</button><button type="button" role="radio" aria-checked="${tp.mode === 'type'}" aria-pressed="${tp.mode === 'type'}" data-act="tp-mode" data-mode="type">Type time</button></span></div>
            <div class="tp-digits"><label class="sr-only" for="tp-h">Hour</label><input id="tp-h" class="${tp.face === 'hours' ? 'on' : ''}" inputmode="numeric" maxlength="2" value="${esc(tp.hText)}" data-act="tp-h" ${tp.err === 'h' ? 'aria-invalid="true" aria-describedby="tp-err"' : ''}><span class="tp-colon" aria-hidden="true">:</span><label class="sr-only" for="tp-m">Minutes</label><input id="tp-m" class="${tp.face === 'minutes' ? 'on' : ''}" inputmode="numeric" maxlength="2" value="${esc(tp.mText)}" data-act="tp-m" ${tp.err === 'm' ? 'aria-invalid="true" aria-describedby="tp-err"' : ''}>
                <span class="tp-ampm" role="radiogroup" aria-label="AM or PM"><button type="button" role="radio" aria-checked="${tp.ap === 'am'}" aria-pressed="${tp.ap === 'am'}" data-act="tp-ap" data-ap="am">AM</button><button type="button" role="radio" aria-checked="${tp.ap === 'pm'}" aria-pressed="${tp.ap === 'pm'}" data-act="tp-ap" data-ap="pm">PM</button></span></div>
            ${face}
            ${tp.err ? `<div class="tp-err" id="tp-err" role="alert">${tp.err === 'h' ? 'Enter an hour from 1 to 12.' : 'Enter minutes from 00 to 59.'}</div>` : `<div class="tp-hint">${tp.mode === 'clock' ? 'Pick the hour, then the minutes. Type any exact minute.' : 'Type the hour and minutes, then choose AM or PM.'}</div>`}
            <div class="tp-foot"><span class="tz">NZDT · Pacific/Auckland</span><button class="btn btn-outline btn-sm" type="button" data-act="tp-cancel">Cancel</button><button class="btn btn-primary btn-sm" type="button" data-act="tp-apply">Use time</button></div>
        </div>`;
    }
    function timeField(label) {
        const t = W.time;
        return `<div class="field tp-wrap"><span class="flabel" id="w-time-l">${esc(label)} <span class="req">*</span></span>
            <button id="w-time" class="time-trigger" type="button" data-act="tp-open" aria-haspopup="dialog" aria-expanded="${!!W.tp}" aria-labelledby="w-time-l w-time-v" ${W.error === 'time' ? 'aria-invalid="true" aria-describedby="w-time-e"' : ''}>${ic('clock')}<span id="w-time-v">${fmtTime(t)}</span><span class="tz">NZDT</span></button>
            ${W.error === 'time' ? '<span class="ferr" id="w-time-e">The time can’t be later than now (9:12 am NZDT).</span>' : '<span class="who-sub">Monday 28 September 2026 · exact minute kept · change it if you gave it at another time</span>'}
            ${W.tp ? timePicker(W.tp, label) : ''}</div>`;
    }
    function tpApply() {
        const tp = W.tp;
        if (W.error === 'time') W.error = null;
        const h = /^\d{1,2}$/.test(tp.hText) ? parseInt(tp.hText, 10) : NaN;
        const m = /^\d{1,2}$/.test(tp.mText) ? parseInt(tp.mText, 10) : NaN;
        if (!(h >= 1 && h <= 12)) { tp.err = 'h'; renderWizard(false, '#tp-h'); return; }
        if (!(m >= 0 && m <= 59)) { tp.err = 'm'; renderWizard(false, '#tp-m'); return; }
        W.time = { h, m, ap: tp.ap };
        W.tp = null;
        renderWizard(false, '#w-time');
    }
    function needsCosign() { return W && S.scenario === 'restrictedCosigner' && S.method !== 'A' && W.r.support === 'administer' && ['given', 'reoffered'].includes(W.outcome); }
    function cosignFields() {
        if (S.nobody) return NOBODY('co-sign');
        const m = S.method;
        const sel = `<div class="field"><label for="w-cos">${m === 'C' ? 'Ask for confirmation from' : 'Co-signed by'} <span class="req">*</span></label><select id="w-cos" data-act="wiz-cos" ${W.error === 'cosign' ? 'aria-invalid="true" aria-describedby="w-cos-e"' : ''}><option value="">Choose a co-signer on shift</option>${(m === 'password' ? ['Daniel Ahn', 'Mere Kahu'] : ['Daniel Ahn']).map((x) => `<option${W.cosigner === x ? ' selected' : ''}>${x}</option>`).join('')}</select>${W.error === 'cosign' ? `<span class="ferr" id="w-cos-e">Choose a co-signer${m === 'C' ? '.' : ' and ask them to enter their ' + (m === 'B' ? 'witness PIN.' : 'password.')}</span>` : '<span class="who-sub">A present, qualified colleague whose own competency isn’t restricted</span>'}</div>`;
        if (m === 'C') {
            const st = W.req || 'none';
            const status = st === 'pending' ? B('info', 'send', `Waiting for ${esc(W.cosigner)} to confirm on their own device`, `They must be with you. The request expires after the organisation’s time limit: ${NC()}`) + `<div class="annot" style="padding:8px 10px"><div class="a-tag">Mockup controls — what the colleague does</div><div style="display:flex;gap:6px;flex-wrap:wrap;margin-top:4px"><button class="btn btn-outline btn-sm" type="button" data-act="req-sim" data-r="approved">Daniel approves</button><button class="btn btn-outline btn-sm" type="button" data-act="req-sim" data-r="declined">Daniel declines</button><button class="btn btn-outline btn-sm" type="button" data-act="req-sim" data-r="expired">Request expires</button></div></div>`
                : st === 'approved' ? B('success', 'check-circle', `Confirmed by ${esc(W.cosigner)} at 9:13 am from their own session`, '')
                    : st === 'declined' ? B('critical', 'x-circle', `${esc(W.cosigner)} declined at 9:13 am`, '“I wasn’t there.” Choose someone else, or record it differently.')
                        : st === 'expired' ? B('warning', 'clock', `The request to ${esc(W.cosigner)} expired`, 'No answer in time. Send it again or choose someone else.') : '';
            return `<div class="fgrid">${sel}<div class="field"><span class="flabel">&nbsp;</span><button class="btn btn-outline" type="button" data-act="req-send" ${st === 'pending' || st === 'approved' ? 'disabled' : ''}>${ic('send')}${st === 'declined' || st === 'expired' ? 'Send again' : 'Send request'}</button>${W.error === 'req' ? '<span class="ferr">Send a request and wait for your co-signer to approve.</span>' : ''}</div></div>${status}`;
        }
        const pwLabel = m === 'B' ? 'Co-signer’s witness PIN' : 'Co-signer password';
        const pwHelp = m === 'B' ? 'Their own witness PIN — not their login password. They can reset it from their own account.' : 'Entered by the co-signer, not by you (today’s implemented method)';
        return `<div class="fgrid">${sel}<div class="field"><label for="w-cpw">${pwLabel} <span class="req">*</span></label><input id="w-cpw" type="password" ${m === 'B' ? 'inputmode="numeric" maxlength="8"' : ''} data-act="wiz-cpw" value="${esc(W.cosignPw || '')}" autocomplete="off" ${W.error === 'cpw' ? 'aria-invalid="true" aria-describedby="w-cpw-e"' : ''}>${W.error === 'cpw' ? `<span class="ferr" id="w-cpw-e">Your co-signer needs to enter their own ${m === 'B' ? 'witness PIN' : 'password'}.</span>` : `<span class="who-sub">${pwHelp}</span>`}</div></div>${m === 'B' ? '<div class="annot" style="padding:6px 10px;font-size:11.5px"><div class="a-tag">Mockup</div>PIN 0000 shows a wrong PIN; 9999 shows a locked PIN; anything else succeeds.</div>' : ''}`;
    }
    function wizNext() {
        if (W.step === 1) {
            if (!W.outcome) { W.error = 'outcome'; renderWizard(); return; }
            if ((W.outcome === 'withheld' || W.outcome === 'away') && !W.reason) { W.error = 'reason'; renderWizard(); $('#w-reason') && $('#w-reason').focus(); return; }
            if (['given', 'prompted', 'assisted', 'reoffered'].includes(W.outcome) && canon(W.time) > '09:12') { W.error = 'time'; renderWizard(false, '#w-time'); return; }
            if (needsCosign() && !W.cosigner) { W.error = 'cosign'; renderWizard(); $('#w-cos') && $('#w-cos').focus(); return; }
            if (needsCosign() && S.method === 'C' && W.req !== 'approved') { W.error = 'req'; renderWizard(false, '[data-act="req-send"]'); return; }
            if (needsCosign() && S.method !== 'C' && !W.cosignPw) { W.error = 'cpw'; renderWizard(); $('#w-cpw') && $('#w-cpw').focus(); return; }
        }
        W.error = null; W.step = Math.min(2, W.step + 1); renderWizard();
    }
    function wizSubmit() {
        W.sending = true; W.rejectedMsg = null; renderWizard();
        setTimeout(() => {
            if (!W) return;
            const lbl = outcomeOptions().find((x) => x.k === W.outcome);
            if (needsCosign() && S.method === 'B' && W.cosignPw === '0000') { W.sending = false; W.rejectedMsg = 'Incorrect PIN. Repeated wrong attempts lock the PIN.'; renderWizard(); return; }
            if (needsCosign() && S.method === 'B' && W.cosignPw === '9999') { W.sending = false; W.rejectedMsg = `${W.cosigner}’s witness PIN is locked after too many wrong attempts. ${W.cosigner} can reset it from their own account (My profile › Witness PIN). Choose another co-signer.`; renderWizard(); return; }
            if (needsCosign() && W.cosigner === 'Mere Kahu') {
                W.sending = false; W.rejectedMsg = 'This co-signer cannot confirm the dose — their own medication competency is restricted or not current. Choose another co-signer.';
                renderWizard(); return;
            }
            if (S.scenario === 'uncertain') {
                W.sending = false; W.uncertain = true; renderWizard();
                if (W.rowKey !== 'prn') { S.rows[W.rowKey] = { state: 'uncertain', line: 'Sent 9:12 am · no confirmation — check the chart before trying again' }; render(true); }
                return;
            }
            if (S.scenario === 'reject' && !W.rejectedOnce) {
                W.sending = false; W.rejectedOnce = true;
                W.rejectedMsg = 'Your competency was checked again when you saved: your competency record changed at 9:10 am and no longer covers this dose.';
                renderWizard();
                if (W.rowKey !== 'prn') { S.rows[W.rowKey] = { state: 'rejected', line: 'Tried 9:12 am · not saved — review needed' }; render(true); }
                return;
            }
            const offline = S.scenario === 'offline';
            const key = W.rowKey;
            const t = ['given', 'prompted', 'assisted', 'reoffered'].includes(W.outcome) ? fmtTime(W.time) : NOW;
            const lineFor = {
                given: `Given ${t} · ${PERSONAS[S.persona].short}`, prompted: `Taken ${t} · ${PERSONAS[S.persona].short} reminded ${W.pp.pref}`, assisted: `Taken ${t} · ${PERSONAS[S.persona].short} helped`,
                refused: `Refused ${t} · ${W.pp.pref} said no`, withheld: `Withheld ${t} · ${W.reason}`, away: `Away · ${W.reason}`, reoffered: `Given ${t} after re-offer · first offered 8:10 am`,
            };
            if (key !== 'prn') S.rows[key] = offline ? { state: 'queued', line: 'Saved on this device · not sent yet' } : { state: W.outcome, line: lineFor[W.outcome] };
            const pref = W.pp.pref, medName = W.r.med, outLabel = lbl ? lbl.l : W.outcome;
            W = null;
            closeDialog(true);
            render(true);
            toast(offline ? 'warning' : 'success', offline ? `Saved on this device — ${medName} for ${pref} isn’t on the chart yet. It will send when you reconnect.` : `Recorded — ${medName} for ${pref}: ${outLabel.toLowerCase()} at ${t}.`);
            restoreFocus();
        }, 1100);
    }

    function toast(kind, msg) {
        const el = document.createElement('div');
        el.className = `toast ${kind}`;
        el.innerHTML = `${ic(kind === 'success' ? 'check-circle' : kind === 'critical' ? 'x-circle' : kind === 'warning' ? 'alert-triangle' : 'info')}<div>${esc(msg)}</div>`;
        $('#toasts').appendChild(el);
        setTimeout(() => el.remove(), 5200);
    }

    /* ───────────── render ───────────── */
    function render(keepScroll) {
        const y = window.scrollY;
        renderViewer();
        const app = $('#app');
        if (S.mode === 'catalogue') {
            app.innerHTML = cataloguePage();
        } else {
            const content = S.mode === 'person' ? personPage() : S.mode === 'record' ? recordPage() : S.mode === 'myday' ? myDayPage() : S.mode === 'handover' ? handoverPage() : hubPage();
            app.innerHTML = `<div class="shell${isCollapsed() ? ' collapsed' : ''}">${topbar()}${sidebar()}<div class="main">${appWideBanner()}${content}</div></div>`;
        }
        fitRails();
        if (keepScroll) window.scrollTo(0, y);
    }

    /* App-wide medication banners (sticky under the top bar, on every page) — implemented in
     * offline-status-banner.tsx by the P0 fix (EM-26). Wording here is the P00 contract. */
    function appWideBanner() {
        if (!has(S.persona, 'administer') || S.persona !== 'sw') return '';
        if (S.scenario === 'offlineRefused' && !S.rejectedAcknowledged) {
            return `<div class="appwide" role="alert">${ic('x-circle')}<span>A saved medication action was <b>not</b> recorded: Paracetamol for Aroha, saved offline at 8:50 am — the as-needed limit on the prescription is reached. It won’t be sent again.</span><span class="aw-actions"><button type="button" data-act="rejected" data-row="prn" data-fk="aw-review">What to do</button><button type="button" data-act="rej-dismiss" data-fk="aw-dismiss">Dismiss</button></span></div>`;
        }
        if (S.scenario === 'uncertain' && !S.rejectedAcknowledged) {
            return `<div class="appwide warn" role="status">${ic('help')}<span>1 saved medication action needs a check: we didn’t get confirmation that Macrogol for Tama (9:04 am) was saved, so it isn’t shown as recorded. Check the chart before trying again.</span><span class="aw-actions"><a href="#/person/${S.persona}/tama/chart" style="color:inherit;font-weight:700">Check the chart</a><button type="button" data-act="toast" data-msg="Sent again — trying again never creates a duplicate (mockup).">Try again</button></span></div>`;
        }
        return '';
    }
    function fitRails() {
        $$('[data-rail]').forEach((rail) => {
            const tabs = $$('.rail-tab', rail), more = $('.rail-more', rail), find = $('.rail-find', rail);
            tabs.forEach((t) => (t.hidden = false)); more.hidden = true;
            if (rail.scrollWidth <= rail.clientWidth + 1) return;
            more.hidden = false;
            const avail = rail.clientWidth - find.offsetWidth - more.offsetWidth - 12;
            const widths = tabs.map((t) => t.offsetWidth + 4);
            const activeIdx = tabs.findIndex((t) => t.classList.contains('on'));
            let keep = [], sum = 0;
            tabs.forEach((t, i) => { if (sum + widths[i] <= avail) { keep.push(i); sum += widths[i]; } });
            if (activeIdx >= 0 && !keep.includes(activeIdx)) {
                while (keep.length && sum + widths[activeIdx] > avail) { const drop = keep.pop(); sum -= widths[drop]; }
                keep.push(activeIdx); keep.sort((a, b) => a - b);
            }
            let alertSum = 0;
            tabs.forEach((t, i) => { if (!keep.includes(i)) { t.hidden = true; const c = $('.rail-count.alert', t); if (c) alertSum += parseInt(c.textContent, 10) || 0; } });
            const mc = $('.rail-count', more);
            if (alertSum) { mc.hidden = false; mc.textContent = alertSum; } else mc.hidden = true;
        });
    }

    function openCtxMenu(spec, x, y, trigger) {
        closeMore();
        const [kind, id] = spec.split(':');
        const items = menuItems(kind, id);
        const fk = trigger && trigger.dataset.fk ? trigger.dataset.fk : `keb-${kind}-${id}`;
        const pop = document.createElement('div');
        pop.className = 'rail-more-pop ctx-menu'; pop.setAttribute('role', 'menu'); pop.id = 'rail-more-pop';
        pop.setAttribute('aria-label', 'Actions');
        pop.innerHTML = menuHtml(items, fk);
        document.body.appendChild(pop);
        const w = pop.offsetWidth, h = pop.offsetHeight;
        pop.style.left = `${Math.max(8, Math.min(x, window.innerWidth - w - 8)) + window.scrollX}px`;
        pop.style.top = `${Math.max(8, Math.min(y, window.innerHeight - h - 8)) + window.scrollY}px`;
        if (trigger) trigger.setAttribute('aria-expanded', 'true');
        pop._trigger = trigger || $(`[data-fk="${fk}"]`);
        const all = $$('.ctx-item', pop);
        if (all[0]) all[0].focus();
        pop.addEventListener('keydown', (e) => {
            const i = all.indexOf(document.activeElement);
            if (e.key === 'Escape') { e.preventDefault(); const tr = pop._trigger; closeMore(); if (tr) tr.focus(); }
            if (e.key === 'ArrowDown') { e.preventDefault(); all[(i + 1) % all.length].focus(); }
            if (e.key === 'ArrowUp') { e.preventDefault(); all[(i - 1 + all.length) % all.length].focus(); }
            if (e.key === 'Home') { e.preventDefault(); all[0].focus(); }
            if (e.key === 'End') { e.preventDefault(); all[all.length - 1].focus(); }
            if (e.key === 'Tab') { e.preventDefault(); const tr = pop._trigger; closeMore(); if (tr) tr.focus(); }
        });
    }
    function openMore(btn) {
        const rail = btn.closest('[data-rail]');
        const hidden = $$('.rail-tab[hidden]', rail);
        closeMore();
        const pop = document.createElement('div');
        pop.className = 'rail-more-pop'; pop.setAttribute('role', 'menu'); pop.id = 'rail-more-pop';
        pop.innerHTML = hidden.map((t) => `<button type="button" role="menuitem" data-act="more-go" data-href="${t.getAttribute('href')}">${t.innerHTML}</button>`).join('');
        document.body.appendChild(pop);
        const r = btn.getBoundingClientRect();
        pop.style.top = `${r.bottom + window.scrollY + 4}px`;
        pop.style.left = `${Math.max(8, Math.min(r.left + window.scrollX, window.innerWidth - 240))}px`;
        btn.setAttribute('aria-expanded', 'true');
        const first = $('button', pop); if (first) first.focus();
        pop.addEventListener('keydown', (e) => {
            const items = $$('button', pop); const i = items.indexOf(document.activeElement);
            if (e.key === 'Escape') { closeMore(); btn.focus(); }
            if (e.key === 'ArrowDown') { e.preventDefault(); items[(i + 1) % items.length].focus(); }
            if (e.key === 'ArrowUp') { e.preventDefault(); items[(i - 1 + items.length) % items.length].focus(); }
        });
    }
    function closeMore() { const p = $('#rail-more-pop'); if (p) p.remove(); $$('.rail-more, .kebab').forEach((b) => b.setAttribute('aria-expanded', 'false')); }

    /* ───────────── events ───────────── */
    document.addEventListener('click', (e) => {
        const t = e.target.closest('[data-act]');
        if (!t) { if (!e.target.closest('#rail-more-pop')) closeMore(); return; }
        const act = t.dataset.act;
        const p = S.persona;
        if (t.closest('#rail-more-pop.ctx-menu') && !['menu-go'].includes(act)) { lastFocusEl = null; closeMore(); }
        switch (act) {
            case 'skip': e.preventDefault(); { const m = $('#main'); if (m) m.focus(); } break;
            case 'mode': location.hash = t.dataset.mode === 'catalogue' ? '#/catalogue/intro' : hrefFrame(S.persona, visibleHubs(S.persona)[0].id); break;
            case 'noop': break;
            case 'toast': e.preventDefault(); toast('info', t.dataset.msg); break;
            case 'toast-outside': e.preventDefault(); toast('info', 'Outside this mockup — other modules are unchanged.'); break;
            case 'collapse': S.forceCollapse = !isCollapsed(); render(true); $('.sb-handle') && $('.sb-handle').focus(); break;
            case 'clock-in': {
                if (t.dataset.close) closeDialog(true);
                S.clockedIn = true; if (S.scenario === 'notClockedIn') S.scenario = 'normal';
                location.hash = hrefFrame(p, 'today', S.view === 'none' ? 'schedule' : S.view);
                render(true); toast('success', 'Clocked in at 9:12 am — Kōwhai House, 7:00 am–3:00 pm shift.');
                break;
            }
            case 'eligibility': if (t.dataset.close) closeDialog(true); openEligibility(); break;
            case 'why': { const k = t.dataset.row, r = MEDS[k]; openWhy(rowBlock(k), { p: PEOPLE[r.pid].pref, med: r.med, time: r.slot, pid: r.pid, support: r.support, row: k }); break; }
            case 'why-cat': { openWhy(t.dataset.block, { p: t.dataset.p, med: t.dataset.med, pid: Object.values(PEOPLE).find((x) => x.pref === t.dataset.p)?.id }); break; }
            case 'record': if (S.scenario === 'notClockedIn' && S.mode !== 'catalogue') { const k = t.dataset.row, r = MEDS[k]; openWhy('notClockedIn', { p: PEOPLE[r.pid].pref, med: r.med, time: r.slot, pid: r.pid, support: r.support }); break; } openRecord(t.dataset.row, { notGivenOnly: !!(MEDS[t.dataset.row] && rowBlock(t.dataset.row)) }); break;
            case 'record-notgiven': { const k = t.dataset.row; closeDialog(true); openRecord(k, { notGivenOnly: true }); break; }
            case 'prn': openRecord('prn'); break;
            case 'rejected': if (t.dataset.row === 'prn') openRejected('prn'); else openDetail(t.dataset.row); break;
            case 'rej-dismiss': S.rejectedAcknowledged = true; closeDialog(true); render(true); toast('info', 'Dismissed. The refused attempt stays in the audit trail.'); { const m = $('#main'); if (m) m.focus(); } break;
            case 'rej-tell': S.rejectedAcknowledged = true; closeDialog(true); render(true); toast('success', 'Sent to Jordan Tipene (house lead) with the details of the refused record.'); { const m = $('#main'); if (m) m.focus(); } break;
            case 'detail': openDetail(t.dataset.row); break;
            case 'fu-record': toast('info', 'The effect-check dialog is designed in P08a. No result is preselected.'); break;
            case 'fu-reoffer': openRecord('r4', { reoffer: true }); W.step = 1; renderWizard(); break;
            case 'close': closeDialog(); break;
            case 'close-nav': closeDialog(true); break;
            case 'backdrop': if (e.target === t) closeDialog(); break;
            case 'find': openPalette(); break;
            case 'rail-more': if ($('#rail-more-pop')) { closeMore(); } else openMore(t); break;
            case 'menu': { if ($('#rail-more-pop') && t.getAttribute('aria-expanded') === 'true') { closeMore(); break; } const rc = t.getBoundingClientRect(); openCtxMenu(t.dataset.menu, rc.right - 240, rc.bottom + 4, t); break; }
            case 'menu-go': closeMore(); location.hash = t.dataset.href; break;
            case 'more-go': closeMore(); location.hash = t.dataset.href; break;
            case 'wiz-next': wizNext(); break;
            case 'wiz-back': W.error = null; W.step = Math.max(0, W.step - 1); renderWizard(); break;
            case 'wiz-step': { const s = +t.dataset.step; if (s <= W.step) { W.step = s; W.error = null; renderWizard(); } break; }
            case 'wiz-outcome': if (t.getAttribute('aria-disabled') === 'true') { toast('info', 'This outcome can’t be recorded right now — see the reason under it.'); break; } W.outcome = t.dataset.k; W.error = null; W.reason = W.outcome === 'withheld' && W.block && BLOCKS[W.block].safety ? 'Safety check blocked it' : W.reason; renderWizard(); { const b = $(`.tile[data-k="${W.outcome}"]`); if (b) b.focus(); } break;
            case 'wiz-submit': if (W && W.tp) { W.tp = null; } wizSubmit(); break;
            case 'sr-save': openSafetyConfirm(); break;
            case 'ho-ack': S.handoverAck = true; render(true); toast('success', 'Handover acknowledged. Medication follow-ups stay open.'); { const m = $('#main'); if (m) m.focus(); } break;
            case 'req-send': if (!W.cosigner) { W.error = 'cosign'; renderWizard(false, '#w-cos'); break; } W.req = 'pending'; W.error = null; renderWizard(false, '[data-act="req-sim"]'); break;
            case 'req-sim': W.req = t.dataset.r; renderWizard(false, W.req === 'approved' ? '#wiz-foot .btn-primary' : '[data-act="req-send"]'); break;
            case 'sr-confirm': { SAFETY_RULES.forEach((r) => { if (S.safetyDraft[r.key] !== S.safety[r.key]) S.safetySetBy[r.key] = 'Hana Kereama, 29 Sep 2026 9:12 am'; }); S.safety = { ...S.safetyDraft }; closeDialog(true); render(true); toast('success', 'Safety rules saved. They apply from the next dose signed, at every site.'); const hd = $('#sr-h'); if (hd) hd.focus(); break; }
            case 'tp-open': if (W.tp) { W.tp = null; renderWizard(false, '#w-time'); break; } W.tp = { ...W.time, mode: 'clock', face: 'hours', hText: String(W.time.h), mText: pad2(W.time.m), err: null }; renderWizard(false, '#tp-h'); break;
            case 'tp-mode': W.tp.mode = t.dataset.mode; renderWizard(false, t.dataset.mode === 'type' ? '#tp-h' : `[data-act="tp-mode"][data-mode="clock"]`); break;
            case 'tp-dial': if (W.tp.face === 'hours') { W.tp.hText = t.dataset.val; W.tp.face = 'minutes'; W.tp.err = null; renderWizard(false, `.tp-face button[aria-pressed="true"], .tp-face button`); } else { W.tp.mText = pad2(+t.dataset.val); W.tp.err = null; renderWizard(false, `.tp-face button[data-val="${t.dataset.val}"]`); } break;
            case 'tp-ap': W.tp.ap = t.dataset.ap; renderWizard(false, `[data-act="tp-ap"][data-ap="${t.dataset.ap}"]`); break;
            case 'tp-cancel': W.tp = null; renderWizard(false, '#w-time'); break;
            case 'tp-apply': tpApply(); break;
            default: break;
        }
    });
    document.addEventListener('change', (e) => {
        const t = e.target;
        if (t.dataset.act === 'persona') { S.persona = t.value; S.rows = {}; const hubs = visibleHubs(S.persona); location.hash = S.mode === 'person' ? `#/person/${S.persona}/${S.pid}/${S.ptab}` : hrefFrame(S.persona, hubs[0].id); }
        if (t.dataset.act === 'scenario') { S.scenario = t.value; S.rows = {}; S.rejectedAcknowledged = false; S.clockedIn = t.value !== 'notClockedIn'; const base = location.hash.split('?')[0] || hrefFrame(S.persona, 'today', 'schedule'); history.replaceState(null, '', `${base}?scenario=${t.value}`); render(); }
        if (t.dataset.act === 'wiz-reason' && W) { W.reason = t.value; if (W.error === 'reason' && t.value) W.error = null; }
        if (t.dataset.act === 'sr-sel') { S.safetyDraft[t.dataset.key] = t.value; const btn = $('[data-act="sr-save"]'); if (btn) btn.disabled = !SAFETY_RULES.some((r) => S.safetyDraft[r.key] !== S.safety[r.key]); }
        if (t.dataset.act === 'method') { S.method = t.value; S.rows = {}; render(); }
        if (t.dataset.act === 'wiz-cos' && W) { W.cosigner = t.value; W.req = 'none'; W.rejectedMsg = null; if (W.error === 'cosign' && t.value) W.error = null; }
    });
    document.addEventListener('contextmenu', (e) => {
        const row = e.target.closest('[data-menu]');
        if (!row || e.target.closest('input, textarea, select')) return;
        e.preventDefault();
        openCtxMenu(row.dataset.menu, e.clientX, e.clientY, $('.kebab', row));
    });
    document.addEventListener('keydown', (e) => {
        if (!((e.key === 'F10' && e.shiftKey) || e.key === 'ContextMenu')) return;
        const row = document.activeElement && document.activeElement.closest && document.activeElement.closest('[data-menu]');
        if (!row || $('#dialog-root').innerHTML) return;
        e.preventDefault();
        const rc = document.activeElement.getBoundingClientRect();
        openCtxMenu(row.dataset.menu, rc.left, rc.bottom + 4, $('.kebab', row));
    });
    document.addEventListener('input', (e) => { if (e.target.dataset.act === 'wiz-note' && W) W.note = e.target.value; if (W && W.tp && e.target.dataset.act === 'tp-h') { W.tp.hText = e.target.value.trim(); W.tp.face = 'hours'; if (W.tp.err === 'h') W.tp.err = null; } if (W && W.tp && e.target.dataset.act === 'tp-m') { W.tp.mText = e.target.value.trim(); W.tp.face = 'minutes'; if (W.tp.err === 'm') W.tp.err = null; } if (e.target.dataset.act === 'wiz-cpw' && W) { W.cosignPw = e.target.value; if (W.error === 'cpw') W.error = null; } });
    document.addEventListener('keydown', (e) => {
        if (e.key === '/' && !$('#dialog-root').innerHTML && !/INPUT|TEXTAREA|SELECT/.test(document.activeElement.tagName)) { const s = $('.eh-search input'); if (s) { e.preventDefault(); s.focus(); } }
    });

    window.addEventListener('hashchange', () => {
        closeMore();
        if ($('#dialog-root').innerHTML) closeDialog(true);
        const prevMode = S.mode, prevSection = S.section;
        parseHash();
        if (S.mode === 'catalogue' && prevMode === 'catalogue') {
            renderViewer();
            $$('.cat-index a').forEach((a) => a.setAttribute('aria-current', a.dataset.sec === S.section ? 'true' : 'false'));
            const sec = $(`#sec-${S.section}`); if (sec) sec.scrollIntoView({ block: 'start' });
            return;
        }
        render();
        if (S.mode === 'catalogue') { const sec = $(`#sec-${S.section}`); if (sec && S.section !== 'intro') sec.scrollIntoView({ block: 'start' }); }
        else window.scrollTo(0, 0);
        void prevSection;
        openFromQuery();
    });
    let rz;
    window.addEventListener('resize', () => { clearTimeout(rz); rz = setTimeout(() => { if (S.mode !== 'catalogue') { const want = isCollapsed(); const sh = $('.shell'); if (sh && sh.classList.contains('collapsed') !== want) render(true); else fitRails(); } }, 120); });

    // Deep-link a single dialog for screenshots: ?open=record:r2 | why:notClockedIn | prn | rejected | eligibility
    function openFromQuery() {
        const q = new URLSearchParams((location.hash.split('?')[1]) || '');
        const o = q.get('open'); if (!o) return;
        const [kind, arg, step] = o.split(':');
        if (kind === 'record') { openRecord(arg, { notGivenOnly: !!(MEDS[arg] && rowBlock(arg)) }); if (step) { W.step = +step; if (+step >= 1 && q.get('outcome')) W.outcome = q.get('outcome'); if (q.get('reason')) W.reason = q.get('reason'); if (q.get('err')) W.error = q.get('err'); if (q.get('tp')) W.tp = { ...W.time, mode: q.get('tp') === 'type' ? 'type' : 'clock', face: q.get('face') || 'hours', hText: '9', mText: '12', err: null }; renderWizard(false, q.get('tp') ? '#tp-h' : undefined); } }
        if (kind === 'reoffer') { openRecord('r4', { reoffer: true }); W.step = 1; renderWizard(); }
        if (kind === 'why') { const r = MEDS[arg]; if (r) openWhy(rowBlock(arg), { p: PEOPLE[r.pid].pref, med: r.med, time: r.slot, pid: r.pid, support: r.support, row: arg }); }
        if (kind === 'prn') openRecord('prn');
        if (kind === 'rejected') openRejected('prn');
        if (kind === 'eligibility') openEligibility();
        if (kind === 'find') openPalette();
        if (kind === 'menu') { const kb = $('[data-menu="' + arg + ':' + step + '"] .kebab'); if (kb) { const rc = kb.getBoundingClientRect(); openCtxMenu(arg + ':' + step, rc.right - 240, rc.bottom + 4, kb); } }
    }

    if (!location.hash) history.replaceState(null, '', '#/frame/sw/today/schedule');
    parseHash();
    render();
    if (S.mode === 'catalogue' && S.section !== 'intro' && !S.only) { const sec = $(`#sec-${S.section}`); if (sec) sec.scrollIntoView({ block: 'start' }); }
    openFromQuery();
})();
