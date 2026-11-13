<?php

declare(strict_types=1);

namespace App\Tests\Doctrine;

use Doctrine\DBAL\Schema\ComparatorConfig;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Un schéma PostgreSQL étranger à l'application (comme auth ou storage chez Supabase)
 * ne doit pas apparaître dans les migrations générées.
 *
 * Le CREATE SCHEMA est annulé en fin de test par DAMA (DDL transactionnel sous PostgreSQL).
 *
 * Non couvert : un schéma sur lequel le rôle de connexion n'a aucun droit (il faudrait un
 * second rôle PostgreSQL). Le listener lit pour cela la même source que DBAL, listSchemaNames().
 */
final class SupabaseSchemasListenerTest extends KernelTestCase
{
    public function testForeignSchemasAreIgnoredByTheSchemaDiff(): void
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $connection = $entityManager->getConnection();
        $connection->executeStatement('CREATE SCHEMA supabase_like');

        $schemaManager = $connection->createSchemaManager();
        $databaseSchema = $schemaManager->introspectSchema();
        $mappingSchema = new SchemaTool($entityManager)->getSchemaFromMetadata(
            $entityManager->getMetadataFactory()->getAllMetadata(),
        );

        self::assertTrue($mappingSchema->hasNamespace('supabase_like'));
        // public est déclaré par doctrine:migrations:diff lui-même : le déclarer ici le ferait planter.
        self::assertFalse($mappingSchema->hasNamespace('public'));

        // Mêmes comparaisons que doctrine:migrations:diff (DiffGenerator) pour up() et down().
        $comparator = $schemaManager->createComparator(new ComparatorConfig()->withReportModifiedIndexes(false));
        $platform = $connection->getDatabasePlatform();
        $up = $platform->getAlterSchemaSQL($comparator->compareSchemas($databaseSchema, $mappingSchema));
        $down = $platform->getAlterSchemaSQL($comparator->compareSchemas($mappingSchema, $databaseSchema));

        // Diff entièrement vide : détecte aussi une régression du schema_filter.
        self::assertSame([], $this->withoutDiffGeneratorAdjustments($up));
        self::assertSame([], $this->withoutDiffGeneratorAdjustments($down));
    }

    /**
     * Retire ce que doctrine:migrations:diff corrige lui-même avant de comparer :
     * il exclut sa table doctrine_migration_versions (absente du mapping) et déclare
     * le schéma public dans le schéma issu des entités.
     *
     * @param list<string> $sql
     *
     * @return list<string>
     */
    private function withoutDiffGeneratorAdjustments(array $sql): array
    {
        return array_values(array_filter(
            $sql,
            static fn (string $statement): bool => 'CREATE SCHEMA public' !== $statement
                && !str_contains($statement, 'doctrine_migration_versions'),
        ));
    }
}
