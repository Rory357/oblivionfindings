import { useEffect, useCallback } from 'react';

type KeyCombo = {
    key: string;
    ctrl?: boolean;
    meta?: boolean;
    shift?: boolean;
    alt?: boolean;
};

type ShortcutHandler = (event: KeyboardEvent) => void;

interface ShortcutConfig {
    combo: KeyCombo;
    handler: ShortcutHandler;
    description?: string;
    preventDefault?: boolean;
}

function matchesCombo(event: KeyboardEvent, combo: KeyCombo): boolean {
    const keyMatch = event.key.toLowerCase() === combo.key.toLowerCase();
    const ctrlMatch = !!combo.ctrl === (event.ctrlKey || event.metaKey);
    const shiftMatch = !!combo.shift === event.shiftKey;
    const altMatch = !!combo.alt === event.altKey;

    return keyMatch && ctrlMatch && shiftMatch && altMatch;
}

export function useKeyboardShortcut(
    combo: KeyCombo,
    handler: ShortcutHandler,
    options: { preventDefault?: boolean; enabled?: boolean } = {}
) {
    const { preventDefault = true, enabled = true } = options;

    const memoizedHandler = useCallback(
        (event: KeyboardEvent) => {
            if (!enabled) return;

            if (matchesCombo(event, combo)) {
                if (preventDefault) {
                    event.preventDefault();
                }
                handler(event);
            }
        },
        [combo, handler, preventDefault, enabled]
    );

    useEffect(() => {
        window.addEventListener('keydown', memoizedHandler);
        return () => window.removeEventListener('keydown', memoizedHandler);
    }, [memoizedHandler]);
}

export function useKeyboardShortcuts(
    shortcuts: ShortcutConfig[],
    options: { enabled?: boolean } = {}
) {
    const { enabled = true } = options;

    useEffect(() => {
        if (!enabled) return;

        const handleKeyDown = (event: KeyboardEvent) => {
            // Don't trigger shortcuts when typing in inputs
            const target = event.target as HTMLElement;
            if (
                target.tagName === 'INPUT' ||
                target.tagName === 'TEXTAREA' ||
                target.isContentEditable
            ) {
                return;
            }

            for (const shortcut of shortcuts) {
                if (matchesCombo(event, shortcut.combo)) {
                    if (shortcut.preventDefault !== false) {
                        event.preventDefault();
                    }
                    shortcut.handler(event);
                    break; // Only trigger first match
                }
            }
        };

        window.addEventListener('keydown', handleKeyDown);
        return () => window.removeEventListener('keydown', handleKeyDown);
    }, [shortcuts, enabled]);
}

// Common shortcut presets
export const commonShortcuts = {
    search: { key: 'k', ctrl: true, meta: true },
    newItem: { key: 'n', ctrl: true, meta: true },
    save: { key: 's', ctrl: true, meta: true },
    close: { key: 'Escape' },
    goToDashboard: { key: 'd', ctrl: true, meta: true },
    goToClients: { key: 'c', ctrl: true, meta: true, shift: true },
    goToShifts: { key: 's', ctrl: true, meta: true, shift: true },
    goToIncidents: { key: 'i', ctrl: true, meta: true, shift: true },
    help: { key: '?', shift: true },
} as const;

// Hook for common app shortcuts
export function useAppShortcuts(
    handlers: {
        onSearch?: () => void;
        onNew?: () => void;
        onSave?: () => void;
        onClose?: () => void;
        onGoToDashboard?: () => void;
        onGoToClients?: () => void;
        onGoToShifts?: () => void;
        onGoToIncidents?: () => void;
        onHelp?: () => void;
    },
    options: { enabled?: boolean } = {}
) {
    const shortcuts: ShortcutConfig[] = [
        handlers.onSearch && {
            combo: commonShortcuts.search,
            handler: handlers.onSearch,
            description: 'Open search',
        },
        handlers.onNew && {
            combo: commonShortcuts.newItem,
            handler: handlers.onNew,
            description: 'Create new item',
        },
        handlers.onSave && {
            combo: commonShortcuts.save,
            handler: handlers.onSave,
            description: 'Save changes',
        },
        handlers.onClose && {
            combo: commonShortcuts.close,
            handler: handlers.onClose,
            description: 'Close modal/panel',
        },
        handlers.onGoToDashboard && {
            combo: commonShortcuts.goToDashboard,
            handler: handlers.onGoToDashboard,
            description: 'Go to Dashboard',
        },
        handlers.onGoToClients && {
            combo: commonShortcuts.goToClients,
            handler: handlers.onGoToClients,
            description: 'Go to Clients',
        },
        handlers.onGoToShifts && {
            combo: commonShortcuts.goToShifts,
            handler: handlers.onGoToShifts,
            description: 'Go to Shifts',
        },
        handlers.onGoToIncidents && {
            combo: commonShortcuts.goToIncidents,
            handler: handlers.onGoToIncidents,
            description: 'Go to Incidents',
        },
        handlers.onHelp && {
            combo: commonShortcuts.help,
            handler: handlers.onHelp,
            description: 'Show keyboard shortcuts',
        },
    ].filter(Boolean) as ShortcutConfig[];

    useKeyboardShortcuts(shortcuts, options);

    return shortcuts;
}

export default useKeyboardShortcut;
