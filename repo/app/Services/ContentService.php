<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\ContentItem;
use App\Models\ContentVersion;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ContentService
{
    public function __construct(
        private readonly AuditService $audit,
        private readonly DeduplicationService $dedup,
    ) {}

    public function draft(
        string $type,
        string $title,
        string $body,
        int $authorId,
        array $options = []
    ): ContentItem {
        $simhash = $this->dedup->computeSimHash($body);

        $similar = ContentItem::where('type', $type)
            ->whereNotIn('status', ['archived'])
            ->whereNotNull('simhash')
            ->get()
            ->first(fn(ContentItem $item) => $this->dedup->isSimilar($item->simhash, $simhash));

        if ($similar && empty($options['force_create'])) {
            throw ValidationException::withMessages([
                'body' => ["Near-duplicate content detected (similar to content ID {$similar->id}). Use force_create to override."],
            ]);
        }

        return DB::transaction(function () use ($type, $title, $body, $authorId, $options, $simhash) {
            $slug = $this->generateUniqueSlug($title);

            $item = ContentItem::create([
                'type'           => $type,
                'title'          => $title,
                'slug'           => $slug,
                'body'           => $body,
                'excerpt'        => $options['excerpt'] ?? Str::limit(strip_tags($body), 200),
                'status'         => 'draft',
                'version'        => 1,
                'author_id'      => $authorId,
                'facility_ids'   => $options['facility_ids'] ?? null,
                'department_ids' => $options['department_ids'] ?? null,
                'role_targets'   => $options['role_targets'] ?? null,
                'tags'           => $options['tags'] ?? null,
                'simhash'        => $simhash,
                'priority'       => $options['priority'] ?? 0,
            ]);

            ContentVersion::create([
                'content_item_id' => $item->id,
                'version'         => 1,
                'title'           => $title,
                'body'            => $body,
                'changed_by'      => $authorId,
                'change_note'     => 'Initial draft',
            ]);

            $this->audit->logModel('content.draft', $item);

            return $item;
        });
    }

    public function update(ContentItem $item, array $data, int $changedBy, string $changeNote = ''): ContentItem
    {
        return DB::transaction(function () use ($item, $data, $changedBy, $changeNote) {
            $oldVersion = $item->version;
            $newVersion = $oldVersion + 1;

            $simhash = isset($data['body']) ? $this->dedup->computeSimHash($data['body']) : $item->simhash;

            $item->update(array_merge($data, [
                'version' => $newVersion,
                'simhash' => $simhash,
            ]));

            ContentVersion::create([
                'content_item_id' => $item->id,
                'version'         => $newVersion,
                'title'           => $data['title'] ?? $item->title,
                'body'            => $data['body'] ?? $item->body,
                'changed_by'      => $changedBy,
                'change_note'     => $changeNote,
            ]);

            $this->audit->logModel('content.update', $item);

            return $item->refresh();
        });
    }

    public function submitForReview(ContentItem $item, int $submittedBy): ContentItem
    {
        if ($item->status !== 'draft') {
            throw ValidationException::withMessages([
                'status' => ['Only draft items can be submitted for review.'],
            ]);
        }

        $item->update(['status' => 'in_review']);
        $this->audit->logModel('content.submit_review', $item, ['status' => 'draft'], ['status' => 'in_review']);

        return $item->refresh();
    }

    public function approve(ContentItem $item, int $approvedBy): ContentItem
    {
        if ($item->status !== 'in_review') {
            throw ValidationException::withMessages([
                'status' => ['Only items in review can be approved.'],
            ]);
        }

        $item->update(['status' => 'approved', 'approved_by' => $approvedBy]);
        $this->audit->logModel('content.approve', $item, ['status' => 'in_review'], ['status' => 'approved']);

        return $item->refresh();
    }

    public function publish(ContentItem $item, int $publishedBy, ?\DateTime $publishAt = null): ContentItem
    {
        // Publish must only succeed from `approved`. Allowing `draft` would
        // bypass the review-and-approve workflow the prompt requires.
        if ($item->status !== 'approved') {
            throw ValidationException::withMessages([
                'status' => ['Only approved items can be published.'],
            ]);
        }

        $item->update([
            'status'       => 'published',
            'published_at' => $publishAt ?? now(),
        ]);
        $this->audit->logModel('content.publish', $item);

        return $item->refresh();
    }

    public function rollback(ContentItem $item, int $targetVersion, int $rolledBackBy): ContentItem
    {
        $version = ContentVersion::where('content_item_id', $item->id)
            ->where('version', $targetVersion)
            ->first();

        if (!$version) {
            throw ValidationException::withMessages([
                'version' => ["Version {$targetVersion} not found."],
            ]);
        }

        return $this->update($item, [
            'title'  => $version->title,
            'body'   => $version->body,
            'status' => 'draft',
        ], $rolledBackBy, "Rolled back to version {$targetVersion}");
    }

    private function generateUniqueSlug(string $title): string
    {
        $base = Str::slug($title);
        $slug = $base;
        $i = 1;
        while (ContentItem::where('slug', $slug)->exists()) {
            $slug = "{$base}-{$i}";
            $i++;
        }
        return $slug;
    }
}
