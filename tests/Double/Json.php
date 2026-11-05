<?php

declare(strict_types=1);

namespace Eekes\Sulu\FormBuilderBundle\Tests\Double;

use PHPUnit\Framework\Assert;

/**
 * A decoded JSON document read by path ("builder.steps.0.title"), so a test can reach deep into
 * an API response without losing the types static analysis needs.
 */
final readonly class Json
{
    /**
     * @param array<mixed> $data
     */
    public function __construct(
        public array $data,
    ) {
    }

    public static function decode(string $json): self
    {
        $data = json_decode($json, true);
        Assert::assertIsArray($data, 'Not a JSON object or array: '.substr($json, 0, 2000));

        return new self($data);
    }

    public static function of(mixed $value): self
    {
        return self::decode(json_encode($value, \JSON_THROW_ON_ERROR));
    }

    public function at(string $path): mixed
    {
        $value = $this->data;

        foreach (explode('.', $path) as $key) {
            Assert::assertIsArray($value, \sprintf('"%s" does not lead to a value.', $path));
            Assert::assertArrayHasKey($key, $value, \sprintf('"%s" does not exist.', $path));
            $value = $value[$key];
        }

        return $value;
    }

    public function string(string $path): string
    {
        $value = $this->at($path);
        Assert::assertIsString($value, $path);

        return $value;
    }

    public function int(string $path): int
    {
        $value = $this->at($path);
        Assert::assertIsInt($value, $path);

        return $value;
    }

    public function get(string $path): self
    {
        $value = $this->at($path);
        Assert::assertIsArray($value, $path);

        return new self($value);
    }

    /**
     * @return list<mixed>
     */
    public function column(string $path, string $key): array
    {
        $value = $this->at($path);
        Assert::assertIsArray($value, $path);

        return array_values(array_map(static function (mixed $row) use ($key): mixed {
            Assert::assertIsArray($row);

            return $row[$key] ?? null;
        }, $value));
    }

    /**
     * The first row of a list whose $key equals $value.
     */
    public function find(string $path, string $key, mixed $value): self
    {
        foreach ($this->get($path)->data as $row) {
            if (\is_array($row) && ($row[$key] ?? null) === $value) {
                return new self($row);
            }
        }

        Assert::fail(\sprintf('No row in "%s" has %s = %s.', $path, $key, var_export($value, true)));
    }

    public function with(string $path, mixed $value): self
    {
        return new self(self::replace($this->data, explode('.', $path), $value));
    }

    /**
     * @param array<mixed> $data
     * @param list<string> $keys
     *
     * @return array<mixed>
     */
    private static function replace(array $data, array $keys, mixed $value): array
    {
        $key = array_shift($keys);

        if ($keys === []) {
            $data[$key] = $value;

            return $data;
        }

        $child = $data[$key] ?? null;
        Assert::assertIsArray($child);
        $data[$key] = self::replace($child, $keys, $value);

        return $data;
    }
}
