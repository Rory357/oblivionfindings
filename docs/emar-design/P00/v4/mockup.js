/* eMAR P00 v4 — Medication rules & states. Clickable design mockup, synthetic data only.
 * No application code, routes, schema or configuration. Every state specimen in the
 * catalogue is rendered by the same function the navigation frame uses, so wording and
 * treatment cannot drift between the two. */
(() => {
    'use strict';

    const VERSION = 'P00 v4';
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
        // v4: senior role that can grant time-limited controlled-drug witness overrides ('cd.override' — permission key is a decision).
        pm: { id: 'pm', name: 'Rangi Parata', short: 'Rangi P.', initials: 'RP', role: 'Provider manager', perms: [...ALL, 'cd.override'] },
        auditor: { id: 'auditor', name: 'Alex Morgan', short: 'Alex M.', initials: 'AM', role: 'Auditor', perms: ['view', 'audit.view'] },
        finance: { id: 'finance', name: 'Kiri Thompson', short: 'Kiri T.', initials: 'KT', role: 'Finance', perms: ['view', 'reports.export', 'stock.update'] },
    };
    const has = (p, k) => PERSONAS[p].perms.includes(k);
    const allSites = (p) => ['clinical', 'pm'].includes(p); // all-sites medication settings authority (mockup)
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
                { id: 'overrides', label: 'Witness overrides', icon: 'shield', url: 'new — time-limited controlled-drug witness overrides, requests and history', gate: 'lead capability (grant: witness-override permission — decision)', pkg: 'P07a', ok: (p) => leadCap(p) },
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
                { id: 'rules', label: 'Medication rules', icon: 'settings', url: '/emar/settings (was “Administration rules”)', gate: 'settings.manage', pkg: 'P11', ok: () => true },
                { id: 'templates', label: 'Round templates', icon: 'repeat', url: 'moved from Rounds', gate: 'settings.manage', pkg: 'P11', ok: () => true },
                { id: 'secondperson', label: 'Second-person confirmation', icon: 'users', url: 'new — PIN rules and staff PIN status', gate: 'settings.manage', pkg: 'P11', ok: () => true },
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
            next: ['Ask a competent colleague to give this dose. On shift now and able to give it: <b>Daniel Ahn</b>, <b>Jordan Tipene</b> (from the roster and who is clocked in).', 'Ask a competency assessor to review the restriction.'],
            still: 'notgiven', action: { label: 'View my eligibility', act: 'eligibility', icon: 'user-check' },
            gate: 'Organisation safety rule “restricted competency” set to Block. Off by default. Stephan set Block on 29 Sep 2026 (NF-03). Review recommendation: move to Co-signer with witness PIN once the PIN is built; never a co-signer by login password.', depends: ['D3', 'NF-03 setting'], fixes: ['NF-03'],
            note: 'Shown at the start of the dose and as-needed wizards, only when the organisation sets this rule. With Off, no notice shows and the restriction isn’t enforced.',
        },
        restrictedCosigner: {
            title: 'Co-signer required', icon: 'users', cosigner: true,
            text: 'Your medication competency is restricted (Hana Kereama, 2 March 2026: “supervised practice until reassessed”). A present, qualified co-signer must confirm each dose you sign as given.',
            next: ['Choose a co-signer who is on shift now and whose own competency is current and not restricted.', 'They type their own 6-digit witness PIN on your screen — never their login password.'],
            still: 'Refusals, withheld doses and absences don’t need a co-signer.', action: { label: 'View my eligibility', act: 'eligibility', icon: 'user-check' },
            gate: 'Organisation safety rule “restricted competency” set to Co-signer. Confirmation is by the colleague’s 6-digit witness PIN (Stephan, 29 Sep 2026). The server rejects a co-signer whose own competency is restricted or not current.', depends: ['D3', 'NF-03 setting'], fixes: ['NF-03'],
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
            text: '{med} is a controlled medicine and needs a witness: a different person, clocked in on a shift covering Kōwhai House now, with controlled-medicine witness competency and a witness PIN set. Checked against the roster and clock-ins at 9:12 am: nobody meets all of them.',
            next: ['Ask a manager for a witness override. They see the same roster and can allow it for a limited time.', `Or contact the coordinator on call: ${NC()}`, 'Don’t ask someone who isn’t eligible to witness.'],
            still: 'withheld', roster: true, action: { label: 'Ask a manager for a witness override', act: 'ovr-request', icon: 'send' },
            gate: 'Witness independence, presence (clocked in on a shift covering the house), witness competency and a witness PIN set (server rule kept; PIN = Stephan’s option B). The override request is new (v4).', depends: ['D8', 'D12'], fixes: ['EM-03', 'EM-25'],
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
            next: ['Don’t give another dose.', `If {p} still needs relief, contact the prescriber or on-call contact: ${NC()}`, 'If the prescriber advises another dose, record their advice first — who, when and how. Only then can someone authorised to override the limit allow it. A colleague’s PIN is not authority for another dose.'],
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
            ${b.roster ? rosterEvidence('now', 'you') : ''}
            <ul class="bl-next">${b.next.map((n) => `<li>${tpl(n, { p: esc(c.p), med: esc(c.med) })}</li>`).join('')}</ul>
            ${stillHtml}${bg}${ov}
            <div class="bl-foot">Checked again when you save.</div>
        </div>`;
    }

    /* Roster and clock-in evidence (Stephan, 29 Sep 2026): “no eligible colleague” comes from who is clocked in
     * on a shift covering the house right now, who holds witness competency and who has a witness PIN set.
     * The same evidence is shown to the worker, on the override request and on the manager's approve screen. */
    const ROSTER = {
        now: {
            title: 'Roster and clock-ins · Kōwhai House · checked 9:12 am NZDT',
            rows: [
                { name: 'Priya Shah', shift: '7:00 am–3:00 pm', now: 'clocked in 7:02 am', self: true },
                { name: 'Mere Kahu', shift: '7:00 am–3:00 pm', now: 'clocked in 6:58 am', why: 'no witness competency · no witness PIN set' },
                { name: 'Daniel Ahn', shift: '11:00 pm–9:00 am (night)', now: 'clocked out 9:03 am', why: 'not on shift now' },
                { name: 'Jordan Tipene', shift: '3:00 pm–11:00 pm', now: 'not started', why: 'not on shift yet' },
            ],
            result: 'Nobody else on shift can witness right now. Next witness-eligible staff member: Jordan Tipene from 3:00 pm.',
            summary: '1 witness-eligible staff member rostered 7:00 am–3:00 pm, Kōwhai House',
        },
        night: {
            title: 'Roster · Kōwhai House · 10:00 pm Mon 28 Sep – 7:00 am Tue 29 Sep',
            rows: [
                { name: 'Mere Kahu', shift: '10:00 pm–7:00 am (night)', now: 'rostered', note: 'the only staff member rostered' },
            ],
            result: 'Single staffing: 1 staff rostered 10:00 pm–7:00 am. Kōwhai House holds controlled drugs — 1 controlled dose falls in this window (Grace, clonazepam, 6:00 am).',
            summary: '1 staff rostered 10:00 pm–7:00 am, Kōwhai House',
        },
    };
    function rosterEvidence(kind = 'now', selfLabel = 'you') {
        const R0 = ROSTER[kind];
        return `<div class="roster-ev" role="group" aria-label="Roster evidence"><div class="re-h">${ic('calendar', 's35')}<b>${esc(R0.title)}</b></div>
            <ul class="re-list">${R0.rows.map((x) => `<li><span class="re-n">${esc(x.name)}${x.self ? ` <span class="who-sub">(${selfLabel})</span>` : ''}</span><span class="re-s">${esc(x.shift)} · ${esc(x.now)}</span><span class="re-v">${x.self ? (selfLabel === 'you' ? 'You — the witness must be someone else' : 'Asking — the witness must be someone else') : x.note ? esc(x.note) : `${ic('x', 's3')} ${esc(x.why)}`}</span></li>`).join('')}</ul>
            <div class="re-f">${ic('info', 's3')} ${esc(R0.result)}</div></div>`;
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
        // v4: a controlled dose due now, shown only in the three controlled-drug witness scenarios.
        r11: { slot: '9:00 am', pid: 'grace', med: 'Clonazepam', str: '0.5 mg tablet', dose: '1 tablet', ins: 'In the morning · needs a witness', support: 'administer', state: 'due', line: 'Due now · 9:00 am · needs a witness', cd: true, scn: ['cdWitness', 'cdNoWitness', 'cdOverride'] },
    };
    const medKeys = () => Object.keys(MEDS).filter((k) => (!MEDS[k].scn || MEDS[k].scn.includes(S.scenario)) && (!MEDS[k].cd || has(S.persona, 'cd.view'))); // scenario rows + CD concealment for counts
    // v4: a variable (range) order — the actual amount is chosen, never defaulted (EM-08).
    const PRN = { pid: 'aroha', med: 'Paracetamol', str: '500 mg tablet', dose: '1–2 tablets', range: [1, 2], ins: 'For pain · 1 or 2 tablets, up to 4 doses in 24 hours (from the prescription)' };

    /* ───────────── router state ───────────── */
    const S = {
        mode: 'frame', persona: 'sw', hub: 'today', view: 'schedule', pid: 'aroha', ptab: 'chart', section: 'intro', scenario: 'normal',
        rows: {}, rejectedAcknowledged: false, clockedIn: true, forceCollapse: null,
        orderConfirmed: false, method: 'B', nobody: false, myPin: 'set', fallbackDemo: null,
        // v4
        ovRequest: null, ovRequestCover: 'dose', ovDecline: '', ovGranted: [], ovRevoked: {}, rulesDemo: 'loaded', ruleHistory: [], cdHistory: [],
        cdw: { org: 'on', kowhai: 'org', rimu: 'org', suggest: 'proposed' }, cdwDraft: { org: 'on', kowhai: 'org', rimu: 'org', suggest: 'proposed' },
        pinRules: { attempts: '', lockout: '', renewal: '', fallback: 'nc', fallbackCd: 'nc', resetRoles: 'nc', confirmLimit: '', routeTo: 'nc' },
        pinDraft: { attempts: '', lockout: '', renewal: '', fallback: 'nc', fallbackCd: 'nc', resetRoles: 'nc', confirmLimit: '', routeTo: 'nc' }, pinHistory: [],
        safety: { profileAllergy: 'warn', restricted: 'block', area: 'failed', amount: 'avail' }, safetyDraft: { profileAllergy: 'warn', restricted: 'block', area: 'failed', amount: 'avail' }, safetySetBy: { profileAllergy: 'Stephan, 29 Sep 2026 — mode 3 still open', restricted: 'Stephan’s decision, 29 Sep 2026', area: 'Stephan’s decision, 29 Sep 2026', amount: 'Stephan’s decision, 29 Sep 2026' },
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
        ['cdWitness', 'Controlled dose — witness on shift'],
        ['cdNoWitness', 'Controlled dose — no eligible witness'],
        ['cdOverride', 'Controlled dose — witness override active'],
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
        if (q.get('fallback')) { S.pinRules.fallback = q.get('fallback'); S.pinDraft.fallback = q.get('fallback'); }
        if (q.get('pin')) S.myPin = q.get('pin');
        if (q.get('nobody') !== null) S.nobody = q.get('nobody') === '1';
        if (q.get('ovreq')) S.ovRequest = q.get('ovreq') === 'none' ? null : q.get('ovreq');
        if (q.get('rules')) S.rulesDemo = q.get('rules');
        if (seg[0] === 'catalogue') { S.mode = 'catalogue'; S.section = seg[1] || 'intro'; return; }
        if (seg[0] === 'person') { S.mode = 'person'; S.persona = PERSONAS[seg[1]] ? seg[1] : 'sw'; S.pid = PEOPLE[seg[2]] ? seg[2] : 'aroha'; S.ptab = seg[3] || 'chart'; return; }
        if (seg[0] === 'mypin') { S.mode = 'mypin'; S.persona = PERSONAS[seg[1]] ? seg[1] : 'sw'; return; }
        if (seg[0] === 'tasks') { S.mode = 'tasks'; S.persona = PERSONAS[seg[1]] ? seg[1] : 'sw'; return; }
        if (seg[0] === 'mycal') { S.mode = 'mycal'; S.persona = PERSONAS[seg[1]] ? seg[1] : 'sw'; return; }
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
            ${has(S.persona, 'administer') && isFrontline(S.persona) ? `<span class="v-group"><label for="v-nb">Colleagues on shift</label><select id="v-nb" data-act="nobody-demo"><option value="0"${S.nobody ? '' : ' selected'}>Others on shift</option><option value="1"${S.nobody ? ' selected' : ''}>Nobody else on shift</option></select></span>` : ''}
            ${S.scenario === 'restrictedCosigner' ? `<span class="v-group"><label for="v-fb">Forgotten-PIN fallback (organisation setting)</label><select id="v-fb" data-act="fallback-demo">${[['nc', 'Not configured — not allowed'], ['yes', 'Allowed'], ['no', 'Not allowed']].map(([k, l]) => `<option value="${k}"${k === S.pinRules.fallback ? ' selected' : ''}>${l}</option>`).join('')}</select></span>` : ''}` : ''}
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
        const late = medKeys().filter((k) => rowState(k) === 'late').length;
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
            <a class="sb-item" href="#/mycal/${p}" ${S.mode === 'mycal' ? 'aria-current="page"' : ''}>${ic('calendar')}<span class="sb-label">My Calendar</span></a>
            <a class="sb-item" href="#/tasks/${p}" ${S.mode === 'tasks' ? 'aria-current="page"' : ''}>${ic('list-todo')}<span class="sb-label">All Tasks</span><span class="sb-count" aria-label="3 overdue">3</span></a>
            <div class="sb-divider" role="separator"></div>
            ${mod('building', 'Sites & Locations')}
            ${mod('activity', 'Operations')}
            ${mod('briefcase', 'Workforce')}
            ${med}
            ${mod('stethoscope', 'Health & Clinical')}
            ${mod('alert-triangle', 'Incidents', 1)}
            <div class="sb-bottom"><div class="sb-divider" role="separator"></div>
            <a class="sb-item" href="#/mypin/${p}" ${S.mode === 'mypin' ? 'aria-current="page"' : ''}>${ic('settings')}<span class="sb-label">Settings</span></a></div>
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
        if (k === 'r11' && S.scenario === 'cdNoWitness' && S.ovRequest !== 'approved') return 'noWitness';
        return MEDS[k].block || null;
    }
    /* Witness override that covers a controlled dose right now (scenario, or approved at runtime). */
    function cdOverrideFor(k) {
        if (!MEDS[k] || !MEDS[k].cd) return null;
        if (S.scenario === 'cdOverride') return overrideById('wo5');
        if (S.scenario === 'cdNoWitness' && S.ovRequest === 'approved') return overrideById('wo6');
        return null;
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
                    if (block === 'noWitness') items.push(S.ovRequest === 'waiting' ? { label: 'Ask a manager for a witness override', icon: 'send', disabled: true, reason: 'Request sent at 9:13 am — waiting for a manager' } : { label: 'Ask a manager for a witness override', icon: 'send', act: 'ovr-request', data: { row: id } });
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
        } else if (kind === 'staffpin') {
            const x = STAFF_PINS.find((y) => y.name === id);
            const cfg = S.pinRules.resetRoles !== 'nc';
            items.push(cfg && allSites(S.persona) ? { label: 'Reset PIN (they must set a new one)', icon: 'refresh', act: 'pin-reset', data: { name: id } } : { label: 'Reset PIN', icon: 'refresh', disabled: true, reason: cfg ? 'Your role can’t reset PINs' : 'Who can reset PINs isn’t configured' });
            items.push({ sep: true });
            items.push({ label: 'Open staff eligibility', icon: 'user-check', act: 'toast', msg: 'Staff eligibility is designed in P11.' });
            void x;
        } else if (kind === 'rule') {
            const r = ruleById(id);
            const can = allSites(p);
            const ro = 'Only someone who manages medication settings for all sites can change rules';
            items.push(can ? { label: 'Edit rule', icon: 'pencil', act: 'rule-edit', data: { id } } : { label: 'Edit rule', icon: 'pencil', disabled: true, reason: ro });
            items.push(can ? { label: r.active ? 'Pause rule' : 'Resume rule', icon: r.active ? 'pause' : 'check', act: 'rule-toggle', data: { id } } : { label: r.active ? 'Pause rule' : 'Resume rule', icon: 'pause', disabled: true, reason: ro });
            items.push({ sep: true });
            items.push({ label: 'View change history', icon: 'history', act: 'rule-history', data: { id } });
        } else if (kind === 'override') {
            const o = overrideById(id);
            const can = has(p, 'cd.override');
            const live = ['active', 'soon', 'scheduled'].includes(o.state);
            items.push({ label: 'View details', icon: 'info', act: 'ovr-detail', data: { id } });
            if (live) items.push(can ? { label: 'Revoke override', icon: 'x-circle', act: 'ovr-revoke', data: { id } } : { label: 'Revoke override', icon: 'x-circle', disabled: true, reason: 'Only people allowed to grant witness overrides can revoke them' });
            items.push({ sep: true });
            items.push({ label: 'Open the roster for this house', icon: 'calendar', act: 'toast-outside' });
        } else if (kind === 'ovreq') {
            const can = has(p, 'cd.override');
            items.push({ label: can ? 'Review and approve' : 'View request', icon: 'check', act: 'ovr-grant', data: { mode: 'request' } });
            items.push(can ? { label: 'Decline', icon: 'x', act: 'ovr-grant', data: { mode: 'request', decline: '1' } } : { label: 'Decline', icon: 'x', disabled: true, reason: 'Only people allowed to grant witness overrides can answer' });
            items.push({ sep: true });
            items.push({ label: 'Open the roster for this house', icon: 'calendar', act: 'toast-outside' });
        } else if (kind === 'mtask') {
            items.push({ label: 'Open in Meds today', icon: 'pill', act: 'go', href: hrefFrame(p, 'today', 'schedule') });
            items.push({ label: 'Why is this on my list?', icon: 'help', act: 'toast', msg: 'You’re rostered on a shift covering Kōwhai House from 7:00 am to 3:00 pm, and these doses fall inside it. It completes by itself when every dose has an outcome.' });
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
        const sr = S.rows[k] || {};
        const SECOND_L = { cosign: 'Co-signed by', witness: 'Witnessed by', amount: 'Different amount confirmed by' };
        const pinLine = sr.second && sr.second.forgot ? `<span class="state-line" style="color:var(--status-warning);font-weight:600">${ic('alert-triangle', 's3')} Second person not verified — PIN forgotten · waiting for ${esc(sr.second.name)} to confirm</span>` : sr.second ? `<span class="state-line">${ic('users', 's3')} ${SECOND_L[sr.second.kind]} ${esc(sr.second.name)} (witness PIN)</span>` : '';
        const warnL = (i, t) => `<span class="state-line" style="color:var(--status-warning);font-weight:600">${ic(i, 's3')} ${t}</span>`;
        const ov = !S.rows[k] && ['due', 'late'].includes(st) ? cdOverrideFor(k) : null;
        const amtLine = [
            sr.overdose ? `<span class="state-line" style="color:var(--status-critical);font-weight:600">${ic('alert-octagon', 's3')} More than ordered — medication error ${sr.overdose.err} and incident ${sr.overdose.inc} created</span>` : '',
            sr.notConfirmed ? warnL('alert-triangle', 'Different amount not confirmed by a second person · follow-up for the house lead') : '',
            sr.rx ? warnL('file-text', `Prescriber’s phone instruction (${esc(sr.rx.who)}) · waiting for a lead to countersign`) : '',
            sr.override ? warnL('shield', `No witness — override by ${esc(sr.override.by)}: ${esc(sr.override.reason)} · follow-up for the house lead next shift`) : '',
            ov ? warnL('shield', `Witness override by ${esc(ov.by)} until ${esc(ov.endShort)} — can be recorded without a witness`) : '',
            !S.rows[k] && k === 'r11' && block === 'noWitness' && S.ovRequest === 'waiting' ? `<span class="state-line">${ic('send', 's3')} Witness override requested 9:13 am · waiting for a manager</span>` : '',
            !S.rows[k] && k === 'r11' && block === 'noWitness' && S.ovRequest === 'declined' ? `<span class="state-line" style="color:var(--status-critical);font-weight:600">${ic('x-circle', 's3')} Override declined by Rangi Parata: “${esc(S.ovDecline || 'Jordan can come in at 10:00 am to witness')}” · record it as not given, or wait</span>` : '',
        ].join('');
        const extra = r.fu && st === 'refused' ? `<span class="state-line">${ic('flag', 's3')} ${esc(r.fu)}</span>` : '';
        return `<div class="dose-row" data-row-id="${k}" data-menu="dose:${k}">
            <div class="who"><span class="disc" aria-hidden="true">${pp.initials}</span><div style="min-width:0"><a class="who-name name-link" href="#/person/${persona}/${pp.id}/chart">${esc(pp.pref)}</a><div class="who-sub">${esc(pp.surname)}</div></div></div>
            <div style="min-width:0"><div class="med-name">${esc(r.med)} <span style="font-weight:500;color:var(--muted-foreground)">${esc(r.str)}</span></div>
                <div class="med-sub">${supportChip(r.support)}${r.cd ? `<span class="chipn">${ic('shield', 's3')}Controlled</span>` : ''}<span>${esc(r.dose)} · ${esc(r.ins)}</span></div></div>
            <div class="state-cell">${dbadge(st)}<span class="state-line">${esc(rowLine(k))}</span>${blockLine}${allergyLine}${compLine}${pinLine}${amtLine}${extra}</div>
            <div class="row-actions">${actions}${kebab('dose', k, `${pp.pref}, ${r.med}`)}</div>
        </div>`;
    }

    /* ───────────── Meds today views ───────────── */
    function medsTodayHeader(view) {
        const p = S.persona, scn = S.scenario;
        const hub = hubById('today');
        const views = visibleViews(p, hub);
        const lateN = medKeys().filter((k) => rowState(k) === 'late').length;
        const dueN = medKeys().filter((k) => rowState(k) === 'due').length;
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
            const recorded = medKeys().filter((k) => ['given', 'refused', 'assisted', 'prompted', 'withheld', 'away', 'reoffered'].includes(rowState(k))).length;
            const denom = medKeys().filter((k) => MEDS[k].slot !== '12:00 pm' && rowState(k) !== 'selfmanaged').length;
            meters = meter({ label: 'Due now', big: String(dueN), cap: dueN ? `${[...new Set(medKeys().filter((k) => rowState(k) === 'due').map((k) => PEOPLE[MEDS[k].pid].pref))].join(', ')} · 9:00 am` : 'Next: 12:00 pm', href: hrefFrame(p, 'today', 'schedule'), aria: `View ${dueN} doses due now` })
                + meter({ label: 'Late', big: String(lateN), cap: lateN ? 'Oldest due 8:00 am' : 'Nothing late', tone: lateN ? 'warning' : undefined, href: hrefFrame(p, 'today', 'schedule'), aria: `View ${lateN} late doses` })
                + (() => { const bl = medKeys().filter((k) => ['due', 'late'].includes(rowState(k)) && rowBlock(k)); const safety = bl.some((k) => BLOCKS[rowBlock(k)].safety); const cap = bl.map((k) => ({ safetyAllergyProfile: 'allergy match', allergyNotConfirmed: 'allergy not confirmed', awaitingVerification: 'order to check', notClockedIn: 'not clocked in', noWitness: 'no witness on shift' })[rowBlock(k)] || 'blocked').filter((v, i, arr) => arr.indexOf(v) === i).join(' · '); return meter({ label: 'Needs help', big: String(bl.length), cap: bl.length ? cap.charAt(0).toUpperCase() + cap.slice(1) : 'Nothing blocked', tone: bl.length ? (safety ? 'critical' : 'warning') : undefined, href: hrefFrame(p, 'today', 'schedule'), aria: `View ${bl.length} doses you can’t record` }); })()
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
            sub: `Monday 28 September 2026 · Kōwhai House · shift 7:00 am–3:00 pm · times in <abbr title="Pacific/Auckland" style="text-decoration:none">NZDT</abbr><span class="tz-long"> (Pacific/Auckland)</span>`,
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
        medKeys().forEach((k) => { if (MEDS[k].cd && !cdVisible) return; (slots[MEDS[k].slot] = slots[MEDS[k].slot] || []).push(k); });
        const body = Object.entries(slots).map(([slot, ks]) => `<div class="slot-head" role="heading" aria-level="3">${ic('clock', 's3')}${slot}<span style="font-weight:500">· ${ks.length} ${ks.length === 1 ? 'medicine' : 'medicines'}</span></div>${ks.map((k) => doseRow(k, p)).join('')}`).join('');
        if (scn === 'cdNoWitness' && S.ovRequest === 'waiting' && has(p, 'administer')) {
            out.push(`<div class="annot"><div class="a-tag">${ic('info', 's3')}Mockup</div>The manager answers from their own login: switch “Signed in as” to Provider manager › Safety &amp; oversight › Witness overrides. Or simulate it here: <button class="btn btn-outline btn-sm" type="button" data-act="ovr-sim" data-r="approved">Simulate approval</button> <button class="btn btn-outline btn-sm" type="button" data-act="ovr-sim" data-r="declined">Simulate decline</button></div>`);
        }
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
        pinDisputed: { title: 'Check a dose co-signed without a PIN — Tama', src: 'Levetiracetam 9:12 am · recorded by Priya S. · named co-signer Daniel Ahn', owner: 'Jordan Tipene', oi: 'JT', osub: 'House lead (routing: not configured)', badge: `<span class="badge b-critical">${ic('x-circle')}Disputed</span>`, line: 'Daniel Ahn answered “I wasn’t there” at 9:40 am', actions: `<button class="btn btn-primary btn-sm" type="button" data-act="toast" data-msg="Review is designed in P08a.">Review</button>` },
        pinNoAnswer: { title: 'Check a dose co-signed without a PIN — Aroha', src: 'Metformin 8:05 am · recorded by Priya S. · named co-signer Daniel Ahn', owner: 'Jordan Tipene', oi: 'JT', osub: 'House lead (routing: not configured)', badge: `<span class="badge b-warning">${ic('clock')}No answer</span>`, line: 'Daniel Ahn didn’t confirm within the time limit', actions: `<button class="btn btn-primary btn-sm" type="button" data-act="toast" data-msg="Review is designed in P08a.">Review</button>` },
        ovDose: { title: 'Review a controlled dose given without a witness — Grace', src: 'Clonazepam 6:00 am · witness override by Rangi Parata (single staffing) · recorded by Mere Kahu', owner: 'Jordan Tipene', oi: 'JT', osub: 'House lead · next shift', badge: `<span class="badge b-warning">${ic('shield')}Needs review</span>`, line: 'Raised 6:02 am · review due: ' + NC(), actions: `<button class="btn btn-primary btn-sm" type="button" data-act="toast" data-msg="Review is designed in P07a/P08a.">Review</button>` },
        rxPending: { title: 'Countersign a prescriber’s phone instruction — Aroha', src: 'Metformin 8:00 am · Dr Lena Chen by phone 7:50 am: 2 tablets this morning only · read back · recorded by Priya S.', owner: 'Jordan Tipene', oi: 'JT', osub: 'House lead · a lead who can check orders', badge: `<span class="badge b-warning">${ic('file-text')}Waiting to countersign</span>`, line: 'Recorded 8:05 am · countersign by next day (rule: ' + NC() + ')', actions: `<button class="btn btn-primary btn-sm" type="button" data-act="toast" data-msg="Countersigning is designed in P04.">Countersign</button>` },
        amtNc: { title: 'Check a partial dose not confirmed by a second person — Tama', src: 'Levetiracetam 8:00 am · ½ tablet (250 mg), only part taken · nobody else on shift', owner: 'Jordan Tipene', oi: 'JT', osub: 'House lead', badge: `<span class="badge b-warning">${ic('alert-triangle')}Needs review</span>`, line: 'Raised 8:06 am · recorded by Priya S.', actions: `<button class="btn btn-primary btn-sm" type="button" data-act="toast" data-msg="Review is designed in P08a.">Review</button>` },
        unable: { title: 'Check whether paracetamol helped — Sam', src: 'As-needed dose given 8:30 am', owner: 'Priya Shah', oi: 'PS', badge: `<span class="badge b-neutral">${ic('moon')}Unable to assess</span>`, line: 'Sam was asleep at 9:00 am · check again due 10:00 am', actions: `<button class="btn btn-outline btn-sm" type="button" data-act="fu-record" data-fk="fu-4">Record result</button>` },
    };

    function followUpsBody(oversight) {
        const rows = oversight ? ['overdue', 'escalated', 'noowner', 'pinDisputed', 'pinNoAnswer', 'ovDose', 'rxPending', 'amtNc', 'due', 'refusal', 'unable', 'late'] : ['overdue', 'due', 'refusal', 'unable'];
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
            const items = views.map((v) => ({ id: v.id, label: v.label, icon: v.icon, href: hrefFrame(p, hub.id, v.id), ...(hub.id === 'safety' && v.id === 'followups' ? { count: 9, alert: true, countLabel: '9 open, 5 need attention' } : hub.id === 'safety' && v.id === 'overrides' && S.ovRequest === 'waiting' ? { count: 1, alert: true, countLabel: '1 request waiting' } : {}) }));
            header = pageHeader({
                icon: hub.icon, title: hub.label, chip: chipBadge('neutral', has(p, 'cd.view') || p === 'lead' ? '2 houses' : '2 houses'),
                sub: `Kōwhai House and Rimu House · your approved houses · times in NZDT (Pacific/Auckland)`,
                actions: searchBox(`Search ${hub.label.toLowerCase()}…`, 'hs'),
                meters: `<div class="eh-meter-annot">${ic('info')}<span><b>Design note:</b> this hub’s meter blocks are designed in ${view.pkg}. Each block must link to its view and use the P00 data-quality states (n/a for zero denominators, “Unavailable” not 0, CD-excluded totals).</span></div>`,
                filters: `${has(p, 'cd.view') || hub.id === 'settings' ? '' : '<span class="fchip static">Totals exclude medicines your role can’t see</span>'}<button type="button" class="fchip" data-act="toast" data-msg="Filter menus are designed with each page package (mockup).">${ic('home', 's3')}All approved houses${ic('chev-down', 's3')}</button><button type="button" class="fchip" data-act="toast" data-msg="Filter menus are designed with each page package (mockup).">${ic('calendar', 's3')}Today${ic('chev-down', 's3')}</button><span class="fchip static">${esc(view.label)} filters · ${view.pkg}</span>`,
                rail: railHtml(items, view.id),
            });
            body = hub.id === 'safety' && view.id === 'overview' ? `<div class="annot"><div class="a-tag">${ic('info', 's3')}Design note</div>The Overview page is redesigned in P09. Its counts already follow the P00 vocabulary below (the EM-01 fix, live on main).</div>${leadCounts()}`
                : hub.id === 'settings' && view.id === 'rules' ? medicationRulesView()
                : hub.id === 'safety' && view.id === 'overrides' ? overridesView()
                : hub.id === 'settings' && view.id === 'secondperson' ? secondPersonSettingsView()
                : hub.id === 'mar' && view.id === 'charts' ? marChartsBody()
                : hub.id === 'safety' && view.id === 'followups' ? followUpsBody(true)
                    : annotCard(hub, view.id);
        }
        return crumbs(crumbTrail) + `<div class="stack" id="main" tabindex="-1">${header}${body}</div>`;
    }

    /* Counts shared by Meds today, My Day and handover — one schedule, one set of numbers. */
    function shiftCounts() {
        const keys = medKeys();
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
    /* ───────────── v4: rostered medication work in All Tasks and My Calendar ─────────────
     * Stephan (29 Sep 2026): “these emar schedules [should] form part of the to do automatically to rostered staff”.
     * Verified in code: not built today — All Tasks has no dose/round provider (TaskAggregator), and My Calendar's
     * Meds source shows only MedicationRound rows assigned_to the user (MyCalendarController). Design: the same
     * schedule as Meds today, grouped into one task per time slot per house for every worker rostered on a shift
     * covering those people; it completes by itself when every dose has an outcome. */
    function medTasks() {
        const slots = {};
        medKeys().filter((k) => MEDS[k].support !== 'independent').forEach((k) => { (slots[MEDS[k].slot] = slots[MEDS[k].slot] || []).push(k); });
        return Object.entries(slots).map(([slot, ks]) => {
            const st = ks.map(rowState);
            const late = st.filter((x) => x === 'late').length, due = st.filter((x) => x === 'due').length;
            const open = ks.filter((k) => ['due', 'late', 'notdue'].includes(rowState(k))).length;
            const blocked = ks.filter((k) => ['due', 'late'].includes(rowState(k)) && rowBlock(k)).length;
            return { slot, ks, late, due, blocked, done: ks.length - open, total: ks.length, people: [...new Set(ks.map((k) => PEOPLE[MEDS[k].pid].pref))], state: open === 0 ? 'done' : late ? 'late' : due ? 'due' : 'notdue' };
        });
    }
    const medTaskBadge = (t) => (t.state === 'done' ? `<span class="badge b-success">${ic('check')}Done — all recorded</span>` : t.state === 'late' ? `<span class="badge b-warning">${ic('alert-triangle')}${t.late} late</span>` : t.state === 'due' ? `<span class="badge b-info">${ic('clock')}Due now</span>` : `<span class="badge b-neutral">${ic('clock')}Due ${t.slot}</span>`);
    function medTaskRow(t, p = S.persona) {
        const id = t.slot.replace(/[^0-9a-z]/gi, '');
        return `<div class="fu-row" data-menu="mtask:${id}"><div><div class="fu-title"><span class="chipn" style="margin-right:6px">${ic('pill', 's3')}Medication</span>Medicines due ${esc(t.slot)} — Kōwhai House</div><div class="fu-src">${t.total} ${t.total === 1 ? 'dose' : 'doses'} for ${esc(t.people.join(', '))} · from your rostered shift 7:00 am–3:00 pm</div></div>
            <div class="owner"><span class="disc sm" aria-hidden="true">${PERSONAS[p].initials}</span><div><div>You and Daniel Ahn</div><div class="o-sub">Both rostered on shifts covering Kōwhai House</div></div></div>
            <div class="state-cell">${medTaskBadge(t)}<span class="state-line">${t.done} of ${t.total} recorded${t.blocked ? ` · ${t.blocked} ${t.blocked === 1 ? 'needs' : 'need'} help` : ''}</span><span class="state-line">${t.state === 'done' ? 'Completed by itself when the last dose was recorded' : 'Completes by itself when every dose has an outcome'}</span></div>
            <div class="row-actions"><a class="btn ${t.state === 'late' || t.state === 'due' ? 'btn-primary' : 'btn-outline'} btn-sm frontline-tap" href="${hrefFrame(p, 'today', 'schedule')}" data-fk="mt-${id}">Open meds</a>${kebab('mtask', id, `Medicines due ${t.slot}`)}</div></div>`;
    }
    function medTasksList(p = S.persona) {
        const ts = medTasks();
        return `<section class="card" style="overflow:hidden" aria-label="Medication tasks"><div class="slot-head" role="heading" aria-level="3">Medication · from your roster<span style="font-weight:500">· ${ts.filter((t) => t.state !== 'done').length} open</span></div>${ts.map((t) => medTaskRow(t, p)).join('')}${followUpRow(FU.overdue, 'overdue')}${followUpRow(FU.due, 'due')}</section>`;
    }
    function tasksPage() {
        const p = S.persona;
        const ts = medTasks();
        const header = pageHeader({
            icon: 'list-todo', title: 'All Tasks', chip: chipBadge('neutral', 'Mine'),
            sub: 'Everything assigned to you across modules · Monday 28 September 2026 · times in NZDT',
            actions: searchBox('Search tasks…', 'ts'),
            meters: meter({ label: 'Overdue', big: String(ts.filter((t) => t.state === 'late').length + 1), cap: 'Late doses and follow-ups', tone: 'warning', act: 'noop' }) + meter({ label: 'Due today', big: String(ts.filter((t) => t.state !== 'done').length + 2), cap: 'Across all modules', act: 'noop' }) + meter({ label: 'Medication', big: String(ts.filter((t) => t.state !== 'done').length), cap: 'Rounds from your roster', href: hrefFrame(p, 'today', 'schedule') }),
            filters: `<span class="fchip static">All modules</span><span class="fchip static">Due today</span>`,
        });
        return crumbs([{ label: 'Home', href: '#' }, { label: 'All Tasks' }]) + `<div class="stack" id="main" tabindex="-1">${header}
            <div class="annot"><div class="a-tag">${ic('info', 's3')}Reference frame — All Tasks is unchanged</div>Only the medication rows are new: a new task provider. Today (verified) All Tasks has no dose or round provider — only medication errors and controlled-drug loss reports. Designed in full in <b>P01</b> (follow-ups in <b>P08a</b>).</div>
            ${medTasksList(p)}
            <div class="annot" style="min-height:70px;display:grid;place-items:center;text-align:center"><div><div class="a-tag">Placeholder</div>Tasks from the other 31 providers — unchanged</div></div></div>`;
    }
    /* My Calendar lens — follows CALENDAR_STYLE_GUIDE (date anchor meter, view rail, source pills, Today rail). */
    function dateAnchorMeter(n) {
        return `<a class="eh-meter date-anchor" href="#/mycal/${S.persona}" aria-label="Viewing Monday 28 September 2026, ${n} entries"><span class="eh-meter-head"><span class="eh-meter-label">Viewing</span><span class="eh-meter-value">${n} entries</span></span><span class="da"><span class="da-d">28</span><span class="da-my"><b>September</b><b>2026</b></span></span><span class="eh-meter-cap">Monday, 28 September 2026</span></a>`;
    }
    function calDayGrid(p = S.persona) {
        const ts = medTasks();
        const H = 44, start = 6;
        const toMin = (s) => { const m = /(\d+):(\d+) (am|pm)/.exec(s); let h = +m[1] % 12; if (m[3] === 'pm') h += 12; return h * 60 + +m[2]; };
        const top = (min) => ((min - start * 60) / 60) * H;
        const hours = Array.from({ length: 10 }, (_, i) => start + i);
        const ev = ts.map((t) => { const m = toMin(t.slot); return `<a class="mcal-ev meds ${t.state}" href="${hrefFrame(p, 'today', 'schedule')}" style="top:${top(m)}px;height:${H - 6}px"><b>Medicines ${esc(t.slot)}</b><span>${t.total} doses · ${t.state === 'done' ? 'all recorded' : t.state === 'late' ? t.late + ' late' : t.state === 'due' ? 'due now' : 'not yet due'}</span></a>`; }).join('');
        return `<div class="mcal-day" role="group" aria-label="Monday 28 September 2026, day view"><div class="mcal-hours">${hours.map((h) => `<div class="mcal-h" style="height:${H}px">${h % 12 || 12}${h < 12 ? 'am' : 'pm'}</div>`).join('')}</div>
            <div class="mcal-lane" style="height:${hours.length * H}px">${hours.map((_, i) => `<div class="mcal-line" style="top:${i * H}px"></div>`).join('')}
                <div class="mcal-ev shift" style="top:${top(7 * 60)}px;height:${8 * H - 4}px"><b>Shift · Kōwhai House</b><span>7:00 am–3:00 pm</span></div>
                ${ev}<div class="mcal-now" style="top:${top(9 * 60 + 12)}px" aria-hidden="true"></div></div></div>`;
    }
    function myCalPage() {
        const p = S.persona;
        const ts = medTasks();
        const header = pageHeader({
            icon: 'calendar', title: 'My Calendar', chip: chipBadge('neutral', 'Personal'),
            sub: 'Your shifts, medication rounds and tasks · times in NZDT (Pacific/Auckland)',
            actions: searchBox('Search my calendar…', 'mcs'),
            meters: dateAnchorMeter(ts.length + 1) + meter({ label: 'Upcoming', big: String(ts.filter((t) => t.state === 'notdue').length), cap: 'upcoming from now', act: 'noop' }) + meter({ label: 'Overdue', big: String(ts.filter((t) => t.state === 'late').length), cap: 'past their due time', tone: 'warning', act: 'noop' }),
            filters: `<button type="button" class="fchip" data-act="toast" data-msg="Date navigation is the shared calendar’s (unchanged).">${ic('chev-left', 's3')}</button><span class="fchip static">${ic('calendar', 's3')}Mon, 28 September 2026</span><button type="button" class="fchip" data-act="toast" data-msg="Date navigation is the shared calendar’s (unchanged).">${ic('chev-right', 's3')}</button><button type="button" class="fchip" data-act="toast" data-msg="Date navigation is the shared calendar’s (unchanged).">Today</button>`,
            rail: railHtml(['Month', 'Week', 'Day', 'Agenda', 'Timeline'].map((l) => ({ id: l.toLowerCase(), label: l, icon: 'calendar', href: `#/mycal/${p}` })), 'day'),
        });
        const pills = ['Personal tasks', 'Meetings', 'Shifts', 'Meds', 'Leave', 'Tasks'].map((l) => `<span class="src-pill${l === 'Meds' ? ' on' : ''}">${l === 'Meds' ? ic('pill', 's3') : '<i aria-hidden="true"></i>'}${l}</span>`).join('');
        const rail = `<aside class="card card-pad today-rail" aria-label="Today"><div class="text-caption" style="font-weight:700;letter-spacing:.06em;text-transform:uppercase">Monday</div><div style="font-size:26px;font-weight:700;line-height:1">28 <span style="font-size:15px;font-weight:600">September 2026</span></div><ul class="rp-list" style="margin-top:10px">
            <li><b>7:00 am–3:00 pm</b> Shift · Kōwhai House</li>${ts.map((t) => `<li><b>${esc(t.slot)}</b> Medicines · ${t.total} doses ${medTaskBadge(t)}</li>`).join('')}</ul></aside>`;
        return crumbs([{ label: 'Home', href: '#' }, { label: 'My Calendar' }]) + `<div class="stack" id="main" tabindex="-1">${header}
            <div class="annot"><div class="a-tag">${ic('info', 's3')}Reference frame — the shared calendar is unchanged</div>Only the Meds source changes: it shows the medication slots on your rostered shifts, from the same schedule as Meds today. Today (verified) it shows a “Medication Round” only when a round is assigned to you (the round template’s default assignee or a manager), so most workers see nothing — as in the 29 September screenshot. Built in <b>P01</b>.</div>
            <div class="src-pills" role="group" aria-label="Sources">${pills}<span class="fchip-plain" style="margin-left:auto">${ic('clock', 's3')}&nbsp;Times in NZT</span></div>
            <div class="mcal-wrap"><section class="card" style="overflow:hidden" aria-label="Day view"><div class="card-pad" style="border-bottom:1px solid var(--border);display:flex;gap:10px;align-items:center"><span class="mcal-dchip"><small>MON</small>28</span><div><div style="font-weight:650">28 September 2026</div><div class="text-caption">${ts.length + 1} entries scheduled</div></div></div>${calDayGrid(p)}</section>${rail}</div></div>`;
    }
    function touchSection() {
        return stateCard({ wide: true, id: 'touch-tasks', name: 'All Tasks — medication from the roster (new provider)', spec: medTasksList('sw'), wording: ['Medication · from your roster', 'Medicines due 8:00 am — Kōwhai House', '{n} doses for {people} · from your rostered shift 7:00 am–3:00 pm', 'You and Daniel Ahn · Both rostered on shifts covering Kōwhai House', '{n} late · Due now · Due 12:00 pm · Done — all recorded', '{x} of {y} recorded · {n} need help', 'Completes by itself when every dose has an outcome', 'Open meds · Why is this on my list?'], when: 'In All Tasks (and the My Day task list) for every worker rostered on a shift covering the house, for the dose times inside their shift.', treatment: 'Stephan (29 Sep): medication schedules join rostered staff’s to-do automatically. Not built today (verified: All Tasks has no dose or round provider). One task per time slot per house — not one per dose — built from the same schedule and counts as Meds today. It can’t be ticked off by hand: it completes when every dose has an outcome, and turns late when a dose does. Controlled doses count only for roles with controlled-medicine access; self-managed medicines never appear. Follow-ups keep their own rows (P08a).', never: 'A separate medication to-do that disagrees with Meds today; a task someone can mark done while doses have no outcome.', reuse: ['All Tasks provider (P01)', 'My Day task list', 'Meds today'], depends: ['D2', 'D12', 'D4'], fixes: ['EM-01', 'EM-05', 'EM-12'], link: { href: '#/tasks/sw', label: 'Open the All Tasks reference frame' } })
            + stateCard({ wide: true, id: 'touch-calendar', name: 'My Calendar — Meds source from the roster', spec: `<div class="mcal-wrap one"><section class="card" style="overflow:hidden">${calDayGrid('sw')}</section></div>`, white: true, wording: ['Medicines 8:00 am · 5 doses · 1 late', 'Shift · Kōwhai House · 7:00 am–3:00 pm', 'Monday, 28 September 2026 (date anchor)'], when: 'My Calendar (and the site calendar’s Meds source) for the worker’s rostered shifts.', treatment: 'Follows CALENDAR_STYLE_GUIDE: the date anchor is the first meter, the connected Month / Week / Day / Agenda / Timeline rail, source pills and the Today rail. Each medication slot on a rostered shift is an entry that opens Meds today. Today (verified) the Meds source shows a “Medication Round” only when a round is assigned to the worker, so most support workers see nothing. Care records keep their own workflow: entries can’t be dragged or edited here.', never: 'Doses the worker isn’t rostered for; controlled medicines for roles without access.', reuse: ['My Calendar (P01)', 'Site calendar Meds source'], depends: ['D2', 'D12'], fixes: ['EM-01', 'EM-12'], link: { href: '#/mycal/sw', label: 'Open the My Calendar reference frame' } })
            + stateCard({ id: 'touch-schedule-day', name: 'Meds today stays a worklist — with the day in the header', spec: `<div class="eh-header" style="padding:12px 14px;border-radius:12px"><div class="eh-inner"><p class="eh-sub" style="margin:0">Monday 28 September 2026 · Kōwhai House · shift 7:00 am–3:00 pm · times in NZDT (Pacific/Auckland)</p></div></div>`, wording: ['Monday 28 September 2026 · Kōwhai House · shift 7:00 am–3:00 pm · times in NZDT (Pacific/Auckland)'], when: 'The Meds today header.', treatment: 'Meds today is an action list for one shift, not a calendar: a Day time-grid would stack several doses into one cell and lose states, blocked reasons and record buttons. It takes the calendar rules that fit — the viewed day stated in the header, NZ time zone visible — and the calendar gets the doses as its Meds source. Browsing other days is the MAR chart’s job (P02), which uses the calendar date anchor.', reuse: ['Meds today (P01)', 'MAR charts (P02)'], depends: [], fixes: ['EM-02'] })
            + stateCard({ wide: true, id: 'touch-myday', name: 'My Day — medication card', spec: myDayMedsCard(), white: false, wording: ['Medicines', 'Due now · Late · Needs help · Recorded {x} of {y}', 'Your follow-ups', 'Open meds · Open follow-ups', 'Couldn’t load medicines'], when: 'On My Day for anyone who records medication.', treatment: 'The same counts as Meds today (one schedule, the same NZ day), the worker’s own follow-ups with owner and due time, and the P00 data-quality states when the read fails or is loading.', never: 'A second count that disagrees with Meds today; a 0 after a failed read.', reuse: ['My Day (P01)', 'Follow-ups (P08a)'], depends: ['D4'], fixes: ['EM-01', 'NF-14', 'EM-18'], link: { href: '#/myday/sw', label: 'Open the My Day reference frame' } })
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
        { key: 'restricted', label: 'A worker’s medication competency is marked restricted', help: 'Refusals and withheld doses can always be recorded. The co-signer confirms with their own witness PIN — never their login password.', rec: 'Stephan set Block on 29 Sep 2026 (the dialog shows who on shift can give it). Review recommendation: move to Co-signer with witness PIN once the PIN is built — never a co-signer by login password.', opts: [['off', 'Off — no extra check'], ['block', 'Block — they can’t sign as given; the dialog shows who on shift can give it'], ['cosigner', 'Co-signer with witness PIN (recommended) — a present, qualified colleague confirms each dose']], def: 'off' },
        { key: 'area', label: 'The controlled-drug or covert area wasn’t passed', help: 'Applies to controlled-drug orders and orders with an active covert authorisation. Insulin isn’t covered yet: orders don’t record whether a medicine is insulin.', opts: [['off', 'Off — no extra check'], ['failed', 'Block when the area was failed'], ['failed_or_not_seen', 'Block when failed or not seen at assessment']], def: 'off' },
        { key: 'amount', label: 'Less than the ordered amount is given', help: 'A reason is always required. A colleague on shift confirms with their witness PIN; if nobody is available the dose is marked “Not confirmed by a second person” with a house-lead follow-up. A missing second person never blocks recording what actually happened. More than ordered is never a normal choice — it is recorded as a medication error with a linked incident.', opts: [['avail', 'If someone is available — never blocks'], ['always', 'Always — the house lead confirms later if nobody is on shift'], ['no', 'Not needed — the reason is enough']], def: 'avail' },
    ];
    function safetyRulesCard(canManage, staticSpec) {
        const vals = S.safety, draft = S.safetyDraft;
        const changed = SAFETY_RULES.filter((r) => draft[r.key] !== vals[r.key]);
        return `<section class="card card-pad" aria-labelledby="sr-h" ${staticSpec ? 'inert' : 'id="sec-safety"'}><div class="cap-row" style="margin-bottom:2px"><h3 id="sr-h" tabindex="-1" style="outline:none">Organisation-wide safety rules</h3></div><p class="text-subtle" style="margin:0 0 6px;font-size:12.5px">These apply at every site when a dose is signed. Changes are recorded in the audit log.</p>
            ${SAFETY_RULES.map((r) => `<div class="set-row"><div><label for="sr-${r.key}" style="font-weight:600;font-size:13px">${esc(r.label)}</label><div class="help">${esc(r.help)}</div></div><div><select id="sr-${r.key}" class="field" style="border:1px solid var(--input);border-radius:8px;min-height:36px;padding:0 8px;background:var(--card)" data-act="sr-sel" data-key="${r.key}" ${canManage ? '' : 'disabled'}>${r.opts.map(([v, l]) => `<option value="${v}"${draft[r.key] === v ? ' selected' : ''}>${esc(l)}</option>`).join('')}</select><div class="set-meta">${S.safetySetBy[r.key] ? `<span class="chipn">${S.safetySetBy[r.key].includes('decision') ? '' : 'Set by '}${esc(S.safetySetBy[r.key])}</span>` : `<span class="nc">${ic('settings', 's3')}Default — not yet reviewed</span>`}${r.rec ? `<span class="badge b-info sm">${ic('info')}Recommended later: Co-signer with witness PIN</span>` : ''}</div>${r.rec ? `<div class="help" style="margin-top:4px">${esc(r.rec)}</div>` : ''}</div></div>`).join('')}
            <div style="display:flex;justify-content:flex-end;margin-top:12px">${canManage ? `<button class="btn btn-primary btn-sm" type="button" data-act="sr-save" data-fk="sr-save" ${changed.length ? '' : 'disabled'}>Save safety rules</button>` : '<p class="text-caption" style="margin:0">Only someone who manages medication settings for all sites can change these rules.</p>'}</div>
        </section>`;
    }
    function openSafetyConfirm() {
        const changed = SAFETY_RULES.filter((r) => S.safetyDraft[r.key] !== S.safety[r.key]);
        openDialog(simpleDialog({
            title: 'Change the medication safety rules?', icon: 'shield', desc: 'From the next dose signed, at every site:',
            body: `<ul style="margin:0;padding-left:18px;font-size:13px">${changed.map((r) => `<li><b>${esc(r.label)}:</b> ${esc(r.opts.find((o) => o[0] === S.safetyDraft[r.key])[1])}</li>`).join('')}</ul><p class="text-caption" style="margin:0">Recorded in the audit log with your name and the time.</p>`,
            foot: `<button class="btn btn-outline" type="button" data-act="close">Cancel</button><button class="btn btn-primary" type="button" data-act="sr-confirm">Save rules</button>`,
        }), 'dlg-simple w480', 'dlg-t', 'dlg-d');
    }

    /* ───────────── v4: Settings › Medication rules ─────────────
     * One place for every medication rule (Stephan, 29 Sep 2026): rules for specific medicines — a plain-language
     * builder over the existing MedicationAdminRule (countersign and/or observations; matched by medicine name, route
     * or NZULM code; all houses or one house) plus new type/class and controlled-status matching — alongside the
     * organisation-wide safety rules, the controlled-drug witness default and a summary of the witness PIN rules. */
    const stripTags = (h) => String(h).replace(/<[^>]+>/g, '').replace(/\s+/g, ' ').trim();
    const HOUSES = { all: 'All houses', kowhai: 'Kōwhai House', rimu: 'Rimu House' };
    const OBS = { pulse: 'pulse', bsl: 'blood sugar (BSL)', bp: 'blood pressure' };
    const MATCH = {
        name: { l: 'Medicine name', i: 'pill', d: 'For example insulin glargine' },
        route: { l: 'Route', i: 'activity', d: 'For example subcutaneous injection' },
        nzulm: { l: 'NZULM code', i: 'file-text', d: 'One exact product' },
        cls: { l: 'Type or class', i: 'layers', d: 'For example anticoagulants', isNew: 'New — needs a medicine classification on orders (P04)' },
        controlled: { l: 'Controlled status', i: 'shield', d: 'Any controlled medicine', isNew: 'New — uses the order’s controlled flag' },
    };
    const ROUTES = ['Oral', 'Subcutaneous injection', 'Inhaled', 'Topical', 'Rectal'];
    const CLASSES = ['Anticoagulants', 'Insulins', 'Cardiac glycosides', 'Benzodiazepines', 'Opioid analgesics'];
    const NZULM = [['9000031', 'Digoxin 62.5 microgram tablet'], ['9000047', 'Enoxaparin 40 mg/0.4 mL injection'], ['9000052', 'Insulin glargine 100 units/mL pen']];
    const MEDLIST = [
        { med: 'Insulin glargine', route: 'Subcutaneous injection', nzulm: '9000052', cls: 'Insulins', pid: 'aroha', house: 'kowhai' },
        { med: 'Insulin glargine', route: 'Subcutaneous injection', nzulm: '9000052', cls: 'Insulins', pid: 'ben', house: 'rimu' },
        { med: 'Enoxaparin', route: 'Subcutaneous injection', nzulm: '9000047', cls: 'Anticoagulants', pid: 'tama', house: 'kowhai' },
        { med: 'Digoxin', route: 'Oral', nzulm: '9000031', cls: 'Cardiac glycosides', pid: 'grace', house: 'kowhai' },
        { med: 'Metformin', route: 'Oral', cls: 'Biguanides', pid: 'aroha', house: 'kowhai' },
        { med: 'Levetiracetam', route: 'Oral', cls: 'Antiepileptics', pid: 'tama', house: 'kowhai' },
        { med: 'Salbutamol', route: 'Inhaled', cls: 'Bronchodilators', pid: 'aroha', house: 'kowhai' },
        { med: 'Methylphenidate', route: 'Oral', cls: 'Stimulants', pid: 'aroha', house: 'kowhai', cd: true },
        { med: 'Clonazepam', route: 'Oral', cls: 'Benzodiazepines', pid: 'grace', house: 'kowhai', cd: true },
    ];
    const RULES = [
        { id: 'mr1', match: 'name', value: 'Insulin glargine', scope: 'all', countersign: false, obs: ['bsl'], active: true, by: 'Hana Kereama', when: '3 Aug 2026' },
        { id: 'mr2', match: 'route', value: 'Subcutaneous injection', scope: 'kowhai', countersign: true, obs: [], active: true, by: 'Hana Kereama', when: '19 Aug 2026' },
        { id: 'mr3', match: 'nzulm', value: '9000031', scope: 'all', countersign: false, obs: ['pulse'], active: false, by: 'Hana Kereama', when: '2 Sep 2026', paused: 'Paused 20 Sep 2026 by Hana Kereama' },
    ];
    const RULE_HISTORY_SEED = [
        '29 Sep 2026 · P00 v4 · Existing rules carried over with the same meaning. “Countersign” now reads “a second person confirms with their witness PIN” (Stephan’s option B, once built).',
        '20 Sep 2026 · Hana Kereama · Paused: digoxin — record pulse',
        '19 Aug 2026 · Hana Kereama · Added: subcutaneous injection at Kōwhai House — countersign',
        '3 Aug 2026 · Hana Kereama · Added: insulin glargine — record blood sugar',
    ];
    const ruleById = (id) => RULES.find((r) => r.id === id);
    function ruleItems(r) {
        return MEDLIST.filter((m) => (r.scope === 'all' || m.house === r.scope) && (r.match === 'name' ? m.med.toLowerCase() === String(r.value).trim().toLowerCase() : r.match === 'route' ? m.route === r.value : r.match === 'nzulm' ? m.nzulm === r.value : r.match === 'cls' ? m.cls === r.value : r.match === 'controlled' ? !!m.cd : false));
    }
    function ruleWhat(r) {
        const v = esc(r.value || '…');
        if (r.match === 'name') return `<b>${v}</b>`;
        if (r.match === 'route') return `any medicine given by <b>${esc((r.value || '…').toLowerCase())}</b>`;
        if (r.match === 'nzulm') { const n = NZULM.find((x) => x[0] === r.value); return `<b>${esc(n ? n[1] : 'NZULM code ' + (r.value || '…'))}</b> <span class="who-sub">(NZULM ${v}, test code)</span>`; }
        if (r.match === 'cls') return `any medicine in the class <b>${esc((r.value || '…').toLowerCase())}</b>`;
        return '<b>any controlled medicine</b>';
    }
    function ruleNeeds(r) {
        const parts = [r.countersign ? 'a second person confirms with their witness PIN' : '', ...r.obs.map((o) => `record ${OBS[o]}`)].filter(Boolean);
        return parts.length ? parts.join(' and ') : 'choose what it requires';
    }
    const ruleSentence = (r) => `Before saving a dose of ${ruleWhat(r)} at <b>${esc(HOUSES[r.scope])}</b>: ${ruleNeeds(r)}.`;
    function ruleOverlaps(r, p = S.persona) {
        const see = (m) => has(p, 'cd.view') || !m.cd;
        const mine = ruleItems(r).filter(see);
        return RULES.filter((o) => o.id !== r.id && o.active && ruleItems(o).some((m) => see(m) && mine.includes(m)));
    }
    function rulePreview(r, p = S.persona) {
        const seeCd = has(p, 'cd.view');
        const shown = ruleItems(r).filter((m) => seeCd || !m.cd);
        const people = [...new Set(shown.map((m) => m.pid))], meds = [...new Set(shown.map((m) => m.med))];
        const list = shown.length ? `<ul class="rp-list">${shown.map((m) => `<li><b>${esc(PEOPLE[m.pid].pref)}</b> ${esc(PEOPLE[m.pid].surname)} · ${esc(m.med)} <span class="who-sub">${esc(HOUSES[m.house])}</span></li>`).join('')}</ul>` : `<p class="text-caption" style="margin:4px 0 0">${seeCd ? 'No current orders match. The rule still applies to future orders that match.' : 'No matching medicines your role can see. The rule still applies to future orders that match.'}</p>`;
        return `<div class="rp"><div class="rp-h">${ic('users', 's35')}<b>Would apply now to ${meds.length} ${meds.length === 1 ? 'medicine' : 'medicines'} for ${people.length} ${people.length === 1 ? 'person' : 'people'}</b></div>${list}${seeCd ? '' : '<p class="text-caption" style="margin:6px 0 0">Showing medicines your role can see. Totals exclude medicines your role can’t see.</p>'}</div>`;
    }
    function overlapWarn(r) {
        const ov = ruleOverlaps(r);
        return ov.length ? B('warning', 'layers', `Overlaps with ${ov.length === 1 ? 'another rule' : ov.length + ' other rules'}`, `${ov.map((o) => ruleSentence(o)).join('<br>')}<br>Both apply where they overlap: the dose needs everything either rule asks for.`) : '';
    }
    function medRulesCard(canManage) {
        const demo = S.rulesDemo;
        const head = `<div class="cap-row" style="margin-bottom:2px"><h3 id="mr-h" tabindex="-1" style="outline:none">Rules for specific medicines</h3>${canManage && demo === 'loaded' ? `<button class="btn btn-primary btn-sm" type="button" data-act="rule-new" data-fk="rule-new">${ic('plus')}Add a rule</button>` : ''}</div><p class="text-subtle" style="margin:0;font-size:12.5px">Extra checks before a dose is saved: a second person confirms with their witness PIN, and/or an observation is recorded.</p>`;
        let body;
        if (demo === 'loading') body = `<div aria-busy="true" aria-label="Loading medicine rules">${[1, 2, 3].map(() => `<div class="dose-row" style="grid-template-columns:2fr 1fr 1fr"><span class="skel-line" style="width:80%"></span><span class="skel-line" style="width:60%"></span><span class="skel-line" style="width:50%"></span></div>`).join('')}<span class="sr-only">Loading medicine rules…</span></div>`;
        else if (demo === 'error') body = `<div class="empty error" role="alert"><span class="e-ico">${ic('alert-triangle', 's6')}</span><h3>Couldn’t load the medicine rules</h3><p>The rules still apply when doses are saved — this page just couldn’t show them.</p><div class="e-act"><button class="btn btn-outline btn-sm" type="button" data-act="rules-retry">${ic('refresh')}Try again</button></div></div>`;
        else if (demo === 'empty') body = `<div class="empty"><span class="e-ico">${ic('settings', 's6')}</span><h3>No medicine rules yet</h3><p>Add a rule when a medicine needs a second person or an observation before each dose.</p>${canManage ? `<div class="e-act"><button class="btn btn-primary btn-sm" type="button" data-act="rule-new">${ic('plus')}Add a rule</button></div>` : ''}</div>`;
        else body = `<div class="tbl-wrap"><table class="etable"><caption class="sr-only">Rules for specific medicines</caption><thead><tr><th scope="col">Rule</th><th scope="col">Where</th><th scope="col">Status</th><th scope="col">Last changed</th><th scope="col"><span class="sr-only">Actions</span></th></tr></thead><tbody>
            ${RULES.map((r) => { const ov = ruleOverlaps(r); return `<tr data-menu="rule:${r.id}"><td class="rule-cell">${ruleSentence(r)}${ov.length && r.active ? `<div class="who-sub" style="margin-top:3px">${ic('layers', 's3')} Overlaps with ${ov.length} ${ov.length === 1 ? 'rule' : 'rules'} — both apply</div>` : ''}</td><td><span class="chipn">${ic(r.scope === 'all' ? 'building' : 'home', 's3')}${esc(HOUSES[r.scope])}</span></td><td>${r.active ? `<span class="badge b-success sm">${ic('check')}Active</span>` : `<span class="badge b-neutral sm">${ic('pause')}Paused</span><div class="who-sub">${esc(r.paused || '')}</div>`}</td><td style="white-space:nowrap">${esc(r.by)}<div class="who-sub">${esc(r.when)}</div></td><td style="text-align:right;white-space:nowrap"><button class="btn btn-ghost btn-sm" type="button" data-act="${canManage ? 'rule-edit' : 'rule-history'}" data-id="${r.id}" data-fk="rule-${r.id}">${canManage ? 'Edit' : 'History'}</button>${kebab('rule', r.id, stripTags(ruleSentence(r)))}</td></tr>`; }).join('')}
            </tbody></table></div>`;
        const hist = [...S.ruleHistory.map((h) => `${h.when} · ${h.who} · ${h.what}`), ...RULE_HISTORY_SEED];
        return `<section class="card" id="sec-medrules" aria-labelledby="mr-h" style="overflow:hidden"><div class="card-pad" style="padding-bottom:10px">${head}</div>${body}
            <div class="card-pad" style="border-top:1px solid var(--border)">${canManage ? '' : '<p class="text-caption" style="margin:0 0 8px">Only someone who manages medication settings for all sites can add or change rules.</p>'}<div class="nh">Change history</div><ul class="hist">${hist.map((h) => `<li>${esc(h)}</li>`).join('')}</ul></div></section>`;
    }

    /* Rule builder — WizardShell (entity add/edit), 3 steps with a live preview of who it affects. */
    let RW = null;
    const RSTEPS = [
        { l: 'What it applies to', b: 'Medicines and houses', i: 'pill' },
        { l: 'What it requires', b: 'Before a dose is saved', i: 'clipboard-check' },
        { l: 'Review & save', b: 'Check who it affects', i: 'check' },
    ];
    function openRuleWizard(id) {
        const src = id ? ruleById(id) : null;
        RW = src ? { ...src, obs: [...src.obs], step: 0, edit: true, error: null, saved: false } : { id: null, match: 'name', value: '', scope: 'all', countersign: false, obs: [], active: true, step: 0, edit: false, error: null, saved: false };
        const shell = `<div class="wiz-grid"><aside class="wiz-rail" aria-label="Steps"></aside><div class="wiz-main"><div class="wiz-head"><span id="dlg-t" tabindex="-1" style="outline:none"></span><button class="d-close wiz-close" type="button" data-act="close" aria-label="Close">${ic('x', 's5')}</button></div><div class="wiz-prog" aria-hidden="true"><i style="width:33%"></i></div><div class="wiz-body" id="wiz-body"></div><div class="wiz-foot" id="wiz-foot"></div></div></div>`;
        openDialog(shell, 'wiz', 'dlg-t');
        renderRuleWizard();
    }
    const ruleLive = (p = S.persona) => `<div class="rule-live"><div class="rl-s">${ruleSentence(RW)}</div>${rulePreview(RW, p)}</div>`;
    function renderRuleWizard(focusSel) {
        if (!RW) return;
        const dlg = $('.dlg.wiz'); if (!dlg) return;
        const p = S.persona;
        $('.wiz-rail', dlg).innerHTML = `<div class="wiz-railhead"><span class="rtile">${ic('settings', 's5')}</span><div><div class="t">${RW.edit ? 'Edit medicine rule' : 'Add a medicine rule'}</div><div class="s">Settings › Medication rules</div></div></div>
            ${RSTEPS.map((s, i) => `<button class="wiz-step${i === RW.step && !RW.saved ? ' on' : ''}${i < RW.step || RW.saved ? ' done' : ''}" type="button" data-act="rw-step" data-step="${i}" ${i > RW.step || RW.saved ? 'disabled' : ''} aria-current="${i === RW.step ? 'step' : 'false'}"><span class="n">${ic(i < RW.step || RW.saved ? 'check' : s.i, 's35')}</span><span><span class="l">${s.l}</span><span class="b">${s.b}</span></span></button>`).join('')}
            <div class="wiz-railfoot"><div class="wiz-signed"><b>Changed by</b><br>${esc(PERSONAS[p].name)}<br>${esc(PERSONAS[p].role)}</div></div>`;
        $('#dlg-t').innerHTML = RW.saved ? '<b>Rule saved</b>' : `Step ${RW.step + 1} of 3 · <b>${RSTEPS[RW.step].l}</b>`;
        $('.wiz-prog i', dlg).style.width = RW.saved ? '100%' : `${((RW.step + 1) / 3) * 100}%`;
        const body = $('#wiz-body'), foot = $('#wiz-foot');
        const e = RW.error;
        if (RW.saved) {
            body.innerHTML = `<div class="wiz-success" role="status"><span class="ws-i">${ic('check-circle', 's6')}</span><h3>Rule ${RW.edit ? 'updated' : 'added'}</h3><p>${ruleSentence(RW)}</p><p class="text-caption">${RW.active ? 'Applies from the next dose saved.' : 'Saved as paused — it doesn’t apply until someone resumes it.'} Recorded in the change history with your name.</p></div>`;
            foot.innerHTML = `<div></div><div class="end"><button class="btn btn-primary" type="button" data-act="close" data-autofocus>Done</button></div>`;
            const d = $('[data-autofocus]', foot); if (d) d.focus();
            return;
        }
        body.innerHTML = ruleBody(p);
        const back = RW.step > 0 ? `<button class="btn btn-ghost" type="button" data-act="rw-back">${ic('chev-left')}Back</button>` : `<button class="btn btn-outline" type="button" data-act="close">Cancel</button>`;
        const next = RW.step < 2 ? `<button class="btn btn-primary" type="button" data-act="rw-next">Continue${ic('chev-right')}</button>` : `<button class="btn btn-primary" type="button" data-act="rw-save">${ic('check')}${RW.edit ? 'Save changes' : 'Save rule'}</button>`;
        foot.innerHTML = `<div>${back}</div><div class="end">${RW.step > 0 ? '<button class="btn btn-outline" type="button" data-act="close">Cancel</button>' : ''}${next}</div>`;
        if (focusSel) { const el = $(focusSel); if (el) el.focus(); }
        else { const target = e ? $('#wiz-body [aria-invalid="true"], #wiz-body [aria-invalid="true"] input') : $('#wiz-body .tile[aria-pressed="true"], #wiz-body input, #wiz-body select'); if (target) target.focus(); }
    }
    function ruleBody(p = S.persona) {
        const e = RW.error;
        if (RW.step === 0) {
            const names = [...new Set(MEDLIST.filter((m) => has(p, 'cd.view') || !m.cd).map((m) => m.med))];
            const valField = RW.match === 'name' ? `<div class="field"><label for="rw-v">Medicine name <span class="req">*</span></label><input id="rw-v" list="rw-names" data-act="rw-text" value="${esc(RW.value)}" autocomplete="off" placeholder="Start typing a medicine" ${e === 'value' ? 'aria-invalid="true" aria-describedby="rw-v-e"' : ''}><datalist id="rw-names">${names.map((n) => `<option value="${esc(n)}">`).join('')}</datalist>${e === 'value' ? ferr('rw-v-e', 'Choose which medicines this rule applies to.') : '<span class="who-sub">Matches the medicine’s name on the order.</span>'}</div>`
                : RW.match === 'controlled' ? `<div class="field"><span class="flabel">Medicines</span><div class="amt-fixed">${ic('shield', 's35')}<b>Any controlled medicine</b><span class="who-sub">from the order’s controlled flag</span></div></div>`
                    : `<div class="field"><label for="rw-v">${RW.match === 'route' ? 'Route' : RW.match === 'nzulm' ? 'Product (NZULM code)' : 'Type or class'} <span class="req">*</span></label><select id="rw-v" data-act="rw-val" ${e === 'value' ? 'aria-invalid="true" aria-describedby="rw-v-e"' : ''}><option value="">Choose</option>${(RW.match === 'route' ? ROUTES.map((x) => [x, x]) : RW.match === 'nzulm' ? NZULM.map(([c, n]) => [c, `${c} — ${n} (test code)`]) : CLASSES.map((x) => [x, x])).map(([v, l]) => `<option value="${esc(v)}"${RW.value === v ? ' selected' : ''}>${esc(l)}</option>`).join('')}</select>${e === 'value' ? ferr('rw-v-e', 'Choose which medicines this rule applies to.') : ''}</div>`;
            return `<div class="field" role="group" aria-labelledby="rw-m-l"><span class="flabel" id="rw-m-l">Match medicines by <span class="req">*</span></span><div class="tiles five">${Object.entries(MATCH).map(([k, m]) => `<button class="tile" type="button" data-act="rw-match" data-k="${k}" aria-pressed="${RW.match === k}"><span class="t-i">${ic(m.i)}</span><span><span class="t-l">${m.l}</span><span class="t-d">${esc(m.d)}</span>${m.isNew ? `<span class="t-d" style="color:var(--primary);font-weight:600">${esc(m.isNew)}</span>` : ''}</span></button>`).join('')}</div></div>
                ${valField}
                <div class="field" role="group" aria-labelledby="rw-s-l"><span class="flabel" id="rw-s-l">Where it applies <span class="req">*</span></span><div class="tiles">${Object.entries(HOUSES).map(([k, l]) => `<button class="tile" type="button" data-act="rw-scope" data-k="${k}" aria-pressed="${RW.scope === k}"><span class="t-i">${ic(k === 'all' ? 'building' : 'home')}</span><span><span class="t-l">${l}</span><span class="t-d">${k === 'all' ? 'Every house, now and in future' : 'This house only'}</span></span></button>`).join('')}</div></div>
                <div id="rw-live">${ruleLive(p)}</div>`;
        } else if (RW.step === 1) {
            const box = (k, l, on) => `<label class="tick"><input type="checkbox" data-act="rw-need" data-k="${k}" ${on ? 'checked' : ''} ${e === 'needs' ? 'aria-describedby="rw-n-e"' : ''}> ${l}</label>`;
            return `<fieldset class="need-set" ${e === 'needs' ? 'aria-invalid="true"' : ''}><legend class="flabel">Before the dose is saved <span class="req">*</span></legend>
                    ${box('cs', 'A second person confirms with their witness PIN', RW.countersign)}${box('pulse', 'Record pulse', RW.obs.includes('pulse'))}${box('bsl', 'Record blood sugar (BSL)', RW.obs.includes('bsl'))}${box('bp', 'Record blood pressure', RW.obs.includes('bp'))}
                    ${e === 'needs' ? ferr('rw-n-e', 'Choose at least one: a second person or an observation.') : '<span class="who-sub">A rule needs a second person and/or at least one observation. Observation values and ranges aren’t set here.</span>'}</fieldset>
                <div class="field" role="group" aria-labelledby="rw-a-l"><span class="flabel" id="rw-a-l">Status</span><div class="tiles two">${[['on', 'Active', 'Applies from the next dose saved', 'check'], ['off', 'Paused', 'Saved, but doesn’t apply yet', 'pause']].map(([k, l, d, i]) => `<button class="tile" type="button" data-act="rw-active" data-k="${k}" aria-pressed="${RW.active === (k === 'on')}"><span class="t-i">${ic(i)}</span><span><span class="t-l">${l}</span><span class="t-d">${d}</span></span></button>`).join('')}</div></div>
                <div id="rw-live">${ruleLive(p)}</div>`;
        } else {
            return `<div class="review-card"><h4>${ic('settings', 's35')}Rule <button class="btn-link" type="button" data-act="rw-step" data-step="0" style="margin-left:auto;font-size:12.5px">${ic('pencil', 's3')} Edit</button></h4><p style="margin:0;font-size:13.5px">${ruleSentence(RW)}</p><div style="display:flex;gap:6px;flex-wrap:wrap;margin-top:8px"><span class="chipn">${ic(RW.scope === 'all' ? 'building' : 'home', 's3')}${esc(HOUSES[RW.scope])}</span>${RW.active ? `<span class="badge b-success sm">${ic('check')}Active</span>` : `<span class="badge b-neutral sm">${ic('pause')}Paused</span>`}${MATCH[RW.match].isNew ? `<span class="badge b-info sm">${esc(MATCH[RW.match].isNew)}</span>` : ''}</div></div>
                ${rulePreview(RW, p)}${overlapWarn(RW)}
                <p class="text-caption" style="margin:0">${RW.active ? 'Applies from the next dose saved, at ' + (RW.scope === 'all' ? 'every house' : esc(HOUSES[RW.scope])) + '.' : 'Saved as paused.'} Recorded in the change history with your name and the time.</p>`;
        }
    }
    function ruleNext() {
        if (RW.step === 0 && RW.match !== 'controlled' && !String(RW.value).trim()) { RW.error = 'value'; renderRuleWizard('#rw-v'); return; }
        if (RW.step === 1 && !RW.countersign && !RW.obs.length) { RW.error = 'needs'; renderRuleWizard('[data-act="rw-need"]'); return; }
        RW.error = null; RW.step = Math.min(2, RW.step + 1); renderRuleWizard();
    }
    function ruleSave() {
        const who = PERSONAS[S.persona].name;
        const data = { match: RW.match, value: RW.match === 'name' ? String(RW.value).trim() : RW.value, scope: RW.scope, countersign: RW.countersign, obs: [...RW.obs], active: RW.active, by: who, when: '29 Sep 2026', paused: RW.active ? '' : `Paused 29 Sep 2026 by ${who}` };
        if (RW.edit) Object.assign(ruleById(RW.id), data); else { RW.id = 'mr' + (RULES.length + 1); RULES.push({ id: RW.id, ...data }); }
        S.ruleHistory.unshift({ when: '29 Sep 2026 9:12 am', who, what: `${RW.edit ? 'Changed' : 'Added'}: ${stripTags(ruleSentence(RW))}` });
        RW.saved = true; render(true); renderRuleWizard();
        toast('success', RW.edit ? 'Rule updated.' : 'Rule added.');
    }
    function openRuleToggle(id) {
        const r = ruleById(id);
        openDialog(simpleDialog({
            title: r.active ? 'Pause this rule?' : 'Resume this rule?', icon: r.active ? 'pause' : 'check', desc: r.active ? 'From the next dose saved, doses no longer need it.' : 'From the next dose saved, doses need it again.',
            body: `<p style="margin:0;font-size:13.5px">${ruleSentence(r)}</p>${rulePreview(r)}<p class="text-caption" style="margin:0">Recorded in the change history with your name and the time.</p>`,
            foot: `<button class="btn btn-outline" type="button" data-act="close">Cancel</button><button class="btn ${r.active ? 'btn-destructive' : 'btn-primary'}" type="button" data-act="rule-toggle-confirm" data-id="${id}">${r.active ? 'Pause rule' : 'Resume rule'}</button>`,
        }), 'dlg-simple', 'dlg-t', 'dlg-d');
    }
    function openRuleHistory(id) {
        const r = ruleById(id);
        const mine = [...S.ruleHistory.filter((h) => h.what.includes(stripTags(ruleWhat(r)))).map((h) => `${h.when} · ${h.who} · ${h.what}`), ...RULE_HISTORY_SEED.filter((h) => h.toLowerCase().includes((r.match === 'nzulm' ? 'digoxin' : String(r.value).toLowerCase())) || h.includes('P00 v4'))];
        openDialog(simpleDialog({ title: 'Rule change history', icon: 'history', desc: stripTags(ruleSentence(r)), body: `<ul class="hist">${mine.map((h) => `<li>${esc(h)}</li>`).join('')}</ul>`, foot: `<button class="btn btn-outline" type="button" data-act="close" data-autofocus>Close</button>` }), 'dlg-simple', 'dlg-t', 'dlg-d');
    }

    /* Controlled drugs — witness (Stephan, 29 Sep 2026): organisation default On, a per-house setting, per-medicine
     * witness on the order unchanged, and time-limited overrides granted by a senior role. */
    const CDW_OPTS = {
        org: [['on', 'On — witness required'], ['off', 'Off — not required']],
        house: [['org', 'Follow the organisation setting'], ['on', 'Always required at this house'], ['off', 'Not required at this house']],
        suggest: [['proposed', 'Suggest to managers (proposed)'], ['off', 'Don’t suggest']],
    };
    const CDW_LABEL = { org: 'Witness required for controlled drugs', kowhai: 'Kōwhai House', rimu: 'Rimu House', suggest: 'Suggest an override from the roster' };
    function cdWitnessCard(canManage) {
        const d = S.cdwDraft, v = S.cdw;
        const changed = Object.keys(v).some((k) => v[k] !== d[k]);
        const sel = (key, opts) => `<select id="cdw-${key}" class="set-sel" data-act="cdw-sel" data-key="${key}" ${canManage ? '' : 'disabled'}>${opts.map(([k, l]) => `<option value="${k}"${d[key] === k ? ' selected' : ''}>${esc(l)}</option>`).join('')}</select>`;
        const eff = (h) => (d[h] === 'org' ? d.org : d[h]) === 'on';
        const live = overridesList().filter((o) => ['active', 'soon', 'scheduled'].includes(o.state)).length;
        const hist = [...S.cdHistory.map((h) => `${h.when} · ${h.who} · ${h.what}`), '29 Sep 2026 · Stephan · Decided: witness required for controlled drugs by default (On); the per-medicine witness on orders stays as it is'];
        return `<section class="card card-pad" id="sec-cdw" aria-labelledby="cdw-h"><div class="cap-row" style="margin-bottom:2px"><h3 id="cdw-h" tabindex="-1" style="outline:none">Controlled drugs — witness</h3>${dtag('D8')}</div>
            <p class="text-subtle" style="margin:0 0 6px;font-size:12.5px">A witness is a different person, clocked in on a shift covering the house, with witness competency and a witness PIN set — checked against the roster. Any order can still require a witness for its medicine; that stays on the order.</p>
            <div class="set-row"><div><label for="cdw-org" style="font-weight:600;font-size:13px">Witness required for controlled drugs</label><div class="help">The organisation default for every house.</div></div><div>${sel('org', CDW_OPTS.org)}<div class="set-meta"><span class="chipn">Stephan’s decision, 29 Sep 2026: On</span></div></div></div>
            <div class="set-row"><div><div style="font-weight:600;font-size:13px">Each house</div><div class="help">A house follows the organisation setting unless it sets its own.</div></div><div class="house-set">
                ${[['kowhai', '2 people with controlled medicines'], ['rimu', '1 person with controlled medicines']].map(([h, n]) => `<div class="hs-row"><label for="cdw-${h}"><b>${HOUSES[h]}</b><span class="who-sub">${n}</span></label>${sel(h, CDW_OPTS.house)}<span class="badge b-${eff(h) ? 'success' : 'warning'} sm">${ic(eff(h) ? 'check' : 'alert-triangle')}${eff(h) ? 'Witness required' : 'Not required'}</span></div>`).join('')}</div></div>
            <div class="set-row"><div><div style="font-weight:600;font-size:13px">Time-limited witness overrides</div><div class="help">For one house (optionally one person or medicine), with a start, an end and a reason. They end by themselves and can be revoked. Each dose is marked and followed up.</div></div><div><dl class="kv stack-kv" style="margin:0"><dt>Who can grant</dt><dd><span class="chipn">Provider managers (mockup)</span><div class="who-sub" style="margin-top:3px">Permission decision: reuse <code>medications.controlled.override</code> (today it means “override controlled drug discrepancy blocks”) or add a new key. Recommendation: a new key, so the existing permission doesn’t quietly gain a new meaning.</div></dd><dt>Longest override</dt><dd>${NC()} <span class="who-sub">Every override needs an end time.</span></dd><dt>Now</dt><dd><a href="${hrefFrame(S.persona, 'safety', 'overrides')}">${live} active or scheduled · open Witness overrides</a></dd></dl></div></div>
            <div class="set-row"><div><label for="cdw-suggest" style="font-weight:600;font-size:13px">Suggest an override from the roster</label><div class="help">When the roster shows single staffing at a house that holds controlled drugs, managers see a suggestion to grant a time-limited override. It never grants anything by itself.</div></div><div>${sel('suggest', CDW_OPTS.suggest)}<div class="set-meta"><span class="badge b-info sm">Proposed — Stephan to confirm</span></div></div></div>
            <div style="display:flex;justify-content:flex-end;margin-top:12px">${canManage ? `<button class="btn btn-primary btn-sm" type="button" data-act="cdw-save" ${changed ? '' : 'disabled'}>Save witness settings</button>` : '<p class="text-caption" style="margin:0">Only someone who manages medication settings for all sites can change these.</p>'}</div>
            <div style="margin-top:14px"><div class="nh">Change history</div><ul class="hist">${hist.map((h) => `<li>${esc(h)}</li>`).join('')}</ul></div></section>`;
    }
    function openCdwConfirm() {
        const ch = Object.keys(S.cdw).filter((k) => S.cdw[k] !== S.cdwDraft[k]);
        const off = ch.some((k) => S.cdwDraft[k] === 'off' && k !== 'suggest');
        openDialog(simpleDialog({
            title: 'Change the controlled-drug witness settings?', icon: 'shield', desc: 'From the next controlled dose recorded:',
            body: `<ul style="margin:0;padding-left:18px;font-size:13px">${ch.map((k) => `<li><b>${esc(CDW_LABEL[k])}:</b> ${esc(CDW_OPTS[k === 'kowhai' || k === 'rimu' ? 'house' : k].find((x) => x[0] === S.cdwDraft[k])[1])}</li>`).join('')}</ul>${off ? B('warning', 'alert-triangle', 'Controlled doses will be recorded without a witness there', 'Check this with clinical governance first (D8). For a short gap, a time-limited override is safer.') : ''}<p class="text-caption" style="margin:0">Recorded in the change history and the audit log with your name and the time.</p>`,
            foot: `<button class="btn btn-outline" type="button" data-act="close">Cancel</button><button class="btn ${off ? 'btn-destructive' : 'btn-primary'}" type="button" data-act="cdw-confirm">Save witness settings</button>`,
        }), 'dlg-simple w480', 'dlg-t', 'dlg-d');
    }
    function pinSummaryCard() {
        const r = S.pinRules;
        const n = (st) => STAFF_PINS.filter((x) => x.pin === st).length;
        return `<section class="card card-pad" id="sec-pin" aria-labelledby="ps-h"><div class="cap-row"><h3 id="ps-h" tabindex="-1" style="outline:none">Second-person confirmation</h3><a class="btn btn-outline btn-sm" href="${hrefFrame(S.persona, 'settings', 'secondperson')}">Manage PIN rules${ic('arrow-right', 's3')}</a></div>
            <dl class="kv" style="margin:0"><dt>Method</dt><dd>Personal 6-digit witness PIN — for co-signing, controlled-drug witnessing and confirming a different amount</dd><dt>Wrong attempts</dt><dd>${r.attempts ? esc(r.attempts) + ' attempts' : NC()}</dd><dt>Lockout</dt><dd>${r.lockout ? esc(r.lockout) + ' minutes' : NC()}</dd><dt>Forgotten-PIN fallback</dt><dd>${r.fallback === 'yes' ? 'Allowed' : NC('Not configured — not allowed')}</dd><dt>Staff PINs</dt><dd>${n('set')} set · ${n('notset')} not set · ${n('locked')} locked · ${n('adminreset')} reset</dd><dt>Own PIN</dt><dd>Each person sets theirs in their account settings (Settings › Witness PIN)</dd></dl></section>`;
    }
    function medicationRulesView() {
        const canManage = allSites(S.persona);
        const onPage = `<nav class="onpage card card-pad" aria-label="On this page"><span class="nh" style="margin:0 6px 0 0">On this page</span>${[['sec-medrules', 'Rules for specific medicines'], ['sec-safety', 'Safety rules'], ['sec-cdw', 'Controlled drugs — witness'], ['sec-pin', 'Second-person confirmation']].map(([id, l]) => `<button type="button" class="fchip-plain" data-act="jump" data-to="${id}">${l}</button>`).join('')}</nav>`;
        return onPage + medRulesCard(canManage) + safetyRulesCard(canManage) + cdWitnessCard(canManage) + pinSummaryCard()
            + `<div class="annot"><div class="a-tag">${ic('info', 's3')}Design note</div>“Medication rules” replaces “Administration rules” at the same URL (/emar/settings). Existing medicine rules keep their meaning: a countersignature and/or observations (pulse, blood sugar, blood pressure), matched by medicine name, route or NZULM code, for all houses or one house. New: matching by type or class (needs a medicine classification on orders) and by controlled status. The allergy rule, restricted competency, the amount rule, the controlled-drug witness default and a summary of the PIN rules sit alongside. ${canManage ? `You’re signed in as the ${esc(PERSONAS[S.persona].role.toLowerCase())}, with all-sites authority.` : 'Switch “Signed in as” to Clinical lead or Provider manager to edit; the house lead sees everything read-only.'} Built in <b>P11</b>.
                <div style="margin-top:8px"><label for="rules-demo" style="font-weight:600">Mockup state for the medicine rules:</label> <select id="rules-demo" data-act="rules-demo">${[['loaded', 'Loaded'], ['empty', 'No rules yet'], ['loading', 'Loading'], ['error', 'Couldn’t load']].map(([k, l]) => `<option value="${k}"${S.rulesDemo === k ? ' selected' : ''}>${l}</option>`).join('')}</select></div></div>`;
    }

    /* ───────────── v4: controlled-drug witness overrides (Safety & oversight) ─────────────
     * Stephan (29 Sep 2026): uses the roster and shifts explicitly — “no eligible colleague” comes from clock-ins,
     * witness competency and PIN status; the request shows the roster evidence for its window; managers get a
     * suggestion when the roster shows single staffing at a house holding controlled drugs; one screen to approve. */
    const OVERRIDES = [
        { id: 'wo5', house: 'Kōwhai House', scope: 'Grace · clonazepam', start: '7:00 am today', end: '3:00 pm today', endShort: '3:00 pm', by: 'Rangi Parata', reason: 'Single staffing on the day shift (roster: 1 witness-eligible staff member)', state: 'active', doses: 0, scn: 'cdOverride' },
        { id: 'wo6', house: 'Kōwhai House', scope: 'Grace · clonazepam', start: '9:14 am today', end: '3:00 pm today', endShort: '3:00 pm', by: 'Rangi Parata', reason: 'Nobody else on shift can witness (from the roster)', state: 'active', doses: 0, req: 'Priya Shah', seedApproved: true },
        { id: 'wo4', house: 'Rimu House', scope: 'Any controlled medicine', start: '7:00 am today', end: '11:00 am today', endShort: '11:00 am', by: 'Rangi Parata', reason: 'Witness called away to hospital with a resident', state: 'soon', doses: 1 },
        { id: 'wo7', house: 'Kōwhai House', scope: 'Any controlled medicine', start: '10:00 pm today', end: '7:00 am Tue 29 Sep', endShort: '7:00 am', by: 'Rangi Parata', reason: 'Single staffing on the roster (1 staff rostered 10:00 pm–7:00 am)', state: 'scheduled', doses: 0, catalogueOnly: true },
        { id: 'wo1', house: 'Kōwhai House', scope: 'Grace · clonazepam', start: '10:00 pm Sun 27 Sep', end: '7:00 am Mon 28 Sep', endShort: '7:00 am', by: 'Rangi Parata', reason: 'Single staffing on the roster (1 staff rostered 10:00 pm–7:00 am)', state: 'expired', doses: 1, endedNote: 'Ended by itself at 7:00 am' },
        { id: 'wo2', house: 'Rimu House', scope: 'Any controlled medicine', start: '8:00 am Sat 26 Sep', end: '8:00 pm Sat 26 Sep', endShort: '8:00 pm', by: 'Rangi Parata', reason: 'Staff shortage — no cover found', state: 'revoked', doses: 0, endedNote: 'Revoked at 4:10 pm by Rangi Parata: “Second staff member arrived”' },
        { id: 'wo0', house: 'Kōwhai House', scope: 'Aroha · methylphenidate', start: 'Requested 11:40 am Fri 25 Sep', end: '—', endShort: '—', by: 'Rangi Parata', reason: 'Asked by Daniel Ahn: witness called away', state: 'declined', req: 'Daniel Ahn', endedNote: 'Declined at 11:48 am: “Jordan is 10 minutes away — wait for him.”' },
    ];
    const overrideById = (id) => OVERRIDES.find((o) => o.id === id) || S.ovGranted.find((o) => o.id === id);
    function overridesList() {
        const fromReq = S.ovGranted.some((o) => o.fromRequest);
        const seed = OVERRIDES.filter((o) => !o.catalogueOnly && (o.scn ? S.scenario === o.scn : true) && (!o.seedApproved || (S.ovRequest === 'approved' && !fromReq)));
        return [...S.ovGranted, ...seed].map((o) => (S.ovRevoked[o.id] ? { ...o, state: 'revoked', endedNote: S.ovRevoked[o.id] } : o));
    }
    const OV_BADGE = { active: ['warning', 'shield', 'Active'], soon: ['warning', 'clock', 'Ends before shift end'], scheduled: ['info', 'calendar', 'Scheduled'], expired: ['neutral', 'check', 'Ended'], revoked: ['neutral', 'x-circle', 'Revoked'], declined: ['neutral', 'x', 'Declined'] };
    const initialsOf = (n) => n.split(' ').map((x) => x[0]).join('');
    function overrideRow(o) {
        const [v, i, l] = OV_BADGE[o.state];
        const line = o.state === 'soon' ? `Ends ${o.endShort} — before this shift ends (3:00 pm)` : o.state === 'scheduled' ? `Starts ${o.start}` : o.state === 'active' ? `Until ${o.end}` : o.endedNote || '';
        return `<div class="fu-row" data-menu="override:${o.id}"><div><div class="fu-title">${esc(o.house)} · ${esc(o.scope)}</div><div class="fu-src">${esc(o.start)}${o.end !== '—' ? ' – ' + esc(o.end) : ''} · ${esc(o.reason)}</div></div>
            <div class="owner"><span class="disc sm" aria-hidden="true">${initialsOf(o.by)}</span><div><div>${esc(o.by)}</div><div class="o-sub">${o.state === 'declined' ? 'Declined' : 'Granted'}${o.req ? ' · asked by ' + esc(o.req) : ''}</div></div></div>
            <div class="state-cell"><span class="badge b-${v}">${ic(i)}${l}</span><span class="state-line">${esc(line)}</span>${o.doses != null && o.state !== 'declined' ? `<span class="state-line">${o.doses} ${o.doses === 1 ? 'dose' : 'doses'} recorded without a witness</span>` : ''}</div>
            <div class="row-actions"><button class="btn btn-ghost btn-sm" type="button" data-act="ovr-detail" data-id="${o.id}" data-fk="ovr-${o.id}">View</button>${kebab('override', o.id, `${o.house} override`)}</div></div>`;
    }
    function requestRow() {
        const can = has(S.persona, 'cd.override');
        return `<div class="fu-row" data-menu="ovreq:1"><div><div class="fu-title">Priya Shah asks for a witness override</div><div class="fu-src">Grace · clonazepam 9:00 am dose · Kōwhai House · roster: ${esc(ROSTER.now.summary)}</div></div>
            <div class="owner"><span class="disc sm" aria-hidden="true">PS</span><div><div>Priya Shah</div><div class="o-sub">Support worker · clocked in 7:02 am</div></div></div>
            <div class="state-cell"><span class="badge b-info">${ic('clock')}Waiting for a manager</span><span class="state-line">Sent 9:13 am · asks until 3:00 pm (end of Priya’s shift)</span></div>
            <div class="row-actions"><button class="btn ${can ? 'btn-primary' : 'btn-outline'} btn-sm" type="button" data-act="ovr-grant" data-mode="request" data-fk="ovr-req">${can ? 'Review' : 'View'}</button>${kebab('ovreq', '1', 'witness override request')}</div></div>`;
    }
    const suggestionBanner = (can) => `<div class="banner info" role="status"><span class="b-ico">${ic('calendar')}</span><div class="b-body"><div class="b-title">Single staffing tonight at Kōwhai House</div><div class="b-text">The roster shows 1 staff rostered 10:00 pm–7:00 am (Mere Kahu). Kōwhai House holds controlled drugs and 1 controlled dose falls in that window (Grace, clonazepam, 6:00 am). A time-limited witness override may be needed. This is a suggestion — nothing changes until someone grants it.</div><div class="b-actions">${can ? `<button class="btn btn-primary btn-sm" type="button" data-act="ovr-grant" data-mode="suggest" data-fk="ovr-suggest">${ic('shield')}Review and grant</button>` : '<span class="text-caption">A provider manager can grant it.</span>'}<button class="btn btn-ghost btn-sm" type="button" data-act="toast-outside">${ic('calendar')}Open the roster</button></div></div></div>`;
    function overridesView() {
        const p = S.persona, can = has(p, 'cd.override');
        const list = overridesList();
        const live = list.filter((o) => ['active', 'soon', 'scheduled'].includes(o.state));
        const past = list.filter((o) => ['expired', 'revoked', 'declined'].includes(o.state));
        const suggested = S.ovGranted.some((o) => o.fromSuggest);
        const suggest = S.cdw.suggest !== 'off' && !suggested ? suggestionBanner(can) : '';
        const empty = (t) => `<div class="card-pad"><p class="text-caption" style="margin:0">${t}</p></div>`;
        return suggest
            + `<section class="card" style="overflow:hidden" aria-labelledby="ovq-h"><div class="slot-head" role="heading" aria-level="3" id="ovq-h">Requests waiting<span style="font-weight:500">· ${S.ovRequest === 'waiting' ? 1 : 0}</span></div>${S.ovRequest === 'waiting' ? requestRow() : empty('No requests waiting. Workers ask from the dose when nobody on shift can witness.')}</section>`
            + `<section class="card" style="overflow:hidden" aria-labelledby="ovl-h"><div class="slot-head" role="heading" aria-level="3" id="ovl-h" style="justify-content:space-between"><span>Active and scheduled overrides <span style="font-weight:500">· ${live.length}</span></span>${can ? `<button class="btn btn-outline btn-sm" type="button" data-act="ovr-grant" data-mode="new" data-fk="ovr-new">${ic('plus')}Grant an override</button>` : ''}</div>${live.length ? live.map(overrideRow).join('') : empty('No overrides in force. Every controlled dose needs a witness.')}</section>`
            + `<section class="card" style="overflow:hidden" aria-labelledby="ovh-h"><div class="slot-head" role="heading" aria-level="3" id="ovh-h">History<span style="font-weight:500">· ${past.length}</span></div>${past.map(overrideRow).join('')}</section>`
            + `<p class="text-caption" style="margin:0">Every dose recorded under an override is marked “No witness — override by {name}: {reason}”, and the house lead gets a follow-up next shift. Times in NZDT.</p>`
            + `<div class="annot"><div class="a-tag">${ic('info', 's3')}Design note</div>New view, designed in full in <b>P07a</b>. Evidence comes from the roster and clock-ins (shifts covering the house now), witness competency and witness-PIN status — the same checks the server makes. ${can ? 'You’re the provider manager: you can grant, approve, decline and revoke.' : 'Switch “Signed in as” to Provider manager to grant, approve or revoke; leads see everything read-only.'} Which permission grants overrides is a decision (${dtag('D8')}).</div>`;
    }
    function openOverrideRequest(row) {
        const r = MEDS[row] || MEDS.r11, pp = PEOPLE[r.pid];
        openDialog(simpleDialog({
            title: 'Ask a manager for a witness override', icon: 'send', desc: `${esc(pp.pref)} · ${esc(r.med)} ${esc(r.str)} · ${r.slot} dose · Kōwhai House`,
            body: overrideRequestBody(row),
            foot: `<button class="btn btn-outline" type="button" data-act="close">Cancel</button><button class="btn btn-primary" type="button" data-act="orq-send">${ic('send')}Send request</button>`,
        }), 'dlg-simple', 'dlg-t', 'dlg-d');
    }
    function overrideRequestBody(row) {
        const r = MEDS[row] || MEDS.r11, pp = PEOPLE[r.pid];
        const c = S.ovRequestCover;
        return identityHeader(r.pid, { compact: true, support: r.support }) + rosterEvidence('now', 'you')
                + `<div class="field" role="group" aria-labelledby="orq-l"><span class="flabel" id="orq-l">What should it cover? <span class="req">*</span></span><div class="tiles two">
                    <button class="tile" type="button" data-act="orq-cover" data-k="dose" aria-pressed="${c === 'dose'}"><span class="t-i">${ic('pill')}</span><span><span class="t-l">${esc(pp.pref)}’s ${esc(r.med.toLowerCase())}</span><span class="t-d">Until 3:00 pm, the end of your shift</span></span></button>
                    <button class="tile" type="button" data-act="orq-cover" data-k="shift" aria-pressed="${c === 'shift'}"><span class="t-i">${ic('home')}</span><span><span class="t-l">All controlled doses at Kōwhai House</span><span class="t-d">Until 3:00 pm, the end of your shift</span></span></button></div></div>
                <div class="field"><label for="orq-r">Why <span class="req">*</span></label><select id="orq-r"><option selected>Nobody else on shift can witness (from the roster)</option><option>Witness called away</option><option>Other</option></select></div>
                <div class="field"><label for="orq-n">Anything the manager should know <span class="who-sub">(optional)</span></label><textarea id="orq-n" rows="2" placeholder="e.g. Mere is on shift but isn’t witness-trained."></textarea></div>
                ${B('info', 'users', 'Who gets it', `Managers allowed to grant witness overrides (in this mockup, provider managers — the permission is a decision). They see this roster and approve or decline from their own login.`)}
                <p class="text-caption" style="margin:0">Until a manager answers, don’t give it without a witness. You can record it as not given, or wait.</p>`;
    }

    /* Manager's approve / grant screen — ONE screen, prefilled house, window and reason (Stephan: “must be easy”). */
    let G = null;
    const OV_REASONS = ['Nobody else on shift can witness (from the roster)', 'Single staffing on the roster', 'Witness called away', 'Staff shortage — no cover found', 'Other'];
    const dtKey = (d) => `${d.date} ${canon(d.time)}`;
    function fmtWhen(d) {
        if (d.date === TODAY) return `${fmtTime(d.time)} today`;
        const [y, m, day] = d.date.split('-').map(Number);
        return `${fmtTime(d.time)} ${WD_SHORT[new Date(y, m - 1, day).getDay()]} ${day} ${MON[m - 1]}`;
    }
    function openOverrideGrant(mode, opts = {}) {
        const dt = (date, h, m, ap) => ({ date, time: { h, m, ap }, dp: null, tp: null });
        G = mode === 'suggest' ? { mode, house: 'kowhai', person: '', med: '', start: dt(TODAY, 10, 0, 'pm'), end: dt('2026-09-29', 7, 0, 'am'), reason: 'Single staffing on the roster', note: '', roster: 'night' }
            : mode === 'request' ? { mode, house: 'kowhai', person: S.ovRequestCover === 'shift' ? '' : 'grace', med: S.ovRequestCover === 'shift' ? '' : 'Clonazepam', start: dt(TODAY, 9, 14, 'am'), end: dt(TODAY, 3, 0, 'pm'), reason: 'Nobody else on shift can witness (from the roster)', note: 'Mere is on shift but isn’t witness-trained.', roster: 'now' }
                : { mode: 'new', house: 'kowhai', person: '', med: '', start: dt(TODAY, 9, 14, 'am'), end: dt(TODAY, 3, 0, 'pm'), reason: '', note: '', roster: 'now' };
        G.error = null; G.declining = !!opts.decline; G.declineReason = '';
        openDialog('', 'dlg-simple w640', 'dlg-t', 'dlg-d');
        renderGrant(G.declining ? '#g-decline' : null);
    }
    function grantParts(persona = S.persona) {
        const g = G, can = has(persona, 'cd.override'), req = g.mode === 'request';
        const title = req ? 'Witness override request' : g.mode === 'suggest' ? 'Grant a witness override for tonight' : 'Grant a witness override';
        const desc = req ? 'From Priya Shah at 9:13 am · Grace · clonazepam 9:00 am dose · Kōwhai House' : g.mode === 'suggest' ? 'Suggested from the roster: single staffing at a house that holds controlled drugs' : 'Let controlled doses be recorded without a witness, for a limited time';
        const who = g.person ? `${PEOPLE[g.person].pref}’s ${g.med ? g.med.toLowerCase() : 'controlled medicines'}` : g.med ? `${g.med.toLowerCase()} (anyone)` : 'all controlled medicines';
        const sel = (key, label, opts, req2) => `<div class="field"><label for="g-${key}">${label}${req2 ? ' <span class="req">*</span>' : ''}</label><select id="g-${key}" data-act="g-sel" data-key="${key}" ${can ? '' : 'disabled'} ${g.error === key ? `aria-invalid="true" aria-describedby="g-${key}-e"` : ''}>${opts.map(([v, l]) => `<option value="${esc(v)}"${g[key] === v ? ' selected' : ''}>${esc(l)}</option>`).join('')}</select>${g.error === key ? ferr(`g-${key}-e`, 'Choose a reason.') : ''}</div>`;
        const winErr = g.error === 'window' ? 'The end must be after the start and later than now (9:12 am).' : '';
        const body = rosterEvidence(g.roster, req ? 'requester' : 'you')
            + `<div class="fgrid three">${sel('house', 'House', [['kowhai', 'Kōwhai House'], ['rimu', 'Rimu House']], true)}${sel('person', 'Only for (optional)', [['', 'Anyone at this house'], ['grace', 'Grace Liu'], ['aroha', 'Aroha Ngata']])}${sel('med', 'Only this medicine (optional)', [['', 'Any controlled medicine'], ['Clonazepam', 'Clonazepam'], ['Methylphenidate', 'Methylphenidate']])}</div>`
            + dateTimeField('Starts', { state: g.start, key: 'gs', id: 'g-s', hint: 'Prefilled from the roster.', err: '' })
            + dateTimeField('Ends', { state: g.end, key: 'ge', id: 'g-e', hint: 'It ends by itself at this time. Every override needs an end.', err: winErr })
            + `<div class="fgrid">${sel('reason', 'Reason', [['', 'Choose a reason'], ...OV_REASONS.map((x) => [x, x])], true)}<div class="field"><label for="g-note">Note <span class="who-sub">(optional)</span></label><textarea id="g-note" rows="2" data-act="g-note" ${can ? '' : 'disabled'}>${esc(g.note)}</textarea></div></div>`
            + B('info', 'info', 'What this does', `Controlled doses — ${esc(who)} — at ${esc(HOUSES[g.house])} can be recorded without a witness from <b>${fmtWhen(g.start)}</b> to <b>${fmtWhen(g.end)}</b>. Each dose is marked “No witness — override by ${esc(PERSONAS[can ? persona : 'pm'].name)}”, and the house lead gets a follow-up next shift. It ends by itself; it can be revoked sooner.`)
            + (g.declining ? `<div class="field"><label for="g-decline">Tell Priya why, and what to do instead <span class="req">*</span></label><textarea id="g-decline" rows="2" data-act="g-decline" placeholder="e.g. Jordan can come in at 10:00 am to witness." ${g.error === 'decline' ? 'aria-invalid="true" aria-describedby="g-decline-e"' : ''}>${esc(g.declineReason)}</textarea>${g.error === 'decline' ? ferr('g-decline-e', 'Say why you’re declining.') : ''}</div>` : '')
            + (can ? '' : `<p class="text-caption" style="margin:0">Only people allowed to grant witness overrides can approve or decline. You can see the request and the roster.</p>`);
        const foot = !can ? `<button class="btn btn-outline" type="button" data-act="close" data-autofocus>Close</button>`
            : g.declining ? `<button class="btn btn-outline" type="button" data-act="ovr-decline-cancel">Back</button><button class="btn btn-destructive" type="button" data-act="ovr-decline">Decline request</button>`
                : `<button class="btn btn-outline" type="button" data-act="close">Cancel</button>${req ? '<button class="btn btn-outline" type="button" data-act="ovr-decline-start">Decline</button>' : ''}<button class="btn btn-primary" type="button" data-act="ovr-approve">${ic('check')}${req ? 'Approve override' : 'Grant override'}</button>`;
        return { title, icon: 'shield', desc, body, foot };
    }
    function renderGrant(focusSel) {
        const dlg = $('.dlg'); if (!dlg || !G) return;
        const scrollTop = $('.d-body', dlg) ? $('.d-body', dlg).scrollTop : 0;
        dlg.innerHTML = simpleDialog(grantParts());
        const b2 = $('.d-body', dlg); if (b2) b2.scrollTop = scrollTop;
        if (focusSel) { const el = $(focusSel); if (el) el.focus(); } else { const t2 = $('#dlg-t'); if (t2) t2.focus(); }
        placePopovers();
    }
    function grantApprove() {
        const g = G;
        if (!g.reason) { g.error = 'reason'; renderGrant('#g-reason'); return; }
        if (!(dtKey(g.end) > dtKey(g.start)) || !(dtKey(g.end) > `${TODAY} 09:12`)) { g.error = 'window'; renderGrant('#g-e-time'); return; }
        const req = g.mode === 'request';
        const scope = g.person ? `${PEOPLE[g.person].pref} · ${g.med ? g.med.toLowerCase() : 'any controlled medicine'}` : g.med ? g.med : 'Any controlled medicine';
        const state = dtKey(g.start) > `${TODAY} 09:14` ? 'scheduled' : dtKey(g.end) < `${TODAY} 15:00` ? 'soon' : 'active';
        const o = { id: 'wo' + (100 + S.ovGranted.length), house: HOUSES[g.house], scope, start: fmtWhen(g.start), end: fmtWhen(g.end), endShort: fmtTime(g.end.time), by: PERSONAS[S.persona].name, reason: g.reason + (g.note && !req ? ` — ${g.note}` : ''), state, doses: 0, req: req ? 'Priya Shah' : null, fromRequest: req, fromSuggest: g.mode === 'suggest' };
        S.ovGranted.unshift(o);
        if (req) S.ovRequest = 'approved';
        closeDialog(true); G = null; render(true);
        toast('success', req ? 'Override approved. Priya Shah can record Grace’s clonazepam without a witness until 3:00 pm, and gets a message now.' : `Override ${state === 'scheduled' ? 'scheduled' : 'granted'}: ${o.house}, ${o.start} to ${o.end}.`);
        const f = $('[data-fk="ovr-' + o.id + '"]'); if (f) f.focus();
    }
    function grantDecline() {
        const g = G;
        if (!g.declineReason.trim()) { g.error = 'decline'; renderGrant('#g-decline'); return; }
        S.ovRequest = 'declined'; S.ovDecline = g.declineReason.trim();
        S.ovGranted.unshift({ id: 'wo' + (100 + S.ovGranted.length), house: 'Kōwhai House', scope: 'Grace · clonazepam', start: 'Requested 9:13 am today', end: '—', endShort: '—', by: PERSONAS[S.persona].name, reason: 'Asked by Priya Shah: nobody else on shift can witness', state: 'declined', req: 'Priya Shah', endedNote: `Declined at 9:15 am: “${S.ovDecline}”` });
        closeDialog(true); G = null; render(true);
        toast('info', 'Declined. Priya Shah sees your reason on the dose.');
    }
    function openOverrideDetail(id) {
        const o = overridesList().find((x) => x.id === id) || overrideById(id);
        const [v, i, l] = OV_BADGE[o.state];
        const can = has(S.persona, 'cd.override') && ['active', 'soon', 'scheduled'].includes(o.state);
        openDialog(simpleDialog({
            title: `Witness override · ${o.house}`, icon: 'shield', desc: `${esc(o.scope)} · ${esc(o.start)}${o.end !== '—' ? ' – ' + esc(o.end) : ''}`,
            body: `<div><span class="badge b-${v}">${ic(i)}${l}</span></div><dl class="kv" style="margin:0"><dt>House</dt><dd>${esc(o.house)}</dd><dt>Covers</dt><dd>${esc(o.scope)}</dd><dt>From</dt><dd>${esc(o.start)}</dd><dt>To</dt><dd>${esc(o.end)}</dd><dt>${o.state === 'declined' ? 'Declined by' : 'Granted by'}</dt><dd>${esc(o.by)}${o.req ? ` · asked by ${esc(o.req)}` : ''}</dd><dt>Reason</dt><dd>${esc(o.reason)}</dd>${o.endedNote ? `<dt>Outcome</dt><dd>${esc(o.endedNote)}</dd>` : ''}${o.doses != null && o.state !== 'declined' ? `<dt>Doses without a witness</dt><dd>${o.doses ? `${o.doses} — each marked and followed up by the house lead` : 'None yet'}</dd>` : ''}</dl>${o.req === 'Priya Shah' ? rosterEvidence('now', 'requester') : ''}`,
            foot: `<button class="btn btn-outline" type="button" data-act="close" data-autofocus>Close</button>${can ? `<button class="btn btn-destructive" type="button" data-act="ovr-revoke" data-id="${o.id}">Revoke override</button>` : ''}`,
        }), 'dlg-simple', 'dlg-t', 'dlg-d');
    }
    function openOverrideRevoke(id) {
        const o = overridesList().find((x) => x.id === id) || overrideById(id);
        openDialog(simpleDialog({
            title: 'Revoke this witness override?', icon: 'x-circle', desc: `${esc(o.house)} · ${esc(o.scope)} · until ${esc(o.end)}`,
            body: `<p style="margin:0;font-size:13px">From now, controlled doses there need a witness again. Doses already recorded under it stay marked.</p><div class="field"><label for="rv-why">Why <span class="req">*</span></label><textarea id="rv-why" rows="2" placeholder="e.g. A second staff member arrived."></textarea><div id="rv-err"></div></div><p class="text-caption" style="margin:0">Recorded with your name and the time. The house lead and anyone on shift there are told.</p>`,
            foot: `<button class="btn btn-outline" type="button" data-act="close">Cancel</button><button class="btn btn-destructive" type="button" data-act="ovr-revoke-confirm" data-id="${id}">Revoke override</button>`,
        }), 'dlg-simple w480', 'dlg-t', 'dlg-d');
    }

    /* Second-person confirmation settings (Stephan, 29 Sep 2026: “the pin management will need to be introduced in the settings”). */
    const PIN_RULES = [
        { key: 'attempts', label: 'Wrong attempts before a PIN locks', help: 'Counts wrong PINs typed for one person, across all screens.', type: 'number', unit: 'attempts' },
        { key: 'lockout', label: 'How long a locked PIN stays locked', help: 'Until then, only the owner (or an allowed reset) unlocks it.', type: 'number', unit: 'minutes' },
        { key: 'renewal', label: 'PIN renewal (optional)', help: 'Leave empty for no renewal. When set, people are asked to choose a new PIN after this many months.', type: 'number', unit: 'months' },
        { key: 'fallback', label: 'Forgotten-PIN fallback', help: 'Lets the recorder name a colleague who has forgotten their PIN. The dose is marked “second person not verified”, and the colleague confirms from their own login.', type: 'select', opts: [['nc', 'Not configured — not allowed'], ['yes', 'Allowed'], ['no', 'Not allowed']] },
        { key: 'fallbackCd', label: 'Forgotten-PIN fallback for controlled drugs', help: 'Separate switch, because a controlled-drug witness must be physically present (D8).', type: 'select', opts: [['nc', 'Not configured — not allowed'], ['yes', 'Allowed'], ['no', 'Not allowed']] },
        { key: 'resetRoles', label: 'Who can reset another person’s PIN', help: 'A reset never shows or sets the PIN. The owner must choose a new one before they can co-sign or witness.', type: 'select', opts: [['nc', 'Not configured — nobody'], ['lead', 'House leads'], ['clinical', 'Clinical leads'], ['both', 'House leads and clinical leads']] },
        { key: 'confirmLimit', label: 'Time limit for a named colleague to confirm', help: 'After this, a missing answer raises a follow-up for the house lead.', type: 'number', unit: 'minutes' },
        { key: 'routeTo', label: 'Who gets the follow-up', help: 'When the colleague answers “I wasn’t there” or doesn’t answer in time.', type: 'select', opts: [['nc', 'Not configured'], ['lead-on-shift', 'House lead on shift'], ['lead-house', 'House lead for the house']] },
    ];
    const pinVal = (r, v) => r.type === 'number' ? (v ? `${v} ${r.unit}` : 'Not configured') : r.opts.find((o) => o[0] === v)[1];
    function pinRulesCard(canManage, staticSpec) {
        const vals = S.pinRules, draft = S.pinDraft;
        const changed = PIN_RULES.filter((r) => String(draft[r.key]) !== String(vals[r.key]));
        return `<section class="card card-pad" aria-labelledby="pr-h" ${staticSpec ? 'inert' : ''}><div class="cap-row" style="margin-bottom:2px"><h3 id="pr-h" tabindex="-1" style="outline:none">Second-person confirmation</h3><span class="text-caption">Applies at every site, for restricted-competency co-signing and controlled-drug witnessing</span></div>
            <div class="set-row"><div><div style="font-weight:600;font-size:13px">Method</div><div class="help">Chosen by Stephan on 29 September 2026. The colleague’s login password is retired for this.</div></div><div><span class="fixed-val">${ic('lock', 's35')}Personal witness PIN</span><div class="set-meta"><span class="chipn">Login password — retired</span></div></div></div>
            <div class="set-row"><div><div style="font-weight:600;font-size:13px">PIN length</div><div class="help">Fixed. Digits only. Separate from the login password.</div></div><div><span class="fixed-val">6 digits</span></div></div>
            ${PIN_RULES.map((r) => `<div class="set-row"><div><label for="pr-${r.key}" style="font-weight:600;font-size:13px">${esc(r.label)}</label><div class="help">${esc(r.help)}</div></div><div>${r.type === 'number' ? `<input id="pr-${r.key}" type="number" min="1" inputmode="numeric" placeholder="Not configured" value="${esc(draft[r.key])}" data-act="pr-in" data-key="${r.key}" ${canManage ? '' : 'disabled'} aria-describedby="pr-${r.key}-u"><span class="who-sub" id="pr-${r.key}-u">${esc(r.unit)}</span>` : `<select id="pr-${r.key}" style="border:1px solid var(--input);border-radius:8px;min-height:36px;padding:0 8px;background:var(--card);width:100%" data-act="pr-sel" data-key="${r.key}" ${canManage ? '' : 'disabled'}>${r.opts.map(([v, l]) => `<option value="${v}"${draft[r.key] === v ? ' selected' : ''}>${esc(l)}</option>`).join('')}</select>`}<div class="set-meta">${vals[r.key] && vals[r.key] !== 'nc' ? `<span class="chipn">Set: ${esc(pinVal(r, vals[r.key]))}</span>` : `<span class="nc">${ic('settings', 's3')}Not configured</span>`}</div></div></div>`).join('')}
            <div style="display:flex;justify-content:flex-end;margin-top:12px">${canManage ? `<button class="btn btn-primary btn-sm" type="button" data-act="pr-save" ${changed.length ? '' : 'disabled'}>Save rules</button>` : '<p class="text-caption" style="margin:0">Only someone who manages medication settings for all sites can change these rules.</p>'}</div>
            <div style="margin-top:14px"><div class="nh" style="font-size:10px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;color:var(--muted-foreground)">Change history</div><ul class="hist">${S.pinHistory.length ? S.pinHistory.map((h) => `<li><b>${esc(h.when)}</b> · ${esc(h.who)} · ${esc(h.what)}</li>`).join('') : '<li>29 Sep 2026 · Stephan · Method chosen: personal 6-digit witness PIN; login password retired (decision recorded, not yet built)</li>'}</ul></div>
        </section>`;
    }
    function staffPinsCard(canReset) {
        const resetCfg = S.pinRules.resetRoles !== 'nc';
        return `<section class="card" style="overflow:hidden" aria-labelledby="sp-h"><div class="card-pad" style="padding-bottom:6px"><div class="cap-row" style="margin:0"><h3 id="sp-h">Staff witness PINs</h3><span class="text-caption">Status only — nobody can see or set another person’s PIN</span></div></div>
            <div class="tbl-wrap"><table class="etable"><caption class="sr-only">Staff witness PIN status</caption><thead><tr><th scope="col">Person</th><th scope="col">Role</th><th scope="col">House</th><th scope="col">PIN</th><th scope="col"><span class="sr-only">Actions</span></th></tr></thead><tbody>
            ${STAFF_PINS.map((x) => `<tr data-menu="staffpin:${esc(x.name)}"><td><div class="who"><span class="disc sm" aria-hidden="true">${x.name.split(' ').map((n) => n[0]).join('')}</span><div style="font-weight:600">${esc(x.name)}</div></div></td><td>${esc(x.role)}</td><td>${esc(x.house)}</td><td>${pinBadge(x.pin, x.changed)}${x.pin === 'locked' ? '<div class="who-sub">Locked at 8:55 am after wrong attempts</div>' : x.pin === 'notset' ? '<div class="who-sub">Can’t be chosen to co-sign or witness</div>' : ''}</td><td style="text-align:right">${kebab('staffpin', x.name, x.name)}</td></tr>`).join('')}
            </tbody></table></div>
            <div class="card-pad" style="border-top:1px solid var(--border)"><p class="text-caption" style="margin:0">${resetCfg ? 'Resetting a PIN makes its owner choose a new one before they can co-sign or witness again.' : 'Resetting another person’s PIN is off until the organisation chooses who may do it.'}</p></div></section>`;
    }
    function secondPersonSettingsView() {
        const canManage = allSites(S.persona);
        return pinRulesCard(canManage) + staffPinsCard(canManage) + `<div class="annot"><div class="a-tag">${ic('info', 's3')}Design note</div>New settings for the method Stephan chose (option B). Values nobody has approved read “Not configured” and fail closed: no lockout period, no renewal, the forgotten-PIN fallback not allowed, nobody able to reset another person’s PIN. Built in <b>P11</b>; the witness credential for controlled drugs is part of ${dtag('D8')}. ${canManage ? 'You’re the ' + PERSONAS[S.persona].role.toLowerCase() + ', with all-sites authority.' : 'Switch to Clinical lead or Provider manager to edit.'}</div>`;
    }
    function openPinConfirm() {
        const changed = PIN_RULES.filter((r) => String(S.pinDraft[r.key]) !== String(S.pinRules[r.key]));
        openDialog(simpleDialog({
            title: 'Change the second-person confirmation rules?', icon: 'users', desc: 'From the next dose signed or witnessed, at every site:',
            body: `<ul style="margin:0;padding-left:18px;font-size:13px">${changed.map((r) => `<li><b>${esc(r.label)}:</b> ${esc(pinVal(r, S.pinDraft[r.key]))}</li>`).join('')}</ul><p class="text-caption" style="margin:0">Recorded in the change history and the audit log with your name and the time.</p>`,
            foot: `<button class="btn btn-outline" type="button" data-act="close">Cancel</button><button class="btn btn-primary" type="button" data-act="pr-confirm">Save rules</button>`,
        }), 'dlg-simple w480', 'dlg-t', 'dlg-d');
    }
    function openPinReset(name) {
        openDialog(simpleDialog({
            title: `Reset ${name}’s witness PIN?`, icon: 'refresh', desc: 'They won’t be able to co-sign or witness until they choose a new PIN from their own account.',
            body: `<p style="margin:0;font-size:13px">Nobody sees the old or the new PIN. ${esc(name)} gets a message asking them to set a new one in Settings › Witness PIN. The reset is recorded in the audit log.</p>`,
            foot: `<button class="btn btn-outline" type="button" data-act="close">Cancel</button><button class="btn btn-destructive" type="button" data-act="toast-close" data-msg="${esc(name)}’s PIN was reset. They must set a new one before they can co-sign or witness.">Reset PIN</button>`,
        }), 'dlg-simple w480', 'dlg-t', 'dlg-d');
    }

    /* Personal “Witness PIN” page in the person's own account settings (Stephan, 29 Sep 2026: moved out of My HR,
     * next to Password and Two-Factor Authentication in layouts/settings/layout.tsx). */
    function myPinCard(state, staticSpec) {
        const setForm = (title, cta) => `<div class="pin-form" style="margin-top:10px"><div class="field"><label for="mp-new">${title}</label><input id="mp-new" class="pin-input" type="password" inputmode="numeric" maxlength="6" autocomplete="new-password" ${staticSpec ? 'tabindex="-1" readonly' : ''}><span class="who-sub">6 digits. Not your login password. Don’t share it.</span></div><div class="field"><label for="mp-new2">Enter it again</label><input id="mp-new2" class="pin-input" type="password" inputmode="numeric" maxlength="6" autocomplete="new-password" ${staticSpec ? 'tabindex="-1" readonly' : ''}></div></div><div id="mp-err"></div><div style="margin-top:12px"><button class="btn btn-primary btn-sm" type="button" data-act="mp-set" ${staticSpec ? 'tabindex="-1"' : ''}>${cta}</button></div>`;
        const body = state === 'notset' ? B('warning', 'alert-triangle', 'You haven’t set a witness PIN', 'You can’t be chosen to co-sign or witness a dose until you set one.') + setForm('New 6-digit PIN', 'Set PIN')
            : state === 'locked' ? B('critical', 'lock', 'Your PIN is locked', `Too many wrong attempts (limit: ${NC()}). It unlocks after: ${NC()} You can reset it now by confirming your login.`) + `<div style="margin-top:12px"><button class="btn btn-primary btn-sm" type="button" data-act="toast" data-msg="You’d confirm your login password, then choose a new PIN (mockup)." ${staticSpec ? 'tabindex="-1"' : ''}>Reset my PIN</button></div>`
                : state === 'adminreset' ? B('warning', 'refresh', 'Your PIN was reset by Jordan Tipene', 'Reset on 29 September 2026 at 8:30 am. Set a new one before you can co-sign or witness.') + setForm('New 6-digit PIN', 'Set new PIN')
                    : B('success', 'check-circle', 'Your witness PIN is set', 'Last changed 9 September 2026.') + `<div class="pin-form" style="margin-top:10px"><div class="field"><label for="mp-cur">Current PIN</label><input id="mp-cur" class="pin-input" type="password" inputmode="numeric" maxlength="6" ${staticSpec ? 'tabindex="-1" readonly' : ''}><span class="who-sub">Or <button type="button" class="btn-link" data-act="toast" data-msg="You’d confirm your login password instead (mockup)." ${staticSpec ? 'tabindex="-1"' : ''}>confirm your login instead</button></span></div><div></div><div class="field"><label for="mp-new">New 6-digit PIN</label><input id="mp-new" class="pin-input" type="password" inputmode="numeric" maxlength="6" ${staticSpec ? 'tabindex="-1" readonly' : ''}></div><div class="field"><label for="mp-new2">Enter it again</label><input id="mp-new2" class="pin-input" type="password" inputmode="numeric" maxlength="6" ${staticSpec ? 'tabindex="-1" readonly' : ''}></div></div><div id="mp-err"></div><div style="display:flex;gap:10px;align-items:center;margin-top:12px;flex-wrap:wrap"><button class="btn btn-primary btn-sm" type="button" data-act="mp-set" ${staticSpec ? 'tabindex="-1"' : ''}>Change PIN</button><button type="button" class="btn-link" data-act="toast" data-msg="Forgot your PIN: confirm your login password, then choose a new one (mockup)." ${staticSpec ? 'tabindex="-1"' : ''}>Forgot your PIN?</button></div>`;
        return `<section class="card card-pad" aria-labelledby="mp-h" ${staticSpec ? 'inert' : ''}><div class="acct-card-h"><h3 id="mp-h" class="acct-title">Witness PIN</h3><p class="acct-desc">Used when you co-sign, witness or confirm a colleague’s dose. Separate from your password.</p></div>${body}</section>`;
    }
    function myPinPage() {
        const nav = [['General', [['user', 'Profile'], ['palette', 'Appearance'], ['lock', 'Password'], ['shield-check', 'Two-Factor Authentication'], ['key', 'Witness PIN', true]]], ['Notifications', [['bell', 'My Notifications']]]];
        return crumbs([{ label: 'Home', href: '#' }, { label: 'Settings', href: '#' }, { label: 'Witness PIN' }]) + `<div class="acct">
            <aside class="acct-side" aria-label="Settings"><h2 class="acct-h">Settings</h2><nav aria-label="Settings sections">${nav.map(([sec, items]) => `<div><h4 class="acct-sec">${sec}</h4>${items.map(([i, l, on]) => `<a class="acct-item${on ? ' on' : ''}" href="${on ? '#/mypin/' + S.persona : '#'}" ${on ? 'aria-current="page"' : 'data-act="toast-outside"'}>${ic(i, 's35')}<span>${l}</span>${on ? '<span class="badge b-info sm">New</span>' : ''}</a>`).join('')}</div>`).join('')}</nav></aside>
            <div class="acct-main" id="main" tabindex="-1"><div class="acct-inner">${myPinCard(S.myPin)}
                <div class="annot"><div class="a-tag">${ic('info', 's3')}Reference frame — account settings</div>Moved here from My HR (Stephan, 29 Sep 2026): the Witness PIN sits next to Password and Two-Factor Authentication in the account settings (layouts/settings/layout.tsx, section “General”). The settings shell is unchanged; only this page and its menu item are new. The organisation’s PIN rules and staff PIN status stay in Medication › Settings › Second-person confirmation. <label style="font-weight:600;margin-left:6px" for="mp-demo">Mockup state:</label> <select id="mp-demo" data-act="mypin-demo">${[['set', 'PIN set'], ['notset', 'Not set yet'], ['locked', 'Locked'], ['adminreset', 'Reset by an admin']].map(([k, l]) => `<option value="${k}"${S.myPin === k ? ' selected' : ''}>${l}</option>`).join('')}</select></div></div></div></div>`;
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
        ['second', 'Second-person confirmation (PIN)', 'users'],
        ['amount', 'Amount given', 'pill'],
        ['witness', 'Controlled-drug witness & overrides', 'shield'],
        ['allergy', 'Allergy status', 'alert-octagon'],
        ['quality', 'Data quality', 'refresh'],
        ['controlled', 'Controlled-medicine concealment', 'eye-off'],
        ['followups', 'Follow-ups', 'flag'],
        ['touch', 'Touchpoints: tasks, calendar, My Day', 'dashboard'],
        ['identity', 'Person identity header', 'user'],
        ['time', 'Time display rules', 'clock'],
        ['universal', 'Universal interaction states', 'keyboard'],
        ['rules', 'Medication rules (Settings)', 'settings'],
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
            case 'second': return H('Second-person confirmation — personal witness PIN', 'Stephan chose option B on 29 September 2026: a 6-digit witness PIN, separate from the login, for restricted-competency co-signing and controlled-drug witnessing. The login-password method is retired; A and C stay in the table as not chosen. Includes the forgotten-PIN fallback and PIN management.') + secondSection();
            case 'amount': return H('Amount given', 'The actual amount given is recorded, in the order’s units (EM-08). Less than ordered needs a reason, and a second person if someone is available — it never blocks the record. More than ordered is only ever “this already happened”, recorded as a medication error with one linked incident. A different dose needs the prescriber, never a colleague’s PIN.') + reuseBar(R.record, true) + amountCards();
            case 'witness': return H('Controlled-drug witness and overrides', 'A witness is required for controlled drugs by default, set per house, and any order can still require one. Who can witness comes from the roster and clock-ins, witness competency and the witness PIN. When nobody can, a manager grants a time-limited override from one screen; every dose under it is marked and followed up.') + witnessCards();
            case 'rules': return H('Medication rules (Settings)', 'One page for every medication rule: rules for specific medicines as plain sentences with a live preview, the organisation-wide safety rules (allergy, restricted competency, areas, amount), the controlled-drug witness default and a summary of the PIN rules.') + rulesCards();
            case 'allergy': return H('Allergy status', 'Three states always, a fourth only when the organisation approves the vocabulary. The screen never says “no known allergies” because a list came back empty.') + reuseBar(R.allergy) + allergyCards();
            case 'quality': return H('Data quality', 'Missing, failed or not-applicable data never looks like a reassuring zero.') + reuseBar(R.quality) + qualityCards();
            case 'controlled': return H('Controlled-medicine concealment', 'For roles without controlled-medicine access, controlled records leave no trace: no row, no placeholder, no count, no search result, no dead link.') + reuseBar(R.cd) + cdCards();
            case 'followups': return H('Follow-ups', 'Every follow-up has an owner, a due time in NZ time and a state. It survives midnight and shift changes and closes only with a recorded result.') + reuseBar(R.fu) + fuCards();
            case 'touch': return H('Touchpoints: All Tasks, My Calendar, My Day and handover', 'Reference frames only — the pages keep their approved designs. They show how the shared states appear where medication work surfaces outside Meds today, from the same schedule and counts.') + touchSection();
            case 'identity': return H('Person identity header', 'The same header opens every recording dialog: preferred name first, photo only if one is held, house, and the support level for the medicine being recorded.') + reuseBar(R.id) + idCards();
            case 'time': return H('Time display rules', 'All medication times are the house’s local time in Pacific/Auckland, with the zone visible. Daylight saving never shifts a dose silently.') + reuseBar(R.time) + timeSection();
            case 'universal': return H('Universal interaction states', 'States every package mockup must include (plan §7.3), shown here once so they read the same everywhere.') + universalCards();
            case 'decisions': return H('Organisation decisions', 'Values nobody has approved yet display “Not configured” and fail closed. A mockup or code default never becomes policy by being displayed.') + decisionsSection();
            case 'reuse': return H('Reuse by package', 'Which state families each later package must reuse, from plan §7.3. Every package brief must cite this P00 version.') + reuseSection();
            default: return '';
        }
    }

    function introSection() {
        return `<div class="card cat-hero"><h1>Medication rules &amp; states</h1><p>P00 · version 4 · 29 September 2026. The shared state catalogue every medication page reuses, aligned with the P0 fixes, which Stephan accepted and which are live on main (c2838f86a). Design only: no application code, routes, schema or configuration change. Synthetic people and staff; no clinical values are approved by appearing here.</p>
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
            <div class="card card-pad"><div class="cap-row"><h2>What changed from v1</h2><span class="text-caption">P0 fixes accepted and live on main (c2838f86a), including the P00 v2 copy fixes (f5c97b770)</span></div><ul style="margin:0;padding-left:18px;font-size:13px;display:grid;gap:3px">
                <li>Competency: restricted is now an organisation setting — Block (“You can’t sign doses as given”) or Co-signer (“Co-signer required”, with Co-signed by / Co-signer password). Areas are Controlled drugs and Covert administration (failed or not seen); insulin is deferred.</li>
                <li>Allergies: read from the medication allergy list and the health profile. New “Possible allergy match” warning for health-profile matches (no severity), or a block if the organisation chooses.</li>
                <li>Recording: a refused save keeps you on Review; refused offline items show an app-wide banner; new “Not confirmed” state for saves with no answer.</li>
                <li>Counts for leads: “Overdue — not recorded”, “Missed”, “{n} overdue”, “All recorded”, “Given of due”, n/a.</li>
                <li>Settings: “Organisation-wide safety rules” card with a confirm dialog and a “Default — not yet reviewed” marker.</li>
                <li>Every row now has the ⋯ button and a right-click menu (also Shift+F10). Every outcome has an optional “What happened” note.</li>
                <li><b>v3:</b> date and time use the approved PKG-01 control itself (date trigger + calendar, time trigger + clock), copied view-for-view from Fleet and Maintenance.</li>
                <li><b>v3:</b> clicking a row opens it (dose, follow-up, table rows).</li>
                <li><b>v3:</b> second-person confirmation is the personal 6-digit witness PIN Stephan chose, with the forgotten-PIN fallback, PIN rules and staff PIN status in Settings, and a Witness PIN page in My HR.</li>
            </ul></div>
            <div class="card card-pad"><div class="cap-row"><h2>What changed in v4</h2><span class="text-caption">Stephan’s answers of 29 September 2026, relayed by the review session</span></div><ul style="margin:0;padding-left:18px;font-size:13px;display:grid;gap:3px">
                <li><b>Amount given can be adjusted safely</b> (Stephan: “should this be static?”). As ordered by default; less than ordered needs a reason and a second person <i>if someone is available</i> (decided — never blocks, otherwise marked and followed up); more than ordered only as “this already happened”, creating the medication error and one linked incident (approved); “Prescriber asked for a different dose?” is a short in-dialog step, countersigned by a lead next day (approved if easy — authority is a decision). Variable orders are chosen, never defaulted.</li>
                <li><b>Controlled-drug witness:</b> required by default with a per-house setting; the per-medicine witness on the order stays. “No eligible witness” now shows the roster and clock-in evidence. Workers can ask a manager for a time-limited override; managers approve on one prefilled screen, see roster suggestions for single staffing, and can revoke. New view: Safety &amp; oversight › Witness overrides. New persona: Provider manager.</li>
                <li><b>Medication rules</b> replaces “Administration rules”: a plain-language rule builder (WizardShell) with live preview, overlap warnings and change history, beside the allergy, restricted-competency, area and amount rules, the witness default and the PIN summary.</li>
                <li><b>Safety rules show Stephan’s values</b> (29 Sep 2026): restricted competency Block (showing who on shift can give it), areas Block when failed, allergy Warn (whether to adopt mode 3 is still open). The recommendation to move restricted competency to Co-signer with witness PIN once the PIN is built is shown.</li>
                <li><b>Rostered medication work reaches All Tasks and My Calendar</b> (Stephan: “form part of the to do automatically to rostered staff”). Verified not built today. One task per time slot per house for everyone rostered on a covering shift, completing by itself when every dose has an outcome; the same slots appear in My Calendar’s Meds source. Meds today stays a worklist and now states the day in its header.</li>
                <li><b>Witness PIN page moved</b> from My HR to the account settings, next to Password and Two-Factor Authentication.</li>
                <li>The main /dashboard medication widget now uses the same maths (NF-25 fix, commit 4a5f2f238, live on main) — the v3 open item is closed.</li>
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
            { id: 'dose-lead-counts', name: 'Counts for leads (dashboard, client cards, handover)', spec: leadCounts() + leadCounts(true), wording: ['{n} doses due so far today across {houses}, and {n}% of due doses given so far.', '…and no doses due yet.', 'Overdue — not recorded', 'Missed', '{n} overdue', 'All recorded', 'Given of due: n/a'], when: 'Safety & oversight › Overview, client cards, handover and reports.', treatment: 'Counted from the obligation schedule on the NZ day, so the numbers match Meds today (implemented, EM-01 commit 1b9060953). “Overdue — not recorded” is the lead’s total of Late plus Not yet recorded doses; recorded “Missed” is separate. “Given of due” = given ÷ due and reads n/a when nothing is due. A person’s card says “All recorded” only when every due dose has an outcome. The main /dashboard medication widget uses the same maths (NF-25 fix, commit 4a5f2f238, live on main).', never: '0 due while doses are late; 0 % or 100 % when nothing is due; a green “complete” with unrecorded doses.', reuse: ['Safety & oversight · Overview (/emar)', 'Client cards', 'Handover medication lens', 'Reports (P09)'], depends: ['D4'], fixes: ['EM-01', 'EM-18'], link: { href: hrefFrame('lead', 'safety', 'overview'), label: 'See it in Safety & oversight' } },
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
            ['Locked', B('critical', 'lock', 'Daniel Ahn’s witness PIN is locked', `Too many wrong attempts (limit: ${NC()}). Daniel can reset it from their own account — Settings › Witness PIN. Choose another ${cd ? 'witness' : 'co-signer'}.`) + `<div class="ferr" style="margin-top:6px">Before locking: “Incorrect PIN. Repeated wrong attempts lock the PIN.”</div>`],
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
        const table = `<div class="card" style="overflow:hidden"><div class="tbl-wrap"><table class="etable"><caption class="sr-only">Second-person confirmation options</caption><thead><tr><th scope="col">Option</th><th scope="col">How it works</th><th scope="col">Typed on the recorder’s screen</th><th scope="col">Proves presence</th><th scope="col">Status</th></tr></thead><tbody>
            <tr><td><b>Login password</b> (today)</td><td>Colleague types their login password</td><td>Their login password</td><td>Partly</td><td><span class="badge b-neutral sm">Retired</span></td></tr>
            <tr><td><b>A</b> Block and show who can give it</td><td>No second person; the dose goes to an eligible colleague</td><td>Nothing</td><td>—</td><td><span class="badge b-neutral sm">Not chosen</span></td></tr>
            <tr><td><b>B</b> Personal witness PIN</td><td>6-digit PIN, separate from the login, attempt-limited, reset from the person’s own account</td><td>Their PIN</td><td>Yes — at the screen</td><td><span class="badge b-success sm">${ic('check')}Chosen — 29 Sep 2026</span></td></tr>
            <tr><td><b>C</b> Confirm from their own session</td><td>Request, then approve or decline on their device</td><td>Nothing</td><td>Not by itself</td><td><span class="badge b-neutral sm">Not chosen</span></td></tr>
        </tbody></table></div></div>`;
        const W0 = { cosigner: 'Daniel Ahn', cosignPw: '123456', error: null, forgot: false };
        const pickOpen = { cosigner: null, cosignPw: '', error: null, forgot: false, pick: { q: '' } };
        const fbW = { cosigner: 'Mere Kahu', cosignPw: '', error: null, forgot: true };
        const confirmItem = `<div class="card card-pad" style="padding:10px 12px"><div style="font-weight:650;font-size:13px">Priya Shah named you as co-signer — please confirm</div><div class="who-sub">Levetiracetam 500 mg for Tama · 9:12 am · Kōwhai House · you’d forgotten your PIN</div><div class="who-sub" style="margin-top:4px">Answer within the time limit (${NC()}).</div><div style="display:flex;gap:6px;margin-top:8px;flex-wrap:wrap"><button class="btn btn-outline btn-sm" type="button" tabindex="-1">I wasn’t there</button><button class="btn btn-primary btn-sm" type="button" tabindex="-1">I was there</button><button class="btn btn-ghost btn-sm" type="button" tabindex="-1">Reset my PIN</button></div></div>`;
        const card = (o) => stateCard({ wide: true, ...o });
        return table
            + card({ id: 'second-pin-cosign', name: 'Co-signing with a witness PIN (chosen)', spec: stateStrip([
                ['Choosing the colleague', cosignFields({ spec: pickOpen })],
                ['Colleague chosen, PIN typed', cosignFields({ spec: W0 })],
                ['Success', B('success', 'check-circle', 'Co-signed by Daniel Ahn with their witness PIN at 9:12 am', 'The dose record shows “Co-signed by Daniel Ahn (witness PIN)”.')],
                ['Wrong PIN', B('critical', 'x-circle', 'Not recorded — this dose was not saved.', 'Incorrect PIN. Repeated wrong attempts lock the PIN. What you entered is kept.')],
                ['Locked', B('critical', 'lock', 'Daniel Ahn’s PIN is locked', `Too many wrong attempts (limit: ${NC()}). Daniel can reset it from their own account (Settings › Witness PIN). Choose another co-signer, or use the forgotten-PIN fallback if allowed.`)],
                ['Colleague has no PIN', `<div class="picker-opt" aria-disabled="true" style="border:1px dashed var(--border)"><span class="disc sm" aria-hidden="true">MK</span><span><span class="po-n">Mere Kahu</span><span class="po-s">Support worker · on shift now · no PIN set — can’t be chosen until they set one</span></span></div>`],
                ['No eligible colleague', NOBODY('co-sign')],
            ]), wording: ['Co-signed by', 'Choose a colleague on shift', 'Search colleagues on shift…', 'Their 6-digit PIN', 'Their own witness PIN — not their login password. They type it here.', 'Enter their 6-digit PIN.', 'Choose who is co-signing.', 'no PIN set — can’t be chosen until they set one', 'They’ve forgotten their PIN'], when: 'A worker with restricted competency records a dose as given, under the Co-signer rule.', treatment: 'A searchable picker of colleagues on shift now with current, unrestricted competency (people without a PIN are listed but disabled), then a masked 6-digit PIN field. The PIN is checked when the dose is saved; wrong attempts count towards the lock.', reuse: ['RecordDoseWizard', 'PrnWizard', 'GuidedRoundDialog', 'RecordAdministrationDialog', 'Fleet transport dialogs'], depends: ['D3', 'NF-03 setting', 'D8'], fixes: ['NF-03', 'NF-08'], link: { href: hrefFrame('sw', 'today', 'schedule') + '?scenario=restrictedCosigner&open=record:r2:1&outcome=given', label: 'Try it in the recording dialog' } })
            + card({ id: 'second-pin-cd', name: 'Controlled-drug witness with a witness PIN (chosen)', spec: stateStrip([
                ['What the recorder sees', cosignFields({ spec: W0, cd: true })],
                ['Success', B('success', 'check-circle', 'Witnessed by Daniel Ahn with their witness PIN at 12:02 pm', '')],
                ['Locked / wrong PIN / no PIN / nobody eligible', '<span class="who-sub">Same states as co-signing, with “witness” wording.</span>'],
            ]), wording: ['Witnessed by', 'Witness’s 6-digit PIN', 'They type it here, at the medicine cupboard.'], when: 'A controlled medicine that needs a witness.', treatment: 'The witness types their own PIN on the recorder’s screen at the medicine cupboard, which shows they were present. Witness eligibility (a different person, clocked in on a shift covering the house, with witness competency) is unchanged, checked against the roster; a PIN must be set. When nobody is eligible, see Controlled-drug witness & overrides.', reuse: ['RecordCdEntryDialog', 'BalanceCheckDialog (P07a)', 'Recording dialogs (P01)', 'Destructions (P07b)'], depends: ['D8', 'D2'], fixes: ['NF-08', 'EM-03'] })
            + card({ id: 'second-pin-fallback', name: 'Forgotten-PIN fallback', spec: stateStrip([
                ['Fallback used', cosignFields({ spec: fbW }) + `<div style="display:flex;flex-direction:column;gap:4px;margin-top:8px">${dbadge('given')}<span class="state-line" style="color:var(--status-warning);font-weight:600">${ic('alert-triangle', 's3')} Second person not verified — PIN forgotten · waiting for Mere Kahu to confirm</span></div>`],
                ['Colleague’s own login — decided', confirmItem],
                ['Confirmed later', B('success', 'check-circle', 'Mere Kahu confirmed “I was there” at 9:40 am', 'The dose shows “Second person confirmed later (PIN forgotten)” — still distinct from a PIN-verified signature.')],
                ['Disputed — decided', B('critical', 'x-circle', 'Mere Kahu answered “I wasn’t there”', `A follow-up goes to the house lead (who: ${NC()}). The dose stays recorded and marked disputed.`) + `<div class="card" style="overflow:hidden;margin-top:8px">${followUpRow(FU.pinDisputed, 'pinDisputed')}</div>`],
                ['Expired without an answer — decided', B('warning', 'clock', 'No answer from Mere Kahu within the time limit', `Time limit: ${NC()} A follow-up goes to the house lead.`) + `<div class="card" style="overflow:hidden;margin-top:8px">${followUpRow(FU.pinNoAnswer, 'pinNoAnswer')}</div>`],
                ['Not allowed by the organisation', `<label class="tick off"><input type="checkbox" disabled tabindex="-1"> They’ve forgotten their PIN</label><div class="who-sub">Not available: your organisation hasn’t allowed the forgotten-PIN fallback (Settings › Second-person confirmation).</div>`],
            ]), wording: ['They’ve forgotten their PIN', 'Second person not verified — PIN forgotten', 'Priya Shah named you as co-signer — please confirm', 'I was there · I wasn’t there · Reset my PIN', 'Second person confirmed later (PIN forgotten)', 'No answer from {name} within the time limit'], when: 'The chosen colleague is present but can’t remember their PIN, and the organisation allows the fallback (separately for controlled drugs).', treatment: 'Decided by Stephan (29 Sep): the named colleague gets an item in their own login — “I was there” / “I wasn’t there” — and “I wasn’t there” or no answer in time raises a follow-up for the house lead. Proposals for Stephan to judge: the “Second person not verified — PIN forgotten” marking on the dose, in the audit trail and reports (never shown as a PIN-verified signature), and the organisation switches (allowed / not allowed, and a separate switch for controlled drugs). Time limit and routing read “Not configured”. The colleague is prompted to reset their PIN.', never: 'Treating a name picked from a list as a verified signature.', reuse: ['Recording dialogs (P01)', 'Controlled checks (P07a)', 'Follow-ups (P08a)', 'Audit trail and reports (P09)'], depends: ['D8', 'D12', 'NF-03 setting'], fixes: ['NF-08'], link: { href: hrefFrame('sw', 'today', 'schedule') + '?scenario=restrictedCosigner&fallback=yes&open=record:r2:1&outcome=given', label: 'Try the fallback (organisation allows it)' } })
            + card({ id: 'second-pin-settings', name: 'PIN rules in Settings', spec: pinRulesCard(false, true), white: true, wording: ['Second-person confirmation', 'Method: Personal witness PIN · Login password — retired', 'PIN length: 6 digits', 'Not configured', 'Change the second-person confirmation rules?'], when: 'Settings › Second-person confirmation.', treatment: 'PIN length fixed at 6 and shown, not editable. Attempt limit, lockout, renewal, fallback switches, who can reset, confirmation time limit and follow-up routing read “Not configured” until someone with all-sites authority sets them; saving goes through a confirm dialog and the change history.', reuse: ['Settings (P11)'], depends: ['D8', 'D2', 'D12'], fixes: ['NF-08'], link: { href: hrefFrame('clinical', 'settings', 'secondperson'), label: 'Open it as the clinical lead' } })
            + card({ id: 'second-pin-mine', name: 'My witness PIN (account settings)', spec: `<div class="opt-grid two">${['notset', 'set', 'locked', 'adminreset'].map((st) => `<div class="opt"><div class="opt-h">${{ notset: 'Not set yet', set: 'Set', locked: 'Locked', adminreset: 'Reset by an admin' }[st]}</div>${myPinCard(st, true)}</div>`).join('')}</div>`, white: true, wording: ['You haven’t set a witness PIN', 'New 6-digit PIN · Enter it again · Set PIN', 'Your witness PIN is set · Change PIN · Forgot your PIN?', 'Or confirm your login instead', 'Your PIN is locked', 'Your PIN was reset by Jordan Tipene', 'Enter exactly 6 digits.', 'The two PINs don’t match.'], when: 'Settings › Witness PIN in the person’s account settings, next to Password and Two-Factor Authentication (moved from My HR, Stephan 29 Sep 2026); linked from My eligibility and from the forgotten-PIN item.', treatment: 'Set, change (needs the current PIN or a login re-check) and self-reset. Not set, locked and admin-reset states block being chosen as a second person, with a clear prompt.', reuse: ['Account settings (reference frame)', 'My eligibility'], depends: ['D8'], fixes: ['NF-08'], link: { href: '#/mypin/sw', label: 'Open the Witness PIN page' } })
            + card({ id: 'second-pin-admin', name: 'Staff witness PIN status (admin)', spec: staffPinsCard(false), wording: ['Staff witness PINs', 'Status only — nobody can see or set another person’s PIN', 'PIN set · No PIN set · Locked · Reset — must set a new one', 'Reset PIN (they must set a new one)', 'Who can reset PINs isn’t configured'], when: 'Settings › Second-person confirmation.', treatment: 'A list of status only, with the ⋯ and right-click menu. Resetting is allowed only for the roles the organisation chooses, needs a confirm, and forces the owner to set a new PIN. There is no way to see or set a PIN value.', reuse: ['Settings (P11)', 'Staff eligibility (P11)'], depends: ['D2', 'D8'], fixes: ['NF-08'] });
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
            ['Clock format', '“8:05 am” in running text — the app’s formatTime (12-hour, lower-case am/pm, no leading zero). The approved time picker’s own trigger shows “09:12 AM”; that difference already exists in the app. Headers use “Monday 28 September 2026”; dense rows “Mon 28 Sep”; date pickers “28 Sep 2026”. Exports use “28/09/2026 8:05 am NZDT”.'],
            ['Relative time always has a clock time', '“32 min ago (8:40 am)” or “Due 8:00 am · 1 h 12 min ago”. Never a relative time alone.'],
            ['Today means the house’s calendar day', '12:00 am to 11:59 pm local, including the 23-hour and 25-hour days when clocks change.'],
            ['Given time and recorded time', 'Both are kept. Detail views show “Given 8:05 am · recorded 8:40 am” whenever they differ.'],
            ['Across midnight', 'Anything from a previous day shows its weekday: “Due 11:30 pm Sunday”.'],
            ['Elapsed time is real time', 'Intervals (“last dose 6 h ago”) count real hours, so the day the clocks change shows the true gap.'],
            ['Daylight saving start', 'Sunday 27 September 2026: 2:00–2:59 am doesn’t exist. A dose scheduled then says so; how it is rescheduled is D4.'],
            ['Daylight saving end', 'Sunday 4 April 2027: 2:00–2:59 am happens twice. Times in that hour always show the zone: “2:30 am NZDT”, then “2:30 am NZST”.'],
        ];
        return `<div class="rules">${rules.map(([t, d]) => `<div class="card rule"><b>${t}</b>${d}</div>`).join('')}</div>` + [
            { wide: true, id: 'time-entry', name: 'Date and time entry (the approved PKG-01 control)', spec: `<div class="opt-grid"><div class="opt"><div class="opt-h">Closed</div>${dateTimeField('Given', { spec: { date: TODAY, time: { h: 9, m: 12, ap: 'am' }, error: null } })}</div><div class="opt"><div class="opt-h">Time picker</div>${timePopover({ ap: 'am', hText: '09', mText: '12', face: 'minute', manual: false, err: false }, 'Given', 'spec-t', true)}</div><div class="opt"><div class="opt-h">Date picker</div>${datePopover({ draft: TODAY, month: TODAY.slice(0, 7) }, 'Given', true)}</div></div>`, white: true, wording: ['Given · Pacific/Auckland', 'Given date · 28 Sep 2026 · Choose a day on the calendar', 'Given time · 09:12 AM · Choose on the clock or type a time', 'Select a time / Type a time · Hour · Minute · AM · PM · Hours · Minutes', 'Choose an hour, then minutes. You can also type above.', 'Enter an hour from 1 to 12. · Enter minutes from 00 to 59.', 'Type time · Cancel · Use time · Use date', 'The date and time can’t be later than now (Monday 28 September 2026, 9:12 am NZDT).'], when: 'Every recorded time: given or taken, effect check, witness, correction.', treatment: 'The approved composition used in Fleet and Maintenance (DateTimeField, DatePicker, TimePicker), copied view-for-view: a fieldset with a Pacific/Auckland legend, 66px outline triggers with an icon tile, the calendar popover with a summary and Use date, and the clock popover with 83×76 digit boxes, AM/PM buttons, Hours/Minutes tabs, a 256px dial and Type time / Cancel / Use time. Escape closes the popover first; Enter applies typed values; arrow keys adjust; exact minutes are kept. Canonical values stay YYYY-MM-DD and HH:mm.', never: 'A plain text box, a one-off picker style, rounding to five minutes, or a time without its zone.', reuse: R.time, depends: ['D4'], fixes: ['EM-02', 'EM-08'], link: { href: hrefFrame('sw', 'today', 'schedule') + '?open=record:r2:1&outcome=given&tp=clock', label: 'Open it in a recording dialog' } },
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

    /* ───────────── v4 catalogue sections ─────────────
     * Specimens are rendered by the live dialog functions with temporary state (specW / specG / specRW), wrapped in
     * inert, so wording and treatment can't drift from the frame. */
    function specW(state, fn, sOver = {}) {
        const saveW = W, saveS = {};
        Object.keys(sOver).forEach((k) => { saveS[k] = S[k]; S[k] = sOver[k]; });
        if (state) W = { __spec: true, error: null, note: '', amtMode: 'asOrdered', amt: null, amtReason: '', sev: '', imm: '', rx: null, cosigner: null, cosignPw: '', forgot: false, pick: null, date: TODAY, time: { h: 9, m: 12, ap: 'am' }, dp: null, tp: null, isPrn: false, block: null, ...state };
        try { return fn(); } finally { W = saveW; Object.keys(sOver).forEach((k) => { S[k] = saveS[k]; }); }
    }
    const specG = (state, fn) => { const save = G; G = state; try { return fn(); } finally { G = save; } };
    const specRW = (state, fn) => { const save = RW; RW = { id: null, match: 'name', value: '', scope: 'all', countersign: false, obs: [], active: true, step: 0, edit: false, error: null, saved: false, ...state }; try { return fn(); } finally { RW = save; } };
    const inertBox = (h) => `<div inert class="spec-inert">${h}</div>`;
    const NORMAL = () => ({ scenario: 'normal', nobody: false, safety: { ...S.safety, amount: 'avail' } });
    function amountCards() {
        const tama = { r: MEDS.r2, pp: PEOPLE.tama, rowKey: 'r2', outcome: 'given' };
        const prnW = { r: { ...PRN, slot: 'As needed', support: 'administer' }, pp: PEOPLE.aroha, rowKey: 'prn', isPrn: true, outcome: 'given' };
        const af = (st, over = {}) => inertBox(specW(st, amountField, { ...NORMAL(), ...over }));
        const rxState = { ...tama, amtMode: 'prescriber', amt: 2, rx: { who: 'Dr Lena Chen', date: TODAY, time: { h: 9, m: 5, ap: 'am' }, dp: null, tp: null, readBack: true, note: 'Give 2 tablets this morning only, then back to 1.' } };
        const warnLine = (i, t, crit) => `<span class="state-line" style="color:var(--status-${crit ? 'critical' : 'warning'});font-weight:600">${ic(i, 's3')} ${t}</span>`;
        const refs = { err: 'ME-2026-031', inc: 'INC-2026-118' };
        return [
            { id: 'amount-as-ordered', name: 'As ordered (the default)', spec: af({ ...tama, amt: 1 }), white: true, wording: ['Amount given', '1 tablet (500 mg) · as ordered', 'Record a different amount', 'Prescriber asked for a different dose?'], when: 'Every dose recorded as given, taken with prompting or taken with assistance.', treatment: 'Prefilled with the ordered amount, its unit and strength; the actual amount and unit are saved with the dose (EM-08). Two plain links open the other paths. Replaces v3’s read-only “Fixed by the order” (Stephan: “should this be static?”).', never: 'A free-typed amount or unit.', reuse: R.record, depends: ['D4'], fixes: ['EM-08'], link: { href: hrefFrame('sw', 'today', 'schedule') + '?open=record:r2:1&outcome=given', label: 'Open it in the recording dialog' } },
            { id: 'amount-variable', name: 'Variable order — chosen, never defaulted', spec: af({ ...prnW }) + af({ ...prnW, error: 'amt-range' }), white: true, wording: ['Choose the amount', '1 tablet (500 mg) · 2 tablets (1000 mg)', 'The order allows 1 or 2 tablets. Nothing is chosen for you.', 'Choose the amount given.'], when: 'A variable order, for example paracetamol 1–2 tablets as needed.', treatment: 'Choices are limited to the order’s range, in its units with the strength shown. Nothing is preselected. Outside the range needs the prescriber.', never: 'Defaulting to the largest amount, or saving the range as the amount.', reuse: R.record, depends: ['D4'], fixes: ['EM-08', 'NF-09'], link: { href: hrefFrame('sw', 'today', 'schedule') + '?open=prn', label: 'Open the as-needed dialog' } },
            { wide: true, id: 'amount-less', name: 'Less than ordered — decided', spec: stateStrip([
                ['Someone else on shift', af({ ...tama, amtMode: 'less', amt: 0.5, amtReason: 'Only part taken', cosigner: 'Daniel Ahn', cosignPw: '123456' })],
                ['Nobody else on shift', af({ ...tama, amtMode: 'less', amt: 0.5, amtReason: 'Only part taken' }, { nobody: true }) + `<div style="display:flex;flex-direction:column;gap:4px;margin-top:8px">${dbadge('given')}<span class="state-line">Given 9:12 am · Priya S. · ½ tablet (250 mg), less than ordered (only part taken)</span>${warnLine('alert-triangle', 'Different amount not confirmed by a second person · follow-up for the house lead')}</div><div class="card" style="overflow:hidden;margin-top:8px">${followUpRow(FU.amtNc, 'amtNc')}</div>`],
                ['Checks', af({ ...tama, amtMode: 'less', amt: 1, error: 'amt-over' }) + af({ ...tama, amtMode: 'less', amt: 0.5, error: 'amt-reason' })],
            ]), wording: ['Amount given — less than ordered', 'Why was it less? Only part taken · Dropped or spilled · Vomited soon after · Other', 'A colleague on shift confirms the different amount', 'Nobody else on shift to confirm the amount', 'It will still be recorded, marked “Not confirmed by a second person”, and the house lead gets a follow-up. This never stops you recording what happened.', 'Different amount not confirmed by a second person · follow-up for the house lead', 'That isn’t less than the order (1 tablet (500 mg)). If more was given, use “More than ordered was given”.', 'Choose why less was given.', 'Nothing given? Go back and record it as refused or withheld.'], when: 'The person took or kept less than the order, for example only part taken.', treatment: 'Decided by Stephan (29 Sep 2026): a reason is required, and a colleague on shift confirms with their witness PIN if someone is available. If nobody is, the dose is still recorded, marked “Not confirmed by a second person”, with a follow-up for the house lead — it never blocks the record. If a witness or co-signer is already needed, the same person confirms the amount. Units follow the order (½ steps for tablets); zero goes to refused or withheld.', never: 'Blocking the record because nobody else is on shift; a free-typed unit.', reuse: [...R.record, 'Follow-ups (P08a)'], depends: ['D4', 'D12'], fixes: ['EM-08'], link: { href: hrefFrame('sw', 'today', 'schedule') + '?open=record:r2:1&outcome=given&amt=less&why=Only%20part%20taken', label: 'Try it (then set Colleagues on shift to Nobody)' } },
            { wide: true, id: 'amount-more', name: 'More than ordered — “this already happened”', spec: stateStrip([
                ['In the dialog', af({ ...tama, amtMode: 'more', amt: 2, sev: 'major', imm: 'Rang the on-call contact at 9:15 am. Staying with Tama.' })],
                ['After saving', errorCreatedBody('Tama', false, refs)],
                ['On the dose', `<div style="display:flex;flex-direction:column;gap:4px">${dbadge('given')}<span class="state-line">Given 9:12 am · Priya S. · 2 tablets (1000 mg), more than ordered</span>${warnLine('alert-octagon', `More than ordered — medication error ${refs.err} and incident ${refs.inc} created`, true)}</div>`],
            ]), wording: ['More than ordered was given', 'Only record this if it has already happened', 'Amount actually given', 'How serious does it seem? Minor · Moderate · Major · Critical', 'What did you do straight away?', 'What happened (goes into the medication error report)', 'Recorded and reported', 'Medication error ME-2026-031 · Incident INC-2026-118', 'Do this now: contact the prescriber or on-call contact · stay with Tama · the house lead has been told', 'Saving again or trying again reuses this error and incident — never a second one.'], when: 'Only when more than ordered has already been given. It is not offered next to “less”.', treatment: 'Approved by Stephan (29 Sep: “an incident needs to be created”). The chart records what was really given, and saving creates the medication error and one linked incident through the existing link (<code>create_incident</code> sets <code>client_incident_id</code>; linking is idempotent), then shows the references and the escalation step. What happened and the immediate action are required; they become the error’s description and immediate action. For a controlled medicine the incident doesn’t name the medicine to people without controlled-medicine access.', never: 'A normal “more” amount; a colleague’s PIN as authority for a larger dose; a second incident on retry.', reuse: ['Recording dialogs (P01)', 'Medication errors (P08b)', 'Incidents (unchanged)'], depends: ['D12'], fixes: ['EM-08', 'EM-18'], link: { href: hrefFrame('sw', 'today', 'schedule') + '?open=errcreated', label: 'Open the confirmation' } },
            { wide: true, id: 'amount-prescriber', name: 'Prescriber asked for a different dose', spec: stateStrip([
                ['In the dialog', af(rxState)],
                ['Review', inertBox(specW(rxState, () => `<div class="review-card"><h4>${ic('clipboard-check', 's35')}Outcome</h4>${reviewAmountRows()}</div>`, NORMAL()))],
                ['Waiting for a lead', `<div class="card" style="overflow:hidden">${followUpRow(FU.rxPending, 'rxPending')}</div>`],
            ]), wording: ['Prescriber’s instruction for this dose', 'A different dose needs the prescriber — a colleague’s PIN is not authority for it.', 'Prescriber · New dose · Instruction given (date and time)', 'I read the instruction back to the prescriber and they confirmed it', 'What the prescriber said', 'For this dose only — a lead countersigns it', 'Waiting for a lead to countersign', 'Enter who gave the instruction. · Enter the new dose — different from the order. · Read the instruction back to the prescriber, then tick this.'], when: 'The prescriber has told the worker by phone to give a different dose now.', treatment: 'Approved by Stephan if it works as expected and is easy (29 Sep). A short step inside the dialog — prescriber, time (the approved time picker), new dose, read-back and a note — then recording carries on. It applies to this dose only and doesn’t change the order; a lead who can check orders countersigns it next day. <b>Decision for Stephan:</b> support workers can’t create orders today (medications.orders.manage); the proposal lets them record a phone instruction for one dose, countersigned by a lead.', never: 'A colleague’s PIN standing in for the prescriber; silently changing the order.', reuse: ['Recording dialogs (P01)', 'Prescriptions countersign (P04)', 'Follow-ups (P08a)'], depends: ['D2', 'D4'], fixes: ['NF-09', 'EM-08'], link: { href: hrefFrame('sw', 'today', 'schedule') + '?open=record:r2:1&outcome=given&amt=prescriber', label: 'Try it in the recording dialog' } },
        ].map(stateCard).join('');
    }
    function witnessCards() {
        const grace = { r: MEDS.r11, pp: PEOPLE.grace, rowKey: 'r11', outcome: 'given' };
        const gReq = { mode: 'request', house: 'kowhai', person: 'grace', med: 'Clonazepam', start: { date: TODAY, time: { h: 9, m: 14, ap: 'am' }, dp: null, tp: null }, end: { date: TODAY, time: { h: 3, m: 0, ap: 'pm' }, dp: null, tp: null }, reason: 'Nobody else on shift can witness (from the roster)', note: 'Mere is on shift but isn’t witness-trained.', roster: 'now', error: null, declining: false, declineReason: '' };
        const gp = specG(gReq, () => grantParts('pm'));
        const row = (st, extra) => `<div style="display:flex;flex-direction:column;gap:4px">${dbadge(st)}${extra}</div>`;
        const markedRow = specW(null, () => doseRow('r11', 'sw'), { scenario: 'cdOverride', rows: { r11: { state: 'given', line: 'Given 9:14 am · Priya S.', override: { by: 'Rangi Parata', reason: 'Nobody else on shift can witness (from the roster)' } } } });
        return [
            { id: 'witness-pin', name: 'Witness on shift — PIN at the cupboard', spec: inertBox(specW({ ...grace, cosigner: 'Daniel Ahn', cosignPw: '123456' }, () => cosignFields({ cd: true }), { ...NORMAL(), scenario: 'cdWitness' })), white: true, wording: ['Witnessed by', 'Witness’s 6-digit PIN', 'Their own witness PIN — not their login password. They type it here, at the medicine cupboard.', 'Witnessed by Daniel Ahn (witness PIN)'], when: 'A controlled dose recorded as given, with an eligible witness on shift.', treatment: 'The picker lists colleagues clocked in on a shift covering the house now; anyone without witness competency or a PIN is listed but can’t be chosen. The witness types their own PIN at the cupboard. The same person confirms a different amount.', reuse: ['Recording dialogs (P01)', 'Controlled checks (P07a)'], depends: ['D8'], fixes: ['NF-08', 'EM-03'], link: { href: hrefFrame('sw', 'today', 'schedule') + '?scenario=cdWitness&open=record:r11:1&outcome=given', label: 'Try it (scenario: witness on shift)' } },
            { id: 'witness-none', name: 'No eligible witness — from the roster', spec: blockedPanel('noWitness', { p: 'Grace', med: 'Clonazepam', persona: 'sw' }), wording: [stripTags(tpl(BLOCKS.noWitness.text, { p: 'Grace', med: 'Clonazepam' })), 'Roster and clock-ins · Kōwhai House · checked 9:12 am NZDT', 'Mere Kahu · 7:00 am–3:00 pm · clocked in 6:58 am · no witness competency · no witness PIN set', 'Nobody else on shift can witness right now. Next witness-eligible staff member: Jordan Tipene from 3:00 pm.', 'Ask a manager for a witness override'], when: 'A controlled dose is due and nobody meets all the witness checks right now.', treatment: 'Stephan (29 Sep): “no eligible colleague” comes from who is clocked in on a shift covering the house right now, who holds witness competency and who has a PIN set — shown, not just asserted. Refusal, withhold and absence stay recordable. The worker can ask a manager for an override from the dose, the ⋯ menu or “Why can’t I record this?”.', reuse: ['Meds today rows', 'Recording dialogs (P01)', 'Controlled checks (P07a)'], depends: ['D8', 'D12'], fixes: ['EM-03', 'EM-25'], link: { href: hrefFrame('sw', 'today', 'schedule') + '?scenario=cdNoWitness', label: 'Try it (scenario: no eligible witness)' } },
            { wide: true, id: 'witness-request', name: 'Asking a manager for an override', spec: stateStrip([
                ['Asking', inertBox(overrideRequestBody('r11'))],
                ['Waiting', row('due', `<span class="state-line">${ic('send', 's3')} Witness override requested 9:13 am · waiting for a manager</span>`)],
                ['Approved', row('due', `<span class="state-line" style="color:var(--status-warning);font-weight:600">${ic('shield', 's3')} Witness override by Rangi Parata until 3:00 pm — can be recorded without a witness</span>`)],
                ['Declined', row('due', `<span class="state-line" style="color:var(--status-critical);font-weight:600">${ic('x-circle', 's3')} Override declined by Rangi Parata: “Jordan can come in at 10:00 am to witness” · record it as not given, or wait</span>`)],
            ]), wording: ['Ask a manager for a witness override', 'What should it cover? Grace’s clonazepam · All controlled doses at Kōwhai House — until 3:00 pm, the end of your shift', 'Why · Anything the manager should know', 'Who gets it', 'Until a manager answers, don’t give it without a witness. You can record it as not given, or wait.', 'Request sent to managers at 9:13 am. You’ll see their answer on this dose.'], when: 'From a blocked controlled dose when nobody on shift can witness.', treatment: 'Prefilled from the dose and the roster; the window is the end of the worker’s rostered shift. The request carries the roster evidence. The answer comes back on the dose — approved (recordable without a witness) or declined with the manager’s reason.', reuse: ['Meds today rows', 'Recording dialogs (P01)', 'Controlled checks (P07a)'], depends: ['D8', 'D12'], fixes: ['NF-08'], link: { href: hrefFrame('sw', 'today', 'schedule') + '?scenario=cdNoWitness&open=ovrreq:r11', label: 'Open the request' } },
            { wide: true, id: 'witness-approve', name: 'Manager’s approve screen — one screen', spec: `<div class="card card-pad" style="display:flex;flex-direction:column;gap:12px"><div style="font-weight:650">${esc(gp.title)}</div><div class="text-caption">${gp.desc}</div>${inertBox(gp.body)}<div style="display:flex;justify-content:flex-end;gap:8px;flex-wrap:wrap" inert>${gp.foot}</div></div>`, wording: ['Witness override request', 'From Priya Shah at 9:13 am · Grace · clonazepam 9:00 am dose · Kōwhai House', 'House · Only for (optional) · Only this medicine (optional)', 'Starts · Ends — It ends by itself at this time. Every override needs an end.', 'Reason · Note', 'What this does', 'Cancel · Decline · Approve override', 'Tell Priya why, and what to do instead', 'The end must be after the start and later than now (9:12 am).'], when: 'A manager with the witness-override permission opens a request, a roster suggestion, or “Grant an override”.', treatment: 'Stephan (29 Sep): easy for the manager — one screen, prefilled house, window and reason, and approve. The roster evidence sits at the top; the window uses the approved date and time control; declining needs a reason the worker sees. Leads without the permission see it read-only.', reuse: ['Safety & oversight · Witness overrides (P07a)'], depends: ['D8'], fixes: ['NF-08'], link: { href: hrefFrame('pm', 'safety', 'overrides') + '?scenario=cdNoWitness&ovreq=waiting&open=grant:request', label: 'Open it as the provider manager' } },
            { id: 'witness-suggest', name: 'Roster suggestion for managers', spec: suggestionBanner(true), wording: ['Single staffing tonight at Kōwhai House', 'The roster shows 1 staff rostered 10:00 pm–7:00 am (Mere Kahu). Kōwhai House holds controlled drugs and 1 controlled dose falls in that window…', 'This is a suggestion — nothing changes until someone grants it.', 'Review and grant · Open the roster'], when: 'The roster shows single staffing at a house that holds controlled drugs, in a window with a controlled dose due.', treatment: 'A suggestion only, for managers, at the top of Witness overrides; “Review and grant” opens the one-screen form prefilled for that window. Proposed setting (Stephan to confirm).', never: 'Granting anything automatically.', reuse: ['Safety & oversight · Witness overrides (P07a)', 'Rostering (unchanged — read only)'], depends: ['D8'], fixes: [] },
            { wide: true, id: 'witness-states', name: 'Override states', spec: `<div class="card" style="overflow:hidden">${requestRow()}${['wo5', 'wo4', 'wo7', 'wo1', 'wo2', 'wo0'].map((id) => overrideRow(overrideById(id))).join('')}</div>`, wording: ['Waiting for a manager', 'Active · Until 3:00 pm today', 'Ends before shift end · Ends 11:00 am — before this shift ends (3:00 pm)', 'Scheduled · Starts 10:00 pm today', 'Ended · Ended by itself at 7:00 am', 'Revoked · Revoked at 4:10 pm by Rangi Parata: “Second staff member arrived”', 'Declined · Declined at 11:48 am: “Jordan is 10 minutes away — wait for him.”', '{n} doses recorded without a witness'], when: 'Safety & oversight › Witness overrides: requests, active and scheduled overrides, and history.', treatment: 'Each row has View, the ⋯ and right-click menu (Revoke only for people who can grant), and opens on click. Active overrides are amber — a safety exception in force. “Ends before shift end” is derived from the roster, not an invented threshold.', reuse: ['Safety & oversight · Witness overrides (P07a)'], depends: ['D8'], fixes: ['NF-08'], link: { href: hrefFrame('pm', 'safety', 'overrides'), label: 'Open Witness overrides as the provider manager' } },
            { id: 'witness-marked', name: 'Dose recorded under an override', spec: `<div class="card" style="overflow:hidden">${markedRow}</div><div class="card" style="overflow:hidden">${followUpRow(FU.ovDose, 'ovDose')}</div>`, wording: ['No witness — override by Rangi Parata: {reason} · follow-up for the house lead next shift', 'Review a controlled dose given without a witness — Grace', 'Needs review'], when: 'Every controlled dose recorded while an override covers it.', treatment: 'The dose carries the override (who and why) everywhere it appears — row, detail, audit trail, reports — and the house lead gets a follow-up next shift.', never: 'A dose under an override that looks like a witnessed one.', reuse: ['Meds today rows', 'Person record (P02)', 'Follow-ups (P08a)', 'Audit trail (P09)'], depends: ['D8', 'D12'], fixes: ['NF-08'], link: { href: hrefFrame('sw', 'today', 'schedule') + '?scenario=cdOverride', label: 'Try it (scenario: override active)' } },
            { wide: true, id: 'witness-settings', name: 'Witness settings (organisation and houses)', spec: inertBox(cdWitnessCard(false)), white: true, wording: ['Controlled drugs — witness', 'Witness required for controlled drugs: On — witness required', 'Each house: Follow the organisation setting · Always required at this house · Not required at this house', 'Time-limited witness overrides · Who can grant · Longest override: Not configured', 'Suggest an override from the roster: Suggest to managers (proposed) · Don’t suggest', 'Change the controlled-drug witness settings?'], when: 'Settings › Medication rules.', treatment: 'Stephan (29 Sep): witness required by default, with a per-house setting; the per-medicine witness on the order stays as it is. Turning it off anywhere goes through a confirm with a warning. Who can grant overrides is a permission decision: reuse medications.controlled.override (today “override controlled drug discrepancy blocks”) or add a new key — recommended: a new key.', reuse: ['Settings (P11)'], depends: ['D8'], fixes: ['NF-08'], link: { href: hrefFrame('clinical', 'settings', 'rules'), label: 'Open Medication rules as the clinical lead' } },
        ].map(stateCard).join('');
    }
    function rulesCards() {
        const step = (st, p) => inertBox(specRW(st, () => ruleBody(p)));
        return [
            { wide: true, id: 'rules-list', name: 'Rules for specific medicines', spec: inertBox(specW(null, () => medRulesCard(false), { rulesDemo: 'loaded' })), white: true, wording: ['Rules for specific medicines', 'Before saving a dose of insulin glargine at All houses: record blood sugar (BSL).', 'Before saving a dose of any medicine given by subcutaneous injection at Kōwhai House: a second person confirms with their witness PIN.', 'Active · Paused', 'Overlaps with 1 rule — both apply', 'Only someone who manages medication settings for all sites can add or change rules.'], when: 'Settings › Medication rules (the same URL as “Administration rules”).', treatment: 'Each rule reads as a sentence, with a scope chip (all houses or one house), Active or Paused, who changed it and when, Edit (or History when read-only), the ⋯ and right-click menu, and row click. Existing rules keep their meaning: countersign and/or observations, matched by name, route or NZULM code. The change history sits underneath.', reuse: ['Settings (P11)'], depends: ['D2'], fixes: ['EM-17'], link: { href: hrefFrame('clinical', 'settings', 'rules'), label: 'Open it as the clinical lead' } },
            { wide: true, id: 'rules-builder', name: 'Rule builder (WizardShell)', spec: stateStrip([
                ['1 · What it applies to', step({ match: 'cls', value: 'Anticoagulants', scope: 'all', step: 0 }, 'pm')],
                ['2 · What it requires', step({ match: 'cls', value: 'Anticoagulants', scope: 'all', countersign: true, step: 1 }, 'pm')],
                ['2 · Nothing chosen', step({ match: 'cls', value: 'Anticoagulants', scope: 'all', step: 1, error: 'needs' }, 'pm')],
                ['3 · Review, with an overlap', step({ match: 'route', value: 'Subcutaneous injection', scope: 'all', obs: ['bsl'], step: 2 }, 'pm')],
                ['Controlled status, role without controlled access', step({ match: 'controlled', value: 'controlled', scope: 'all', step: 0 }, 'clinical')],
            ]), wording: ['Match medicines by: Medicine name · Route · NZULM code · Type or class (new) · Controlled status (new)', 'Where it applies: All houses · Kōwhai House · Rimu House', 'Before the dose is saved: A second person confirms with their witness PIN · Record pulse · Record blood sugar (BSL) · Record blood pressure', 'Choose at least one: a second person or an observation.', 'Would apply now to {n} medicines for {n} people', 'Overlaps with another rule … Both apply where they overlap: the dose needs everything either rule asks for.', 'Showing medicines your role can see. Totals exclude medicines your role can’t see.', 'Rule added · Applies from the next dose saved.'], when: 'Add a rule, or Edit from a row.', treatment: 'Entity add/edit, so a WizardShell: what it applies to, what it requires, review. The sentence and a live preview of the medicines and people it would affect update as you choose; overlapping rules are named. Type/class matching is new and needs a medicine classification on orders (P04); controlled status uses the order’s controlled flag. The preview respects controlled-medicine concealment. No observation ranges are set here (no invented thresholds).', never: 'Silently saving a rule that requires nothing; showing controlled medicines to a role without access.', reuse: ['Settings (P11)'], depends: ['D2', 'D9'], fixes: ['EM-17', 'EM-12'], link: { href: hrefFrame('clinical', 'settings', 'rules') + '?open=rule:new', label: 'Open the builder' } },
            { id: 'rules-states', name: 'Empty, loading and couldn’t load', spec: stateStrip(['empty', 'loading', 'error'].map((d) => [{ empty: 'No rules yet', loading: 'Loading', error: 'Couldn’t load' }[d], inertBox(specW(null, () => medRulesCard(true), { rulesDemo: d }))])), wording: ['No medicine rules yet', 'Add a rule when a medicine needs a second person or an observation before each dose.', 'Loading medicine rules…', 'Couldn’t load the medicine rules', 'The rules still apply when doses are saved — this page just couldn’t show them.'], when: 'The rules list has none, is loading, or failed to load.', treatment: 'The P00 data-quality states: an error never reads as “no rules”.', reuse: ['Settings (P11)'], depends: [], fixes: ['EM-18'] },
            { id: 'rules-readonly', name: 'Read-only (no all-sites authority)', spec: `<div class="rail-more-pop ctx-menu" role="menu" aria-label="Actions (specimen)" style="position:static" inert>${menuHtml(menuItems('rule', 'mr1', 'lead'), 'spec')}</div>`, wording: ['Edit rule — Only someone who manages medication settings for all sites can change rules', 'Pause rule', 'View change history'], when: 'A house lead (or anyone without all-sites authority) opens Medication rules.', treatment: 'Everything is visible; changing actions stay listed, disabled, with the reason. Pause and resume go through a confirm dialog for those who can.', reuse: ['Settings (P11)'], depends: ['D2'], fixes: [] },
            { wide: true, id: 'rules-safety', name: 'Safety rules alongside (restricted competency, amount)', spec: safetyRulesCard(false, true), white: true, wording: ['Recommended later: Co-signer with witness PIN', 'Stephan set Block on 29 Sep 2026 (the dialog shows who on shift can give it). Review recommendation: move to Co-signer with witness PIN once the PIN is built — never a co-signer by login password.', 'Less than the ordered amount is given', 'Stephan’s decision, 29 Sep 2026'], when: 'Settings › Medication rules.', treatment: 'The allergy rule, restricted competency, the area rule and the amount rule sit on the same page as the medicine rules. Stephan set restricted competency to Block and the area rule to Block when failed (29 Sep 2026); the recommendation to move to Co-signer with witness PIN once the PIN is built is shown beside it. The co-signer never confirms with their login password. The amount rule shows Stephan’s decision.', reuse: ['Settings (P11)'], depends: ['D3', 'NF-03 setting', 'D5'], fixes: ['NF-03'] },
        ].map(stateCard).join('');
    }

    const DECISIONS = [
        ['D1', 'Service classification per site (certified residential / supported living / respite) and which standards apply', 'Provider manager / quality', 'Needs decision', 'No state wording depends on it yet; no regulator is named anywhere.'],
        ['D2', 'Who may prompt, assist, administer, witness, verify, override; relief and agency staff; recording after clock-out; identification method. v4 proposal: a support worker may record a prescriber’s phone instruction for one dose, countersigned by a lead next day (today only medications.orders.manage creates orders)', 'Clinical governance + operations', 'Needs decision', 'Blocked reasons (shift, site, verification, shift ended), override visibility, identity check line, prescriber’s phone instruction.'],
        ['D3', 'Competency model: areas, pass mark, restriction meaning, per-task authority, exemption limits. The NF-03 fix added settings (restricted: Off / Block / Co-signer; controlled-drug and covert areas: Off / failed / failed or not seen), all defaulting to Off. Stephan set restricted = Block and areas = Block when failed (29 Sep 2026). Review recommendation: Co-signer with witness PIN once the PIN is built; never a co-signer by login password.', 'Clinical governance / L&D', 'Partly decided (NF-03 values set)', 'Competency blocks, co-signer, area not passed, exemption ended, My eligibility.'],
        ['D4', 'Timing and amount rules: due window, late/early, time-critical, PRN interval and amount counting, re-offer, DST rescheduling. Decided 29 Sep 2026: a partial dose needs a second person if someone is available and never blocks the record.', 'Prescriber/pharmacist advice + clinical governance', 'Partly decided (partial-dose rule)', 'Not yet due / due / late boundary, not yet recorded, re-offer, PRN limit, DST start, follow-up defaults.'],
        ['D5', 'Allergy source of truth and status vocabulary. The EM-07 fix now reads both the medication allergy list and the health profile; a health-profile match warns by default (setting: Warn / Block).', 'Health & Clinical owner', 'Needs decision', 'All allergy states, health-profile match; “No known drug allergies” hidden until approved.'],
        ['D6', 'Support levels per medicine (independent / prompt / assist / administer), consent, review triggers', 'Care planning + clinical', 'Needs decision', 'Self-managed, taken with prompting/assistance, support chip, support not recorded.'],
        ['D7', 'Viewport and device scope; offline expectation', 'Stephan', 'Decided 28 Sep 2026: desktop web only. Offline expectation still open', 'Queued offline vs recording paused.'],
        ['D8', 'Controlled drugs in supported living: register, count cadence, witness credential, destruction. Stephan chose a personal 6-digit witness PIN and decided (29 Sep 2026): witness required by default with a per-house setting, the per-medicine witness on orders kept, and time-limited overrides that use the roster. Open: the forgotten-PIN fallback for controlled drugs; who grants overrides (reuse medications.controlled.override or a new key — recommended: new); longest override; the roster suggestion.', 'Clinical governance + pharmacy', 'Partly decided (PIN, default, overrides)', 'No eligible witness, witness overrides, controlled checks cadence, witness PIN, CD fallback switch.'],
        ['D9', 'Controlled-medicine need-to-know: roles, aggregates, small-number suppression', 'Privacy officer + clinical governance', 'Needs decision', 'Concealment captions, totals, Tasks/search.'],
        ['D10', 'Stock model: lots, person-owned supply, balance meaning', 'Operations + pharmacy', 'Needs decision', 'Not used in P00 (P06).'],
        ['D11', 'Downtime and paper recording; reconciliation on return', 'Operations + IT', 'Needs decision', 'Print pack link in unavailable, stale and offline states.'],
        ['D12', 'Escalation contacts and acknowledgement (replaces the hard-coded “on-call nurse”); who gets the house-lead follow-ups and by when; who owns a rostered medication task when several staff cover the same house (proposal: everyone rostered sees it; a round’s assignee or a lead narrows it) and whether overdue alerts go to rostered staff and the house lead, not only a round’s assignee', 'Operations', 'Needs decision', '“On-call contact: Not configured”, escalation next contact, coordinator on call, follow-ups for override doses, unconfirmed partial doses and phone instructions.'],
        ['D13*', 'Proposed: covert administration process (listed as organisation policy but not numbered)', 'Clinical governance', 'Proposed — not yet on the decision list', 'Covert plan missing/expired blocks.'],
    ];
    function decisionsSection() {
        return stateCard({ id: 'org-setting-default', name: 'Organisation setting still on its default', spec: specW(null, () => safetyRulesCard(false, true), { safety: { profileAllergy: 'warn', restricted: 'off', area: 'off', amount: 'avail' }, safetyDraft: { profileAllergy: 'warn', restricted: 'off', area: 'off', amount: 'avail' }, safetySetBy: {} }), white: true, wording: ['Default — not yet reviewed', 'Set by {name}, {date} {time}', 'Only someone who manages medication settings for all sites can change these rules.', 'Change the medication safety rules? · From the next dose signed, at every site: …'], when: 'A setting exists (for example the safety rules the NF-03 fix added) but nobody has deliberately chosen a value, so it still carries the code default (the previous behaviour).', treatment: 'The value shows with a dashed “Default — not yet reviewed” chip until someone saves a choice; then “Set by …”. Saving goes through a confirm dialog that states the effect. Read-only for people without all-sites authority.', never: 'A code default presented as approved policy.', reuse: ['Settings · Administration rules (P11)', 'Every future organisation setting'], depends: ['D3', 'D5', 'NF-03 setting'], fixes: ['NF-03', 'EM-17'], link: { href: hrefFrame('clinical', 'settings', 'rules'), label: 'Open Settings as the clinical lead' } })
            + stateCard({ id: 'not-configured', name: 'The “Not configured” pattern', spec: `<div style="display:flex;flex-direction:column;gap:8px"><span>On-call contact: ${NC()}</span><span>Late-dose instruction: ${NC()}</span><span>Support for this medicine: ${NC('Not recorded')}</span></div>`, wording: ['Not configured', 'Not recorded (a missing fact on a record, not a policy)'], when: 'An organisation value nobody has approved, or a record fact that is missing.', treatment: 'Neutral dashed chip with a settings icon. It fails closed: where safety depends on the value, the screen gives no instruction instead of a default. Settings managers will be able to set it once the setting exists (P11); nobody sees a “Set it” button before then.', never: 'A hard-coded default (“on-call nurse”, 95 % target, 120 min, 10/12 pass mark) shown as if approved.', reuse: ['Every package'], depends: [], fixes: ['EM-17'] })
            + `<div class="card" style="overflow:hidden"><div class="tbl-wrap"><table class="etable"><caption class="sr-only">Organisation decisions</caption><thead><tr><th scope="col">#</th><th scope="col">Decision</th><th scope="col">Suggested owner</th><th scope="col">Status</th><th scope="col">P00 states that wait on it</th></tr></thead><tbody>
                ${DECISIONS.map(([d, t, o, s, st]) => `<tr id="dec-${d.replace('*', '')}"><td>${dtag(d)}</td><td>${esc(t)}</td><td>${esc(o)}</td><td class="dec-status">${s.startsWith('Decided') || s.startsWith('Partly') ? `<span class="badge b-success sm">${esc(s)}</span>` : s.startsWith('Proposed') ? `<span class="badge b-info sm">${esc(s)}</span>` : `<span class="badge b-warning sm">${esc(s)}</span>`}</td><td>${esc(st)}</td></tr>`).join('')}
            </tbody></table></div></div>
            <div class="card card-pad"><div class="cap-row"><h3>Other open items reported by the P0 fix session</h3></div><ul style="margin:0;padding-left:18px;font-size:13px"><li>Co-signer picker on the guided round, client-profile MAR, shift card and transport: today those show only the server refusal (P01).</li><li>Enforcing “can administer unsupervised” and the insulin area: deferred (needs a data review and a medicine classification).</li><li>Closed since v3: the main /dashboard medication widget now uses the same maths (NF-25, commit 4a5f2f238, live on main).</li></ul></div>`;
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
            ['Amount given', ['P01', 'P02', 'P08a', 'P08b', 'P04']],
            ['Witness & overrides', ['P01', 'P07a', 'P08a', 'P11']],
            ['Medication rules', ['P11', 'P01', 'P04']],
            ['Time display rules', pk],
            ['Universal interaction states', pk],
        ];
        return `<div class="card" style="overflow:hidden"><div class="tbl-wrap"><table class="etable matrix"><caption class="sr-only">State families by package</caption><thead><tr><th scope="col">State family</th>${pk.map((p) => `<th scope="col">${p}</th>`).join('')}</tr></thead><tbody>${fam.map(([f, ps]) => `<tr><td style="font-weight:600">${f}</td>${pk.map((p) => `<td>${ps.includes(p) ? '<span class="dot-yes" role="img" aria-label="reused"></span>' : '<span class="sr-only">not used</span>'}</td>`).join('')}</tr>`).join('')}</tbody></table></div></div>
            <div class="annot"><div class="a-tag">${ic('info', 's3')}Design note</div>Package order after P00 (plan §7.2): P01 → P02 → P08a → P07a → P03 → P04 → P06 → P07b → P05 → P08b → P11 → P09 → P10. None starts until the P0 fixes are accepted and this exact P00 version is approved.</div>`;
    }

    function cataloguePage() {
        const secs = SECTIONS.map(([id, label, icon]) => `<a href="#/catalogue/${id}" ${S.section === id ? 'aria-current="true"' : ''} data-sec="${id}">${ic(icon, 's35')}${label}</a>`).join('');
        return `<div class="cat">
            <aside class="card cat-index" aria-label="Catalogue sections"><h2>P00 v4 catalogue</h2><nav>${secs}</nav></aside>
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
            if (e.key === 'Escape') { e.preventDefault(); if (W && W.pick && $('.picker-pop')) { W.pick = null; renderWizard(false, '#w-cos'); return; } const oc = openDtCtx(); if (oc) { const wasTp = !!oc.s.tp; oc.s.tp = null; oc.s.dp = null; oc.rr(`#${oc.id}-${wasTp ? 'time' : 'date'}`); return; } if ($('#rail-more-pop')) { closeMore(); return; } closeDialog(); }
            if ((e.key === 'ArrowDown' || e.key === 'ArrowUp') && W && W.pick && e.target.closest && e.target.closest('.picker-pop')) { e.preventDefault(); const opts = $$('.picker-opt'); const i = opts.indexOf(document.activeElement); const n = e.key === 'ArrowDown' ? (i + 1) % opts.length : i <= 0 ? opts.length - 1 : i - 1; if (opts[n]) opts[n].focus(); return; }
            const dc = e.target.closest && e.target.closest('.time-picker-popover') ? dtCtx(e.target) : null;
            if (e.key === 'Enter' && dc && dc.s.tp && e.target.tagName === 'INPUT') { e.preventDefault(); tpApply(dc); return; }
            if ((e.key === 'ArrowUp' || e.key === 'ArrowDown') && dc && dc.s.tp && /-(hour|minute)$/.test(e.target.id)) { e.preventDefault(); const tp = dc.s.tp; const up = e.key === 'ArrowUp' ? 1 : -1; if (e.target.id.endsWith('-hour')) { tp.hText = pad2((((parseInt(tp.hText, 10) || 12) - 1 + up + 12) % 12) + 1); tp.face = 'hour'; } else { tp.mText = pad2(((parseInt(tp.mText, 10) || 0) + up + 60) % 60); tp.face = 'minute'; } tp.err = false; dc.rr('#' + e.target.id); return; }
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
        if (act) primary = act.act === 'go' ? `<a class="btn btn-primary" href="${tpl(act.href, { persona: p })}" data-act="close-nav">${ic(act.icon)}${esc(act.label)}</a>` : `<button class="btn btn-primary" type="button" data-act="${act.act}" data-close="1"${ctx.row ? ` data-row="${ctx.row}"` : ''}>${ic(act.icon)}${esc(act.label)}</button>`;
        if (block === 'noWitness' && S.ovRequest === 'waiting') primary = `<button class="btn btn-primary" type="button" disabled>${ic('send')}Request sent 9:13 am — waiting</button>`;
        if (block === 'noWitness' && !has(p, 'administer')) primary = '';
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
                ['Witness PIN', `${S.myPin === 'set' ? '<span class="badge b-success sm">Set</span> last changed 9 September 2026' : S.myPin === 'notset' ? '<span class="badge b-warning sm">Not set</span> you can’t co-sign or witness until you set one' : S.myPin === 'locked' ? '<span class="badge b-critical sm">Locked</span>' : '<span class="badge b-warning sm">Reset by an admin</span> set a new one'} · <a href="#/mypin/${p}" data-act="close-nav">Manage my witness PIN</a>`],
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

    /* More than ordered — “this already happened” (Stephan: “an incident needs to be created”). Saving creates the
     * medication error and ONE linked incident through the existing link (store(create_incident) sets
     * client_incident_id; linkIncident is idempotent), with the escalation step and controlled-drug concealment. */
    function openErrorCreated(pref, med, amtShort, isCd, refs) {
        openDialog(simpleDialog({
            title: 'Recorded and reported', icon: 'flag', desc: `${esc(med)} for ${esc(pref)}: ${esc(amtShort)} given — more than ordered. The chart shows what was really given.`,
            body: errorCreatedBody(pref, isCd, refs),
            foot: `<button class="btn btn-outline" type="button" data-act="toast" data-msg="The medication error record is designed in P08b (mockup).">Open the error</button><button class="btn btn-outline" type="button" data-act="toast-outside">Open the incident</button><button class="btn btn-primary" type="button" data-act="close" data-autofocus>Done</button>`,
        }), 'dlg-simple', 'dlg-t', 'dlg-d');
    }
    function errorCreatedBody(pref, isCd, refs) {
        return `<div class="created-refs">
                    <div class="cr"><span class="cr-i crit">${ic('alert-octagon')}</span><div><div class="cr-t">Medication error ${refs.err}</div><div class="who-sub">Created and linked to this dose · the house lead reviews the severity</div></div></div>
                    <div class="cr"><span class="cr-i">${ic('alert-triangle')}</span><div><div class="cr-t">Incident ${refs.inc}</div><div class="who-sub">Created and linked to the error — one incident for this dose</div></div></div></div>
                ${B('critical', 'bell', 'Do this now', `<ol style="margin:4px 0 0;padding-left:18px"><li>Contact the prescriber or on-call contact: ${NC()}</li><li>Stay with ${esc(pref)} and watch closely. Add what you see to the incident.</li><li>The house lead has been told (who: ${NC()}).</li></ol>`)}
                <p class="text-caption" style="margin:0">Saving again or trying again reuses this error and incident — never a second one.${isCd ? ' The incident doesn’t name the medicine to people without controlled-medicine access.' : ''}</p>
                <div class="annot" style="padding:8px 10px"><div class="a-tag">Design note</div>Reuses the existing link: the error is saved with <code>create_incident</code>, which sets <code>client_incident_id</code> — the same shape as “Create &amp; link incident”, which is idempotent. References are synthetic. The error record is designed in P08b.</div>`;
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
        W = { amtMode: 'asOrdered', amt: null, amtReason: '', sev: '', imm: '', rx: null, date: TODAY, dp: null, time: { h: 9, m: 12, ap: 'am' }, tp: null, rowKey, r, pp, block, step: opts.notGivenOnly ? 1 : 0, outcome: null, reason: '', note: '', error: null, sending: false, rejectedMsg: null, isPrn, reoffer, notGivenOnly: !!opts.notGivenOnly };
        const shell = `<div class="wiz-grid"><aside class="wiz-rail" aria-label="Steps"></aside><div class="wiz-main"><div class="wiz-head"><span id="dlg-t" tabindex="-1" style="outline:none"></span><button class="d-close wiz-close" type="button" data-act="close" aria-label="Close">${ic('x', 's5')}</button></div><div class="wiz-prog" aria-hidden="true"><i style="width:33%"></i></div><div class="wiz-body" id="wiz-body"></div><div class="wiz-foot" id="wiz-foot"></div></div></div>`;
        openDialog(shell, 'wiz', 'dlg-t');
        const oa = orderAmt(r);
        W.amt = oa.range ? null : oa.n; // a variable order is chosen, never defaulted (EM-08)
        renderWizard(true);
    }
    const GIVEN_LIKE = ['given', 'prompted', 'assisted', 'reoffered'];
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
        const expired = cs === 'expired' || cs === 'restrictedBlock' || (cs === 'restrictedCosigner' && S.nobody);
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
            <dl class="kv"><dt>Instructions</dt><dd>${esc(r.ins)} <span class="who-sub">(from the prescription)</span></dd><dt>Due</dt><dd>${W.isPrn ? 'When needed' : `${esc(r.slot)} · due window: ${NC()}`}</dd><dt>Order</dt><dd>${W.block === 'awaitingVerification' ? '<span class="badge b-warning sm">Waiting to be checked</span>' : 'Verified 3 August 2026 by Jordan Tipene'}</dd>${r.cd ? `<dt>Witness</dt><dd>${cdOverrideFor(W.rowKey) ? `Not needed now — witness override by ${esc(cdOverrideFor(W.rowKey).by)} until ${esc(cdOverrideFor(W.rowKey).endShort)}` : 'Needed — a different person, clocked in on a shift covering this house, with witness competency and a witness PIN'}</dd>` : ''}</dl></div>`;
        const ovr0 = r.cd && !W.isPrn ? cdOverrideFor(W.rowKey) : null;
        const ovStep0 = ovr0 ? B('warning', 'shield', `Witness override by ${esc(ovr0.by)} until ${esc(ovr0.endShort)}`, `${esc(ovr0.reason)}. This dose can be recorded without a witness. It will be marked “No witness — override by ${esc(ovr0.by)}”, and the house lead gets a follow-up next shift.`) : '';
        const ovAsk = W.block === 'noWitness' ? (S.ovRequest === 'waiting' ? B('info', 'send', 'Witness override requested at 9:13 am', 'Waiting for a manager. Until they answer, don’t give it without a witness.') : S.ovRequest === 'declined' ? B('critical', 'x-circle', 'Rangi Parata declined the witness override', `“${esc(S.ovDecline || 'Jordan can come in at 10:00 am to witness')}” Record it as not given, or wait.`) : `<div><button class="btn btn-outline btn-sm" type="button" data-act="ovr-request" data-row="${W.rowKey}" data-fk="ovr-ask-w">${ic('send')}Ask a manager for a witness override</button></div>`) : '';
        const late = !W.isPrn && rowState(W.rowKey) === 'late' && !W.block ? `<div class="banner warning"><span class="b-ico">${ic('alert-triangle')}</span><div class="b-body"><div class="b-title">This dose is late</div><div class="b-text">No late-dose instruction is set for this medicine. Check with the on-call contact before giving it: ${NC()}</div></div></div>` : '';
        const blk = W.block ? blockedPanel(W.block, { p: pp.pref, med: r.med, persona: S.persona }) : '';
        const csW = r.support === 'administer' && !W.block ? compState() : null;
        const expiredB = csW === 'restrictedCosigner' && S.nobody ? NOBODY('co-sign') : csW === 'restrictedCosigner' && false ? B('warning', 'users', 'A colleague needs to give this dose', 'Your medication competency is restricted. On shift now and able to give it: <b>Daniel Ahn</b> (current competency, not restricted). Ask Daniel to give and record it from their own login. You can still record a refusal, withhold or absence.') : csW ? blockedPanel(csW === 'expired' ? 'competencyExpired' : csW, { p: pp.pref, med: r.med, persona: S.persona }) : '';
        const rej = W.rejectedMsg ? `<div class="banner critical" role="alert"><span class="b-ico">${ic('x-circle')}</span><div class="b-body"><div class="b-title">Not recorded — this dose was not saved.</div><div class="b-text">${W.rejectedMsg} The chart doesn’t show this dose. What you entered is kept.</div></div></div>` : '';
        const unc = W.uncertain ? `<div class="banner warning" role="alert"><span class="b-ico">${ic('help')}</span><div class="b-body"><div class="b-title">Not confirmed — check before trying again</div><div class="b-text">We didn’t get confirmation that this was saved, so it isn’t shown as recorded. Check ${esc(pp.pref)}’s chart first. Trying again won’t create a duplicate.</div><div class="b-actions"><a class="btn btn-outline btn-sm" href="#/person/${S.persona}/${pp.id}/chart" data-act="close-nav">${ic('search')}Check the chart</a></div></div></div>` : '';
        const sending = W.sending ? `<div class="banner info" role="status"><span class="b-ico"><span class="ring-spin"></span></span><div class="b-body"><div class="b-title">Sending — not yet confirmed</div><div class="b-text">Don’t close this window. Nothing shows as recorded until it’s confirmed.</div></div></div>` : '';
        if (W.step === 0) {
            const aw = W.rowKey === 'r3' && !W.block ? (S.safety.profileAllergy === 'warn' ? profileMatchWarning(pp.pref, r.med) : S.orderConfirmed ? allergyMatchLine(r.med) + allergyConfirmedNote() : '') : '';
            body.innerHTML = `${idh}${al}${med}${late}${blk}${ovAsk}${ovStep0}${aw}${expiredB}`;
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
                <div class="review-card"><h4>${ic('pill', 's35')}Medicine</h4><div class="rrow"><span>Medicine</span><span>${esc(r.med)} ${esc(r.str)}</span></div><div class="rrow"><span>Ordered</span><span>${esc(r.dose)}</span></div></div>
                <div class="review-card"><h4>${ic('clipboard-check', 's35')}Outcome <button class="btn-link" type="button" data-act="wiz-step" data-step="1" style="margin-left:auto;font-size:12.5px">${ic('pencil', 's3')} Edit</button></h4><div class="rrow"><span>What happened</span><span>${esc(o.l)}</span></div>${W.reason ? `<div class="rrow"><span>Reason</span><span>${esc(W.reason)}</span></div>` : ''}${reviewAmountRows()}<div class="rrow"><span>Time</span><span>${['given', 'prompted', 'assisted', 'reoffered'].includes(W.outcome) ? `${formatDateOnly(W.date)} · ${fmtTime(W.time)}` : `28 Sep 2026 · ${NOW}`} NZDT</span></div>${W.cosigner && secondKind() ? `<div class="rrow"><span>${{ cosign: 'Co-signed by', witness: 'Witnessed by', amount: 'Amount confirmed by' }[secondKind()]}</span><span>${esc(W.cosigner)} ${W.forgot ? '<span class="badge b-warning sm">Not verified — PIN forgotten</span>' : '<span class="badge b-success sm">PIN checked when saved</span>'}${secondKind() !== 'amount' && W.amtMode === 'less' ? ' <span class="who-sub">· also confirms the different amount</span>' : ''}</span></div>` : ''}${W.note ? `<div class="rrow"><span>Note</span><span>${esc(W.note)}</span></div>` : ''}${W.outcome === 'refused' ? `<div class="rrow"><span>Follow-up</span><span>Owner Priya Shah · due 12:00 pm</span></div>` : ''}</div>
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
        if (focusSel) { const el = $(focusSel); if (el) { el.focus(); if (el.select && el.tagName === 'INPUT') el.select(); } placePopovers(); }
        else if (!first) {
            const target = W.error ? $('#wiz-body [aria-invalid="true"], #wiz-body .tile:not([aria-disabled="true"])') : $('#wiz-body .tile:not([aria-disabled="true"]), #wiz-body select, #wiz-foot .btn-primary:not([disabled])');
            if (target) target.focus();
        }
        if (!focusSel) body.scrollTop = 0;
        placePopovers();
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
            const kind = secondKindBase();
            const ovr = W.r.cd && !W.isPrn ? cdOverrideFor(W.rowKey) : null;
            const ovBanner = ovr ? B('warning', 'shield', `No witness needed — override by ${esc(ovr.by)} until ${esc(ovr.endShort)}`, `${esc(ovr.reason)}. The dose will be marked “No witness — override by ${esc(ovr.by)}”, and the house lead gets a follow-up next shift.`) : '';
            const co = ovBanner + (kind ? cosignFields({ cd: kind === 'witness' }) : '');
            const noteReq = W.amtMode === 'more';
            const note = `<div class="field"><label for="w-note">What happened ${noteReq ? '<span class="req">*</span> <span class="who-sub">(goes into the medication error report)</span>' : '<span class="who-sub">(optional note)</span>'}</label><textarea id="w-note" rows="2" data-act="wiz-note" maxlength="1000" placeholder="${noteReq ? 'e.g. Gave 2 tablets instead of 1 — picked up the wrong pack. Noticed when signing.' : 'e.g. Took it with yoghurt. Asked about side effects.'}" ${W.error === 'note-more' ? 'aria-invalid="true" aria-describedby="w-note-e"' : ''}>${esc(W.note)}</textarea>${W.error === 'note-more' ? '<span class="ferr" id="w-note-e">Say what happened — it goes into the medication error report.</span>' : '<span class="who-sub">Saved with this dose and shown in the chart history.</span>'}</div>`;
            return co + dateTimeField(W.outcome === 'given' || W.outcome === 'reoffered' ? 'Given' : 'Taken') + amountField() + note;
        }
        return '';
    }
    /* Date + time entry — the approved PKG-01 DateTimeField / DatePicker / TimePicker composition
     * (resources/js/components/fleet-assets/maintenance/*, POPUP_STYLE_GUIDE 2026-09-20), rebuilt
     * view-for-view: fieldset with a Pacific/Auckland legend, 66px outline triggers with icon tile,
     * date popover (calendar, summary, Use date) and time popover (digits, AM/PM, Hours/Minutes,
     * 256px dial, Type time / Cancel / Use time). Canonical values stay YYYY-MM-DD and HH:mm. */
    const pad2 = (n) => String(n).padStart(2, '0');
    const fmtTime = (t) => `${t.h}:${pad2(t.m)} ${t.ap}`;
    const canon = (t) => `${pad2((t.h % 12) + (t.ap === 'pm' ? 12 : 0))}:${pad2(t.m)}`;
    const displayTime = (t) => `${pad2(t.h)}:${pad2(t.m)} ${t.ap.toUpperCase()}`; // the component's own trigger format
    const MON = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
    const MONTHS_FULL = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];
    const WD_SHORT = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
    const formatDateOnly = (iso) => { const [y, m, d] = iso.split('-').map(Number); return `${d} ${MON[m - 1]} ${y}`; };
    const TODAY = '2026-09-28';

    function timePopover(tp, label, id, staticSpec) {
        const hN = parseInt(tp.hText, 10), mN = parseInt(tp.mText, 10);
        const selected = tp.face === 'hour' ? (hN % 12) : mN / 5;
        const ang = (selected * Math.PI) / 6;
        const hx = 128 + Math.sin(ang) * 96, hy = 128 - Math.cos(ang) * 96;
        const validH = /^\d{1,2}$/.test(tp.hText) && hN >= 1 && hN <= 12, validM = /^\d{1,2}$/.test(tp.mText) && mN >= 0 && mN <= 59;
        const dial = tp.manual ? '' : `<div class="time-picker-face-tabs"><button type="button" class="btn btn-sm ${tp.face === 'hour' ? 'btn-secondary' : 'btn-ghost'}" aria-pressed="${tp.face === 'hour'}" data-act="tp-face" data-face="hour">Hours</button><button type="button" class="btn btn-sm ${tp.face === 'minute' ? 'btn-secondary' : 'btn-ghost'}" aria-pressed="${tp.face === 'minute'}" data-act="tp-face" data-face="minute">Minutes</button></div>
            <div class="time-picker-dial" role="group" aria-label="${tp.face === 'hour' ? 'Choose hour on clock' : 'Choose minutes on clock'}"><svg viewBox="0 0 256 256" aria-hidden="true"><line x1="128" y1="128" x2="${Number.isFinite(hx) ? hx.toFixed(1) : 128}" y2="${Number.isFinite(hy) ? hy.toFixed(1) : 32}"/><circle cx="128" cy="128" r="4"/></svg>
            ${Array.from({ length: 12 }, (_, i) => { const n = tp.face === 'hour' ? i || 12 : i * 5; const on = tp.face === 'hour' ? hN === n : mN === n; return `<button type="button" class="time-picker-mark" data-act="tp-dial" data-val="${n}" style="left:${(50 + Math.sin((i * Math.PI) / 6) * 37.5).toFixed(2)}%;top:${(50 - Math.cos((i * Math.PI) / 6) * 37.5).toFixed(2)}%" aria-label="${tp.face === 'hour' ? 'Hour ' + n : 'Minute ' + pad2(n)}" aria-pressed="${on}">${tp.face === 'hour' ? n : pad2(n)}</button>`; }).join('')}</div>`;
        return `<div class="time-picker-popover dtf-pop dtf-right" role="dialog" aria-label="${esc(label)} time picker" ${staticSpec ? 'inert' : ''}>
            <div class="time-picker-heading"><div><strong>${esc(label)} time</strong><small>Pacific/Auckland</small></div><span class="time-picker-mode-label">${tp.manual ? 'Type a time' : 'Select a time'}</span></div>
            <div class="time-picker-digits">
                <div><label for="${id}-hour">Hour</label><input id="${id}-hour" data-act="tp-h" aria-label="${esc(label)} hour" inputmode="numeric" autocomplete="off" maxlength="2" value="${esc(tp.hText)}" data-active="${tp.face === 'hour'}" ${tp.err && !validH ? 'aria-invalid="true"' : ''} aria-describedby="${tp.err ? id + '-terr' : id + '-help'}"></div>
                <span class="time-picker-colon">:</span>
                <div><label for="${id}-minute">Minute</label><input id="${id}-minute" data-act="tp-m" aria-label="${esc(label)} minute" inputmode="numeric" autocomplete="off" maxlength="2" value="${esc(tp.mText)}" data-active="${tp.face === 'minute'}" ${tp.err && !validM ? 'aria-invalid="true"' : ''} aria-describedby="${tp.err ? id + '-terr' : id + '-help'}"></div>
                <div class="time-picker-period" role="group" aria-label="${esc(label)} AM or PM">${['AM', 'PM'].map((x) => `<button type="button" class="btn btn-sm ${tp.ap === x.toLowerCase() ? 'btn-primary' : 'btn-outline'}" aria-pressed="${tp.ap === x.toLowerCase()}" data-act="tp-ap" data-ap="${x.toLowerCase()}">${x}</button>`).join('')}</div>
            </div>
            ${dial}
            <p class="time-picker-help" id="${id}-help">${tp.manual ? 'Type any minute. Use the arrow keys to adjust.' : tp.face === 'hour' ? 'Choose an hour, then minutes. You can also type above.' : 'Choose a 5-minute mark, or type any minute above.'}</p>
            <p class="sr-only" role="status">${validH && validM ? `Selected ${pad2(hN)}:${pad2(mN)} ${tp.ap.toUpperCase()}` : 'Enter a valid time'}</p>
            ${tp.err ? `<p class="upload-error" id="${id}-terr" role="alert">${!validH ? 'Enter an hour from 1 to 12.' : 'Enter minutes from 00 to 59.'}</p>` : ''}
            <div class="time-picker-footer"><button type="button" class="btn btn-ghost btn-sm" data-act="tp-mode">${ic(tp.manual ? 'clock' : 'keyboard')}${tp.manual ? 'Clock' : 'Type time'}</button><div><button type="button" class="btn btn-ghost btn-sm" data-act="tp-cancel">Cancel</button><button type="button" class="btn btn-primary btn-sm" data-act="tp-apply">${ic('check')}Use time</button></div></div>
        </div>`;
    }
    function datePopover(dp, label, staticSpec) {
        const [y, m] = dp.month.split('-').map(Number);
        const first = new Date(y, m - 1, 1);
        const offset = (first.getDay() + 6) % 7;
        const days = new Date(y, m, 0).getDate();
        const cells = [...Array(offset).fill(null), ...Array.from({ length: days }, (_, i) => i + 1)];
        return `<div class="date-picker-popover dtf-pop" role="dialog" aria-label="${esc(label)} date picker" ${staticSpec ? 'inert' : ''}>
            <div class="time-picker-heading"><div><strong>${esc(label)} date</strong><small>Pacific/Auckland · one date</small></div>${ic('calendar', 's35')}</div>
            <div class="date-time-calendar"><div class="cal-nav"><span class="t">Dates</span><div class="nav"><button type="button" data-act="dp-month" data-d="-1" aria-label="Previous month">${ic('chev-left')}</button><span class="m">${MONTHS_FULL[m - 1]} ${y}</span><button type="button" data-act="dp-month" data-d="1" aria-label="Next month">${ic('chev-right')}</button></div></div>
            <div class="cal-box"><div class="cal-grid">${['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'].map((w) => `<div class="cal-wd">${w}</div>`).join('')}</div>
            <div class="cal-grid">${cells.map((d) => { if (d == null) return '<div style="height:40px"></div>'; const iso = `${y}-${pad2(m)}-${pad2(d)}`; const dow = new Date(y, m - 1, d).getDay(); return `<button type="button" class="cal-day${dow === 0 || dow === 6 ? ' we' : ''}" data-act="dp-day" data-iso="${iso}" aria-pressed="${dp.draft === iso}" aria-label="${WD_SHORT[dow]} ${d} ${MONTHS_FULL[m - 1]} ${y}"><span>${d}</span></button>`; }).join('')}</div></div></div>
            <div class="date-picker-summary" role="status">${ic('calendar', 's5')}<div><strong>${dp.draft ? formatDateOnly(dp.draft) : 'Pick your date'}</strong><small>Select one day, then choose Use date.</small></div></div>
            <div class="time-picker-footer date-picker-footer"><span></span><div><button type="button" class="btn btn-ghost btn-sm" data-act="dp-cancel">Cancel</button><button type="button" class="btn btn-primary btn-sm" data-act="dp-apply" ${dp.draft ? '' : 'disabled'}>${ic('check')}Use date</button></div></div>
        </div>`;
    }
    function dateTimeField(label, opts = {}) {
        const w = opts.spec || opts.state || W;
        const id = opts.id || 'w-dt';
        const err = opts.err != null ? opts.err : w.error === 'time' ? 'The date and time can’t be later than now (Monday 28 September 2026, 9:12 am NZDT).' : '';
        const desc = [`${id}-hint`, err && `${id}-error`].filter(Boolean).join(' ');
        return `<fieldset class="date-time-field" data-dt="${opts.key || 'w'}"><legend>${esc(label)} <span>Pacific/Auckland</span></legend>
            <div class="inline-fields">
                <div class="field dtf-field"><label for="${id}-date">${esc(label)} date</label><button type="button" id="${id}-date" class="btn btn-outline time-picker-trigger" data-act="dp-open" aria-haspopup="dialog" aria-expanded="${!!w.dp}" aria-label="${esc(label)} date: ${formatDateOnly(w.date)}" ${err ? 'aria-invalid="true"' : ''} aria-describedby="${desc}" ${opts.spec ? 'tabindex="-1"' : ''}><span class="time-picker-icon">${ic('calendar')}</span><span><strong>${formatDateOnly(w.date)}</strong><small>Choose a day on the calendar</small></span>${ic('chev-down')}</button>${w.dp ? datePopover(w.dp, label, !!opts.spec) : ''}</div>
                <div class="field dtf-field"><label for="${id}-time">${esc(label)} time</label><button type="button" id="${id}-time" class="btn btn-outline time-picker-trigger" data-act="tp-open" aria-haspopup="dialog" aria-expanded="${!!w.tp}" aria-label="${esc(label)} time: ${displayTime(w.time)}" ${err ? 'aria-invalid="true"' : ''} aria-describedby="${desc}" ${opts.spec ? 'tabindex="-1"' : ''}><span class="time-picker-icon">${ic('clock')}</span><span><strong>${displayTime(w.time)}</strong><small>Choose on the clock or type a time</small></span>${ic('chev-down')}</button>${w.tp ? timePopover(w.tp, label, id, !!opts.spec) : ''}</div>
            </div>
            <p id="${id}-hint" class="muted">${esc(opts.hint || 'Change these if it happened at another time. The exact minute is kept.')}</p>
            ${err ? `<p id="${id}-error" class="upload-error" role="alert">${err}</p>` : ''}
        </fieldset>`;
    }
    function placePopovers() {
        const body = $('#wiz-body');
        $$('.dlg .dtf-pop, .dlg .picker-pop').forEach((pop) => {
            const trig = pop.parentElement.querySelector('.time-picker-trigger, .picker-trigger');
            if (!trig) return;
            const r = trig.getBoundingClientRect();
            const w = pop.offsetWidth, h = pop.offsetHeight;
            const below = window.innerHeight - r.bottom - 8, above = r.top - 8;
            let top = below >= h + 8 ? r.bottom + 8 : above >= h + 8 ? r.top - h - 8 : Math.max(8, window.innerHeight - h - 8);
            let left = pop.classList.contains('dtf-right') ? r.right - w : r.left;
            left = Math.max(8, Math.min(left, window.innerWidth - w - 8));
            pop.style.top = top + 'px'; pop.style.left = left + 'px'; pop.style.right = 'auto';
        });
        if (body && !body.dataset.popwired) { body.dataset.popwired = '1'; body.addEventListener('scroll', placePopovers); }
    }
    window.addEventListener('resize', () => placePopovers());
    /* Date/time contexts: every DateTimeField fieldset carries data-dt, so one set of handlers drives the dose
     * time, the prescriber-instruction time and the override start/end, each with its own state and re-render. */
    const DT_CTX = {
        w: () => W && { s: W, id: 'w-dt', rr: (f) => renderWizard(false, f), clear: () => { if (W.error === 'time') W.error = null; } },
        rx: () => W && W.rx && { s: W.rx, id: 'w-rx', rr: (f) => renderWizard(false, f), clear: () => { if (W.error === 'rx-time') W.error = null; } },
        gs: () => G && { s: G.start, id: 'g-s', rr: (f) => renderGrant(f), clear: () => { if (G.error === 'window') G.error = null; } },
        ge: () => G && { s: G.end, id: 'g-e', rr: (f) => renderGrant(f), clear: () => { if (G.error === 'window') G.error = null; } },
    };
    function dtCtx(el) {
        const fs = el && el.closest ? el.closest('[data-dt]') : null;
        const f = DT_CTX[fs ? fs.dataset.dt : 'w'];
        return f ? f() : null;
    }
    const openDtCtx = () => { const pop = $('.dlg .time-picker-popover, .dlg .date-picker-popover'); return pop ? dtCtx(pop) : null; };
    function closeOtherDt() { Object.values(DT_CTX).forEach((f) => { const c = f(); if (c) { c.s.tp = null; c.s.dp = null; } }); }
    function tpApply(c) {
        const tp = c.s.tp;
        const h = /^\d{1,2}$/.test(tp.hText) ? parseInt(tp.hText, 10) : NaN;
        const m = /^\d{1,2}$/.test(tp.mText) ? parseInt(tp.mText, 10) : NaN;
        if (!(h >= 1 && h <= 12)) { tp.err = true; c.rr(`#${c.id}-hour`); return; }
        if (!(m >= 0 && m <= 59)) { tp.err = true; c.rr(`#${c.id}-minute`); return; }
        c.clear();
        c.s.time = { h, m, ap: tp.ap };
        c.s.tp = null;
        c.rr(`#${c.id}-time`);
    }
    /* Second-person confirmation — Stephan chose option B (29 Sep 2026): a personal 6-digit witness PIN,
     * separate from the login, for restricted-competency co-signing and controlled-drug witnessing. */
    const STAFF_PINS = [
        { name: 'Daniel Ahn', role: 'Support worker', house: 'Kōwhai House', onShift: true, pin: 'set', changed: '3 Sep 2026' },
        { name: 'Jordan Tipene', role: 'House lead', house: 'Kōwhai House', onShift: true, pin: 'set', changed: '12 Aug 2026' },
        { name: 'Mere Kahu', role: 'Support worker', house: 'Kōwhai House', onShift: true, pin: 'notset' },
        { name: 'Priya Shah', role: 'Support worker', house: 'Kōwhai House', onShift: true, pin: 'set', changed: '9 Sep 2026', self: true },
        { name: 'Leilani Faleolo', role: 'Support worker', house: 'Rimu House', onShift: false, pin: 'locked', changed: '1 Sep 2026' },
        { name: 'Hemi Walker', role: 'Support worker', house: 'Rimu House', onShift: false, pin: 'adminreset' },
    ];
    const pinBadge = (st, changed) => st === 'set' ? `<span class="badge b-success sm">${ic('check')}PIN set${changed ? ' · ' + changed : ''}</span>` : st === 'notset' ? `<span class="badge b-warning sm">${ic('alert-triangle')}No PIN set</span>` : st === 'locked' ? `<span class="badge b-critical sm">${ic('lock')}Locked</span>` : `<span class="badge b-warning sm">${ic('refresh')}Reset — must set a new one</span>`;
    const fallbackAllowed = (cd) => (cd ? S.pinRules.fallbackCd : S.pinRules.fallback) === 'yes';
    function needsCosign() { return W && S.scenario === 'restrictedCosigner' && W.r.support === 'administer' && ['given', 'reoffered'].includes(W.outcome); }
    function needsWitness() { return !!(W && !W.isPrn && W.r.cd && ['given', 'reoffered'].includes(W.outcome) && !cdOverrideFor(W.rowKey)); }
    /* One second person covers everything the dose needs (witness, co-sign and a different amount). */
    const secondKindBase = () => (needsWitness() ? 'witness' : needsCosign() ? 'cosign' : null);
    const amountNeedsSecond = () => !!(W && W.amtMode === 'less' && GIVEN_LIKE.includes(W.outcome) && S.safety.amount !== 'no');
    const secondKind = () => secondKindBase() || (amountNeedsSecond() && !S.nobody ? 'amount' : null);

    /* ───────────── v4: amount given ─────────────
     * Stephan (29 Sep): “can this not be adjusted … if different amount a cosigner needs to enter their pin?” With the
     * review corrections Stephan then approved: less than ordered needs a reason and a second person IF someone is
     * available (never blocks — otherwise marked and followed up); more than ordered is never a normal choice, only
     * “this already happened”, which creates the medication error and one linked incident; a different dose needs the
     * prescriber's instruction (short in-dialog step, countersigned by a lead next day), never a colleague's PIN.
     * EM-08: the actual amount and its unit are recorded; a variable order is chosen, never defaulted. */
    function orderAmt(r) {
        const m = /^(\d+(?:\.\d+)?)(?:–(\d+(?:\.\d+)?))?\s+([a-z]+?)s?$/i.exec(r.dose || '1 dose');
        const per = /^(\d+(?:\.\d+)?)\s*mg/.exec(r.str || '');
        return { n: m ? parseFloat(m[2] || m[1]) : 1, min: m ? parseFloat(m[1]) : 1, unit: m ? m[3] : 'dose', per: per ? parseFloat(per[1]) : null, range: r.range || null };
    }
    function fmtAmt(n, o) {
        if (n == null || !Number.isFinite(n)) return '—';
        const whole = Math.floor(n), half = Math.abs(n - whole - 0.5) < 1e-9;
        const num = half ? (whole ? `${whole}½` : '½') : String(n);
        return `${num} ${n <= 1 ? o.unit : o.unit + 's'}${o.per ? ` (${+(n * o.per).toFixed(2)} mg)` : ''}`;
    }
    const amtStep = (o) => (o.unit === 'tablet' ? 0.5 : 1);
    const AMT_REASONS = ['Only part taken', 'Dropped or spilled', 'Vomited soon after', 'Other'];
    const ferr = (id, t) => `<span class="ferr" id="${id}">${t}</span>`;
    function amtStepper(o, invalid, label) {
        return `<div class="amt-step"><button type="button" class="btn btn-outline btn-sm" data-act="amt-dec" aria-label="Less" aria-controls="w-amt">−</button><input id="w-amt" inputmode="decimal" autocomplete="off" data-act="amt-in" value="${W.amt == null ? '' : W.amt}" ${invalid ? 'aria-invalid="true" aria-describedby="w-amt-e"' : ''} aria-label="${esc(label)}, in ${o.unit}s"><button type="button" class="btn btn-outline btn-sm" data-act="amt-inc" aria-label="More" aria-controls="w-amt">+</button><span class="amt-unit">${W.amt != null && W.amt <= 1 ? o.unit : o.unit + 's'}</span></div>`;
    }
    function amountField() {
        const o = orderAmt(W.r);
        const diff = `<div class="amt-links"><button type="button" class="btn-link" data-act="amt-mode" data-m="less">Record a different amount</button><button type="button" class="btn-link" data-act="amt-mode" data-m="prescriber">Prescriber asked for a different dose?</button></div>`;
        const back = (l) => `<button type="button" class="btn-link" data-act="amt-mode" data-m="asOrdered">${l}</button>`;
        const e = W.error;
        if (W.amtMode === 'prescriber') {
            const rx = W.rx;
            return `<div class="amt-box" role="group" aria-labelledby="rx-l"><div class="flabel" id="rx-l">${ic('file-text', 's35')} Prescriber’s instruction for this dose</div>
                <p class="text-caption" style="margin:0">A different dose needs the prescriber — a colleague’s PIN is not authority for it. Record what the prescriber told you by phone, then carry on recording.</p>
                <div class="fgrid"><div class="field"><label for="w-rx-who">Prescriber <span class="req">*</span></label><input id="w-rx-who" data-act="rx-who" value="${esc(rx.who)}" placeholder="e.g. Dr Lena Chen" autocomplete="off" ${e === 'rx-who' ? 'aria-invalid="true" aria-describedby="w-rx-who-e"' : ''}>${e === 'rx-who' ? ferr('w-rx-who-e', 'Enter who gave the instruction.') : '<span class="who-sub">The prescriber on the order is Dr Lena Chen.</span>'}</div>
                    <div class="field"><label for="w-amt">New dose <span class="req">*</span></label>${amtStepper(o, e === 'rx-dose', 'New dose')}<span class="who-sub">${fmtAmt(W.amt, o)} · the order is ${fmtAmt(o.n, o)}</span>${e === 'rx-dose' ? ferr('w-amt-e', 'Enter the new dose — different from the order.') : ''}</div></div>
                ${dateTimeField('Instruction given', { state: rx, key: 'rx', id: 'w-rx', hint: 'When the prescriber told you. The exact minute is kept.', err: e === 'rx-time' ? 'The time can’t be later than now (Monday 28 September 2026, 9:12 am NZDT).' : '' })}
                <div class="field"><label class="tick"><input type="checkbox" data-act="rx-rb" ${rx.readBack ? 'checked' : ''} ${e === 'rx-rb' ? 'aria-invalid="true" aria-describedby="w-rx-rb-e"' : ''}> I read the instruction back to the prescriber and they confirmed it <span class="req">*</span></label>${e === 'rx-rb' ? ferr('w-rx-rb-e', 'Read the instruction back to the prescriber, then tick this.') : ''}</div>
                <div class="field"><label for="w-rx-note">What the prescriber said <span class="who-sub">(optional)</span></label><textarea id="w-rx-note" rows="2" data-act="rx-note" placeholder="e.g. Give 2 tablets this morning only, then back to 1.">${esc(rx.note)}</textarea></div>
                ${B('info', 'file-text', 'For this dose only — a lead countersigns it', `It doesn’t change the order. A lead who can check orders countersigns it the next day (who and by when: ${NC()}). If the change is ongoing, the order is changed in Prescriptions.`)}
                <div class="annot" style="padding:6px 10px;font-size:11.5px"><div class="a-tag">Decision for Stephan</div>Support workers can’t create orders today (medications.orders.manage). Proposal: a support worker records the prescriber’s phone instruction for this dose only, and a lead countersigns it next day.</div>
                <div class="amt-links">${back('Back to the ordered amount')}</div></div>`;
        }
        if (W.amtMode === 'more') {
            return `<div class="amt-box crit" role="group" aria-labelledby="more-l"><div class="flabel" id="more-l">${ic('alert-octagon', 's35')} More than ordered was given</div>
                ${B('critical', 'alert-octagon', 'Only record this if it has already happened', 'The chart will show what was really given. Saving also reports a medication error and creates one linked incident. A colleague’s PIN is not authority for a larger dose.')}
                <div class="fgrid"><div class="field"><label for="w-amt">Amount actually given <span class="req">*</span></label>${amtStepper(o, e === 'amt-more', 'Amount actually given')}<span class="who-sub">The order is ${fmtAmt(o.n, o)}.${W.amt > o.n ? ` Recording ${fmtAmt(W.amt, o)}.` : ''}</span>${e === 'amt-more' ? ferr('w-amt-e', `Enter the amount actually given — more than ${fmtAmt(o.n, o)}.`) : ''}</div>
                    <div class="field"><label for="w-sev">How serious does it seem? <span class="req">*</span></label><select id="w-sev" data-act="amt-sev" ${e === 'amt-sev' ? 'aria-invalid="true" aria-describedby="w-sev-e"' : ''}><option value="">Choose</option>${[['minor', 'Minor'], ['moderate', 'Moderate'], ['major', 'Major'], ['critical', 'Critical']].map(([k, l]) => `<option value="${k}"${W.sev === k ? ' selected' : ''}>${l}</option>`).join('')}</select>${e === 'amt-sev' ? ferr('w-sev-e', 'Choose how serious it seems.') : '<span class="who-sub">Your first view — the house lead reviews it.</span>'}</div></div>
                <div class="field"><label for="w-imm">What did you do straight away? <span class="req">*</span></label><textarea id="w-imm" rows="2" data-act="amt-imm" placeholder="e.g. Rang the on-call contact at 9:15 am. Staying with ${esc(W.pp.pref)}." ${e === 'amt-imm' ? 'aria-invalid="true" aria-describedby="w-imm-e"' : ''}>${esc(W.imm)}</textarea>${e === 'amt-imm' ? ferr('w-imm-e', 'Say what you did straight away.') : ''}</div>
                <div class="esc-steps" role="group" aria-label="What to do now"><div class="flabel">Now</div><ol><li>Contact the prescriber or on-call contact: ${NC()}</li><li>Stay with ${esc(W.pp.pref)} and watch closely. Note what you see.</li><li>The house lead is told when you save (who: ${NC()}).</li></ol></div>
                <div class="amt-links">${back('Back to the ordered amount')}</div></div>`;
        }
        if (W.amtMode === 'less') {
            const kind = secondKindBase();
            const rule = S.safety.amount;
            const minOrd = o.range ? o.range[0] : o.n;
            let second = '';
            if (kind) second = `<p class="text-caption" style="margin:0">The ${kind === 'witness' ? 'witness' : 'co-signer'} above also confirms the different amount.</p>`;
            else if (rule !== 'no') second = S.nobody ? B('warning', 'users', 'Nobody else on shift to confirm the amount', `It will still be recorded, marked “Not confirmed by a second person”, and ${rule === 'always' ? 'the house lead must confirm it afterwards' : 'the house lead gets a follow-up'} (who: ${NC()}). This never stops you recording what happened.`)
                : `<div class="amt-sp"><div class="flabel" style="margin-bottom:6px">A colleague on shift confirms the different amount</div>${cosignFields({ amount: true })}</div>`;
            return `<div class="amt-box" role="group" aria-labelledby="less-l"><div class="flabel" id="less-l">Amount given — less than ordered</div>
                <div class="fgrid"><div class="field"><label for="w-amt">Amount given <span class="req">*</span></label>${amtStepper(o, e === 'amt-over' || e === 'amt-zero', 'Amount given')}<span class="who-sub">${fmtAmt(W.amt, o)} · the order is ${o.range ? `${o.range[0]}–${o.range[1]} ${o.unit}s` : fmtAmt(o.n, o)}. Units follow the order.</span>${e === 'amt-over' ? ferr('w-amt-e', `That isn’t less than the order (${fmtAmt(minOrd, o)}). If more was given, use “More than ordered was given”.`) : e === 'amt-zero' ? ferr('w-amt-e', 'Nothing given? Go back and record it as refused or withheld.') : ''}</div>
                    <div class="field"><label for="w-amt-r">Why was it less? <span class="req">*</span></label><select id="w-amt-r" data-act="amt-reason" ${e === 'amt-reason' ? 'aria-invalid="true" aria-describedby="w-amt-r-e"' : ''}><option value="">Choose a reason</option>${AMT_REASONS.map((x) => `<option${W.amtReason === x ? ' selected' : ''}>${x}</option>`).join('')}</select>${e === 'amt-reason' ? ferr('w-amt-r-e', 'Choose why less was given.') : ''}</div></div>
                ${second}
                <div class="amt-links">${back('Use the ordered amount')}<button type="button" class="btn-link amt-more" data-act="amt-mode" data-m="more">More than ordered was given</button></div></div>`;
        }
        if (o.range) {
            return `<div class="field"><label for="w-amt-sel">Amount given <span class="req">*</span></label><select id="w-amt-sel" data-act="amt-range" ${e === 'amt-range' ? 'aria-invalid="true" aria-describedby="w-amt-sel-e"' : ''}><option value="">Choose the amount</option>${Array.from({ length: o.range[1] - o.range[0] + 1 }, (_, i) => o.range[0] + i).map((n) => `<option value="${n}"${W.amt === n ? ' selected' : ''}>${fmtAmt(n, o)}</option>`).join('')}</select>${e === 'amt-range' ? ferr('w-amt-sel-e', 'Choose the amount given.') : `<span class="who-sub">The order allows ${o.range[0]} or ${o.range[1]} ${o.unit}s. Nothing is chosen for you.</span>`}${diff}</div>`;
        }
        return `<div class="field"><span class="flabel" id="amt-l">Amount given</span><div class="amt-fixed" role="group" aria-labelledby="amt-l">${ic('check', 's35')}<b>${fmtAmt(o.n, o)}</b><span class="who-sub">as ordered</span></div>${diff}</div>`;
    }
    function reviewAmountRows() {
        if (!GIVEN_LIKE.includes(W.outcome)) return '';
        const o = orderAmt(W.r);
        const tag = W.amtMode === 'less' ? ` <span class="badge b-warning sm">Less than ordered</span> ${esc(W.amtReason)}` : W.amtMode === 'more' ? ' <span class="badge b-critical sm">More than ordered — medication error</span>' : W.amtMode === 'prescriber' ? ' <span class="badge b-warning sm">Prescriber’s instruction</span>' : o.range ? ' · chosen within the order' : ' · as ordered';
        let rows = `<div class="rrow"><span>Amount given</span><span>${fmtAmt(W.amt, o)}${tag}</span></div>`;
        if (W.amtMode === 'prescriber') rows += `<div class="rrow"><span>Prescriber’s instruction</span><span>${esc(W.rx.who)} by phone · ${fmtTime(W.rx.time)} · read back ✓${W.rx.note ? ` · “${esc(W.rx.note)}”` : ''}<br><span class="badge b-warning sm">Waiting for a lead to countersign</span></span></div>`;
        if (W.amtMode === 'more') rows += `<div class="rrow"><span>When you save</span><span>A medication error (severity: ${esc(W.sev)}) and one linked incident are created. Immediate action: ${esc(W.imm)}</span></div>`;
        if (amountNeedsSecond() && S.nobody && !secondKindBase()) rows += `<div class="rrow"><span>Second person</span><span><span class="badge b-warning sm">Not confirmed by a second person</span> nobody else on shift · the house lead gets a follow-up</span></div>`;
        const ovr = W.r.cd && !W.isPrn ? cdOverrideFor(W.rowKey) : null;
        if (ovr) rows += `<div class="rrow"><span>Witness</span><span><span class="badge b-warning sm">No witness — override</span> by ${esc(ovr.by)}: ${esc(ovr.reason)}</span></div>`;
        return rows;
    }
    /* Searchable colleague picker (POPUP_STYLE_GUIDE "Searchable record selectors"): on-shift colleagues only;
     * people without a PIN are listed but can't be chosen. */
    function colleaguePicker(label, id, opts = {}) {
        const w = opts.spec || W;
        const list = STAFF_PINS.filter((x) => x.onShift && !x.self);
        const q = (w.pick && w.pick.q || '').toLowerCase();
        const shown = list.filter((x) => x.name.toLowerCase().includes(q));
        const fallback = !!w.forgot;
        const pop = w.pick ? `<div class="picker-pop" role="dialog" aria-label="Choose ${esc(label.toLowerCase())}" ${opts.spec ? 'inert' : ''}><label class="sr-only" for="${id}-q">Search colleagues on shift</label><input id="${id}-q" class="picker-q" data-act="pick-q" placeholder="Search colleagues on shift…" value="${esc(w.pick.q || '')}" autocomplete="off">
            <ul class="picker-list" role="listbox" aria-label="Colleagues on shift at Kōwhai House">${shown.length ? shown.map((x) => { const ok = x.pin === 'set' || fallback; return `<li><button type="button" role="option" class="picker-opt" data-act="pick-opt" data-name="${esc(x.name)}" aria-selected="${w.cosigner === x.name}" ${ok ? '' : 'aria-disabled="true"'}><span class="disc sm" aria-hidden="true">${x.name.split(' ').map((n) => n[0]).join('')}</span><span><span class="po-n">${esc(x.name)}</span><span class="po-s">${esc(x.role)} · on shift now · ${x.pin === 'set' ? 'PIN set' : 'no PIN set' + (fallback ? ' — the forgotten-PIN fallback still applies' : ' — can’t be chosen until they set one')}</span></span></button></li>`; }).join('') : '<li class="po-empty">No colleague on shift matches.</li>'}</ul>
            <div class="po-foot">Only colleagues on shift at Kōwhai House now with current, unrestricted competency are listed.</div></div>` : '';
        return `<div class="field picker"><span class="flabel" id="${id}-l">${esc(label)} <span class="req">*</span></span><button type="button" id="${id}" class="picker-trigger" data-act="pick-open" aria-haspopup="dialog" aria-expanded="${!!w.pick}" aria-labelledby="${id}-l ${id}-v" ${w.error === 'cosign' ? 'aria-invalid="true" aria-describedby="' + id + '-e"' : ''} ${opts.spec ? 'tabindex="-1"' : ''}>${ic('search')}<span id="${id}-v">${w.cosigner ? esc(w.cosigner) : 'Choose a colleague on shift'}</span>${ic('chev-down')}</button>${w.error === 'cosign' ? `<span class="ferr" id="${id}-e">Choose who is ${esc(label.toLowerCase().includes('witness') ? 'witnessing' : label.toLowerCase().includes('confirmed') ? 'confirming' : 'co-signing')}.</span>` : ''}${pop}</div>`;
    }
    function fallbackPanel(name, cd) {
        return B('warning', 'alert-triangle', 'Second person not verified — PIN forgotten', `This dose will be marked as not verified. <b>${esc(name || 'The colleague')}</b> gets an item in their own login to confirm “I was there” or “I wasn’t there”, within the time limit: ${NC()} If they answer “I wasn’t there”, or don’t answer in time, the house lead gets a follow-up (who: ${NC()}). They’ll also be asked to reset their PIN.${cd ? ' For controlled drugs this needs its own organisation setting (D8).' : ''}`);
    }
    function cosignFields(opts = {}) {
        if (!opts.spec && W && W.__spec) opts = { ...opts, spec: W };
        const w = opts.spec || W;
        const cd = !!opts.cd;
        if (!opts.spec && S.nobody) return NOBODY(cd ? 'witness this dose' : 'co-sign');
        const who = cd ? 'Witnessed by' : opts.amount ? 'Confirmed by' : 'Co-signed by';
        const allowed = fallbackAllowed(cd);
        const picker = colleaguePicker(who, 'w-cos', opts);
        const pin = w.forgot ? '' : `<div class="field"><label for="w-cpw">${cd ? 'Witness’s' : 'Their'} 6-digit PIN <span class="req">*</span></label><input id="w-cpw" class="pin-input" type="password" inputmode="numeric" maxlength="6" pattern="[0-9]{6}" data-act="wiz-cpw" value="${esc(w.cosignPw || '')}" autocomplete="off" ${w.error === 'cpw' ? 'aria-invalid="true" aria-describedby="w-cpw-e"' : 'aria-describedby="w-cpw-h"'} ${opts.spec ? 'tabindex="-1" readonly' : ''}>${w.error === 'cpw' ? '<span class="ferr" id="w-cpw-e">Enter their 6-digit PIN.</span>' : `<span class="who-sub" id="w-cpw-h">Their own witness PIN — not their login password. They type it here${cd ? ', at the medicine cupboard' : ''}.</span>`}</div>`;
        const tick = `<div class="field"><label class="tick${allowed ? '' : ' off'}"><input type="checkbox" data-act="pin-forgot" ${w.forgot ? 'checked' : ''} ${allowed && !opts.spec ? '' : 'disabled'} aria-describedby="w-forgot-h"> They’ve forgotten their PIN</label><span class="who-sub" id="w-forgot-h">${allowed ? 'Switches to naming them from the list. The dose is then marked “second person not verified”.' : `Not available: your organisation hasn’t allowed the forgotten-PIN fallback${cd ? ' for controlled drugs' : ''} (Settings › Second-person confirmation).`}</span></div>`;
        return `<div class="fgrid">${picker}${pin}</div>${tick}${w.forgot ? fallbackPanel(w.cosigner, cd) : ''}${!opts.spec ? '<div class="annot" style="padding:6px 10px;font-size:11.5px"><div class="a-tag">Mockup</div>PIN 000000 shows a wrong PIN; 999999 shows a locked PIN; any other 6 digits succeed.</div>' : ''}`;
    }
    function wizNext() {
        if (W.step === 1) {
            if (!W.outcome) { W.error = 'outcome'; renderWizard(); return; }
            if ((W.outcome === 'withheld' || W.outcome === 'away') && !W.reason) { W.error = 'reason'; renderWizard(); $('#w-reason') && $('#w-reason').focus(); return; }
            if (['given', 'prompted', 'assisted', 'reoffered'].includes(W.outcome) && (W.date > TODAY || (W.date === TODAY && canon(W.time) > '09:12'))) { W.error = 'time'; renderWizard(false, '#w-dt-time'); return; }
            const sk = secondKind();
            if (sk && sk !== 'amount' && !W.cosigner) { W.error = 'cosign'; renderWizard(false, '#w-cos'); return; }
            if (sk && sk !== 'amount' && !W.forgot && !/^\d{6}$/.test(W.cosignPw || '')) { W.error = 'cpw'; renderWizard(false, '#w-cpw'); return; }
            if (GIVEN_LIKE.includes(W.outcome)) {
                const o = orderAmt(W.r), minOrd = o.range ? o.range[0] : o.n;
                const stop = (err, sel) => { W.error = err; renderWizard(false, sel); };
                if (W.amtMode === 'asOrdered' && o.range && W.amt == null) return stop('amt-range', '#w-amt-sel');
                if (W.amtMode === 'less') {
                    if (!(W.amt > 0)) return stop('amt-zero', '#w-amt');
                    if (W.amt >= minOrd) return stop('amt-over', '#w-amt');
                    if (!W.amtReason) return stop('amt-reason', '#w-amt-r');
                }
                if (W.amtMode === 'more') {
                    if (!(W.amt > o.n)) return stop('amt-more', '#w-amt');
                    if (!W.sev) return stop('amt-sev', '#w-sev');
                    if (!W.imm.trim()) return stop('amt-imm', '#w-imm');
                    if (!W.note.trim()) return stop('note-more', '#w-note');
                }
                if (W.amtMode === 'prescriber') {
                    if (!W.rx.who.trim()) return stop('rx-who', '#w-rx-who');
                    if (!(W.amt > 0) || W.amt === o.n) return stop('rx-dose', '#w-amt');
                    if (W.rx.date > TODAY || (W.rx.date === TODAY && canon(W.rx.time) > '09:12')) return stop('rx-time', '#w-rx-time');
                    if (!W.rx.readBack) return stop('rx-rb', '[data-act="rx-rb"]');
                }
                if (sk === 'amount' && !W.cosigner) return stop('cosign', '#w-cos');
                if (sk === 'amount' && !W.forgot && !/^\d{6}$/.test(W.cosignPw || '')) return stop('cpw', '#w-cpw');
            }
        }
        W.error = null; W.step = Math.min(2, W.step + 1); renderWizard();
    }
    function wizSubmit() {
        W.sending = true; W.rejectedMsg = null; renderWizard();
        setTimeout(() => {
            if (!W) return;
            const lbl = outcomeOptions().find((x) => x.k === W.outcome);
            const sk = secondKind();
            if (sk && !W.forgot && W.cosignPw === '000000') { W.sending = false; W.rejectedMsg = 'Incorrect PIN. Repeated wrong attempts lock the PIN.'; renderWizard(); return; }
            if (sk && !W.forgot && W.cosignPw === '999999') { W.sending = false; W.rejectedMsg = `${W.cosigner}’s PIN is locked after too many wrong attempts. ${W.cosigner} can reset it from their own account settings (Settings › Witness PIN). Choose another ${sk === 'witness' ? 'witness' : sk === 'amount' ? 'colleague' : 'co-signer'}${fallbackAllowed(sk === 'witness') ? ', or use the forgotten-PIN fallback' : ''}.`; renderWizard(); return; }
            if (false) {
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
            const isG = GIVEN_LIKE.includes(W.outcome);
            const oa = orderAmt(W.r);
            const amtTxt = isG && (W.amtMode !== 'asOrdered' || oa.range) ? ` · ${fmtAmt(W.amt, oa)}${W.amtMode === 'less' ? `, less than ordered (${W.amtReason.toLowerCase()})` : W.amtMode === 'more' ? ', more than ordered' : W.amtMode === 'prescriber' ? ', by the prescriber’s instruction' : ''}` : '';
            const ovr = isG && W.r.cd && !W.isPrn ? cdOverrideFor(key) : null;
            const refs = isG && W.amtMode === 'more' ? { err: 'ME-2026-031', inc: 'INC-2026-118' } : null;
            const notConf = isG && amountNeedsSecond() && S.nobody && !secondKindBase();
            const rxWho = isG && W.amtMode === 'prescriber' ? W.rx.who : null;
            if (key !== 'prn') S.rows[key] = offline ? { state: 'queued', line: 'Saved on this device · not sent yet' } : { state: W.outcome, line: lineFor[W.outcome] + amtTxt, second: sk ? { kind: sk, name: W.cosigner, forgot: !!W.forgot } : null, notConfirmed: notConf, overdose: refs, rx: rxWho ? { who: rxWho } : null, override: ovr ? { by: ovr.by, reason: ovr.reason } : null };
            const unv = sk && W.forgot ? W.cosigner : null;
            const pref = W.pp.pref, medName = W.r.med, outLabel = lbl ? lbl.l : W.outcome, isCd = !!W.r.cd, amtShort = fmtAmt(W.amt, oa);
            W = null;
            closeDialog(true);
            render(true);
            const base = `Recorded — ${medName} for ${pref}: ${outLabel.toLowerCase()} at ${t}.`;
            if (!refs) toast(offline || unv || notConf || rxWho || ovr ? 'warning' : 'success', offline ? `Saved on this device — ${medName} for ${pref} isn’t on the chart yet. It will send when you reconnect.` : unv ? `${base} Second person not verified: ${unv} has been asked to confirm.` : notConf ? `${base} Not confirmed by a second person — the house lead has a follow-up.` : rxWho ? `${base} The prescriber’s instruction is waiting for a lead to countersign.` : ovr ? `${base} No witness — recorded under the override by ${ovr.by}. The house lead gets a follow-up next shift.` : base);
            restoreFocus();
            if (refs) openErrorCreated(pref, medName, amtShort, isCd, refs);
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
            const content = S.mode === 'person' ? personPage() : S.mode === 'record' ? recordPage() : S.mode === 'myday' ? myDayPage() : S.mode === 'mypin' ? myPinPage() : S.mode === 'tasks' ? tasksPage() : S.mode === 'mycal' ? myCalPage() : S.mode === 'handover' ? handoverPage() : hubPage();
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
        if (!t) {
            if (!e.target.closest('#rail-more-pop')) closeMore();
            const row = e.target.closest('[data-menu]');
            if (row && !e.target.closest('a, button, input, select, textarea, label, .dlg, #rail-more-pop') && !window.getSelection().toString()) {
                const primary = $('.row-actions .btn:not(.kebab), td .btn:not(.kebab)', row);
                if (primary) primary.click();
                else if (row.dataset.menu.startsWith('dose:')) openDetail(row.dataset.menu.split(':')[1]);
                else if (row.dataset.menu.startsWith('staffpin:')) toast('info', 'Staff eligibility detail is designed in P11.');
            }
            return;
        }
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
            case 'pr-save': openPinConfirm(); break;
            case 'pr-confirm': { const changed = PIN_RULES.filter((r) => String(S.pinDraft[r.key]) !== String(S.pinRules[r.key])); changed.forEach((r) => S.pinHistory.unshift({ when: '29 Sep 2026 9:12 am', who: PERSONAS[S.persona].name, what: `${r.label}: ${pinVal(r, S.pinDraft[r.key])}` })); S.pinRules = { ...S.pinDraft }; closeDialog(true); render(true); toast('success', 'Second-person confirmation rules saved.'); const hd = $('#pr-h'); if (hd) hd.focus(); break; }
            case 'pin-reset': closeMore(); openPinReset(t.dataset.name); break;
            case 'toast-close': closeDialog(); toast('success', t.dataset.msg); break;
            case 'mp-set': { const a = ($('#mp-new') || {}).value || '', b2 = ($('#mp-new2') || {}).value || ''; const err = !/^\d{6}$/.test(a) ? 'Enter exactly 6 digits.' : a !== b2 ? 'The two PINs don’t match.' : ''; const box = $('#mp-err'); if (err) { box.innerHTML = `<p class="ferr" role="alert" style="margin:8px 0 0">${err}</p>`; $('#mp-new').setAttribute('aria-invalid', 'true'); $('#mp-new').focus(); break; } S.myPin = 'set'; render(true); toast('success', 'Your witness PIN is set. You can now co-sign and witness.'); break; }
            case 'ho-ack': S.handoverAck = true; render(true); toast('success', 'Handover acknowledged. Medication follow-ups stay open.'); { const m = $('#main'); if (m) m.focus(); } break;
            case 'pick-open': if (W.pick) { W.pick = null; renderWizard(false, '#w-cos'); break; } W.pick = { q: '' }; renderWizard(false, '#w-cos-q'); break;
            case 'pick-opt': if (t.getAttribute('aria-disabled') === 'true') { toast('info', 'They haven’t set a witness PIN, so they can’t be chosen. They can set one in Settings › Witness PIN.'); break; } W.cosigner = t.dataset.name; W.pick = null; W.rejectedMsg = null; if (W.error === 'cosign') W.error = null; renderWizard(false, W.forgot ? '#w-cos' : '#w-cpw'); break;
            case 'req-send': if (!W.cosigner) { W.error = 'cosign'; renderWizard(false, '#w-cos'); break; } W.req = 'pending'; W.error = null; renderWizard(false, '[data-act="req-sim"]'); break;
            case 'req-sim': W.req = t.dataset.r; renderWizard(false, W.req === 'approved' ? '#wiz-foot .btn-primary' : '[data-act="req-send"]'); break;
            case 'sr-confirm': { SAFETY_RULES.forEach((r) => { if (S.safetyDraft[r.key] !== S.safety[r.key]) S.safetySetBy[r.key] = `${PERSONAS[S.persona].name}, 29 Sep 2026 9:12 am`; }); S.safety = { ...S.safetyDraft }; closeDialog(true); render(true); toast('success', 'Safety rules saved. They apply from the next dose signed, at every site.'); const hd = $('#sr-h'); if (hd) hd.focus(); break; }
            case 'tp-open': { const c = dtCtx(t); if (!c) break; const wasOpen = !!c.s.tp; closeOtherDt(); if (wasOpen) { c.rr(`#${c.id}-time`); break; } c.s.tp = { ap: c.s.time.ap, hText: pad2(c.s.time.h), mText: pad2(c.s.time.m), face: 'hour', manual: false, err: false }; c.rr(`#${c.id}-hour`); break; }
            case 'tp-mode': { const c = dtCtx(t); c.s.tp.manual = !c.s.tp.manual; c.rr(`[data-dt] [data-act="tp-mode"]`); break; }
            case 'tp-face': { const c = dtCtx(t); c.s.tp.face = t.dataset.face; c.rr(`[data-act="tp-face"][data-face="${t.dataset.face}"]`); break; }
            case 'tp-dial': { const c = dtCtx(t); if (c.s.tp.face === 'hour') { c.s.tp.hText = pad2(+t.dataset.val); c.s.tp.face = 'minute'; } else { c.s.tp.mText = pad2(+t.dataset.val); } c.s.tp.err = false; c.rr(`.time-picker-mark[data-val="${t.dataset.val}"]`); break; }
            case 'tp-ap': { const c = dtCtx(t); c.s.tp.ap = t.dataset.ap; c.rr(`[data-act="tp-ap"][data-ap="${t.dataset.ap}"]`); break; }
            case 'tp-cancel': { const c = dtCtx(t); c.s.tp = null; c.rr(`#${c.id}-time`); break; }
            case 'dp-open': { const c = dtCtx(t); if (!c) break; const wasOpen = !!c.s.dp; closeOtherDt(); if (wasOpen) { c.rr(`#${c.id}-date`); break; } c.s.dp = { draft: c.s.date, month: c.s.date.slice(0, 7) }; c.rr('.cal-day[aria-pressed="true"]'); break; }
            case 'dp-month': { const c = dtCtx(t); const [yy, mm] = c.s.dp.month.split('-').map(Number); const d = new Date(yy, mm - 1 + Number(t.dataset.d), 1); c.s.dp.month = `${d.getFullYear()}-${pad2(d.getMonth() + 1)}`; c.rr(`[data-act="dp-month"][data-d="${t.dataset.d}"]`); break; }
            case 'dp-day': { const c = dtCtx(t); c.s.dp.draft = t.dataset.iso; c.rr(`.cal-day[data-iso="${t.dataset.iso}"]`); break; }
            case 'dp-cancel': { const c = dtCtx(t); c.s.dp = null; c.rr(`#${c.id}-date`); break; }
            case 'dp-apply': { const c = dtCtx(t); c.s.date = c.s.dp.draft; c.s.dp = null; c.clear(); c.rr(`#${c.id}-date`); break; }
            case 'tp-apply': tpApply(dtCtx(t)); break;
            // v4 — amount given
            case 'amt-mode': {
                const o = orderAmt(W.r), minOrd = o.range ? o.range[0] : o.n;
                W.amtMode = t.dataset.m; W.error = null; closeOtherDt();
                if (W.amtMode === 'less') W.amt = minOrd - amtStep(o);
                if (W.amtMode === 'more') W.amt = null; // entered, never prefilled
                if (W.amtMode === 'asOrdered') W.amt = o.range ? null : o.n;
                if (W.amtMode === 'prescriber') { W.amt = o.n; W.rx = W.rx || { who: '', date: TODAY, time: { h: 9, m: 5, ap: 'am' }, dp: null, tp: null, readBack: false, note: '' }; }
                renderWizard(false, W.amtMode === 'asOrdered' ? (o.range ? '#w-amt-sel' : '[data-act="amt-mode"][data-m="less"]') : W.amtMode === 'prescriber' ? '#w-rx-who' : '#w-amt');
                break;
            }
            case 'amt-dec': case 'amt-inc': { const o = orderAmt(W.r); const s2 = amtStep(o); const cur = W.amt == null ? (W.amtMode === 'more' ? o.n : 0) : W.amt; W.amt = Math.max(0, Math.round((cur + (act === 'amt-inc' ? s2 : -s2)) * 2) / 2); if (W.error && /^(amt-|rx-dose)/.test(W.error)) W.error = null; renderWizard(false, `[data-act="${act}"]`); break; }
            // v4 — controlled-drug witness override
            case 'ovr-request': { const row = t.dataset.row || 'r11'; if (t.dataset.close || $('#dialog-root').innerHTML) closeDialog(true); openOverrideRequest(row); break; }
            case 'orq-cover': S.ovRequestCover = t.dataset.k; $$('[data-act="orq-cover"]').forEach((b) => b.setAttribute('aria-pressed', String(b === t))); break;
            case 'orq-send': S.ovRequest = 'waiting'; closeDialog(true); render(true); toast('success', 'Request sent to managers at 9:13 am. You’ll see their answer on this dose.'); { const b = $('[data-row-id="r11"] .row-actions .btn'); if (b) b.focus(); } break;
            case 'ovr-sim': S.ovRequest = t.dataset.r; if (t.dataset.r === 'declined') S.ovDecline = 'Jordan can come in at 10:00 am to witness'; render(true); toast(t.dataset.r === 'approved' ? 'success' : 'warning', t.dataset.r === 'approved' ? 'Rangi Parata approved the witness override until 3:00 pm. You can now record Grace’s clonazepam without a witness.' : 'Rangi Parata declined the witness override. Record it as not given, or wait for Jordan at 10:00 am.'); break;
            case 'ovr-grant': closeMore(); openOverrideGrant(t.dataset.mode || 'new', { decline: t.dataset.decline === '1' }); break;
            case 'ovr-decline-start': G.declining = true; G.error = null; renderGrant('#g-decline'); break;
            case 'ovr-decline-cancel': G.declining = false; G.error = null; renderGrant('[data-act="ovr-decline-start"]'); break;
            case 'ovr-decline': grantDecline(); break;
            case 'ovr-approve': grantApprove(); break;
            case 'ovr-detail': closeMore(); openOverrideDetail(t.dataset.id); break;
            case 'ovr-revoke': closeMore(); if ($('#dialog-root').innerHTML) closeDialog(true); openOverrideRevoke(t.dataset.id); break;
            case 'ovr-revoke-confirm': { const why = ($('#rv-why') || {}).value || ''; if (!why.trim()) { $('#rv-why').setAttribute('aria-invalid', 'true'); $('#rv-err').innerHTML = '<span class="ferr" role="alert">Say why you’re revoking it — the house lead sees this.</span>'; $('#rv-why').focus(); break; } S.ovRevoked[t.dataset.id] = `Revoked at 9:15 am by ${PERSONAS[S.persona].name}: “${why.trim()}”`; closeDialog(true); render(true); toast('success', 'Override revoked. From now, controlled doses there need a witness again.'); break; }
            // v4 — Settings › Medication rules
            case 'jump': { const el = document.getElementById(t.dataset.to); if (el) { el.scrollIntoView({ block: 'start' }); const h = $('h3', el); if (h) { h.setAttribute('tabindex', '-1'); h.focus({ preventScroll: true }); } } break; }
            case 'rules-retry': S.rulesDemo = 'loaded'; render(true); toast('success', 'Medicine rules loaded (mockup).'); break;
            case 'rule-new': closeMore(); openRuleWizard(null); break;
            case 'rule-edit': closeMore(); openRuleWizard(t.dataset.id); break;
            case 'rule-toggle': closeMore(); openRuleToggle(t.dataset.id); break;
            case 'rule-toggle-confirm': { const r = ruleById(t.dataset.id); r.active = !r.active; r.paused = r.active ? '' : `Paused 29 Sep 2026 by ${PERSONAS[S.persona].name}`; r.by = PERSONAS[S.persona].name; r.when = '29 Sep 2026'; S.ruleHistory.unshift({ when: '29 Sep 2026 9:12 am', who: PERSONAS[S.persona].name, what: `${r.active ? 'Resumed' : 'Paused'}: ${stripTags(ruleSentence(r))}` }); closeDialog(true); render(true); toast('success', r.active ? 'Rule resumed. It applies from the next dose saved.' : 'Rule paused. Doses no longer need it.'); break; }
            case 'rule-history': closeMore(); openRuleHistory(t.dataset.id); break;
            case 'rw-match': RW.match = t.dataset.k; RW.value = RW.match === 'controlled' ? 'controlled' : ''; RW.error = null; renderRuleWizard(`[data-act="rw-match"][data-k="${RW.match}"]`); break;
            case 'rw-scope': RW.scope = t.dataset.k; renderRuleWizard(`[data-act="rw-scope"][data-k="${RW.scope}"]`); break;
            case 'rw-active': RW.active = t.dataset.k === 'on'; renderRuleWizard(`[data-act="rw-active"][data-k="${t.dataset.k}"]`); break;
            case 'rw-next': ruleNext(); break;
            case 'rw-back': RW.error = null; RW.step = Math.max(0, RW.step - 1); renderRuleWizard(); break;
            case 'rw-step': { const s2 = +t.dataset.step; if (s2 <= RW.step) { RW.step = s2; RW.error = null; renderRuleWizard(); } break; }
            case 'rw-save': ruleSave(); break;
            case 'cdw-save': openCdwConfirm(); break;
            case 'cdw-confirm': { Object.keys(S.cdw).forEach((k) => { if (S.cdw[k] !== S.cdwDraft[k]) S.cdHistory.unshift({ when: '29 Sep 2026 9:12 am', who: PERSONAS[S.persona].name, what: `${CDW_LABEL[k]}: ${CDW_OPTS[k === 'kowhai' || k === 'rimu' ? 'house' : k].find((x) => x[0] === S.cdwDraft[k])[1]}` }); }); S.cdw = { ...S.cdwDraft }; closeDialog(true); render(true); toast('success', 'Controlled-drug witness settings saved.'); const hd = $('#cdw-h'); if (hd) hd.focus(); break; }
            default: break;
        }
    });
    document.addEventListener('change', (e) => {
        const t = e.target;
        if (t.dataset.act === 'persona') { S.persona = t.value; S.rows = {}; const hubs = visibleHubs(S.persona); location.hash = S.mode === 'person' ? `#/person/${S.persona}/${S.pid}/${S.ptab}` : hrefFrame(S.persona, hubs[0].id); }
        if (t.dataset.act === 'scenario') { S.scenario = t.value; S.rows = {}; S.rejectedAcknowledged = false; S.clockedIn = t.value !== 'notClockedIn'; const base = location.hash.split('?')[0] || hrefFrame(S.persona, 'today', 'schedule'); history.replaceState(null, '', `${base}?scenario=${t.value}`); render(); }
        if (t.dataset.act === 'wiz-reason' && W) { W.reason = t.value; if (W.error === 'reason' && t.value) W.error = null; }
        if (t.dataset.act === 'pr-sel') { S.pinDraft[t.dataset.key] = t.value; const btn = $('[data-act="pr-save"]'); if (btn) btn.disabled = !PIN_RULES.some((r) => String(S.pinDraft[r.key]) !== String(S.pinRules[r.key])); }
        if (t.dataset.act === 'sr-sel') { S.safetyDraft[t.dataset.key] = t.value; const btn = $('[data-act="sr-save"]'); if (btn) btn.disabled = !SAFETY_RULES.some((r) => S.safetyDraft[r.key] !== S.safety[r.key]); }
        if (t.dataset.act === 'pin-forgot' && W) { W.forgot = t.checked; W.cosignPw = ''; if (W.error === 'cpw') W.error = null; if (W.forgot && W.cosigner && STAFF_PINS.find((x) => x.name === W.cosigner)) {} renderWizard(false, '[data-act="pin-forgot"]'); }
        if (t.dataset.act === 'nobody-demo') { S.nobody = t.value === '1'; render(true); }
        if (W && t.dataset.act === 'amt-reason') { W.amtReason = t.value; if (W.error === 'amt-reason' && t.value) W.error = null; }
        if (W && t.dataset.act === 'amt-sev') { W.sev = t.value; if (W.error === 'amt-sev' && t.value) W.error = null; }
        if (W && t.dataset.act === 'amt-range') { W.amt = t.value ? Number(t.value) : null; if (W.error === 'amt-range' && t.value) W.error = null; }
        if (W && t.dataset.act === 'rx-rb') { W.rx.readBack = t.checked; if (W.error === 'rx-rb' && t.checked) W.error = null; }
        if (G && t.dataset.act === 'g-sel') { G[t.dataset.key] = t.value; if (t.dataset.key === 'reason' && G.error === 'reason' && t.value) G.error = null; renderGrant('#' + t.id); }
        if (RW && t.dataset.act === 'rw-val') { RW.value = t.value; if (RW.error === 'value' && t.value) RW.error = null; renderRuleWizard('#' + t.id); }
        if (RW && t.dataset.act === 'rw-need') { if (t.dataset.k === 'cs') RW.countersign = t.checked; else RW.obs = t.checked ? [...new Set([...RW.obs, t.dataset.k])] : RW.obs.filter((x) => x !== t.dataset.k); if (RW.error === 'needs') RW.error = null; renderRuleWizard(`[data-act="rw-need"][data-k="${t.dataset.k}"]`); }
        if (t.dataset.act === 'rules-demo') { S.rulesDemo = t.value; render(true); }
        if (t.dataset.act === 'cdw-sel') { S.cdwDraft[t.dataset.key] = t.value; const btn = $('[data-act="cdw-save"]'); if (btn) btn.disabled = !Object.keys(S.cdw).some((k) => S.cdw[k] !== S.cdwDraft[k]); }
        if (t.dataset.act === 'fallback-demo') { S.pinRules.fallback = t.value; S.pinDraft.fallback = t.value; render(); }
        if (t.dataset.act === 'mypin-demo') { S.myPin = t.value; render(); }
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
    document.addEventListener('focusin', (e) => { if (!e.target.closest || !e.target.closest('.time-picker-popover') || !/-(hour|minute)$/.test(e.target.id)) return; const c = dtCtx(e.target); if (c && c.s.tp) { const face = e.target.id.endsWith('-hour') ? 'hour' : 'minute'; if (c.s.tp.face !== face) { c.s.tp.face = face; c.rr('#' + e.target.id); } } });
    document.addEventListener('input', (e) => {
        const a = e.target.dataset.act;
        if (W && a === 'amt-in') { const v = parseFloat(String(e.target.value).replace('½', '.5')); W.amt = Number.isFinite(v) ? v : null; return; }
        if (W && W.rx && a === 'rx-who') { W.rx.who = e.target.value; if (W.error === 'rx-who') W.error = null; return; }
        if (W && W.rx && a === 'rx-note') { W.rx.note = e.target.value; return; }
        if (W && a === 'amt-imm') { W.imm = e.target.value; if (W.error === 'amt-imm') W.error = null; return; }
        if (G && a === 'g-note') { G.note = e.target.value; return; }
        if (G && a === 'g-decline') { G.declineReason = e.target.value; if (G.error === 'decline') G.error = null; return; }
        if (RW && a === 'rw-text') { RW.value = e.target.value; if (RW.error === 'value') RW.error = null; const pv = $('#rw-live'); if (pv) pv.innerHTML = ruleLive(); return; }
    });
    document.addEventListener('input', (e) => { if (e.target.dataset.act === 'pr-in') { S.pinDraft[e.target.dataset.key] = e.target.value; const btn = $('[data-act="pr-save"]'); if (btn) btn.disabled = !PIN_RULES.some((r) => String(S.pinDraft[r.key]) !== String(S.pinRules[r.key])); return; } if (e.target.dataset.act === 'pick-q' && W && W.pick) { W.pick.q = e.target.value; const pos = e.target.selectionStart; renderWizard(false, '#w-cos-q'); const qi = $('#w-cos-q'); if (qi) qi.setSelectionRange(pos, pos); return; } if (e.target.dataset.act === 'wiz-note' && W) W.note = e.target.value; if (e.target.dataset.act === 'tp-h' || e.target.dataset.act === 'tp-m') { const c = dtCtx(e.target); if (c && c.s.tp) { if (e.target.dataset.act === 'tp-h') { c.s.tp.hText = e.target.value.trim(); c.s.tp.face = 'hour'; } else { c.s.tp.mText = e.target.value.trim(); c.s.tp.face = 'minute'; } c.s.tp.err = false; } } if (e.target.dataset.act === 'wiz-cpw' && W) { W.cosignPw = e.target.value; if (W.error === 'cpw') W.error = null; } });
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
        if (kind === 'record') { openRecord(arg, { notGivenOnly: !!(MEDS[arg] && rowBlock(arg)) }); if (step) { W.step = +step; if (+step >= 1 && q.get('outcome')) W.outcome = q.get('outcome'); if (q.get('reason')) W.reason = q.get('reason'); if (q.get('err')) W.error = q.get('err'); if (q.get('amt')) { const o = orderAmt(W.r), minOrd = o.range ? o.range[0] : o.n; W.amtMode = q.get('amt'); W.amt = q.get('amtv') ? Number(q.get('amtv')) : W.amtMode === 'less' ? minOrd - amtStep(o) : W.amtMode === 'more' ? null : W.amtMode === 'prescriber' ? o.n * 2 : W.amt; if (W.amtMode === 'prescriber') W.rx = { who: q.get('rxwho') || '', date: TODAY, time: { h: 9, m: 5, ap: 'am' }, dp: null, tp: null, readBack: q.get('rb') === '1', note: '' }; if (q.get('why')) W.amtReason = q.get('why'); if (q.get('sev')) W.sev = q.get('sev'); if (q.get('imm')) W.imm = q.get('imm'); if (q.get('note')) W.note = q.get('note'); } if (q.get('who')) { W.cosigner = q.get('who'); W.cosignPw = q.get('pin2') || ''; } if (q.get('tp')) W.tp = { ap: 'am', hText: '09', mText: '12', face: q.get('face') || 'hour', manual: q.get('tp') === 'type', err: false }; if (q.get('dp')) W.dp = { draft: TODAY, month: TODAY.slice(0, 7) }; renderWizard(false, q.get('tp') ? '#w-dt-hour' : q.get('dp') ? '.cal-day[aria-pressed="true"]' : undefined); } }
        if (kind === 'reoffer') { openRecord('r4', { reoffer: true }); W.step = 1; renderWizard(); }
        if (kind === 'why') { const r = MEDS[arg]; if (r) openWhy(rowBlock(arg), { p: PEOPLE[r.pid].pref, med: r.med, time: r.slot, pid: r.pid, support: r.support, row: arg }); }
        if (kind === 'prn') openRecord('prn');
        if (kind === 'rejected') openRejected('prn');
        if (kind === 'eligibility') openEligibility();
        if (kind === 'find') openPalette();
        if (kind === 'ovrreq') openOverrideRequest(arg || 'r11');
        if (kind === 'grant') openOverrideGrant(arg || 'new', { decline: step === 'decline' });
        if (kind === 'ovdetail') openOverrideDetail(arg);
        if (kind === 'revoke') openOverrideRevoke(arg);
        if (kind === 'rule') { openRuleWizard(arg === 'new' ? null : arg); if (step) { RW.step = +step; if (q.get('rmatch')) RW.match = q.get('rmatch'); if (q.get('rval')) RW.value = q.get('rval'); if (q.get('rerr')) RW.error = q.get('rerr'); renderRuleWizard(); } }
        if (kind === 'ruletoggle') openRuleToggle(arg);
        if (kind === 'errcreated') openErrorCreated('Tama', 'Levetiracetam', '2 tablets (1000 mg)', false, { err: 'ME-2026-031', inc: 'INC-2026-118' });
        if (kind === 'menu') { const kb = $('[data-menu="' + arg + ':' + step + '"] .kebab'); if (kb) { const rc = kb.getBoundingClientRect(); openCtxMenu(arg + ':' + step, rc.right - 240, rc.bottom + 4, kb); } }
    }

    if (!location.hash) history.replaceState(null, '', '#/frame/sw/today/schedule');
    parseHash();
    render();
    if (S.mode === 'catalogue' && S.section !== 'intro' && !S.only) { const sec = $(`#sec-${S.section}`); if (sec) sec.scrollIntoView({ block: 'start' }); }
    openFromQuery();
})();
