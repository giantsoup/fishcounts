<?php

namespace App\Exceptions;

use App\DTOs\AiParserProviderResponseData;
use Throwable;
use UnexpectedValueException;

final class AiParserProviderResponseException extends UnexpectedValueException
{
    public function __construct(
        string $message,
        public readonly AiParserProviderResponseData $providerResponse,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, previous: $previous);
    }
}
