<?php

namespace App\Http\Controllers;

use App\Support\Guides;
use Illuminate\Http\JsonResponse;

/**
 * One step-by-step guide as JSON, fetched when the user starts it, so pages don't carry every guide's text.
 */
class GuideController extends Controller
{
    public function __invoke(string $guide): JsonResponse
    {
        $found = Guides::all()[$guide] ?? null;
        abort_if($found === null, 404);

        return response()->json($found);
    }
}
