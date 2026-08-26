<?php

namespace App\Models;

use App\Enums\AccountType;
use Database\Factories\AccountFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Support\Str;

#[Fillable(['code', 'type', 'customer_id', 'name', 'email', 'password', 'active'])]
#[Hidden(['password'])]
class Account extends Authenticatable
{
    /** @use HasFactory<AccountFactory> */
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
            'type' => AccountType::class,
            'active' => 'boolean',
            'password' => 'hashed',
        ];
    }

    public static function normalizeEmail(?string $email): ?string
    {
        if ($email === null) {
            return null;
        }

        $normalized = (string) Str::of($email)->trim()->lower();

        return $normalized === '' ? null : $normalized;
    }

    /**
     * @return Attribute<int, never>
     */
    protected function accountAgeDays(): Attribute
    {
        return Attribute::get(function (): int {
            if ($this->created_at === null) {
                return 0;
            }

            return (int) $this->created_at->diffInDays(now());
        });
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function sellerQuotes(): HasMany
    {
        return $this->hasMany(Quote::class, 'seller_account_id');
    }

    public function customerQuotes(): HasMany
    {
        return $this->hasMany(Quote::class, 'customer_account_id');
    }

    #[Scope]
    protected function search(Builder $query, string $term): Builder
    {
        $term = '%'.$term.'%';

        return $query->where(function (Builder $query) use ($term): void {
            $query->where('name', 'like', $term)
                ->orWhere('email', 'like', $term)
                ->orWhere('code', 'like', $term);
        });
    }
}
