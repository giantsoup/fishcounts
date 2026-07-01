<x-mail::message>
# Hot bite threshold crossed

{{ $rule->name }}

<x-mail::panel>
**{{ $rule->species->name }}** reached **{{ $levelLabel }}** with a score of **{{ $alertEvent->score }}**.
</x-mail::panel>

<x-mail::table>
| Metric | Value |
| :-- | --: |
| Score date | {{ $alertEvent->event_date->toFormattedDateString() }} |
| Alert level | {{ $levelLabel }} |
| Score | {{ $alertEvent->score }} |
| Threshold | {{ $rule->minimum_score }} |
| Target fish | {{ $scoreResult?->total_count ?? 'n/a' }} |
| Boats reporting | {{ $scoreResult?->boat_count ?? 'n/a' }} |
| Landings reporting | {{ $scoreResult?->landing_count ?? 'n/a' }} |
</x-mail::table>

@if ($environmentalCondition)
**Official conditions:** {{ $environmentalCondition }}
@else
Official environmental conditions are not available for this alert date yet.
@endif

## Best trip options for {{ $rule->species->name }}

@if ($tripOptions->isEmpty())
No matching boat-level trip counts were found for this alert date.
@else
<x-mail::table>
| Date | Boat | Landing | Trip | Count |
| :-- | :-- | :-- | :-- | --: |
@foreach ($tripOptions as $trip)
| {{ $trip['trip_date'] }} | {{ $trip['boat_name'] }} | {{ $trip['landing_name'] }} | {{ $trip['trip_type'] }} | @if ($trip['source_url']) [{{ $trip['target_count'] }} ↗]({{ $trip['source_highlight_url'] ?? $trip['source_url'] }}) @else {{ $trip['target_count'] }} @endif |
@endforeach
</x-mail::table>

**Recommended boats**

@if ($tripRecommendations->isEmpty())
No booking links are available for the ranked boats.
@else
@foreach ($tripRecommendations as $trip)
- {{ $trip['boat_name'] }} - {{ $trip['trip_type'] }} on {{ $trip['trip_date'] }} from {{ $trip['landing_name'] }} ({{ $trip['target_count'] }} {{ $rule->species->name }}) - [Book]({{ $trip['booking_url'] }})
@endforeach
@endif
@endif

<x-mail::button :url="$scoresUrl">
View scores
</x-mail::button>
</x-mail::message>
