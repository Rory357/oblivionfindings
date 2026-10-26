import {
    ArrowRight,
    HeartHandshake,
    Phone,
    ShieldCheck,
    Users,
    Wallet,
} from 'lucide-react';
import { useState } from 'react';
import { usePrototype } from './context';
import { makeFunding, makeReferral, pathways } from './data';
import { businessDate, eligibleOwners, routeIntake, stamp, uid } from './model';
import { Modal, Notice, Button as SharedButton, Wizard } from './ui';
const Button = ({ className = '', ...props }) => (
    <SharedButton {...props} className={'frontline-tap ' + className} />
);
const DRAFT = 'referrals-public-draft-v2';
const readReceipt = () => {
    try {
        return JSON.parse(sessionStorage.getItem('referrals-public-receipt'));
    } catch {
        return null;
    }
};
function readDraft() {
    try {
        const d = JSON.parse(sessionStorage.getItem(DRAFT));
        return d && d.expires > Date.now() ? d : null;
    } catch {
        return null;
    }
}
export function PublicView() {
    const { data, setData, navigate, failure, setFailure } = usePrototype();
    const [entry, setEntry] = useState(null),
        [draft, setDraft] = useState(readDraft),
        [receipt, setReceipt] = useState(
            () => data.receipts[readReceipt()?.token] || null,
        ),
        [info, setInfo] = useState(null),
        [retry, setRetry] = useState(''),
        [last, setLast] = useState(() =>
            readReceipt() ? { token: readReceipt().token } : null,
        );
    const config = data.config;
    const launch = (kind) => {
        setEntry({ kind, token: uid('submission') });
        setReceipt(null);
        sessionStorage.removeItem('referrals-public-receipt');
        setRetry('');
    };
    const jump = (id) => {
        setReceipt(null);
        sessionStorage.removeItem('referrals-public-receipt');
        requestAnimationFrame(() =>
            document.getElementById(id)?.scrollIntoView({
                behavior: matchMedia('(prefers-reduced-motion: reduce)').matches
                    ? 'auto'
                    : 'smooth',
            }),
        );
    };
    const clearDraft = () => {
        sessionStorage.removeItem(DRAFT);
        setDraft(null);
    };
    const submit = (v, token) => {
        const prior = data.receipts[token];
        if (prior) {
            setReceipt(prior);
            return {
                message:
                    'Already received as ' +
                    prior +
                    '. No second referral was created.',
            };
        }
        const self = v.relationship === 'For myself',
            general = !self && v.generalOnly;
        const contactName = self
            ? v.name
            : v.contactPerson === 'Person seeking support'
              ? v.name
              : v.referrer;
        const routing = routeIntake({ ...v, path: v.path }, config);
        const r = makeReferral({
            ...v,
            name: general ? 'General enquiry · ' + v.referrer : v.name,
            source: 'Website',
            kind: entry?.kind || v.kind,
            phone:
                self || v.contactPerson === 'Person seeking support'
                    ? v.phone
                    : '',
            email:
                self || v.contactPerson === 'Person seeking support'
                    ? v.email
                    : '',
            referrerRecord: self
                ? null
                : {
                      id: uid('contact'),
                      name: v.referrer,
                      organisation: v.organisation,
                      phone:
                          v.contactPerson === 'Person seeking support'
                              ? ''
                              : v.phone,
                      email:
                          v.contactPerson === 'Person seeking support'
                              ? ''
                              : v.email,
                      relationship: v.relationship,
                      authority: v.authority,
                      safe: v.safe,
                      verified: false,
                  },
            safeContactPerson: contactName,
            safeRecipientName: contactName,
            noticeVersion: config.noticeVersion,
            generalOnly: general,
            preferredContact: v.channel,
            safeContactPhone: v.phone,
            safeContactEmail: v.email,
            generalSummary: general
                ? 'Callback about the referral process'
                : `${v.service || 'Support'} enquiry — contact preferences recorded`,
            needs: v.needs || 'Discuss the referral process',
            needsAudience: 'Restricted assessment',
            authority: v.authority || 'To clarify',
            authorityScopes: { contact: true, disclose: false, service: false },
            notice: {
                version: config.noticeVersion,
                at: stamp(),
                method: 'Website acknowledgement',
                thirdParty: !self,
            },
            intakeAnswers: config.forms.map((f) => ({
                id: f.id,
                label: f.id === 'goal' ? config.customField : f.label,
                value: v['field_' + f.id] ?? '',
                sensitivity: f.sensitivity,
                destination: f.destination,
            })),
            ...routing,
            version: config.version,
            due: businessDate(config.firstContact, undefined, config),
            fundingSituation: v.fundingSituation || 'Not sure yet',
            funding:
                v.path && v.path !== 'Not sure yet' && !general
                    ? [
                          {
                              ...makeFunding(
                                  v.path,
                                  config.version,
                                  config.requirements[v.path],
                              ),
                              navigator: eligibleOwners(
                                  data,
                                  routing.site,
                              ).includes('Sam Patel')
                                  ? 'Sam Patel'
                                  : eligibleOwners(data, routing.site)[0],
                              due: businessDate(
                                  config.reviewDays,
                                  undefined,
                                  config,
                              ),
                              service: v.service || 'Supported living',
                              assistanceReason:
                                  'Funding assistance requested through the website; confirm the person’s wishes with their coordinator.',
                          },
                      ]
                    : [],
            next: 'Confirm safe contact, authority and the requested funding help',
        });
        const next = {
            ...data,
            records: [r, ...data.records],
            receipts: { ...data.receipts, [token]: r.id },
        };
        try {
            localStorage.setItem(
                'oblivion-referrals-prototype-v1',
                JSON.stringify(next),
            );
        } catch {
            return {
                error: 'Your enquiry could not be saved in this browser. Keep these details and retry, or use the callback option. No receipt has been issued.',
            };
        }
        setData(next);
        setReceipt(r.id);
        sessionStorage.setItem(
            'referrals-public-receipt',
            JSON.stringify({ id: r.id, token }),
        );
        setLast({ values: v, token });
        clearDraft();
        return {
            message: `Received as ${r.id}. ${routing.team} will follow your safe-contact instructions.`,
        };
    };
    return (
        <main className="public-page">
            <header className="public-nav">
                <Button variant="ghost" onClick={() => jump('public-home')}>
                    <span className="brand-ring" />
                    Oblivion Care
                </Button>
                <nav aria-label="Website navigation" className="actions">
                    <Button
                        variant="ghost"
                        onClick={() => jump('support-options')}
                    >
                        Our support
                    </Button>
                    <Button
                        variant="ghost"
                        onClick={() => jump('funding-options')}
                    >
                        Funding
                    </Button>
                    <Button
                        variant="ghost"
                        onClick={() => setInfo('About our support')}
                    >
                        About us
                    </Button>
                    <Button
                        variant="outline"
                        onClick={() => launch('Request a callback')}
                    >
                        <Phone />
                        Request a call
                    </Button>
                </nav>
            </header>
            {receipt ? (
                <section className="public-receipt">
                    <ShieldCheck className="text-status-success" />
                    <h1 className="text-page-title">
                        Your enquiry has been received
                    </h1>
                    <p>
                        Reference <strong>{receipt}</strong>. We will use your
                        nominated safe contact. Sample response target:{' '}
                        {config.firstContact} business day(s), during{' '}
                        {config.hours}.
                    </p>
                    <Notice>
                        No funding or place has been promised. Our intake team
                        will talk through your options.
                    </Notice>
                    {config.acknowledgement && (
                        <Notice>
                            Need help with funding? We can help you identify a
                            pathway, prepare information and follow up with the
                            appropriate agency. You can ask for this help even
                            if you do not have funding yet.
                            <Button
                                variant="link"
                                onClick={() => jump('funding-options')}
                            >
                                Explore funding assistance
                            </Button>
                        </Notice>
                    )}
                    <div className="actions">
                        <Button onClick={() => jump('support-options')}>
                            Back to support options
                        </Button>
                        <Button
                            variant="outline"
                            onClick={() => {
                                const result = submit(last.values, last.token);
                                setRetry(result.error || result.message);
                            }}
                        >
                            Retry the same submission
                        </Button>
                    </div>
                    {retry && <Notice>{retry}</Notice>}
                    <details>
                        <summary>Prototype walkthrough</summary>
                        <Button
                            variant="link"
                            onClick={() => navigate('Referrals', receipt)}
                        >
                            Follow this enquiry in the staff prototype{' '}
                            <ArrowRight />
                        </Button>
                    </details>
                </section>
            ) : (
                <>
                    <section id="public-home" className="public-hero">
                        <div>
                            <p className="text-caption">
                                SUPPORTED LIVING · AOTEAROA NEW ZEALAND
                            </p>
                            <h1>
                                A life that feels
                                <br />
                                more like yours.
                            </h1>
                            <p>
                                Practical, personal support to live with more
                                choice and independence. Start with a
                                conversation — for yourself or someone you
                                support.
                            </p>
                            <div className="actions">
                                <Button onClick={() => launch('Quick enquiry')}>
                                    Ask about support <ArrowRight />
                                </Button>
                                <Button
                                    variant="outline"
                                    onClick={() => launch('Funding help')}
                                >
                                    Help with funding
                                </Button>
                            </div>
                        </div>
                        <aside className="public-feature">
                            <HeartHandshake />
                            <h2 className="text-page-title">
                                Start where you are.
                            </h2>
                            <p>
                                You do not need a funding decision, diagnosis or
                                full assessment to contact us.
                            </p>
                            <ol>
                                <li>Tell us what matters to you.</li>
                                <li>Meet your intake coordinator.</li>
                                <li>Explore support and funding together.</li>
                            </ol>
                            <Button
                                variant="link"
                                onClick={() => setInfo('How referrals work')}
                            >
                                How referrals work <ArrowRight />
                            </Button>
                        </aside>
                    </section>
                    {draft && (
                        <Notice>
                            You have a saved enquiry in this browser tab. It
                            expires after 12 hours.
                            <div className="actions">
                                <Button
                                    variant="outline"
                                    onClick={() =>
                                        setEntry({
                                            kind: draft.kind,
                                            token: draft.token,
                                            resume: true,
                                        })
                                    }
                                >
                                    Resume enquiry
                                </Button>
                                <Button variant="ghost" onClick={clearDraft}>
                                    Discard saved enquiry
                                </Button>
                            </div>
                        </Notice>
                    )}
                    <section id="support-options" className="public-options">
                        <h2 className="text-page-title">How can we help?</h2>
                        <p className="text-subtle">
                            Choose a short enquiry, professional referral or a
                            callback about the process.
                        </p>
                        <div className="public-grid">
                            {[
                                [
                                    'Quick enquiry',
                                    'For yourself or whānau',
                                    'Talk about your goals and the support you want.',
                                ],
                                [
                                    'Professional referral',
                                    'For professionals',
                                    'Refer a person, with clear authority and safe-contact details.',
                                ],
                                [
                                    'Request a callback',
                                    'Not sure where to begin?',
                                    'Ask about the process without identifying another person.',
                                ],
                            ].map(([kind, title, description]) => (
                                <section className="public-option" key={kind}>
                                    <Users />
                                    <h3 className="text-section-title">
                                        {title}
                                    </h3>
                                    <p>{description}</p>
                                    <Button
                                        variant="outline"
                                        onClick={() => launch(kind)}
                                    >
                                        {kind}
                                        <ArrowRight />
                                    </Button>
                                </section>
                            ))}
                        </div>
                        <h2 className="text-section-title mt-5">
                            Explore our support
                        </h2>
                        <div className="actions">
                            {config.services.map((s) => (
                                <Button
                                    key={s}
                                    variant="outline"
                                    onClick={() => setInfo(s)}
                                >
                                    {s}
                                    <ArrowRight />
                                </Button>
                            ))}
                        </div>
                        <p className="text-subtle">
                            Sample service areas: {config.regions.join(' · ')}.
                            Availability is confirmed with you.
                        </p>
                    </section>
                    <section id="funding-options" className="public-options">
                        <h2 className="text-page-title">
                            Help to find a funding pathway
                        </h2>
                        <p>
                            We can help you prepare questions, gather
                            information and follow up with the relevant
                            organisation. Decisions are made by the funder or
                            payer.
                        </p>
                        <div className="actions">
                            {pathways.map((p) => (
                                <Button
                                    key={p}
                                    variant="outline"
                                    onClick={() => setInfo(p)}
                                >
                                    <Wallet />
                                    {p}
                                </Button>
                            ))}
                        </div>
                        <Button onClick={() => launch('Funding help')}>
                            Start a funding conversation <ArrowRight />
                        </Button>
                    </section>
                    <section className="public-contact">
                        <ShieldCheck />
                        <div>
                            <h2 className="text-section-title">
                                Contact at a time that is safe for you
                            </h2>
                            <p>
                                {config.hours}. Sample target:{' '}
                                {config.firstContact} business day(s).
                            </p>
                            <p>
                                Prefer help completing the form? Request a
                                callback and tell us about language, interpreter
                                or communication preferences.
                            </p>
                            <Button
                                variant="outline"
                                onClick={() => launch('Request a callback')}
                            >
                                Request help with the form
                            </Button>
                            <p className="text-caption">
                                Fictional company configuration. This prototype
                                is not a live referral service or an urgent-help
                                channel.
                            </p>
                        </div>
                    </section>
                </>
            )}
            <footer className="public-footer">
                <span>Oblivion Care · Supported living</span>
                <div className="actions">
                    <Button
                        variant="link"
                        onClick={() => setInfo('Privacy and your information')}
                    >
                        Privacy
                    </Button>
                    <Button
                        variant="link"
                        onClick={() =>
                            setInfo('Accessibility and communication')
                        }
                    >
                        Accessibility
                    </Button>
                    <Button
                        variant="link"
                        onClick={() => launch('Request a callback')}
                    >
                        Contact us
                    </Button>
                </div>
            </footer>
            {info && (
                <Modal
                    title={info}
                    onClose={() => setInfo(null)}
                    actions={
                        <Button
                            onClick={() => {
                                setInfo(null);
                                launch(
                                    pathways.includes(info)
                                        ? 'Funding help'
                                        : 'Quick enquiry',
                                );
                            }}
                        >
                            Ask us about this <ArrowRight />
                        </Button>
                    }
                >
                    {info === 'Privacy and your information' ? (
                        <>
                            <p>
                                Notice {config.noticeVersion}. We use your
                                details to respond safely, discuss support and
                                establish appropriate authority before sharing
                                information. Only relevant staff should see your
                                information.
                            </p>
                            <p>
                                You can ask to correct information, change
                                contact preferences or discuss withdrawal of
                                permission with intake. Drafts are saved only
                                when you choose Save draft, in this tab for up
                                to 12 hours. Discard removes them.
                            </p>
                            <p>
                                This local prototype contains fictional examples
                                only. The company notice and contact details
                                must be approved before a live service launches.
                            </p>
                        </>
                    ) : info === 'Accessibility and communication' ? (
                        <>
                            <p>
                                These forms support keyboard navigation,
                                labelled fields, error messages and a review
                                step. Tell us whether you prefer phone, email,
                                an interpreter or another communication
                                arrangement.
                            </p>
                            <Button
                                variant="outline"
                                onClick={() => {
                                    setInfo(null);
                                    launch('Request a callback');
                                }}
                            >
                                Ask for form assistance
                            </Button>
                        </>
                    ) : pathways.includes(info) ? (
                        <>
                            <p>
                                {info === 'Personal income assistance'
                                    ? 'Personal income assistance is separate from purchasing authority for this provider’s services.'
                                    : 'We can help you explore this pathway, identify the appropriate contact, prepare information and track the next step.'}
                            </p>
                            <p>
                                We will clarify authority, the person's choice
                                and the relevant service arrangement. An enquiry
                                does not determine eligibility or guarantee
                                funding.
                            </p>
                            <p>
                                Agency, host and provider details will be
                                checked with you.{' '}
                                {config.pathwayDetails[info]?.eligibility || ''}
                            </p>
                        </>
                    ) : (
                        <>
                            <p>
                                We start with your goals, choices, communication
                                preferences and the people you want involved. We
                                then review suitable support, availability and
                                funding arrangements together.
                            </p>
                            <p>
                                Intake stays responsible until a named
                                coordinator takes over. You can ask for more
                                time, another option or closure at any stage.
                            </p>
                            <p>
                                Sample areas: {config.regions.join(', ')}.{' '}
                                {config.hours}.
                            </p>
                        </>
                    )}
                </Modal>
            )}
            {entry && (
                <Wizard
                    title={entry.kind}
                    description="Share only what is needed for a safe first conversation."
                    initial={
                        entry.resume
                            ? draft.values
                            : {
                                  relationship:
                                      entry.kind === 'Professional referral'
                                          ? 'Professional'
                                          : entry.kind === 'Request a callback'
                                            ? 'Other'
                                            : 'For myself',
                                  generalOnly:
                                      entry.kind === 'Request a callback',
                                  name: '',
                                  referrer: '',
                                  contactPerson: 'Referrer / caller',
                                  channel: 'Phone',
                                  leaveMessage: 'Do not leave a message',
                                  safe: '',
                                  fundingSituation:
                                      entry.kind === 'Funding help'
                                          ? 'I need help to get funding'
                                          : 'Not sure yet',
                                  path: 'Not sure yet',
                                  service: config.services[0],
                                  region: config.regions[0],
                                  kind: entry.kind,
                              }
                    }
                    steps={[
                        {
                            label: 'You & safe contact',
                            fields: [
                                {
                                    key: 'relationship',
                                    label: 'I am enquiring as',
                                    type: 'select',
                                    options: [
                                        'For myself',
                                        'Whānau / family',
                                        'Professional',
                                        'Other',
                                    ],
                                },
                                {
                                    key: 'generalOnly',
                                    label: 'I only want a callback about the process — no person’s details yet',
                                    type: 'check',
                                    when: (v) =>
                                        v.relationship !== 'For myself',
                                },
                                {
                                    key: 'name',
                                    label: 'Person seeking support — full name',
                                    required: true,
                                    when: (v) =>
                                        !v.generalOnly ||
                                        v.relationship === 'For myself',
                                },
                                {
                                    key: 'preferred',
                                    label: 'Person’s preferred name',
                                    when: (v) => !v.generalOnly,
                                },
                                {
                                    key: 'referrer',
                                    label: 'Your name (referrer / caller)',
                                    required: true,
                                    when: (v) =>
                                        v.relationship !== 'For myself',
                                },
                                {
                                    key: 'organisation',
                                    label: 'Your organisation (if applicable)',
                                    when: (v) =>
                                        v.relationship === 'Professional',
                                },
                                {
                                    key: 'contactPerson',
                                    label: 'Who should we contact?',
                                    type: 'select',
                                    options: [
                                        'Referrer / caller',
                                        'Person seeking support',
                                    ],
                                    when: (v) =>
                                        v.relationship !== 'For myself' &&
                                        !v.generalOnly,
                                },
                                {
                                    key: 'phone',
                                    label: 'Safe contact person’s phone',
                                    type: 'tel',
                                },
                                {
                                    key: 'email',
                                    label: 'Safe contact person’s email',
                                    type: 'email',
                                },
                                {
                                    key: 'channel',
                                    label: 'Preferred contact method',
                                    type: 'select',
                                    options: [
                                        'Phone',
                                        'Email',
                                        'Text callback request',
                                    ],
                                },
                                {
                                    key: 'leaveMessage',
                                    label: 'Permission to leave a brief callback message',
                                    type: 'select',
                                    options: [
                                        'Do not leave a message',
                                        'A brief message is safe',
                                    ],
                                },
                                {
                                    key: 'safe',
                                    label: 'When and how is it safe to contact this person?',
                                    required: true,
                                },
                            ],
                            validate: (v) =>
                                !v.phone && !v.email
                                    ? 'Provide a safe phone number or email.'
                                    : v.channel === 'Email' && !v.email
                                      ? 'Provide an email for your preferred contact method.'
                                      : [
                                              'Phone',
                                              'Text callback request',
                                          ].includes(v.channel) && !v.phone
                                        ? 'Provide a phone number for your preferred contact method.'
                                        : '',
                        },
                        {
                            label: 'Support & funding',
                            fields: [
                                {
                                    key: 'service',
                                    label: 'Support being considered',
                                    type: 'select',
                                    options: [
                                        ...config.services,
                                        'Not sure yet',
                                    ],
                                    when: (v) => !v.generalOnly,
                                },
                                {
                                    key: 'needs',
                                    label: 'What would you like to discuss?',
                                    type: 'textarea',
                                    required: true,
                                },
                                {
                                    key: 'region',
                                    label: 'Where is support needed?',
                                    type: 'select',
                                    options: [
                                        ...config.regions,
                                        'Not sure / another region',
                                    ],
                                    when: (v) => !v.generalOnly,
                                },
                                {
                                    key: 'fundingSituation',
                                    label: 'Your funding situation',
                                    type: 'select',
                                    options: [
                                        'Not sure yet',
                                        'I need help to get funding',
                                        'Assessment / application underway',
                                        'I have an allocation / approval',
                                        'Private payment',
                                    ],
                                    when: (v) => !v.generalOnly,
                                },
                                {
                                    key: 'path',
                                    label: 'Pathway to explore (if known)',
                                    type: 'select',
                                    options: ['Not sure yet', ...pathways],
                                    when: (v) => !v.generalOnly,
                                },
                                {
                                    key: 'funder',
                                    label: 'Agency / host and current arrangement',
                                    when: (v) =>
                                        !v.generalOnly &&
                                        [
                                            'I have an allocation / approval',
                                            'Assessment / application underway',
                                        ].includes(v.fundingSituation),
                                },
                                ...config.forms.map((f) => ({
                                    key: 'field_' + f.id,
                                    label:
                                        f.id === 'goal'
                                            ? config.customField
                                            : f.label,
                                    type: f.type,
                                    required: f.required,
                                    options: (f.options || '').split('|'),
                                    when: (v) =>
                                        !v.generalOnly &&
                                        (f.condition === 'Always' ||
                                            ([
                                                'Funding help',
                                                'Funding help requested',
                                            ].includes(f.condition) &&
                                                v.fundingSituation ===
                                                    'I need help to get funding') ||
                                            ([
                                                'Professional',
                                                'Professional referrals',
                                            ].includes(f.condition) &&
                                                v.relationship ===
                                                    'Professional')),
                                })),
                                {
                                    key: 'interpreter',
                                    label: 'Language / interpreter / communication preferences',
                                    when: () => config.requireInterpreter,
                                },
                            ],
                        },
                        {
                            label: 'Privacy & permission',
                            notice: `Privacy notice ${config.noticeVersion}. Staff will establish authority before disclosing information. No service or funding is approved by this enquiry.`,
                            fields: [
                                {
                                    key: 'authority',
                                    label: 'Person’s wishes and your authority (or what needs clarifying)',
                                    type: 'textarea',
                                    when: (v) =>
                                        v.relationship !== 'For myself' &&
                                        !v.generalOnly,
                                },
                                {
                                    key: 'privacy',
                                    label: 'I understand how these details will be used for this enquiry',
                                    type: 'check',
                                },
                                {
                                    key: 'safeConfirm',
                                    label: 'It is safe to contact the nominated person using these details',
                                    type: 'check',
                                },
                            ],
                            validate: (v) =>
                                !v.privacy || !v.safeConfirm
                                    ? 'Please acknowledge the notice and confirm safe contact.'
                                    : '',
                        },
                    ]}
                    onClose={() => setEntry(null)}
                    onDraft={(v) => {
                        const d = {
                            kind: entry.kind,
                            token: entry.token,
                            values: v,
                            expires: Date.now() + 12 * 3600000,
                        };
                        sessionStorage.setItem(DRAFT, JSON.stringify(d));
                        setDraft(d);
                    }}
                    failure={failure}
                    clearFailure={() => setFailure('Normal')}
                    submitLabel="Send enquiry to prototype"
                    onSave={(v) => submit(v, entry.token)}
                />
            )}
        </main>
    );
}
