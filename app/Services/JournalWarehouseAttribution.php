<?php

namespace App\Services;

final class JournalWarehouseAttribution
{
    public function __construct(
        public readonly ?int $warehouseId,
        public readonly string $classification,
        public readonly string $reason,
        public readonly bool $requiresWarehouse = false,
    ) {
    }

    public static function attributed(int $warehouseId, string $reason): self
    {
        return new self($warehouseId, 'attributed', $reason);
    }

    public static function global(string $reason): self
    {
        return new self(null, 'global', $reason);
    }

    public static function unresolved(string $reason, bool $requiresWarehouse = true): self
    {
        return new self(null, 'unresolved', $reason, $requiresWarehouse);
    }
}
