<?php
declare(strict_types=1);

namespace Ken\Models;

use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Contracts\Support\Jsonable;
use Illuminate\Database\Eloquent\Concerns\GuardsAttributes;
use Illuminate\Database\Eloquent\Concerns\HasAttributes;
use Illuminate\Database\Eloquent\Concerns\HasTimestamps;
use Illuminate\Database\Eloquent\Concerns\HidesAttributes;
use Illuminate\Database\Eloquent\MassAssignmentException;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;
use Ken\Enums\ProtocolType;
use Ken\Repositories\BaseRepository;
use Redislabs\Module\RedisJson\RedisJson;

class Model extends BaseRepository implements ModelInterface, Arrayable, Jsonable
{
    use HasAttributes,
        HidesAttributes,
        GuardsAttributes,
        HasTimestamps;

    public const ID_KEY = "{model}:%d";
    protected $incrementing = false;
    /**
     * Set default values to model attributes, this way whenever you add new columns
     * it will automatically fill all those newly added columns with default values
     *
     * @var array
     */
    protected $default = [];

    /**
     * Redis connection name as defined in config/database.php
     *
     * @var string
     */
    protected $connection;

    /**
     * If current model exists or not
     *
     * @var bool
     */
    protected $exists = false;

    /**
     * The name of the "created at" field.
     *
     * @var string|null
     */
    const CREATED_AT = 'created_at';

    /**
     * The name of the "updated at" field.
     *
     * @var string|null
     */
    const UPDATED_AT = 'updated_at';

    protected $primaryKey = "id";

    /**
     * Create a new Redis model instance.
     *
     * @param array $attributes
     * @return void
     */
    public function __construct(array $attributes = [])
    {
        $connect = 'default';
        if (!is_null($this->connection)) {
            $connect = $this->connection;
        }
        $this->setConnectionName($connect);
        $this->fill($attributes);
    }

    /**
     * @param string $connection
     */
    public function setConnectionName(string $connection)
    {
        $this->connection = $connection;
    }
    /**
     * @return string
     */
    public function getConnectionName(): string
    {
        return $this->connection;
    }

    /**
     * Fill the model with an array of attributes.
     *
     * @param array $attributes
     * @return $this
     *
     * @throws MassAssignmentException
     */
    public function fill(array $attributes)
    {
        $totallyGuarded = $this->totallyGuarded();
        foreach ($this->fillableFromArray($attributes) as $key => $value) {
            // The developers may choose to place some attributes in the "fillable" array
            // which means only those attributes may be set through mass assignment to
            // the model, and all others will just get ignored for security reasons.
            if ($this->isFillable($key)) {
                $this->setAttribute($key, $value);
            } elseif ($totallyGuarded) {
                throw new MassAssignmentException(sprintf(
                    'Add [%s] to fillable property to allow mass assignment on [%s].',
                    $key, get_class($this)
                ));
            }
        }

        // Fill fields with default values if not present in attributes
        foreach ($this->getDefault() as $key => $value) {
            if (!isset($this->attributes[$key])) {
                $this->setAttribute($key, $value);
            }
        }

        return $this;
    }

    /**
     * Provide list of all default values for model attributes
     * @return array
     */
    public function getDefault(): array
    {
        return $this->default;
    }

    /**
     * Get an attribute from the model.
     *
     * @param string $key
     * @return mixed
     */
    public function getAttribute($key)
    {
        if (!$key) {
            return null;
        }

        // If the attribute exists in the attribute array or has a "get" mutator we will
        // get the attribute's value. Otherwise, we will proceed as if the developers
        // are asking for a relationship's value. This covers both types of values.
        if (array_key_exists($key, $this->attributes) ||
            array_key_exists($key, $this->casts) ||
            $this->hasGetMutator($key) ||
            $this->isClassCastable($key)) {
            return $this->getAttributeValue($key);
        }
    }

    /**
     * Return new id for model by maintaining auto-increment in redis environment
     *
     * @return mixed
     */
    public function getConnection(): RedisJson
    {
        $protocol = env('REDIS_CLIENT',ProtocolType::PREDIS->value);
        $connectName = $this->getConnectionName();
        $connect = null;
        switch ($protocol){
            case ProtocolType::PREDIS->value:{
                $connect = RedisJson::createWithPredis(Redis::connection($connectName)->client());
                break;
            }
            case ProtocolType::PHP_REDIS->value:{
                $connect = RedisJson::createWithPhpRedis(Redis::connection($connectName)->client());
                break;
            }
        }
        return $connect;
    }
    /**
     * Get the value indicating whether the IDs are incrementing.
     *
     * @return bool
     */
    public function getIncrementing(): bool
    {
        return $this->incrementing;
    }

    /**
     * Set whether IDs are incrementing.
     *
     * @param bool $value
     * @return Model
     */
    public function setIncrementing(bool $value): Model
    {
        $this->incrementing = $value;

        return $this;
    }
    public function newModel($attributes = null): Model
    {
        return new static($attributes);
    }
    protected function getNextId()
    {
        $totalRecordsKey = 'total_' . Str::plural(rtrim($this->prefix(), ':'));
        $client = $this->getConnection()->getClient();
        if (!$client->exists($totalRecordsKey)) {
            $client->set($totalRecordsKey, 0);
        }

        return $client->incr($totalRecordsKey);
    }
    /**
     * Create new record in redis database using the provided attributes
     *
     * @param $attributes
     * @return $this
     */
    public function create($attributes)
    {
        // Combining all fields to auto-fill them with at least with null
        $allFields = $this->getFillable() + $this->getHidden() + $this->getGuarded();
        // fill all fields
        $attributes = collect($allFields)->unique()->mapWithKeys(function ($field) use ($attributes) {
            return [$field => $attributes[$field] ?? null];
        })->toArray();

        $attributes = $this->fill($attributes)->getAttributes();

        $newId = $this->getNextId();
        $attributes[$this->getKeyName()] = $newId;
        // Maintaining timestamps
        if ($this->usesTimestamps()) {
            $attributes[self::CREATED_AT] = now()->timestamp;
            $attributes[self::UPDATED_AT] = now()->timestamp;
        }
        $this->getConnection()->set($this->getKeyDb($newId), '$', $attributes);
        $this->exists = true;
        return $this->newModel($attributes);
    }
    /**
     * Update attributes in model
     * @param $attributes
     */
    public function update($attributes)
    {
        if ($this->usesTimestamps()) {
            $attributes[self::UPDATED_AT] = now()->timestamp;
        }

        $this->fill($attributes)->save();
    }
    /**
     * Update model with current attributes
     */
    public function save()
    {
        // Updating data
        $this->getConnection()->set($this->getKeyDb($this->attributes['id']), '$', $this->attributes);
        $this->exists = true;
    }
    public function delete()
    {
        $this->getConnection()->del($this->getKeyDb($this->attributes['id']));
        $this->exists = false;
    }

    public function toJson($options = 0)
    {
        // TODO: Implement toJson() method.
    }

    public function prefix(): string
    {
        return Str::snake(class_basename($this)) . ':';
    }
    /**
     * Convert the object into something JSON serializable.
     *
     * @return array
     */
    #[\ReturnTypeWillChange]
    public function jsonSerialize()
    {
        return $this->toArray();
    }
    /**
     * Convert the model instance to an array.
     *
     * @return array
     */
    public function toArray(): array
    {
        return $this->attributesToArray();
    }

    /**
     * Get the primary key.
     *
     * @return string
     */
    public function getKeyName(): string
    {
        return $this->primaryKey;
    }
    /**
     * Get the default foreign key name for the model.
     *
     * @return string
     */
    public function getForeignKey(): string
    {
        return Str::snake(class_basename($this)) . '_' . $this->getKeyName();
    }

    public function getKeyDb($newId): string
    {
        $database = env('REDIS_JSON_NAME') ?? 'database';
        return "$database:{$this->prefix()}:{$newId}";
    }

    public function rawQuery($command, ...$arguments)
    {
        return $this->getConnection()->raw($command, $arguments);
    }

    function model(): self
    {
        return $this;
    }
    /**
     * Dynamically access the user's attributes.
     *
     * @param string $key
     * @return mixed
     */
    public function __get($key)
    {
        if (!array_key_exists($key, $this->getAttributes())) {
            $this->setAttribute($key, null);
        }
        return $this->getAttribute($key);
    }

    /**
     * Dynamically set an attribute on the user.
     *
     * @param string $key
     * @param mixed $value
     * @return void
     */
    public function __set($key, $value)
    {
        $this->setAttribute($key, $value);


    }

    /**
     * Dynamically check if a value is set on the user.
     *
     * @param string $key
     * @return bool
     */
    public function __isset($key)
    {
        return isset($this->attributes[$key]);
    }

    /**
     * Dynamically unset a value on the user.
     *
     * @param string $key
     * @return void
     */
    public function __unset($key)
    {
        unset($this->attributes[$key]);
    }
}
