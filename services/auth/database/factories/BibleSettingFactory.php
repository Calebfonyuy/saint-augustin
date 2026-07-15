<?php

namespace Database\Factories;

use App\Models\BibleSetting;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BibleSetting>
 */
class BibleSettingFactory extends Factory
{
    protected $model = BibleSetting::class;

    public function definition(): array
    {
        return [
            'enabled_translations'   => ['BSB'],
            'default_translation_id' => 'BSB',
        ];
    }
}
