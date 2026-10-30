import { cn } from '@/lib/utils';
import { useId } from 'react';

/**
 * The Oblivion Care "Journey" mark: New Zealand's North, South and Stewart
 * islands with the journey pathway cut through them (vector source:
 * Artwork/Exports/Journey-2026-10-03-v1/mark-purple.svg). Fill comes from the
 * `tone` token so Settings › Branding recolours it. Never stretch it: keep the
 * 280 × 372 aspect ratio.
 *
 * `traveller` adds the light that runs the pathway once while signing in; it
 * only animates inside `.journey-auth.is-signing` (journey-auth.css).
 */

export const JOURNEY_PATHWAY =
    'M51 295 C74 279 93 265 103 244 C111 227 109 212 122 200 C140 184 165 182 180 167 C196 150 190 126 211 108';

const NORTH_ISLAND =
    'M145 7 C150 6 152 12 151 17 C155 18 158 22 159 27 C165 24 171 27 173 34 L178 49 C177 52 173 52 174 56 C178 62 183 62 188 60 C191 57 188 53 189 50 C190 47 194 47 196 51 L195 62 C201 66 200 74 205 77 C215 79 220 85 230 87 C241 91 247 88 254 82 C261 76 267 75 272 80 C278 87 266 91 266 100 C266 109 264 113 258 117 C256 123 259 129 252 130 C246 129 241 127 235 131 C228 136 230 143 229 148 C226 158 220 168 213 177 C207 184 201 191 194 190 C190 190 184 188 183 185 C191 177 190 166 185 160 C178 154 169 151 165 145 C160 141 152 143 152 137 C151 131 157 126 165 122 C172 119 172 113 174 105 C178 88 176 76 170 64 C168 58 164 63 163 56 C158 52 157 45 152 39 C145 30 145 24 143 18 L137 11 C135 7 139 5 143 10 Z';
const SOUTH_ISLAND =
    'M141 160 C146 160 141 166 143 168 C149 169 144 176 151 178 L163 174 C167 173 166 179 171 180 C176 181 174 185 169 186 L163 190 C165 197 174 199 170 206 C165 217 159 226 151 235 C144 242 146 249 138 255 C128 261 122 269 119 279 C118 288 109 292 109 300 C109 309 100 310 97 317 C96 324 88 325 83 329 C74 335 68 337 60 334 C54 332 49 337 44 333 C41 329 35 329 29 329 C23 330 22 325 17 326 C12 328 9 324 6 321 C1 324 1 319 5 317 C8 303 16 291 23 282 C29 274 38 272 41 265 C46 258 53 257 58 252 C64 248 66 241 72 240 C81 239 87 232 89 225 C95 218 99 211 101 203 C105 194 111 197 116 189 C120 182 119 175 125 169 C129 165 134 162 141 160 Z';
const STEWART_ISLAND =
    'M32 340 C38 339 40 344 40 348 C47 352 43 359 37 360 C34 364 26 365 23 361 C20 358 27 354 26 350 C25 346 27 342 32 340 Z';

export function JourneyMark({
    tone,
    traveller = false,
    className,
    title,
}: {
    /** lavender on ink, primary (brand) on light grounds. */
    tone: 'lavender' | 'primary';
    traveller?: boolean;
    className?: string;
    /** Accessible name; omit for decorative use (aria-hidden). */
    title?: string;
}) {
    const maskId = `journey-pathway-${useId().replace(/:/g, '')}`;

    return (
        <svg
            viewBox="0 0 280 372"
            className={className}
            {...(title
                ? { role: 'img', 'aria-label': title }
                : { 'aria-hidden': true })}
        >
            <defs>
                <mask
                    id={maskId}
                    maskUnits="userSpaceOnUse"
                    x="0"
                    y="0"
                    width="280"
                    height="372"
                >
                    {/* Mask luminance: white keeps the islands, black cuts the pathway. */}
                    <rect width="280" height="372" fill="white" />
                    <path
                        d={JOURNEY_PATHWAY}
                        fill="none"
                        stroke="black"
                        strokeWidth="5.8"
                        strokeLinecap="round"
                    />
                    <circle cx="51" cy="295" r="6.9" fill="black" />
                    <circle cx="211" cy="108" r="6.9" fill="black" />
                </mask>
            </defs>
            <g
                mask={`url(#${maskId})`}
                className={cn(
                    tone === 'lavender'
                        ? 'fill-journey-lavender'
                        : 'fill-primary',
                )}
            >
                <path d={NORTH_ISLAND} />
                <path d={SOUTH_ISLAND} />
                <path d={STEWART_ISLAND} />
            </g>
            {traveller && (
                <>
                    <path
                        className="journey-traveller"
                        pathLength={100}
                        d={JOURNEY_PATHWAY}
                    />
                    <circle
                        className="journey-arrive"
                        cx="211"
                        cy="108"
                        r="6.9"
                    />
                </>
            )}
        </svg>
    );
}
