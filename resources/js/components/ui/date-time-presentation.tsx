import { createContext, useContext } from 'react';

/** Dialogs and sheets use ordinary, touch-sized fields. The context also
 * reaches portalled calendar/clock popovers without changing page filters. */
export const CompactDateTimeContext = createContext(false);

export function useCompactDateTime(override?: boolean) {
    const inCompactForm = useContext(CompactDateTimeContext);
    return override ?? inCompactForm;
}
