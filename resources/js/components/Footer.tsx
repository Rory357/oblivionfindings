import { Link } from '@inertiajs/react';
import { Heart, Mail, MapPin } from 'lucide-react';
import React from 'react';

const Footer: React.FC = () => {
    const year = new Date().getFullYear();

    const footerLinks = {
        product: [
            { label: 'Features', href: '/features' },
            { label: 'Smart Monitoring', href: '/smart-monitoring' },
            { label: 'Pricing', href: '/pricing' },
            { label: 'Demo', href: '/contact' },
            { label: 'Log in', href: '/login' },
        ],
        company: [
            { label: 'About', href: '/about' },
            { label: 'Contact', href: '/contact' },
            { label: 'Support', href: '/contact' },
        ],
        legal: [
            { label: 'Privacy Policy', href: '/privacy' },
            { label: 'Terms of Service', href: '/terms' },
            { label: 'Cookie Policy', href: '/privacy' },
        ],
    };

    return (
        <footer className="mt-20 border-t border-border pt-12">
            <div className="grid gap-10 md:grid-cols-2 lg:grid-cols-4">
                {/* Brand */}
                <div className="lg:col-span-1">
                    <div className="flex items-center gap-3">
                        <div className="flex h-10 w-10 items-center justify-center rounded-2xl bg-gradient-to-br from-primary to-primary/80 text-primary-foreground text-sm font-semibold shadow-lg shadow-primary/20">
                            OF
                        </div>
                        <span className="text-sm font-semibold tracking-tight">
                            Oblivion Findings
                        </span>
                    </div>
                    <p className="mt-4 text-sm leading-relaxed text-muted-foreground">
                        Modern operations platform for supported living providers. 
                        Manage residents, staff, compliance and care delivery in one place.
                    </p>
                    <div className="mt-6 space-y-3 text-sm text-muted-foreground">
                        <a href="mailto:hello@oblivionfindings.co.nz" className="flex items-center gap-2 hover:text-primary transition-colors">
                            <Mail size={16} />
                            <span>hello@oblivionfindings.co.nz</span>
                        </a>
                        <div className="flex items-center gap-2">
                            <MapPin size={16} />
                            <span>Auckland, New Zealand</span>
                        </div>
                    </div>
                </div>

                {/* Product */}
                <div>
                    <h3 className="text-sm font-semibold text-foreground">Product</h3>
                    <ul className="mt-4 space-y-3">
                        {footerLinks.product.map((link) => (
                            <li key={link.label}>
                                <Link
                                    href={link.href}
                                    className="text-sm text-muted-foreground hover:text-primary transition-colors"
                                >
                                    {link.label}
                                </Link>
                            </li>
                        ))}
                    </ul>
                </div>

                {/* Company */}
                <div>
                    <h3 className="text-sm font-semibold text-foreground">Company</h3>
                    <ul className="mt-4 space-y-3">
                        {footerLinks.company.map((link) => (
                            <li key={link.label}>
                                <Link
                                    href={link.href}
                                    className="text-sm text-muted-foreground hover:text-primary transition-colors"
                                >
                                    {link.label}
                                </Link>
                            </li>
                        ))}
                    </ul>
                </div>

                {/* Legal */}
                <div>
                    <h3 className="text-sm font-semibold text-foreground">Legal</h3>
                    <ul className="mt-4 space-y-3">
                        {footerLinks.legal.map((link) => (
                            <li key={link.label}>
                                <Link
                                    href={link.href}
                                    className="text-sm text-muted-foreground hover:text-primary transition-colors"
                                >
                                    {link.label}
                                </Link>
                            </li>
                        ))}
                    </ul>
                </div>
            </div>

            {/* Bottom */}
            <div className="mt-12 flex flex-col items-center justify-between gap-4 border-t border-border pt-8 sm:flex-row">
                <p className="text-xs text-muted-foreground">
                    © {year} Oblivion Findings. All rights reserved.
                </p>
                <p className="flex items-center gap-1 text-xs text-muted-foreground">
                    Made with <Heart size={12} className="text-status-critical" /> in New Zealand
                </p>
            </div>
        </footer>
    );
};

export default Footer;
