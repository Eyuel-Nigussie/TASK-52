<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\ContentItem;
use App\Models\ContentVersion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ContentPublishingTest extends TestCase
{
    use RefreshDatabase;

    public function test_editor_can_create_draft(): void
    {
        $this->actingAsContentEditor();

        $response = $this->postJson('/api/content', [
            'type'  => 'announcement',
            'title' => 'Important Announcement',
            'body'  => 'This is a very important announcement for all staff members.',
        ]);

        $response->assertStatus(201)->assertJsonPath('status', 'draft');
        $this->assertDatabaseHas('content_versions', ['version' => 1]);
    }

    public function test_can_submit_for_review(): void
    {
        $editor = $this->actingAsContentEditor();
        $item = ContentItem::factory()->create(['status' => 'draft', 'author_id' => $editor->id]);

        $response = $this->postJson("/api/content/{$item->id}/submit-review");
        $response->assertStatus(200)->assertJsonPath('status', 'in_review');
    }

    public function test_approver_can_approve_content(): void
    {
        $this->actingAsContentApprover();
        $item = ContentItem::factory()->create(['status' => 'in_review']);

        $response = $this->postJson("/api/content/{$item->id}/approve");
        $response->assertStatus(200)->assertJsonPath('status', 'approved');
    }

    public function test_editor_cannot_approve_content(): void
    {
        $this->actingAsContentEditor();
        $item = ContentItem::factory()->create(['status' => 'in_review']);

        $response = $this->postJson("/api/content/{$item->id}/approve");
        $response->assertStatus(403);
    }

    public function test_approver_can_publish_content(): void
    {
        $this->actingAsContentApprover();
        $item = ContentItem::factory()->create(['status' => 'approved']);

        $response = $this->postJson("/api/content/{$item->id}/publish");
        $response->assertStatus(200)->assertJsonPath('status', 'published');
    }

    public function test_update_creates_new_version(): void
    {
        $editor = $this->actingAsContentEditor();
        $item = ContentItem::factory()->create(['status' => 'draft', 'version' => 1, 'author_id' => $editor->id]);

        ContentVersion::create([
            'content_item_id' => $item->id,
            'version'         => 1,
            'title'           => $item->title,
            'body'            => $item->body,
            'changed_by'      => $editor->id,
            'change_note'     => 'Initial draft',
        ]);

        $response = $this->putJson("/api/content/{$item->id}", [
            'title' => 'Updated Title',
            'body'  => 'Updated body content here for staff.',
        ]);

        $response->assertStatus(200)->assertJsonPath('version', 2);
        $this->assertEquals(2, ContentVersion::where('content_item_id', $item->id)->count());
    }

    public function test_can_rollback_to_previous_version(): void
    {
        $editor = $this->actingAsContentEditor();
        $item = ContentItem::factory()->create(['status' => 'draft', 'version' => 1, 'author_id' => $editor->id]);

        ContentVersion::create([
            'content_item_id' => $item->id,
            'version'         => 1,
            'title'           => 'Original Title',
            'body'            => 'Original body content',
            'changed_by'      => $editor->id,
        ]);

        $this->putJson("/api/content/{$item->id}", ['title' => 'Changed Title', 'body' => 'Changed body.']);

        $response = $this->postJson("/api/content/{$item->id}/rollback", ['version' => 1]);
        $response->assertStatus(200)->assertJsonPath('title', 'Original Title');
    }

    public function test_near_duplicate_detection_blocks_similar_content(): void
    {
        $editor = $this->actingAsContentEditor();

        $this->postJson('/api/content', [
            'type'  => 'announcement',
            'title' => 'Staff Meeting Tomorrow',
            'body'  => 'Please join us for the staff meeting tomorrow at 10 AM in conference room B.',
        ]);

        $response = $this->postJson('/api/content', [
            'type'  => 'announcement',
            'title' => 'Staff Meeting Tomorrow Again',
            'body'  => 'Please join us for the staff meeting tomorrow at 10 AM in conference room B.',
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors(['body']);
    }

    public function test_force_create_bypasses_dedup_check(): void
    {
        $this->actingAsContentEditor();

        $this->postJson('/api/content', [
            'type'  => 'announcement',
            'title' => 'Meeting Notice',
            'body'  => 'Please attend the meeting on Friday at 2pm in the conference room.',
        ]);

        $response = $this->postJson('/api/content', [
            'type'         => 'announcement',
            'title'        => 'Meeting Notice 2',
            'body'         => 'Please attend the meeting on Friday at 2pm in the conference room.',
            'force_create' => true,
        ]);

        $response->assertStatus(201);
    }

    public function test_published_content_visible_to_all_authenticated(): void
    {
        ContentItem::factory()->published()->create(['type' => 'announcement']);
        $this->actingAsTechnicianDoctor();

        $response = $this->getJson('/api/content/published?type=announcement');
        $response->assertStatus(200);
        $this->assertGreaterThan(0, count($response->json()));
    }

    public function test_content_index_filters_by_status(): void
    {
        $this->actingAsContentApprover();
        ContentItem::factory()->count(2)->create(['status' => 'draft']);
        ContentItem::factory()->count(3)->create(['status' => 'published']);

        $response = $this->getJson('/api/content?status=draft');

        $response->assertStatus(200)
            ->assertJsonPath('total', 2);
    }

    public function test_content_index_filters_by_type(): void
    {
        $this->actingAsContentApprover();
        ContentItem::factory()->count(2)->create(['type' => 'announcement']);
        ContentItem::factory()->count(1)->create(['type' => 'carousel']);

        $response = $this->getJson('/api/content?type=carousel');

        $response->assertStatus(200)
            ->assertJsonPath('total', 1);
    }

    public function test_content_search_by_title(): void
    {
        $this->actingAsContentApprover();
        ContentItem::factory()->create(['title' => 'Annual Team Meeting']);
        ContentItem::factory()->create(['title' => 'Security Policy Update']);

        $response = $this->getJson('/api/content?search=Annual');

        $response->assertStatus(200)
            ->assertJsonPath('total', 1);
    }

    public function test_versions_endpoint_returns_version_history(): void
    {
        $editor = $this->actingAsContentEditor();
        $item = ContentItem::factory()->create(['status' => 'draft', 'version' => 1, 'author_id' => $editor->id]);
        ContentVersion::create([
            'content_item_id' => $item->id,
            'version'         => 1,
            'title'           => $item->title,
            'body'            => $item->body,
            'changed_by'      => $editor->id,
        ]);

        $response = $this->getJson("/api/content/{$item->id}/versions");

        $response->assertStatus(200);
        $this->assertGreaterThanOrEqual(1, count($response->json()));
    }

    public function test_destroy_archives_content(): void
    {
        $this->actingAsContentApprover();
        $item = ContentItem::factory()->create(['status' => 'published']);

        $response = $this->deleteJson("/api/content/{$item->id}");

        $response->assertStatus(200)
            ->assertJsonPath('message', 'Content archived.');
        $this->assertDatabaseHas('content_items', ['id' => $item->id, 'status' => 'archived']);
    }

    public function test_editor_cannot_publish(): void
    {
        $this->actingAsContentEditor();
        $item = ContentItem::factory()->create(['status' => 'approved']);

        $response = $this->postJson("/api/content/{$item->id}/publish");

        $response->assertStatus(403);
    }

    public function test_technician_cannot_create_content(): void
    {
        $this->actingAsTechnicianDoctor();

        $response = $this->postJson('/api/content', [
            'type'  => 'announcement',
            'title' => 'Unauthorized attempt',
            'body'  => 'This user should not be allowed to create content items at all.',
        ]);

        $response->assertStatus(403);
    }

    public function test_rollback_requires_version_number(): void
    {
        $editor = $this->actingAsContentEditor();
        $item = ContentItem::factory()->create(['status' => 'draft', 'author_id' => $editor->id]);

        $response = $this->postJson("/api/content/{$item->id}/rollback", []);

        $response->assertStatus(422)->assertJsonValidationErrors(['version']);
    }

    public function test_publish_accepts_scheduled_date(): void
    {
        $this->actingAsContentApprover();
        $item = ContentItem::factory()->create(['status' => 'approved']);
        $scheduleAt = now()->addDays(7)->toIso8601String();

        $response = $this->postJson("/api/content/{$item->id}/publish", [
            'publish_at' => $scheduleAt,
        ]);

        $response->assertStatus(200);
    }

    public function test_uploading_zero_files_fails(): void
    {
        $this->actingAsContentEditor();
        $item = ContentItem::factory()->create(['status' => 'draft']);

        $response = $this->postJson("/api/content/{$item->id}/media", ['files' => []]);

        $response->assertStatus(422)->assertJsonValidationErrors(['files']);
    }

    public function test_published_feed_hides_content_targeted_to_other_department(): void
    {
        $user = $this->actingAsTechnicianDoctor();
        $user->department_id = 1;
        $user->save();

        // Targeted at a different department — must be filtered out.
        ContentItem::factory()->published()->create([
            'type'           => 'announcement',
            'title'          => 'Nursing-only update',
            'department_ids' => [99],
        ]);
        // Targeted at the user's department — must appear.
        ContentItem::factory()->published()->create([
            'type'           => 'announcement',
            'title'          => 'Department 1 update',
            'department_ids' => [1],
        ]);
        // No department targeting — must appear.
        ContentItem::factory()->published()->create([
            'type'           => 'announcement',
            'title'          => 'Global notice',
        ]);

        $response = $this->getJson('/api/content/published?type=announcement');
        $response->assertStatus(200);

        $titles = array_column($response->json(), 'title');
        $this->assertContains('Department 1 update', $titles);
        $this->assertContains('Global notice', $titles);
        $this->assertNotContains('Nursing-only update', $titles);
    }

    public function test_show_content_item_returns_detail_with_versions_and_media(): void
    {
        $this->actingAsContentApprover();
        $item = ContentItem::factory()->create([
            'status'  => 'draft',
            'title'   => 'Detail Visible',
            'version' => 2,
        ]);
        ContentVersion::create([
            'content_item_id' => $item->id,
            'version'         => 1,
            'title'           => 'Detail Visible',
            'body'            => $item->body,
            'changed_by'      => $item->author_id ?? 1,
        ]);

        $response = $this->getJson("/api/content/{$item->id}");

        $response->assertStatus(200)
            ->assertJsonPath('id', $item->id)
            ->assertJsonPath('title', 'Detail Visible')
            ->assertJsonStructure(['id', 'title', 'status', 'versions', 'media']);
    }

    public function test_show_content_returns_404_for_missing_item(): void
    {
        $this->actingAsContentApprover();

        $this->getJson('/api/content/9999999')->assertStatus(404);
    }

    public function test_published_feed_filters_tag_targeted_content_without_matching_tags(): void
    {
        $this->actingAsTechnicianDoctor();

        ContentItem::factory()->published()->create([
            'type'  => 'announcement',
            'title' => 'Cardiology-only',
            'tags'  => ['cardiology'],
        ]);
        ContentItem::factory()->published()->create([
            'type'  => 'announcement',
            'title' => 'Everyone',
        ]);

        // No tags filter — tag-targeted content must be hidden.
        $default = $this->getJson('/api/content/published?type=announcement');
        $default->assertStatus(200);
        $titles = array_column($default->json(), 'title');
        $this->assertContains('Everyone', $titles);
        $this->assertNotContains('Cardiology-only', $titles);

        // Opt-in via ?tags= — tagged content now visible.
        $opted = $this->getJson('/api/content/published?type=announcement&tags=cardiology');
        $opted->assertStatus(200);
        $titles = array_column($opted->json(), 'title');
        $this->assertContains('Cardiology-only', $titles);
        $this->assertContains('Everyone', $titles);
    }
}
