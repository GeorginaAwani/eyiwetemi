<?php

namespace Database\Factories;

use App\Models\PlotPoint;
use App\Models\PlotThread;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PlotPoint>
 */
class PlotPointFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'plot_thread_id' => PlotThread::factory(),
            'work_item_id' => null,
            'text' => fake()->sentence(),
            'position' => 0,
        ];
    }
}
