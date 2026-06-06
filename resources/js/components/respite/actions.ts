/**
 * Pipeline status transitions, wired to the existing respite endpoints. Each
 * controller responds with back()->with('success'), so Inertia re-renders the
 * workspace (with its ?tab= preserved) and the lists refresh in place.
 */
import { router } from '@inertiajs/react';

const opts = { preserveScroll: true } as const;

export const respiteActions = {
    triageReferral: (id: number) =>
        router.put(`/respite/referrals/${id}`, { status: 'triaged' }, opts),
    acceptReferral: (id: number) =>
        router.put(`/respite/referrals/${id}`, { status: 'accepted' }, opts),
    approveRequest: (
        id: number,
        data: Record<string, string | number | boolean | null> = {},
    ) => router.post(`/respite/requests/${id}/approve`, data, opts),
    promoteRequest: (
        id: number,
        data: Record<string, string | number | boolean | null> = {},
    ) => router.post(`/respite/requests/${id}/promote`, data, opts),
    confirmBooking: (
        id: number,
        data: Record<string, string | number | boolean | null> = {},
    ) => router.post(`/respite/bookings/${id}/confirm`, data, opts),
    checkInStay: (
        id: number,
        data: Record<string, string | number | boolean | null> = {},
    ) => router.post(`/respite/stays/${id}/check-in`, data, opts),
    startTask: (id: number) =>
        router.post(`/respite/tasks/${id}/start`, {}, opts),
    completeTask: (id: number) =>
        router.post(`/respite/tasks/${id}/complete`, {}, opts),
};
