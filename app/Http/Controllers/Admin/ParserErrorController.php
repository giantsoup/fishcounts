<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ParserError;
use Illuminate\Contracts\View\View;

class ParserErrorController extends Controller
{
    public function __invoke(): View
    {
        return view('admin.parser-errors.index', ['errors' => ParserError::query()->latest()->paginate(25)]);
    }
}
