<?php

namespace Modules\AIAssistant\Tests\Feature;

use Tests\TestCase;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Modules\AIAssistant\DTO\AssistantMessageData;
use Modules\AIAssistant\DTO\AssistantContextData;
use Modules\AIAssistant\Services\PromptNormalizer;
use Modules\AIAssistant\Services\LocalizedIntentMatcher;
use Modules\AIAssistant\Services\SkillRegistry;
use Modules\AIAssistant\Services\AssistantOrchestrator;
use App\Models\User;
use App\Models\Warehouse;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\View;
use Tests\Support\SeedsAiAssistantAccess;

class AIAssistantLocalizationTest extends TestCase
{
    use DatabaseTransactions;
    use SeedsAiAssistantAccess;

    private SkillRegistry $registry;
    private AssistantOrchestrator $orchestrator;
    private User $adminUser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->enableAiAssistantForFixture();

        $this->registry = app(SkillRegistry::class);
        $this->orchestrator = app(AssistantOrchestrator::class);

        // Load canonical English translations
        $english = collect(include database_path('seeders/Tenant/translations/en.php'))
            ->filter(fn (array $row) => str_starts_with($row['key'], 'ai_assistant'))
            ->mapWithKeys(fn (array $row) => ['db.' . $row['key'] => $row['value']])
            ->all();
        app('translator')->addLines($english, 'en');

        // Load Spanish translations
        $spanish = collect(include database_path('seeders/Tenant/translations/es.php'))
            ->filter(fn (array $row) => str_starts_with($row['key'], 'ai_assistant'))
            ->mapWithKeys(fn (array $row) => ['db.' . $row['key'] => $row['value']])
            ->all();
        app('translator')->addLines($spanish, 'es');

        // Load Arabic translations
        $arabic = collect(include database_path('seeders/Tenant/translations/ar.php'))
            ->filter(fn (array $row) => str_starts_with($row['key'], 'ai_assistant'))
            ->mapWithKeys(fn (array $row) => ['db.' . $row['key'] => $row['value']])
            ->all();
        app('translator')->addLines($arabic, 'ar');

        // Setup Admin User with permissions for page testing
        $permission = Permission::firstOrCreate(['name' => 'ai-assistant-index', 'guard_name' => 'web']);
        DB::table('roles')->updateOrInsert(['id' => 1], ['name' => 'Admin', 'guard_name' => 'web', 'is_active' => 1]);
        $adminRole = Role::findOrFail(1);
        $this->grantAiAssistantAccess((int) $adminRole->id);
        DB::table('role_has_permissions')->updateOrInsert([
            'permission_id' => $permission->id,
            'role_id' => $adminRole->id,
        ]);
        app()->make(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
        Cache::forget('role_has_permissions_list1');

        $this->adminUser = User::create([
            'name' => 'Admin User',
            'email' => 'admin_loc_' . uniqid() . '@example.com',
            'phone' => '123456789' . rand(100, 999),
            'password' => bcrypt('password'),
            'role_id' => 1,
            'is_active' => true,
            'is_deleted' => false
        ]);

        $role_has_permissions_list = DB::table('permissions')
            ->join('role_has_permissions', 'permissions.id', '=', 'role_has_permissions.permission_id')
            ->where('role_id', 1)
            ->get();
        View::share('role_has_permissions_list', $role_has_permissions_list);
        View::share('alert_product', 0);
        View::share('dso_alert_product_no', 0);
        View::share('expire_alert_products', 0);
        View::share('languages', collect());
        View::share('theme_font', 'Nunito');
        View::share('theme', 'light');
        View::share('theme_color', '#7c5cc4');
        View::share('currency', (object)['code' => 'USD']);
    }

    protected function tearDown(): void
    {
        App::setLocale('en');
        parent::tearDown();
    }

    /**
     * Test 1: Conservative Unicode Prompt Normalization & Numerals
     */
    public function test_prompt_normalizer_handles_unicode_arabic_and_numerals(): void
    {
        // English with apostrophes & punctuation
        $this->assertSame('todays sales', PromptNormalizer::normalize("Today's sales!"));
        $this->assertSame('todays sales', PromptNormalizer::normalize("  Today's   Sales?  "));

        // Spanish with accents
        $this->assertSame('ventas de hoy', PromptNormalizer::normalize('¿Ventas de hoy?'));
        $this->assertSame('productos más vendidos', PromptNormalizer::normalize('¡Productos más vendidos!'));

        // Arabic with Eastern Arabic numerals and Arabic punctuation
        $this->assertSame('مبيعات اليوم', PromptNormalizer::normalize('مبيعات اليوم؟'));
        $this->assertSame('مبيعات 2026', PromptNormalizer::normalize('مبيعات ٢٠٢٦'));
    }

    /**
     * Test 2: English Behavior Remains Intact Across All Skills
     */
    public function test_english_behavior_remains_intact_for_all_skills(): void
    {
        App::setLocale('en');

        $englishSkillMap = [
            "today's sales" => 'sales_summary',
            'show today sales' => 'sales_summary',
            'todays purchases' => 'purchase_summary',
            'low stock' => 'low_stock',
            'which products are low in stock' => 'low_stock',
            'top selling products' => 'top_products',
            'customer due summary' => 'customer_due',
            'supplier due summary' => 'supplier_due',
            'cash and bank summary' => 'cash_bank_summary',
            'todays expenses' => 'expense_summary',
            'daily business snapshot' => 'daily_snapshot',
            'slow moving products' => 'slow_moving_products',
        ];

        foreach ($englishSkillMap as $prompt => $expectedSkillKey) {
            $msg = new AssistantMessageData('user', $prompt);
            $skill = $this->registry->resolve($msg);
            $this->assertNotNull($skill, "Failed to resolve English prompt: '{$prompt}'");
            $this->assertSame($expectedSkillKey, $skill->key(), "Prompt '{$prompt}' did not resolve to expected skill '{$expectedSkillKey}'");
        }
    }

    /**
     * Test 3: Non-English LTR Intent Aliases (Spanish) Map Correctly
     */
    public function test_spanish_intent_aliases_map_to_correct_skills(): void
    {
        App::setLocale('es');

        $spanishSkillMap = [
            'ventas de hoy' => 'sales_summary',
            'resumen de ventas' => 'sales_summary',
            'compras de hoy' => 'purchase_summary',
            'resumen de compras' => 'purchase_summary',
            'stock bajo' => 'low_stock',
            'alerta de stock' => 'low_stock',
            'productos mas vendidos' => 'top_products',
            'deudas de clientes' => 'customer_due',
            'deudas de proveedores' => 'supplier_due',
            'resumen de caja y banco' => 'cash_bank_summary',
            'gastos de hoy' => 'expense_summary',
            'resumen de gastos' => 'expense_summary',
            'instantanea diaria' => 'daily_snapshot',
            'resumen diario' => 'daily_snapshot',
            'productos de lento movimiento' => 'slow_moving_products',
        ];

        foreach ($spanishSkillMap as $prompt => $expectedSkillKey) {
            $msg = new AssistantMessageData('user', $prompt);
            $skill = $this->registry->resolve($msg);
            $this->assertNotNull($skill, "Failed to resolve Spanish prompt: '{$prompt}'");
            $this->assertSame($expectedSkillKey, $skill->key(), "Spanish prompt '{$prompt}' did not resolve to '{$expectedSkillKey}'");
        }
    }

    /**
     * Test 4: RTL Intent Aliases (Arabic) Map Correctly
     */
    public function test_arabic_intent_aliases_map_to_correct_skills(): void
    {
        App::setLocale('ar');

        $arabicSkillMap = [
            'مبيعات اليوم' => 'sales_summary',
            'ملخص المبيعات' => 'sales_summary',
            'مشتريات اليوم' => 'purchase_summary',
            'ملخص المشتريات' => 'purchase_summary',
            'نقص المخزون' => 'low_stock',
            'تنبيهات المخزون' => 'low_stock',
            'المنتجات الاكثر مبيعا' => 'top_products',
            'مستحقات العملاء' => 'customer_due',
            'ديون العملاء' => 'customer_due',
            'مستحقات الموردين' => 'supplier_due',
            'ديون الموردين' => 'supplier_due',
            'ملخص النقد والبنوك' => 'cash_bank_summary',
            'مصروفات اليوم' => 'expense_summary',
            'ملخص المصروفات' => 'expense_summary',
            'نظرة عامة يومية' => 'daily_snapshot',
            'منتجات بطيئة الحركة' => 'slow_moving_products',
        ];

        foreach ($arabicSkillMap as $prompt => $expectedSkillKey) {
            $msg = new AssistantMessageData('user', $prompt);
            $skill = $this->registry->resolve($msg);
            $this->assertNotNull($skill, "Failed to resolve Arabic prompt: '{$prompt}'");
            $this->assertSame($expectedSkillKey, $skill->key(), "Arabic prompt '{$prompt}' did not resolve to '{$expectedSkillKey}'");
        }
    }

    /**
     * Test 5: Deterministic Direct Intent Codes (Suggestion Buttons)
     */
    public function test_direct_intent_identifiers_resolve_deterministically(): void
    {
        $directIntents = [
            'intent:sales_today' => 'sales_summary',
            'intent:purchases_today' => 'purchase_summary',
            'intent:low_stock' => 'low_stock',
            'intent:top_products' => 'top_products',
            'intent:customer_due' => 'customer_due',
            'intent:supplier_due' => 'supplier_due',
            'intent:cash_bank' => 'cash_bank_summary',
            'intent:expenses_today' => 'expense_summary',
            'intent:daily_snapshot' => 'daily_snapshot',
            'intent:slow_products' => 'slow_moving_products',
        ];

        foreach ($directIntents as $intentCode => $expectedSkillKey) {
            $msg = new AssistantMessageData('user', $intentCode);
            $skill = $this->registry->resolve($msg);
            $this->assertNotNull($skill, "Failed to resolve direct intent: '{$intentCode}'");
            $this->assertSame($expectedSkillKey, $skill->key());
        }
    }

    /**
     * Test 6: Non-English Results Use Localized Labels
     */
    public function test_non_english_results_use_localized_labels(): void
    {
        App::setLocale('es');

        $context = new AssistantContextData(
            userId: (int) $this->adminUser->id,
            businessContext: []
        );

        $msg = new AssistantMessageData('user', 'intent:sales_today');
        $response = $this->orchestrator->executeStructured($msg, $context);

        $this->assertSame('card', $response->responseType);
        $this->assertNotEmpty($response->cards);

        // Check card titles are localized in Spanish
        $cardTitles = array_column($response->cards, 'title');
        $this->assertContains('Transacciones', $cardTitles);
        $this->assertContains('Total bruto', $cardTitles);
        $this->assertContains('Monto pagado', $cardTitles);
        $this->assertContains('Monto pendiente', $cardTitles);

        // Check link label is localized
        $linkLabels = array_column($response->links, 'label');
        $this->assertContains('Ver ventas', $linkLabels);
    }

    /**
     * Test 7: Missing Translation Safely Falls Back to Canonical English
     */
    public function test_missing_translation_safely_falls_back(): void
    {
        App::setLocale('al'); // Locale without explicit overrides for ai_assistant

        $translated = __('db.ai_assistant_structured_subtitle');
        $this->assertSame('Free Structured Assistant', $translated);
        $this->assertStringNotContainsString('db.ai_assistant', $translated);
    }

    /**
     * Test 8: No Raw 'db.*' Keys Appear in View in Any Locale
     */
    public function test_no_raw_db_keys_in_rendered_view(): void
    {
        $locales = ['en', 'es', 'ar'];

        foreach ($locales as $locale) {
            App::setLocale($locale);

            $view = view('aiassistant::index')->render();
            $this->assertStringNotContainsString('db.ai_assistant', $view, "Raw db key found in {$locale} rendered view");

            // Also check via controller endpoint
            $response = $this->actingAs($this->adminUser)
                             ->withCookie('theme_font', 'Nunito')
                             ->withCookie('theme', 'light')
                             ->withCookie('theme_color', '#7c5cc4')
                             ->get(route('ai-assistant.index'));

            $response->assertStatus(200);
            $content = $response->getContent();
            $this->assertStringNotContainsString('db.ai_assistant', $content, "Raw db key found in {$locale} HTTP response");
        }
    }

    /**
     * Test 9: Warehouse & Authorization Scoping Is Preserved Identically Across Locales
     */
    public function test_warehouse_scoping_is_identical_across_locales(): void
    {
        $warehouse = Warehouse::first();
        $warehouseId = $warehouse ? (int) $warehouse->id : 1;

        $scopedContext = new AssistantContextData(
            userId: (int) $this->adminUser->id,
            businessContext: ['warehouse_ids' => [$warehouseId]]
        );

        // Test English vs Spanish vs Arabic
        App::setLocale('en');
        $resEn = $this->orchestrator->executeStructured(new AssistantMessageData('user', 'sales today'), $scopedContext);

        App::setLocale('es');
        $resEs = $this->orchestrator->executeStructured(new AssistantMessageData('user', 'ventas de hoy'), $scopedContext);

        App::setLocale('ar');
        $resAr = $this->orchestrator->executeStructured(new AssistantMessageData('user', 'مبيعات اليوم'), $scopedContext);

        $this->assertSame($resEn->responseType, $resEs->responseType);
        $this->assertSame($resEn->responseType, $resAr->responseType);
        $this->assertCount(count($resEn->cards), $resEs->cards);
        $this->assertCount(count($resEn->cards), $resAr->cards);
    }
}
