// Website copy from the approved client demo site (mockup v2, data.ts). It is
// written as the provider ("we") and is generic enough for any NZ supported
// living provider. Facts that belong to the fictional "Oblivion Living" (its
// regions, its team, its photos) are in `demo` and only show on the
// demonstration server (config('demo.site')); a client's own site never shows
// our demo people or photos.

/** Photos for the demonstration server, served from public/demo-site. */
export const demoPhotos = {
    home: {
        src: '/demo-site/support-home.jpg',
        alt: 'A young man hanging out his washing in the back garden while his support worker passes him pegs',
    },
    community: {
        src: '/demo-site/support-community.jpg',
        alt: 'An older man and his support worker sharing a coffee at a café table, his walker beside him',
    },
    respite: {
        src: '/demo-site/support-respite.jpg',
        alt: 'A sunny, quiet guest bedroom in a short-break home, looking out on native bush and tree ferns',
    },
    careers: {
        src: '/demo-site/careers-team.jpg',
        alt: 'Five members of our team talking and laughing over mugs of tea around a table',
    },
    whanau: {
        src: '/demo-site/about-whanau.jpg',
        alt: 'A family sharing an evening meal on the deck, passing dishes to a man in a wheelchair at the end of the table',
    },
    office: {
        src: '/demo-site/contact-house.jpg',
        alt: 'Our office: a white weatherboard house with a wooden ramp up to the front door',
    },
} as const;

/** The fictional demo provider's own facts (demonstration server only). */
export const demo = {
    regions: ['Kāpiti Coast', 'Porirua', 'Lower Hutt', 'Wellington'],
    team: [
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
    ],
} as const;

/** "Kāpiti Coast, Porirua, Lower Hutt and Wellington". */
export function regionList(regions: readonly string[]): string {
    return regions.length < 2
        ? (regions[0] ?? '')
        : `${regions.slice(0, -1).join(', ')} and ${regions[regions.length - 1]}`;
}

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
] as const;

export const services = [
    {
        key: 'home',
        title: 'Support at home',
        icon: 'Home',
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

// Real NZ pathways. Plain descriptions only — no eligibility rules, amounts
// or guarantees.
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

export const fundingSteps = [
    {
        title: 'Talk to us',
        body: 'Tell us what support you’re looking for. We’ll explain the pathways that might fit.',
    },
    {
        title: 'An assessment or approval',
        body: 'If a funder is involved, they assess your needs and decide what they’ll fund. We can help you prepare.',
    },
    {
        title: 'A clear proposal',
        body: 'We send you a written proposal: the support, the hours and the costs, and who pays for what.',
    },
    {
        title: 'Agree and start',
        body: 'Nothing starts until you, and the funder if there is one, have agreed the plan.',
    },
] as const;
