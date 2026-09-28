/* eMAR P00 v1 — Medication rules & states. Clickable design mockup, synthetic data only.
 * No application code, routes, schema or configuration. Every state specimen in the
 * catalogue is rendered by the same function the navigation frame uses, so wording and
 * treatment cannot drift between the two. */
(() => {
    'use strict';

    const VERSION = 'P00 v1';
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
            title: 'Your medication competency expired on 14 September 2026', icon: 'user-check',
            text: 'You can’t record doses as given until you’re reassessed.',
            next: ['Ask a colleague with current competency to give this dose.', 'Book a reassessment with your assessor, Hana Kereama.'],
            still: 'notgiven', action: { label: 'View my eligibility', act: 'eligibility', icon: 'user-check' },
            gate: 'MedicationAdministratorCompetencyPolicy, re-checked when you save.', depends: ['D3'], fixes: ['EM-03', 'NF-03'],
        },
        exemptionEnded: {
            title: 'Your competency exemption ended on 20 September 2026', icon: 'user-check',
            text: 'Jordan Tipene approved an exemption until 20 September 2026. Exemptions have a fixed end date.',
            next: ['Ask a colleague with current competency to give this dose.', 'Ask Jordan Tipene about booking an assessment.'],
            still: 'notgiven', action: { label: 'View my eligibility', act: 'eligibility', icon: 'user-check' },
            gate: 'Finite approved exemption (server rule kept).', depends: ['D3'], fixes: ['NF-03'],
        },
        restricted: {
            title: 'Your assessment says you work under supervision', icon: 'user-check',
            text: 'Hana Kereama assessed you on 2 March 2026 for supervised practice only. You can’t record this dose as given on your own.',
            next: ['Ask a colleague with unrestricted competency to give and record it.', `Whether a colleague who is present can co-sign instead: ${NC()}`],
            still: 'notgiven', action: { label: 'View my eligibility', act: 'eligibility', icon: 'user-check' },
            gate: 'Restricted assessment (NF-03 enforcement rule awaits Stephan’s decision).', depends: ['D3', 'NF-03 rule'], fixes: ['NF-03'],
        },
        areaNotPassed: {
            title: 'Insulin isn’t one of your passed areas', icon: 'user-check',
            text: '{p}’s {med} needs the insulin area. Your assessment on 2 March 2026 didn’t include it.',
            next: ['Ask a colleague who has passed the insulin area to give it.', 'Talk to your assessor about the insulin area.'],
            still: 'notgiven', action: { label: 'View my eligibility', act: 'eligibility', icon: 'user-check' },
            gate: 'Assessment area matched to the medicine’s classification (NF-03).', depends: ['D3'], fixes: ['NF-03'],
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
            text: '{p} has a recorded penicillin allergy (severe) on the health profile, reviewed 12 August 2026. {med} is a penicillin.',
            next: ['Don’t give it.', 'Record this dose as withheld.', `Contact the on-call contact: ${NC()}`],
            still: 'withheld-always', override: true,
            gate: 'Blocking safety checks apply to “given” only; not-given outcomes always save (NF-06).', depends: ['D5', 'D2'], fixes: ['EM-07', 'NF-06', 'NF-09'],
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
        return `<div class="blocked${b.safety ? ' safety' : ''}" role="group" aria-label="${esc(tpl(b.title, c))}">
            <div class="bl-title">${ic(b.icon)}${esc(tpl(b.title, c))}</div>
            <p class="bl-text">${tpl(b.text, { p: esc(c.p), med: esc(c.med) })}</p>
            <ul class="bl-next">${b.next.map((n) => `<li>${tpl(n, { p: esc(c.p), med: esc(c.med) })}</li>`).join('')}</ul>
            ${stillHtml}${bg}${ov}
            <div class="bl-foot">Checked again when you save.</div>
        </div>`;
    }

    /* Allergy status (D5). Never a false "no known allergies". */
    function allergyBanner(state, p = 'Aroha', compact = false) {
        const P = esc(p);
        if (state === 'recorded' || state === 'recorded-pen') {
            const list = state === 'recorded' ? 'Penicillin (severe) · Latex (mild)' : 'Penicillin (severe)';
            return `<div class="banner safety-solid" role="note"><span class="b-ico">${ic('alert-octagon')}</span><div class="b-body"><div class="b-title">Allergies: ${list}</div>${compact ? '' : `<div class="b-text">From the health profile · reviewed 12 August 2026 by Jordan Tipene</div>`}</div></div>`;
        }
        if (state === 'nkda') {
            return `<div class="banner neutral" role="note"><span class="b-ico">${ic('check-circle')}</span><div class="b-body"><div class="b-title">No known drug allergies</div>${compact ? '' : `<div class="b-text">Recorded on the health profile by Jordan Tipene, 12 August 2026.</div>`}</div></div>`;
        }
        if (state === 'none') {
            return `<div class="banner warning" role="note"><span class="b-ico">${ic('alert-triangle')}</span><div class="b-body"><div class="b-title">No allergies recorded</div><div class="b-text">This doesn’t mean ${P} has none — allergy status hasn’t been reviewed. Check with the house lead before giving a new medicine.</div></div></div>`;
        }
        return `<div class="banner critical" role="alert"><span class="b-ico">${ic('alert-circle')}</span><div class="b-body"><div class="b-title">Allergy information couldn’t be loaded</div><div class="b-text">Don’t assume ${P} has no allergies. Try again, or check the paper record before giving anything new.</div>${compact ? '' : `<div class="b-actions"><button class="btn btn-outline btn-sm" type="button" data-act="toast" data-msg="Allergy information reloaded (mockup).">${ic('refresh')}Try again</button></div>`}</div></div>`;
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
        ['reject', 'Server refuses the next save'],
    ];

    function parseHash() {
        const raw = location.hash.replace(/^#\/?/, '');
        const [path, query] = raw.split('?');
        const seg = path.split('/').filter(Boolean);
        const q = new URLSearchParams(query || '');
        if (q.get('scenario')) S.scenario = q.get('scenario');
        S.only = q.get('only') || null;
        if (seg[0] === 'catalogue') { S.mode = 'catalogue'; S.section = seg[1] || 'intro'; return; }
        if (seg[0] === 'person') { S.mode = 'person'; S.persona = PERSONAS[seg[1]] ? seg[1] : 'sw'; S.pid = PEOPLE[seg[2]] ? seg[2] : 'aroha'; S.ptab = seg[3] || 'chart'; return; }
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
                <select id="v-scn" data-act="scenario">${SCENARIOS.map(([k, l]) => `<option value="${k}"${k === S.scenario ? ' selected' : ''}>${l}</option>`).join('')}</select></span>` : ''}
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
            <a class="sb-item" href="#" data-act="toast-outside">${ic('dashboard')}<span class="sb-label">My Day</span></a>
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
        return MEDS[k].state;
    }
    function rowLine(k) {
        if (S.rows[k]) return S.rows[k].line;
        return MEDS[k].line;
    }
    function rowBlock(k) {
        if (S.scenario === 'notClockedIn' && S.clockedIn === false) return 'notClockedIn';
        if (S.scenario === 'notClockedIn') return 'notClockedIn';
        return MEDS[k].block || null;
    }

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
        const compLine = !block && openable && S.scenario === 'competencyExpired' && r.support === 'administer' && has(persona, 'administer') ? `<span class="state-line" style="color:var(--status-warning);font-weight:600">${ic('lock', 's3')} Can’t record as given — your competency expired. Refusal, withhold or absence still OK.</span>` : '';
        const extra = r.fu && st === 'refused' ? `<span class="state-line">${ic('flag', 's3')} ${esc(r.fu)}</span>` : '';
        return `<div class="dose-row" data-row-id="${k}">
            <div class="who"><span class="disc" aria-hidden="true">${pp.initials}</span><div style="min-width:0"><a class="who-name name-link" href="#/person/${persona}/${pp.id}/chart">${esc(pp.pref)}</a><div class="who-sub">${esc(pp.surname)}</div></div></div>
            <div style="min-width:0"><div class="med-name">${esc(r.med)} <span style="font-weight:500;color:var(--muted-foreground)">${esc(r.str)}</span></div>
                <div class="med-sub">${supportChip(r.support)}${r.cd ? `<span class="chipn">${ic('shield', 's3')}Controlled</span>` : ''}<span>${esc(r.dose)} · ${esc(r.ins)}</span></div></div>
            <div class="state-cell">${dbadge(st)}<span class="state-line">${esc(rowLine(k))}</span>${blockLine}${compLine}${extra}</div>
            <div class="row-actions">${actions}</div>
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
                + meter({ label: 'Needs help', big: '2', cap: 'Allergy match · order to check', tone: 'critical', href: hrefFrame(p, 'today', 'schedule'), aria: 'View 2 doses you can’t record' })
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
            actions: searchBox('Search people or medicines…', 'hs') + `<button class="glass-btn" type="button" data-act="toast-outside" aria-label="Shift handover — medication" title="Shift handover — medication" style="width:36px;padding:0;justify-content:center">${ic('repeat')}</button><button class="glass-btn" type="button" data-act="toast-outside" aria-label="Report a medication error" title="Report a medication error" style="width:36px;padding:0;justify-content:center">${ic('flag')}</button>${has(p, 'administer') ? `<button class="white-btn" type="button" data-act="prn" data-fk="prn">${ic('plus')}Record as-needed dose</button>` : ''}`,
            meters, filters: (filterFor[view] || '') + updated, rail: railHtml(items, view),
        });
    }
    function eligibilityMeter(expired) {
        if (!has(S.persona, 'administer')) return meter({ label: 'My eligibility', big: 'Not assessed', cap: 'You don’t record doses in this role', href: undefined, act: 'eligibility', aria: 'View my eligibility' });
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
        if (!S.rejectedAcknowledged && p === 'sw') {
            out.push(`<div class="banner critical" role="alert"><span class="b-ico">${ic('x-circle')}</span><div class="b-body"><div class="b-title">1 record wasn’t saved — action needed</div><div class="b-text">Paracetamol for Aroha at 8:50 am was <b>not recorded</b>: the as-needed limit on the prescription is reached. The chart doesn’t show this dose.</div><div class="b-actions"><button class="btn btn-outline btn-sm frontline-tap" type="button" data-act="rejected" data-row="prn" data-fk="rej-prn">Review what happened</button></div></div></div>`);
        }
        const cdVisible = has(p, 'cd.view');
        const slots = {};
        Object.keys(MEDS).forEach((k) => { if (MEDS[k].cd && !cdVisible) return; (slots[MEDS[k].slot] = slots[MEDS[k].slot] || []).push(k); });
        const body = Object.entries(slots).map(([slot, ks]) => `<div class="slot-head" role="heading" aria-level="3">${ic('clock', 's3')}${slot}<span style="font-weight:500">· ${ks.length} ${ks.length === 1 ? 'medicine' : 'medicines'}</span></div>${ks.map((k) => doseRow(k, p)).join('')}`).join('');
        out.push(`<section class="card" aria-label="Today’s doses" style="overflow:hidden">${body}</section>`);
        out.push(`<p class="text-caption" style="margin:0">${cdVisible ? '' : 'Showing medicines your role can see. '}Times in NZDT. Self-managed medicines are listed for information and never count as late or missed.</p>`);
        return out.join('');
    }

    function followUpRow(f) {
        return `<div class="fu-row">
            <div><div class="fu-title">${esc(f.title)}</div><div class="fu-src">${f.src}</div></div>
            <div class="owner">${f.owner ? `<span class="disc sm" aria-hidden="true">${f.oi}</span><div><div>${esc(f.owner)}</div>${f.osub ? `<div class="o-sub">${f.osub}</div>` : ''}</div>` : `<span class="disc sm muted" aria-hidden="true">${ic('user', 's3')}</span><div><div style="color:var(--status-critical);font-weight:600">No owner</div><div class="o-sub">Assign someone</div></div>`}</div>
            <div class="state-cell">${f.badge}<span class="state-line">${f.line}</span>${f.line2 ? `<span class="state-line">${f.line2}</span>` : ''}</div>
            <div class="row-actions">${f.actions || ''}</div>
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
        return `<section class="card" style="overflow:hidden"><div class="tbl-wrap"><table class="etable"><caption class="sr-only">People and today’s doses</caption><thead><tr><th scope="col">Person</th><th scope="col">House</th><th scope="col">Recorded today</th><th scope="col">Not yet recorded</th><th scope="col">Allergy status</th><th scope="col"><span class="sr-only">Open</span></th></tr></thead><tbody>
            ${rows.map((r) => { const pp = PEOPLE[r.pid]; return `<tr><td><div class="who"><span class="disc sm" aria-hidden="true">${pp.initials}</span><div><div style="font-weight:600">${esc(pp.pref)} ${esc(pp.surname)}</div><div class="who-sub">${esc(pp.legal)}</div></div></div></td><td>${esc(pp.house)}</td><td class="tabular">${r.rec}</td><td>${r.nr ? `<span class="cnt-pill b-critical" style="border:0">${r.nr}</span>` : '<span style="color:var(--muted-foreground)">—</span>'}</td><td>${alChip(r.al)}</td><td style="text-align:right"><a class="btn btn-ghost btn-sm" href="#/person/${p}/${pp.id}/chart">Open medication record ${ic('arrow-right', 's3')}</a></td></tr>`; }).join('')}
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
            <div class="dose-row"><div class="who"><span class="disc" aria-hidden="true">AN</span><div><div class="who-name">Aroha</div><div class="who-sub">Ngata</div></div></div><div><div class="med-name">Paracetamol <span style="font-weight:500;color:var(--muted-foreground)">500 mg tablet</span></div><div class="med-sub">2 tablets · for pain</div></div><div class="state-cell">${dbadge('rejected')}<span class="state-line">Tried 8:50 am · limit on the prescription reached · not on the chart</span></div><div class="row-actions"><button class="btn btn-outline btn-sm frontline-tap" type="button" data-act="rejected" data-row="prn" data-fk="rej-prn2">Review</button></div></div>
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
                filters: `${has(p, 'cd.view') ? '' : '<span class="fchip static">Totals exclude medicines your role can’t see</span>'}<button type="button" class="fchip" data-act="toast" data-msg="Filter menus are designed with each page package (mockup).">${ic('home', 's3')}All approved houses${ic('chev-down', 's3')}</button><button type="button" class="fchip" data-act="toast" data-msg="Filter menus are designed with each page package (mockup).">${ic('calendar', 's3')}Today${ic('chev-down', 's3')}</button><span class="fchip static">${esc(view.label)} filters · ${view.pkg}</span>`,
                rail: railHtml(items, view.id),
            });
            body = hub.id === 'mar' && view.id === 'charts' ? marChartsBody()
                : hub.id === 'safety' && view.id === 'followups' ? followUpsBody(true)
                    : annotCard(hub, view.id);
        }
        return crumbs(crumbTrail) + `<div class="stack" id="main" tabindex="-1">${header}${body}</div>`;
    }

    function noAccess(what) {
        return `<div class="stack" id="main" tabindex="-1"><div class="card"><div class="empty" role="alert"><span class="e-ico">${ic('lock', 's6')}</span><h3>You don’t have access to ${esc(what)}</h3><p>Ask your manager if you need it for your work.</p><div class="e-act"><a class="btn btn-outline" href="${hrefFrame(S.persona, visibleHubs(S.persona)[0]?.id || 'today')}">Go to ${esc(visibleHubs(S.persona)[0]?.label || 'Home')}</a></div></div></div>
            <div class="annot"><div class="a-tag">${ic('info', 's3')}Design note</div>Page-level “no access” (403) names the page, never its content. Records use “We can’t show this record” instead, so a hidden record and a missing one look the same.</div></div>`;
    }
    function notFound(backHref, backLabel) {
        return `<div class="card"><div class="empty" role="alert"><span class="e-ico">${ic('search', 's6')}</span><h3>We can’t show this record</h3><p>It may not exist, or it may not be available to you. Check the link, or go back.</p><div class="e-act"><a class="btn btn-outline" href="${backHref}">${esc(backLabel)}</a></div></div></div>`;
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
            return `<section class="card" style="overflow:hidden"><div class="tbl-wrap"><table class="etable"><caption class="sr-only">Medicines</caption><thead><tr><th scope="col">Medicine</th><th scope="col">Support</th><th scope="col">Status</th></tr></thead><tbody>
                ${list.map(([n, d, s, st, isCd]) => `<tr><td><div style="font-weight:600">${esc(n)} ${isCd ? `<span class="chipn">${ic('shield', 's3')}Controlled</span>` : ''}</div><div class="who-sub">${esc(d)}</div></td><td>${supportChip(s)}</td><td><span class="badge b-success sm">${st}</span></td></tr>`).join('')}
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
        ['allergy', 'Allergy status', 'alert-octagon'],
        ['quality', 'Data quality', 'refresh'],
        ['controlled', 'Controlled-medicine concealment', 'eye-off'],
        ['followups', 'Follow-ups', 'flag'],
        ['identity', 'Person identity header', 'user'],
        ['time', 'Time display rules', 'clock'],
        ['universal', 'Universal interaction states', 'keyboard'],
        ['decisions', 'Organisation decisions (D1–D13)', 'settings'],
        ['reuse', 'Reuse by package', 'git-branch'],
    ];

    function stateCard(s) {
        return `<article class="card sc" id="st-${s.id}" aria-labelledby="st-${s.id}-h">
            <div class="sc-spec">
                <div class="sc-name"><h3 id="st-${s.id}-h">${esc(s.name)}</h3><span class="sc-id">${s.id}</span></div>
                <div class="sc-stage${s.white ? ' white' : ''}">${s.spec}</div>
                ${s.open ? `<button class="btn btn-outline btn-sm sc-open" type="button" ${s.open.attrs}>${ic('arrow-up-right')}${esc(s.open.label)}</button>` : ''}
                ${s.link ? `<a class="btn btn-outline btn-sm sc-open" href="${s.link.href}">${ic('arrow-up-right')}${esc(s.link.label)}</a>` : ''}
            </div>
            <div class="notes">
                ${s.wording ? `<div><div class="nh">Exact wording</div><div class="wording">${s.wording.map((w) => `<div><q>${w}</q></div>`).join('')}</div></div>` : ''}
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
            case 'allergy': return H('Allergy status', 'Three states always, a fourth only when the organisation approves the vocabulary. The screen never says “no known allergies” because a list came back empty.') + reuseBar(R.allergy) + allergyCards();
            case 'quality': return H('Data quality', 'Missing, failed or not-applicable data never looks like a reassuring zero.') + reuseBar(R.quality) + qualityCards();
            case 'controlled': return H('Controlled-medicine concealment', 'For roles without controlled-medicine access, controlled records leave no trace: no row, no placeholder, no count, no search result, no dead link.') + reuseBar(R.cd) + cdCards();
            case 'followups': return H('Follow-ups', 'Every follow-up has an owner, a due time in NZ time and a state. It survives midnight and shift changes and closes only with a recorded result.') + reuseBar(R.fu) + fuCards();
            case 'identity': return H('Person identity header', 'The same header opens every recording dialog: preferred name first, photo only if one is held, house, and the support level for the medicine being recorded.') + reuseBar(R.id) + idCards();
            case 'time': return H('Time display rules', 'All medication times are the house’s local time in Pacific/Auckland, with the zone visible. Daylight saving never shifts a dose silently.') + reuseBar(R.time) + timeSection();
            case 'universal': return H('Universal interaction states', 'States every package mockup must include (plan §7.3), shown here once so they read the same everywhere.') + universalCards();
            case 'decisions': return H('Organisation decisions', 'Values nobody has approved yet display “Not configured” and fail closed. A mockup or code default never becomes policy by being displayed.') + decisionsSection();
            case 'reuse': return H('Reuse by package', 'Which state families each later package must reuse, from plan §7.3. Every package brief must cite this P00 version.') + reuseSection();
            default: return '';
        }
    }

    function introSection() {
        return `<div class="card cat-hero"><h1>Medication rules &amp; states</h1><p>P00 · version 1 · 28 September 2026. The shared state catalogue every medication page reuses. Design only: no application code, routes, schema or configuration change. Synthetic people and staff; no clinical values are approved by appearing here.</p>
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

    function doseCards() {
        const P = 'Aroha';
        const row = (k, line, extra = '') => `<div style="display:flex;flex-direction:column;gap:4px">${dbadge(k)}<span class="state-line">${line}</span>${extra}</div>`;
        return [
            { id: 'dose-not-yet-due', name: 'Not yet due', spec: row('notdue', 'Due 12:00 pm'), wording: ['Not yet due', 'Due 12:00 pm'], when: 'Before the dose’s approved due window opens. The window is organisation decision D4; until it is set the dialog shows “Due window: Not configured”.', treatment: 'Neutral. No record button on the board; early recording follows the early-dose rule (D4) and records the reason.', reuse: R.dose, depends: ['D4'], fixes: ['EM-24'] },
            { id: 'dose-due', name: 'Due', spec: row('due', 'Due now · 9:00 am'), wording: ['Due', 'Due now · 9:00 am'], when: 'Inside the approved window (D4) with no outcome recorded.', treatment: 'Info (primary tint). Primary “Record” button, 44 px target.', reuse: R.dose, depends: ['D4'], fixes: ['EM-01'], link: { href: hrefFrame('sw', 'today', 'schedule'), label: 'See it on Meds today' } },
            { id: 'dose-late', name: 'Late', spec: row('late', 'Due 8:00 am · 1 h 12 min ago', `<div class="banner warning" style="margin-top:6px"><span class="b-ico">${ic('alert-triangle')}</span><div class="b-body"><div class="b-title">This dose is late</div><div class="b-text">No late-dose instruction is set for this medicine. Check with the on-call contact before giving it: ${NC()}</div></div></div>`), wording: ['Late', 'Due 8:00 am · 1 h 12 min ago', 'This dose is late', 'No late-dose instruction is set for this medicine. Check with the on-call contact before giving it: Not configured'], when: 'After the approved window closes, while the dose can still be acted on this shift. Elapsed time is real time (DST-safe).', treatment: 'Warning (amber). Replaces “Give now if safe, or tell your supervisor”. When a per-medicine late instruction exists (D4), its text is shown instead, with its source.', never: '“Give now if safe”. Calling a dose late one minute after its time when a window is configured.', reuse: R.dose, depends: ['D4', 'D12'], fixes: ['EM-24', 'EM-02'], open: { label: 'Open the late dose dialog', attrs: 'data-act="record" data-row="r2" data-fk="cat-late"' } },
            { id: 'dose-not-yet-recorded', name: 'Not yet recorded', spec: row('notrecorded', '8:00 am dose · no outcome recorded') + `<p class="text-caption" style="margin:6px 0 0">Lead view: “3 not yet recorded” — never “0 due” and never “missed”.</p>`, wording: ['Not yet recorded', '8:00 am dose · no outcome recorded', 'No one has recorded what happened with this dose. It may have been given. Find out before anyone gives it, then record the outcome.'], when: 'A past obligation with no outcome once its window has closed (D4), and in every retrospective lens: handover, previous days, dashboard, reports, exports.', treatment: 'Critical. Counted from the obligation schedule, not from stored rows, so every surface shows the same number.', never: 'Shown as “missed”, blank, or counted as 0 because no row exists.', reuse: R.dose, depends: ['D4'], fixes: ['EM-01', 'EM-18'] },
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
        return [
            { id: 'rec-sending', name: 'Pending server confirmation', spec: `<div style="display:flex;flex-direction:column;gap:6px">${dbadge('sending')}<span class="state-line">Sending — not yet confirmed. Don’t close this window.</span><button class="btn btn-primary btn-sm" type="button" disabled><span class="ring-spin" aria-hidden="true"></span>Sending…</button></div>`, wording: ['Sending…', 'Sending — not yet confirmed. Don’t close this window.'], when: 'From pressing save until the server answers.', treatment: 'Info with the ring-only loader. The submit button is disabled so it can’t be pressed twice. If there’s no answer, it becomes “Saved on this device” or “Not recorded” — never “Given”.', reuse: R.record, depends: [], fixes: ['EM-26'] },
            { id: 'rec-confirmed', name: 'Confirmed', spec: `<div class="toast success" style="box-shadow:none">${ic('check-circle')}<div><b>Recorded</b><div>Aroha’s vitamin D — taken with prompting at 9:04 am.</div></div></div>${dbadge('prompted')}`, wording: ['Recorded', 'Aroha’s vitamin D — taken with prompting at 9:04 am.'], when: 'The server saved the record. Only now does the row show the outcome.', treatment: 'Success toast plus the row re-read from the server. Focus returns to the row.', reuse: R.record, depends: [], fixes: ['EM-26'] },
            { id: 'rec-queued', name: 'Queued offline', spec: `<div style="display:flex;flex-direction:column;gap:6px">${dbadge('queued')}<span class="state-line">Saved on this device · not sent yet</span><div class="banner warning"><span class="b-ico">${ic('wifi-off')}</span><div class="b-body"><div class="b-title">Saved on this device only</div><div class="b-text">It isn’t on Aroha’s chart yet and other staff can’t see it. It will send when you reconnect — don’t record it again.</div></div></div></div>`, wording: ['Saved on this device', 'Saved on this device · not sent yet', 'It isn’t on Aroha’s chart yet and other staff can’t see it. It will send when you reconnect — don’t record it again.'], when: 'Offline, if offline recording is kept (D7: screen scope decided; offline expectation still open).', treatment: 'Warning. Stays visible to the person who recorded it until it sends or is refused. If offline recording is not kept, recording pauses and the print pack is offered instead.', reuse: R.record, depends: ['D7', 'D11'], fixes: ['EM-26'], link: { href: hrefFrame('sw', 'today', 'schedule') + '?scenario=offline', label: 'Try the offline scenario' } },
            { id: 'rec-rejected', name: 'Rejected (not recorded)', spec: `<div style="display:flex;flex-direction:column;gap:6px">${dbadge('rejected')}<div class="banner critical"><span class="b-ico">${ic('x-circle')}</span><div class="b-body"><div class="b-title">Not recorded</div><div class="b-text">Paracetamol for Aroha wasn’t saved: the as-needed limit on the prescription is reached. The chart doesn’t show this dose. If it was already given, tell the house lead now so it can be recorded correctly.</div></div></div></div>`, wording: ['Not recorded', 'Paracetamol for Aroha wasn’t saved: the as-needed limit on the prescription is reached.', 'The chart doesn’t show this dose. If it was already given, tell the house lead now so it can be recorded correctly.'], when: 'The server refused the record (online or on replay): limit reached, order awaiting verification, competency, witness.', treatment: 'Critical. The dialog stays open with values kept; if closed, the item stays in a private “1 record wasn’t saved — action needed” list at the top of Meds today until someone acts on it.', never: '“PRN administration recorded.” for a refused record. Deleting a refused offline item with a generic toast.', reuse: R.record, depends: [], fixes: ['EM-26'], open: { label: 'Review the refused record', attrs: 'data-act="rejected" data-row="prn" data-fk="cat-rej"' } },
            { id: 'rec-corrected', name: 'Corrected (with lineage)', spec: lineage(), white: true, wording: ['Corrected', 'Now: Withheld · Vomit or nausea'], when: 'An approved correction replaced an outcome. The original record is kept and shown struck through.', treatment: 'Neutral badge on the current outcome; lineage in detail views, History and the audit trail. Counts once.', never: 'Turning “not given” into “given” by correction (existing rule) — use “Given after re-offer”.', reuse: ['CorrectionsReviewDialog', 'MedicationEventDrawer', 'Person record · History', 'Audit trail (P09)'], depends: [], fixes: ['NF-11'] },
        ].map(stateCard).join('');
    }

    function blockedCards() {
        const map = [
            ['notClockedIn', 'Aroha', 'Vitamin D'], ['notOnShift', 'Tama', 'Levetiracetam'], ['shiftEnded', 'Aroha', 'Metformin'], ['siteNotApproved', 'Ben', 'Metformin'],
            ['competencyExpired', 'Tama', 'Levetiracetam'], ['exemptionEnded', 'Tama', 'Levetiracetam'], ['restricted', 'Tama', 'Levetiracetam'], ['areaNotPassed', 'Tama', 'insulin glargine'],
            ['noWitness', 'Aroha', 'Methylphenidate'], ['awaitingVerification', 'Mele', 'Omeprazole'], ['covertMissing', 'Grace', 'Donepezil'], ['covertExpired', 'Grace', 'donepezil'],
            ['prnLimit', 'Aroha', 'Paracetamol'], ['safetyAllergy', 'Mele', 'Amoxicillin'], ['safetyContra', 'Mele', 'Amoxicillin'],
        ];
        return map.map(([k, pn, med]) => {
            const b = BLOCKS[k];
            const still = STILL[b.still] !== undefined ? STILL[b.still] : b.still;
            const plain = (s) => tpl(s, { p: pn, med }).replace(/<[^>]+>/g, '').replace(/\s+/g, ' ').trim();
            return stateCard({
                id: `block-${k.replace(/[A-Z]/g, (m) => '-' + m.toLowerCase())}`, name: plain(b.title), spec: blockedPanel(k, { p: pn, med, persona: 'sw' }),
                wording: [plain(b.title), plain(b.text), ...(still ? [plain(still)] : b.stillText ? [plain(b.stillText)] : []), 'Checked again when you save.'],
                next: b.next.map(plain).join(' · ') + (b.action ? ` · Button: “${b.action.label}”.` : '') + (b.breakglass ? ' Emergency-access holders also see a one-person emergency access route; nobody else is told it exists.' : '') + (b.override ? ' People allowed to override safety checks also see an override that needs a reason and is reviewed; nobody else sees it.' : ''),
                treatment: (b.safety ? 'Fixed solid critical safety surface (brand-independent). ' : 'Warning panel. ') + `Server rule kept: ${esc(b.gate)}` + (b.note ? ` ${esc(b.note)}` : ''),
                reuse: k === 'noWitness' ? [...R.blocked, 'CD dialogs (P07a)'] : k === 'awaitingVerification' ? [...R.blocked, 'VerifyOrderDialog (P04)'] : R.blocked,
                depends: b.depends, fixes: b.fixes,
                open: { label: 'Open “Why can’t I record this?”', attrs: `data-act="why-cat" data-block="${k}" data-p="${pn}" data-med="${esc(med)}" data-fk="cat-${k}"` },
            });
        }).join('') + stateCard({ id: 'block-emergency-route', name: 'Emergency access route (emergency-access holders only)', spec: `<div class="banner info"><span class="b-ico">${ic('lock')}</span><div class="b-body"><div class="b-title">Request emergency access for Ben</div><div class="b-text">It covers Ben only, lasts a limited time, is recorded and is reviewed afterwards. It never covers a whole round or a house.</div><div class="b-actions"><button class="btn btn-outline btn-sm" type="button" data-act="toast" data-msg="The request dialog is designed in P10.">Request emergency access for Ben</button></div></div></div>`, wording: ['Request emergency access for Ben', 'It covers Ben only, lasts a limited time, is recorded and is reviewed afterwards. It never covers a whole round or a house.'], when: 'Added under “not on your shift” and “access doesn’t include this house” — only for people who hold emergency access.', treatment: 'Info. Everyone else sees only the real route (clock in, ask the coordinator).', reuse: ['Blocked panels', 'Person record redirect (existing)', 'Emergency access (P10)'], depends: ['D2', 'D12'], fixes: ['EM-28', 'NF-12'] });
    }

    function allergyCards() {
        return [
            { id: 'allergy-recorded', name: 'Allergies recorded', spec: allergyBanner('recorded'), wording: ['Allergies: Penicillin (severe) · Latex (mild)', 'From the health profile · reviewed 12 August 2026 by Jordan Tipene'], when: 'The canonical allergy record (the health profile, D5) lists allergies.', treatment: 'Fixed solid critical safety surface, first thing under the identity header. A matching medicine blocks “given” (see Allergy match).', reuse: R.allergy, depends: ['D5'], fixes: ['EM-07'] },
            { id: 'allergy-none-recorded', name: 'No allergies recorded', spec: allergyBanner('none'), wording: ['No allergies recorded', 'This doesn’t mean Aroha has none — allergy status hasn’t been reviewed. Check with the house lead before giving a new medicine.'], when: 'The read succeeded, the list is empty and nobody has recorded “no known drug allergies”.', treatment: 'Warning. This replaces “No known medication allergies on file”.', never: '“No known allergies” or a green tick for an empty list.', reuse: R.allergy, depends: ['D5'], fixes: ['EM-07'] },
            { id: 'allergy-unavailable', name: 'Allergy information unavailable', spec: allergyBanner('unavailable'), wording: ['Allergy information couldn’t be loaded', 'Don’t assume Aroha has no allergies. Try again, or check the paper record before giving anything new.'], when: 'The allergy read failed or timed out. Whether “given” is paused while allergies can’t be read: Not configured (D5).', treatment: 'Critical, with Try again.', never: 'An empty list after an error.', reuse: R.allergy, depends: ['D5'], fixes: ['EM-07', 'EM-18'] },
            { id: 'allergy-nkda', name: 'No known drug allergies (only once D5 approves it)', spec: allergyBanner('nkda'), wording: ['No known drug allergies', 'Recorded on the health profile by Jordan Tipene, 12 August 2026.'], when: 'Only when someone explicitly recorded it on the canonical record, and only after D5 approves this status.', treatment: 'Neutral with a tick — never green success, because it is a recorded statement, not a safety check.', reuse: R.allergy, depends: ['D5'], fixes: ['EM-07'] },
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
            { id: 'time-header', name: 'Header zone and update time', spec: `<div class="eh-header" style="padding:12px 14px;border-radius:12px"><div class="eh-inner"><p class="eh-sub" style="margin:0">Kōwhai House · your shift 7:00 am–3:00 pm · times in NZDT (Pacific/Auckland)</p><div style="margin-top:8px"><button type="button" class="fchip" data-act="toast" data-msg="Filter menus are designed with each page package (mockup).">${ic('refresh', 's3')}Updated 9:12 am NZDT</button></div></div></div>`, wording: ['times in NZDT (Pacific/Auckland)', 'Updated 9:12 am NZDT'], when: 'Every medication page header.', reuse: R.time, depends: [], fixes: ['EM-02'] },
            { id: 'time-dst-start', name: 'Dose in the skipped hour (DST start)', spec: `<div style="display:flex;flex-direction:column;gap:6px">${dbadge('due')}<span class="state-line">Scheduled 2:30 am · Sunday 27 September 2026</span><div class="banner warning"><span class="b-ico">${ic('clock')}</span><div class="b-body"><div class="b-title">This time doesn’t exist today</div><div class="b-text">Clocks went forward at 2:00 am, so 2:30 am was skipped. How this dose is rescheduled: ${NC()}</div></div></div></div>`, wording: ['This time doesn’t exist today', 'Clocks went forward at 2:00 am, so 2:30 am was skipped. How this dose is rescheduled: Not configured'], when: 'A scheduled time falls in the skipped hour.', treatment: 'Warning; never silently moved.', reuse: R.time, depends: ['D4'], fixes: ['EM-02'] },
            { id: 'time-dst-end', name: 'Repeated hour (DST end)', spec: `<div class="card" style="overflow:hidden"><div class="dose-row" style="grid-template-columns:1fr 1fr"><span>${dbadge('given', true)} 2:30 am NZDT</span><span class="state-line">first 2:30 am</span></div><div class="dose-row" style="grid-template-columns:1fr 1fr"><span>${dbadge('given', true)} 2:30 am NZST</span><span class="state-line">second 2:30 am, one hour later</span></div></div>`, wording: ['2:30 am NZDT', '2:30 am NZST'], when: 'Any time inside the repeated hour on Sunday 4 April 2027.', treatment: 'The zone abbreviation is always shown in that hour, in rows, detail and exports.', reuse: R.time, depends: [], fixes: ['EM-02'] },
            { id: 'time-interval', name: 'Interval across a clock change', spec: `<div style="display:flex;flex-direction:column;gap:4px"><span class="state-line"><b>Last given 1:30 am NZST · 6 h ago</b></span><span class="state-line">Now 8:30 am NZDT · clocks went forward overnight</span></div>`, wording: ['Last given 1:30 am NZST · 6 h ago', 'clocks went forward overnight'], when: 'As-needed history, late doses and effect checks on a clock-change day.', treatment: 'Real elapsed hours (6), not wall-clock difference (7).', reuse: R.time, depends: ['D4'], fixes: ['EM-02'] },
        ].map(stateCard).join('');
    }

    function universalCards() {
        return [
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
        ['D3', 'Competency model: areas, pass mark, restriction meaning, per-task authority, exemption limits', 'Clinical governance / L&D', 'Needs decision (NF-03 rule: Stephan)', 'Competency expired/restricted/area not passed, exemption ended, My eligibility.'],
        ['D4', 'Timing rules: due window, late/early, time-critical, PRN interval and amount counting, re-offer, DST rescheduling', 'Prescriber/pharmacist advice + clinical governance', 'Needs decision', 'Not yet due / due / late boundary, not yet recorded, re-offer, PRN limit, DST start, follow-up defaults.'],
        ['D5', 'Allergy source of truth and status vocabulary', 'Health & Clinical owner', 'Needs decision', 'All allergy states; “No known drug allergies” hidden until approved.'],
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
        return stateCard({ id: 'not-configured', name: 'The “Not configured” pattern', spec: `<div style="display:flex;flex-direction:column;gap:8px"><span>On-call contact: ${NC()}</span><span>Late-dose instruction: ${NC()}</span><span>Support for this medicine: ${NC('Not recorded')}</span></div>`, wording: ['Not configured', 'Not recorded (a missing fact on a record, not a policy)'], when: 'An organisation value nobody has approved, or a record fact that is missing.', treatment: 'Neutral dashed chip with a settings icon. It fails closed: where safety depends on the value, the screen gives no instruction instead of a default. Settings managers will be able to set it once the setting exists (P11); nobody sees a “Set it” button before then.', never: 'A hard-coded default (“on-call nurse”, 95 % target, 120 min, 10/12 pass mark) shown as if approved.', reuse: ['Every package'], depends: [], fixes: ['EM-17'] })
            + `<div class="card" style="overflow:hidden"><div class="tbl-wrap"><table class="etable"><caption class="sr-only">Organisation decisions</caption><thead><tr><th scope="col">#</th><th scope="col">Decision</th><th scope="col">Suggested owner</th><th scope="col">Status</th><th scope="col">P00 states that wait on it</th></tr></thead><tbody>
                ${DECISIONS.map(([d, t, o, s, st]) => `<tr id="dec-${d.replace('*', '')}"><td>${dtag(d)}</td><td>${esc(t)}</td><td>${esc(o)}</td><td class="dec-status">${s.startsWith('Decided') ? `<span class="badge b-success sm">${esc(s)}</span>` : s.startsWith('Proposed') ? `<span class="badge b-info sm">${esc(s)}</span>` : `<span class="badge b-warning sm">${esc(s)}</span>`}</td><td>${esc(st)}</td></tr>`).join('')}
            </tbody></table></div></div>`;
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
            <aside class="card cat-index" aria-label="Catalogue sections"><h2>P00 v1 catalogue</h2><nav>${secs}</nav></aside>
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
            if (e.key === 'Escape') { e.preventDefault(); closeDialog(); }
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
                ['Restrictions', 'None'],
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
            title: 'Not recorded — action needed', icon: 'x-circle', desc: 'Aroha · Paracetamol 500 mg tablet · tried at 8:50 am',
            body: identityHeader('aroha', { compact: true, support: 'administer' }) + `<div class="banner critical" role="alert"><span class="b-ico">${ic('x-circle')}</span><div class="b-body"><div class="b-title">Not recorded</div><div class="b-text">Paracetamol for Aroha wasn’t saved: the as-needed limit on the prescription is reached (4 doses in the last 24 hours, the most recent at 8:05 am). The chart doesn’t show this dose.</div></div></div>
                <p style="margin:0;font-size:13px"><b>If the dose was already given</b>, tell the house lead now so it can be recorded correctly and followed up. <b>If it wasn’t given</b>, you can dismiss this.</p>
                <div class="annot"><div class="a-tag">${ic('info', 's3')}Design note</div>Replaces today’s false “PRN administration recorded.” (EM-26). The item stays in this private list until someone acts on it. Who is told is ${dtag('D12')}.</div>`,
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
        W = { rowKey, r, pp, block, step: opts.notGivenOnly ? 1 : 0, outcome: null, reason: '', note: '', error: null, sending: false, rejectedMsg: null, isPrn, reoffer, notGivenOnly: !!opts.notGivenOnly };
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
        const expired = S.scenario === 'competencyExpired';
        const givenLabel = s === 'prompt' ? 'Taken with prompting' : s === 'assist' ? 'Taken with assistance' : W.reoffer ? 'Given after re-offer' : 'Given';
        const givenKey = s === 'prompt' ? 'prompted' : s === 'assist' ? 'assisted' : W.reoffer ? 'reoffered' : 'given';
        const givenReason = blocked ? tpl(BLOCKS[blocked].title, { p: W.pp.pref, med: W.r.med }).replace(/<[^>]+>/g, '') : expired && givenKey === 'given' ? 'Your competency expired on 14 September 2026' : null;
        const opts = [
            { k: givenKey, l: givenLabel, d: s === 'prompt' ? `${W.pp.pref} took it after a reminder` : s === 'assist' ? 'You helped; the person took it' : W.reoffer ? 'Offered again and taken' : 'You gave the medicine', i: s === 'prompt' ? 'message' : s === 'assist' ? 'hand' : W.reoffer ? 'repeat' : 'check', disabled: givenBlocked || (expired && givenKey === 'given') || W.notGivenOnly, why: givenReason || (W.notGivenOnly ? 'Can’t be recorded as given right now' : null) },
        ];
        if (!W.isPrn && !W.reoffer) {
            opts.push({ k: 'refused', l: 'Refused', d: `${W.pp.pref} chose not to take it`, i: 'x' });
            opts.push({ k: 'withheld', l: 'Withheld', d: 'Not given, with a reason', i: 'pause' });
            opts.push({ k: 'away', l: 'Away', d: `${W.pp.pref} isn’t here`, i: 'log-out' });
        }
        return opts;
    }
    function renderWizard(first) {
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
        const expiredB = S.scenario === 'competencyExpired' && !W.block ? blockedPanel('competencyExpired', { p: pp.pref, med: r.med, persona: S.persona }) : '';
        const rej = W.rejectedMsg ? `<div class="banner critical" role="alert"><span class="b-ico">${ic('x-circle')}</span><div class="b-body"><div class="b-title">Not recorded</div><div class="b-text">${W.rejectedMsg} What you entered is kept. Nothing is on ${esc(pp.pref)}’s chart for this dose.</div></div></div>` : '';
        const sending = W.sending ? `<div class="banner info" role="status"><span class="b-ico"><span class="ring-spin"></span></span><div class="b-body"><div class="b-title">Sending — not yet confirmed</div><div class="b-text">Don’t close this window. Nothing shows as recorded until it’s confirmed.</div></div></div>` : '';
        if (W.step === 0) {
            body.innerHTML = `${idh}${al}${med}${late}${blk}${expiredB}`;
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
            body.innerHTML = `${identityHeader(pp.id, { compact: true, support: r.support })}${al}${sending}${rej}
                <div class="review-card"><h4>${ic('pill', 's35')}Medicine</h4><div class="rrow"><span>Medicine</span><span>${esc(r.med)} ${esc(r.str)}</span></div><div class="rrow"><span>Dose</span><span>${esc(r.dose)}</span></div></div>
                <div class="review-card"><h4>${ic('clipboard-check', 's35')}Outcome <button class="btn-link" type="button" data-act="wiz-step" data-step="1" style="margin-left:auto;font-size:12.5px">${ic('pencil', 's3')} Edit</button></h4><div class="rrow"><span>What happened</span><span>${esc(o.l)}</span></div>${W.reason ? `<div class="rrow"><span>Reason</span><span>${esc(W.reason)}</span></div>` : ''}<div class="rrow"><span>Time</span><span>${NOW} NZDT · Monday 28 September 2026</span></div>${W.note ? `<div class="rrow"><span>Note</span><span>${esc(W.note)}</span></div>` : ''}${W.outcome === 'refused' ? `<div class="rrow"><span>Follow-up</span><span>Owner Priya Shah · due 12:00 pm</span></div>` : ''}</div>
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
        else next = W.sending ? `<button class="btn btn-primary" type="button" disabled><span class="ring-spin" aria-hidden="true"></span>Sending…</button>` : `<button class="btn btn-primary" type="button" data-act="wiz-submit">${ic('check')}Record outcome</button>`;
        const hasBack = backBtn.includes('wiz-back');
        foot.innerHTML = `<div>${backBtn}</div><div class="end">${hasBack && !expiredNoAlt ? `<button class="btn btn-outline" type="button" data-act="close">Cancel</button>` : ''}${next}</div>`;
        if (!first) {
            const target = W.error ? $('#wiz-body [aria-invalid="true"], #wiz-body .tile:not([aria-disabled="true"])') : $('#wiz-body .tile:not([aria-disabled="true"]), #wiz-body select, #wiz-foot .btn-primary:not([disabled])');
            if (target) target.focus();
        }
        body.scrollTop = 0;
    }
    function outcomeFields() {
        if (W.outcome === 'withheld' || W.outcome === 'away') {
            const reasons = W.outcome === 'withheld' ? ['Doctor’s instruction', 'Fasting', 'Vomit or nausea', 'Medication unavailable', 'Safety check blocked it', 'Other'] : ['Social leave', 'Hospitalised', 'Transferred'];
            return `<div class="fgrid"><div class="field"><label for="w-reason">${W.outcome === 'withheld' ? 'Reason for withholding' : 'Where is ' + esc(W.pp.pref) + '?'} <span class="req">*</span></label><select id="w-reason" data-act="wiz-reason" ${W.error === 'reason' ? 'aria-invalid="true" aria-describedby="w-reason-e"' : ''}><option value="">Choose a reason</option>${reasons.map((x) => `<option${W.reason === x ? ' selected' : ''}>${x}</option>`).join('')}</select>${W.error === 'reason' ? `<span class="ferr" id="w-reason-e">${W.outcome === 'withheld' ? 'Choose a reason for withholding.' : 'Choose where ' + esc(W.pp.pref) + ' is.'}</span>` : ''}</div>
                <div class="field"><label for="w-note">Note <span class="who-sub">(optional)</span></label><textarea id="w-note" rows="2" data-act="wiz-note">${esc(W.note)}</textarea></div></div>${W.outcome === 'withheld' && W.block && BLOCKS[W.block].safety ? `<p class="text-caption" style="margin:0">Saving a withhold is always allowed while a safety check blocks “given”. Who is told: ${NC()}</p>` : ''}`;
        }
        if (W.outcome === 'refused') {
            return `<div class="fgrid"><div class="field"><label for="w-note">What happened <span class="who-sub">(optional)</span></label><textarea id="w-note" rows="2" data-act="wiz-note" placeholder="e.g. Grace said she felt fine and didn’t want it today">${esc(W.note)}</textarea></div>
                <div class="field"><span class="flabel">Follow-up</span><div class="medblock" style="padding:10px"><div class="owner"><span class="disc sm" aria-hidden="true">PS</span><div>Owner: Priya Shah<div class="o-sub">Due 12:00 pm · entered by you · no default rule (${NC()})</div></div></div></div></div></div>`;
        }
        if (W.outcome && ['given', 'prompted', 'assisted', 'reoffered'].includes(W.outcome)) {
            return `<div class="fgrid"><div class="field"><label for="w-time">Time ${W.outcome === 'given' || W.outcome === 'reoffered' ? 'given' : 'taken'} <span class="req">*</span></label><input id="w-time" value="${NOW}" inputmode="text"><span class="who-sub">NZDT · Monday 28 September 2026 · exact minute kept</span></div><div class="field"><label for="w-dose">Amount</label><input id="w-dose" value="${esc(W.r.dose)}" readonly aria-readonly="true"><span class="who-sub">Fixed by the order</span></div></div>`;
        }
        return '';
    }
    function wizNext() {
        if (W.step === 1) {
            if (!W.outcome) { W.error = 'outcome'; renderWizard(); return; }
            if ((W.outcome === 'withheld' || W.outcome === 'away') && !W.reason) { W.error = 'reason'; renderWizard(); $('#w-reason') && $('#w-reason').focus(); return; }
        }
        W.error = null; W.step = Math.min(2, W.step + 1); renderWizard();
    }
    function wizSubmit() {
        W.sending = true; W.rejectedMsg = null; renderWizard();
        setTimeout(() => {
            if (!W) return;
            const lbl = outcomeOptions().find((x) => x.k === W.outcome);
            if (S.scenario === 'reject' && !W.rejectedOnce) {
                W.sending = false; W.rejectedOnce = true;
                W.rejectedMsg = 'The server checked your eligibility again when you saved: your competency record changed at 9:10 am and no longer covers this dose.';
                W.step = 1; renderWizard();
                if (W.rowKey !== 'prn') { S.rows[W.rowKey] = { state: 'rejected', line: 'Tried 9:12 am · not saved — review needed' }; render(true); }
                return;
            }
            const offline = S.scenario === 'offline';
            const key = W.rowKey;
            const t = NOW;
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
            const content = S.mode === 'person' ? personPage() : S.mode === 'record' ? recordPage() : hubPage();
            app.innerHTML = `<div class="shell${isCollapsed() ? ' collapsed' : ''}">${topbar()}${sidebar()}<div class="main">${content}</div></div>`;
        }
        fitRails();
        if (keepScroll) window.scrollTo(0, y);
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
    function closeMore() { const p = $('#rail-more-pop'); if (p) p.remove(); $$('.rail-more').forEach((b) => b.setAttribute('aria-expanded', 'false')); }

    /* ───────────── events ───────────── */
    document.addEventListener('click', (e) => {
        const t = e.target.closest('[data-act]');
        if (!t) { if (!e.target.closest('#rail-more-pop')) closeMore(); return; }
        const act = t.dataset.act;
        const p = S.persona;
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
            case 'more-go': closeMore(); location.hash = t.dataset.href; break;
            case 'wiz-next': wizNext(); break;
            case 'wiz-back': W.error = null; W.step = Math.max(0, W.step - 1); renderWizard(); break;
            case 'wiz-step': { const s = +t.dataset.step; if (s <= W.step) { W.step = s; W.error = null; renderWizard(); } break; }
            case 'wiz-outcome': if (t.getAttribute('aria-disabled') === 'true') { toast('info', 'This outcome can’t be recorded right now — see the reason under it.'); break; } W.outcome = t.dataset.k; W.error = null; W.reason = W.outcome === 'withheld' && W.block && BLOCKS[W.block].safety ? 'Safety check blocked it' : W.reason; renderWizard(); { const b = $(`.tile[data-k="${W.outcome}"]`); if (b) b.focus(); } break;
            case 'wiz-submit': wizSubmit(); break;
            default: break;
        }
    });
    document.addEventListener('change', (e) => {
        const t = e.target;
        if (t.dataset.act === 'persona') { S.persona = t.value; S.rows = {}; const hubs = visibleHubs(S.persona); location.hash = S.mode === 'person' ? `#/person/${S.persona}/${S.pid}/${S.ptab}` : hrefFrame(S.persona, hubs[0].id); }
        if (t.dataset.act === 'scenario') { S.scenario = t.value; S.rows = {}; S.rejectedAcknowledged = false; S.clockedIn = t.value !== 'notClockedIn'; const base = location.hash.split('?')[0] || hrefFrame(S.persona, 'today', 'schedule'); history.replaceState(null, '', `${base}?scenario=${t.value}`); render(); }
        if (t.dataset.act === 'wiz-reason' && W) { W.reason = t.value; if (W.error === 'reason' && t.value) W.error = null; }
    });
    document.addEventListener('input', (e) => { if (e.target.dataset.act === 'wiz-note' && W) W.note = e.target.value; });
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
    });
    let rz;
    window.addEventListener('resize', () => { clearTimeout(rz); rz = setTimeout(() => { if (S.mode !== 'catalogue') { const want = isCollapsed(); const sh = $('.shell'); if (sh && sh.classList.contains('collapsed') !== want) render(true); else fitRails(); } }, 120); });

    // Deep-link a single dialog for screenshots: ?open=record:r2 | why:notClockedIn | prn | rejected | eligibility
    function openFromQuery() {
        const q = new URLSearchParams((location.hash.split('?')[1]) || '');
        const o = q.get('open'); if (!o) return;
        const [kind, arg, step] = o.split(':');
        if (kind === 'record') { openRecord(arg, { notGivenOnly: !!(MEDS[arg] && rowBlock(arg)) }); if (step) { W.step = +step; if (+step >= 1 && q.get('outcome')) W.outcome = q.get('outcome'); if (q.get('reason')) W.reason = q.get('reason'); if (q.get('err')) W.error = q.get('err'); renderWizard(); } }
        if (kind === 'reoffer') { openRecord('r4', { reoffer: true }); W.step = 1; renderWizard(); }
        if (kind === 'why') { const r = MEDS[arg]; if (r) openWhy(rowBlock(arg), { p: PEOPLE[r.pid].pref, med: r.med, time: r.slot, pid: r.pid, support: r.support, row: arg }); }
        if (kind === 'prn') openRecord('prn');
        if (kind === 'rejected') openRejected('prn');
        if (kind === 'eligibility') openEligibility();
        if (kind === 'find') openPalette();
    }

    if (!location.hash) history.replaceState(null, '', '#/frame/sw/today/schedule');
    parseHash();
    render();
    if (S.mode === 'catalogue' && S.section !== 'intro' && !S.only) { const sec = $(`#sec-${S.section}`); if (sec) sec.scrollIntoView({ block: 'start' }); }
    openFromQuery();
})();
