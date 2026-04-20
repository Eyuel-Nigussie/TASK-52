<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\ContentItem;
use App\Models\User;

class ContentItemPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, ContentItem $item): bool
    {
        if ($user->isAdmin()) {
            return true;
        }
        if (!is_array($item->facility_ids) || $item->facility_ids === []) {
            return true;
        }
        return in_array($user->facility_id, $item->facility_ids, true);
    }

    public function create(User $user): bool
    {
        return in_array($user->role, ['content_editor', 'content_approver', 'system_admin'], true);
    }

    public function update(User $user, ContentItem $item): bool
    {
        if ($user->role === 'content_editor' && $item->author_id !== null && $item->author_id !== $user->id) {
            return false;
        }
        return in_array($user->role, ['content_editor', 'content_approver', 'system_admin'], true);
    }

    public function submitForReview(User $user, ContentItem $item): bool
    {
        return $this->update($user, $item);
    }

    public function approve(User $user, ContentItem $item): bool
    {
        return in_array($user->role, ['content_approver', 'system_admin'], true);
    }

    public function publish(User $user, ContentItem $item): bool
    {
        return $this->approve($user, $item);
    }

    public function rollback(User $user, ContentItem $item): bool
    {
        return in_array($user->role, ['content_editor', 'content_approver', 'system_admin'], true);
    }

    public function uploadMedia(User $user, ContentItem $item): bool
    {
        return $this->update($user, $item);
    }

    public function delete(User $user, ContentItem $item): bool
    {
        return in_array($user->role, ['content_approver', 'system_admin'], true);
    }
}
