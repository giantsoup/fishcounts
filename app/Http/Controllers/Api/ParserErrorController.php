<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\ParserErrorResource;
use App\Models\ParserError;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ParserErrorController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        $errors = ParserError::query()
            ->with('scrapeSource')
            ->open()
            ->latest('id')
            ->paginate(50);

        return ParserErrorResource::collection($errors);
    }
}
