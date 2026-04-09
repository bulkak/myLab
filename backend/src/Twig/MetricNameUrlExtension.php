<?php

declare(strict_types=1);

namespace App\Twig;

use App\Util\MetricDynamicsToken;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

final class MetricNameUrlExtension extends AbstractExtension
{
    public function __construct(
        private UrlGeneratorInterface $urlGenerator
    ) {}

    public function getFunctions(): array
    {
        return [
            new TwigFunction('metric_dynamics_path', [$this, 'metricDynamicsPath']),
            new TwigFunction('metric_history_path', [$this, 'metricHistoryPath']),
        ];
    }

    public function metricDynamicsPath(?string $displayName): string
    {
        if ($displayName === null || $displayName === '') {
            return '#';
        }

        return $this->urlGenerator->generate('app_metric_dynamics', [
            'token' => MetricDynamicsToken::encode($displayName),
        ]);
    }

    public function metricHistoryPath(?string $displayName): string
    {
        if ($displayName === null || $displayName === '') {
            return '#';
        }

        return $this->urlGenerator->generate('app_history_metric', [
            'token' => MetricDynamicsToken::encode($displayName),
        ]);
    }
}
