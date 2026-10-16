import { AppreciationIcon } from '@/components/recognition/appreciation-icon';
import { DoorOpen } from 'lucide-react';
import type { ReactNode, SVGProps } from 'react';

// Original feature glyphs from the approved Hub collection, preserving its stored icon names.
function featureIcon(name: string, shape: ReactNode) {
    return function HubFeatureIcon(props: SVGProps<SVGSVGElement>) {
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
                data-hub-icon={name}
                {...props}
            >
                {shape}
            </svg>
        );
    };
}

export const heroIcons = {
    'heart-handshake': AppreciationIcon,
    sparkles: AppreciationIcon,
    users: featureIcon(
        'people',
        <>
            <circle cx="8.6" cy="7" r="3" />
            <path d="M14.4 4.7a3 3 0 0 1 0 5.7M2.8 19v-1.3a5.8 5.8 0 0 1 11.6 0V19H8.6" />
            <path d="M17.2 13a4.2 4.2 0 0 1 4 4.2V19h-3.7" />
        </>,
    ),
    heart: featureIcon(
        'care',
        <>
            <path d="M12 13.5 7.3 9a3 3 0 0 1 4.2-4.3l.5.5.5-.5A3 3 0 0 1 16.7 9L15 10.6" />
            <path d="M3 14.5h3.3c1.8 0 2.7 2 4.5 2H14a2 2 0 0 1 2 2v.5H9" />
            <path d="m16 17.5 2.3-3a2 2 0 0 1 3.1 2.5l-2.3 3a3 3 0 0 1-2.4 1.2H8l-5-3" />
        </>,
    ),
    'book-open': featureIcon(
        'resources',
        <>
            <path d="M12 7c-2.8-2.4-5.5-3-9-2v13c3.5-1 6.2-.4 9 2 2.8-2.4 5.5-3 9-2V9M12 7v13" />
            <path d="M16 6h5M18.5 3.5v5M6.5 10h2M6.5 14h2M15.5 14H18" />
        </>,
    ),
    newspaper: featureIcon(
        'stories',
        <>
            <path d="M14.5 3H7A3 3 0 0 0 4 6v12a3 3 0 0 0 3 3h9.5a3 3 0 0 0 3-3v-6M14.5 3v4a2 2 0 0 0 2 2h3V7.5L15 3" />
            <path d="M8 13h7M8 17h4" />
            <circle
                cx="20"
                cy="3.5"
                r="1.2"
                fill="currentColor"
                stroke="none"
            />
        </>,
    ),
    flag: featureIcon(
        'growth',
        <>
            <path d="M4 20h8.5a6.5 6.5 0 0 0 6.5-6.5V8M8 16v-4.5A4.5 4.5 0 0 0 3.5 7H3v4.5A4.5 4.5 0 0 0 7.5 16H8ZM19 8h-4.5A4.5 4.5 0 0 1 19 3h2v2a3 3 0 0 1-3 3" />
            <circle
                cx="11"
                cy="4.5"
                r="1.3"
                fill="currentColor"
                stroke="none"
            />
        </>,
    ),
    'building-2': featureIcon(
        'place',
        <>
            <path d="M19.5 13.5A8 8 0 1 1 10.5 4M8 20V9a3 3 0 0 1 3-3h4v14M11.5 20H19M15 6h2a3 3 0 0 1 3 3" />
            <circle
                cx="19"
                cy="3.5"
                r="1.3"
                fill="currentColor"
                stroke="none"
            />
        </>,
    ),
    'door-open': DoorOpen,
};

export const heroIconChoices = [
    { value: 'sparkles', label: 'Appreciation' },
    { value: 'users', label: 'People' },
    { value: 'heart', label: 'Care' },
    { value: 'building-2', label: 'Our Hub' },
    { value: 'book-open', label: 'Resources' },
    { value: 'newspaper', label: 'Stories' },
    { value: 'flag', label: 'Progress' },
    { value: 'door-open', label: 'Welcome' },
] as const;
