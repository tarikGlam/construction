<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\QrCode;
use App\Models\Warehouse;
use App\Models\Table;
use App\Models\QrCatelogSetting;
use App\Services\PermissionService;
use App\Services\QrCodeStorageService;
use App\Services\WarehouseAccessService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use SimpleSoftwareIO\QrCode\Facades\QrCode as SimpleQrCode;
use Throwable;

class QrCodeController extends Controller
{
    public function __construct(
        private WarehouseAccessService $warehouseAccess,
        private PermissionService $permissions,
        private QrCodeStorageService $storage
    ) {}

    /**
     * Generate QR code for a warehouse or table
     */
    public function index()
    {
        $general_setting = cache()->get('general_setting');
        $type = $this->catalogueTargetType($general_setting);
        $this->authorizeManagement($type);

        $qr_catelog_setting = QrCatelogSetting::latest()->first();
        if (!$qr_catelog_setting) {
            $qr_catelog_setting = new QrCatelogSetting();
            $qr_catelog_setting->show_stock_out_product = 1;
        }
      
        if ($type === 'table')
        {
            $tables = Table::where('is_active', true)
                ->whereIn('floor_id', $this->authorizedFloorIds())
                ->get();
            return view('backend.qr-menu.index', compact('tables', 'general_setting', 'qr_catelog_setting'));
        }
        else
        {
            $lims_warehouse_list = Warehouse::where('is_active', true)->get();
            return view('backend.qr-menu.index', compact('lims_warehouse_list', 'general_setting','qr_catelog_setting'));
        }
    }

    public function generate(Request $request, $type, $id)
    {
        $validated = $request->validate([
            'color' => ['nullable', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'show_logo' => ['nullable', 'boolean'],
        ]);

        $color = $validated['color'] ?? '#000000';
        $showLogo = (bool) ($validated['show_logo'] ?? true);
        $type = (string) $type;
        $id = (int) $id;

        [$model, $warehouse] = $this->resolveTarget($type, $id);
        $this->authorizeTarget($type, $warehouse->id);

        if ($type === 'warehouse') {
            $warehouse_name = $warehouse->name;
            $slug = Str::slug($warehouse_name);
            $url = url("/menu/{$slug}");
        } else {
            $warehouse_name = $warehouse->name;
            $slug = Str::slug($warehouse_name);
            $url = url("/menu/{$slug}?table_id={$id}");
        }

        $general_setting = cache()->get('general_setting');

        $replacementPath = null;
        $obsoletePath = null;

        try {
            // Imagick enables PNG/logo rendering. SVG remains the local fallback.
            $hasImagick = extension_loaded('imagick') && class_exists('Imagick');

            if ($hasImagick) {
                $qrGenerator = SimpleQrCode::format('png')
                    ->size(400)
                    ->errorCorrection('H')
                    ->color(...sscanf($color, "#%02x%02x%02x"));
                $fileExtension = 'png';

                if ($showLogo && $general_setting && $general_setting->site_logo) {
                    $logoPath = public_path('logo/' . $general_setting->site_logo);

                    if (file_exists($logoPath)) {
                        $qrGenerator = $qrGenerator->merge($logoPath, 0.2, true);
                    }
                }
            } else {
                $qrGenerator = SimpleQrCode::format('svg')
                    ->size(400)
                    ->errorCorrection('H')
                    ->color(...sscanf($color, "#%02x%02x%02x"));
                $fileExtension = 'svg';
            }

            $qr = DB::transaction(function () use (
                $model,
                $type,
                $id,
                $url,
                $qrGenerator,
                $fileExtension,
                &$replacementPath,
                &$obsoletePath
            ) {
                $table = $type === 'warehouse' ? 'warehouses' : 'tables';
                $targetQuery = DB::table($table)
                    ->where('id', $model->id)
                    ->where('is_active', true);

                if (!$targetQuery->lockForUpdate()->first(['id'])) {
                    throw new \RuntimeException('QR target changed during generation.');
                }

                $qr = QrCode::where('qrable_id', $model->id)
                    ->where('qrable_type', get_class($model))
                    ->lockForUpdate()
                    ->first();

                if (!$qr) {
                    $qr = new QrCode([
                        'qrable_id' => $model->id,
                        'qrable_type' => get_class($model),
                        'code' => (string) Str::uuid(),
                    ]);
                }

                $redirectUrl = url('/q/'.$qr->code);
                $qrImage = $qrGenerator->generate($redirectUrl);
                $replacementPath = $this->storage->storeReplacement(
                    (string) $qrImage,
                    $type,
                    $id,
                    $fileExtension
                );

                $obsoletePath = $qr->path;
                $qr->url = $url;
                $qr->path = $replacementPath;
                $qr->is_active = true;
                $qr->save();

                $targetQuery->update(['qr_code_id' => $qr->id]);

                return $qr;
            });
        } catch (Throwable $exception) {
            if ($replacementPath) {
                $this->storage->delete($replacementPath);
            }

            report($exception);

            return response()->json([
                'success' => false,
                'message' => $this->dbText('QR Code generation failed'),
            ], 500);
        }

        if ($obsoletePath && $obsoletePath !== $replacementPath) {
            try {
                $this->storage->delete($obsoletePath);
            } catch (Throwable $exception) {
                report($exception);
            }
        }

        return response()->json([
            'success' => true,
            'message' => $this->dbText('QR Code successfully generated'),
            'qr_url'  => route('qr.image', $qr->id),
            'download_url' => route('qr.download', $qr->id),
            'url'     => $qr->url,
            'code'    => $qr->code,
            'entity_type' => $type,
            'entity_id' => (int) $model->id,
        ]);
    }

    /**
     * View QR code details
     */
    public function show($id)
    {
        $qr = QrCode::where('is_active', true)->findOrFail($id);
        [$type, $warehouseId] = $this->resolveQrWarehouse($qr);
        $this->authorizeTarget($type, $warehouseId);
        abort_unless($qr->path && $this->storage->exists($qr->path), 404, $this->dbText('QR Code image not found.'));

        return response()->json([
            'success'   => true,
            'image_url' => route('qr.image', $qr->id),
            'download_url' => route('qr.download', $qr->id),
            'url'       => $qr->url,
            'code'      => $qr->code,
            'redirect'  => url('/q/' . $qr->code),
            'entity_type' => $type,
            'entity_id' => (int) $qr->qrable_id,
        ]);
    }

    /**
     * Serve a protected QR image for an authorized operator.
     */
    public function image($id)
    {
        $qr = QrCode::where('is_active', true)->findOrFail($id);
        [$type, $warehouseId] = $this->resolveQrWarehouse($qr);
        $this->authorizeTarget($type, $warehouseId);

        $filePath = $this->storedFilePath($qr);

        return response()->file($filePath, [
            'Content-Type' => $this->storage->mimeType($qr->path),
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /**
     * Download QR code
     */
    public function download($id)
    {
        $qr = QrCode::where('is_active', true)->findOrFail($id);
        [$type, $warehouseId] = $this->resolveQrWarehouse($qr);
        $this->authorizeTarget($type, $warehouseId);

        $filePath = $this->storedFilePath($qr);

        return response()->download($filePath, basename($filePath), [
            'Content-Type' => $this->storage->mimeType($qr->path),
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /**
     * Redirect route /q/{code}
     */
    public function redirect($code)
    {
        $qr = QrCode::where('code', $code)->where('is_active', true)->firstOrFail();
        $this->resolveQrWarehouse($qr);

        return redirect()->away($qr->url);
    }

    public function saveSettings(Request $request)
    {
        $type = $this->catalogueTargetType(cache()->get('general_setting'));
        $this->authorizeManagement($type, true);
        $validated = $request->validate([
            'show_stock_out_product' => ['required', 'boolean'],
        ]);

        $qr_catelog_setting = QrCatelogSetting::latest()->first();
        if (!$qr_catelog_setting) {
            $qr_catelog_setting = new QrCatelogSetting();
        }
        
        $qr_catelog_setting->show_stock_out_product = $validated['show_stock_out_product'];
        $qr_catelog_setting->save();

        return response()->json([
            'success' => true,
            'message' => $this->dbText('Settings updated successfully'),
        ]);
    }

    /**
     * Resolve the requested QR entity and the warehouse that owns it.
     */
    private function resolveTarget(string $type, int $id): array
    {
        if ($type === 'warehouse') {
            $warehouse = Warehouse::withoutGlobalScope('authorized_warehouse')
                ->where('is_active', true)
                ->findOrFail($id);

            return [$warehouse, $warehouse];
        }

        abort_unless($type === 'table', 400, $this->dbText('Invalid QR target type.'));

        $table = Table::where('is_active', true)->findOrFail($id);
        $warehouseId = DB::table('floors')
            ->where('id', $table->floor_id)
            ->where('status', true)
            ->value('warehouse_id');
        abort_unless($warehouseId, 404, $this->dbText('Table floor not found.'));

        $warehouse = Warehouse::withoutGlobalScope('authorized_warehouse')
            ->where('is_active', true)
            ->findOrFail($warehouseId);

        return [$table, $warehouse];
    }

    private function resolveQrWarehouse(QrCode $qr): array
    {
        if ($qr->qrable_type === Warehouse::class) {
            $warehouse = Warehouse::withoutGlobalScope('authorized_warehouse')
                ->where('is_active', true)
                ->findOrFail($qr->qrable_id);

            return ['warehouse', (int) $warehouse->id];
        }

        if ($qr->qrable_type === Table::class) {
            [, $warehouse] = $this->resolveTarget('table', (int) $qr->qrable_id);

            return ['table', (int) $warehouse->id];
        }

        abort(404);
    }

    private function authorizeTarget(string $type, int $warehouseId): void
    {
        $user = auth()->user();
        abort_unless($user, 403);

        $ability = $type === 'table' ? 'restaurant-table' : 'warehouse';
        abort_unless($this->permissions->userHasExplicitPermission($user, $ability), 403);

        $classification = $this->warehouseAccess->classification($user);
        if ($classification === WarehouseAccessService::GLOBAL_OPERATIONAL) {
            return;
        }

        abort_unless($classification === WarehouseAccessService::WAREHOUSE_OPERATIONAL, 403);
        abort_unless($this->warehouseAccess->warehouseId($user) === $warehouseId, 403);
    }

    private function authorizeManagement(string $type, bool $globalOnly = false): void
    {
        $user = auth()->user();
        abort_unless($user, 403);

        $ability = $type === 'table' ? 'restaurant-table' : 'warehouse';
        abort_unless($this->permissions->userHasExplicitPermission($user, $ability), 403);

        if ($globalOnly) {
            abort_unless(
                $this->warehouseAccess->classification($user) === WarehouseAccessService::GLOBAL_OPERATIONAL,
                403
            );
        }
    }

    private function authorizedFloorIds()
    {
        $query = DB::table('floors')->select('id')->where('status', true);
        $this->warehouseAccess->scope($query);

        return $query;
    }

    private function catalogueTargetType(?object $generalSetting): string
    {
        $modules = explode(',', (string) ($generalSetting->modules ?? ''));

        return in_array('restaurant', $modules, true) ? 'table' : 'warehouse';
    }

    private function storedFilePath(QrCode $qr): string
    {
        abort_unless($qr->path, 404, $this->dbText('QR Code image not found.'));

        $filePath = $this->storage->absolutePath($qr->path);
        abort_unless(is_file($filePath), 404, $this->dbText('QR Code image not found.'));

        return $filePath;
    }

    private function dbText(string $key): string
    {
        $translationKey = 'db.'.$key;
        $translated = __($translationKey);

        return $translated === $translationKey ? $key : $translated;
    }
}
