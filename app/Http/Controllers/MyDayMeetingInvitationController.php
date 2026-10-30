<?php

namespace App\Http\Controllers;

use App\Services\HealthSafety\WorkerParticipationInvitationReadService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

final class MyDayMeetingInvitationController extends Controller
{
    public function show(Request $request, string $id, WorkerParticipationInvitationReadService $invitations): Response
    {
        $max = (string) min(PHP_INT_MAX, 9007199254740991);
        abort_unless(preg_match('/^[1-9][0-9]*$/D', $id) && (strlen($id) < strlen($max) || (strlen($id) === strlen($max) && strcmp($id, $max) <= 0)), 404);
        $meetingId = (int) $id;
        if ($request->expectsJson() && ! $request->header('X-Inertia')) {
            $response = response($invitations->json($request->user(), $meetingId), 200, ['Content-Type' => 'application/json']);
        } else {
            $data = $invitations->show($request->user(), $meetingId);
            $response = Inertia::render('my-day/meeting-invitation', array_map(fn ($value) => Inertia::always($value), $data))->toResponse($request);
        }
        $response->headers->set('Cache-Control', 'private, no-store');
        $response->headers->set('Referrer-Policy', 'no-referrer');
        return $response;
    }
}
