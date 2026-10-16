import type { SVGProps } from 'react';

/** Original clapping-hands glyph from the approved Company Hub collection. */
export function AppreciationIcon(props: SVGProps<SVGSVGElement>) {
    return (
        <svg
            viewBox="0 0 24 24"
            width="24"
            height="24"
            fill="none"
            stroke="currentColor"
            strokeWidth="1.7"
            strokeLinecap="round"
            strokeLinejoin="round"
            aria-hidden="true"
            focusable="false"
            {...props}
        >
            <path d="m10.5 21-5-5a4.5 4.5 0 0 1-1.3-3.2V8a1.3 1.3 0 0 1 2.6 0v3.2-6a1.3 1.3 0 0 1 2.6 0V11 4a1.3 1.3 0 0 1 2.6 0v7-4.5a1.3 1.3 0 0 1 2.6 0v7l1.1-1.1a1.5 1.5 0 0 1 2.1 2.1l-3.3 3.3V21" />
            <path d="m18 20.5 2.1-2.1a4.8 4.8 0 0 0 1.4-3.4V9a1.3 1.3 0 0 0-2.6 0v2M18 5.5V3M21 5l1-1" />
        </svg>
    );
}
