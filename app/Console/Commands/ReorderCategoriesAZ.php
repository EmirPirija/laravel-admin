<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Category;
use Illuminate\Support\Facades\DB;

class ReorderCategoriesAZ extends Command
{
    protected $signature = 'categories:reorder-az {parentId}';
    protected $description = 'Reorder categories A-Z and fix sequence under a parent category';

    public function handle()
    {
        $parentId = (int) $this->argument('parentId');

        $categories = Category::where('parent_category_id', $parentId)
            ->orderByRaw('LOWER(name) ASC')
            ->get();

        if ($categories->isEmpty()) {
            $this->warn('No categories found.');
            return;
        }

        DB::transaction(function () use ($categories) {
            $sequence = 1;

            foreach ($categories as $category) {
                $category->update([
                    'sequence' => $sequence
                ]);
                $sequence++;
            }
        });

        $this->info('Categories reordered A–Z successfully.');
    }
}
