<?php

namespace Database\Seeders;

use App\Models\Category;
use Illuminate\Database\Seeder;

class CategorySeeder extends Seeder
{
    public function run()
    {
        Category::factory(3)->create(["level" => 1]);

        Category::query()
            ->where("level", 1)
            ->get()
            ->each(function (Category $level1) {
                Category::factory()
                    ->count(3)
                    ->create([
                        "parent_id" => $level1->id,
                        "type" => $level1->type,
                        "level" => 2,
                    ]);
            });

        Category::query()
            ->where("level", 2)
            ->get()
            ->each(function (Category $level2) {
                Category::factory()
                    ->count(3)
                    ->create([
                        "parent_id" => $level2->id,
                        "type" => $level2->type,
                        "level" => 3,
                    ]);
            });
    }
}
