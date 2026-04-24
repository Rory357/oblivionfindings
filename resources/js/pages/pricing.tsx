import MarketingLayout from '@/layouts/marketing-layout';
import { Link } from '@inertiajs/react';
import { ArrowRight, Check, HelpCircle, X } from 'lucide-react';
import React from 'react';

const Pricing: React.FC = () => {
    const plans = [
        {
            name: 'Starter',
            description: 'Perfect for small providers just getting started',
            price: '12',
            priceUnit: 'per resident/month',
            features: [
                'Up to 25 residents',
                'Core resident management',
                'Visit scheduling & tracking',
                'Digital notes & records',
                'Basic reporting',
                'Email support',
                'Unlimited staff accounts',
            ],
            notIncluded: [
                'Medication management (eMAR)',
                'Advanced compliance tools',
                'API access',
                'Dedicated account manager',
            ],
            cta: 'Get started',
            ctaLink: '/contact',
            popular: false,
        },
        {
            name: 'Professional',
            description: 'For growing services that need the full feature set',
            price: '18',
            priceUnit: 'per resident/month',
            features: [
                'Unlimited residents',
                'Everything in Starter, plus:',
                'Full eMAR medication management',
                'Safeguarding & incident management',
                'Staff rostering & timesheets',
                'Training & competency tracking',
                'Advanced analytics & reports',
                'Priority support',
                'Data migration assistance',
            ],
            notIncluded: ['White-label options', 'Custom integrations'],
            cta: 'Start free trial',
            ctaLink: '/contact',
            popular: true,
        },
        {
            name: 'Enterprise',
            description: 'For larger organisations with complex requirements',
            price: 'Custom',
            priceUnit: 'tailored pricing',
            features: [
                'Everything in Professional, plus:',
                'Multi-service management',
                'White-label options',
                'Custom integrations',
                'API access',
                'Dedicated account manager',
                'On-site training',
                'SLA guarantees',
                'Bespoke reporting',
            ],
            notIncluded: [],
            cta: 'Contact sales',
            ctaLink: '/contact',
            popular: false,
        },
    ];

    const faqs = [
        {
            question: 'How does the per-resident pricing work?',
            answer: 'You only pay for residents who are actively receiving support in a given month. If a resident moves out, you stop paying for them from the next billing cycle. There are no charges for staff accounts, family portal users, or administrators.',
        },
        {
            question: 'Is there a minimum contract length?',
            answer: 'No. Our monthly plans can be cancelled at any time with 30 days notice. Annual plans offer a discount and can be cancelled at the end of the term. We believe in earning your business every month.',
        },
        {
            question: 'What about setup and training?',
            answer: 'Starter and Professional plans include free remote onboarding and training sessions. Enterprise plans include on-site training. We also provide comprehensive documentation and video tutorials.',
        },
        {
            question: 'Can I import data from my current system?',
            answer: 'Yes. Professional plans include data migration assistance for standard formats (CSV, Excel). Enterprise plans include bespoke migration services for complex data structures or legacy systems.',
        },
        {
            question: 'Is my data secure?',
            answer: "Absolutely. We use bank-grade encryption, GDPR-compliant data handling, and UK-based servers. We're registered with the ICO and undergo regular security audits. Your data is never sold or shared.",
        },
        {
            question: 'What support do you offer?',
            answer: 'All plans include email support with guaranteed response times. Professional plans get priority support with faster response times. Enterprise plans get a dedicated account manager and 24/7 phone support.',
        },
    ];

    const comparisons = [
        {
            feature: 'Resident management',
            starter: true,
            professional: true,
            enterprise: true,
        },
        {
            feature: 'Visit scheduling',
            starter: true,
            professional: true,
            enterprise: true,
        },
        {
            feature: 'Digital notes',
            starter: true,
            professional: true,
            enterprise: true,
        },
        {
            feature: 'eMAR medication',
            starter: false,
            professional: true,
            enterprise: true,
        },
        {
            feature: 'Incident reporting',
            starter: 'Basic',
            professional: 'Advanced',
            enterprise: 'Advanced',
        },
        {
            feature: 'Staff rostering',
            starter: false,
            professional: true,
            enterprise: true,
        },
        {
            feature: 'Training tracking',
            starter: false,
            professional: true,
            enterprise: true,
        },
        {
            feature: 'Family portal',
            starter: false,
            professional: true,
            enterprise: true,
        },
        {
            feature: 'API access',
            starter: false,
            professional: false,
            enterprise: true,
        },
        {
            feature: 'Custom integrations',
            starter: false,
            professional: false,
            enterprise: true,
        },
    ];

    return (
        <MarketingLayout
            title="Pricing"
            description="Simple, transparent pricing for supported living providers. No hidden fees, no long-term contracts."
        >
            {/* Hero */}
            <section className="text-center">
                <h1 className="text-4xl font-bold tracking-tight text-foreground sm:text-5xl">
                    Simple, transparent pricing
                </h1>
                <p className="mx-auto mt-6 max-w-2xl text-lg text-muted-foreground">
                    No hidden fees, no long-term contracts. Pay only for the
                    residents you actively support, with unlimited staff
                    accounts on every plan.
                </p>
            </section>

            {/* Pricing Cards */}
            <section className="mt-16">
                <div className="grid gap-8 lg:grid-cols-3">
                    {plans.map((plan, index) => (
                        <div
                            key={index}
                            className={`group relative overflow-hidden rounded-3xl border p-8 transition-all hover:shadow-xl hover:shadow-primary/10 ${
                                plan.popular
                                    ? 'border-primary bg-card shadow-xl shadow-primary/10'
                                    : 'border-border bg-card hover:border-primary/30'
                            }`}
                        >
                            {/* Gloss overlay */}
                            <div className="pointer-events-none absolute inset-0 bg-gradient-to-br from-white/40 via-transparent to-transparent opacity-0 transition-opacity group-hover:opacity-100 dark:from-white/10" />
                            <div className="pointer-events-none absolute -inset-full top-0 block h-full w-1/2 -skew-x-12 bg-gradient-to-r from-transparent to-white/20 opacity-0 transition-all duration-700 group-hover:animate-shine" />
                            {plan.popular && (
                                <div className="absolute -top-4 left-1/2 -translate-x-1/2 rounded-full bg-primary px-4 py-1 text-sm font-medium text-primary-foreground">
                                    Most popular
                                </div>
                            )}

                            <div>
                                <h2 className="text-xl font-semibold text-foreground">
                                    {plan.name}
                                </h2>
                                <p className="mt-2 text-sm text-muted-foreground">
                                    {plan.description}
                                </p>
                            </div>

                            <div className="mt-6">
                                {plan.price === 'Custom' ? (
                                    <div className="text-4xl font-bold text-foreground">
                                        {plan.price}
                                    </div>
                                ) : (
                                    <div className="flex items-baseline gap-1">
                                        <span className="text-muted-foreground">
                                            £
                                        </span>
                                        <span className="text-4xl font-bold text-foreground">
                                            {plan.price}
                                        </span>
                                    </div>
                                )}
                                <p className="mt-1 text-sm text-muted-foreground">
                                    {plan.priceUnit}
                                </p>
                            </div>

                            <div className="mt-8">
                                <Link
                                    href={plan.ctaLink}
                                    className={`flex w-full items-center justify-center gap-2 rounded-full px-6 py-3 text-sm font-medium transition-all ${
                                        plan.popular
                                            ? 'bg-primary text-primary-foreground shadow-lg shadow-primary/25 hover:bg-primary/90'
                                            : 'border border-border bg-background text-foreground hover:bg-muted'
                                    }`}
                                >
                                    {plan.cta}
                                    <ArrowRight size={16} />
                                </Link>
                            </div>

                            <div className="mt-8 space-y-4">
                                <p className="text-sm font-medium text-foreground">
                                    Included:
                                </p>
                                <ul className="space-y-3">
                                    {plan.features.map((feature, i) => (
                                        <li
                                            key={i}
                                            className="flex items-start gap-3"
                                        >
                                            <Check
                                                size={18}
                                                className="mt-0.5 shrink-0 text-status-success"
                                            />
                                            <span className="text-sm text-muted-foreground">
                                                {feature}
                                            </span>
                                        </li>
                                    ))}
                                </ul>

                                {plan.notIncluded.length > 0 && (
                                    <>
                                        <p className="text-sm font-medium text-muted-foreground">
                                            Not included:
                                        </p>
                                        <ul className="space-y-3">
                                            {plan.notIncluded.map(
                                                (feature, i) => (
                                                    <li
                                                        key={i}
                                                        className="flex items-start gap-3"
                                                    >
                                                        <X
                                                            size={18}
                                                            className="mt-0.5 shrink-0 text-muted-foreground/50"
                                                        />
                                                        <span className="text-sm text-muted-foreground/70">
                                                            {feature}
                                                        </span>
                                                    </li>
                                                ),
                                            )}
                                        </ul>
                                    </>
                                )}
                            </div>
                        </div>
                    ))}
                </div>
            </section>

            {/* Comparison Table */}
            <section className="mt-24">
                <h2 className="text-center text-2xl font-bold text-foreground">
                    Compare plans
                </h2>
                <div className="mt-10 overflow-x-auto">
                    <table className="w-full border-collapse">
                        <thead>
                            <tr className="border-b border-border">
                                <th className="py-4 pr-4 text-left text-sm font-medium text-muted-foreground">
                                    Feature
                                </th>
                                <th className="px-4 py-4 text-center text-sm font-medium text-foreground">
                                    Starter
                                </th>
                                <th className="px-4 py-4 text-center text-sm font-medium text-primary">
                                    Professional
                                </th>
                                <th className="px-4 py-4 text-center text-sm font-medium text-foreground">
                                    Enterprise
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            {comparisons.map((row, index) => (
                                <tr
                                    key={index}
                                    className="border-b border-border"
                                >
                                    <td className="py-4 pr-4 text-sm text-foreground">
                                        {row.feature}
                                    </td>
                                    <td className="px-4 py-4 text-center">
                                        {typeof row.starter === 'boolean' ? (
                                            row.starter ? (
                                                <Check
                                                    size={18}
                                                    className="mx-auto text-status-success"
                                                />
                                            ) : (
                                                <X
                                                    size={18}
                                                    className="mx-auto text-muted-foreground/30"
                                                />
                                            )
                                        ) : (
                                            <span className="text-sm text-muted-foreground">
                                                {row.starter}
                                            </span>
                                        )}
                                    </td>
                                    <td className="bg-primary/5 px-4 py-4 text-center">
                                        {typeof row.professional ===
                                        'boolean' ? (
                                            row.professional ? (
                                                <Check
                                                    size={18}
                                                    className="mx-auto text-status-success"
                                                />
                                            ) : (
                                                <X
                                                    size={18}
                                                    className="mx-auto text-muted-foreground/30"
                                                />
                                            )
                                        ) : (
                                            <span className="text-sm text-muted-foreground">
                                                {row.professional}
                                            </span>
                                        )}
                                    </td>
                                    <td className="px-4 py-4 text-center">
                                        {typeof row.enterprise === 'boolean' ? (
                                            row.enterprise ? (
                                                <Check
                                                    size={18}
                                                    className="mx-auto text-status-success"
                                                />
                                            ) : (
                                                <X
                                                    size={18}
                                                    className="mx-auto text-muted-foreground/30"
                                                />
                                            )
                                        ) : (
                                            <span className="text-sm text-muted-foreground">
                                                {row.enterprise}
                                            </span>
                                        )}
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            </section>

            {/* FAQs */}
            <section className="mt-24">
                <h2 className="text-center text-2xl font-bold text-foreground">
                    Frequently asked questions
                </h2>
                <div className="mt-10 grid gap-6 md:grid-cols-2">
                    {faqs.map((faq, index) => (
                        <div
                            key={index}
                            className="group relative overflow-hidden rounded-2xl border border-border bg-card p-6 transition-all hover:border-primary/20 hover:shadow-lg hover:shadow-primary/5"
                        >
                            {/* Gloss overlay */}
                            <div className="pointer-events-none absolute inset-0 bg-gradient-to-br from-white/40 via-transparent to-transparent opacity-0 transition-opacity group-hover:opacity-100 dark:from-white/10" />
                            <div className="pointer-events-none absolute -inset-full top-0 block h-full w-1/2 -skew-x-12 bg-gradient-to-r from-transparent to-white/20 opacity-0 transition-all duration-700 group-hover:animate-shine" />
                            <div className="relative flex items-start gap-3">
                                <HelpCircle
                                    size={20}
                                    className="mt-0.5 shrink-0 text-primary"
                                />
                                <div>
                                    <h3 className="font-medium text-foreground">
                                        {faq.question}
                                    </h3>
                                    <p className="mt-2 text-sm text-muted-foreground">
                                        {faq.answer}
                                    </p>
                                </div>
                            </div>
                        </div>
                    ))}
                </div>
            </section>

            {/* CTA */}
            <section className="mt-24">
                <div className="rounded-3xl bg-gradient-to-r from-primary to-primary/90 px-6 py-12 sm:px-12 sm:py-16">
                    <div className="mx-auto max-w-3xl text-center">
                        <h2 className="text-2xl font-bold text-primary-foreground sm:text-3xl">
                            Not sure which plan is right for you?
                        </h2>
                        <p className="mt-4 text-primary-foreground/80">
                            Get in touch and we'll help you find the perfect fit
                            for your service.
                        </p>
                        <div className="mt-8 flex flex-col items-center justify-center gap-4 sm:flex-row">
                            <Link
                                href="/contact"
                                className="inline-flex items-center justify-center gap-2 rounded-full bg-background px-8 py-4 text-base font-medium text-foreground shadow-lg transition-all hover:bg-background/90"
                            >
                                Talk to our team
                                <ArrowRight size={18} />
                            </Link>
                        </div>
                    </div>
                </div>
            </section>
        </MarketingLayout>
    );
};

export default Pricing;
