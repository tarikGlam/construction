<?php

namespace Modules\AIAssistant\Tests\Unit;

use Tests\TestCase;
use App\Models\User;
use Modules\AIAssistant\DTO\SkillAvailabilityResult;
use Modules\AIAssistant\DTO\SkillDefinition;
use Modules\AIAssistant\Security\AssistantAccessContext;
use Modules\AIAssistant\Services\SkillRegistry;
use Modules\AIAssistant\Skills\SalesSummarySkill;
use Modules\AIAssistant\Specialists\SpecialistRegistry;
use Spatie\Permission\Models\Role;

class SkillRegistryUpgradeTest extends TestCase
{
    public function test_legacy_skill_registration_auto_wraps_in_definition(): void
    {
        $registry = new SkillRegistry();
        $legacy = new SalesSummarySkill();
        $registry->register($legacy);

        $this->assertSame($legacy, $registry->get('sales_summary'));
        $def = $registry->getDefinition('sales_summary');
        $this->assertNotNull($def);
        $this->assertEquals('sales_summary', $def->skillId);
        $this->assertEquals('sales', $def->specialty);
        $this->assertContains('sales-index', $def->requiredPermissions);
    }

    public function test_explicit_skill_definition_registration(): void
    {
        $registry = new SkillRegistry();
        $def = new SkillDefinition(
            skillId: 'inventory.stock',
            specialty: 'supply',
            description: 'Check stock levels',
            requiredPermissions: ['products-index'],
            warehousePolicy: 'optional'
        );

        $registry->registerDefinition($def);

        $this->assertSame($def, $registry->getDefinition('inventory.stock'));
        $supplySkills = $registry->bySpecialty('supply');
        $this->assertCount(1, $supplySkills);
        $this->assertEquals('inventory.stock', $supplySkills[0]->skillId);
    }

    public function test_availability_check_permits_admin(): void
    {
        $registry = new SkillRegistry();
        $def = new SkillDefinition(
            skillId: 'sales.confidential',
            specialty: 'sales',
            requiredPermissions: ['sales-index']
        );
        $registry->registerDefinition($def);

        $admin = new User(['id' => 1, 'role_id' => 1, 'is_active' => true]);
        $context = AssistantAccessContext::fromUser($admin);

        $result = $registry->checkAvailability('sales.confidential', $context);
        $this->assertTrue($result->isAvailable);
        $this->assertEquals(SkillAvailabilityResult::AVAILABLE, $result->state);
    }

    public function test_availability_check_denies_lacking_permission(): void
    {
        $registry = new SkillRegistry();
        $def = new SkillDefinition(
            skillId: 'purchases.restricted',
            specialty: 'supply',
            requiredPermissions: ['purchases-index']
        );
        $registry->registerDefinition($def);

        $role = Role::create(['name' => 'RestrictedNoPurchases_' . uniqid(), 'guard_name' => 'web', 'is_active' => true]);
        $user = User::create([
            'name' => 'NoPurchases',
            'email' => 'nopurchases_' . uniqid() . '@example.test',
            'phone' => '1234567890',
            'password' => bcrypt('password'),
            'role_id' => $role->id,
            'is_active' => true,
            'is_deleted' => false,
        ]);
        $user->syncRoles([$role->name]);

        $context = AssistantAccessContext::fromUser($user);

        $result = $registry->checkAvailability('purchases.restricted', $context);
        $this->assertFalse($result->isAvailable);
        $this->assertEquals(SkillAvailabilityResult::PERMISSION_DENIED, $result->state);
    }

    public function test_availability_check_denies_disabled_module(): void
    {
        $registry = new SkillRegistry();
        $def = new SkillDefinition(
            skillId: 'ecommerce.orders',
            specialty: 'sales',
            moduleRequirement: 'ecommerce'
        );
        $registry->registerDefinition($def);

        $admin = new User(['id' => 1, 'role_id' => 1, 'is_active' => true]);
        $context = AssistantAccessContext::fromUser($admin);

        $result = $registry->checkAvailability('ecommerce.orders', $context);
        $this->assertFalse($result->isAvailable);
        $this->assertEquals(SkillAvailabilityResult::MODULE_DISABLED, $result->state);
    }

    public function test_specialist_registry_contains_six_specialists(): void
    {
        $specialists = new SpecialistRegistry();

        $this->assertEquals('Salama', $specialists->get('business')->displayName);
        $this->assertEquals('Jason', $specialists->get('sales')->displayName);
        $this->assertEquals('Abdul', $specialists->get('supply')->displayName);
        $this->assertEquals('Nora', $specialists->get('finance')->displayName);
        $this->assertEquals('Maya', $specialists->get('people')->displayName);
        $this->assertEquals('Rami', $specialists->get('restaurant')->displayName);

        $this->assertEquals('business', $specialists->getDefault()->key);
        $this->assertCount(6, $specialists->all());
    }
}
