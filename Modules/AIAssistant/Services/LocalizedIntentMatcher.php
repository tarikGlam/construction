<?php

namespace Modules\AIAssistant\Services;

class LocalizedIntentMatcher
{
    /**
     * Stable intent aliases mapping internal localized phrases to canonical skill keys.
     * These aliases are internal parser vocabulary and strictly independent of user-customizable UI translation strings.
     *
     * @var array<string, array<string, string[]>>
     */
    private static array $intentAliases = [
        'sales_summary' => [
            'en' => [
                'show todays sales',
                'show today sales',
                'today sales',
                'todays sales',
                'today\'s sales',
                'sales today',
                'sales summary',
                'sales summary today',
                'sales of today',
                'show sales for today',
                'sales for today',
                'sales this month',
                'sales this week',
                'sales yesterday',
                'sales last month',
                'sales last week',
                'sales',
            ],
            'es' => [
                'ventas de hoy',
                'ventas hoy',
                'resumen de ventas',
                'resumen de ventas de hoy',
                'mostrar ventas de hoy',
                'ventas del dia',
            ],
            'ar' => [
                'مبيعات اليوم',
                'مبيعات اليوم ملخص',
                'ملخص المبيعات',
                'ملخص مبيعات اليوم',
                'عرض مبيعات اليوم',
                'مبيعات هذا اليوم',
            ],
        ],
        'purchase_summary' => [
            'en' => [
                'show todays purchases',
                'show today purchases',
                'todays purchases',
                'today purchases',
                'today\'s purchases',
                'purchases today',
                'purchase today',
                'purchase summary',
                'purchase summary today',
                'recent purchases',
                'recent purchase',
                'purchases this month',
                'purchases this week',
                'purchases',
            ],
            'es' => [
                'compras de hoy',
                'compras hoy',
                'resumen de compras',
                'resumen de compras de hoy',
                'mostrar compras de hoy',
                'compras del dia',
            ],
            'ar' => [
                'مشتريات اليوم',
                'ملخص المشتريات',
                'ملخص مشتريات اليوم',
                'عرض مشتريات اليوم',
                'مشتريات هذا اليوم',
            ],
        ],
        'low_stock' => [
            'en' => [
                'show low stock products',
                'which products are low in stock',
                'low stock',
                'stock alerts',
                'low stock alert',
                'low stock alerts',
                'stock alert',
                'low inventory',
            ],
            'es' => [
                'bajo stock',
                'stock bajo',
                'alerta de stock',
                'alertas de stock',
                'productos con bajo stock',
                'mostrar productos con bajo stock',
                'alerta de existencias',
            ],
            'ar' => [
                'نقص المخزون',
                'تنبيهات المخزون',
                'تنبيه المخزون',
                'منتجات منخفضة المخزون',
                'مخزون منخفض',
                'عرض المنتجات منخفضة المخزون',
            ],
        ],
        'top_products' => [
            'en' => [
                'show top selling products',
                'top selling products',
                'top selling products today',
                'top selling products this month',
                'best selling products today',
                'top products',
                'top products today',
                'top products this month',
                'top 5 products',
                'top 10 products',
                'what sold the most today',
                'best sellers',
                'top selling',
            ],
            'es' => [
                'productos mas vendidos',
                'productos mas vendidos hoy',
                'mejores productos',
                'mas vendidos hoy',
                'lo mas vendido hoy',
                'productos top',
            ],
            'ar' => [
                'المنتجات الاكثر مبيعا',
                'المنتجات الاكثر مبيعا اليوم',
                'افضل المنتجات مبيعا',
                'اكثر المنتجات مبيعا',
                'المنتجات الاعلى مبيعا',
            ],
        ],
        'customer_due' => [
            'en' => [
                'customer due summary',
                'show customer dues',
                'which customers owe money',
                'outstanding customer balances',
                'customer dues',
                'customer debt',
                'customer due',
            ],
            'es' => [
                'deudas de clientes',
                'deuda de clientes',
                'resumen de deudas de clientes',
                'saldos pendientes de clientes',
                'clientes que deben dinero',
                'cuentas por cobrar',
            ],
            'ar' => [
                'مستحقات العملاء',
                'ديون العملاء',
                'ملخص ديون العملاء',
                'مستحقات الزبائن',
                'العملاء المدينون',
                'ارصدة العملاء المستحقة',
            ],
        ],
        'supplier_due' => [
            'en' => [
                'supplier due summary',
                'show supplier dues',
                'which suppliers do we owe',
                'outstanding supplier balances',
                'accounts payable summary',
                'supplier due',
                'supplier dues',
                'supplier debt',
            ],
            'es' => [
                'deudas de proveedores',
                'deuda de proveedores',
                'resumen de deudas de proveedores',
                'saldos pendientes de proveedores',
                'proveedores a los que debemos',
                'cuentas por pagar',
            ],
            'ar' => [
                'مستحقات الموردين',
                'ديون الموردين',
                'ملخص ديون الموردين',
                'الموردون الدائنون',
                'ارصدة الموردين المستحقة',
                'حسابات الموردين المستحقة',
            ],
        ],
        'cash_bank_summary' => [
            'en' => [
                'cash and bank summary',
                'cash bank summary',
                'show account balances',
                'how much cash do we have',
                'bank balances',
                'cash balance',
                'account balances',
            ],
            'es' => [
                'resumen de caja y banco',
                'resumen de caja y bancos',
                'saldos de cuentas',
                'saldo de caja',
                'cuanto dinero tenemos',
                'saldos bancarios',
            ],
            'ar' => [
                'ملخص النقد والبنوك',
                'ملخص النقدية والبنك',
                'ارصدة الحسابات',
                'رصيد الخزينة والبنك',
                'كم لدينا من النقد',
                'ارصدة البنوك',
            ],
        ],
        'expense_summary' => [
            'en' => [
                'todays expense summary',
                'todays expenses',
                'today expense summary',
                'expense summary',
                'show todays expenses',
                'show today expenses',
                'how much did we spend today',
                'expenses today',
                'expenses',
            ],
            'es' => [
                'gastos de hoy',
                'resumen de gastos',
                'resumen de gastos de hoy',
                'mostrar gastos de hoy',
                'cuanto gastamos hoy',
                'gastos del dia',
            ],
            'ar' => [
                'مصروفات اليوم',
                'نفقات اليوم',
                'ملخص المصروفات',
                'ملخص مصروفات اليوم',
                'عرض مصروفات اليوم',
                'كم انفقنا اليوم',
            ],
        ],
        'daily_snapshot' => [
            'en' => [
                'daily business snapshot',
                'todays business summary',
                'today business summary',
                'show todays snapshot',
                'show today snapshot',
                'how is business today',
                'daily snapshot',
                'todays snapshot',
                'business snapshot',
                'business summary',
                'salama business summary',
            ],
            'es' => [
                'resumen diario del negocio',
                'resumen del negocio de hoy',
                'instantanea diaria',
                'como va el negocio hoy',
                'resumen diario',
            ],
            'ar' => [
                'نظرة عامة يومية',
                'ملخص الاعمال اليومي',
                'نظرة عامة على اعمال اليوم',
                'كيف يسير العمل اليوم',
                'ملخص اليوم',
            ],
        ],
        'slow_moving_products' => [
            'en' => [
                'show slow moving products',
                'slow moving products',
                'products not selling',
                'products with low sales',
                'dead stock',
                'stagnant products',
            ],
            'es' => [
                'productos de lento movimiento',
                'productos que no se venden',
                'stock estancado',
                'productos lentos',
                'productos sin ventas',
            ],
            'ar' => [
                'منتجات بطيئة الحركة',
                'المنتجات الراكدة',
                'منتجات لا تباع',
                'مخزون راكد',
                'منتجات بطيئة البيع',
            ],
        ],
        'financial_pnl' => [
            'en' => [
                'financial pnl',
                'show profit and loss',
                'profit and loss',
                'p&l summary',
                'pnl summary',
                'income statement',
                'financial profit',
                'gl net profit',
            ],
            'es' => [
                'ganancias y perdidas',
                'estado de resultados',
                'p&l',
            ],
            'ar' => [
                'الارباح والخسائر',
                'قائمة الدخل',
            ],
        ],
    ];

    /**
     * Map of direct intent codes (e.g. data-intent passed from deterministic UI buttons).
     *
     * @var array<string, string>
     */
    private static array $directIntentMap = [
        'sales_summary'         => 'sales_summary',
        'sales_today'           => 'sales_summary',
        'purchase_summary'      => 'purchase_summary',
        'purchases_today'       => 'purchase_summary',
        'low_stock'             => 'low_stock',
        'low_stock_alerts'      => 'low_stock',
        'top_products'          => 'top_products',
        'top_selling_products'  => 'top_products',
        'customer_due'          => 'customer_due',
        'customer_dues'         => 'customer_due',
        'supplier_due'          => 'supplier_due',
        'supplier_dues'         => 'supplier_due',
        'cash_bank_summary'     => 'cash_bank_summary',
        'cash_bank'             => 'cash_bank_summary',
        'cash_bank_balances'    => 'cash_bank_summary',
        'expense_summary'       => 'expense_summary',
        'expenses_today'        => 'expense_summary',
        'todays_expenses'       => 'expense_summary',
        'daily_snapshot'        => 'daily_snapshot',
        'todays_business_summary' => 'daily_snapshot',
        'slow_moving_products'  => 'slow_moving_products',
        'slow_products'         => 'slow_moving_products',
        'financial_pnl'         => 'financial_pnl',
        'profit_loss'           => 'financial_pnl',
        'profit_and_loss'       => 'financial_pnl',
        'pnl'                   => 'financial_pnl',
        'today_attendance'      => 'today_attendance',
        'headcount_summary'     => 'headcount_summary',
        'payroll_summary'       => 'payroll_summary',
        'table_occupancy'       => 'table_occupancy',
        'open_table_orders'     => 'open_table_orders',
        'quotations_summary'    => 'quotations_summary',
        'sales_agent_performance' => 'sales_agent_performance',
        'batch_expiry_alerts'   => 'batch_expiry_alerts',
        'pending_purchase_orders' => 'pending_arrivals',
        'pending_arrivals'      => 'pending_arrivals',
        'landed_cost_overview'  => 'landed_cost_summary',
        'landed_cost_summary'   => 'landed_cost_summary',
    ];

    /**
     * Resolves the canonical skill key for a normalized or raw prompt.
     *
     * Resolution order:
     * 1. Direct intent identifier (e.g. "intent:sales_today" or "sales_summary").
     * 2. Localized aliases for all registered language vocabularies.
     * 3. Specialist routing prefixes (e.g. "ask jason today sales", "abdul low stock").
     *
     * @param string $prompt
     * @return string|null Canonical skill key or null if unmatched.
     */
    public static function resolve(string $prompt): ?string
    {
        $raw = trim($prompt);
        if ($raw === '') {
            return null;
        }

        // Check if prompt is a direct intent identifier (e.g., "intent:sales_today")
        if (str_starts_with($raw, 'intent:')) {
            $intentCode = substr($raw, 7);
            return self::$directIntentMap[$intentCode] ?? null;
        }

        if (isset(self::$directIntentMap[$raw])) {
            return self::$directIntentMap[$raw];
        }

        $normalized = PromptNormalizer::normalize($raw);
        if ($normalized === '') {
            return null;
        }

        foreach (self::$intentAliases as $skillKey => $localeMap) {
            foreach ($localeMap as $locale => $phrases) {
                foreach ($phrases as $phrase) {
                    if ($normalized === PromptNormalizer::normalize($phrase)) {
                        return $skillKey;
                    }
                }
            }
        }

        // Specialist prefix routing (supports configurable display names and stable domain keys)
        $specialistPrefixes = self::getSpecialistPrefixes();

        foreach ($specialistPrefixes as $prefix) {
            if (str_starts_with($normalized, $prefix)) {
                $stripped = trim(substr($normalized, strlen($prefix)));
                if ($stripped !== '') {
                    foreach (self::$intentAliases as $skillKey => $localeMap) {
                        foreach ($localeMap as $locale => $phrases) {
                            foreach ($phrases as $phrase) {
                                if ($stripped === PromptNormalizer::normalize($phrase)) {
                                    return $skillKey;
                                }
                            }
                        }
                    }
                }
            }
        }

        return null;
    }

    /**
     * Resolves specialist prefixes dynamically from configured specialist display names and stable role keys.
     *
     * @return string[]
     */
    public static function getSpecialistPrefixes(): array
    {
        $prefixes = [
            'ask business ', 'business ',
            'ask sales ', 'sales ',
            'ask supply ', 'supply ',
            'ask finance ', 'finance ',
            'ask people ', 'people ',
            'ask restaurant ', 'restaurant ',
            'ask management ', 'management ',
            'ask inventory ', 'inventory ',
        ];

        $specialistDefaults = [
            'business' => 'Salama',
            'sales' => 'Jason',
            'supply' => 'Abdul',
            'finance' => 'Nora',
            'people' => 'Maya',
            'restaurant' => 'Rami',
        ];

        foreach ($specialistDefaults as $key => $defaultName) {
            try {
                $configured = function_exists('config') ? config("aiassistant.specialists.{$key}.name", $defaultName) : $defaultName;
            } catch (\Throwable) {
                $configured = $defaultName;
            }
            $configuredLower = strtolower(trim((string) $configured));
            if ($configuredLower !== '') {
                $prefixes[] = "ask {$configuredLower} ";
                $prefixes[] = "{$configuredLower} ";
            }
            $defaultLower = strtolower($defaultName);
            if ($defaultLower !== $configuredLower) {
                $prefixes[] = "ask {$defaultLower} ";
                $prefixes[] = "{$defaultLower} ";
            }
        }

        usort($prefixes, fn($a, $b) => strlen($b) <=> strlen($a));

        return array_values(array_unique($prefixes));
    }

    /**
     * Checks whether a given skill key matches the user message prompt.
     */
    public static function matchesSkill(string $skillKey, string $prompt): bool
    {
        return self::resolve($prompt) === $skillKey;
    }
}
