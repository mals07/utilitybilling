<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Serves the stored photo of one of the company's customers.
 */
class CustomerPhotoController extends Controller
{
    public function __invoke(Request $request, int $customer): Response
    {
        $photo = Customer::query()
            ->select(['photo_data', 'photo_mime_type'])
            ->where('company_id', $request->user()->company_id)
            ->find($customer);

        abort_if($photo?->photo_data === null || $photo->photo_mime_type === null, 404);

        return response($photo->photo_data, 200, [
            'Content-Type' => $photo->photo_mime_type,
            'Cache-Control' => 'private, no-cache',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
