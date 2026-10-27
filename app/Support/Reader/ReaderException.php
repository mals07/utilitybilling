<?php

namespace App\Support\Reader;

use DomainException;
use Illuminate\Http\JsonResponse;

/**
 * Something the meter reader app asked for that can't be done, with the message the app shows and
 * the HTTP status it is answered with.
 */
class ReaderException extends DomainException
{
    public function __construct(string $message, private readonly int $status = 422)
    {
        parent::__construct($message);
    }

    public function render(): JsonResponse
    {
        return response()->json(['message' => $this->getMessage()], $this->status);
    }
}
