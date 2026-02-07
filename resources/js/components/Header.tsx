import { useAppearance } from '@/hooks/use-appearance';
import { Link, usePage } from '@inertiajs/react';
import { Menu, Moon, Sun, X } from 'lucide-react';
import React, { useState } from 'react';

const Header: React.FC = () => {
    const [mobileMenuOpen, setMobileMenuOpen] = useState(false);
    const { url } = usePage();
    const { appearance, updateAppearance } = useAppearance();

    const isActive = (path: string) => {
        if (path === '/') return url === '/';
        return url.startsWith(path);
    };

    const navItems = [
        { href: '/', label: 'Home' },
        { href: '/features', label: 'Features' },
        { href: '/smart-monitoring', label: 'Smart Monitoring' },
        { href: '/pricing', label: 'Pricing' },
    ];

    const toggleTheme = () => {
        const newAppearance = appearance === 'dark' ? 'light' : 'dark';
        updateAppearance(newAppearance);
    };

    const isDark =
        appearance === 'dark' ||
        (appearance === 'system' &&
            typeof window !== 'undefined' &&
            window.matchMedia('(prefers-color-scheme: dark)').matches);

    return (
        <header className="flex items-center justify-between gap-4">
            <Link href="/" className="group flex items-center gap-3">
                <div className="flex h-10 w-10 items-center justify-center rounded-2xl bg-gradient-to-br from-primary to-primary/80 text-sm font-semibold text-primary-foreground shadow-lg shadow-primary/20 transition-transform group-hover:scale-105">
                    OF
                </div>
                <div>
                    <span className="text-sm font-semibold tracking-tight">
                        Oblivion Findings
                    </span>
                    <p className="text-[11px] text-muted-foreground italic">
                        Preserving stories. Powered by code.
                    </p>
                </div>
            </Link>

            {/* Desktop nav */}
            <nav className="hidden items-center gap-1 text-sm text-muted-foreground sm:flex">
                {navItems.map((item) => (
                    <Link
                        key={item.href}
                        href={item.href}
                        className={`rounded-full px-4 py-2 transition-colors ${
                            isActive(item.href)
                                ? 'bg-muted font-medium text-foreground'
                                : 'hover:bg-muted/50 hover:text-foreground'
                        }`}
                    >
                        {item.label}
                    </Link>
                ))}
                <Link
                    href="/contact"
                    className={`rounded-full px-4 py-2 transition-colors ${
                        isActive('/contact')
                            ? 'bg-muted font-medium text-foreground'
                            : 'hover:bg-muted/50 hover:text-foreground'
                    }`}
                >
                    Contact
                </Link>
            </nav>

            <div className="flex items-center gap-2">
                {/* Theme toggle */}
                <button
                    onClick={toggleTheme}
                    className="hidden h-10 w-10 items-center justify-center rounded-full border border-border bg-background text-muted-foreground transition-colors hover:bg-muted hover:text-foreground sm:flex"
                    aria-label="Toggle theme"
                >
                    {isDark ? <Sun size={18} /> : <Moon size={18} />}
                </button>

                <Link
                    href="/login"
                    className="hidden rounded-full border border-border bg-background px-4 py-2 text-sm text-muted-foreground transition-colors hover:border-border/80 hover:bg-muted sm:inline-flex"
                >
                    Log in
                </Link>

                <Link
                    href="/contact"
                    className="rounded-full bg-primary px-4 py-2 text-sm font-medium text-primary-foreground shadow-lg shadow-primary/20 transition-all hover:bg-primary/90 hover:shadow-primary/30"
                >
                    Book a demo
                </Link>

                {/* Mobile menu button */}
                <button
                    onClick={() => setMobileMenuOpen(!mobileMenuOpen)}
                    className="flex h-10 w-10 items-center justify-center rounded-xl border border-border bg-background text-muted-foreground sm:hidden"
                    aria-label="Toggle menu"
                >
                    {mobileMenuOpen ? <X size={20} /> : <Menu size={20} />}
                </button>
            </div>

            {/* Mobile nav */}
            {mobileMenuOpen && (
                <div className="absolute top-20 right-4 left-4 z-50 rounded-2xl border border-border bg-popover/95 p-4 shadow-2xl backdrop-blur-xl sm:hidden">
                    <nav className="flex flex-col gap-1">
                        {navItems.map((item) => (
                            <Link
                                key={item.href}
                                href={item.href}
                                onClick={() => setMobileMenuOpen(false)}
                                className={`rounded-xl px-4 py-3 text-sm transition-colors ${
                                    isActive(item.href)
                                        ? 'bg-primary/10 font-medium text-primary'
                                        : 'text-muted-foreground hover:bg-muted'
                                }`}
                            >
                                {item.label}
                            </Link>
                        ))}
                        <Link
                            href="/contact"
                            onClick={() => setMobileMenuOpen(false)}
                            className={`rounded-xl px-4 py-3 text-sm transition-colors ${
                                isActive('/contact')
                                    ? 'bg-primary/10 font-medium text-primary'
                                    : 'text-muted-foreground hover:bg-muted'
                            }`}
                        >
                            Contact
                        </Link>
                        <div className="my-2 h-px bg-border" />
                        <button
                            onClick={() => {
                                toggleTheme();
                                setMobileMenuOpen(false);
                            }}
                            className="flex items-center gap-3 rounded-xl px-4 py-3 text-sm text-muted-foreground transition-colors hover:bg-muted"
                        >
                            {isDark ? <Sun size={18} /> : <Moon size={18} />}
                            <span>
                                Switch to {isDark ? 'light' : 'dark'} mode
                            </span>
                        </button>
                        <Link
                            href="/login"
                            onClick={() => setMobileMenuOpen(false)}
                            className="rounded-xl px-4 py-3 text-sm text-muted-foreground transition-colors hover:bg-muted"
                        >
                            Log in
                        </Link>
                    </nav>
                </div>
            )}
        </header>
    );
};

export default Header;
