<?php

namespace App\Enums;

enum ParserDiagnosticType: string
{
    case UnknownAlias = 'unknown_alias';
    case FractionalTripConflict = 'fractional_trip_conflict';
    case ProseCapturedAsEntity = 'prose_captured_as_entity';
    case ExcessiveNameLength = 'excessive_name_length';
    case UnaccountedNumericTokens = 'unaccounted_numeric_tokens';
    case EmptyOrUnexpectedlySmallResultSet = 'empty_or_unexpectedly_small_result_set';
    case StructuredSourceFallback = 'structured_source_fallback';
    case ExtractedValueSourceSpanMismatch = 'extracted_value_source_span_mismatch';

    public function isEstablished(): bool
    {
        return $this === self::UnknownAlias;
    }
}
