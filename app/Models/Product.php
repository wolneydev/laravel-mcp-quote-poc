<?php

namespace App\Models;

use Database\Factories\ProductFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['code', 'name', 'description', 'unit', 'price', 'currency', 'active'])]
class Product extends Model
{
    /** @use HasFactory<ProductFactory> */
    use HasFactory;

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'currency' => 'CAD',
        'active' => true,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'active' => 'boolean',
        ];
    }

    #[Scope]
    protected function search(Builder $query, string $term): Builder
    {
        $term = '%'.$term.'%';

        return $query->where(function (Builder $query) use ($term): void {
            $query->where('name', 'like', $term)
                ->orWhere('code', 'like', $term);
        });
    }
}
