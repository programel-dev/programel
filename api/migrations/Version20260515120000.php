<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260515120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create monitoring_config table with initial enabled row';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE monitoring_config (
            id INT NOT NULL,
            updated_by_id INT DEFAULT NULL,
            enabled BOOLEAN NOT NULL DEFAULT TRUE,
            updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
            PRIMARY KEY(id)
        )');
        $this->addSql('ALTER TABLE monitoring_config ADD CONSTRAINT fk_monitoring_config_user FOREIGN KEY (updated_by_id) REFERENCES users (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('CREATE INDEX idx_monitoring_config_updated_by ON monitoring_config (updated_by_id)');
        $this->addSql('COMMENT ON COLUMN monitoring_config.updated_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('INSERT INTO monitoring_config (id, enabled, updated_at) VALUES (1, TRUE, NOW())');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE monitoring_config DROP CONSTRAINT fk_monitoring_config_user');
        $this->addSql('DROP TABLE monitoring_config');
    }
}
