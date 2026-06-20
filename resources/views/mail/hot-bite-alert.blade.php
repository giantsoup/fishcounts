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
| Total fish | {{ $scoreResult?->total_count ?? 'n/a' }} |
| Boats reporting | {{ $scoreResult?->boat_count ?? 'n/a' }} |
| Landings reporting | {{ $scoreResult?->landing_count ?? 'n/a' }} |
| Fish / angler | {{ $countPerAngler }} |
</x-mail::table>

<x-mail::button :url="$scoresUrl">
View scores
</x-mail::button>
</x-mail::message>
