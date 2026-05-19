<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260519145313 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add slot_scraping_enabled to monitoring.config';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE monitoring.config ADD COLUMN slot_scraping_enabled BOOLEAN NOT NULL DEFAULT FALSE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE monitoring.config DROP COLUMN slot_scraping_enabled');
    }
}
