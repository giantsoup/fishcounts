<!-- parser-bug-signature: {{ $signature }} -->

## Summary

A validated parser diagnostic identified a `{{ $classification }}` defect in `{{ $sourceSlug }}`.

## Source and parser

- Source: `{{ $sourceSlug }}`
- Source URL: {{ $sourceUrl !== '' ? $sourceUrl : 'Not available' }}
- Parser version: `{{ $parserVersion }}`
- Diagnostic: `{{ $diagnosticType }}`
- Affected field: `{{ $field }}`
- Review: `#{{ $reviewId }}` at `{{ $confidence }}` confidence

## Minimal reproduction

<pre><code>{{ $paragraph !== '' ? $paragraph : 'No sanitized paragraph was available.' }}</code></pre>

## Actual parse

<pre><code>{{ $actualParse }}</code></pre>

## Expected parse

Apply these validated corrections:

<pre><code>{{ $expectedParse }}</code></pre>

## Deterministic evidence

<pre><code>{{ $evidence }}</code></pre>

## Regression-test suggestion

Add a source-adapter or parsing-pipeline fixture for the minimal reproduction above. Assert the corrected `{{ $field }}` output and that the `{{ $diagnosticType }}` diagnostic no longer occurs.

## Acceptance criteria

- The deterministic parser produces the expected corrected values.
- Existing clean fixtures for `{{ $sourceSlug }}` continue to pass.
- A focused regression test covers this reproduction.
- No AI call is required for the corrected parse.

## Copy-ready Codex task

<pre><code>Investigate and fix parser bug signature {{ $signature }} in the {{ $sourceSlug }} parser. Reproduce it with the sanitized paragraph and deterministic evidence in this issue, implement the smallest source-appropriate parser correction, add a focused PHPUnit regression test for the actual and expected parse shown above, run that test, and run vendor/bin/pint --dirty --format agent. Do not change unrelated parsing behavior or use AI output at runtime.</code></pre>
