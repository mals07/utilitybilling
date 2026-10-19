<?php

namespace App\Enums;

enum PaymentMethod: string
{
    case Cash = 'cash';
    case Check = 'check';
    case BankTransfer = 'bank_transfer';
    case EWallet = 'e_wallet';

    public function label(): string
    {
        return match ($this) {
            self::Cash => 'Cash',
            self::Check => 'Check',
            self::BankTransfer => 'Bank Transfer',
            self::EWallet => 'E-Wallet (GCash, Maya, …)',
        };
    }

    /**
     * Every method but cash needs a check, transaction or reference number.
     */
    public function requiresReference(): bool
    {
        return $this !== self::Cash;
    }

    /**
     * @return array<string, string> value => label
     */
    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $method): array => [$method->value => $method->label()])->all();
    }
}
