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
 *
 * Limite : les commandes qui génèrent tout le schéma à partir de rien
 * (doctrine:schema:create, doctrine:migrations:diff --from-empty-schema) émettent un
 * CREATE SCHEMA pour chaque schéma déclaré ici. Sur Supabase, retirer ces lignes à la main.
 */
#[AsDoctrineListener(event: ToolEvents::postGenerateSchema, priority: -1024)]
final class SupabaseSchemasListener
{
    public function postGenerateSchema(GenerateSchemaEventArgs $event): void
    {
        $schema = $event->getSchema();
        $schemaManager = $event->getEntityManager()->getConnection()->createSchemaManager();

        // Même source que AbstractSchemaManager::introspectSchema(), qui construit le schéma
        // de la base comparé par migrations:diff : information_schema.schemata, limitée aux
        // schémas sur lesquels le rôle a des droits. introspectSchemaNames() lit pg_namespace
        // sans tenir compte des droits et déclarerait des schémas absents côté base.
        foreach ($schemaManager->listSchemaNames() as $namespace) {
            if ('public' !== $namespace && !$schema->hasNamespace($namespace)) {
                // Seule API DBAL 4.5 pour déclarer un schéma sans table (dépréciée, sans remplaçant).
                $schema->createNamespace($namespace);
            }
        }
    }
}
