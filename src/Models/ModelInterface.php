<?php

namespace Ken\Models;

use Redislabs\Module\RedisJson\RedisJson;

interface ModelInterface
{
    public function prefix(): string;

    public function getConnection(): RedisJson;

    public function getKeyName(): string;
    public function create($attributes);
    public function getKeyDb($newId): string;
    public function rawQuery($command, ...$arguments);
}
