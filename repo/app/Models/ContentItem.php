<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class ContentItem extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'type', 'title', 'slug', 'body', 'excerpt', 'status', 'version',
        'parent_id', 'author_id', 'approved_by', 'published_at', 'expires_at',
        'facility_ids', 'department_ids', 'role_targets', 'tags',
        'simhash', 'priority',
    ];

    protected function casts(): array
    {
        return [
            'facility_ids' => 'array',
            'department_ids' => 'array',
            'role_targets' => 'array',
            'tags' => 'array',
            'published_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    public function versions(): HasMany
    {
        return $this->hasMany(ContentVersion::class)->orderByDesc('version');
    }

    public function media(): HasMany
    {
        return $this->hasMany(ContentMedia::class)->orderBy('sort_order');
    }

    public function isPublished(): bool
    {
        return $this->status === 'published'
            && ($this->published_at === null || $this->published_at->isPast())
            && ($this->expires_at === null || $this->expires_at->isFuture());
    }

    public function scopePublished($query): mixed
    {
        return $query->where('status', 'published')
            ->where(fn($q) => $q->whereNull('published_at')->orWhere('published_at', '<=', now()))
            ->where(fn($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()));
    }

    /**
     * Filter content to what the user is allowed to see. All four targeting
     * axes (facility, department, role, tags) behave as "if the list is set,
     * the user must match; if the list is null/empty, the axis is unrestricted."
     * An optional $userTags list lets callers filter by a user's interest tags
     * (e.g. subscribed categories) — if omitted, tag-targeted content is only
     * shown when the item has no tags set.
     */
    public function scopeForUser($query, User $user, array $userTags = []): mixed
    {
        return $query->where(function ($q) use ($user) {
            $q->whereJsonContains('facility_ids', $user->facility_id)
              ->orWhereNull('facility_ids');
        })->where(function ($q) use ($user) {
            $q->whereJsonContains('role_targets', $user->role)
              ->orWhereNull('role_targets');
        })->where(function ($q) use ($user) {
            $q->whereNull('department_ids');
            if ($user->department_id !== null) {
                $q->orWhereJsonContains('department_ids', $user->department_id);
            }
        })->where(function ($q) use ($userTags) {
            $q->whereNull('tags');
            foreach ($userTags as $tag) {
                $q->orWhereJsonContains('tags', $tag);
            }
        });
    }
}
