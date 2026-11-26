<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use RuntimeException;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed a local development database with the static prototype's sample content.
     */
    public function run(): void
    {
        if (app()->isProduction()) {
            throw new RuntimeException('Refusing to seed: this is a production environment.');
        }

        $this->call([
            MediaFileSeeder::class,
            UserSeeder::class,
            WorkSeeder::class,
            PlanningSeeder::class,
            ReviewerSeeder::class,
            ReaderSeeder::class,
            NoteSeeder::class,
            NewsletterSeeder::class,
            SiteSeeder::class,
            StatsSeeder::class,
            NotificationSeeder::class,
        ]);
    }
}
