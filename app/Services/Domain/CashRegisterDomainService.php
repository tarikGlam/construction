<?php

namespace App\Services\Domain;

use App\Models\CashRegister;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class CashRegisterDomainService
{
    public function open(array $data, int $userId): CashRegister
    {
        return DB::transaction(function () use ($data, $userId) {
            User::query()->lockForUpdate()->findOrFail($userId);

            $register = CashRegister::where([
                ['user_id', $userId],
                ['warehouse_id', (int) $data['warehouse_id']],
                ['status', true],
            ])->first();

            return $register ?? CashRegister::create([
                'cash_in_hand' => (float) $data['cash_in_hand'],
                'user_id' => $userId,
                'warehouse_id' => (int) $data['warehouse_id'],
                'status' => true,
            ]);
        }, 3);
    }

    public function withLockedOpenRegister(int $registerId, callable $callback, string $closedMessage): mixed
    {
        return DB::transaction(function () use ($registerId, $callback, $closedMessage) {
            $register = CashRegister::lockForUpdate()->find($registerId);

            if (!$register || !$register->status) {
                throw new RuntimeException($closedMessage);
            }

            return $callback($register);
        }, 3);
    }

    public function close(CashRegister $register, float $closingBalance, float $actualCash): CashRegister
    {
        return DB::transaction(function () use ($register, $closingBalance, $actualCash) {
            $register = CashRegister::withoutGlobalScopes()->lockForUpdate()->findOrFail($register->id);
            if (!$register->status) {
                throw new RuntimeException('Cash register is already closed.');
            }
            $register->closing_balance = $closingBalance;
            $register->actual_cash = $actualCash;
            $register->status = false;
            $register->save();
            return $register->fresh();
        });
    }

    public function variance(CashRegister $register): ?float
    {
        if ($register->closing_balance === null || $register->actual_cash === null) return null;
        return round((float) $register->actual_cash - (float) $register->closing_balance, 4);
    }
}
