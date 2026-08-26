<?php

namespace App\Models;

use App\Enums\QuoteStatus;
use Database\Factories\QuoteFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'number',
    'seller_account_id',
    'customer_account_id',
    'status',
    'currency',
    'total',
    'valid_until',
    'notes',
    'approved_at',
    'rejected_at',
])]
class Quote extends Model
{
    /** @use HasFactory<QuoteFactory> */
    use HasFactory;

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => 'draft',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => QuoteStatus::class,
            'total' => 'decimal:2',
            'valid_until' => 'date',
            'approved_at' => 'datetime',
            'rejected_at' => 'datetime',
        ];
    }

    public function sellerAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'seller_account_id');
    }

    public function customerAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'customer_account_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(QuoteItem::class);
    }

    #[Scope]
    protected function withDetails(Builder $query): Builder
    {
        return $query->with([
            'sellerAccount',
            'customerAccount.customer',
            'items',
        ]);
    }
}
