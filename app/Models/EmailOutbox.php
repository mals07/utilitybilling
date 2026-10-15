<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * An email captured instead of sent while EMAIL_DELIVERY=console (development).
 */
#[Fillable(['to', 'subject', 'body', 'action_url'])]
class EmailOutbox extends Model
{
    protected $table = 'email_outbox';

    public const UPDATED_AT = null;
}
