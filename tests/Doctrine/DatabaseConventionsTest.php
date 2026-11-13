<?php

declare(strict_types=1);

namespace App\Tests\Doctrine;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Garde-fous sur les règles de base de données de CLAUDE.md, vérifiés sur la base
 * de test migrée : ils couvrent toutes les migrations futures.
 */
final class DatabaseConventionsTest extends KernelTestCase
{
    /**
     * Sans RLS, une table de public est lisible et modifiable par l'API PostgREST de Supabase.
     */
    public function testEveryPublicTableHasRowLevelSecurity(): void
    {
        $connection = self::getContainer()->get(Connection::class);

        $tablesWithoutRls = $connection->fetchFirstColumn(<<<'SQL'
            SELECT c.relname
            FROM pg_class c
            JOIN pg_namespace n ON n.oid = c.relnamespace
            WHERE n.nspname = 'public'
              AND c.relkind IN ('r', 'p')
              AND NOT c.relrowsecurity
            ORDER BY c.relname
            SQL);

        self::assertSame([], $tablesWithoutRls, 'Ajouter « ALTER TABLE … ENABLE ROW LEVEL SECURITY » dans la migration qui crée ces tables.');
    }

    /**
     * Le schema_filter de doctrine.yaml ignore les tables hors de public : migrations:diff
     * ne les créerait jamais.
     */
    public function testNoEntityIsMappedOutsidePublicSchema(): void
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);

        $entitiesOutsidePublic = [];
        foreach ($entityManager->getMetadataFactory()->getAllMetadata() as $metadata) {
            if (null !== $metadata->getSchemaName() || str_contains($metadata->getTableName(), '.')) {
                $entitiesOutsidePublic[] = $metadata->getName();
            }
        }

        self::assertSame([], $entitiesOutsidePublic, 'Ces entités sont mappées hors du schéma public, voir schema_filter dans config/packages/doctrine.yaml.');
    }
}
