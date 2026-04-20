<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\ContentItem;
use App\Models\ContentVersion;
use App\Models\User;
use App\Services\ContentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * Unit-level coverage for ContentService. Locks down the draft/update
 * version-incrementing invariant, near-duplicate detection via SimHash,
 * and the force_create bypass. Reused by ContentPublishingTest for
 * end-to-end HTTP coverage.
 */
class ContentServiceTest extends TestCase
{
    use RefreshDatabase;

    private ContentService $content;

    protected function setUp(): void
    {
        parent::setUp();
        $this->content = app(ContentService::class);
    }

    public function test_draft_creates_initial_version_row_and_stores_simhash(): void
    {
        $user = User::factory()->contentEditor()->create();

        $item = $this->content->draft('announcement', 'New Title', 'Quite a long body of content here.', $user->id);

        $this->assertEquals('draft', $item->status);
        $this->assertNotNull($item->simhash);
        $this->assertEquals(1, ContentVersion::where('content_item_id', $item->id)->count());
    }

    public function test_draft_detects_near_duplicate_content(): void
    {
        $user = User::factory()->contentEditor()->create();
        $body = 'Please join the weekly staff huddle every Tuesday at 9am for updates and coffee.';

        $this->content->draft('announcement', 'First', $body, $user->id);

        try {
            $this->content->draft('announcement', 'Second', $body, $user->id);
            $this->fail('Expected near-duplicate detection.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('body', $e->errors());
        }
    }

    public function test_draft_force_create_bypasses_near_duplicate_check(): void
    {
        $user = User::factory()->contentEditor()->create();
        $body = 'Equipment calibration scheduled monthly — please mark your calendars accordingly.';

        $this->content->draft('announcement', 'First', $body, $user->id);
        $second = $this->content->draft('announcement', 'Second', $body, $user->id, ['force_create' => true]);

        $this->assertNotNull($second->id);
    }

    public function test_update_increments_version_and_records_change_note(): void
    {
        $user = User::factory()->contentEditor()->create();
        $item = $this->content->draft('announcement', 'T', 'Body one of the announcement copy here.', $user->id);

        $updated = $this->content->update($item, ['body' => 'Body two new copy'], $user->id, 'Copy change');

        $this->assertEquals(2, $updated->version);
        $this->assertDatabaseHas('content_versions', [
            'content_item_id' => $item->id,
            'version'         => 2,
            'change_note'     => 'Copy change',
        ]);
    }

    public function test_submit_for_review_rejects_non_draft(): void
    {
        $this->expectException(ValidationException::class);

        $user = User::factory()->contentEditor()->create();
        $item = ContentItem::factory()->create(['status' => 'published']);

        $this->content->submitForReview($item, $user->id);
    }

    public function test_approve_rejects_non_review_items(): void
    {
        $this->expectException(ValidationException::class);

        $user = User::factory()->contentApprover()->create();
        $item = ContentItem::factory()->create(['status' => 'draft']);

        $this->content->approve($item, $user->id);
    }

    public function test_publish_sets_published_at_timestamp(): void
    {
        $user = User::factory()->contentApprover()->create();
        $item = ContentItem::factory()->create(['status' => 'approved']);

        $result = $this->content->publish($item, $user->id);

        $this->assertEquals('published', $result->status);
        $this->assertNotNull($result->published_at);
    }

    public function test_rollback_restores_prior_version_body(): void
    {
        $user = User::factory()->contentEditor()->create();
        $item = $this->content->draft('announcement', 'T', 'Original body content one two three four five.', $user->id);
        $this->content->update($item, ['body' => 'Changed body content six seven eight nine ten.'], $user->id, 'rev');

        $restored = $this->content->rollback($item->fresh(), 1, $user->id);

        $this->assertStringContainsString('Original body', $restored->body);
    }
}
