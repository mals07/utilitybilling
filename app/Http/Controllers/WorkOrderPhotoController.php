<?php

namespace App\Http\Controllers;

use App\Models\WorkOrderPhoto;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Serves a proof photo of one of the company's work orders.
 */
class WorkOrderPhotoController extends Controller
{
    public function __invoke(Request $request, int $photo): Response
    {
        $found = WorkOrderPhoto::query()
            ->select(['work_order_photos.photo_data', 'work_order_photos.mime_type'])
            ->join('work_orders', 'work_orders.id', '=', 'work_order_photos.work_order_id')
            ->where('work_orders.company_id', $request->user()->company_id)
            ->where('work_order_photos.id', $photo)
            ->first();

        abort_if($found === null, 404);

        return response($found->photo_data, 200, [
            'Content-Type' => $found->mime_type,
            'Cache-Control' => 'private, max-age=86400',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
