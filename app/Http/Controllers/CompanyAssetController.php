<?php

namespace App\Http\Controllers;

use App\Models\Company;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Serves the company's logo or icon, stored in the database.
 */
class CompanyAssetController extends Controller
{
    public function __invoke(Request $request, string $asset): Response
    {
        $company = $request->user()->company_id === null
            ? null
            : Company::query()->select(["{$asset}_data", "{$asset}_mime_type"])->find($request->user()->company_id);

        $data = $company?->{"{$asset}_data"};
        $mimeType = $company?->{"{$asset}_mime_type"};

        abort_if($data === null || $mimeType === null, 404);

        return response($data, 200, [
            'Content-Type' => $mimeType,
            // Per-user content: always revalidate so a shared browser never shows another account's file after logout.
            'Cache-Control' => 'private, no-cache',
            'X-Content-Type-Options' => 'nosniff',
            // Uploaded SVGs must never run scripts if opened directly.
            'Content-Security-Policy' => "default-src 'none'; style-src 'unsafe-inline'; sandbox",
        ]);
    }
}
