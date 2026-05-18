<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260518180620 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Rename reserved-word table users."user" → users.account to avoid Doctrine quoting issues';
    }

    public function up(Schema $schema): void
    {
        // Drop FKs before renaming
        $this->addSql('ALTER TABLE telegram.account DROP CONSTRAINT FK_468AA65CA76ED395');
        $this->addSql('ALTER TABLE monitoring.config DROP CONSTRAINT FK_8DABDC8A896DBBDE');
        $this->addSql('ALTER TABLE document_center.watch DROP CONSTRAINT FK_826D7326A76ED395');

        $this->addSql('ALTER TABLE users."user" RENAME TO account');

        // Rename index to match Doctrine-generated name for users.account
        $this->addSql('ALTER INDEX users.uniq_86a85138e7927c74 RENAME TO uniq_edccb360e7927c74');

        // Recreate FKs pointing to users.account
        $this->addSql('ALTER TABLE telegram.account ADD CONSTRAINT FK_468AA65CA76ED395 FOREIGN KEY (user_id) REFERENCES users.account (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE monitoring.config ADD CONSTRAINT FK_8DABDC8A896DBBDE FOREIGN KEY (updated_by_id) REFERENCES users.account (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE document_center.watch ADD CONSTRAINT FK_826D7326A76ED395 FOREIGN KEY (user_id) REFERENCES users.account (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE telegram.account DROP CONSTRAINT FK_468AA65CA76ED395');
        $this->addSql('ALTER TABLE monitoring.config DROP CONSTRAINT FK_8DABDC8A896DBBDE');
        $this->addSql('ALTER TABLE document_center.watch DROP CONSTRAINT FK_826D7326A76ED395');

        $this->addSql('ALTER TABLE users.account RENAME TO "user"');
        $this->addSql('ALTER INDEX users.uniq_edccb360e7927c74 RENAME TO uniq_86a85138e7927c74');

        $this->addSql('ALTER TABLE telegram.account ADD CONSTRAINT FK_468AA65CA76ED395 FOREIGN KEY (user_id) REFERENCES users."user" (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE monitoring.config ADD CONSTRAINT FK_8DABDC8A896DBBDE FOREIGN KEY (updated_by_id) REFERENCES users."user" (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE document_center.watch ADD CONSTRAINT FK_826D7326A76ED395 FOREIGN KEY (user_id) REFERENCES users."user" (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
    }
}
