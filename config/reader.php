<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Meter Reader App
    |--------------------------------------------------------------------------
    |
    | The oldest app version that may keep reading and printing (e.g. "1.2.0").
    | Older apps are told to update by GET /reader/v1/status. Null allows any.
    |
    */

    'minimum_app_version' => env('READER_MINIMUM_APP_VERSION'),

];
