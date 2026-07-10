<?php

namespace App\Services\Parsing;

use Illuminate\Support\Str;

class BoatNameNormalizer
{
    public static function normalize(string $value): string
    {
        return Str::of($value)->lower()->replaceMatches('/[^a-z0-9]+/', ' ')->squish()->toString();
    }
}
