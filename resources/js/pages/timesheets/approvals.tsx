import { useEffect } from 'react';
import { router } from '@inertiajs/react';

export default function TimesheetApprovalsRedirect() {
    useEffect(() => {
        router.visit('/timesheets?mode=approvals', { replace: true });
    }, []);

    return null;
}
