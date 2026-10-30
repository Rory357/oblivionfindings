<?php

namespace App\Exceptions;

use Illuminate\Http\Exceptions\HttpResponseException;

/** P01's form retains its draft and maps this response to the approved ended state. */
class MedicationEmergencyAccessEnded extends HttpResponseException
{
    public function __construct()
    {
        parent::__construct(response()->json([
            'code' => 'emergency_access_ended',
            'message' => 'Emergency access ended. Keep this entry open; start it again or ask someone on shift to record.',
            'errors' => ['emergency_access' => ['Emergency access ended. Your entry has not been recorded.']],
        ], 409));
    }
}
