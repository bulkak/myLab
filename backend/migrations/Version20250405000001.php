<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20250405000001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Initial database schema: users, analyses, metrics, metric_aliases';
    }

    public function up(Schema $schema): void
    {
        // users table
        $this->addSql('CREATE TABLE users (
            id SERIAL PRIMARY KEY,
            username VARCHAR(255) NOT NULL UNIQUE,
            totp_secret VARCHAR(255) NOT NULL,
            status VARCHAR(50) DEFAULT \'active\',
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
        )');

        // analyses table
        $this->addSql('CREATE TABLE analyses (
            id SERIAL PRIMARY KEY,
            user_id INT NOT NULL,
            file_path VARCHAR(500) NOT NULL,
            title VARCHAR(255) NULL,
            ocr_raw_text TEXT NULL,
            status VARCHAR(50) DEFAULT \'pending\',
            analysis_date TIMESTAMP NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            CONSTRAINT fk_analysis_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        )');
        $this->addSql('CREATE INDEX idx_analysis_user ON analyses(user_id)');
        $this->addSql('CREATE INDEX idx_analysis_status ON analyses(status)');

        // metrics table
        $this->addSql('CREATE TABLE metrics (
            id SERIAL PRIMARY KEY,
            analysis_id INT NOT NULL,
            name VARCHAR(255) NOT NULL,
            canonical_name VARCHAR(255) NULL,
            value VARCHAR(100) NOT NULL,
            unit VARCHAR(50) NULL,
            reference_min NUMERIC(10, 4) NULL,
            reference_max NUMERIC(10, 4) NULL,
            is_above_normal BOOLEAN NULL,
            is_below_normal BOOLEAN NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            CONSTRAINT fk_metric_analysis FOREIGN KEY (analysis_id) REFERENCES analyses(id) ON DELETE CASCADE
        )');
        $this->addSql('CREATE INDEX idx_metric_analysis ON metrics(analysis_id)');
        $this->addSql('CREATE INDEX idx_metric_name ON metrics(name)');
        $this->addSql('CREATE INDEX idx_metric_canonical_name ON metrics(canonical_name)');

        // metric_aliases table
        $this->addSql('CREATE TABLE metric_aliases (
            id SERIAL PRIMARY KEY,
            user_id INT NOT NULL,
            original_name VARCHAR(255) NOT NULL,
            canonical_name VARCHAR(255) NOT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            CONSTRAINT fk_alias_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        )');
        $this->addSql('CREATE UNIQUE INDEX unique_user_alias ON metric_aliases(user_id, original_name)');
        $this->addSql('CREATE INDEX idx_alias_user ON metric_aliases(user_id)');
        $this->addSql('CREATE INDEX idx_alias_canonical ON metric_aliases(canonical_name)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS metric_aliases');
        $this->addSql('DROP TABLE IF EXISTS metrics');
        $this->addSql('DROP TABLE IF EXISTS analyses');
        $this->addSql('DROP TABLE IF EXISTS users');
    }
}
