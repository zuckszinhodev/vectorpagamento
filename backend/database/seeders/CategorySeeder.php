<?php

namespace Database\Seeders;

use App\Domain\Product\Entities\Category;
use Illuminate\Database\Seeder;

class CategorySeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $categories = [
            [
                'name' => 'Digital Products',
                'slug' => 'digital-products',
                'description' => 'E-books, courses, software',
                'is_active' => true,
            ],
            [
                'name' => 'Physical Products',
                'slug' => 'physical-products',
                'description' => 'Physical goods that require shipping',
                'is_active' => true,
            ],
            [
                'name' => 'Services',
                'slug' => 'services',
                'description' => 'Consulting, subscriptions, memberships',
                'is_active' => true,
            ],
            [
                'name' => 'Events',
                'slug' => 'events',
                'description' => 'Tickets, workshops, webinars',
                'is_active' => true,
            ],
        ];

        foreach ($categories as $categoryData) {
            Category::create($categoryData);
        }
    }
}
