<?php

declare(strict_types=1);

namespace App\Tests;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

abstract class FunctionalTestCase extends WebTestCase
{
    public static function assertHttp2xx(Response $response): void
    {
        self::assertTrue(
            in_array($response->getStatusCode(), [200, 201, 202, 204]),
            sprintf('Expected status code to be 2xx, got %d', $response->getStatusCode())
        );
    }

    public static function assertResponseHasContent(Response $response): void
    {
        self::assertNotEmpty(
            $response->getContent(),
            'Response content is empty'
        );
    }
}
