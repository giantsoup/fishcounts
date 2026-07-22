<?php

namespace App\Enums;

enum ParserReparseItemMode: string
{
    case DiagnosticsOnly = 'diagnostics_only';
    case Authoritative = 'authoritative';
}
