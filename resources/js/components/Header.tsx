import { Link } from '@inertiajs/react';
import React from 'react';

const Header: React.FC = () => {
    return (
        <header className="flex items-center justify-between gap-4">
            <div className="flex items-center gap-3">
                <div className="flex h-9 w-9 items-center justify-center rounded-2xl bg-indigo-500 text-sm font-semibold">
                    OF
                </div>
                <div>
                    <span className="text-sm font-semibold tracking-tight">
                        Oblivion Findings
                    </span>
                    <p className="text-xs text-slate-400">
                        Supported Living Operations Platform
                    </p>
                </div>
            </div>

            <nav className="hidden items-center gap-6 text-xs text-slate-300 sm:flex">
                <a href="/#features" className="hover:text-white">
                    Features
                </a>
                <a href="/#providers" className="hover:text-white">
                    For Providers
                </a>
                <a href="/#workers" className="hover:text-white">
                    For Support Workers
                </a>
                <a href="/#pricing" className="hover:text-white">
                    Pricing
                </a>
                <Link href="/contact" className="hover:text-white">
                    Contact
                </Link>
            </nav>

            <div className="flex items-center gap-3">
                <Link
                    href="/login"
                    className="hidden rounded-full border border-slate-700 px-3 py-1.5 text-xs text-slate-200 hover:bg-slate-800 sm:inline-flex"
                >
                    Log in
                </Link>

                <Link
                    href="/demo"
                    className="rounded-full bg-indigo-500 px-4 py-2 text-xs font-medium text-white shadow-sm hover:bg-indigo-400"
                >
                    Book a demo
                </Link>
            </div>
        </header>
    );
};

export default Header;
