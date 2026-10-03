<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use ZipArchive;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use App\Models\Category;
use App\Models\Brand;
use App\Models\Product;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Models\landlord\Tenant;
use Nwidart\Modules\Facades\Module;
use RuntimeException;


class AddonInstallController extends Controller
{
    private const MAX_PACKAGE_BYTES = 262144000; // 250 MiB
    private const MAX_ARCHIVE_ENTRIES = 10000;

    public function __construct()
    {
        // Installation executes vendor PHP/migrations. Keep this guard on the
        // controller as well as the route group so new installer actions cannot
        // accidentally be exposed without an administrator check.
        $this->middleware(function (Request $request, $next) {
            abort_unless($request->user() && in_array((int) $request->user()->role_id, [1, 2], true), 403);

            return $next($request);
        });
    }

    public function saasInstall(Request $request)
    {
        $data = [
            'purchase_code' => $request->purchase_code,
            'product' => 'saas'
        ];
        $path = '';
        $module = 'saas';
        return $this->addonIstallUnzipMigrateRemoveTempFolder($data, $path, $module);
    }

    public function ecommerceInstall(Request $request)
    {
        return $this->enableBundledModule($request, 'Ecommerce');
    }

    public function woocommerceInstall(Request $request)
    {
        return $this->enableBundledModule($request, 'Woocommerce');
    }

    public function apiInstall(Request $request)
    {
        $data = [
            'purchase_code' => $request->purchase_code,
            'product' => (config('database.connections.saleprosaas_landlord')) ? 'saas_api' : 'api'
        ];
        $path = '/app/Http/Controllers/';
        $module = 'api';
        return $this->addonIstallUnzipMigrateRemoveTempFolder($data, $path, $module);
    }

    public function addonIstallUnzipMigrateRemoveTempFolder($data, $path, $module)
    {
        $db_str = '';
        if(!config('database.connections.saleprosaas_landlord')) {
            $db_str = 'db.';
        }
        if(!config('app.user_verified')) {
            return redirect()->back()->with('not_permitted', __($db_str.'This feature is disable for demo!'));
        }

        try {
            $manifest = $this->requestPackageManifest($data);
            $temporaryDirectory = storage_path('app/addon-install-' . (string) Str::uuid());
            File::ensureDirectoryExists($temporaryDirectory, 0700, true);
            $localFilePath = $temporaryDirectory . DIRECTORY_SEPARATOR . 'package.zip';

            try {
                $this->downloadPackage($manifest['url'], $localFilePath);
                $actualHash = hash_file('sha256', $localFilePath);
                if (!is_string($actualHash) || !hash_equals($manifest['sha256'], $actualHash)) {
                    throw new RuntimeException('Downloaded add-on package checksum does not match the vendor manifest.');
                }

                $this->extractVerifiedPackage($localFilePath, base_path($path));

                if ($module == 'saas') {
                    return redirect('/');
                }

                if(!config('database.connections.saleprosaas_landlord')) {
                    if ($module == 'ecommerce' || $module == 'woocommerce') {
                        Artisan::call('module:migrate', ['--force' => true]);
                    }

                    if ($module != 'saas') {
                        $settings = DB::table('general_settings')->select('id','modules')->first();
                        if(isset($settings->modules) && (!in_array($module,explode(',',$settings->modules)))){
                            $new_modules = $settings->modules.','.$module;
                        }else{
                            $new_modules = $module;
                        }
                        DB::table('general_settings')->where('id',1)->update(['modules'=>$new_modules]);
                    }

                    if ($module == 'ecommerce') {
                        $this->categorySlug();
                        $this->brandSlug();
                        $this->productSlug();
                    }
                }
                else {
                    $tenant_all = Tenant::all();
                    if(count($tenant_all)) {
                        \Artisan::call('tenants:migrate');
                    }
                }

                $data = [
                    'path' => $manifest['url'],
                ];
                $this->postToVendor('https://lion-coders.com/api/addon-db/', $data);
            } finally {
                File::deleteDirectory($temporaryDirectory);
            }

            return redirect()->back()->with('message', __($db_str.'Add-on installed successfully!'));
        } catch (RuntimeException $exception) {
            Log::warning('Add-on installation rejected.', ['module' => $module, 'reason' => $exception->getMessage()]);
            return redirect()->back()->with('not_permitted', __($db_str.'Wrong purchase code!'));
        }
    }

    private function requestPackageManifest(array $data): array
    {
        $response = $this->postToVendor('https://lion-coders.com/api/addon-install/', $data);
        $manifest = json_decode($response, true);

        if (!is_array($manifest) || !is_string($manifest['url'] ?? null) || !is_string($manifest['sha256'] ?? null)) {
            throw new RuntimeException('Vendor did not return a signed add-on package manifest.');
        }

        $url = trim($manifest['url']);
        $sha256 = strtolower(trim($manifest['sha256']));
        if (!$this->isTrustedVendorUrl($url) || preg_match('/\A[a-f0-9]{64}\z/', $sha256) !== 1) {
            throw new RuntimeException('Vendor returned an untrusted add-on package manifest.');
        }

        return compact('url', 'sha256');
    }

    private function postToVendor(string $url, array $data): string
    {
        if (!$this->isTrustedVendorUrl($url)) {
            throw new RuntimeException('Refusing an untrusted add-on vendor endpoint.');
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $data,
            CURLOPT_POSTREDIR => 3,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_CONNECTTIMEOUT => 10,
        ]);
        $this->restrictCurlToHttps($ch);
        $response = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($response === false || $status < 200 || $status >= 300) {
            throw new RuntimeException('Add-on vendor request failed' . ($error ? ': ' . $error : '.'));
        }

        return $response;
    }

    private function downloadPackage(string $url, string $destination): void
    {
        if (!$this->isTrustedVendorUrl($url)) {
            throw new RuntimeException('Refusing an add-on download from an untrusted host.');
        }

        $handle = fopen($destination, 'xb');
        if ($handle === false) {
            throw new RuntimeException('Unable to create a private add-on download file.');
        }

        $bytesWritten = 0;
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_TIMEOUT => 300,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_FAILONERROR => true,
            CURLOPT_WRITEFUNCTION => function ($curl, string $chunk) use ($handle, &$bytesWritten): int {
                $bytesWritten += strlen($chunk);
                if ($bytesWritten > self::MAX_PACKAGE_BYTES) {
                    return 0;
                }

                return fwrite($handle, $chunk) ?: 0;
            },
        ]);
        $this->restrictCurlToHttps($ch);
        $success = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        fclose($handle);

        if ($success === false || $status < 200 || $status >= 300 || $bytesWritten === 0) {
            throw new RuntimeException('Add-on package download failed' . ($error ? ': ' . $error : '.'));
        }
    }

    private function extractVerifiedPackage(string $archivePath, string $destination): void
    {
        $zip = new ZipArchive();
        if ($zip->open($archivePath, ZipArchive::CHECKCONS) !== true) {
            throw new RuntimeException('Downloaded add-on is not a valid ZIP archive.');
        }

        $stagingDirectory = storage_path('app/addon-extract-' . (string) Str::uuid());
        File::ensureDirectoryExists($stagingDirectory, 0700, true);
        $totalUncompressedBytes = 0;

        try {
            if ($zip->numFiles < 1 || $zip->numFiles > self::MAX_ARCHIVE_ENTRIES) {
                throw new RuntimeException('Add-on archive has an invalid number of entries.');
            }

            for ($index = 0; $index < $zip->numFiles; $index++) {
                $stat = $zip->statIndex($index);
                $name = $stat['name'] ?? '';
                if (!is_string($name) || !$this->isSafeArchiveEntry($name) || !$this->isRegularArchiveEntry($zip, $index)) {
                    throw new RuntimeException('Add-on archive contains an unsafe file path.');
                }

                $totalUncompressedBytes += (int) ($stat['size'] ?? 0);
                if ($totalUncompressedBytes > self::MAX_PACKAGE_BYTES) {
                    throw new RuntimeException('Add-on archive exceeds the uncompressed size limit.');
                }

                $target = $stagingDirectory . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, rtrim($name, '/'));
                if (str_ends_with($name, '/')) {
                    File::ensureDirectoryExists($target, 0700, true);
                    continue;
                }

                File::ensureDirectoryExists(dirname($target), 0700, true);
                $input = $zip->getStream($name);
                $output = fopen($target, 'xb');
                if ($input === false || $output === false) {
                    throw new RuntimeException('Unable to safely unpack add-on archive.');
                }
                stream_copy_to_stream($input, $output);
                fclose($input);
                fclose($output);
            }

            if (!File::copyDirectory($stagingDirectory, $destination)) {
                throw new RuntimeException('Unable to install the verified add-on package.');
            }
        } finally {
            $zip->close();
            File::deleteDirectory($stagingDirectory);
        }
    }

    private function isTrustedVendorUrl(string $url): bool
    {
        $parts = parse_url($url);
        if ($parts === false || ($parts['scheme'] ?? null) !== 'https' || isset($parts['user']) || isset($parts['pass'])) {
            return false;
        }

        $host = strtolower((string) ($parts['host'] ?? ''));
        $trustedHosts = array_map('strtolower', config('addon.trusted_hosts', []));
        return $host !== '' && in_array($host, $trustedHosts, true) && (!isset($parts['port']) || (int) $parts['port'] === 443);
    }

    private function restrictCurlToHttps($ch): void
    {
        if (defined('CURLOPT_PROTOCOLS') && defined('CURLPROTO_HTTPS')) {
            curl_setopt($ch, CURLOPT_PROTOCOLS, CURLPROTO_HTTPS);
        }
        if (defined('CURLOPT_REDIR_PROTOCOLS') && defined('CURLPROTO_HTTPS')) {
            curl_setopt($ch, CURLOPT_REDIR_PROTOCOLS, CURLPROTO_HTTPS);
        }
    }

    private function isSafeArchiveEntry(string $name): bool
    {
        if ($name === '' || str_contains($name, "\0") || str_contains($name, '\\') || str_starts_with($name, '/') || preg_match('/\A[a-zA-Z]:/', $name)) {
            return false;
        }

        foreach (explode('/', rtrim($name, '/')) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                return false;
            }
        }

        return true;
    }

    private function isRegularArchiveEntry(ZipArchive $zip, int $index): bool
    {
        if (!$zip->getExternalAttributesIndex($index, $operations, $attributes)) {
            return true;
        }

        // Reject Unix symlinks so staged extraction cannot escape its target.
        return (($attributes >> 16) & 0170000) !== 0120000;
    }

    private function enableBundledModule(Request $request, string $moduleName)
    {
        abort_unless($request->user() && in_array((int) $request->user()->role_id, [1, 2], true), 403);

        $module = Module::find($moduleName);
        abort_if($module === null, 404, "Bundled {$moduleName} module is missing.");

        $module->enable();
        if (config('database.connections.saleprosaas_landlord')) {
            Artisan::call('tenants:migrate');
        } else {
            Artisan::call('module:migrate', [
                'module' => $moduleName,
                '--force' => true,
            ]);
        }

        return redirect()->back()->with('message', __("db.{$moduleName} module enabled successfully!"));
    }

    public function categorySlug()
    {
        $catgories = Category::select('id','name','slug')->get();
        foreach($catgories as $cat){
            $cat->slug = Str::slug($cat->name, '-');
            $cat->save();
        }
    }

    public function brandSlug()
    {
        $brands = Brand::select('id','title','slug')->get();
        foreach($brands as $brand){
            $brand->slug = Str::slug($brand->title, '-');
            $brand->save();
        }
    }

    public function productSlug()
    {
        $products = Product::select('id','name','slug')->get();
        foreach($products as $product){
            $product->slug = Str::slug($product->name, '-');
            $product->save();
        }
    }

}
