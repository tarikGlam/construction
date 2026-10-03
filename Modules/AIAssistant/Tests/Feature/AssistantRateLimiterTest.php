<?php

namespace Modules\AIAssistant\Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\RateLimiter;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AssistantRateLimiterTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
        Permission::firstOrCreate(['name' => 'ai-assistant-index', 'guard_name' => 'web']);
        RateLimiter::clear('ai-assistant');
    }

    private function createAuthorizedUser(): User
    {
        $role = Role::firstOrCreate(['name' => 'TestStaff_' . uniqid(), 'guard_name' => 'web'], ['is_active' => true]);
        $role->givePermissionTo('ai-assistant-index');

        $user = User::create([
            'name' => 'Staff User',
            'email' => 'staff_' . uniqid() . '@example.com',
            'phone' => '123456789',
            'password' => bcrypt('secret123'),
            'role_id' => $role->id,
            'is_active' => true,
            'is_deleted' => false,
        ]);

        $user->assignRole($role);
        return $user;
    }

    public function test_assistant_endpoints_are_throttled_when_limit_is_exceeded(): void
    {
        // Set a small limit of 3 for testing
        Config::set('aiassistant.rate_limit', 3);

        $user = $this->createAuthorizedUser();

        // First 3 requests should not be throttled (HTTP 200 or whatever status, not 429)
        for ($i = 0; $i < 3; $i++) {
            $response = $this->actingAs($user)->getJson(route('ai-assistant.questions.index'));
            $this->assertNotSame(429, $response->getStatusCode(), "Request {$i} should not be throttled.");
        }

        // 4th request must be throttled with 429 Too Many Requests
        $throttledResponse = $this->actingAs($user)->getJson(route('ai-assistant.questions.index'));
        $throttledResponse->assertStatus(429);
    }

    public function test_tenant_a_and_tenant_b_have_independent_rate_limit_buckets(): void
    {
        Config::set('aiassistant.rate_limit', 2);
        RateLimiter::clear('ai-assistant');

        $user = $this->createAuthorizedUser();

        // 1. Simulate Tenant A context
        app()->instance('tenant', (object)['id' => 'tenant_alpha']);

        // Tenant A consumes quota
        $r1 = $this->actingAs($user)->getJson(route('ai-assistant.questions.index'));
        $this->assertNotSame(429, $r1->getStatusCode());

        $r2 = $this->actingAs($user)->getJson(route('ai-assistant.questions.index'));
        $this->assertNotSame(429, $r2->getStatusCode());

        // Tenant A request 3 is throttled
        $r3 = $this->actingAs($user)->getJson(route('ai-assistant.questions.index'));
        $r3->assertStatus(429);

        // 2. Switch to Tenant B context with the SAME user
        app()->instance('tenant', (object)['id' => 'tenant_beta']);

        // Tenant B must have independent bucket and NOT be throttled
        $rBeta1 = $this->actingAs($user)->getJson(route('ai-assistant.questions.index'));
        $this->assertNotSame(429, $rBeta1->getStatusCode(), 'Tenant B should not be affected by Tenant A rate limit.');

        $rBeta2 = $this->actingAs($user)->getJson(route('ai-assistant.questions.index'));
        $this->assertNotSame(429, $rBeta2->getStatusCode());

        // Tenant B request 3 is throttled
        $rBeta3 = $this->actingAs($user)->getJson(route('ai-assistant.questions.index'));
        $rBeta3->assertStatus(429);
    }

    public function test_client_supplied_tenant_id_spoofing_does_not_affect_rate_limit_bucket(): void
    {
        Config::set('aiassistant.rate_limit', 2);
        RateLimiter::clear('ai-assistant');

        $user = $this->createAuthorizedUser();

        // Initialize Tenant Alpha context
        app()->instance('tenant', (object)['id' => 'tenant_alpha']);

        // Request 1 with spoofed client header/param
        $r1 = $this->actingAs($user)->getJson(route('ai-assistant.questions.index') . '?tenant_id=fake_tenant', [
            'X-Tenant-Id' => 'fake_header_tenant',
        ]);
        $this->assertNotSame(429, $r1->getStatusCode());

        // Request 2 with another spoofed param
        $r2 = $this->actingAs($user)->getJson(route('ai-assistant.questions.index') . '?tenant_id=another_fake', [
            'X-Tenant-Id' => 'another_fake',
        ]);
        $this->assertNotSame(429, $r2->getStatusCode());

        // Request 3: even with yet another spoofed tenant ID, it must still be throttled because server-side Tenant Alpha quota is exhausted
        $r3 = $this->actingAs($user)->getJson(route('ai-assistant.questions.index') . '?tenant_id=bypass_attempt', [
            'X-Tenant-Id' => 'bypass_attempt',
        ]);
        $r3->assertStatus(429);
    }
}
