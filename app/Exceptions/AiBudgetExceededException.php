<?php

namespace App\Exceptions;

use Exception;

class AiBudgetExceededException extends Exception
{
    public function __construct()
    {
        parent::__construct('The configured AI budget has been exhausted.');
    }
}
