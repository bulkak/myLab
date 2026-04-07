<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260406000003 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add error_message column to analyses table for storing OCR/processing errors';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE "analyses" ADD error_message TEXT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE "analyses" DROP COLUMN error_message');
    }
}
