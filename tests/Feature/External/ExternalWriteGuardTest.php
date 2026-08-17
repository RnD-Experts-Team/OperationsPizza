<?php

namespace Tests\Feature\External;

use App\Services\External\ExternalWriteGuard;
use App\Services\External\StoreNotAllowlistedException;
use Tests\TestCase;

class ExternalWriteGuardTest extends TestCase
{
    public function test_unset_means_unrestricted(): void
    {
        config(['external.allowed_stores' => null]);

        $guard = new ExternalWriteGuard();

        $this->assertFalse($guard->isRestricted());
        $this->assertTrue($guard->allows('03795-00001'));
        $guard->assertAllowed('03795-00001'); // must not throw
    }

    public function test_a_listed_store_is_allowed_and_others_are_blocked(): void
    {
        config(['external.allowed_stores' => '03795-99999, 03795-00001']);

        $guard = new ExternalWriteGuard();

        $this->assertTrue($guard->isRestricted());
        $this->assertTrue($guard->allows('03795-99999'));
        $this->assertTrue($guard->allows('03795-00001'));
        $this->assertFalse($guard->allows('03795-00002'));

        $this->expectException(StoreNotAllowlistedException::class);
        $guard->assertAllowed('03795-00002');
    }

    public function test_the_exception_names_the_store_and_the_variable(): void
    {
        config(['external.allowed_stores' => '03795-99999']);

        try {
            (new ExternalWriteGuard())->assertAllowed('03795-00007');
            $this->fail('Expected StoreNotAllowlistedException.');
        } catch (StoreNotAllowlistedException $e) {
            $this->assertSame('03795-00007', $e->storeNumber);
            $this->assertStringContainsString('EXTERNAL_WRITE_ALLOWED_STORES', $e->getMessage());
        }
    }
}
