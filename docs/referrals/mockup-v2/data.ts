// Fictional fixtures for the client demo website. No application requests.
// "Oblivion Living" is a made-up provider; every person, role, address and
// number here is invented. The site name itself comes from the brand slot
// (Settings › Branding), never from this file.

/** Settings › Branding fixtures: both logos, the hero photo (the sign-in
 *  brand half shows it) and one colour. Branding stores one hex, and the
 *  real `applyPalette()` derives every brand token from it, so these are the
 *  only colour values in the preview's code (the logos carry their own). */
const hero = './demo/hero-home.jpg';
export const brandSlots = {
    living: {
        label: 'Oblivion Living (demo)',
        branding: {
            name: 'Oblivion Living',
            logoUrl: './oblivion-living-logo.svg',
            logoDarkUrl: './oblivion-living-logo-dark.svg',
            heroUrl: hero,
        },
    },
    kowhai: {
        label: 'Another client (Kōwhai Living)',
        branding: {
            name: 'Kōwhai Living',
            logoUrl: './fictional-client-logo.svg',
            logoDarkUrl: './fictional-client-logo-dark.svg',
            heroUrl: hero,
        },
    },
    name: {
        label: 'Name only',
        branding: {
            name: 'Oblivion Living',
            logoUrl: null,
            logoDarkUrl: null,
            heroUrl: hero,
        },
    },
    none: {
        label: 'No client branding (today’s fallback)',
        branding: {
            name: null,
            logoUrl: null,
            logoDarkUrl: null,
            heroUrl: null,
        },
    },
} as const;
export type BrandSlotKey = keyof typeof brandSlots;

// One colour per client (Stephan, 21 Oct): the app works out the dark-mode
// shades itself (brand text lifts to L ≥ 0.70, fills clamp to L ≤ 0.50).
export const brandColours = {
    teal: { label: 'Oblivion Living teal (demo)', hex: '#0F766E' },
    gold: { label: 'Kōwhai gold', hex: '#A16207' },
    app: { label: 'App default (Journey purple)', hex: null },
} as const;
export type BrandColourKey = keyof typeof brandColours;

export const provider = {
    phone: '0800 000 000',
    phoneHref: 'tel:0800000000',
    email: 'kiaora@oblivionliving.example',
    hours: 'Monday to Friday, 8.30am to 5pm',
    address: ['Level 1, 18 Demo Street', 'Porirua 5022'],
    regions: ['Kāpiti Coast', 'Porirua', 'Lower Hutt', 'Wellington'],
};

export const photos = {
    hero: {
        src: './demo/hero-home.jpg',
        alt: 'A young woman in a wheelchair and her support worker laughing as they make a salad together in her kitchen',
    },
    home: {
        src: './demo/support-home.jpg',
        alt: 'A young man hanging out his washing in the back garden while his support worker passes him pegs',
    },
    community: {
        src: './demo/support-community.jpg',
        alt: 'An older man and his support worker sharing a coffee at a café table, his walker beside him',
    },
    respite: {
        src: './demo/support-respite.jpg',
        alt: 'A sunny, quiet guest bedroom in a short-break home, looking out on native bush and tree ferns',
    },
    careers: {
        src: './demo/careers-team.jpg',
        alt: 'Five members of our team talking and laughing over mugs of tea around a table',
    },
    whanau: {
        src: './demo/about-whanau.jpg',
        alt: 'A family sharing an evening meal on the deck, passing dishes to a man in a wheelchair at the end of the table',
    },
    office: {
        src: './demo/contact-house.jpg',
        alt: 'Our office: a white weatherboard house with a wooden ramp up to the front door',
    },
} as const;

export const helpOptions = [
    {
        value: 'support',
        title: 'Ask about support',
        body: 'For yourself or someone in your whānau. Tell us what matters and we’ll talk it through.',
        time: 'About 3 minutes',
        icon: 'HeartHandshake',
    },
    {
        value: 'referral',
        title: 'Make a professional referral',
        body: 'For NASC, health, ACC and agency staff referring someone with their agreement.',
        time: 'About 6 minutes',
        icon: 'Stethoscope',
    },
    {
        value: 'funding',
        title: 'Help with funding',
        body: 'Not sure how support is paid for? We’ll help you find the right pathway and next step.',
        time: 'About 3 minutes',
        icon: 'Wallet',
    },
    {
        value: 'callback',
        title: 'Request a callback',
        body: 'Rather talk first? Leave a name and a safe way to reach you. Nothing else is needed.',
        time: 'About 1 minute',
        icon: 'PhoneCall',
    },
] as const;
export type HelpOption = (typeof helpOptions)[number]['value'];

export const audiences = [
    { value: 'self', title: 'Myself', icon: 'User' },
    { value: 'family', title: 'A family or whānau member', icon: 'Users' },
    { value: 'supporter', title: 'Someone I support', icon: 'HandHeart' },
    {
        value: 'professional',
        title: 'A professional or agency referral',
        icon: 'Building2',
    },
] as const;

export const journey = [
    {
        title: 'Tell us what matters',
        body: 'Share your goals and how to reach you safely. You can ask about the process before sharing anyone’s details.',
    },
    {
        title: 'Explore support and funding',
        body: 'Your intake coordinator talks it through with you, checks what’s available and works through funding with you.',
    },
    {
        title: 'Agree a plan together',
        body: 'You review the proposed support, costs and responsibilities. Starting a conversation doesn’t commit you to anything.',
    },
    {
        title: 'Prepare for a safe start',
        body: 'Your plan, agreement, consent and support team are checked before a start date is confirmed with you.',
    },
];

export const services = [
    {
        key: 'home',
        title: 'Support at home',
        icon: 'Home',
        photo: photos.home,
        summary:
            'Support in your own home or a shared home, built around your routines and the things that matter to you.',
        includes: [
            'Everyday living: cooking, shopping, cleaning and budgeting',
            'Personal care, at the pace you choose',
            'Keeping on top of appointments, medicines and paperwork',
            'Overnight support, when it’s needed',
        ],
        forWhom:
            'Adults with a disability or a long-term health condition who want to live in their own place, or share a home with others.',
        funding:
            'Often funded by Disability Support Services after a NASC assessment, by ACC, through Individualised Funding, or privately.',
    },
    {
        key: 'community',
        title: 'In the community',
        icon: 'Trees',
        photo: photos.community,
        summary:
            'Getting out, staying connected and doing what you enjoy: study, work, sport, culture or time with friends.',
        includes: [
            'Building skills for work, study or volunteering',
            'Getting around by bus or train, and growing confident on your own',
            'Joining a club, marae, church or sports team',
            'Catching up with friends and whānau',
        ],
        forWhom:
            'Anyone who would like support to take part in their community, whether or not they get support at home.',
        funding:
            'Often part of a Disability Support Services or Individualised Funding allocation, or paid privately.',
    },
    {
        key: 'respite',
        title: 'Short breaks (respite)',
        icon: 'BedDouble',
        photo: photos.respite,
        summary:
            'Planned short stays, or breaks at home, that give you and your whānau time to rest.',
        includes: [
            'Planned short stays in a welcoming home',
            'Breaks at home, so a family carer can have time out',
            'Weekend and school-holiday breaks, booked ahead',
            'Your routines, medicines and preferences kept the same',
        ],
        forWhom:
            'People who live with family or whānau carers, and the carers who need a break.',
        funding:
            'Often funded from a respite allocation through your NASC, or paid privately.',
    },
] as const;
export type ServiceKey = (typeof services)[number]['key'];

// Real NZ pathways named in SPECIFICATION.md §2. Plain descriptions only —
// no eligibility rules, amounts or guarantees.
export const pathways = [
    {
        key: 'dss',
        title: 'Disability Support Services',
        icon: 'HeartHandshake',
        body: 'Funded disability support usually starts with a needs assessment by your local NASC (Needs Assessment and Service Coordination) organisation.',
        start: 'You, your GP or someone you trust can contact your NASC and ask for an assessment.',
        help: 'We can explain what to expect, help you prepare what matters to you, and follow up afterwards.',
    },
    {
        key: 'if',
        title: 'Individualised Funding',
        icon: 'Wallet',
        body: 'Lets you, or someone you choose, manage your support allocation flexibly through an Individualised Funding host.',
        start: 'Ask your NASC whether Individualised Funding is an option for your allocation.',
        help: 'We can check whether the support you want can be bought this way and give you a clear quote.',
    },
    {
        key: 'acc',
        title: 'ACC',
        icon: 'Stethoscope',
        body: 'If your support needs follow an injury, ACC may fund support at home or in the community.',
        start: 'Talk to your ACC recovery team or case owner. They arrange and approve the support.',
        help: 'We can talk with your recovery team about what we offer, with your agreement.',
    },
    {
        key: 'health',
        title: 'Health New Zealand | Te Whatu Ora',
        icon: 'Building2',
        body: 'Some support linked to health conditions, mental health or ageing is funded through Health New Zealand services.',
        start: 'Your GP or health team can usually point you to the right assessment service.',
        help: 'We can help you find the right contact and share what you need with them.',
    },
    {
        key: 'private',
        title: 'Paying privately',
        icon: 'Receipt',
        body: 'You can arrange and pay for support yourself, on its own or alongside funded support.',
        start: 'Make a referral or call us. We’ll talk about what you’d like first.',
        help: 'We’ll give you a written proposal with the costs before anything starts.',
    },
] as const;

export const values = [
    {
        title: 'Your life, your choices',
        body: 'You decide what a good life looks like. Our job is to support it, not to plan it for you.',
        icon: 'Compass',
    },
    {
        title: 'Whānau at the centre',
        body: 'Family, whānau and friends are part of the team when you want them to be.',
        icon: 'Users',
    },
    {
        title: 'Safe and steady',
        body: 'Familiar faces, careful handovers and support you can count on, day and night.',
        icon: 'ShieldCheck',
    },
    {
        title: 'Honest and clear',
        body: 'Plain answers about what we can do, what it costs and what happens next.',
        icon: 'MessageSquareText',
    },
] as const;

export const team = [
    {
        name: 'Mere Tānirau',
        role: 'General manager',
        body: 'Has led supported living services in the Wellington region for 15 years.',
    },
    {
        name: 'Sam Patel',
        role: 'Intake coordinator',
        body: 'The first person most people talk to. Sam answers every enquiry and referral.',
    },
    {
        name: 'Leilani Faleolo',
        role: 'Team leader, Porirua',
        body: 'Leads the Porirua team and looks after rosters, training and day-to-day support.',
    },
    {
        name: 'Jack Thompson',
        role: 'Quality and clinical lead',
        body: 'A registered nurse who keeps our health support, medicines and safety checks on track.',
    },
] as const;

// Field set from the real careers pages (JobListing / CareersApply props).
export type Job = {
    id: number;
    slug: string;
    title: string;
    position_role: string;
    employment_type: 'full_time' | 'part_time' | 'casual' | 'fixed_term';
    hours: string;
    site: { id: number; name: string };
    summary: string;
    description: string;
    responsibilities: string[];
    requirements: string[];
    screening_questions: string[];
    published_at: string;
    closing_at: string;
};

export const jobs: Job[] = [
    {
        id: 1,
        slug: 'support-worker-kapiti-coast',
        title: 'Support Worker',
        position_role: 'support_worker',
        employment_type: 'part_time',
        hours: '20 to 30 hours a week, including some weekends',
        site: { id: 1, name: 'Kāpiti Coast' },
        summary:
            'Support three adults in their shared home in Paraparaumu to live the lives they choose.',
        description:
            'You’ll join a small, steady team supporting three adults who share a home in Paraparaumu. Every day is different: cooking together, getting to work and appointments, a swim at the aquatic centre or a quiet night in. No experience? That’s fine. We’re looking for kindness, patience and good sense, and we’ll train you.',
        responsibilities: [
            'Support people with everyday living, personal care and medicines, the way they prefer',
            'Help people get out and about, and keep in touch with friends and whānau',
            'Write clear daily notes and handovers in our app',
            'Notice when something isn’t right and speak up',
        ],
        requirements: [
            'A full or restricted New Zealand driver licence',
            'The right to work in New Zealand',
            'Kindness, reliability and a sense of humour',
            'Experience is welcome but not needed. Paid training includes the Level 2 Health and Wellbeing certificate',
        ],
        screening_questions: [
            'Which days and times can you usually work?',
            'Tell us about a time you helped someone do something for themselves.',
        ],
        published_at: '13 October 2026',
        closing_at: '14 November 2026',
    },
    {
        id: 2,
        slug: 'team-leader-porirua',
        title: 'Team Leader',
        position_role: 'team_leader',
        employment_type: 'full_time',
        hours: '40 hours a week, Monday to Friday, with an on-call share',
        site: { id: 2, name: 'Porirua' },
        summary:
            'Lead a team of 12 support workers across two homes in Porirua.',
        description:
            'You’ll lead a team of 12 support workers across two homes in Porirua, making sure the people we support get consistent, respectful support. You’ll coach your team, build rosters, keep support plans up to date with each person and their whānau, and be a calm voice when things get busy.',
        responsibilities: [
            'Lead, coach and roster a team of 12 support workers',
            'Review support plans with each person and their whānau',
            'Check notes, medicines records and incident reports, and follow up',
            'Share the on-call roster with the other team leaders',
        ],
        requirements: [
            'At least two years in disability or community support',
            'Experience leading or coaching a team',
            'A Level 4 Health and Wellbeing certificate, or willingness to work towards it',
            'A full New Zealand driver licence',
        ],
        screening_questions: [
            'Tell us about a time you helped a team through a difficult change.',
        ],
        published_at: '15 October 2026',
        closing_at: '7 November 2026',
    },
    {
        id: 3,
        slug: 'night-support-lower-hutt',
        title: 'Night Support',
        position_role: 'support_worker',
        employment_type: 'part_time',
        hours: 'Three awake nights a week, 10pm to 7am',
        site: { id: 3, name: 'Lower Hutt' },
        summary: 'Keep a home in Lower Hutt calm, safe and settled overnight.',
        description:
            'You’ll be the awake night support for four adults who share a home in Lower Hutt. Nights are mostly quiet: checks, a hand back to sleep, medicines when they’re needed and a careful handover in the morning. You’ll work alongside our on-call team leader, so you’re never on your own with a problem.',
        responsibilities: [
            'Do overnight checks the way each person has agreed',
            'Respond calmly when someone wakes or needs help',
            'Give overnight medicines when they’re needed, once you’re trained',
            'Write a clear handover for the morning team',
        ],
        requirements: [
            'Comfortable working awake nights',
            'The right to work in New Zealand',
            'A calm, steady approach',
            'Experience is welcome but not needed. We’ll train you',
        ],
        screening_questions: ['Have you worked night shifts before?'],
        published_at: '16 October 2026',
        closing_at: '21 November 2026',
    },
];
