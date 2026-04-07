<?php

namespace App\Entity;

use App\Repository\MetricRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: MetricRepository::class)]
#[ORM\Table(name: '`metrics`')]
#[ORM\HasLifecycleCallbacks]
class Metric
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'metrics')]
    #[ORM\JoinColumn(nullable: false)]
    private ?Analysis $analysis = null;

    #[ORM\Column(length: 255)]
    private ?string $name = null;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $canonicalName = null;

    #[ORM\Column(length: 100)]
    private ?string $value = null;

    #[ORM\Column(length: 50, nullable: true)]
    private ?string $unit = null;

    #[ORM\Column(type: 'decimal', precision: 10, scale: 4, nullable: true)]
    private ?string $referenceMin = null;

    #[ORM\Column(type: 'decimal', precision: 10, scale: 4, nullable: true)]
    private ?string $referenceMax = null;

    #[ORM\Column(nullable: true)]
    private ?bool $isAboveNormal = null;

    #[ORM\Column(nullable: true)]
    private ?bool $isBelowNormal = null;

    #[ORM\Column]
    private ?\DateTimeImmutable $createdAt = null;

    #[ORM\PrePersist]
    public function onPrePersist(): void
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getAnalysis(): ?Analysis
    {
        return $this->analysis;
    }

    public function setAnalysis(?Analysis $analysis): static
    {
        $this->analysis = $analysis;

        return $this;
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    public function setName(string $name): static
    {
        $this->name = $name;

        return $this;
    }

    public function getCanonicalName(): ?string
    {
        return $this->canonicalName;
    }

    public function setCanonicalName(?string $canonicalName): static
    {
        $this->canonicalName = $canonicalName;

        return $this;
    }

    public function getValue(): ?string
    {
        return $this->value;
    }

    public function setValue(string $value): static
    {
        $this->value = $value;

        return $this;
    }

    public function getUnit(): ?string
    {
        return $this->unit;
    }

    public function setUnit(?string $unit): static
    {
        $this->unit = $unit;

        return $this;
    }

    public function getReferenceMin(): ?string
    {
        return $this->referenceMin;
    }

    public function setReferenceMin(?string $referenceMin): static
    {
        $this->referenceMin = $referenceMin;

        return $this;
    }

    public function getReferenceMax(): ?string
    {
        return $this->referenceMax;
    }

    public function setReferenceMax(?string $referenceMax): static
    {
        $this->referenceMax = $referenceMax;

        return $this;
    }

    public function isAboveNormal(): ?bool
    {
        return $this->isAboveNormal;
    }

    public function setIsAboveNormal(?bool $isAboveNormal): static
    {
        $this->isAboveNormal = $isAboveNormal;

        return $this;
    }

    public function isBelowNormal(): ?bool
    {
        return $this->isBelowNormal;
    }

    public function setIsBelowNormal(?bool $isBelowNormal): static
    {
        $this->isBelowNormal = $isBelowNormal;

        return $this;
    }

    public function getCreatedAt(): ?\DateTimeImmutable
    {
        return $this->createdAt;
    }

    /**
     * Check if value is within normal range
     */
    public function isNormal(): ?bool
    {
        if ($this->isAboveNormal === null && $this->isBelowNormal === null) {
            return null;
        }
        return !$this->isAboveNormal && !$this->isBelowNormal;
    }
}
