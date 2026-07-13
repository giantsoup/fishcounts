<?php

namespace Tests\Feature;

use App\Enums\ParserBugReportStatus;
use App\Models\ParserBugReport;
use App\Models\ParserBugReportOccurrence;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ParserBugReportModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_schema_contains_the_audit_and_review_linkage_constraints(): void
    {
        $this->assertTrue(Schema::hasColumns('parser_bug_reports', [
            'signature',
            'parser_diagnostic_review_id',
            'review_attempt',
            'issue_number',
            'issue_url',
            'issue_state',
            'occurrence_count',
            'first_seen_at',
            'last_seen_at',
            'invalidated_at',
        ]));
        $this->assertTrue(Schema::hasColumns('parser_bug_report_occurrences', [
            'parser_bug_report_id',
            'parser_diagnostic_review_id',
            'parser_error_id',
            'review_attempt',
            'seen_at',
            'invalidated_at',
        ]));
        $this->assertTrue(Schema::hasColumn('parser_diagnostic_reviews', 'parser_bug_report_id'));
    }

    public function test_model_defaults_casts_guarding_and_occurrence_relationships_are_consistent(): void
    {
        $approver = User::factory()->admin()->create();
        $report = ParserBugReport::factory()->create([
            'approved_by_user_id' => $approver->id,
            'approved_at' => now(),
        ]);
        $occurrence = ParserBugReportOccurrence::factory()->create([
            'parser_bug_report_id' => $report->id,
            'review_attempt' => 2,
        ]);

        $this->assertSame(ParserBugReportStatus::Preview, $report->status);
        $this->assertTrue($report->requires_approval);
        $this->assertSame(0, $report->occurrence_count);
        $this->assertIsArray($report->labels);
        $this->assertNotNull($report->approved_at);
        $this->assertTrue($report->approver->is($approver));
        $this->assertTrue($report->occurrences->contains($occurrence));
        $this->assertTrue($occurrence->parserBugReport->is($report));
        $this->assertSame(2, $occurrence->review_attempt);
        $this->assertNotNull($occurrence->seen_at);

        $guarded = new ParserBugReport;
        $guarded->fill(['id' => 999, 'signature' => str_repeat('b', 64)]);
        $this->assertNull($guarded->getAttribute('id'));
        $this->assertSame(str_repeat('b', 64), $guarded->signature);
    }
}
