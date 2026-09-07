<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Artisan;

/**
 * Realistic manual-test data for the admin panel and the Episode Preparation
 * Workspace.
 *
 * NOT registered in DatabaseSeeder — run it explicitly:
 *
 *     php artisan db:seed --class=LocalTestDataSeeder
 *
 * It just delegates to the `demo:seed-gercegin-pesinde` command, which is the
 * single source of truth for the "Gerçeğin Peşinde" demo content and is
 * idempotent (safe to re-run, creates no duplicates).
 */
class LocalTestDataSeeder extends Seeder
{
    public function run(): void
    {
        Artisan::call('demo:seed-gercegin-pesinde');
    }
}
