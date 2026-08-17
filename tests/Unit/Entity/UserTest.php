<?php

declare(strict_types=1);

namespace App\Tests\Unit\Entity;

use App\Entity\User;
use PHPUnit\Framework\TestCase;

class UserTest extends TestCase
{
    public function testIsActiveByDefault(): void
    {
        $user = new User();

        $this->assertTrue($user->isActive());
        $this->assertNull($user->getDeactivatedAt());
    }

    public function testDeactivateSetsDeactivatedAtAndFlipsIsActive(): void
    {
        $user = new User();

        $user->deactivate();

        $this->assertFalse($user->isActive());
        $this->assertNotNull($user->getDeactivatedAt());
    }

    public function testReactivateClearsDeactivatedAt(): void
    {
        $user = new User();

        $user->deactivate();
        $user->reactivate();

        $this->assertTrue($user->isActive());
        $this->assertNull($user->getDeactivatedAt());
    }
}
