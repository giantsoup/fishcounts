<x-mail::message>
# Hot bite threshold crossed

{{ $rule->name }}

<x-mail::panel>
**{{ $rule->species->name }}** reached **{{ $levelLabel }}** with a score of **{{ $alertEvent->score }}**.
</x-mail::panel>

<x-mail::table>
| Metric | Value |
| :-- | --: |
| Score date | {{ $scoreResult?->score_date?->toFormattedDateString() ?? $alertEvent->event_date->toFormattedDateString() }} |
| Alert level | {{ $levelLabel }} |
| Score | {{ $alertEvent->score }} |
| Threshold | {{ $rule->minimum_score }} |
| Target fish | {{ $scoreResult?->total_count ?? 'n/a' }} |
| Boats reporting | {{ $scoreResult?->boat_count ?? 'n/a' }} |
| Landings reporting | {{ $scoreResult?->landing_count ?? 'n/a' }} |
</x-mail::table>

<x-mail.condition-summary :conditions="$environmentalCondition" context="Threshold day" />

## Best previous trips for {{ $rule->species->name }}

@if ($tripOptions->isEmpty())
No matching completed trips with {{ $rule->species->name }} catches were found for this alert date.
@else
<x-mail::table>
| Catch date | Boat | Landing | Trip | Count |
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
- {{ $trip['boat_name'] }} - {{ $trip['trip_type'] }} from {{ $trip['landing_name'] }} ({{ $trip['target_count'] }} {{ $rule->species->name }} caught {{ $trip['trip_date'] }}) - [{{ $trip['booking_is_direct'] ? 'Book next matching trip' : 'View booking options' }}]({{ $trip['booking_url'] }})
@if ($trip['booking_departure_at_display'])
  Next matching departure: **{{ $trip['booking_departure_at_display'] }}**.
@if ($trip['booking_open_spots'] !== null)
  {{ $trip['booking_open_spots'] }} spots open.
@endif
@if ($trip['booking_availability_pulled_at_display'])
  Availability checked {{ $trip['booking_availability_pulled_at_display'] }}.
@endif
@endif
@endforeach
@endif
@endif

<x-mail::button :url="$scoresUrl">
View scores
</x-mail::button>
</x-mail::message>
