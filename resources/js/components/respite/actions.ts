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
    approveRequest: (id: number) =>
        router.post(`/respite/requests/${id}/approve`, {}, opts),
    confirmBooking: (id: number) =>
        router.post(`/respite/bookings/${id}/confirm`, {}, opts),
    checkInStay: (id: number) =>
        router.post(`/respite/stays/${id}/check-in`, {}, opts),
    startTask: (id: number) => router.post(`/respite/tasks/${id}/start`, {}, opts),
    completeTask: (id: number) => router.post(`/respite/tasks/${id}/complete`, {}, opts),
};
