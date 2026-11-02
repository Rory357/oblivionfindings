// Oblivion Live events (spec section 4, "gateway to browser"). They name what changed and
// carry IDs, counts and kinds only; every fetch they trigger re-checks access in the app.

export type PresenceState = 'on_shift' | 'available' | 'away' | 'offline';

export type LiveEvent =
    | {
          type: 'conversation.changed';
          conversation_id: number;
          change_id: number;
          kinds: string[];
      }
    | { type: 'inbox.changed'; reason: string }
    | { type: 'unread.changed'; total: number }
    | {
          type: 'typing';
          conversation_id: number;
          who: string;
          state: 'start' | 'stop';
      }
    | { type: 'presence'; user_id: number; state: PresenceState }
    | { type: 'call.changed'; call_id: string; conversation_id: number }
    | {
          type: 'call.signal';
          call_id: string;
          from: number;
          payload: Record<string, unknown>;
      }
    | { type: 'notice'; kind: string };

export type LiveEventType = LiveEvent['type'];

const PRESENCE = new Set<string>(['on_shift', 'available', 'away', 'offline']);

const isId = (value: unknown): value is number =>
    typeof value === 'number' && Number.isSafeInteger(value) && value > 0;
const isCount = (value: unknown): value is number =>
    typeof value === 'number' && Number.isSafeInteger(value) && value >= 0;
const isText = (value: unknown, max = 200): value is string =>
    typeof value === 'string' && value.length > 0 && value.length <= max;
const isObject = (value: unknown): value is Record<string, unknown> =>
    value !== null && typeof value === 'object' && !Array.isArray(value);

/** Accept only the event shapes the spec defines; anything else is ignored. */
export function parseLiveEvent(value: unknown): LiveEvent | null {
    if (!isObject(value)) return null;
    switch (value.type) {
        case 'conversation.changed':
            return isId(value.conversation_id) &&
                isCount(value.change_id) &&
                Array.isArray(value.kinds) &&
                value.kinds.length <= 20 &&
                value.kinds.every((kind) => isText(kind, 40))
                ? {
                      type: value.type,
                      conversation_id: value.conversation_id,
                      change_id: value.change_id,
                      kinds: value.kinds as string[],
                  }
                : null;
        case 'inbox.changed':
            return isText(value.reason, 40)
                ? { type: value.type, reason: value.reason }
                : null;
        case 'unread.changed':
            return isCount(value.total)
                ? { type: value.type, total: value.total }
                : null;
        case 'typing':
            return isId(value.conversation_id) &&
                isText(value.who) &&
                (value.state === 'start' || value.state === 'stop')
                ? {
                      type: value.type,
                      conversation_id: value.conversation_id,
                      who: value.who,
                      state: value.state,
                  }
                : null;
        case 'presence':
            return isId(value.user_id) &&
                typeof value.state === 'string' &&
                PRESENCE.has(value.state)
                ? {
                      type: value.type,
                      user_id: value.user_id,
                      state: value.state as PresenceState,
                  }
                : null;
        case 'call.changed':
            return isText(value.call_id, 64) && isId(value.conversation_id)
                ? {
                      type: value.type,
                      call_id: value.call_id,
                      conversation_id: value.conversation_id,
                  }
                : null;
        case 'call.signal':
            return isText(value.call_id, 64) &&
                isId(value.from) &&
                isObject(value.payload)
                ? {
                      type: value.type,
                      call_id: value.call_id,
                      from: value.from,
                      payload: value.payload,
                  }
                : null;
        case 'notice':
            return isText(value.kind, 40)
                ? { type: value.type, kind: value.kind }
                : null;
        default:
            return null;
    }
}
