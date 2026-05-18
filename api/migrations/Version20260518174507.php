<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260518174507 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Modular monolith DDD: move tables to PostgreSQL schemas (document_center, telegram, monitoring, user)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE SCHEMA IF NOT EXISTS document_center');
        $this->addSql('CREATE SCHEMA IF NOT EXISTS telegram');
        $this->addSql('CREATE SCHEMA IF NOT EXISTS monitoring');
        $this->addSql('CREATE SCHEMA IF NOT EXISTS "user"');

        // Drop FKs before moving tables
        $this->addSql('ALTER TABLE equeue_notification DROP CONSTRAINT fk_equeue_notification_watch');
        $this->addSql('ALTER TABLE equeue_watch DROP CONSTRAINT fk_equeue_watch_user');
        $this->addSql('ALTER TABLE monitoring_config DROP CONSTRAINT fk_monitoring_config_user');
        $this->addSql('ALTER TABLE telegram_account DROP CONSTRAINT fk_telegram_account_user');

        // Move and rename: users → "user"."user"
        $this->addSql('ALTER TABLE users SET SCHEMA "user"');
        $this->addSql('ALTER TABLE "user".users RENAME TO "user"');

        // Move and rename: refresh_tokens → "user".refresh_token
        $this->addSql('ALTER TABLE refresh_tokens SET SCHEMA "user"');
        $this->addSql('ALTER TABLE "user".refresh_tokens RENAME TO refresh_token');

        // Move and rename: equeue_watch → document_center.watch
        $this->addSql('ALTER TABLE equeue_watch SET SCHEMA document_center');
        $this->addSql('ALTER TABLE document_center.equeue_watch RENAME TO watch');

        // Move and rename: equeue_snapshot → document_center.snapshot
        $this->addSql('ALTER TABLE equeue_snapshot SET SCHEMA document_center');
        $this->addSql('ALTER TABLE document_center.equeue_snapshot RENAME TO snapshot');

        // Move and rename: equeue_raw_html → document_center.raw_html
        $this->addSql('ALTER TABLE equeue_raw_html SET SCHEMA document_center');
        $this->addSql('ALTER TABLE document_center.equeue_raw_html RENAME TO raw_html');

        // Move and rename: equeue_notification → document_center.notification
        $this->addSql('ALTER TABLE equeue_notification SET SCHEMA document_center');
        $this->addSql('ALTER TABLE document_center.equeue_notification RENAME TO notification');

        // Move and rename: telegram_account → telegram.account
        $this->addSql('ALTER TABLE telegram_account SET SCHEMA telegram');
        $this->addSql('ALTER TABLE telegram.telegram_account RENAME TO account');

        // Move and rename: monitoring_config → monitoring.config
        $this->addSql('ALTER TABLE monitoring_config SET SCHEMA monitoring');
        $this->addSql('ALTER TABLE monitoring.monitoring_config RENAME TO config');

        // Remove Doctrine 2 type comment no longer needed in Doctrine 3
        $this->addSql('COMMENT ON COLUMN monitoring.config.updated_at IS NULL');

        // Drop boolean default that Doctrine no longer generates
        $this->addSql('ALTER TABLE monitoring.config ALTER COLUMN enabled DROP DEFAULT');

        // Rename indexes to match new Doctrine-generated names
        $this->addSql('ALTER INDEX "user".uniq_1483a5e9e7927c74 RENAME TO uniq_33a053ffe7927c74');
        $this->addSql('ALTER INDEX "user".uniq_9bace7e1c74f2195 RENAME TO uniq_5ea1def6c74f2195');
        $this->addSql('ALTER INDEX telegram.uniq_26703752a76ed395 RENAME TO uniq_468aa65ca76ed395');
        $this->addSql('ALTER INDEX monitoring.idx_monitoring_config_updated_by RENAME TO idx_8dabdc8a896dbbde');

        // Create FK indexes that did not exist on old tables
        $this->addSql('CREATE INDEX IDX_60ED350C7C58135 ON document_center.notification (watch_id)');
        $this->addSql('CREATE INDEX IDX_826D7326A76ED395 ON document_center.watch (user_id)');

        // Recreate FKs pointing to new schema-qualified table names
        $this->addSql('ALTER TABLE telegram.account ADD CONSTRAINT FK_468AA65CA76ED395 FOREIGN KEY (user_id) REFERENCES "user"."user" (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE monitoring.config ADD CONSTRAINT FK_8DABDC8A896DBBDE FOREIGN KEY (updated_by_id) REFERENCES "user"."user" (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE document_center.watch ADD CONSTRAINT FK_826D7326A76ED395 FOREIGN KEY (user_id) REFERENCES "user"."user" (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE document_center.notification ADD CONSTRAINT FK_60ED350C7C58135 FOREIGN KEY (watch_id) REFERENCES document_center.watch (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
    }

    public function down(Schema $schema): void
    {
        // Drop FKs before moving tables back
        $this->addSql('ALTER TABLE telegram.account DROP CONSTRAINT FK_468AA65CA76ED395');
        $this->addSql('ALTER TABLE monitoring.config DROP CONSTRAINT FK_8DABDC8A896DBBDE');
        $this->addSql('ALTER TABLE document_center.watch DROP CONSTRAINT FK_826D7326A76ED395');
        $this->addSql('ALTER TABLE document_center.notification DROP CONSTRAINT FK_60ED350C7C58135');

        // Drop FK indexes that did not exist before
        $this->addSql('DROP INDEX document_center.idx_60ed350c7c58135');
        $this->addSql('DROP INDEX document_center.idx_826d7326a76ed395');

        // Rename indexes back to old names
        $this->addSql('ALTER INDEX "user".uniq_33a053ffe7927c74 RENAME TO uniq_1483a5e9e7927c74');
        $this->addSql('ALTER INDEX "user".uniq_5ea1def6c74f2195 RENAME TO uniq_9bace7e1c74f2195');
        $this->addSql('ALTER INDEX telegram.uniq_468aa65ca76ed395 RENAME TO uniq_26703752a76ed395');
        $this->addSql('ALTER INDEX monitoring.idx_8dabdc8a896dbbde RENAME TO idx_monitoring_config_updated_by');

        // Restore boolean default and Doctrine 2 type comment
        $this->addSql('ALTER TABLE monitoring.config ALTER COLUMN enabled SET DEFAULT true');
        $this->addSql("COMMENT ON COLUMN monitoring.config.updated_at IS '(DC2Type:datetime_immutable)'");

        // Move and rename back: document_center.notification → equeue_notification
        $this->addSql('ALTER TABLE document_center.notification RENAME TO equeue_notification');
        $this->addSql('ALTER TABLE document_center.equeue_notification SET SCHEMA public');

        // Move and rename back: document_center.raw_html → equeue_raw_html
        $this->addSql('ALTER TABLE document_center.raw_html SET SCHEMA public');
        $this->addSql('ALTER TABLE raw_html RENAME TO equeue_raw_html');

        // Move and rename back: document_center.snapshot → equeue_snapshot
        $this->addSql('ALTER TABLE document_center.snapshot SET SCHEMA public');
        $this->addSql('ALTER TABLE snapshot RENAME TO equeue_snapshot');

        // Move and rename back: document_center.watch → equeue_watch
        $this->addSql('ALTER TABLE document_center.watch RENAME TO equeue_watch');
        $this->addSql('ALTER TABLE document_center.equeue_watch SET SCHEMA public');

        // Move and rename back: telegram.account → telegram_account
        $this->addSql('ALTER TABLE telegram.account RENAME TO telegram_account');
        $this->addSql('ALTER TABLE telegram.telegram_account SET SCHEMA public');

        // Move and rename back: monitoring.config → monitoring_config
        $this->addSql('ALTER TABLE monitoring.config RENAME TO monitoring_config');
        $this->addSql('ALTER TABLE monitoring.monitoring_config SET SCHEMA public');

        // Move and rename back: "user"."user" → users
        $this->addSql('ALTER TABLE "user"."user" RENAME TO users');
        $this->addSql('ALTER TABLE "user".users SET SCHEMA public');

        // Move and rename back: "user".refresh_token → refresh_tokens
        $this->addSql('ALTER TABLE "user".refresh_token RENAME TO refresh_tokens');
        $this->addSql('ALTER TABLE "user".refresh_tokens SET SCHEMA public');

        // Recreate original FK constraints pointing to public schema tables
        $this->addSql('ALTER TABLE equeue_notification ADD CONSTRAINT fk_equeue_notification_watch FOREIGN KEY (watch_id) REFERENCES equeue_watch (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE equeue_watch ADD CONSTRAINT fk_equeue_watch_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE monitoring_config ADD CONSTRAINT fk_monitoring_config_user FOREIGN KEY (updated_by_id) REFERENCES users (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE telegram_account ADD CONSTRAINT fk_telegram_account_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');

        $this->addSql('DROP SCHEMA IF EXISTS document_center');
        $this->addSql('DROP SCHEMA IF EXISTS telegram');
        $this->addSql('DROP SCHEMA IF EXISTS monitoring');
        $this->addSql('DROP SCHEMA IF EXISTS "user"');
    }
}
