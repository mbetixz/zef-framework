<?php

declare(strict_types=1);

/*
 * ZEF Framework v2.7.0 — Demo plugin
 * Extracted from monolith zef_framework_v2.7.0.php during the
 * hexagonal refactor (move-only, no behavioural changes).
 */

namespace Zef\Plugin\Toko;

final class ProdukService
{
    /**
     * @var list<array{id:int,nama:string,harga:int}>
     */
    private array $produk = [
        ['id' => 1, 'nama' => 'Keyboard Mechanical', 'harga' => 450000],
        ['id' => 2, 'nama' => 'Mouse Wireless', 'harga' => 185000],
        ['id' => 3, 'nama' => 'Monitor 27"', 'harga' => 3200000],
    ];

    /** @return list<array{id:int,nama:string,harga:int}> */
    public function all(): array
    {
        return $this->produk;
    }

    /** @return null|array{id:int,nama:string,harga:int} */
    public function find(int $id): ?array
    {
        foreach ($this->produk as $item) {
            if ($item['id'] === $id) {
                return $item;
            }
        }

        return null;
    }
}
