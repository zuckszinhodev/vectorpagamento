<?php

namespace Database\Seeders;

use App\Domain\Auth\Entities\Role;
use Illuminate\Database\Seeder;

class RoleSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $adminCorePermissions = [
            'admin.dashboard.read',
            'admin.users.manage',
            'admin.customers.manage',
            'admin.withdrawals.manage',
            'admin.kyc.review',
            'admin.acquirers.manage',
            'admin.config.manage',
            'admin.webhooks.manage',
            'admin.notifications.manage',
            'admin.fraud.manage',
            'admin.chargebacks.manage',
            'admin.reconciliation.manage',
            'admin.security.monitor',
            'admin.approvals.review',
            'admin.showcase.manage',
        ];

        $roles = [
            [
                'name' => 'owner',
                'slug' => 'owner',
                'description' => 'Account owner with full access',
                'permissions' => [
                    '*',
                    'users.create', 'users.read', 'users.update', 'users.delete',
                    'charges.create', 'charges.read', 'charges.update', 'charges.delete',
                    'products.create', 'products.read', 'products.update', 'products.delete',
                    'checkouts.create', 'checkouts.read', 'checkouts.update', 'checkouts.delete',
                    'affiliates.create', 'affiliates.read', 'affiliates.update', 'affiliates.delete',
                    'coupons.create', 'coupons.read', 'coupons.update', 'coupons.delete',
                    'analytics.read',
                    'settings.read', 'settings.update',
                    'api_keys.create', 'api_keys.read', 'api_keys.delete',
                ],
            ],
            [
                'name' => 'admin',
                'slug' => 'admin',
                'description' => 'Administrator with most permissions',
                'permissions' => array_merge([
                    'users.read', 'users.update',
                    'charges.create', 'charges.read', 'charges.update',
                    'products.create', 'products.read', 'products.update', 'products.delete',
                    'checkouts.create', 'checkouts.read', 'checkouts.update',
                    'affiliates.read',
                    'coupons.read',
                    'analytics.read',
                    'settings.read',
                ], $adminCorePermissions),
            ],
            [
                'name' => 'developer',
                'slug' => 'developer',
                'description' => 'Developer with API access',
                'permissions' => [
                    'charges.create', 'charges.read',
                    'products.read',
                    'checkouts.read',
                    'api_keys.read',
                ],
            ],
            [
                'name' => 'support',
                'slug' => 'support',
                'description' => 'Support team member',
                'permissions' => array_merge([
                    'charges.read',
                    'products.read',
                    'checkouts.read',
                    'affiliates.read',
                    'coupons.read',
                ], [
                    'admin.dashboard.read',
                    'admin.customers.manage',
                    'admin.notifications.manage',
                ]),
            ],
            [
                'name' => 'financial',
                'slug' => 'financial',
                'description' => 'Financial operations analyst',
                'permissions' => [
                    'analytics.read',
                    'charges.read',
                    'refunds.create',
                    'admin.dashboard.read',
                    'admin.withdrawals.manage',
                    'admin.chargebacks.manage',
                    'admin.reconciliation.manage',
                ],
            ],
            [
                'name' => 'antifraud',
                'slug' => 'antifraud',
                'description' => 'Fraud prevention analyst',
                'permissions' => [
                    'analytics.read',
                    'charges.read',
                    'admin.dashboard.read',
                    'admin.fraud.manage',
                    'admin.security.monitor',
                    'admin.approvals.review',
                    'admin.chargebacks.manage',
                    'admin.kyc.review',
                ],
            ],
        ];

        foreach ($roles as $roleData) {
            Role::updateOrCreate(
                ['slug' => $roleData['slug']],
                $roleData
            );
        }
    }
}
