import MarketingLayout from '@/layouts/marketing-layout';
import React from 'react';

const Terms: React.FC = () => {
    return (
        <MarketingLayout
            title="Terms of Service"
            description="Terms of Service for Oblivion Findings - the conditions governing use of our platform."
        >
            <div className="mx-auto max-w-3xl">
                <h1 className="text-4xl font-bold tracking-tight text-foreground">
                    Terms of Service
                </h1>
                <p className="mt-4 text-muted-foreground">
                    Last updated:{' '}
                    {new Date().toLocaleDateString('en-GB', {
                        day: 'numeric',
                        month: 'long',
                        year: 'numeric',
                    })}
                </p>

                <div className="mt-12 space-y-10">
                    <section>
                        <h2 className="text-xl font-semibold text-foreground">
                            1. Agreement to Terms
                        </h2>
                        <p className="mt-4 leading-relaxed text-muted-foreground">
                            These Terms of Service ("Terms") govern your access
                            to and use of the Oblivion Findings platform,
                            website, and services (collectively, the "Services")
                            operated by Oblivion Findings ("we", "our", or
                            "us").
                        </p>
                        <p className="mt-4 leading-relaxed text-muted-foreground">
                            By accessing or using our Services, you agree to be
                            bound by these Terms. If you disagree with any part
                            of the terms, you may not access the Services.
                        </p>
                    </section>

                    <section>
                        <h2 className="text-xl font-semibold text-foreground">
                            2. Definitions
                        </h2>
                        <ul className="mt-4 list-inside list-disc space-y-2 text-muted-foreground">
                            <li>
                                <strong>"Client"</strong> means the supported
                                living provider or organisation that has entered
                                into an agreement with us.
                            </li>
                            <li>
                                <strong>"User"</strong> means any individual who
                                accesses or uses the Services, including staff
                                members authorised by the Client.
                            </li>
                            <li>
                                <strong>"Resident Data"</strong> means personal
                                information about individuals receiving care or
                                support services.
                            </li>
                            <li>
                                <strong>"Content"</strong> means all
                                information, data, text, software, graphics, and
                                other materials uploaded to the Services.
                            </li>
                        </ul>
                    </section>

                    <section>
                        <h2 className="text-xl font-semibold text-foreground">
                            3. Account Registration
                        </h2>
                        <p className="mt-4 leading-relaxed text-muted-foreground">
                            To use our Services, you must:
                        </p>
                        <ul className="mt-2 list-inside list-disc space-y-2 text-muted-foreground">
                            <li>Be at least 18 years of age</li>
                            <li>
                                Be authorised to act on behalf of a registered
                                care provider
                            </li>
                            <li>
                                Provide accurate, current, and complete
                                information
                            </li>
                            <li>
                                Maintain the security of your account
                                credentials
                            </li>
                            <li>
                                Promptly notify us of any unauthorised access
                            </li>
                        </ul>
                        <p className="mt-4 leading-relaxed text-muted-foreground">
                            We reserve the right to suspend or terminate
                            accounts that violate these Terms.
                        </p>
                    </section>

                    <section>
                        <h2 className="text-xl font-semibold text-foreground">
                            4. Service Description
                        </h2>
                        <p className="mt-4 leading-relaxed text-muted-foreground">
                            Oblivion Findings provides a digital operations
                            platform for supported living providers, including
                            but not limited to:
                        </p>
                        <ul className="mt-2 list-inside list-disc space-y-2 text-muted-foreground">
                            <li>Resident care record management</li>
                            <li>Visit and task scheduling</li>
                            <li>Medication administration records (eMAR)</li>
                            <li>Incident and safeguarding management</li>
                            <li>Staff rostering and timesheets</li>
                            <li>Compliance and reporting tools</li>
                        </ul>
                    </section>

                    <section>
                        <h2 className="text-xl font-semibold text-foreground">
                            5. Data Protection and Security
                        </h2>
                        <p className="mt-4 leading-relaxed text-muted-foreground">
                            As a data processor, we commit to:
                        </p>
                        <ul className="mt-2 list-inside list-disc space-y-2 text-muted-foreground">
                            <li>
                                Processing personal data only in accordance with
                                your instructions
                            </li>
                            <li>
                                Implementing appropriate technical and
                                organisational security measures
                            </li>
                            <li>
                                Ensuring staff confidentiality and providing
                                data protection training
                            </li>
                            <li>
                                Not engaging sub-processors without your prior
                                written consent
                            </li>
                            <li>
                                Assisting with privacy impact
                                assessments and breach notifications
                            </li>
                            <li>
                                Returning or deleting all personal data upon
                                termination
                            </li>
                        </ul>
                        <p className="mt-4 leading-relaxed text-muted-foreground">
                            Our Data Processing Agreement (DPA) forms part of
                            these Terms and governs how we handle personal data
                            on your behalf.
                        </p>
                    </section>

                    <section>
                        <h2 className="text-xl font-semibold text-foreground">
                            6. Your Responsibilities
                        </h2>
                        <p className="mt-4 leading-relaxed text-muted-foreground">
                            As a Client, you are responsible for:
                        </p>
                        <ul className="mt-2 list-inside list-disc space-y-2 text-muted-foreground">
                            <li>
                                Maintaining the accuracy of all data entered
                                into the system
                            </li>
                            <li>
                                Ensuring you have Information Privacy Principles basis for processing
                                resident data
                            </li>
                            <li>
                                Training your staff on proper use of the
                                platform
                            </li>
                            <li>
                                Complying with all applicable laws and
                                regulations (e.g., HealthCERT, Privacy Act 2020)
                            </li>
                            <li>
                                Obtaining necessary consents from residents
                                where required
                            </li>
                            <li>
                                Notifying us of any security incidents within 24
                                hours
                            </li>
                        </ul>
                    </section>

                    <section>
                        <h2 className="text-xl font-semibold text-foreground">
                            7. Acceptable Use
                        </h2>
                        <p className="mt-4 leading-relaxed text-muted-foreground">
                            You agree not to use the Services to:
                        </p>
                        <ul className="mt-2 list-inside list-disc space-y-2 text-muted-foreground">
                            <li>Violate any applicable laws or regulations</li>
                            <li>Infringe intellectual property rights</li>
                            <li>Transmit malware, viruses, or harmful code</li>
                            <li>
                                Attempt to gain unauthorised access to systems
                            </li>
                            <li>Harass, abuse, or harm others</li>
                            <li>
                                Store or process data not related to supported
                                living services
                            </li>
                        </ul>
                    </section>

                    <section>
                        <h2 className="text-xl font-semibold text-foreground">
                            8. Fees and Payment
                        </h2>
                        <p className="mt-4 leading-relaxed text-muted-foreground">
                            Fees for our Services are as specified in your Order
                            Form or pricing page. Unless otherwise stated:
                        </p>
                        <ul className="mt-2 list-inside list-disc space-y-2 text-muted-foreground">
                            <li>
                                Fees are billed monthly or annually in advance
                            </li>
                            <li>
                                You are billed based on the number of active
                                residents
                            </li>
                            <li>All fees are exclusive of VAT</li>
                            <li>
                                Payment is due within 14 days of invoice date
                            </li>
                            <li>
                                Late payments may incur interest at 8% above
                                Bank of England base rate
                            </li>
                        </ul>
                    </section>

                    <section>
                        <h2 className="text-xl font-semibold text-foreground">
                            9. Intellectual Property
                        </h2>
                        <p className="mt-4 leading-relaxed text-muted-foreground">
                            <strong>Our IP:</strong> All intellectual property
                            rights in the Services remain our property or that
                            of our licensors. These Terms do not grant you any
                            rights to use our trademarks, logos, or branding.
                        </p>
                        <p className="mt-4 leading-relaxed text-muted-foreground">
                            <strong>Your IP:</strong> You retain all rights to
                            the Content you upload to the Services. You grant us
                            a limited licence to use this Content solely to
                            provide and improve the Services.
                        </p>
                    </section>

                    <section>
                        <h2 className="text-xl font-semibold text-foreground">
                            10. Confidentiality
                        </h2>
                        <p className="mt-4 leading-relaxed text-muted-foreground">
                            Both parties agree to:
                        </p>
                        <ul className="mt-2 list-inside list-disc space-y-2 text-muted-foreground">
                            <li>
                                Maintain the confidentiality of all confidential
                                information
                            </li>
                            <li>
                                Use confidential information only for the
                                purposes of these Terms
                            </li>
                            <li>
                                Disclose confidential information only to
                                employees and contractors who need to know
                            </li>
                            <li>
                                Protect confidential information with at least
                                the same care as their own
                            </li>
                        </ul>
                    </section>

                    <section>
                        <h2 className="text-xl font-semibold text-foreground">
                            11. Limitation of Liability
                        </h2>
                        <p className="mt-4 leading-relaxed text-muted-foreground">
                            To the maximum extent permitted by law:
                        </p>
                        <ul className="mt-2 list-inside list-disc space-y-2 text-muted-foreground">
                            <li>
                                We shall not be liable for any indirect,
                                incidental, special, or consequential damages
                            </li>
                            <li>
                                Our total liability shall not exceed the fees
                                paid by you in the 12 months preceding the claim
                            </li>
                            <li>
                                Nothing in these Terms excludes liability for
                                death, personal injury, fraud, or fraudulent
                                misrepresentation
                            </li>
                        </ul>
                    </section>

                    <section>
                        <h2 className="text-xl font-semibold text-foreground">
                            12. Service Level Agreement
                        </h2>
                        <p className="mt-4 leading-relaxed text-muted-foreground">
                            We commit to maintaining the following service
                            levels:
                        </p>
                        <ul className="mt-2 list-inside list-disc space-y-2 text-muted-foreground">
                            <li>
                                <strong>Uptime:</strong> 99.9% monthly
                                availability (excluding scheduled maintenance)
                            </li>
                            <li>
                                <strong>Support Response:</strong> Initial
                                response within 4 business hours for critical
                                issues
                            </li>
                            <li>
                                <strong>Data Backup:</strong> Daily backups with
                                30-day retention
                            </li>
                            <li>
                                <strong>Disaster Recovery:</strong> Recovery
                                Point Objective (RPO) of 24 hours
                            </li>
                        </ul>
                    </section>

                    <section>
                        <h2 className="text-xl font-semibold text-foreground">
                            13. Term and Termination
                        </h2>
                        <p className="mt-4 leading-relaxed text-muted-foreground">
                            These Terms commence when you first access the
                            Services and continue until terminated. Either party
                            may terminate:
                        </p>
                        <ul className="mt-2 list-inside list-disc space-y-2 text-muted-foreground">
                            <li>Monthly plans: With 30 days written notice</li>
                            <li>
                                Annual plans: At the end of the current term
                                with 60 days notice
                            </li>
                            <li>
                                Immediately if the other party breaches these
                                Terms and fails to remedy within 14 days
                            </li>
                            <li>
                                Immediately if the other party becomes insolvent
                            </li>
                        </ul>
                        <p className="mt-4 leading-relaxed text-muted-foreground">
                            Upon termination, we will return or delete your data
                            in accordance with our data retention policy.
                        </p>
                    </section>

                    <section>
                        <h2 className="text-xl font-semibold text-foreground">
                            14. Force Majeure
                        </h2>
                        <p className="mt-4 leading-relaxed text-muted-foreground">
                            Neither party shall be liable for any failure or
                            delay in performing obligations due to circumstances
                            beyond their reasonable control, including acts of
                            God, war, terrorism, riots, embargoes, fire, flood,
                            or strikes.
                        </p>
                    </section>

                    <section>
                        <h2 className="text-xl font-semibold text-foreground">
                            15. Governing Law
                        </h2>
                        <p className="mt-4 leading-relaxed text-muted-foreground">
                            These Terms shall be governed by and construed in
                            accordance with the laws of England and Wales. Any
                            disputes shall be subject to the exclusive
                            jurisdiction of the courts of England and Wales.
                        </p>
                    </section>

                    <section>
                        <h2 className="text-xl font-semibold text-foreground">
                            16. Changes to Terms
                        </h2>
                        <p className="mt-4 leading-relaxed text-muted-foreground">
                            We may modify these Terms at any time. We will
                            notify you of material changes via email or through
                            the platform. Continued use of the Services after
                            changes constitutes acceptance of the revised Terms.
                        </p>
                    </section>

                    <section>
                        <h2 className="text-xl font-semibold text-foreground">
                            17. Contact Information
                        </h2>
                        <p className="mt-4 leading-relaxed text-muted-foreground">
                            If you have any questions about these Terms, please
                            contact us:
                        </p>
                        <ul className="mt-2 list-inside list-disc space-y-2 text-muted-foreground">
                            <li>
                                <strong>Email:</strong>{' '}
                                legal@oblivionfindings.co.uk
                            </li>
                            <li>
                                <strong>Address:</strong> London, United Kingdom
                            </li>
                        </ul>
                    </section>
                </div>
            </div>
        </MarketingLayout>
    );
};

export default Terms;
