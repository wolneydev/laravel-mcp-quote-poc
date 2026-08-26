<?php

namespace App\Models;

use Database\Factories\CustomerFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Str;

#[Fillable(['code', 'name', 'document', 'email', 'phone', 'contact_name', 'active'])]
class Customer extends Model
{
    /** @use HasFactory<CustomerFactory> */
    use HasFactory;

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'active' => true,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'active' => 'boolean',
        ];
    }

    public function account(): HasOne
    {
        return $this->hasOne(Account::class);
    }

    public static function normalizeDocument(?string $document): ?string
    {
        if ($document === null) {
            return null;
        }

        $normalized = (string) Str::of($document)->replaceMatches('/[^A-Za-z0-9]/', '');

        return $normalized === '' ? null : $normalized;
    }

    public static function normalizeEmail(?string $email): ?string
    {
        if ($email === null) {
            return null;
        }

        $normalized = (string) Str::of($email)->trim()->lower();

        return $normalized === '' ? null : $normalized;
    }

    #[Scope]
    protected function search(Builder $query, string $term): Builder
    {
        $term = '%'.$term.'%';

        return $query->where(function (Builder $query) use ($term): void {
            $query->where('name', 'like', $term)
                ->orWhere('code', 'like', $term)
                ->orWhere('contact_name', 'like', $term);
        });
    }
}
