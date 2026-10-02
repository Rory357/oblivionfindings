/* Medication › Settings page state shared by the views and dialogs: the
 * server's settings, the page's draft, who may change what, navigation
 * between views and tabs, the open dialog, and the in-page status message. */
import { createContext, useContext } from 'react';
import type { Draft, SettingsPayload, ViewKey } from './_model';

export type Dialog =
    | { kind: 'review'; view: ViewKey }
    | { kind: 'discard'; view: ViewKey }
    | { kind: 'unsaved' }
    | { kind: 'guard'; url: string }
    | { kind: 'hist'; id: number }
    | { kind: 'restore'; id: number }
    | { kind: 'keep'; group: string; key: string }
    | { kind: 'reviewdefaults' }
    | { kind: 'rule'; id: number | 'new' }
    | { kind: 'ruleview'; id: number }
    | { kind: 'ruletoggle'; id: number }
    | { kind: 'rulehistory'; id: number }
    | { kind: 'tpl'; id: number | 'new' }
    | { kind: 'tplview'; id: number }
    | { kind: 'tpltoggle'; id: number }
    | { kind: 'tplretire'; id: number }
    | { kind: 'gen' }
    | { kind: 'alertwho'; key: string }
    | { kind: 'alertperson'; key: string }
    | { kind: 'msgpreview'; key: string };

export type SettingsContext = {
    s: SettingsPayload;
    draft: Draft;
    setDraft: (fn: (d: Draft) => Draft) => void;
    /** Can this person change the settings in this group? */
    canEdit: (group: string) => boolean;
    go: (view: ViewKey, section?: string) => void;
    open: (d: Dialog) => void;
    close: () => void;
    flash: (message: string) => void;
    /** History entries with a higher id were saved during this visit ("Just now"). */
    freshAfter: number;
    /** Leave the page with an unsaved draft (after the guard). */
    leave: (url: string) => void;
    /** Values "Review changes" can't save yet, by "group.key". */
    errors: Record<string, string>;
    clearError: (id: string) => void;
};

export const SettingsCtx = createContext<SettingsContext | null>(null);

export function useSettings(): SettingsContext {
    const ctx = useContext(SettingsCtx);
    if (!ctx) throw new Error('useSettings must be used inside SettingsCtx');
    return ctx;
}
