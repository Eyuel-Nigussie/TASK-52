<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\ScopesByFacility;
use App\Http\Controllers\Controller;
use App\Models\ReviewAppeal;
use App\Models\Visit;
use App\Models\VisitReview;
use App\Services\ReviewService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ReviewController extends Controller
{
    use ScopesByFacility;

    public function __construct(private readonly ReviewService $reviewService) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', VisitReview::class);

        $query = VisitReview::with(['images', 'response', 'appeals', 'doctor'])
            ->when($request->filled('doctor_id'), fn($q) => $q->where('doctor_id', $request->doctor_id))
            ->when($request->filled('status'), fn($q) => $q->where('status', $request->status))
            ->when($request->filled('rating'), fn($q) => $q->where('rating', $request->rating))
            ->orderByDesc('submitted_at');

        $this->applyFacilityScope($query, $request->user(), $request->integer('facility_id') ?: null);

        return response()->json($query->paginate($request->integer('per_page', 20)));
    }

    /**
     * Public endpoint — declared outside the auth group in routes/api.php.
     * Scoped by visit id + one-time review_token. No authenticated user is expected.
     * The token is generated when the visit is marked completed and is consumed
     * (nullified) on first use so replay is impossible.
     */
    public function submit(Request $request, Visit $visit): JsonResponse
    {
        $data = $request->validate([
            'review_token'       => 'required|string',
            'rating'             => 'required|integer|min:1|max:5',
            'body'               => 'nullable|string|max:5000',
            'tags'               => 'nullable|array',
            'submitted_by_name'  => 'nullable|string|max:150',
            'images'             => 'nullable|array|max:5',
            'images.*'           => 'image|max:' . (config('vetops.upload_max_mb', 20) * 1024),
        ]);

        if (!$visit->review_token || !hash_equals($visit->review_token, $data['review_token'])) {
            abort(403, 'Invalid or already-used review token.');
        }

        // Consume the token so it cannot be replayed.
        $visit->update(['review_token' => null]);

        $review = $this->reviewService->submit(
            visit: $visit,
            rating: $data['rating'],
            body: $data['body'] ?? null,
            tags: $data['tags'] ?? [],
            submitterName: $data['submitted_by_name'] ?? null,
            images: $request->file('images') ?? [],
        );

        return response()->json($review, 201);
    }

    public function show(Request $request, VisitReview $visitReview): JsonResponse
    {
        $this->authorize('view', $visitReview);

        $visitReview->load(['images', 'response', 'appeals', 'doctor', 'visit.patient']);
        return response()->json($visitReview);
    }

    public function publish(Request $request, VisitReview $visitReview): JsonResponse
    {
        $this->authorize('publish', $visitReview);
        $review = $this->reviewService->publish($visitReview, $request->user()->id);
        return response()->json($review);
    }

    public function hide(Request $request, VisitReview $visitReview): JsonResponse
    {
        $this->authorize('hide', $visitReview);
        $data = $request->validate(['reason' => 'required|string|min:10']);
        $review = $this->reviewService->hide($visitReview, $request->user()->id, $data['reason']);
        return response()->json($review);
    }

    public function respond(Request $request, VisitReview $visitReview): JsonResponse
    {
        $this->authorize('respond', $visitReview);
        $data = $request->validate(['body' => 'required|string|max:2000']);
        $response = $this->reviewService->respond($visitReview, $request->user()->id, $data['body']);
        return response()->json($response);
    }

    public function appeal(Request $request, VisitReview $visitReview): JsonResponse
    {
        $this->authorize('appeal', $visitReview);
        $data = $request->validate(['reason' => 'required|string|min:10|max:2000']);
        $appeal = $this->reviewService->appeal($visitReview, $request->user()->id, $data['reason']);
        return response()->json($appeal, 201);
    }

    public function resolveAppeal(Request $request, ReviewAppeal $reviewAppeal): JsonResponse
    {
        $this->authorize('appeal', $reviewAppeal->review);
        $data = $request->validate(['resolution' => 'required|string|min:10|max:2000']);
        $appeal = $this->reviewService->resolveAppeal($reviewAppeal, $request->user()->id, $data['resolution']);
        return response()->json($appeal);
    }

    public function dashboard(Request $request): JsonResponse
    {
        $user = $request->user();
        $requestedFacilityId = $request->integer('facility_id') ?: null;

        if (!$user->isAdmin()) {
            if ($user->facility_id === null) {
                abort(403, 'Account has no facility assignment.');
            }
            $facilityId = $user->facility_id;
        } else {
            $request->validate(['facility_id' => 'required|exists:facilities,id']);
            $facilityId = $requestedFacilityId;
        }

        $stats = $this->reviewService->getDashboardStats(
            (int) $facilityId,
            $request->filled('doctor_id') ? (int) $request->doctor_id : null,
        );
        return response()->json($stats);
    }

    /**
     * Breakdown dashboard surface: overall + per-clinic + per-provider rows.
     * Returned shape is stable for the reviews view so managers see facility
     * AND provider performance at once.
     */
    public function dashboardBreakdown(Request $request): JsonResponse
    {
        $user = $request->user();
        $facilityId = $request->filled('facility_id') ? (int) $request->facility_id : null;

        $breakdown = $this->reviewService->getBreakdownDashboard($user, $facilityId);

        return response()->json($breakdown);
    }
}
