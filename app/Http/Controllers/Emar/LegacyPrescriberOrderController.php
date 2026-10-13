<?php

namespace App\Http\Controllers\Emar;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * EA-053 (Lane C3): the old prescriber-order register
 * (/emar/prescriptions/legacy) is read only. Its New / Change / Verbal
 * orders never reached the chart, and its Cease stopped orders outside P04
 * (no stop action, open order checks, no order.stopped event). Every write
 * it had — create, edit, confirm, countersign, dispense, cancel — now
 * answers here: new orders, changes and stops are entered and checked in
 * Orders. Historic records and covert history stay readable.
 */
final class LegacyPrescriberOrderController extends Controller
{
    public const MESSAGE = 'Old prescriber-order records are read only. Enter new orders, changes and stops in Orders, where they are checked before they are used.';

    /** EA-023: a CSV import created unchecked orders outside P04 (and never as controlled). */
    public const IMPORT_MESSAGE = 'Medicines can’t be imported. Enter each one in Orders from the prescriber’s source, where it is checked before it is used.';

    public function refuseImport(Request $request): JsonResponse|RedirectResponse
    {
        return $request->expectsJson()
            ? response()->json(['message' => self::IMPORT_MESSAGE], 409)
            : redirect('/emar/prescriptions')->with('error', self::IMPORT_MESSAGE);
    }

    public function refuse(Request $request): JsonResponse|RedirectResponse
    {
        return $request->expectsJson()
            ? response()->json(['message' => self::MESSAGE], 409)
            : redirect('/emar/prescriptions')->with('error', self::MESSAGE);
    }
}
