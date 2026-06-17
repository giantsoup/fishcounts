<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Guarded;
use Illuminate\Database\Eloquent\Model;

#[Guarded(['id'])]
class ParserError extends Model
{
    protected function casts(): array
    {
        return [
            'target_date' => 'date',
            'context' => 'array',
            'resolved_at' => 'datetime',
        ];
    }
}
