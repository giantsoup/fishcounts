<?php

namespace App\DTOs;

use App\Models\ParserReparseRun;

final readonly class StartParserReparseRunResult
{
    public function __construct(
        public ParserReparseRun $run,
        public bool $created,
    ) {}
}
