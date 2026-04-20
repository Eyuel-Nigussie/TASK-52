<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;
use App\Models\VisitReview;

class VisitReviewPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, VisitReview $review): bool
    {
        if ($user->isAdmin()) {
            return true;
        }
        if ($user->facility_id !== null) {
            return $user->facility_id === $review->facility_id;
        }
        return true;
    }

    public function publish(User $user, VisitReview $review): bool
    {
        return $user->isManager() && $this->view($user, $review);
    }

    public function hide(User $user, VisitReview $review): bool
    {
        return $user->isManager() && $this->view($user, $review);
    }

    public function respond(User $user, VisitReview $review): bool
    {
        return $user->isManager() && $this->view($user, $review);
    }

    public function appeal(User $user, VisitReview $review): bool
    {
        return $user->isManager() && $this->view($user, $review);
    }
}
