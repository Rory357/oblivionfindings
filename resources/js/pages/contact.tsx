import MarketingLayout from '@/layouts/marketing-layout';
import { useForm } from '@inertiajs/react';
import {
    Building2,
    Loader2,
    Mail,
    MapPin,
    Phone,
    Send,
    User,
} from 'lucide-react';
import React, { FormEvent } from 'react';
import { toast } from 'sonner';

interface ContactFormData {
    name: string;
    email: string;
    company: string;
    phone: string;
    service_type: string;
    residents_count: string;
    message: string;
}

const Contact: React.FC = () => {
    const { data, setData, post, processing, errors, reset } =
        useForm<ContactFormData>({
            name: '',
            email: '',
            company: '',
            phone: '',
            service_type: '',
            residents_count: '',
            message: '',
        });

    const handleSubmit = (e: FormEvent) => {
        e.preventDefault();
        post('/contact', {
            onSuccess: () => {
                toast.success(
                    "Message sent successfully! We'll be in touch soon.",
                );
                reset();
            },
            onError: () => {
                toast.error('Please check the form for errors and try again.');
            },
        });
    };

    const contactInfo = [
        {
            icon: Mail,
            label: 'Email',
            value: 'hello@oblivionfindings.co.nz',
            href: 'mailto:hello@oblivionfindings.co.nz',
        },
        {
            icon: Phone,
            label: 'Phone',
            value: '09 123 4567',
            href: 'tel:+6491234567',
        },
        {
            icon: MapPin,
            label: 'Location',
            value: 'Auckland, New Zealand',
            href: '#',
        },
    ];

    const serviceTypes = [
        { value: '', label: 'Select your service type' },
        { value: 'supported_living', label: 'Supported Living' },
        { value: 'residential_care', label: 'Residential Care Home' },
        { value: 'domiciliary', label: 'Domiciliary Care' },
        { value: 'respite', label: 'Respite/Short Breaks' },
        { value: 'ld_services', label: 'Learning Disability Services' },
        { value: 'mental_health', label: 'Mental Health Services' },
        { value: 'other', label: 'Other' },
    ];

    const residentCounts = [
        { value: '', label: 'Number of residents' },
        { value: '1-10', label: '1-10 residents' },
        { value: '11-25', label: '11-25 residents' },
        { value: '26-50', label: '26-50 residents' },
        { value: '51-100', label: '51-100 residents' },
        { value: '100+', label: '100+ residents' },
    ];

    return (
        <MarketingLayout
            title="Contact"
            description="Get in touch with the Oblivion Findings team. Book a demo, ask questions, or learn more about our supported living platform."
        >
            {/* Hero */}
            <section className="text-center">
                <h1 className="text-4xl font-bold tracking-tight text-foreground sm:text-5xl">
                    Let's talk about your service
                </h1>
                <p className="mx-auto mt-6 max-w-2xl text-lg text-muted-foreground">
                    Whether you're looking for a demo, have questions about
                    pricing, or just want to learn more—we're here to help.
                </p>
            </section>

            {/* Contact Section */}
            <section className="mt-16">
                <div className="grid gap-10 lg:grid-cols-5">
                    {/* Contact Info Sidebar */}
                    <div className="lg:col-span-2">
                        <div className="group relative overflow-hidden rounded-3xl border border-border bg-card p-8 transition-all hover:border-primary/20 hover:shadow-lg hover:shadow-primary/5">
                            {/* Gloss overlay */}
                            <div className="pointer-events-none absolute inset-0 bg-gradient-to-br from-white/40 via-transparent to-transparent opacity-0 transition-opacity group-hover:opacity-100 dark:from-white/10" />
                            <div className="pointer-events-none absolute -inset-full top-0 block h-full w-1/2 -skew-x-12 bg-gradient-to-r from-transparent to-white/20 opacity-0 transition-all duration-700 group-hover:animate-shine" />
                            <h2 className="relative text-xl font-semibold text-foreground">
                                Get in touch
                            </h2>
                            <p className="mt-2 text-sm text-muted-foreground">
                                Our team typically responds within 24 hours
                                during business days.
                            </p>

                            <div className="relative mt-8 space-y-6">
                                {contactInfo.map((item, index) => {
                                    const Icon = item.icon;
                                    return (
                                        <a
                                            key={index}
                                            href={item.href}
                                            className="group/item flex items-center gap-4"
                                        >
                                            <div className="flex h-12 w-12 items-center justify-center rounded-xl bg-gradient-to-br from-primary/20 to-primary/5 text-primary shadow-inner shadow-primary/10 transition-colors group-hover/item:from-primary/30 group-hover/item:to-primary/10">
                                                <Icon size={20} />
                                            </div>
                                            <div>
                                                <p className="text-xs text-muted-foreground">
                                                    {item.label}
                                                </p>
                                                <p className="text-sm font-medium text-foreground">
                                                    {item.value}
                                                </p>
                                            </div>
                                        </a>
                                    );
                                })}
                            </div>

                            <div className="mt-10 border-t border-border pt-8">
                                <h3 className="text-sm font-medium text-foreground">
                                    What happens next?
                                </h3>
                                <ol className="mt-4 space-y-4">
                                    {[
                                        'We review your message within 24 hours',
                                        'A team member contacts you to understand your needs',
                                        'We schedule a personalised demo at your convenience',
                                        'You get a tailored quote based on your requirements',
                                    ].map((step, i) => (
                                        <li
                                            key={i}
                                            className="flex gap-3 text-sm text-muted-foreground"
                                        >
                                            <span className="flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-primary/10 text-xs font-medium text-primary">
                                                {i + 1}
                                            </span>
                                            <span>{step}</span>
                                        </li>
                                    ))}
                                </ol>
                            </div>
                        </div>
                    </div>

                    {/* Contact Form */}
                    <div className="lg:col-span-3">
                        <form
                            onSubmit={handleSubmit}
                            className="rounded-3xl border border-border bg-card p-8"
                        >
                            <h2 className="text-xl font-semibold text-foreground">
                                Send us a message
                            </h2>
                            <p className="mt-2 text-sm text-muted-foreground">
                                Fill out the form below and we'll get back to
                                you shortly.
                            </p>

                            <div className="mt-8 grid gap-6">
                                {/* Name & Email Row */}
                                <div className="grid gap-6 sm:grid-cols-2">
                                    <div>
                                        <label
                                            htmlFor="name"
                                            className="block text-sm font-medium text-foreground"
                                        >
                                            Full name{' '}
                                            <span className="text-status-critical">
                                                *
                                            </span>
                                        </label>
                                        <div className="relative mt-2">
                                            <User
                                                className="absolute top-1/2 left-3 -translate-y-1/2 text-muted-foreground"
                                                size={18}
                                            />
                                            <input
                                                type="text"
                                                id="name"
                                                value={data.name}
                                                onChange={(e) =>
                                                    setData(
                                                        'name',
                                                        e.target.value,
                                                    )
                                                }
                                                className="w-full rounded-xl border border-border bg-background px-10 py-3 text-sm text-foreground placeholder:text-muted-foreground focus:border-primary focus:ring-2 focus:ring-primary/20 focus:outline-none"
                                                placeholder="John Smith"
                                                required
                                            />
                                        </div>
                                        {errors.name && (
                                            <p className="mt-1 text-xs text-status-critical">
                                                {errors.name}
                                            </p>
                                        )}
                                    </div>

                                    <div>
                                        <label
                                            htmlFor="email"
                                            className="block text-sm font-medium text-foreground"
                                        >
                                            Email address{' '}
                                            <span className="text-status-critical">
                                                *
                                            </span>
                                        </label>
                                        <div className="relative mt-2">
                                            <Mail
                                                className="absolute top-1/2 left-3 -translate-y-1/2 text-muted-foreground"
                                                size={18}
                                            />
                                            <input
                                                type="email"
                                                id="email"
                                                value={data.email}
                                                onChange={(e) =>
                                                    setData(
                                                        'email',
                                                        e.target.value,
                                                    )
                                                }
                                                className="w-full rounded-xl border border-border bg-background px-10 py-3 text-sm text-foreground placeholder:text-muted-foreground focus:border-primary focus:ring-2 focus:ring-primary/20 focus:outline-none"
                                                placeholder="john@company.co.nz"
                                                required
                                            />
                                        </div>
                                        {errors.email && (
                                            <p className="mt-1 text-xs text-status-critical">
                                                {errors.email}
                                            </p>
                                        )}
                                    </div>
                                </div>

                                {/* Company & Phone Row */}
                                <div className="grid gap-6 sm:grid-cols-2">
                                    <div>
                                        <label
                                            htmlFor="company"
                                            className="block text-sm font-medium text-foreground"
                                        >
                                            Organisation name
                                        </label>
                                        <div className="relative mt-2">
                                            <Building2
                                                className="absolute top-1/2 left-3 -translate-y-1/2 text-muted-foreground"
                                                size={18}
                                            />
                                            <input
                                                type="text"
                                                id="company"
                                                value={data.company}
                                                onChange={(e) =>
                                                    setData(
                                                        'company',
                                                        e.target.value,
                                                    )
                                                }
                                                className="w-full rounded-xl border border-border bg-background px-10 py-3 text-sm text-foreground placeholder:text-muted-foreground focus:border-primary focus:ring-2 focus:ring-primary/20 focus:outline-none"
                                                placeholder="Acme Care Services"
                                            />
                                        </div>
                                        {errors.company && (
                                            <p className="mt-1 text-xs text-status-critical">
                                                {errors.company}
                                            </p>
                                        )}
                                    </div>

                                    <div>
                                        <label
                                            htmlFor="phone"
                                            className="block text-sm font-medium text-foreground"
                                        >
                                            Phone number
                                        </label>
                                        <div className="relative mt-2">
                                            <Phone
                                                className="absolute top-1/2 left-3 -translate-y-1/2 text-muted-foreground"
                                                size={18}
                                            />
                                            <input
                                                type="tel"
                                                id="phone"
                                                value={data.phone}
                                                onChange={(e) =>
                                                    setData(
                                                        'phone',
                                                        e.target.value,
                                                    )
                                                }
                                                className="w-full rounded-xl border border-border bg-background px-10 py-3 text-sm text-foreground placeholder:text-muted-foreground focus:border-primary focus:ring-2 focus:ring-primary/20 focus:outline-none"
                                                placeholder="09 123 4567"
                                            />
                                        </div>
                                        {errors.phone && (
                                            <p className="mt-1 text-xs text-status-critical">
                                                {errors.phone}
                                            </p>
                                        )}
                                    </div>
                                </div>

                                {/* Service Type & Residents Row */}
                                <div className="grid gap-6 sm:grid-cols-2">
                                    <div>
                                        <label
                                            htmlFor="service_type"
                                            className="block text-sm font-medium text-foreground"
                                        >
                                            Service type
                                        </label>
                                        <select
                                            id="service_type"
                                            value={data.service_type}
                                            onChange={(e) =>
                                                setData(
                                                    'service_type',
                                                    e.target.value,
                                                )
                                            }
                                            className="mt-2 w-full rounded-xl border border-border bg-background px-4 py-3 text-sm text-foreground focus:border-primary focus:ring-2 focus:ring-primary/20 focus:outline-none"
                                        >
                                            {serviceTypes.map((type) => (
                                                <option
                                                    key={type.value}
                                                    value={type.value}
                                                >
                                                    {type.label}
                                                </option>
                                            ))}
                                        </select>
                                        {errors.service_type && (
                                            <p className="mt-1 text-xs text-status-critical">
                                                {errors.service_type}
                                            </p>
                                        )}
                                    </div>

                                    <div>
                                        <label
                                            htmlFor="residents_count"
                                            className="block text-sm font-medium text-foreground"
                                        >
                                            Number of residents
                                        </label>
                                        <select
                                            id="residents_count"
                                            value={data.residents_count}
                                            onChange={(e) =>
                                                setData(
                                                    'residents_count',
                                                    e.target.value,
                                                )
                                            }
                                            className="mt-2 w-full rounded-xl border border-border bg-background px-4 py-3 text-sm text-foreground focus:border-primary focus:ring-2 focus:ring-primary/20 focus:outline-none"
                                        >
                                            {residentCounts.map((count) => (
                                                <option
                                                    key={count.value}
                                                    value={count.value}
                                                >
                                                    {count.label}
                                                </option>
                                            ))}
                                        </select>
                                        {errors.residents_count && (
                                            <p className="mt-1 text-xs text-status-critical">
                                                {errors.residents_count}
                                            </p>
                                        )}
                                    </div>
                                </div>

                                {/* Message */}
                                <div>
                                    <label
                                        htmlFor="message"
                                        className="block text-sm font-medium text-foreground"
                                    >
                                        Message{' '}
                                        <span className="text-status-critical">*</span>
                                    </label>
                                    <textarea
                                        id="message"
                                        rows={5}
                                        value={data.message}
                                        onChange={(e) =>
                                            setData('message', e.target.value)
                                        }
                                        className="mt-2 w-full resize-none rounded-xl border border-border bg-background px-4 py-3 text-sm text-foreground placeholder:text-muted-foreground focus:border-primary focus:ring-2 focus:ring-primary/20 focus:outline-none"
                                        placeholder="Tell us about your service and what you're looking for..."
                                        required
                                    />
                                    {errors.message && (
                                        <p className="mt-1 text-xs text-status-critical">
                                            {errors.message}
                                        </p>
                                    )}
                                </div>

                                {/* Submit Button */}
                                <button
                                    type="submit"
                                    disabled={processing}
                                    className="inline-flex w-full items-center justify-center gap-2 rounded-xl bg-primary px-8 py-4 text-sm font-medium text-primary-foreground shadow-lg shadow-primary/25 transition-all hover:bg-primary/90 disabled:cursor-not-allowed disabled:opacity-70 sm:w-auto"
                                >
                                    {processing ? (
                                        <>
                                            <Loader2
                                                size={18}
                                                className="animate-spin"
                                            />
                                            Sending...
                                        </>
                                    ) : (
                                        <>
                                            <Send size={18} />
                                            Send message
                                        </>
                                    )}
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </section>
        </MarketingLayout>
    );
};

export default Contact;
