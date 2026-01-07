import React from 'react';

const Footer: React.FC = () => {
    const year = new Date().getFullYear();

    return (
        <footer className="mt-4 flex flex-col justify-between gap-3 border-t border-slate-800 pt-6 text-[11px] text-slate-500 sm:flex-row sm:items-center">
            <p>© {year} Oblivion Findings. All rights reserved.</p>
            <div className="flex gap-4">
                <a href="#" className="hover:text-slate-300">
                    Privacy
                </a>
                <a href="#" className="hover:text-slate-300">
                    Terms
                </a>
                <a href="/contact" className="hover:text-slate-300">
                    Support
                </a>
            </div>
        </footer>
    );
};

export default Footer;
