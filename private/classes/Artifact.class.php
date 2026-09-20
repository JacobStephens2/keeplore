<?php

require_once dirname(__DIR__) . '/item_tags.php';

class Artifact extends DatabaseObject {

  static protected $table_name = 'games';
  static protected $db_columns = [
    'Access', 'Acq', 'Age', 'age_max', 'Av', 'BGG_Rat', 'Candidate', 'FavCt', 'FullTitle', 'id',
    'is_digital', 'is_in_secondary_collection', 'is_kept', 'is_physical',
    'MnP', 'MnT', 'MxP', 'MxT', 'OrigPlat', 'SS', 'System', 'Title',
    'to_get_rid_of', 'type', 'UsedRecUserCt', 'user_id', 'Wt', 'Yr'
  ];

  public $id;
  public $tags = [];

  public $Access;
  public $Acq;
  public $Age;
  public $age_max;
  public $Av;
  public $BGG_Rat;
  public $Candidate;
  public $FavCt;
  public $FullTitle;
  public $is_digital;
  public $is_in_secondary_collection;
  public $is_kept;
  public $is_physical;
  public $MnP;
  public $MnT;
  public $MxP;
  public $MxT;
  public $OrigPlat;
  public $SS;
  public $System;
  public $Title;
  public $to_get_rid_of;
  public $type;
  public $UsedRecUserCt;
  public $user_id;
  public $Wt;
  public $Yr;

  public static function list_artifacts($page = 1, $per_page = 50) {
    $page = max(1, (int) $page);
    $per_page = max(1, min(200, (int) $per_page));
    $offset = ($page - 1) * $per_page;

    $stmt = self::$database->prepare(
      "SELECT games.id, games.Title
       FROM games
       ORDER BY games.Title ASC
       LIMIT ? OFFSET ?"
    );
    $stmt->bind_param("ii", $per_page, $offset);
    $stmt->execute();
    $result = $stmt->get_result();
    $array = array();
    while($record = $result->fetch_assoc()) {
      $array[] = $record;
    }
    $stmt->close();
    return $array;
  }

  public static function list_artifacts_paginated($per_page = 50, $cursor = null) {
    $per_page = max(1, min(200, (int) $per_page));
    $fetch_limit = $per_page + 1;

    if ($cursor !== null) {
      $cursor = (int) $cursor;
      $stmt = self::$database->prepare(
        "SELECT games.id, games.Title
         FROM games
         WHERE games.id > ?
         ORDER BY games.id ASC
         LIMIT ?"
      );
      $stmt->bind_param("ii", $cursor, $fetch_limit);
    } else {
      $stmt = self::$database->prepare(
        "SELECT games.id, games.Title
         FROM games
         ORDER BY games.id ASC
         LIMIT ?"
      );
      $stmt->bind_param("i", $fetch_limit);
    }

    $stmt->execute();
    $result = $stmt->get_result();
    $array = [];
    while ($record = $result->fetch_assoc()) {
      $array[] = $record;
    }
    $stmt->close();

    $has_more = count($array) > $per_page;
    if ($has_more) {
      array_pop($array);
    }

    $next_cursor = null;
    if ($has_more && !empty($array)) {
      $last_item = end($array);
      $next_cursor = $last_item['id'];
    }

    return [
      'data' => $array,
      'next_cursor' => $next_cursor,
      'has_more' => $has_more,
    ];
  }

  public static function list_artifacts_by_user($user_id, $page = 1, $per_page = 50, $tag = '') {
    $user_id = (int) $user_id;
    $page = max(1, (int) $page);
    $per_page = max(1, min(200, (int) $per_page));
    $offset = ($page - 1) * $per_page;
    $filter = item_tag_user_filter($tag, $user_id);

    $stmt = self::$database->prepare(
      "SELECT games.id, games.Title
       FROM games
       WHERE games.user_id = ?" . $filter['sql'] . "
       ORDER BY games.Title ASC
       LIMIT ? OFFSET ?"
    );
    $types = 'i' . $filter['types'] . 'ii';
    $params = array_merge([$user_id], $filter['params'], [$per_page, $offset]);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $result = $stmt->get_result();
    $array = array();
    while($record = $result->fetch_assoc()) {
      $array[] = $record;
    }
    $stmt->close();
    return $array;
  }

  public static function list_artifacts_by_user_paginated($user_id, $per_page = 50, $cursor = null, $tag = '') {
    $user_id = (int) $user_id;
    $per_page = max(1, min(200, (int) $per_page));
    $fetch_limit = $per_page + 1;
    $filter = item_tag_user_filter($tag, $user_id);

    if ($cursor !== null) {
      $cursor = (int) $cursor;
      $stmt = self::$database->prepare(
        "SELECT games.id, games.Title
         FROM games
         WHERE games.user_id = ? AND games.id > ?" . $filter['sql'] . "
         ORDER BY games.id ASC
         LIMIT ?"
      );
      $types = 'ii' . $filter['types'] . 'i';
      $params = array_merge([$user_id, $cursor], $filter['params'], [$fetch_limit]);
      $stmt->bind_param($types, ...$params);
    } else {
      $stmt = self::$database->prepare(
        "SELECT games.id, games.Title
         FROM games
         WHERE games.user_id = ?" . $filter['sql'] . "
         ORDER BY games.id ASC
         LIMIT ?"
      );
      $types = 'i' . $filter['types'] . 'i';
      $params = array_merge([$user_id], $filter['params'], [$fetch_limit]);
      $stmt->bind_param($types, ...$params);
    }

    $stmt->execute();
    $result = $stmt->get_result();
    $array = [];
    while ($record = $result->fetch_assoc()) {
      $array[] = $record;
    }
    $stmt->close();

    $has_more = count($array) > $per_page;
    if ($has_more) {
      array_pop($array);
    }

    $next_cursor = null;
    if ($has_more && !empty($array)) {
      $last_item = end($array);
      $next_cursor = $last_item['id'];
    }

    return [
      'data' => $array,
      'next_cursor' => $next_cursor,
      'has_more' => $has_more,
    ];
  }

  public static function list_artifacts_by_query($query, $user_id, $page = 1, $per_page = 50, $tag = '') {
    $page = max(1, (int) $page);
    $per_page = max(1, min(200, (int) $per_page));
    $offset = ($page - 1) * $per_page;
    $user_id = (int) $user_id;
    $filter = item_tag_user_filter($tag, $user_id);

    $stmt = self::$database->prepare(
      "SELECT games.id, games.Title
       FROM games
       WHERE games.Title LIKE ?
       AND user_id = ?" . $filter['sql'] . "
       ORDER BY games.Title ASC
       LIMIT ? OFFSET ?"
    );
    $like_query = '%' . $query . '%';
    $types = 'si' . $filter['types'] . 'ii';
    $params = array_merge([$like_query, $user_id], $filter['params'], [$per_page, $offset]);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $result = $stmt->get_result();
    $array = array();
    while($record = $result->fetch_assoc()) {
      $array[] = $record;
    }
    $stmt->close();
    return $array;
  }

}

?>