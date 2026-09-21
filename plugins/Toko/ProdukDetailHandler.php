<?php

declare(strict_types=1);

/*
 * ZEF Framework v2.7.0 — Demo plugin
 * Extracted from monolith zef_framework_v2.7.0.php during the
 * hexagonal refactor (move-only, no behavioural changes).
 */

namespace Zef\Plugin\Toko;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Zef\Framework\Http\Response;

final class ProdukDetailHandler implements RequestHandlerInterface
{
    public function __construct(private readonly ProdukService $service) {}

    #[\Override]
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $id = (int) $request->getAttribute('id', 0);
        $p = $this->service->find($id);
        if ($p === null) {
            return new Response(
                404,
                ['Content-Type' => 'application/json'],
                json_encode(['error' => "Produk #{$id} tidak ditemukan."], JSON_THROW_ON_ERROR),
            );
        }

        return new Response(
            200,
            ['Content-Type' => 'application/json'],
            json_encode(
                ['module' => 'toko', 'page' => 'detail', 'produk' => $p],
                JSON_THROW_ON_ERROR,
            ),
        );
    }
}
