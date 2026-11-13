<?php

declare(strict_types=1);

namespace App\Tests\Doctrine;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Un schéma PostgreSQL étranger à l'application (comme auth ou storage chez Supabase)
 * ne doit pas apparaître dans les migrations générées.
 *
 * Le CREATE SCHEMA est annulé en fin de test par DAMA (DDL transactionnel sous PostgreSQL).
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

        // Mêmes comparaisons que doctrine:migrations:diff pour up() et down().
        $comparator = $schemaManager->createComparator();
        $platform = $connection->getDatabasePlatform();
        $up = $platform->getAlterSchemaSQL($comparator->compareSchemas($databaseSchema, $mappingSchema));
        $down = $platform->getAlterSchemaSQL($comparator->compareSchemas($mappingSchema, $databaseSchema));

        self::assertSame([], $this->statementsAbout('supabase_like', $up));
        self::assertSame([], $this->statementsAbout('supabase_like', $down));
    }

    /**
     * @param list<string> $sql
     *
     * @return list<string>
     */
    private function statementsAbout(string $schemaName, array $sql): array
    {
        return array_values(array_filter($sql, static fn (string $statement): bool => str_contains($statement, $schemaName)));
    }
}
