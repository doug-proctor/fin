<?php

namespace App\Http\Controllers;

use App\Models\Category;

abstract class Controller
{
    /**
     * The categories a user can choose from, as value/label pairs for a
     * select control.
     *
     * @return array<int, array{value: string, label: string}>
     */
    protected function categoryOptions(int $userId): array
    {
        return array_map(
            fn (string $value, string $label): array => ['value' => $value, 'label' => $label],
            array_keys($labels = Category::labelsFor($userId)),
            array_values($labels),
        );
    }
}
