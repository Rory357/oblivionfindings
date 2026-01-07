import { Head } from '@inertiajs/react';
import React from 'react';

const Contact: React.FC = () => {
    return (
        <>
            <Head>
                <title>Contact · Oblivion Findings</title>
            </Head>

            <div className="min-h-screen bg-slate-950 text-white">
                <div className="pointer-events-none fixed inset-0 -z-10 bg-gradient-to-b from-slate-950 via-slate-900 to-slate-950" />

                <div className="mx-auto max-w-6xl px-4 py-6 sm:px-6 sm:py-10 lg:px-8">
                    <h1 className="text-3xl font-semibold tracking-tight text-white sm:text-4xl">
                        Contact Oblivion Findings
                    </h1>
                    <p className="mt-3 max-w-xl text-sm text-slate-300">
                        If you can see this, your Contact page is wired up
                        correctly. Next we can drop in the proper layout,
                        header, footer and form.
                    </p>
                </div>
            </div>
        </>
    );
};

export default Contact;
