<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Support\CanonicalJson;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class CanonicalJsonTest extends TestCase
{
    #[Test]
    public function hash_is_deterministic(): void
    {
        $data = ['b' => 2, 'a' => 1];

        $this->assertSame(CanonicalJson::hash($data), CanonicalJson::hash($data));
    }

    #[Test]
    public function hash_is_key_order_independent(): void
    {
        $a = ['b' => 2, 'a' => 1];
        $b = ['a' => 1, 'b' => 2];

        $this->assertSame(CanonicalJson::hash($a), CanonicalJson::hash($b));
    }

    #[Test]
    public function hash_differs_on_different_values(): void
    {
        $this->assertNotSame(
            CanonicalJson::hash(['a' => 1]),
            CanonicalJson::hash(['a' => 2]),
        );
    }

    #[Test]
    public function hash_sorts_nested_object_keys(): void
    {
        $a = ['meta' => ['z' => 9, 'a' => 1], 'key' => 'val'];
        $b = ['key' => 'val', 'meta' => ['a' => 1, 'z' => 9]];

        $this->assertSame(CanonicalJson::hash($a), CanonicalJson::hash($b));
    }

    #[Test]
    public function hash_preserves_sequential_array_order(): void
    {
        $a = ['items' => [1, 2, 3]];
        $b = ['items' => [3, 2, 1]];

        $this->assertNotSame(CanonicalJson::hash($a), CanonicalJson::hash($b));
    }

    #[Test]
    public function hash_handles_utf8_strings(): void
    {
        $data = ['title' => 'Résumé: AI è il futuro 🤖'];

        $hash = CanonicalJson::hash($data);

        $this->assertSame(64, strlen($hash));
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $hash);
    }

    #[Test]
    public function hash_returns_64_char_hex_string(): void
    {
        $hash = CanonicalJson::hash(['foo' => 'bar']);

        $this->assertSame(64, strlen($hash));
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $hash);
    }
}
