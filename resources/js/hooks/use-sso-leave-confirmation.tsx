import { useSettingsLeaveConfirmation } from './use-settings-leave-confirmation';

/** Preserve the existing SSO-specific wording on the shared settings guard. */
export function useSsoLeaveConfirmation(dirty: boolean) {
    return useSettingsLeaveConfirmation(dirty, 'Discard unsaved SSO entries?');
}
