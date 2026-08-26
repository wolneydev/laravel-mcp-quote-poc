<?php

namespace App\Enums;

enum AccountType: string
{
    case Seller = 'seller';
    case Customer = 'customer';

    public function codePrefix(): string
    {
        return match ($this) {
            self::Seller => 'VEN-',
            self::Customer => 'CLI-',
        };
    }
}
