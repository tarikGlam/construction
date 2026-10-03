<?php

namespace App\Services;

use App\DTOs\NotificationEventData;
use Illuminate\Support\Facades\DB;

class NotificationBatchCollector
{
    private array $items = [];

    public function __construct(
        public readonly string $event
    ) {}

    /**
     * Add a lightweight scalar item record to the batch.
     */
    public function add(array $itemData): void
    {
        $this->items[] = $itemData;
    }

    public function count(): int
    {
        return count($this->items);
    }

    /**
     * Dispatch collected batch items in chunks after the import transaction commits.
     */
    public function dispatchAfterCommit(callable $itemToDtoConverter): void
    {
        if (empty($this->items)) {
            return;
        }

        $items = $this->items;
        $this->items = []; // Clear in-memory array

        DB::afterCommit(function () use ($items, $itemToDtoConverter) {
            $notificationService = app(NotificationService::class);
            $chunkSize = 50;

            foreach (array_chunk($items, $chunkSize) as $chunk) {
                foreach ($chunk as $itemData) {
                    try {
                        $dto = $itemToDtoConverter($itemData);
                        if ($dto instanceof NotificationEventData) {
                            $notificationService->dispatch($dto);
                        }
                    } catch (\Throwable $e) {
                        \Illuminate\Support\Facades\Log::error('Batch notification item failed.', [
                            'item' => $itemData['id'] ?? null,
                            'error' => $e->getMessage(),
                        ]);
                    }
                }
            }
        });
    }
}
