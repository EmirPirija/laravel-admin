<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Category;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ImportCategories extends Command
{
    protected $signature = 'categories:import {file} {--dry}';
    protected $description = 'Import categories from CSV';

    public function handle()
    {
        $file = $this->argument('file');

        if (!file_exists($file)) {
            $this->error("File not found: {$file}");
            return;
        }

        $rows = array_map('str_getcsv', file($file));

        DB::transaction(function () use ($rows) {

            foreach ($rows as $i => $row) {
                if ($i === 0) continue; // skip header
            
                [$name, $parentId, $description, $isJob, $priceOptional] =
                    array_pad($row, 5, null);
            
                if (!$name) continue;
            
                $parentId = is_numeric($parentId) ? (int) $parentId : null;
                $slug = $this->uniqueSlug($name);
            
                $sequence = $i; // dodaj ovo, redni broj iz CSV-a
            
                if ($this->option('dry')) {
                    $this->info("[DRY] {$name} → {$slug} (parent ID: {$parentId})");
                    continue;
                }
            
                Category::create([
                    'name'               => $name,
                    'slug'               => $slug,
                    'parent_category_id' => $parentId,
                    'description'        => $description,
                    'status'             => 1,
                    'is_job_category'    => (bool) $isJob,
                    'price_optional'     => (bool) $priceOptional,
                    'sequence'           => $sequence
                ]);
            }
            
        });

        $this->info('Categories imported successfully.');
    }

    private function uniqueSlug(string $name): string
    {
        $slug = Str::slug($name);
        $original = $slug;
        $i = 1;

        while (Category::where('slug', $slug)->exists()) {
            $slug = "{$original}-{$i}";
            $i++;
        }

        return $slug;
    }
}
