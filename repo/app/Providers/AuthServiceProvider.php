<?php

declare(strict_types=1);

namespace App\Providers;

use App\Models\AuditLog;
use App\Models\ContentItem;
use App\Models\Department;
use App\Models\Doctor;
use App\Models\Facility;
use App\Models\InventoryItem;
use App\Models\MergeRequest;
use App\Models\Patient;
use App\Models\RentalAsset;
use App\Models\RentalTransaction;
use App\Models\Service;
use App\Models\ServiceOrder;
use App\Models\ServicePricing;
use App\Models\StocktakeSession;
use App\Models\Storeroom;
use App\Models\User;
use App\Models\Visit;
use App\Models\VisitReview;
use App\Policies\AuditLogPolicy;
use App\Policies\ContentItemPolicy;
use App\Policies\DepartmentPolicy;
use App\Policies\DoctorPolicy;
use App\Policies\FacilityPolicy;
use App\Policies\InventoryItemPolicy;
use App\Policies\MergeRequestPolicy;
use App\Policies\PatientPolicy;
use App\Policies\RentalAssetPolicy;
use App\Policies\RentalTransactionPolicy;
use App\Policies\ServiceOrderPolicy;
use App\Policies\ServicePolicy;
use App\Policies\ServicePricingPolicy;
use App\Policies\StocktakeSessionPolicy;
use App\Policies\StoreroomPolicy;
use App\Policies\UserPolicy;
use App\Policies\VisitPolicy;
use App\Policies\VisitReviewPolicy;
use Illuminate\Foundation\Support\Providers\AuthServiceProvider as ServiceProvider;
use Illuminate\Support\Facades\Gate;

class AuthServiceProvider extends ServiceProvider
{
    protected $policies = [
        AuditLog::class          => AuditLogPolicy::class,
        ContentItem::class       => ContentItemPolicy::class,
        Department::class        => DepartmentPolicy::class,
        Doctor::class            => DoctorPolicy::class,
        Facility::class          => FacilityPolicy::class,
        InventoryItem::class     => InventoryItemPolicy::class,
        MergeRequest::class      => MergeRequestPolicy::class,
        Patient::class           => PatientPolicy::class,
        RentalAsset::class       => RentalAssetPolicy::class,
        RentalTransaction::class => RentalTransactionPolicy::class,
        Service::class           => ServicePolicy::class,
        ServicePricing::class    => ServicePricingPolicy::class,
        ServiceOrder::class      => ServiceOrderPolicy::class,
        StocktakeSession::class  => StocktakeSessionPolicy::class,
        Storeroom::class         => StoreroomPolicy::class,
        User::class              => UserPolicy::class,
        Visit::class             => VisitPolicy::class,
        VisitReview::class       => VisitReviewPolicy::class,
    ];

    public function boot(): void
    {
        $this->registerPolicies();

        Gate::before(function (?User $user, string $ability) {
            if ($user !== null && $user->isAdmin()) {
                return true;
            }
            return null;
        });

        Gate::define('manage-facilities',    fn(User $u) => $u->isManager());
        Gate::define('manage-departments',   fn(User $u) => $u->isManager());
        Gate::define('manage-doctors',       fn(User $u) => $u->isManager());
        Gate::define('manage-users',         fn(User $u) => $u->isAdmin());
        Gate::define('export-audit-logs',    fn(User $u) => $u->isManager());
        Gate::define('resolve-merge-request',fn(User $u) => $u->isManager());
        Gate::define('approve-stocktake',    fn(User $u) => $u->isManager());
        Gate::define('moderate-reviews',     fn(User $u) => $u->isManager());
        Gate::define('approve-content',      fn(User $u) => in_array($u->role, ['content_approver', 'system_admin'], true));
        Gate::define('author-content',       fn(User $u) => in_array($u->role, ['content_editor', 'content_approver', 'system_admin'], true));
        Gate::define('receive-inventory',    fn(User $u) => in_array($u->role, ['inventory_clerk', 'clinic_manager', 'system_admin'], true));
        Gate::define('issue-inventory',      fn(User $u) => in_array($u->role, ['inventory_clerk', 'clinic_manager', 'technician_doctor', 'system_admin'], true));
        Gate::define('transfer-inventory',   fn(User $u) => in_array($u->role, ['inventory_clerk', 'clinic_manager', 'system_admin'], true));
        Gate::define('checkout-rental',      fn(User $u) => in_array($u->role, ['inventory_clerk', 'clinic_manager', 'technician_doctor', 'system_admin'], true));
    }
}
