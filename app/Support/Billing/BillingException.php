<?php

namespace App\Support\Billing;

use DomainException;

/**
 * A bill or payment can't be made as asked; the message is shown to the user.
 */
class BillingException extends DomainException {}
