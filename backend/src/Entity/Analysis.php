<?php

namespace App\Entity;

use App\Repository\AnalysisRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: AnalysisRepository::class)]
#[ORM\Table(name: '`analyses`')]
#[ORM\HasLifecycleCallbacks]
class Analysis
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_ERROR = 'error';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'analyses')]
    #[ORM\JoinColumn(nullable: false)]
    private ?User $user = null;

    #[ORM\Column(length: 500)]
    private ?string $filePath = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $title = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $ocrRawText = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $errorMessage = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $debugImagesPaths = null;

    #[ORM\Column(length: 50)]
    private ?string $status = self::STATUS_PENDING;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $analysisDate = null;

    #[ORM\Column]
    private ?\DateTimeImmutable $createdAt = null;

    #[ORM\Column(type: 'boolean', options: ['default' => false])]
    private bool $isConfirmed = false;

    /** @var Collection<int, Metric> */
    #[ORM\OneToMany(mappedBy: 'analysis', targetEntity: Metric::class, orphanRemoval: true)]
    private Collection $metrics;

    public function __construct()
    {
        $this->metrics = new ArrayCollection();
    }

    #[ORM\PrePersist]
    public function onPrePersist(): void
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function isConfirmed(): bool
    {
        return $this->isConfirmed;
    }

    public function setIsConfirmed(bool $isConfirmed): static
    {
        $this->isConfirmed = $isConfirmed;

        return $this;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUser(): ?User
    {
        return $this->user;
    }

    public function setUser(?User $user): static
    {
        $this->user = $user;

        return $this;
    }

    public function getFilePath(): ?string
    {
        return $this->filePath;
    }

    public function setFilePath(string $filePath): static
    {
        $this->filePath = $filePath;

        return $this;
    }

    public function getTitle(): ?string
    {
        return $this->title;
    }

    public function setTitle(?string $title): static
    {
        $this->title = $title;

        return $this;
    }

    public function getOcrRawText(): ?string
    {
        return $this->ocrRawText;
    }

    public function setOcrRawText(?string $ocrRawText): static
    {
        $this->ocrRawText = $ocrRawText;

        return $this;
    }

    public function getErrorMessage(): ?string
    {
        return $this->errorMessage;
    }

    public function setErrorMessage(?string $errorMessage): static
    {
        $this->errorMessage = $errorMessage;

        return $this;
    }

    public function getStatus(): ?string
    {
        return $this->status;
    }

    public function setStatus(string $status): static
    {
        $this->status = $status;

        return $this;
    }

    public function getAnalysisDate(): ?\DateTimeImmutable
    {
        return $this->analysisDate;
    }

    public function setAnalysisDate(?\DateTimeImmutable $analysisDate): static
    {
        $this->analysisDate = $analysisDate;

        return $this;
    }

    public function getCreatedAt(): ?\DateTimeImmutable
    {
        return $this->createdAt;
    }

    /**
     * @return Collection<int, Metric>
     */
    public function getMetrics(): Collection
    {
        return $this->metrics;
    }

    public function addMetric(Metric $metric): static
    {
        if (!$this->metrics->contains($metric)) {
            $this->metrics->add($metric);
            $metric->setAnalysis($this);
        }

        return $this;
    }

    public function removeMetric(Metric $metric): static
    {
        if ($this->metrics->removeElement($metric)) {
            // set the owning side to null (unless already changed)
            if ($metric->getAnalysis() === $this) {
                $metric->setAnalysis(null);
            }
        }

        return $this;
    }

    public function getDebugImagesPaths(): ?string
    {
        return $this->debugImagesPaths;
    }

    /**
     * Get debug images paths as an array
     *
     * @return array<string>
     */
    public function getDebugImagesPathsArray(): array
    {
        if (!$this->debugImagesPaths) {
            return [];
        }
        
        $decoded = json_decode($this->debugImagesPaths, true);
        return is_array($decoded) ? $decoded : [];
    }

    public function setDebugImagesPaths(?string $paths): static
    {
        $this->debugImagesPaths = $paths;

        return $this;
    }
}
