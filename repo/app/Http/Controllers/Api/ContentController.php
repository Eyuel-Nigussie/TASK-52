<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ContentItem;
use App\Services\AuditService;
use App\Services\ContentService;
use App\Services\FileStorageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ContentController extends Controller
{
    public function __construct(
        private readonly ContentService $contentService,
        private readonly AuditService $audit,
        private readonly FileStorageService $storage,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', ContentItem::class);

        $user  = $request->user();
        $query = ContentItem::query()
            ->when($request->filled('type'), fn($q) => $q->where('type', $request->type))
            ->when($request->filled('status'), fn($q) => $q->where('status', $request->status))
            ->when($request->filled('search'), fn($q) => $q->where('title', 'like', '%' . $request->search . '%'))
            ->with(['media'])
            ->orderByDesc('updated_at');

        // Authoring staff (system_admin, content_editor, content_approver)
        // can see every workflow state for their facilities. Everyone else
        // is limited to content they would be able to see on the published
        // feed — no drafts, in-review, or cross-facility leakage.
        $authoringRoles = ['system_admin', 'content_editor', 'content_approver'];
        if (!in_array($user->role, $authoringRoles, true)) {
            $query->published()->forUser($user);
        } elseif (!$user->isAdmin() && $user->facility_id !== null) {
            // Facility-scoped authoring staff: only their own facility plus
            // global (no facility_ids) content. Editors without a facility
            // assignment (legacy accounts) fall through and see everything.
            $query->where(function ($q) use ($user) {
                $q->whereJsonContains('facility_ids', $user->facility_id)
                  ->orWhereNull('facility_ids');
            });
        }

        return response()->json($query->paginate($request->integer('per_page', 20)));
    }

    public function published(Request $request): JsonResponse
    {
        $user = $request->user();
        $tags = $request->input('tags', []);
        if (is_string($tags)) {
            $tags = array_filter(array_map('trim', explode(',', $tags)));
        }
        $items = ContentItem::published()
            ->when($user, fn($q) => $q->forUser($user, is_array($tags) ? $tags : []))
            ->when($request->filled('type'), fn($q) => $q->where('type', $request->type))
            ->with(['media'])
            ->orderByDesc('priority')
            ->orderByDesc('published_at')
            ->get();

        return response()->json($items);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorize('create', ContentItem::class);

        $data = $request->validate([
            'type'           => 'required|in:announcement,carousel',
            'title'          => 'required|string|max:255',
            'body'           => 'required|string',
            'excerpt'        => 'nullable|string|max:500',
            'facility_ids'   => 'nullable|array',
            'department_ids' => 'nullable|array',
            'role_targets'   => 'nullable|array',
            'tags'           => 'nullable|array',
            'priority'       => 'nullable|integer|min:0',
            'force_create'   => 'nullable|boolean',
        ]);

        $item = $this->contentService->draft(
            type: $data['type'],
            title: $data['title'],
            body: $data['body'],
            authorId: $request->user()->id,
            options: $data,
        );

        return response()->json($item, 201);
    }

    public function show(ContentItem $contentItem): JsonResponse
    {
        $this->authorize('view', $contentItem);

        $contentItem->load(['versions', 'media']);
        return response()->json($contentItem);
    }

    public function update(Request $request, ContentItem $contentItem): JsonResponse
    {
        $this->authorize('update', $contentItem);

        $data = $request->validate([
            'title'          => 'sometimes|string|max:255',
            'body'           => 'sometimes|string',
            'facility_ids'   => 'nullable|array',
            'department_ids' => 'nullable|array',
            'role_targets'   => 'nullable|array',
            'tags'           => 'nullable|array',
            'priority'       => 'nullable|integer|min:0',
        ]);

        $item = $this->contentService->update(
            $contentItem,
            $data,
            $request->user()->id,
            $request->input('change_note', ''),
        );

        return response()->json($item->load('versions'));
    }

    public function submitForReview(Request $request, ContentItem $contentItem): JsonResponse
    {
        $this->authorize('submitForReview', $contentItem);
        $item = $this->contentService->submitForReview($contentItem, $request->user()->id);
        return response()->json($item);
    }

    public function approve(Request $request, ContentItem $contentItem): JsonResponse
    {
        $this->authorize('approve', $contentItem);
        $item = $this->contentService->approve($contentItem, $request->user()->id);
        return response()->json($item);
    }

    public function publish(Request $request, ContentItem $contentItem): JsonResponse
    {
        $this->authorize('publish', $contentItem);
        $data = $request->validate(['publish_at' => 'nullable|date']);
        $publishAt = isset($data['publish_at']) ? new \DateTime($data['publish_at']) : null;
        $item = $this->contentService->publish($contentItem, $request->user()->id, $publishAt);
        return response()->json($item);
    }

    public function rollback(Request $request, ContentItem $contentItem): JsonResponse
    {
        $this->authorize('update', $contentItem);
        $data = $request->validate(['version' => 'required|integer|min:1']);
        $item = $this->contentService->rollback($contentItem, $data['version'], $request->user()->id);
        return response()->json($item->load('versions'));
    }

    public function versions(ContentItem $contentItem): JsonResponse
    {
        $this->authorize('view', $contentItem);
        $versions = $contentItem->versions()->orderByDesc('version')->get();
        return response()->json($versions);
    }

    public function uploadMedia(Request $request, ContentItem $contentItem): JsonResponse
    {
        $this->authorize('update', $contentItem);

        $request->validate([
            'files'   => 'required|array|max:10',
            'files.*' => 'required|file|max:' . (config('vetops.upload_max_mb', 20) * 1024),
        ]);

        $media = [];
        $sortOrder = $contentItem->media()->max('sort_order') ?? -1;

        foreach ($request->file('files') as $file) {
            $sortOrder++;
            $fileData = $this->storage->store($file, 'content/media');
            $media[] = $contentItem->media()->create([
                'file_path'  => $fileData['path'],
                'file_name'  => $fileData['file_name'],
                'mime_type'  => $fileData['mime_type'],
                'file_size'  => $fileData['file_size'],
                'checksum'   => $fileData['checksum'],
                'sort_order' => $sortOrder,
            ]);
        }

        return response()->json($media, 201);
    }

    public function destroy(ContentItem $contentItem): JsonResponse
    {
        $this->authorize('delete', $contentItem);
        $this->audit->logModel('content.archive', $contentItem);
        $contentItem->update(['status' => 'archived']);
        return response()->json(['message' => 'Content archived.']);
    }
}
