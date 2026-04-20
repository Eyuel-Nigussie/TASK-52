<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\ReviewAppeal;
use App\Models\ReviewImage;
use App\Models\ReviewResponse;
use App\Models\User;
use App\Models\Visit;
use App\Models\VisitReview;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ReviewService
{
    public function __construct(
        private readonly AuditService $audit,
        private readonly FileStorageService $storage,
    ) {}

    public function submit(
        Visit $visit,
        int $rating,
        ?string $body,
        array $tags,
        ?string $submitterName,
        array $images = []
    ): VisitReview {
        if ($visit->status !== 'completed') {
            throw ValidationException::withMessages([
                'visit_id' => ['Reviews can only be submitted for completed visits.'],
            ]);
        }

        if ($visit->review()->exists()) {
            throw ValidationException::withMessages([
                'visit_id' => ['A review has already been submitted for this visit.'],
            ]);
        }

        if ($rating < 1 || $rating > 5) {
            throw ValidationException::withMessages([
                'rating' => ['Rating must be between 1 and 5.'],
            ]);
        }

        if (count($images) > 5) {
            throw ValidationException::withMessages([
                'images' => ['Maximum 5 images allowed.'],
            ]);
        }

        return DB::transaction(function () use ($visit, $rating, $body, $tags, $submitterName, $images) {
            $review = VisitReview::create([
                'visit_id'          => $visit->id,
                'facility_id'       => $visit->facility_id,
                'doctor_id'         => $visit->doctor_id,
                'rating'            => $rating,
                'tags'              => $tags,
                'body'              => $body,
                'status'            => 'pending',
                'submitted_at'      => now(),
                'submitted_by_name' => $submitterName,
            ]);

            foreach ($images as $i => $image) {
                $fileData = $this->storage->store($image, 'reviews');
                ReviewImage::create([
                    'review_id'  => $review->id,
                    'file_path'  => $fileData['path'],
                    'file_name'  => $fileData['file_name'],
                    'checksum'   => $fileData['checksum'],
                    'sort_order' => $i,
                ]);
            }

            $this->audit->logModel('review.submit', $review);

            return $review->load(['images']);
        });
    }

    public function publish(VisitReview $review, int $managerId): VisitReview
    {
        $review->update(['status' => 'published']);
        $this->audit->logModel('review.publish', $review, ['status' => 'pending'], ['status' => 'published']);
        return $review->refresh();
    }

    public function hide(VisitReview $review, int $managerId, string $reason): VisitReview
    {
        $review->update(['status' => 'hidden']);
        $this->audit->logModel('review.hide', $review, ['status' => $review->status], ['status' => 'hidden', 'reason' => $reason]);
        return $review->refresh();
    }

    public function respond(VisitReview $review, int $managerId, string $body): ReviewResponse
    {
        $response = ReviewResponse::updateOrCreate(
            ['review_id' => $review->id],
            ['manager_id' => $managerId, 'body' => $body]
        );
        $this->audit->logModel('review.respond', $review, null, ['manager_id' => $managerId]);
        return $response;
    }

    public function appeal(VisitReview $review, int $raisedBy, string $reason): ReviewAppeal
    {
        $review->update(['status' => 'appealed']);
        $appeal = ReviewAppeal::create([
            'review_id' => $review->id,
            'raised_by' => $raisedBy,
            'reason'    => $reason,
            'status'    => 'open',
        ]);
        $this->audit->logModel('review.appeal', $review, null, ['reason' => $reason]);
        return $appeal;
    }

    public function resolveAppeal(ReviewAppeal $appeal, int $resolvedBy, string $resolution): ReviewAppeal
    {
        $appeal->update([
            'status'          => 'resolved',
            'resolved_by'     => $resolvedBy,
            'resolution_note' => $resolution,
        ]);
        $this->audit->logModel('review.resolve_appeal', $appeal->review, null, ['resolution' => $resolution]);
        return $appeal->refresh();
    }

    public function getDashboardStats(int $facilityId, ?int $doctorId = null): array
    {
        $reviews = VisitReview::where('facility_id', $facilityId)
            ->where('status', 'published')
            ->when($doctorId !== null, fn($q) => $q->where('doctor_id', $doctorId))
            ->with('response')
            ->get();

        return $this->summarize($reviews);
    }

    /**
     * Breakdown dashboard: returns an overall summary plus per-facility and
     * per-provider rows. Managers get their own facility only; admins get
     * every facility (and can further filter by facility_id if they want).
     *
     * @return array{
     *   overall: array<string, mixed>,
     *   by_facility: list<array<string, mixed>>,
     *   by_provider: list<array<string, mixed>>,
     * }
     */
    public function getBreakdownDashboard(User $user, ?int $facilityId = null): array
    {
        $query = VisitReview::where('status', 'published')->with('response');

        if ($user->isAdmin()) {
            if ($facilityId !== null) {
                $query->where('facility_id', $facilityId);
            }
        } else {
            if ($user->facility_id === null) {
                // Unassigned non-admin: empty breakdown, no leakage.
                return ['overall' => $this->summarize(collect()), 'by_facility' => [], 'by_provider' => []];
            }
            $query->where('facility_id', $user->facility_id);
        }

        $reviews = $query->get();

        $byFacility = $reviews
            ->groupBy('facility_id')
            ->map(fn($rows, $fid) => array_merge(
                ['facility_id' => (int) $fid],
                $this->summarize($rows),
            ))
            ->values()
            ->all();

        $byProvider = $reviews
            ->groupBy('doctor_id')
            ->map(fn($rows, $did) => array_merge(
                ['doctor_id' => (int) $did],
                $this->summarize($rows),
            ))
            ->values()
            ->all();

        return [
            'overall'     => $this->summarize($reviews),
            'by_facility' => $byFacility,
            'by_provider' => $byProvider,
        ];
    }

    /**
     * Compute the shared summary shape from a collection of reviews.
     *
     * @param \Illuminate\Support\Collection<int, VisitReview> $reviews
     */
    private function summarize($reviews): array
    {
        $total = $reviews->count();

        if ($total === 0) {
            return [
                'total' => 0,
                'average_rating' => 0,
                'negative_review_rate' => 0,
                'median_response_time_hours' => null,
            ];
        }

        $avgRating = $reviews->avg('rating');
        $negativeCount = $reviews->filter(fn(VisitReview $r) => $r->isNegative())->count();
        $negativeRate = $negativeCount / $total * 100;

        $responseTimes = $reviews
            ->filter(fn(VisitReview $r) => $r->response !== null && $r->submitted_at !== null)
            ->map(fn(VisitReview $r) => $r->submitted_at->diffInSeconds($r->response->created_at) / 3600.0)
            ->sort()
            ->values();

        $medianHours = null;
        if ($responseTimes->isNotEmpty()) {
            $count = $responseTimes->count();
            $mid = intdiv($count, 2);
            $medianHours = $count % 2 === 0
                ? ($responseTimes[$mid - 1] + $responseTimes[$mid]) / 2
                : $responseTimes[$mid];
        }

        return [
            'total'                      => $total,
            'average_rating'             => round($avgRating, 2),
            'negative_review_rate'       => round($negativeRate, 2),
            'median_response_time_hours' => $medianHours !== null ? round($medianHours, 2) : null,
        ];
    }
}
