<?php

declare(strict_types=1);

namespace App\Doctrine;

use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Tools\Event\GenerateSchemaEventArgs;
use Doctrine\ORM\Tools\ToolEvents;

/**
 * Déclare dans le schéma généré depuis les entités les schémas PostgreSQL gérés
 * par Supabase (auth, storage, realtime…).
 *
 * Le schema_filter de doctrine.yaml exclut leurs tables, mais DBAL ne l'applique
 * pas aux noms de schémas : sans ce listener, chaque doctrine:migrations:diff
 * produirait un down() qui recrée ces schémas, et une migration même sans changement.
 *
 * Priorité basse : les listeners de Symfony (Messenger, Lock, Cache) reconstruisent le
 * schéma via Schema::edit() puis setSchema(), ce qui efface les schémas déclarés avant eux.
 */
#[AsDoctrineListener(event: ToolEvents::postGenerateSchema, priority: -1024)]
final class SupabaseSchemasListener
{
    public function postGenerateSchema(GenerateSchemaEventArgs $event): void
    {
        $schema = $event->getSchema();
        $schemaManager = $event->getEntityManager()->getConnection()->createSchemaManager();

        foreach ($schemaManager->introspectSchemaNames() as $name) {
            // getValue() et non toString(), qui renvoie l'identifiant entre guillemets.
            $namespace = $name->getIdentifier()->getValue();

            if ('public' !== $namespace && !$schema->hasNamespace($namespace)) {
                // Seule API DBAL 4.5 pour déclarer un schéma sans table (dépréciée, sans remplaçant).
                $schema->createNamespace($namespace);
            }
        }
    }
}
