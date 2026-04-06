<?php
namespace App\Cache;

use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;
use JsonSerializable;

abstract class Cache implements JsonSerializable
{

  private $key = "";

  private $sets = [];

  public function __construct()
  {
    $this->key = class_basename($this);
  }

  public function get($id)
  {
    $data = Redis::hget("$this->key", "{$id}");
    return new static(json_decode($data, true));
  }

  public function set($id, $data, $score = null)
  {
    $score = $score ?? $data['created_at'] ?? $id;
    Redis::hset("$this->key", "{$this->id($id)}", json_encode($data));

    // Add order ID to global sorted set for pagination
    Redis::zadd("$this->key:ids", $score, $this->id($id));

    foreach ($this->filterable() as $filter) {
      if (!isset($data[$filter])) {
        continue;
      }

      Redis::zadd("$this->key:by:$filter:{$data[$filter]}", $score, $this->id($id));
    }
  }

  public function delete($id)
  {
    $data = $this->get($this->id($id))->getAttributes();

    Redis::hdel($this->key, "{$this->id($id)}");
    Redis::zrem("$this->key:ids", $this->id($id));

    foreach ($this->filterable() as $filter) {
      if (!isset($data[$filter])) {
        continue;
      }
      Redis::zrem("$this->key:by:$filter:{$data[$filter]}", $this->id($id));
    }
  }

  public function update($id, $data, $score = null)
  {
    $this->delete($id);
    $this->set($id, $data, $score);
  }

  public function filter($filter = [])
  {
    if (!is_array($filter)) {
      return $this;
    }

    foreach ($filter as $key => $value) {
      if (method_exists($this, "filter" . ucfirst(Str::camel($key)))) {
        $this->{"filter" . ucfirst(Str::camel($key))}($value);
        continue;
      }

      if (!in_array($key, $this->filterable()) || empty($value)) {
        continue;
      }

      if (is_array($value)) {
        foreach ($value as $v) {
          $this->sets[$key][] = "$this->key:by:$key:$v";
        }
      } else {
        $this->sets[] = "$this->key:by:$key:$value";
      }
    }

    return $this;
  }

  public function all()
  {
    $clearable_keys = [];
    if (count($this->sets) > 0) {
      $keys = "$this->key:temp:" . uniqid();
      $temp_keys = [];
      foreach ($this->sets as $key => $set) {
        if (is_array($set)) {
          $temp_key = "$this->key:temp:" . uniqid();
          Redis::zUnionStore($temp_key, $set);
          $temp_keys[] = $temp_key;
          $clearable_keys[] = $temp_key;
          continue;
        }
        $temp_keys[] = $set;
      }
      Redis::zInterStore($keys, $temp_keys);
      $clearable_keys[] = $keys;
    } else {
      $keys = "$this->key:ids";
    }

    $ids = Redis::zrevrange($keys, 0, -1);

    if (count($clearable_keys) > 0) {
      Redis::del($clearable_keys);
    }

    return collect($ids)->map(function ($id) {
      return $this->get($id);
    });
  }

  public function paginate($per_page = 10, $page = null)
  {

    if (is_null($page)) {
      $page = request()->get('page', 1);
    }

    $start = ($page - 1) * $per_page;
    $end = $start + $per_page - 1;

    $clearable_keys = [];

    if (count($this->sets) > 0) {
      $keys = "$this->key:temp:" . uniqid();

      $temp_keys = [];

      foreach ($this->sets as $key => $set) {
        if (is_array($set)) {
          $temp_key = "$this->key:temp:" . uniqid();
          Redis::zUnionStore($temp_key, $set);
          $temp_keys[] = $temp_key;
          $clearable_keys[] = $temp_key;
          continue;
        }

        $temp_keys[] = $set;
      }
      Redis::zInterStore($keys, $temp_keys);
      $clearable_keys[] = $keys;
    } else {
      $keys = "$this->key:ids";
    }

    $total_count = Redis::zCard($keys);
    $keys = Redis::zrevrange($keys, $start, $end);
    $data = array_map(function ($id) {
      return $this->get($id);
    }, $keys);

    if (count($clearable_keys) > 0) {
      Redis::del($clearable_keys);
    }

    return (new LengthAwarePaginator($data, $total_count, $per_page, $page, ['path' => request()->url()]))->withQueryString();
  }

  public function count()
  {

    $clearable_keys = [];

    if (count($this->sets) > 0) {
      $keys = "$this->key:temp:" . uniqid();

      $temp_keys = [];

      foreach ($this->sets as $set) {
        if (is_array($set)) {
          $temp_key = "$this->key:temp:" . uniqid();
          Redis::zUnionStore($temp_key, $set);
          $temp_keys[] = $temp_key;
          $clearable_keys[] = $temp_key;
          continue;
        }

        $temp_keys[] = $set;
      }
      Redis::zInterStore($keys, $temp_keys);
      $clearable_keys[] = $keys;
    } else {
      $keys = "$this->key:ids";
    }

    $count = Redis::zCard($keys);

    if (count($clearable_keys) > 0) {
      Redis::del($clearable_keys);
    }

    return $count;
  }

  protected function id($id)
  {
    $r = '';

    while ($id > 0) {
      $r = chr(($id % 10) + 97) . $r;
      $id = (int) ($id / 10);
    }

    return $r;
  }

  public function setSets($sets)
  {
    array_push($this->sets, ...$sets);
  }

  public function key()
  {
    return $this->key;
  }

  public function searchables()
  {
    return [];
  }

  public function getAttributes()
  {
    return (array) $this->data;
  }

  public function filterable()
  {
    return [];
  }

  public function __get($name)
  {
    if ($this->casts()[$name] ?? false && $this->castsFunctions()[$this->casts()[$name]] ?? false) {
      return $this->castsFunctions()[$this->casts()[$name]]($this->data[$name] ?? null);
    }
    return $this->data[$name] ?? null;
  }

  public function __isset($name)
  {
    if ($this->casts()[$name] ?? false) {
      return isset($this->data[$name]);
    }
    return isset($this->data[$name]);
  }

  public function casts()
  {
    return [];
  }

  public function castsFunctions()
  {
    return [
      'datetime' => function ($value) {
        return $value ? \Illuminate\Support\Carbon::createFromTimestamp($value) : null;
      }
    ];
  }

  public function createSearchIndex()
  {
    $searchables = $this->searchables();
    $schema = [];

    foreach ($searchables as $field => $type) {
      $schema[] = $field;
      $schema[] = $type;
      $schema[] = 'SORTABLE';
    }

    Redis::executeRaw([
      'FT.CREATE',
      'idx:' . $this->key,
      'ON',
      'HASH',
      'PREFIX',
      '1',
      $this->key . ':',
      'SCHEMA',
      ...$schema
    ]);
  }

  public function dropSearchIndex()
  {
    Redis::rawCommand('FT.DROPINDEX', 'idx:' . $this->key);
  }

  public function flush()
  {
    Redis::del("$this->key:ids");

    $cursor = 0;
    do {
      $result = Redis::scan($cursor, 'MATCH', "*$this->key:*", 'COUNT', 1000);

      if ($result === false) {
        break;
      }

      if (array_key_exists(0, $result)) {
        $cursor = (int) $result[0];
        $keys = $result[1] ?? [];
      } else {
        $cursor = (int) ($result['cursor'] ?? 0);
        $keys = $result['keys'] ?? [];
      }

      if (!empty($keys)) {
        $prefix = config('database.redis.options.prefix');
        $keys = array_map(function ($key) use ($prefix) {
          if (strpos($key, $prefix) === 0) {
            return substr($key, strlen($prefix));
          }
          return $key;
        }, $keys);

        Redis::del($keys);
      }
    } while ($cursor != 0);
  }

  public function toArray()
  {
    return $this->data;
  }

  #[\ReturnTypeWillChange]
  public function jsonSerialize()
  {
    return $this->data;
  }
}