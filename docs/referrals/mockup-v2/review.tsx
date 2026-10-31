// Review chrome: the slim bar, brand controls and the "How this works"
// panel. None of this is part of the client's page.
import { applyPalette } from '@/lib/derive-palette';
import { setMockBranding } from '@inertiajs/react';
import {
    useEffect,
    useLayoutEffect,
    useRef,
    useState,
    useSyncExternalStore,
} from 'react';
import {
    brandColours,
    brandSlots,
    type BrandColourKey,
    type BrandSlotKey,
} from './data';

/* ── Review notices (what the real app would do next) ───────────────── */

let notice: string | null = null;
const noticeListeners = new Set<() => void>();
export function showReviewNotice(text: string | null) {
    notice = text;
    noticeListeners.forEach((listener) => listener());
}
function useReviewNotice() {
    return useSyncExternalStore(
        (listener) => {
            noticeListeners.add(listener);
            return () => noticeListeners.delete(listener);
        },
        () => notice,
    );
}

/* ── What happens behind each page ───────────────────────────────────── */

export type HowKey =
    | 'home'
    | 'content'
    | 'funding'
    | 'referral'
    | 'contact'
    | 'careers'
    | 'careers-status'
    | 'upload'
    | 'sign-in'
    | 'legal';

const how: Record<HowKey, { title: string; points: string[] }> = {
    home: {
        title: 'Home',
        points: [
            'This is the client’s own website. The name, both logos and the brand colour come from Settings › Branding. Nothing on the page is fixed to one provider: try the brand slot and colour above.',
            'Every “Make a referral” button opens the referral form. Contact us, Careers and Sign in are separate flows. Open each page to see what it does behind the scenes.',
            'The footer’s “Powered by oblivion care” line is the only mention of Oblivion Care. It shows once a client has set its own name or logo.',
        ],
    },
    content: {
        title: 'Content page',
        points: [
            'A plain content page in the provider’s voice. The words and photos here are demo content for the client to replace.',
            'Each “Ask about…” button opens Make a referral with the matching option already chosen.',
        ],
    },
    funding: {
        title: 'Funding help',
        points: [
            'A content page that names the real NZ pathways (Disability Support Services via NASC, Individualised Funding, ACC, Health NZ, private) without eligibility rules, amounts or timeframes.',
            '“Get help with funding” opens Make a referral with “Help with funding” chosen, so it reaches the same intake team.',
        ],
    },
    referral: {
        title: 'Make a referral',
        points: [
            'Sending creates a referral in the staff Referrals workspace, in the New queue.',
            'The person gets a reference straight away. If the acknowledgement email is turned on in Settings › Referrals, they get an email too.',
            'Callback requests and funding questions use the same form, so they reach the same intake team. Closing the form part-way asks before anything is discarded.',
        ],
    },
    contact: {
        title: 'Contact us',
        points: [
            'A message arrives in referral intake as a general enquiry, in its own queue. It isn’t counted as a referral.',
            'General enquiries get ENQ- references so staff can tell them apart from referrals.',
            'Choosing “Ask about support”, “Make a referral” or “A careers question” suggests the better form, but the person can still send the message.',
            'The reply promise is two working days. Send it empty first to see the error messages.',
        ],
    },
    careers: {
        title: 'Careers',
        points: [
            'An application goes to HR › Recruitment as a new applicant for that job posting.',
            'The applicant gets an emailed status link (“Check application status” after sending) that shows where it’s up to.',
            'Roles are the job postings HR publishes in Recruitment. Send an empty application to see how it jumps back to what’s missing.',
        ],
    },
    'careers-status': {
        title: 'Application status',
        points: [
            'The page the applicant’s emailed status link opens. It shows the candidate-facing stage only, never HR’s notes.',
            'The stage follows the applicant’s stage in HR › Recruitment.',
        ],
    },
    upload: {
        title: 'Secure upload',
        points: [
            'A coordinator requests a document from the referral. The person gets this link and, separately, a 6-digit access code.',
            'The file is held in quarantine until it is virus-scanned, then it is attached to the referral.',
            'Try the code 000000 to see the wrong-code message.',
        ],
    },
    'sign-in': {
        title: 'Sign in',
        points: [
            'Staff reach their workspace; family and whānau reach the family portal.',
            'Email first. Staff continue to a password (or their organisation’s Microsoft or Google sign-in); families are offered an emailed sign-in link first.',
            'The form half is the real sign-in page from the app, rendered with demo props. The brand half shows the client’s hero photo and logo from Settings › Branding, with “Back to website” at the top. The islands art is only Oblivion’s own default (choose “No client branding” to see it).',
        ],
    },
    legal: {
        title: 'Footer page',
        points: [
            'Placeholder text, marked on the page. The client supplies its own privacy statement, terms of use and accessibility statement before launch.',
        ],
    },
};

/* ── The bar ─────────────────────────────────────────────────────────── */

const routeGroups: { label: string; links: [string, string][] }[] = [
    {
        label: 'Site',
        links: [
            ['Home', '#/home'],
            ['Our support', '#/support'],
            ['Funding help', '#/funding'],
            ['About us', '#/about'],
            ['Careers', '#/careers'],
            ['Contact us', '#/contact'],
        ],
    },
    {
        label: 'Flows',
        links: [
            ['Make a referral', '#/referral'],
            ['Job + apply', '#/careers/support-worker-kapiti-coast/apply'],
            ['Application status', '#/careers/status'],
            ['Secure upload', '#/upload'],
            ['Staff sign in', '#/sign-in'],
            ['Family sign in', '#/sign-in/family'],
        ],
    },
    {
        label: 'Footer',
        links: [
            ['Privacy', '#/privacy'],
            ['Terms', '#/terms'],
            ['Accessibility', '#/accessibility'],
        ],
    },
];

export function ReviewBar({ hash, howKey }: { hash: string; howKey: HowKey }) {
    const [dark, setDark] = useState(false);
    const [slot, setSlot] = useState<BrandSlotKey>('living');
    const [colour, setColour] = useState<BrandColourKey>('teal');
    const [howOpen, setHowOpen] = useState(false);
    const current = useReviewNotice();
    const revert = useRef<(() => void) | null>(null);

    // Layout effects, so the first paint already wears the client's brand.
    useLayoutEffect(() => {
        setMockBranding({ ...brandSlots[slot].branding });
    }, [slot]);

    useLayoutEffect(() => {
        document.documentElement.classList.toggle('dark', dark);
        revert.current?.();
        revert.current = null;
        // The real Settings › Branding runtime: one hex in, every brand token
        // out. The same colour in both modes; the app works out the dark
        // shades itself (Stephan, 21 Oct).
        const hex = brandColours[colour].hex;
        if (hex) revert.current = applyPalette(hex);
    }, [dark, colour]);

    // A notice belongs to the page that raised it.
    useEffect(() => showReviewNotice(null), [hash]);

    const notes = how[howKey];
    return (
        <>
            <div className="mockup-bar" role="note">
                <strong>Client demo website · fictional data</strong>
                {routeGroups.map((group) => (
                    <span key={group.label} className="mockup-bar-group">
                        <span className="mockup-bar-label">{group.label}</span>
                        {group.links.map(([label, href]) => (
                            <a
                                key={href}
                                href={href}
                                aria-current={
                                    hash === href ? 'page' : undefined
                                }
                            >
                                {label}
                            </a>
                        ))}
                    </span>
                ))}
                <span className="mockup-bar-group">
                    <button
                        type="button"
                        aria-pressed={howOpen}
                        onClick={() => setHowOpen((value) => !value)}
                    >
                        How this works
                    </button>
                    <button
                        type="button"
                        aria-pressed={dark}
                        onClick={() => setDark((value) => !value)}
                    >
                        Toggle dark
                    </button>
                    <label>
                        Brand slot
                        <select
                            value={slot}
                            onChange={(event) =>
                                setSlot(event.target.value as BrandSlotKey)
                            }
                        >
                            {Object.entries(brandSlots).map(([key, value]) => (
                                <option key={key} value={key}>
                                    {value.label}
                                </option>
                            ))}
                        </select>
                    </label>
                    <label>
                        Brand colour
                        <select
                            value={colour}
                            onChange={(event) =>
                                setColour(event.target.value as BrandColourKey)
                            }
                        >
                            {Object.entries(brandColours).map(
                                ([key, value]) => (
                                    <option key={key} value={key}>
                                        {value.label}
                                    </option>
                                ),
                            )}
                        </select>
                    </label>
                </span>
            </div>
            {howOpen && (
                <section
                    className="mockup-how"
                    aria-label="How this works (review notes)"
                >
                    <p className="mockup-how-title">
                        How this works · {notes.title}
                    </p>
                    <ul>
                        {notes.points.map((point) => (
                            <li key={point}>{point}</li>
                        ))}
                    </ul>
                    <p className="mockup-how-foot">
                        Review notes only: never shown on the client’s site.
                    </p>
                </section>
            )}
            {current && (
                <div className="mockup-notice" role="status">
                    <span>{current}</span>
                    <button
                        type="button"
                        onClick={() => showReviewNotice(null)}
                    >
                        Dismiss
                    </button>
                </div>
            )}
        </>
    );
}
