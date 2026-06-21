<?php

namespace Tests\Feature;

use Tests\TestCase;

class FormSelectComponentTest extends TestCase
{
    public function test_multi_selects_render_shared_enhancement_metadata(): void
    {
        $this->blade(<<<'BLADE'
            <x-form.select name="source_ids[]" multiple placeholder="Select sources">
                <option value="1" selected>Source one</option>
            </x-form.select>
        BLADE)
            ->assertSee('multiple', false)
            ->assertSee('data-enhance="select"', false)
            ->assertSee('data-select-mode="multiple"', false)
            ->assertSee('data-placeholder="Select sources"', false);
    }

    public function test_single_selects_do_not_render_multi_select_metadata(): void
    {
        $this->blade(<<<'BLADE'
            <x-form.select name="species_id" placeholder="Select species">
                <option value="1">Yellowtail</option>
            </x-form.select>
        BLADE)
            ->assertSee('data-enhance="select"', false)
            ->assertSee('data-placeholder="Select species"', false)
            ->assertDontSee('multiple', false)
            ->assertDontSee('data-select-mode="multiple"', false);
    }
}
