@props(['conditions', 'context' => null])

@if ($conditions === null)
### Conditions unavailable

Official condition data is not available for this period.
@elseif (! $conditions['available'])
### {{ $conditions['heading'] }}

Official conditions for **{{ $conditions['location_label'] }}** are not available for {{ $conditions['date_display'] }}. No local reading was substituted.
@else
### {{ $conditions['heading'] }}

**@if ($context){{ $context }} · @endif{{ $conditions['location_label'] }} · {{ $conditions['date_display'] }}**

@if ($conditions['has_readings'])
<x-mail::table>
| Measure | Reading |
| :-- | :-- |
@if ($conditions['water_temperature'])
| Water temperature | {{ $conditions['water_temperature'] }} |
@endif
@if ($conditions['swell'])
| Swell | {{ $conditions['swell'] }} |
@endif
@if ($conditions['waves'])
| Waves | {{ $conditions['waves'] }} |
@endif
@if ($conditions['moon'])
| Moon | {{ $conditions['moon'] }} |
@endif
@if ($conditions['tides'])
| Tides | {{ $conditions['tides'] }} |
@endif
</x-mail::table>
@else
Official data was collected, but no complete condition readings are available for this date.
@endif

@if ($conditions['source_note'])
_Location note: {{ $conditions['source_note'] }}_
@endif

@if ($conditions['is_partial'])
_Some official observations were unavailable for this date._
@endif
@endif
