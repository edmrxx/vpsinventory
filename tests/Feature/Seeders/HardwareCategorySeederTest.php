<?php

namespace Tests\Feature\Seeders;

use App\Http\Requests\Category\StoreCategoryRequest;
use App\Models\Category;
use Database\Seeders\HardwareCategorySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

class HardwareCategorySeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_adds_every_category_switched_on(): void
    {
        $this->seed(HardwareCategorySeeder::class);

        $this->assertSame(count(HardwareCategorySeeder::CATEGORIES), Category::active()->count());
        $this->assertStringContainsString('THHN', Category::firstWhere('name', 'Wires & Cables')->description);
        $this->assertDatabaseCount('audit_trails', 0);
    }

    public function test_every_category_would_pass_the_form_on_the_categories_screen(): void
    {
        $rules = (new StoreCategoryRequest)->rules();

        foreach (HardwareCategorySeeder::CATEGORIES as $name => $description) {
            $validator = Validator::make(
                ['name' => $name, 'description' => $description, 'is_active' => true],
                $rules
            );

            $this->assertFalse($validator->fails(), "[{$name}]: ".implode(' ', $validator->errors()->all()));
        }
    }

    public function test_running_it_twice_keeps_what_was_changed_on_the_screen(): void
    {
        $this->seed(HardwareCategorySeeder::class);

        Category::firstWhere('name', 'Garden & Outdoor')->update(['description' => 'Ours', 'is_active' => false]);

        $this->seed(HardwareCategorySeeder::class);

        $this->assertSame(count(HardwareCategorySeeder::CATEGORIES), Category::count());
        $this->assertSame('Ours', Category::firstWhere('name', 'Garden & Outdoor')->description);
        $this->assertFalse(Category::firstWhere('name', 'Garden & Outdoor')->is_active);
    }
}
