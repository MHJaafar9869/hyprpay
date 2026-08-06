<?php

declare(strict_types=1);

namespace Hyprpay\Payments\Tests\Support;

use Illuminate\Contracts\Config\Repository;

/**
 * A minimal in-memory config repository for exercising ConfigCredentialResolver
 * without booting a Laravel application.
 */
final class ArrayConfig implements Repository
{
    /**
     * @param  array<string, mixed>  $items
     */
    public function __construct(private array $items = []) {}

    /**
     * Determine whether the given dotted configuration key exists.
     */
    public function has($key): bool
    {
        return data_get($this->items, $key) !== null;
    }

    /**
     * Read the value at the given dotted configuration key.
     *
     * @param  array<int, string>|string  $key
     * @param  mixed  $default
     * @return mixed
     */
    public function get($key, $default = null)
    {
        return data_get($this->items, $key, $default);
    }

    /**
     * Return every stored configuration item.
     *
     * @return array<string, mixed>
     */
    public function all(): array
    {
        return $this->items;
    }

    /**
     * Set the value at the given dotted configuration key.
     *
     * @param  array<string, mixed>|string  $key
     * @param  mixed  $value
     */
    public function set($key, $value = null): void
    {
        data_set($this->items, $key, $value);
    }

    /**
     * Prepend a value onto an array configuration value.
     *
     * @param  mixed  $value
     */
    public function prepend($key, $value): void
    {
        $array = $this->get($key, []);
        array_unshift($array, $value);
        $this->set($key, $array);
    }

    /**
     * Push a value onto an array configuration value.
     *
     * @param  mixed  $value
     */
    public function push($key, $value): void
    {
        $array = $this->get($key, []);
        $array[] = $value;
        $this->set($key, $array);
    }
}
