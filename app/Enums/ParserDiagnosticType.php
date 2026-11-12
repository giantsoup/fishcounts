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

    /**
     * Every error type a parser error can be stored under. Unknown aliases are never stored as
     * themselves: they are split into one type per entity (see CanonicalEntityType::aliasErrorType()).
     *
     * @return list<string>
     */
    public static function storedErrorTypes(): array
    {
        return [
            ...array_map(fn (CanonicalEntityType $entityType): string => $entityType->aliasErrorType(), CanonicalEntityType::cases()),
            ...array_values(array_map(
                fn (self $type): string => $type->value,
                array_filter(self::cases(), fn (self $type): bool => $type !== self::UnknownAlias),
            )),
        ];
    }
}
