<?php

declare(strict_types=1);

use App\Http\Controllers\Api\AuditLogController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\ContentController;
use App\Http\Controllers\Api\DedupController;
use App\Http\Controllers\Api\DepartmentController;
use App\Http\Controllers\Api\DoctorController;
use App\Http\Controllers\Api\FacilityController;
use App\Http\Controllers\Api\InventoryController;
use App\Http\Controllers\Api\MergeRequestController;
use App\Http\Controllers\Api\PatientController;
use App\Http\Controllers\Api\RentalAssetController;
use App\Http\Controllers\Api\RentalTransactionController;
use App\Http\Controllers\Api\ReviewController;
use App\Http\Controllers\Api\ServiceController;
use App\Http\Controllers\Api\ServiceOrderController;
use App\Http\Controllers\Api\StoreroomController;
use App\Http\Controllers\Api\StocktakeController;
use App\Http\Controllers\Api\UserController;
use App\Http\Controllers\Api\VisitController;
use Illuminate\Support\Facades\Route;

// Auth routes (public)
Route::prefix('auth')->group(function () {
    Route::post('/login', [AuthController::class, 'login'])
        ->middleware('throttle:login');
    Route::post('/refresh', [AuthController::class, 'refresh'])
        ->middleware('throttle:30,1'); // silent restore — modest rate-limit
    Route::get('/captcha-status', [AuthController::class, 'captchaStatus']);
});

// Public tablet review submission — unauthenticated by design (§RBAC.md Notable Exceptions).
// Scoped by visit id; rate-limited to curb abuse from a shared tablet.
Route::post('/reviews/visits/{visit}/submit', [ReviewController::class, 'submit'])
    ->middleware('throttle:10,60');

// Authenticated routes
Route::middleware(['auth:sanctum', 'vetops.inactivity'])->group(function () {

    // Auth
    Route::prefix('auth')->group(function () {
        Route::post('/logout', [AuthController::class, 'logout']);
        Route::get('/me', [AuthController::class, 'me']);
        Route::post('/change-password', [AuthController::class, 'changePassword']);
    });

    // Facilities
    Route::prefix('facilities')->group(function () {
        Route::get('/', [FacilityController::class, 'index']);
        Route::post('/', [FacilityController::class, 'store'])->middleware('role:system_admin,clinic_manager');
        Route::post('/import', [FacilityController::class, 'import'])->middleware('role:system_admin');
        Route::get('/export', [FacilityController::class, 'export'])->middleware('role:system_admin,clinic_manager');
        Route::get('/{facility}', [FacilityController::class, 'show']);
        Route::put('/{facility}', [FacilityController::class, 'update'])->middleware('role:system_admin,clinic_manager');
        Route::delete('/{facility}', [FacilityController::class, 'destroy'])->middleware('role:system_admin');
        Route::get('/{facility}/history', [FacilityController::class, 'history']);
    });

    // Departments — list is readable to any authenticated user (populates
    // dropdowns for all roles); mutations are role-gated to manager/admin.
    // The controller additionally invokes DepartmentPolicy + facility scope
    // so non-admin users only see their own facility's departments.
    Route::get('/departments', [DepartmentController::class, 'index']);
    Route::apiResource('departments', DepartmentController::class)->except(['index', 'show'])
        ->middleware(['role:system_admin,clinic_manager']);

    // Users
    Route::prefix('users')->middleware('role:system_admin')->group(function () {
        Route::get('/', [UserController::class, 'index']);
        Route::post('/', [UserController::class, 'store']);
        Route::get('/{user}', [UserController::class, 'show']);
        Route::put('/{user}', [UserController::class, 'update']);
        Route::delete('/{user}', [UserController::class, 'destroy']);
    });

    // Rental Assets
    Route::prefix('rental-assets')->group(function () {
        Route::get('/', [RentalAssetController::class, 'index']);
        Route::post('/', [RentalAssetController::class, 'store'])->middleware('role:system_admin,clinic_manager,inventory_clerk');
        Route::get('/scan', [RentalAssetController::class, 'scanLookup']);
        Route::get('/{rentalAsset}', [RentalAssetController::class, 'show']);
        Route::put('/{rentalAsset}', [RentalAssetController::class, 'update'])->middleware('role:system_admin,clinic_manager,inventory_clerk');
        Route::delete('/{rentalAsset}', [RentalAssetController::class, 'destroy'])->middleware('role:system_admin,clinic_manager');
        Route::post('/{rentalAsset}/photo', [RentalAssetController::class, 'uploadPhoto']);
    });

    // Rental Transactions
    Route::prefix('rental-transactions')->group(function () {
        Route::get('/', [RentalTransactionController::class, 'index']);
        Route::post('/checkout', [RentalTransactionController::class, 'checkout'])
            ->middleware('role:system_admin,clinic_manager,inventory_clerk,technician_doctor');
        Route::get('/overdue', [RentalTransactionController::class, 'overdueList']);
        Route::get('/{rentalTransaction}', [RentalTransactionController::class, 'show']);
        Route::post('/{rentalTransaction}/return', [RentalTransactionController::class, 'return'])
            ->middleware('role:system_admin,clinic_manager,inventory_clerk,technician_doctor');
        Route::post('/{rentalTransaction}/cancel', [RentalTransactionController::class, 'cancel'])
            ->middleware('role:system_admin,clinic_manager');
    });

    // Storerooms
    Route::prefix('storerooms')->group(function () {
        Route::get('/', [StoreroomController::class, 'index']);
        Route::post('/', [StoreroomController::class, 'store'])->middleware('role:system_admin,clinic_manager');
        Route::put('/{storeroom}', [StoreroomController::class, 'update'])->middleware('role:system_admin,clinic_manager');
        Route::delete('/{storeroom}', [StoreroomController::class, 'destroy'])->middleware('role:system_admin');
    });

    // Inventory
    Route::prefix('inventory')->group(function () {
        Route::get('/items', [InventoryController::class, 'items']);
        Route::post('/items', [InventoryController::class, 'createItem'])->middleware('role:system_admin,inventory_clerk');
        Route::put('/items/{item}', [InventoryController::class, 'updateItem'])->middleware('role:system_admin,inventory_clerk');
        Route::post('/receive', [InventoryController::class, 'receive'])->middleware('role:inventory_clerk,system_admin,clinic_manager');
        Route::post('/issue', [InventoryController::class, 'issue'])->middleware('role:inventory_clerk,system_admin,clinic_manager,technician_doctor');
        Route::post('/transfer', [InventoryController::class, 'transfer'])->middleware('role:inventory_clerk,system_admin,clinic_manager');
        Route::get('/stock-levels', [InventoryController::class, 'stockLevels']);
        Route::get('/low-stock-alerts', [InventoryController::class, 'lowStockAlerts']);
        Route::get('/ledger', [InventoryController::class, 'ledger']);
        Route::post('/items/import', [InventoryController::class, 'importItems'])->middleware('role:system_admin,inventory_clerk');
        Route::get('/items/export', [InventoryController::class, 'exportItems'])->middleware('role:system_admin,inventory_clerk,clinic_manager');
    });

    // Stocktake
    Route::prefix('stocktake')->group(function () {
        Route::get('/', [StocktakeController::class, 'index']);
        Route::post('/start', [StocktakeController::class, 'start'])->middleware('role:inventory_clerk,clinic_manager,system_admin');
        Route::get('/{stocktakeSession}', [StocktakeController::class, 'show']);
        Route::post('/{stocktakeSession}/entries', [StocktakeController::class, 'addEntry']);
        Route::post('/{stocktakeSession}/entries/{entry}/approve', [StocktakeController::class, 'approveEntry'])->middleware('role:clinic_manager,system_admin');
        Route::post('/{stocktakeSession}/close', [StocktakeController::class, 'close'])->middleware('role:inventory_clerk,clinic_manager,system_admin');
        Route::post('/{stocktakeSession}/approve', [StocktakeController::class, 'approve'])->middleware('role:clinic_manager,system_admin');
    });

    // Services catalog (master data)
    Route::prefix('services')->group(function () {
        Route::get('/', [ServiceController::class, 'index']);
        Route::post('/', [ServiceController::class, 'store'])->middleware('role:system_admin,clinic_manager');
        Route::get('/export', [ServiceController::class, 'export']);
        Route::post('/import', [ServiceController::class, 'import'])->middleware('role:system_admin,clinic_manager');
        Route::get('/{service}', [ServiceController::class, 'show']);
        Route::put('/{service}', [ServiceController::class, 'update'])->middleware('role:system_admin,clinic_manager');
        Route::delete('/{service}', [ServiceController::class, 'destroy'])->middleware('role:system_admin');
        Route::get('/{service}/pricings', [ServiceController::class, 'pricings']);
        Route::post('/{service}/pricings', [ServiceController::class, 'storePricing'])->middleware('role:system_admin,clinic_manager');
    });

    // Service Orders
    Route::prefix('service-orders')->group(function () {
        Route::get('/', [ServiceOrderController::class, 'index']);
        Route::post('/', [ServiceOrderController::class, 'store']);
        Route::get('/{serviceOrder}', [ServiceOrderController::class, 'show']);
        Route::post('/{serviceOrder}/close', [ServiceOrderController::class, 'close'])->middleware('role:technician_doctor,clinic_manager,system_admin');
        Route::post('/{serviceOrder}/reservations', [ServiceOrderController::class, 'addReservation']);
    });

    // Content
    Route::prefix('content')->group(function () {
        Route::get('/', [ContentController::class, 'index']);
        Route::get('/published', [ContentController::class, 'published']);
        Route::post('/', [ContentController::class, 'store'])->middleware('role:content_editor,content_approver,system_admin');
        Route::get('/{contentItem}', [ContentController::class, 'show']);
        Route::put('/{contentItem}', [ContentController::class, 'update'])->middleware('role:content_editor,content_approver,system_admin');
        Route::post('/{contentItem}/submit-review', [ContentController::class, 'submitForReview'])->middleware('role:content_editor,content_approver,system_admin');
        Route::post('/{contentItem}/approve', [ContentController::class, 'approve'])->middleware('role:content_approver,system_admin');
        Route::post('/{contentItem}/publish', [ContentController::class, 'publish'])->middleware('role:content_approver,system_admin');
        Route::post('/{contentItem}/rollback', [ContentController::class, 'rollback'])->middleware('role:content_editor,content_approver,system_admin');
        Route::get('/{contentItem}/versions', [ContentController::class, 'versions']);
        Route::post('/{contentItem}/media', [ContentController::class, 'uploadMedia'])->middleware('role:content_editor,content_approver,system_admin');
        Route::delete('/{contentItem}', [ContentController::class, 'destroy'])->middleware('role:content_approver,system_admin');
    });

    // Doctors
    Route::prefix('doctors')->group(function () {
        Route::get('/', [DoctorController::class, 'index']);
        Route::post('/', [DoctorController::class, 'store'])->middleware('role:system_admin,clinic_manager');
        Route::get('/{doctor}', [DoctorController::class, 'show']);
        Route::put('/{doctor}', [DoctorController::class, 'update'])->middleware('role:system_admin,clinic_manager');
        Route::delete('/{doctor}', [DoctorController::class, 'destroy'])->middleware('role:system_admin');
        Route::post('/import', [DoctorController::class, 'import'])->middleware('role:system_admin');
    });

    // Patients
    Route::prefix('patients')->group(function () {
        Route::get('/', [PatientController::class, 'index']);
        Route::post('/', [PatientController::class, 'store']);
        Route::get('/{patient}', [PatientController::class, 'show']);
        Route::put('/{patient}', [PatientController::class, 'update']);
        Route::delete('/{patient}', [PatientController::class, 'destroy'])->middleware('role:system_admin,clinic_manager');
    });

    // Visits
    Route::prefix('visits')->group(function () {
        Route::get('/', [VisitController::class, 'index']);
        Route::post('/', [VisitController::class, 'store']);
        Route::get('/{visit}', [VisitController::class, 'show']);
        Route::put('/{visit}', [VisitController::class, 'update']);
    });

    // Reviews (submit is public — declared above the auth group)
    Route::prefix('reviews')->group(function () {
        Route::get('/', [ReviewController::class, 'index']);
        Route::get('/dashboard', [ReviewController::class, 'dashboard']);
        Route::get('/dashboard/breakdown', [ReviewController::class, 'dashboardBreakdown']);
        Route::get('/{visitReview}', [ReviewController::class, 'show']);
        Route::post('/{visitReview}/publish', [ReviewController::class, 'publish'])->middleware('role:clinic_manager,system_admin');
        Route::post('/{visitReview}/hide', [ReviewController::class, 'hide'])->middleware('role:clinic_manager,system_admin');
        Route::post('/{visitReview}/respond', [ReviewController::class, 'respond'])->middleware('role:clinic_manager,system_admin');
        Route::post('/{visitReview}/appeal', [ReviewController::class, 'appeal'])->middleware('role:clinic_manager,system_admin');
        Route::post('/appeals/{reviewAppeal}/resolve', [ReviewController::class, 'resolveAppeal'])->middleware('role:clinic_manager,system_admin');
    });

    // Merge Requests (deduplication)
    Route::prefix('merge-requests')->middleware('role:clinic_manager,system_admin')->group(function () {
        Route::get('/', [MergeRequestController::class, 'index']);
        Route::post('/', [MergeRequestController::class, 'store']);
        Route::post('/{mergeRequest}/approve', [MergeRequestController::class, 'approve']);
        Route::post('/{mergeRequest}/reject', [MergeRequestController::class, 'reject']);
    });

    // Dedup candidates — surfaces key-field matches for manager review
    Route::get('dedup/candidates', [DedupController::class, 'candidates'])
        ->middleware('role:clinic_manager,system_admin');

    // Audit Logs
    Route::prefix('audit-logs')->middleware('role:system_admin,clinic_manager')->group(function () {
        Route::get('/', [AuditLogController::class, 'index']);
        Route::get('/export', [AuditLogController::class, 'export']);
    });
});
