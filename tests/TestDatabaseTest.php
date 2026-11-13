<?php

declare(strict_types=1);

namespace App\Tests;

use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Garde-fou : les tests ne doivent jamais viser la base Supabase.
 */
final class TestDatabaseTest extends KernelTestCase
{
    public function testTestsRunAgainstLocalTestDatabase(): void
    {
        $connection = self::getContainer()->get(Connection::class);
        $params = $connection->getParams();

        self::assertSame('app_test', $connection->getDatabase());
        self::assertSame('127.0.0.1', $params['host'] ?? null);
    }
}
