<?php

declare(strict_types=1);

// ZEF Framework v2.20.0 — Adapters layer (OpenAPI documentation module)

namespace Zef\Framework\OpenApi\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Zef\Framework\Http\Response;

/**
 * Serves a minimal Swagger UI page (via CDN) pointing at the JSON spec.
 *
 * Intended for development/staging: the page loads external assets, so
 * wire it behind an environment check (or disable it entirely with
 * enabled: false, which falls through to the next handler).
 */
final readonly class DocsUiHandler implements RequestHandlerInterface
{
    public function __construct(
        private string $specUrl = '/openapi.json',
        private string $title = 'API Documentation',
        private bool $enabled = true,
    ) {}

    #[\Override]
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        if (!$this->enabled) {
            return new Response(404, ['Content-Type' => 'text/plain; charset=utf-8'], 'Documentation UI is disabled.');
        }

        $specUrl = htmlspecialchars($this->specUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $title = htmlspecialchars($this->title, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        $html = <<<HTML
            <!DOCTYPE html>
            <html lang="en">
            <head>
              <meta charset="utf-8">
              <meta name="viewport" content="width=device-width, initial-scale=1">
              <title>{$title}</title>
              <link rel="stylesheet" href="https://unpkg.com/swagger-ui-dist@5/swagger-ui.css">
            </head>
            <body>
              <div id="swagger-ui"></div>
              <script src="https://unpkg.com/swagger-ui-dist@5/swagger-ui-bundle.js" defer></script>
              <script>
                window.addEventListener('load', function () {
                  window.ui = SwaggerUIBundle({ url: '{$specUrl}', dom_id: '#swagger-ui' });
                });
              </script>
            </body>
            </html>
            HTML;

        return new Response(
            200,
            ['Content-Type' => 'text/html; charset=utf-8'],
            $html,
        );
    }
}
