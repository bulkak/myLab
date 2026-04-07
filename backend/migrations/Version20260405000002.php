<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260405000002 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Remove ocr_refined_text column - ocrRawText is sufficient for all cases';
    }

    public function up(Schema $schema): void
    {
        // Check if column exists before trying to drop it
        $table = $schema->getTable('analyses');
        if ($table->hasColumn('ocr_refined_text')) {
            $this->addSql('ALTER TABLE "analyses" DROP COLUMN ocr_refined_text');
        }
    }

    public function down(Schema $schema): void
    {
        $table = $schema->getTable('analyses');
        if (!$table->hasColumn('ocr_refined_text')) {
            $this->addSql('ALTER TABLE "analyses" ADD ocr_refined_text TEXT DEFAULT NULL');
        }
    }
}
