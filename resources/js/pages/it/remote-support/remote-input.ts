export type ScreenInput =
    | { type: 'move'; x: number; y: number }
    | {
          type: 'button_down' | 'button_up';
          x: number;
          y: number;
          button: number;
      }
    | { type: 'key_down' | 'key_up'; key: number }
    | { type: 'wheel'; x: number; y: number; delta: number };
export function screenPoint(
    rect: Pick<DOMRect, 'left' | 'top' | 'width' | 'height'>,
    x: number,
    y: number,
) {
    if (
        rect.width <= 0 ||
        rect.height <= 0 ||
        x < rect.left ||
        y < rect.top ||
        x > rect.left + rect.width ||
        y > rect.top + rect.height
    )
        return null;
    return {
        x: Math.round(((x - rect.left) / rect.width) * 65535),
        y: Math.round(((y - rect.top) / rect.height) * 65535),
    };
}
const keys: Record<string, number> = {
    Backspace: 8,
    Tab: 9,
    Enter: 13,
    NumpadEnter: 13,
    ShiftLeft: 160,
    ShiftRight: 161,
    ControlLeft: 162,
    ControlRight: 163,
    AltLeft: 164,
    AltRight: 165,
    CapsLock: 20,
    Escape: 27,
    Space: 32,
    PageUp: 33,
    PageDown: 34,
    End: 35,
    Home: 36,
    ArrowLeft: 37,
    ArrowUp: 38,
    ArrowRight: 39,
    ArrowDown: 40,
    Insert: 45,
    Delete: 46,
    Semicolon: 186,
    Equal: 187,
    Comma: 188,
    Minus: 189,
    Period: 190,
    Slash: 191,
    Backquote: 192,
    BracketLeft: 219,
    Backslash: 220,
    BracketRight: 221,
    Quote: 222,
    NumpadMultiply: 106,
    NumpadAdd: 107,
    NumpadSubtract: 109,
    NumpadDecimal: 110,
    NumpadDivide: 111,
};
export function windowsKey(code: string): number | null {
    if (/^Key[A-Z]$/.test(code)) return code.charCodeAt(3);
    if (/^Digit[0-9]$/.test(code)) return code.charCodeAt(5);
    if (/^Numpad[0-9]$/.test(code)) return 96 + Number(code.at(-1));
    if (/^F([1-9]|1[0-2])$/.test(code)) return 111 + Number(code.slice(1));
    return keys[code] ?? null;
}
export class RemoteInput {
    private sequence = 0;
    private window = 0;
    private count = 0;
    private keys = new Set<number>();
    private buttons = new Set<number>();
    private lastPoint = { x: 0, y: 0 };
    private lastMove = -Infinity;
    constructor(
        private channel: () => RTCDataChannel | null,
        private permitted: () => boolean,
        private fail: () => void,
        private now = () => performance.now(),
    ) {}
    send(input: ScreenInput): boolean {
        if (!this.permitted()) return false;
        const channel = this.channel(),
            now = this.now();
        if (input.type === 'move' && now - this.lastMove < 25) return false;
        if (now - this.window >= 1000) {
            this.window = now;
            this.count = 0;
        }
        // Reserve headroom below the service's 240/s ceiling. Never silently drop releases.
        if (
            !channel ||
            channel.readyState !== 'open' ||
            channel.bufferedAmount > 8192 ||
            ++this.count > 180 ||
            this.sequence >= Number.MAX_SAFE_INTEGER
        ) {
            this.fail();
            return false;
        }
        if ('x' in input) this.lastPoint = { x: input.x, y: input.y };
        try {
            channel.send(
                JSON.stringify({
                    version: 1,
                    sequence: ++this.sequence,
                    ...input,
                }),
            );
        } catch {
            this.fail();
            return false;
        }
        if (input.type === 'key_down') this.keys.add(input.key);
        if (input.type === 'key_up') this.keys.delete(input.key);
        if (input.type === 'button_down') this.buttons.add(input.button);
        if (input.type === 'button_up') this.buttons.delete(input.button);
        if (input.type === 'move') this.lastMove = now;
        return true;
    }
    release() {
        for (const key of this.keys) this.send({ type: 'key_up', key });
        for (const button of this.buttons)
            this.send({ type: 'button_up', button, ...this.lastPoint });
        this.keys.clear();
        this.buttons.clear();
    }
}
