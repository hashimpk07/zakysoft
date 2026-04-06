<?php
namespace App\Cache;

use App\User;
use Illuminate\Support\Facades\Redis;

class Order extends Cache {

  protected $data = [];

  public function __construct($data = []) {
    $this->data = $data;

    parent::__construct();
  }

  public function __toString()
  {
    return json_encode($this->data);
  }

  public function withUserPermission() {
    $user = auth()->user();

    if($user->data_permission == User::DATA_PERMISSION_ALL_ACCESS_BASED) {
      return $this;
    }

    $sets = [];
    $permission = $user->dataPermission();

    $permission_ids = $permission->pluck('id');
    

    if ($user->data_permission == User::DATA_PERMISSION_BRANCH_BASED) {
      $key = 'shop_id';
    }

    if ($user->data_permission == User::DATA_PERMISSION_ZONE_BASED) {
      $key = 'zone_id';
    }

    if ($user->data_permission == User::DATA_PERMISSION_REGION_BASED) {
      $key = 'region_id';
    }

    if ($user->data_permission == User::DATA_PERMISSION_CLIENT_BASED) {
      $key = 'client_id';
    }

    foreach($permission_ids as $v) {
      $sets[] = "{$this->key()}:by:$key:$v";
    }

    $this->setSets([$sets]);

    return $this;
  }

  public function renderProgressBar() {
    $progress = [
      'width' => 10,
      'color' => 'bg-primary',
      'text' => '0%',
    ];

    if ($this->delivery_start_at && !now()->greaterThan($this->delivery_finish_at) && now()->greaterThan($this->delivery_start_at)) {
      $currentDifference = $this->delivery_start_at->floatDiffInSeconds(now());
      $difference = $this->delivery_start_at->floatDiffInSeconds($this->delivery_finish_at);
      $progress = (int) (100 / ($difference / $currentDifference));

      $progress = [
          'width' => $progress,
          'color' => ($progress > 75 ? 'bg-secondary' : $progress > 25) ? 'bg-info' : 'bg-success',
          'text' => $progress . ' %',
      ];
    }

    if (now()->greaterThan($this->delivery_finish_at)) {
      $progress = [
          'width' => 100,
          'color' => 'bg-danger',
          'text' => 'Delayed',
      ];
    }

    return '<div class="progress">
      <div class="progress-bar ' . $progress['color'] . '" role="progressbar" style="width: ' . $progress['width'] . '%" aria-valuenow="' . $progress['width'] . '" aria-valuemin="0" aria-valuemax="100">' . $progress['text'] . '</div>
    </div>';
  }

  public function filterSearch($value) {
    if(!$value) {
      return;
    }

    $search_key = Redis::keys("{$this->key()}:by:client_order_id:$value*");
    if(count($search_key) == 0) {
      return;
    }

    $search_key = array_map(function($v) {
      return str_replace(config('database.redis.options.prefix'), "", $v);
    }, $search_key);

    $this->setSets([$search_key]);
  }

  public function searchables() {
    return [
      "client_order_id" => "TEXT",
    ];
  }

  public function filterable() {
    return [
      "id",
      "client_id",
      "client_order_id",
      "area_id",
      "zone_id",
      "region_id",
      "shop_id",
      "captain_id",
      "status_id",
      "auto_assignable",
      "has_open_complaint",
      "delivery_type",
    ];
  }

  public function casts() {
    return [
      'delivery_date' => 'datetime',
      'delivery_start_at' => 'datetime',
      'delivery_finish_at' => 'datetime',
      'dispatch_after' => 'datetime',
      'created_at' => 'datetime',
    ];
  }
}