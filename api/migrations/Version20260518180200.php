<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260518180200 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Rename reserved-word schema "user" → users to avoid PostgreSQL quoting issues';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE SCHEMA IF NOT EXISTS users');

        // Drop FKs referencing "user"."user" before moving
        $this->addSql('ALTER TABLE telegram.account DROP CONSTRAINT FK_468AA65CA76ED395');
        $this->addSql('ALTER TABLE monitoring.config DROP CONSTRAINT FK_8DABDC8A896DBBDE');
        $this->addSql('ALTER TABLE document_center.watch DROP CONSTRAINT FK_826D7326A76ED395');

        // Move tables to new schema
        $this->addSql('ALTER TABLE "user"."user" SET SCHEMA users');
        $this->addSql('ALTER TABLE "user".refresh_token SET SCHEMA users');

        // Rename indexes to match new Doctrine-generated names
        $this->addSql('ALTER INDEX users.uniq_33a053ffe7927c74 RENAME TO uniq_86a85138e7927c74');
        $this->addSql('ALTER INDEX users.uniq_5ea1def6c74f2195 RENAME TO uniq_b9ca7babc74f2195');

        // Recreate FKs pointing to users schema
        $this->addSql('ALTER TABLE telegram.account ADD CONSTRAINT FK_468AA65CA76ED395 FOREIGN KEY (user_id) REFERENCES users."user" (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE monitoring.config ADD CONSTRAINT FK_8DABDC8A896DBBDE FOREIGN KEY (updated_by_id) REFERENCES users."user" (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE document_center.watch ADD CONSTRAINT FK_826D7326A76ED395 FOREIGN KEY (user_id) REFERENCES users."user" (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');

        $this->addSql('DROP SCHEMA IF EXISTS "user"');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('CREATE SCHEMA IF NOT EXISTS "user"');

        $this->addSql('ALTER TABLE telegram.account DROP CONSTRAINT FK_468AA65CA76ED395');
        $this->addSql('ALTER TABLE monitoring.config DROP CONSTRAINT FK_8DABDC8A896DBBDE');
        $this->addSql('ALTER TABLE document_center.watch DROP CONSTRAINT FK_826D7326A76ED395');

        $this->addSql('ALTER TABLE users."user" SET SCHEMA "user"');
        $this->addSql('ALTER TABLE users.refresh_token SET SCHEMA "user"');

        $this->addSql('ALTER INDEX "user".uniq_86a85138e7927c74 RENAME TO uniq_33a053ffe7927c74');
        $this->addSql('ALTER INDEX "user".uniq_b9ca7babc74f2195 RENAME TO uniq_5ea1def6c74f2195');

        $this->addSql('ALTER TABLE telegram.account ADD CONSTRAINT FK_468AA65CA76ED395 FOREIGN KEY (user_id) REFERENCES "user"."user" (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE monitoring.config ADD CONSTRAINT FK_8DABDC8A896DBBDE FOREIGN KEY (updated_by_id) REFERENCES "user"."user" (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE document_center.watch ADD CONSTRAINT FK_826D7326A76ED395 FOREIGN KEY (user_id) REFERENCES "user"."user" (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');

        $this->addSql('DROP SCHEMA IF EXISTS users');
    }
}
