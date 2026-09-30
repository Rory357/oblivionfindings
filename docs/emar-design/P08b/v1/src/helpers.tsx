/* Shared dialog helpers (from P07b v1.1): focus-return, the synthetic clock
 * stamp, the offline message and the required marker. */
import { NOW_LABEL } from './clock';

export const cantSaveOffline = 'You’re offline, so this can’t be saved yet. Nothing is lost — keep this open and save when you reconnect.';
export const focusFirst = (x: Record<string, string>) => window.setTimeout(() => document.getElementById(Object.keys(x)[0])?.focus(), 50);
export const restore = (rf?: () => HTMLElement | null) => (ev: Event) => {
    const el = rf?.();
    if (el) {
        ev.preventDefault();
        el.focus();
    }
};
export const stamp = () => `Mon 28 Sep, ${NOW_LABEL}`;
export const Req = () => <span className="text-status-critical">*</span>;
