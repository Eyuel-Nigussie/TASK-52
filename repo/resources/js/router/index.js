import { createRouter, createWebHistory } from 'vue-router';
import { useAuthStore } from '@/stores/auth';
import { hasRole } from '@/utils/roles';
import AppLayout from '@/components/layout/AppLayout.vue';

import LoginView from '@/views/LoginView.vue';
import TabletReviewView from '@/views/TabletReviewView.vue';
import DashboardView from '@/views/DashboardView.vue';
import RentalsView from '@/views/RentalsView.vue';
import InventoryView from '@/views/InventoryView.vue';
import StocktakeView from '@/views/StocktakeView.vue';
import ContentView from '@/views/ContentView.vue';
import ReviewsView from '@/views/ReviewsView.vue';
import PatientsView from '@/views/PatientsView.vue';
import VisitsView from '@/views/VisitsView.vue';
import DoctorsView from '@/views/DoctorsView.vue';
import FacilitiesView from '@/views/FacilitiesView.vue';
import DepartmentsView from '@/views/DepartmentsView.vue';
import UsersView from '@/views/UsersView.vue';
import ServiceOrdersView from '@/views/ServiceOrdersView.vue';
import MergeRequestsView from '@/views/MergeRequestsView.vue';
import AuditLogsView from '@/views/AuditLogsView.vue';
import NotFoundView from '@/views/NotFoundView.vue';
import ChangePasswordView from '@/views/ChangePasswordView.vue';

const protectedChildren = [
    { path: 'dashboard', name: 'dashboard', component: DashboardView },
    { path: 'rentals', name: 'rentals', component: RentalsView },
    { path: 'inventory', name: 'inventory', component: InventoryView },
    { path: 'stocktake', name: 'stocktake', component: StocktakeView,
      meta: { roles: ['system_admin', 'clinic_manager', 'inventory_clerk'] } },
    { path: 'content', name: 'content', component: ContentView,
      meta: { roles: ['system_admin', 'content_editor', 'content_approver'] } },
    { path: 'reviews', name: 'reviews', component: ReviewsView },
    { path: 'patients', name: 'patients', component: PatientsView },
    { path: 'visits', name: 'visits', component: VisitsView },
    { path: 'doctors', name: 'doctors', component: DoctorsView },
    { path: 'facilities', name: 'facilities', component: FacilitiesView,
      meta: { roles: ['system_admin', 'clinic_manager'] } },
    { path: 'departments', name: 'departments', component: DepartmentsView,
      meta: { roles: ['system_admin', 'clinic_manager'] } },
    { path: 'users', name: 'users', component: UsersView,
      meta: { roles: ['system_admin'] } },
    { path: 'service-orders', name: 'service-orders', component: ServiceOrdersView },
    { path: 'merge-requests', name: 'merge-requests', component: MergeRequestsView,
      meta: { roles: ['system_admin', 'clinic_manager'] } },
    { path: 'audit-logs', name: 'audit-logs', component: AuditLogsView,
      meta: { roles: ['system_admin', 'clinic_manager'] } },
];

export const routes = [
    { path: '/login', name: 'login', component: LoginView, meta: { public: true } },
    { path: '/change-password', name: 'change-password', component: ChangePasswordView, meta: { requiresAuth: true } },
    { path: '/tablet/reviews/:visitId', name: 'tablet-review', component: TabletReviewView, meta: { public: true } },
    {
        path: '/',
        component: AppLayout,
        meta: { requiresAuth: true },
        children: [
            { path: '', redirect: '/dashboard' },
            ...protectedChildren,
        ],
    },
    { path: '/:pathMatch(.*)*', name: 'not-found', component: NotFoundView, meta: { public: true } },
];

export function installGuards(router) {
    router.beforeEach((to) => {
        const auth = useAuthStore();
        if (to.meta.public) return true;
        if (to.meta.requiresAuth !== false && !auth.isAuthenticated) {
            return { name: 'login', query: { redirect: to.fullPath } };
        }
        if (auth.requiresPasswordChange && to.name !== 'change-password') {
            return { name: 'change-password' };
        }
        if (Array.isArray(to.meta.roles) && to.meta.roles.length && !hasRole(auth.user, ...to.meta.roles)) {
            return { name: 'dashboard' };
        }
        return true;
    });
    return router;
}

export function createAppRouter(history = createWebHistory()) {
    const router = createRouter({ history, routes });
    installGuards(router);
    return router;
}
