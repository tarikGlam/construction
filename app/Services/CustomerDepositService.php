<?php

namespace App\Services;

use App\Enums\CustomerTypeEnum;
use App\Exceptions\SaleValidationException;
use App\Models\Customer;

/**
 * The single authority for operational customer-deposit liability movements.
 *
 * Callers must invoke this inside the transaction that creates, updates, or
 * reverses the corresponding payment. Customer rows are locked so concurrent
 * deposit redemptions cannot spend the same available balance.
 */
class CustomerDepositService
{
    private const SCALE = 4;

    public function consume(int $customerId, mixed $amount): Customer
    {
        $customer = $this->lockedCustomer($customerId);
        $amount = $this->money($amount);

        $this->assertEligible($customer);

        if (bccomp($amount, '0', self::SCALE) <= 0) {
            throw new SaleValidationException(__('db.Amount exceeds customer deposit!'));
        }

        if (bccomp($amount, $this->available($customer), self::SCALE) === 1) {
            throw new SaleValidationException(__('db.Amount exceeds customer deposit!'));
        }

        $customer->expense = bcadd($this->money($customer->expense), $amount, self::SCALE);
        $customer->save();

        return $customer;
    }

    public function restore(int $customerId, mixed $amount): Customer
    {
        $customer = $this->lockedCustomer($customerId);
        $amount = $this->money($amount);

        if (bccomp($amount, '0', self::SCALE) <= 0) {
            return $customer;
        }

        $customer->expense = bccomp($amount, $this->money($customer->expense), self::SCALE) === 1
            ? '0.0000'
            : bcsub($this->money($customer->expense), $amount, self::SCALE);
        $customer->save();

        return $customer;
    }

    /** Reconcile a payment edit without ever applying its deposit delta twice. */
    public function reconcile(int $customerId, string $oldMethod, mixed $oldAmount, string $newMethod, mixed $newAmount): Customer
    {
        $customer = $this->lockedCustomer($customerId);

        if ($oldMethod === 'Deposit') {
            $oldAmount = $this->money($oldAmount);
            $customer->expense = bccomp($oldAmount, $this->money($customer->expense), self::SCALE) === 1
                ? '0.0000'
                : bcsub($this->money($customer->expense), $oldAmount, self::SCALE);
            $customer->save();
        }

        if ($newMethod !== 'Deposit') {
            return $customer;
        }

        $this->assertEligible($customer);
        $newAmount = $this->money($newAmount);
        if (bccomp($newAmount, '0', self::SCALE) <= 0 || bccomp($newAmount, $this->available($customer), self::SCALE) === 1) {
            throw new SaleValidationException(__('db.Amount exceeds customer deposit!'));
        }

        $customer->expense = bcadd($this->money($customer->expense), $newAmount, self::SCALE);
        $customer->save();

        return $customer;
    }

    public function available(Customer $customer): string
    {
        $available = bcsub($this->money($customer->deposit), $this->money($customer->expense), self::SCALE);

        return bccomp($available, '0', self::SCALE) === -1 ? '0.0000' : $available;
    }

    public function addGross(int $customerId, mixed $amount): Customer
    {
        $customer = $this->lockedCustomer($customerId);
        $this->assertEligible($customer);
        $amount = $this->money($amount);

        if (bccomp($amount, '0', self::SCALE) <= 0) {
            throw new SaleValidationException(__('db.Amount must be greater than zero!'));
        }

        $customer->deposit = bcadd($this->money($customer->deposit), $amount, self::SCALE);
        $customer->save();

        return $customer;
    }

    public function updateGross(int $customerId, mixed $oldAmount, mixed $newAmount): Customer
    {
        $customer = $this->lockedCustomer($customerId);
        $this->assertEligible($customer);
        $oldAmount = $this->money($oldAmount);
        $newAmount = $this->money($newAmount);

        if (bccomp($newAmount, '0', self::SCALE) <= 0) {
            throw new SaleValidationException(__('db.Amount must be greater than zero!'));
        }

        $currentGross = $this->money($customer->deposit);
        $currentConsumed = $this->money($customer->expense);
        $proposedGross = bcadd(bcsub($currentGross, $oldAmount, self::SCALE), $newAmount, self::SCALE);

        if (bccomp($proposedGross, $currentConsumed, self::SCALE) === -1) {
            throw new SaleValidationException(__('db.customer_deposit_insufficient_for_update'));
        }

        $customer->deposit = $proposedGross;
        $customer->save();

        return $customer;
    }

    public function removeGross(int $customerId, mixed $amount): Customer
    {
        $customer = $this->lockedCustomer($customerId);
        $this->assertEligible($customer);
        $amount = $this->money($amount);

        $currentGross = $this->money($customer->deposit);
        $currentConsumed = $this->money($customer->expense);
        $proposedGross = bcsub($currentGross, $amount, self::SCALE);

        if (bccomp($proposedGross, $currentConsumed, self::SCALE) === -1) {
            throw new SaleValidationException(__('db.customer_deposit_already_consumed_cannot_delete'));
        }

        $customer->deposit = $proposedGross;
        $customer->save();

        return $customer;
    }

    private function lockedCustomer(int $customerId): Customer
    {
        return Customer::whereKey($customerId)->lockForUpdate()->firstOrFail();
    }

    private function assertEligible(Customer $customer): void
    {
        if (strtolower((string) $customer->type) === CustomerTypeEnum::WALKIN->value) {
            throw new SaleValidationException(__('db.customer_deposit_walkin_unavailable'));
        }
    }

    private function money(mixed $amount): string
    {
        return bcadd((string) ($amount ?? 0), '0', self::SCALE);
    }
}
