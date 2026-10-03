<?php

namespace Tests\Unit;

use App\Http\Middleware\ConstructionEdition;
use Illuminate\Http\Request;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\TestCase;

class ConstructionEditionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config()->set('app.vertical', 'construction');
    }

    public static function blockedPathProvider(): array
    {
        return array_map(
            static fn (string $path): array => [$path],
            [
                '/pos', '/sales', '/return-sale', '/exchange', '/cash-register',
                '/biller', '/sale-agents', '/gift_cards', '/coupons', '/discounts',
                '/discount-plans', '/bookings', '/delivery', '/couriers',
                '/packing-slips', '/challans', '/menu/demo', '/tables',
                '/setting/pos_setting', '/setting/reward-point-setting',
                '/api/sales', '/api/pos', '/api/gift-cards', '/api/discounts',
            ]
        );
    }

    #[DataProvider('blockedPathProvider')]
    public function test_retail_path_is_not_available_in_construction_edition(string $path): void
    {
        $this->expectException(NotFoundHttpException::class);

        (new ConstructionEdition())->handle(Request::create($path), static fn () => response('allowed'));
    }

    public function test_construction_path_remains_available(): void
    {
        $response = (new ConstructionEdition())->handle(
            Request::create('/construction/dashboard'),
            static fn () => response('allowed')
        );

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('allowed', $response->getContent());
    }
}
