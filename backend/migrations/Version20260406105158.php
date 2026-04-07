<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260406105158 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE SEQUENCE "api_logs_id_seq" INCREMENT BY 1 MINVALUE 1 START 1');
        $this->addSql('CREATE TABLE "api_logs" (id INT NOT NULL, analysis_id INT DEFAULT NULL, provider VARCHAR(50) NOT NULL, endpoint VARCHAR(255) NOT NULL, request_data JSON DEFAULT NULL, response_data JSON DEFAULT NULL, status_code INT DEFAULT NULL, duration_seconds DOUBLE PRECISION DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE INDEX IDX_D178B0AF7941003F ON "api_logs" (analysis_id)');
        $this->addSql('COMMENT ON COLUMN "api_logs".created_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('ALTER TABLE "api_logs" ADD CONSTRAINT FK_D178B0AF7941003F FOREIGN KEY (analysis_id) REFERENCES "analyses" (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE SCHEMA public');
        $this->addSql('DROP SEQUENCE "api_logs_id_seq" CASCADE');
        $this->addSql('ALTER TABLE "api_logs" DROP CONSTRAINT FK_D178B0AF7941003F');
        $this->addSql('DROP TABLE "api_logs"');
    }
}
