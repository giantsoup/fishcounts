<?php

namespace App\Enums;

enum ParserDiagnosticReviewClassification: string
{
    case LegitimateAlias = 'legitimate_alias';
    case NewEntityCandidate = 'new_entity_candidate';
    case ParserBoundaryError = 'parser_boundary_error';
    case FractionalTripConflict = 'fractional_trip_conflict';
    case Clean = 'clean';
    case ValueExtractionError = 'value_extraction_error';
    case MissingReport = 'missing_report';
    case Uncertain = 'uncertain';
}
