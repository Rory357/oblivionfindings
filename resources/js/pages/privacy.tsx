import { PageHero, PageLayout } from '@/components/page';
import MarketingLayout from '@/layouts/marketing-layout';
import { Shield } from 'lucide-react';
import React from 'react';

const Privacy: React.FC = () => {
    const lastUpdated = new Date().toLocaleDateString('en-GB', {
        day: 'numeric',
        month: 'long',
        year: 'numeric',
    });

    return (
        <MarketingLayout
            title="Privacy Policy"
            description="Privacy Policy for Oblivion Findings - how we collect, use and protect your data."
        >
            <PageLayout
                padding="none"
                hero={
                    <PageHero
                        icon={Shield}
                        title="Privacy Policy"
                        description={`Last updated: ${lastUpdated}`}
                    />
                }
            >
                <div className="mx-auto max-w-3xl">
                    <div className="space-y-10">
                    <section>
                        <h2 className="text-xl font-semibold text-foreground">
                            1. Introduction
                        </h2>
                        <p className="mt-4 leading-relaxed text-muted-foreground">
                            Oblivion Findings ("we", "our", or "us") is
                            committed to protecting your privacy. This Privacy
                            Policy explains how we collect, use, disclose, and
                            safeguard your information when you use our
                            platform, website, and services (collectively, the
                            "Services").
                        </p>
                        <p className="mt-4 leading-relaxed text-muted-foreground">
                            We are registered with the Information
                            Commissioner's Office (ICO) and comply with the UK
                            General Data Protection Regulation (UK GDPR) and the
                            Data Protection Act 2018.
                        </p>
                    </section>

                    <section>
                        <h2 className="text-xl font-semibold text-foreground">
                            2. Information We Collect
                        </h2>

                        <h3 className="mt-4 font-medium text-foreground">
                            2.1 Information You Provide
                        </h3>
                        <ul className="mt-2 list-inside list-disc space-y-2 text-muted-foreground">
                            <li>
                                <strong>Account Information:</strong> Name,
                                email address, phone number, and organisation
                                details when you register.
                            </li>
                            <li>
                                <strong>Resident Data:</strong> Names, dates of
                                birth, medical information, care plans, and
                                support records entered by your organisation.
                            </li>
                            <li>
                                <strong>Staff Data:</strong> Employment details,
                                qualifications, training records, and shift
                                information.
                            </li>
                            <li>
                                <strong>Communication Data:</strong> Messages,
                                emails, and support requests you send us.
                            </li>
                        </ul>

                        <h3 className="mt-6 font-medium text-foreground">
                            2.2 Information We Automatically Collect
                        </h3>
                        <ul className="mt-2 list-inside list-disc space-y-2 text-muted-foreground">
                            <li>
                                <strong>Usage Data:</strong> How you interact
                                with our platform, including features used and
                                time spent.
                            </li>
                            <li>
                                <strong>Device Information:</strong> IP address,
                                browser type, operating system, and device
                                identifiers.
                            </li>
                            <li>
                                <strong>Location Data:</strong> GPS location
                                when using mobile check-in features (with your
                                consent).
                            </li>
                            <li>
                                <strong>Cookies:</strong> Information collected
                                through cookies and similar technologies.
                            </li>
                        </ul>
                    </section>

                    <section>
                        <h2 className="text-xl font-semibold text-foreground">
                            3. How We Use Your Information
                        </h2>
                        <p className="mt-4 leading-relaxed text-muted-foreground">
                            We use your information to:
                        </p>
                        <ul className="mt-2 list-inside list-disc space-y-2 text-muted-foreground">
                            <li>Provide, maintain, and improve our Services</li>
                            <li>
                                Process transactions and send related
                                information
                            </li>
                            <li>
                                Send technical notices, updates, and support
                                messages
                            </li>
                            <li>Respond to your comments and questions</li>
                            <li>
                                Monitor and analyse trends, usage, and
                                activities
                            </li>
                            <li>
                                Detect, prevent, and address technical issues
                                and fraud
                            </li>
                            <li>
                                Comply with legal obligations and regulatory
                                requirements
                            </li>
                        </ul>
                    </section>

                    <section>
                        <h2 className="text-xl font-semibold text-foreground">
                            4. Legal Basis for Processing
                        </h2>
                        <p className="mt-4 leading-relaxed text-muted-foreground">
                            We process personal data on the following legal
                            bases:
                        </p>
                        <ul className="mt-2 list-inside list-disc space-y-2 text-muted-foreground">
                            <li>
                                <strong>Contract:</strong> Processing necessary
                                for the performance of our contract with you.
                            </li>
                            <li>
                                <strong>Legal Obligation:</strong> Processing
                                necessary to comply with our legal obligations
                                (e.g., CQC requirements).
                            </li>
                            <li>
                                <strong>Legitimate Interests:</strong>{' '}
                                Processing necessary for our legitimate
                                interests, provided your rights don't override
                                these.
                            </li>
                            <li>
                                <strong>Consent:</strong> Where you have given
                                us explicit consent to process your data.
                            </li>
                        </ul>
                    </section>

                    <section>
                        <h2 className="text-xl font-semibold text-foreground">
                            5. Data Sharing and Disclosure
                        </h2>
                        <p className="mt-4 leading-relaxed text-muted-foreground">
                            We do not sell your personal data. We may share
                            information in the following circumstances:
                        </p>
                        <ul className="mt-2 list-inside list-disc space-y-2 text-muted-foreground">
                            <li>
                                <strong>Service Providers:</strong> With trusted
                                third parties who assist us in operating our
                                Services.
                            </li>
                            <li>
                                <strong>Legal Requirements:</strong> When
                                required by law, regulation, or legal process.
                            </li>
                            <li>
                                <strong>Business Transfers:</strong> In
                                connection with a merger, acquisition, or sale
                                of assets.
                            </li>
                            <li>
                                <strong>Protection:</strong> To protect our
                                rights, privacy, safety, or property.
                            </li>
                        </ul>
                        <p className="mt-4 leading-relaxed text-muted-foreground">
                            All third-party service providers are bound by data
                            processing agreements that ensure they meet UK GDPR
                            standards.
                        </p>
                    </section>

                    <section>
                        <h2 className="text-xl font-semibold text-foreground">
                            6. Data Security
                        </h2>
                        <p className="mt-4 leading-relaxed text-muted-foreground">
                            We implement appropriate technical and
                            organisational measures to protect your data:
                        </p>
                        <ul className="mt-2 list-inside list-disc space-y-2 text-muted-foreground">
                            <li>
                                End-to-end encryption for data in transit and at
                                rest
                            </li>
                            <li>
                                Role-based access controls and authentication
                            </li>
                            <li>
                                Regular security audits and penetration testing
                            </li>
                            <li>
                                UK-based data centres with ISO 27001
                                certification
                            </li>
                            <li>
                                Staff training on data protection and security
                            </li>
                            <li>
                                Incident response and breach notification
                                procedures
                            </li>
                        </ul>
                    </section>

                    <section>
                        <h2 className="text-xl font-semibold text-foreground">
                            7. Data Retention
                        </h2>
                        <p className="mt-4 leading-relaxed text-muted-foreground">
                            We retain personal data only for as long as
                            necessary to fulfil the purposes for which it was
                            collected, including:
                        </p>
                        <ul className="mt-2 list-inside list-disc space-y-2 text-muted-foreground">
                            <li>Providing our Services to you</li>
                            <li>
                                Complying with legal and regulatory requirements
                            </li>
                            <li>Resolving disputes and enforcing agreements</li>
                        </ul>
                        <p className="mt-4 leading-relaxed text-muted-foreground">
                            When data is no longer needed, it is securely
                            deleted or anonymised in accordance with our data
                            retention policy.
                        </p>
                    </section>

                    <section>
                        <h2 className="text-xl font-semibold text-foreground">
                            8. Your Rights
                        </h2>
                        <p className="mt-4 leading-relaxed text-muted-foreground">
                            Under UK GDPR, you have the following rights:
                        </p>
                        <ul className="mt-2 list-inside list-disc space-y-2 text-muted-foreground">
                            <li>
                                <strong>Right to Access:</strong> Request copies
                                of your personal data.
                            </li>
                            <li>
                                <strong>Right to Rectification:</strong> Request
                                correction of inaccurate data.
                            </li>
                            <li>
                                <strong>Right to Erasure:</strong> Request
                                deletion of your data in certain circumstances.
                            </li>
                            <li>
                                <strong>Right to Restrict Processing:</strong>{' '}
                                Request limitation of how we use your data.
                            </li>
                            <li>
                                <strong>Right to Data Portability:</strong>{' '}
                                Request transfer of your data to another
                                service.
                            </li>
                            <li>
                                <strong>Right to Object:</strong> Object to
                                processing based on legitimate interests.
                            </li>
                            <li>
                                <strong>
                                    Rights Related to Automated Decision-Making:
                                </strong>{' '}
                                Not to be subject to solely automated decisions.
                            </li>
                        </ul>
                        <p className="mt-4 leading-relaxed text-muted-foreground">
                            To exercise these rights, please contact us at
                            privacy@oblivionfindings.co.uk.
                        </p>
                    </section>

                    <section>
                        <h2 className="text-xl font-semibold text-foreground">
                            9. Cookies
                        </h2>
                        <p className="mt-4 leading-relaxed text-muted-foreground">
                            We use cookies and similar technologies to enhance
                            your experience, analyse usage, and assist in our
                            marketing efforts. You can control cookies through
                            your browser settings.
                        </p>
                    </section>

                    <section>
                        <h2 className="text-xl font-semibold text-foreground">
                            10. Changes to This Policy
                        </h2>
                        <p className="mt-4 leading-relaxed text-muted-foreground">
                            We may update this Privacy Policy from time to time.
                            We will notify you of any material changes by
                            posting the new policy on this page and updating the
                            "Last updated" date.
                        </p>
                    </section>

                    <section>
                        <h2 className="text-xl font-semibold text-foreground">
                            11. Contact Us
                        </h2>
                        <p className="mt-4 leading-relaxed text-muted-foreground">
                            If you have any questions about this Privacy Policy
                            or our data practices, please contact us:
                        </p>
                        <ul className="mt-2 list-inside list-disc space-y-2 text-muted-foreground">
                            <li>
                                <strong>Email:</strong>{' '}
                                privacy@oblivionfindings.co.uk
                            </li>
                            <li>
                                <strong>Data Protection Officer:</strong>{' '}
                                dpo@oblivionfindings.co.uk
                            </li>
                            <li>
                                <strong>Address:</strong> London, United Kingdom
                            </li>
                        </ul>
                        <p className="mt-4 leading-relaxed text-muted-foreground">
                            You also have the right to complain to the
                            Information Commissioner's Office (ICO) if you
                            believe we have not handled your data properly.
                        </p>
                    </section>
                    </div>
                </div>
            </PageLayout>
        </MarketingLayout>
    );
};

export default Privacy;
