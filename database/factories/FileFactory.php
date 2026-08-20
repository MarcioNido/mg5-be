<?php

namespace Database\Factories;

use App\Enums\ImportStatus;
use App\Models\Account;
use App\Models\File;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<File>
 */
class FileFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition()
    {
        return [
            'account_id' => Account::factory(),
            'filename' => 'files/'.fake()->uuid().'.csv',
            'source_name' => 'RBC',
            'source_type' => 'csv',
            'status' => ImportStatus::Pending,
            'file_fingerprint' => hash('sha256', fake()->uuid()),
        ];
    }
}
