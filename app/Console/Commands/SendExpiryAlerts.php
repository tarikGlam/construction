<?php

namespace App\Console\Commands;

use App\DTOs\NotificationEventData;
use App\Models\Product;
use App\Models\Product_Warehouse;
use App\Models\ProductBatch;
use App\Services\NotificationService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

class SendExpiryAlerts extends Command
{
    protected $signature = 'notifications:expiry-check {days=30}';
    protected $description = 'Scan inventory rows and alert admins about products expiring within X days.';

    public function handle(): int
    {
        $days = (int) $this->argument('days');
        $today = Carbon::now()->toDateString();
        $thresholdDate = Carbon::now()->addDays($days)->toDateString();

        // Product-level expiry where legitimate in schema
        $expiringProducts = collect();
        if (Schema::hasColumn('products', 'expiry_date')) {
            $expiringProducts = Product::whereNotNull('expiry_date')
                ->where('expiry_date', '>=', $today)
                ->where('expiry_date', '<=', $thresholdDate)
                ->get();
        }

        // Authoritative batch-level expiry
        $expiringBatches = ProductBatch::query()
            ->where('qty', '>', 0)
            ->whereNotNull('expired_date')
            ->whereBetween('expired_date', [$today, $thresholdDate])
            ->with('product:id,name,code')
            ->get();

        if ($expiringProducts->isEmpty() && $expiringBatches->isEmpty()) {
            $this->info('No expiring stock items detected.');
            return 0;
        }

        $notificationService = app(NotificationService::class);
        $count = 0;

        // Dispatch a deterministic, idempotent event per expiring product record
        foreach ($expiringProducts as $product) {
            $dto = NotificationEventData::forExpiry(
                $product,
                $today,
                "Exp: {$product->expiry_date}"
            );

            $notificationService->dispatch($dto);
            $count++;
        }

        foreach ($expiringBatches as $batch) {
            if (!$batch->product) {
                continue;
            }

            $warehouseId = (int) (Product_Warehouse::where('product_batch_id', $batch->id)
                ->where('qty', '>', 0)
                ->value('warehouse_id') ?? 0);

            $dto = NotificationEventData::forBatchExpiry($batch, $batch->product, $today);
            if ($warehouseId > 0) {
                $dto->warehouseId = $warehouseId;
            }

            $notificationService->dispatch($dto);
            $count++;
        }

        $this->info("Expiry alerts dispatched for {$count} expiring products/batches.");
        return 0;
    }
}
