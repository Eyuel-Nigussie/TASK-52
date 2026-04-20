<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Services\AuditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AuditLogController extends Controller
{
    public function __construct(private readonly AuditService $audit) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', AuditLog::class);

        $user = $request->user();
        $query = AuditLog::with(['user'])
            ->when($request->filled('user_id'), fn($q) => $q->where('user_id', $request->user_id))
            ->when($request->filled('action'), fn($q) => $q->where('action', 'like', '%' . $request->action . '%'))
            ->when($request->filled('entity_type'), fn($q) => $q->where('entity_type', $request->entity_type))
            ->when($request->filled('entity_id'), fn($q) => $q->where('entity_id', $request->entity_id))
            ->when($request->filled('from'), fn($q) => $q->where('created_at', '>=', $request->from))
            ->when($request->filled('to'), fn($q) => $q->where('created_at', '<=', $request->to))
            ->orderByDesc('created_at');

        // Tenant scoping — managers pinned to a facility only see audit rows
        // tagged with that same facility_id. system_admin (and legacy
        // unassigned accounts, which UserController now blocks from being
        // created) see the full log.
        if (!$user->isAdmin() && $user->facility_id !== null) {
            $query->where('facility_id', $user->facility_id);
        }

        return response()->json($query->paginate($request->integer('per_page', 50)));
    }

    public function export(Request $request): StreamedResponse
    {
        $this->authorize('export', AuditLog::class);

        $this->audit->logExport('audit_log');

        $user = $request->user();
        $query = AuditLog::with(['user'])
            ->when($request->filled('from'), fn($q) => $q->where('created_at', '>=', $request->from))
            ->when($request->filled('to'), fn($q) => $q->where('created_at', '<=', $request->to))
            ->orderByDesc('created_at');

        if (!$user->isAdmin() && $user->facility_id !== null) {
            $query->where('facility_id', $user->facility_id);
        }

        $logs = $query->get();

        $rows = ["id,user,action"];
        foreach ($logs as $log) {
            $rows[] = implode(',', [
                $log->id,
                '"' . str_replace('"', '""', $log->user?->username ?? '') . '"',
                '"' . $log->action . '"',
            ]);
        }

        $csv = implode("\n", $rows) . "\n";

        return new StreamedResponse(function () use ($csv) {
            echo $csv;
        }, 200, ['Content-Type' => 'text/csv']);
    }
}
