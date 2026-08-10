/**
 * Lightweight, dependency-free confetti burst for "delight" moments — end-of-day
 * clock-out, signing a document, sending kudos, clearing every attention item,
 * completing all 1:1 actions. Self-contained (no CSS keyframes required — uses
 * the Web Animations API) and motion-reduce safe: when the user prefers reduced
 * motion it no-ops. Colours come from semantic design tokens (no raw hex).
 */

const PIECE_COLORS = [
    'var(--primary)',
    'var(--category-hr)',
    'var(--status-success)',
    'var(--status-warning)',
    'var(--status-critical)',
    'var(--status-info)',
    'var(--live)',
];

export function fireConfetti(pieces = 110): void {
    if (typeof window === 'undefined' || typeof document === 'undefined')
        return;
    if (window.matchMedia?.('(prefers-reduced-motion: reduce)').matches) return;

    const container = document.createElement('div');
    container.setAttribute('aria-hidden', 'true');
    container.style.cssText =
        'position:fixed;inset:0;z-index:90;pointer-events:none;overflow:hidden';
    document.body.appendChild(container);

    let maxMs = 0;
    for (let i = 0; i < pieces; i++) {
        const span = document.createElement('span');
        const size = 6 + Math.random() * 7;
        const durationMs = (2 + Math.random() * 1.6) * 1000;
        const delayMs = Math.random() * 500;
        maxMs = Math.max(maxMs, durationMs + delayMs);
        span.style.cssText = `position:absolute;top:-12vh;left:${Math.random() * 100}%;width:${size}px;height:${size * 0.6}px;background:${PIECE_COLORS[i % PIECE_COLORS.length]};border-radius:${Math.random() > 0.5 ? '50%' : '2px'}`;
        container.appendChild(span);
        span.animate(
            [
                { transform: 'translateY(0) rotate(0deg)', opacity: 1 },
                {
                    transform: `translateY(122vh) rotate(${720 * (Math.random() > 0.5 ? 1 : -1)}deg)`,
                    opacity: 0.9,
                },
            ],
            {
                duration: durationMs,
                delay: delayMs,
                easing: 'cubic-bezier(0.3, 0.6, 0.7, 1)',
                fill: 'forwards',
            },
        );
    }

    window.setTimeout(() => container.remove(), maxMs + 200);
}
