<?php

namespace Ken\Repositories;

use Illuminate\Pagination\LengthAwarePaginator;
use Ken\Enums\DataType;
use Ken\Models\Model;

abstract class BaseRepository
{
    protected $model;
    protected $query = [];
    protected $limit = 0;
    protected $skip = 0;
    protected $queryString = "";
    protected $sort = [];
    const MAX_SIZE = 1000000;

    abstract function model(): Model;

    public function first(): Model|null
    {
        $args = [
            $this->getIndex(),
            $this->buildQuery(),
            "limit",
            0,
            1
        ];
        $this->buildSort($args);
        $this->restQuery();
        return $this->query($args)[0] ?? null;
    }

    public function where($field, $condition, $value)
    {
        $this->query[] = [$field, $condition, $value];
        return $this;
    }

    public function whereIn($field, array $value)
    {
        $this->query[] = [$field, 'in', $value];
        return $this;
    }

    protected function like($field, $value)
    {
        $this->query[] = [$field, 'like', $value];
        return $this;
    }

    public function get()
    {
        $args = [
            $this->getIndex(),
            !empty($this->buildQuery()) ? $this->buildQuery() : '*',
            "limit",
            0,
            self::MAX_SIZE
        ];
        $this->restQuery();
        $this->buildSort($args);
        $data = $this->query($args) ?? [];
        return collect($data);
    }

    public function limit($limit)
    {
        $this->limit = $limit;
        return $this;
    }

    public function skip($skip)
    {
        $this->skip = $skip;
        return $this;
    }

    public function sortBy($field, $asc = 'desc')
    {
        $this->sort[] = [$field, $asc];
        return $this;
    }

    public function paginate($perPage): LengthAwarePaginator
    {

//        $page = request()->get('updates',[])[0]['payload']['params'] ?? []; // livewire page
        $page = request('page',1); // livewire page
        $args = [
            $this->getIndex(),
            !empty($this->buildQuery()) ? $this->buildQuery() : '*',
            "limit",
            ($page - 1) * $perPage,
            $perPage
        ];
        $total = $this->count();
        $this->buildSort($args);
        $result = $this->query($args);

        return new LengthAwarePaginator($result, $total, $perPage, $page, [
            'path' => request()->url(),
            'query' => request()->query()
        ]);
    }

    public function count(): int
    {
        $count = [
            $this->getIndex(),
            !empty($this->buildQuery()) ? $this->buildQuery() : '*',
            "limit",
            0,
            0
        ];
        $count = $this->model()->getConnection()->raw("FT.SEARCH", ...$count);
        $this->restQuery();
        return $count['0'] ?? 0;
    }

    private function buildQuery(): string
    {
        $query = [];
        foreach ($this->query as $q) {
            list($field, $condition, $value) = $q;
            if ($value == '') {
                continue;
            }
            $query[] = $this->matchCondition($field, $condition, $this->redisReplace($value));
        }
        return implode(' ', $query);
    }

    private function buildSort(array &$query)
    {
        if (empty($this->sort)) {
            return $query;
        }
        if (count($this->sort) > 1){
            throw new \Exception("Redis is only allowed to sort 1 field");
        }
        if (!empty($this->sort)) {
            $query[] = "SORTBY";
            foreach ($this->sort as $sr) {
                if (empty($sr[0]) && empty($sr[1])) {
                    continue;
                }
                $query[] = "{$sr[0]}";
                $query[] = "{$sr[1]}";
            }
        }
        return $query;
    }

    private function restQuery()
    {
        $this->query = [];
    }

    private function matchCondition($field, $condition, $value)
    {

        $query = '';
        $type = $this->matchType($field);
        switch ($condition) {
//            case 'like':
//                $query = "@{$field}:*$value*";
//                break;
            case '=':
            {
                $query = $this->makeQuery($type, $field, $value);
                break;
            }
            case 'in':
            {
                if (!in_array($type, [DataType::TAG->value, DataType::INT->value])) {
                    return $query;
                }
                $query = $this->buildIn($type, $field, $value);
                break;
            }
            case '>':
            case '<':
            case '>=':
            case '<=':
            {
                $query = $this->buildRange($condition, $field, $value);
                break;
            }
        }
        return $query;
    }

    private function makeQuery($type, $field, $value)
    {
        switch ($type) {
            case DataType::TAG->value:
            {
                return "@{$field}:{" . $value . "}";
            }
            case DataType::INT->value:
            {
                return "@{$field}:[{$value} {$value}]";
            }
            case DataType::TEXT->value:{
                return  "@{$field}: {$value}";
            }
        }
    }

    private function buildRange($condition, $field, $value): string
    {
        $query = '';

        switch ($condition) {
            case '>':
                $query = "@$field:[({$value} +inf]";
                break;
            case '<':
                $query = "@$field:[-inf {$value}]";
                break;
        }
        return $query;
    }

    private function buildIn($type, $field, array $values): string
    {
        $query = '';

        switch ($type) {
            case DataType::TAG->value:
            {
                $imp = implode(" | ", $values);
                $query .= "@{$field}: {$imp}";
                break;
            }
            case DataType::INT->value:
            {
                foreach ($values as &$k) {
                    $k = $this->makeQuery(DataType::INT->value, $field, $k);
                }
                $imp = implode(" | ", $values);
                $query .= "({$imp})";
                break;
            }
        }

        return $query;
    }

    private function matchType($field)
    {
        $field = $this->model()->getFielDataType()[$field] ?? null;
        if ($field == null) {
            throw new \Exception("Field not found");
        }
        return $field['type'];
    }

    public function firstById($id): Model | null
    {
        $data = $this->model()->getConnection()->get($this->model()->getKeyDb($id));
        if (empty($data)) {
            return null;
        }
        return $this->model()->newModel($data);

    }

    private function query($args): array
    {
        $res = $this->model()->getConnection()->raw("FT.SEARCH", ...$args);
        unset($res[0]);
        $arr = [];
        $position = !empty($this->sort) ? 3 : 1;
        foreach ($res as $item) {
            if (is_array($item)) {
                $arr[] = $this->model()->newModel(json_decode($item[$position], true));
            }
        }
        $this->sort = [];
        return $arr;
    }

    public function getIndex()
    {
        $dbName = env('REDIS_JSON_NAME', 'database');
        $indexName = "{$dbName}-{$this->model()->prefix()}-idx";
        return "$indexName";
    }

    public function getQuery()
    {
        return $this->buildQuery();
    }


    protected function redisReplace($value)
    {
        $replacements = array(
            ',' => '\\,',
            '.' => '\\.',
            '<' => '\\<',
            '>' => '\\>',
            '{' => '\\{',
            '}' => '\\}',
            '[' => '\\[',
            ']' => '\\]',
            '"' => '\\"',
            "'" => "\\'",
            ':' => '\\:',
            ';' => '\\;',
            '!' => '\\!',
            '@' => '\\@',
            '#' => '\\#',
            '$' => '\\$',
            '%' => '\\%',
            '^' => '\\^',
            '&' => '\\&',
            '*' => '\\*',
            '(' => '\\(',
            ')' => '\\)',
            '-' => '\\-',
            '+' => '\\+',
            '=' => '\\=',
            '~' => '\\~',
        );

        return preg_replace_callback('/' . implode('|', array_map('preg_quote', array_keys($replacements))) . '/', function ($match) use ($replacements) {
            return $replacements[$match[0]];
        }, $value);
    }
}
