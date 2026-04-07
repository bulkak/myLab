<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260406070547 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE analyses DROP CONSTRAINT fk_analysis_user');
        $this->addSql('DROP INDEX idx_analysis_status');
        $this->addSql('ALTER TABLE analyses ADD debug_images_paths TEXT DEFAULT NULL');
        $this->addSql('ALTER TABLE analyses ALTER id DROP DEFAULT');
        $this->addSql('ALTER TABLE analyses ALTER status DROP DEFAULT');
        $this->addSql('ALTER TABLE analyses ALTER status SET NOT NULL');
        $this->addSql('ALTER TABLE analyses ALTER analysis_date TYPE TIMESTAMP(0) WITHOUT TIME ZONE');
        $this->addSql('ALTER TABLE analyses ALTER created_at TYPE TIMESTAMP(0) WITHOUT TIME ZONE');
        $this->addSql('ALTER TABLE analyses ALTER created_at DROP DEFAULT');
        $this->addSql('COMMENT ON COLUMN analyses.analysis_date IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('COMMENT ON COLUMN analyses.created_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('ALTER TABLE analyses ADD CONSTRAINT FK_AC86883CA76ED395 FOREIGN KEY (user_id) REFERENCES "users" (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER INDEX idx_analysis_user RENAME TO IDX_AC86883CA76ED395');
        $this->addSql('ALTER TABLE metric_aliases DROP CONSTRAINT fk_alias_user');
        $this->addSql('DROP INDEX idx_alias_canonical');
        $this->addSql('ALTER TABLE metric_aliases ALTER id DROP DEFAULT');
        $this->addSql('ALTER TABLE metric_aliases ALTER created_at TYPE TIMESTAMP(0) WITHOUT TIME ZONE');
        $this->addSql('ALTER TABLE metric_aliases ALTER created_at DROP DEFAULT');
        $this->addSql('COMMENT ON COLUMN metric_aliases.created_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('ALTER TABLE metric_aliases ADD CONSTRAINT FK_7E26A9B7A76ED395 FOREIGN KEY (user_id) REFERENCES "users" (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER INDEX idx_alias_user RENAME TO IDX_7E26A9B7A76ED395');
        $this->addSql('ALTER TABLE metrics DROP CONSTRAINT fk_metric_analysis');
        $this->addSql('DROP INDEX idx_metric_canonical_name');
        $this->addSql('DROP INDEX idx_metric_name');
        $this->addSql('ALTER TABLE metrics ALTER id DROP DEFAULT');
        $this->addSql('ALTER TABLE metrics ALTER canonical_name TYPE VARCHAR(100)');
        $this->addSql('ALTER TABLE metrics ALTER created_at TYPE TIMESTAMP(0) WITHOUT TIME ZONE');
        $this->addSql('ALTER TABLE metrics ALTER created_at DROP DEFAULT');
        $this->addSql('COMMENT ON COLUMN metrics.created_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('ALTER TABLE metrics ADD CONSTRAINT FK_228AAAE77941003F FOREIGN KEY (analysis_id) REFERENCES "analyses" (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER INDEX idx_metric_analysis RENAME TO IDX_228AAAE77941003F');
        $this->addSql('ALTER TABLE users ALTER id DROP DEFAULT');
        $this->addSql('ALTER TABLE users ALTER status DROP DEFAULT');
        $this->addSql('ALTER TABLE users ALTER status SET NOT NULL');
        $this->addSql('ALTER TABLE users ALTER created_at TYPE TIMESTAMP(0) WITHOUT TIME ZONE');
        $this->addSql('ALTER TABLE users ALTER created_at DROP DEFAULT');
        $this->addSql('COMMENT ON COLUMN users.created_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('ALTER INDEX users_username_key RENAME TO UNIQ_1483A5E9F85E0677');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE SCHEMA public');
        $this->addSql('ALTER TABLE "metric_aliases" DROP CONSTRAINT FK_7E26A9B7A76ED395');
        $this->addSql('CREATE SEQUENCE metric_aliases_id_seq');
        $this->addSql('SELECT setval(\'metric_aliases_id_seq\', (SELECT MAX(id) FROM "metric_aliases"))');
        $this->addSql('ALTER TABLE "metric_aliases" ALTER id SET DEFAULT nextval(\'metric_aliases_id_seq\')');
        $this->addSql('ALTER TABLE "metric_aliases" ALTER created_at TYPE TIMESTAMP(0) WITHOUT TIME ZONE');
        $this->addSql('ALTER TABLE "metric_aliases" ALTER created_at SET DEFAULT CURRENT_TIMESTAMP');
        $this->addSql('COMMENT ON COLUMN "metric_aliases".created_at IS NULL');
        $this->addSql('ALTER TABLE "metric_aliases" ADD CONSTRAINT fk_alias_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('CREATE INDEX idx_alias_canonical ON "metric_aliases" (canonical_name)');
        $this->addSql('ALTER INDEX idx_7e26a9b7a76ed395 RENAME TO idx_alias_user');
        $this->addSql('ALTER TABLE "metrics" DROP CONSTRAINT FK_228AAAE77941003F');
        $this->addSql('CREATE SEQUENCE metrics_id_seq');
        $this->addSql('SELECT setval(\'metrics_id_seq\', (SELECT MAX(id) FROM "metrics"))');
        $this->addSql('ALTER TABLE "metrics" ALTER id SET DEFAULT nextval(\'metrics_id_seq\')');
        $this->addSql('ALTER TABLE "metrics" ALTER canonical_name TYPE VARCHAR(255)');
        $this->addSql('ALTER TABLE "metrics" ALTER created_at TYPE TIMESTAMP(0) WITHOUT TIME ZONE');
        $this->addSql('ALTER TABLE "metrics" ALTER created_at SET DEFAULT CURRENT_TIMESTAMP');
        $this->addSql('COMMENT ON COLUMN "metrics".created_at IS NULL');
        $this->addSql('ALTER TABLE "metrics" ADD CONSTRAINT fk_metric_analysis FOREIGN KEY (analysis_id) REFERENCES analyses (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('CREATE INDEX idx_metric_canonical_name ON "metrics" (canonical_name)');
        $this->addSql('CREATE INDEX idx_metric_name ON "metrics" (name)');
        $this->addSql('ALTER INDEX idx_228aaae77941003f RENAME TO idx_metric_analysis');
        $this->addSql('CREATE SEQUENCE users_id_seq');
        $this->addSql('SELECT setval(\'users_id_seq\', (SELECT MAX(id) FROM "users"))');
        $this->addSql('ALTER TABLE "users" ALTER id SET DEFAULT nextval(\'users_id_seq\')');
        $this->addSql('ALTER TABLE "users" ALTER status SET DEFAULT \'active\'');
        $this->addSql('ALTER TABLE "users" ALTER status DROP NOT NULL');
        $this->addSql('ALTER TABLE "users" ALTER created_at TYPE TIMESTAMP(0) WITHOUT TIME ZONE');
        $this->addSql('ALTER TABLE "users" ALTER created_at SET DEFAULT CURRENT_TIMESTAMP');
        $this->addSql('COMMENT ON COLUMN "users".created_at IS NULL');
        $this->addSql('ALTER INDEX uniq_1483a5e9f85e0677 RENAME TO users_username_key');
        $this->addSql('ALTER TABLE "analyses" DROP CONSTRAINT FK_AC86883CA76ED395');
        $this->addSql('ALTER TABLE "analyses" DROP debug_images_paths');
        $this->addSql('CREATE SEQUENCE analyses_id_seq');
        $this->addSql('SELECT setval(\'analyses_id_seq\', (SELECT MAX(id) FROM "analyses"))');
        $this->addSql('ALTER TABLE "analyses" ALTER id SET DEFAULT nextval(\'analyses_id_seq\')');
        $this->addSql('ALTER TABLE "analyses" ALTER status SET DEFAULT \'pending\'');
        $this->addSql('ALTER TABLE "analyses" ALTER status DROP NOT NULL');
        $this->addSql('ALTER TABLE "analyses" ALTER analysis_date TYPE TIMESTAMP(0) WITHOUT TIME ZONE');
        $this->addSql('ALTER TABLE "analyses" ALTER created_at TYPE TIMESTAMP(0) WITHOUT TIME ZONE');
        $this->addSql('ALTER TABLE "analyses" ALTER created_at SET DEFAULT CURRENT_TIMESTAMP');
        $this->addSql('COMMENT ON COLUMN "analyses".analysis_date IS NULL');
        $this->addSql('COMMENT ON COLUMN "analyses".created_at IS NULL');
        $this->addSql('ALTER TABLE "analyses" ADD CONSTRAINT fk_analysis_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('CREATE INDEX idx_analysis_status ON "analyses" (status)');
        $this->addSql('ALTER INDEX idx_ac86883ca76ed395 RENAME TO idx_analysis_user');
    }
}
