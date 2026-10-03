<?php

namespace Modules\AIAssistant\Specialists;

class SpecialistRegistry
{
    /**
     * @var array<string, SpecialistDefinition>
     */
    private array $specialists = [];

    public function __construct()
    {
        $this->registerDefaults();
    }

    private function registerDefaults(): void
    {
        $salama = config('aiassistant.specialists.business.name', 'Salama');
        $this->register(new SpecialistDefinition(
            key: 'business',
            displayName: $salama,
            role: 'Business Manager',
            avatar: 'dripicons-briefcase',
            welcomeMessage: "Hello, I am {$salama}. I can give you an executive overview of your overall business, performance, and key alerts.",
            enabled: true,
            responsibilities: ['overall business', 'dashboard', 'cross-module summaries', 'alerts', 'comparisons']
        ));

        $jason = config('aiassistant.specialists.sales.name', 'Jason');
        $this->register(new SpecialistDefinition(
            key: 'sales',
            displayName: $jason,
            role: 'Sales Specialist',
            avatar: 'dripicons-cart',
            welcomeMessage: "Hi, I am {$jason}. I specialize in sales, quotations, customers, returns, deliveries, and sales agent performance.",
            enabled: true,
            responsibilities: ['sales', 'POS', 'customers', 'quotations', 'returns', 'exchanges', 'deliveries', 'sale agents']
        ));

        $abdul = config('aiassistant.specialists.supply.name', 'Abdul');
        $this->register(new SpecialistDefinition(
            key: 'supply',
            displayName: $abdul,
            role: 'Supply & Inventory',
            avatar: 'dripicons-box',
            welcomeMessage: "Greetings, I am {$abdul}. I handle inventory, stock alerts, purchases, suppliers, transfers, manufacturing, and landed cost batches.",
            enabled: true,
            responsibilities: ['products', 'inventory', 'purchases', 'suppliers', 'transfers', 'batches', 'manufacturing', 'landed cost/import batches']
        ));

        $nora = config('aiassistant.specialists.finance.name', 'Nora');
        $this->register(new SpecialistDefinition(
            key: 'finance',
            displayName: $nora,
            role: 'Finance & Accounting',
            avatar: 'dripicons-wallet',
            welcomeMessage: "Hello, I am {$nora}. I manage financial reports, accounts, receivables, payables, income, expenses, and profitability.",
            enabled: true,
            responsibilities: ['accounting', 'receivables', 'payables', 'income', 'expenses', 'payments', 'profitability']
        ));

        $maya = config('aiassistant.specialists.people.name', 'Maya');
        $this->register(new SpecialistDefinition(
            key: 'people',
            displayName: $maya,
            role: 'HR & People',
            avatar: 'dripicons-user-group',
            welcomeMessage: "Hi, I am {$maya}. I assist with human resources, employee attendance, leaves, overtime, and payroll summaries.",
            enabled: true,
            responsibilities: ['employees', 'attendance', 'leave', 'overtime', 'payroll']
        ));

        $rami = config('aiassistant.specialists.restaurant.name', 'Rami');
        $this->register(new SpecialistDefinition(
            key: 'restaurant',
            displayName: $rami,
            role: 'Restaurant & Operations',
            avatar: 'dripicons-store',
            welcomeMessage: "Welcome, I am {$rami}. I can assist with restaurant dining, active tables, kitchen orders, service types, and reservations.",
            enabled: true,
            responsibilities: ['restaurant orders', 'tables', 'kitchen/KDS', 'service types', 'reservations']
        ));
    }

    public function register(SpecialistDefinition $specialist): void
    {
        $this->specialists[$specialist->key] = $specialist;
    }

    public function get(string $key): ?SpecialistDefinition
    {
        return $this->specialists[$key] ?? null;
    }

    public function getDefault(): SpecialistDefinition
    {
        return $this->specialists['business'];
    }

    public function getForSkill(?string $skillKey): SpecialistDefinition
    {
        $domain = match ($skillKey) {
            'sales_summary', 'top_products', 'customer_due' => 'sales',
            'purchase_summary', 'low_stock', 'slow_moving_products', 'supplier_due' => 'supply',
            'cash_bank_summary', 'expense_summary', 'financial_pnl' => 'finance',
            default => 'business',
        };

        return $this->get($domain) ?? $this->getDefault();
    }

    /**
     * @return SpecialistDefinition[]
     */
    public function all(): array
    {
        return array_values($this->specialists);
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function toArray(): array
    {
        return array_map(fn (SpecialistDefinition $s) => $s->toArray(), $this->specialists);
    }
}
