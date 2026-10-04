<?php

class DatabaseObject {

  static protected $database;
  static protected $table_name;

  static public function set_database($database) {
    self::$database = $database;
  }

  static public function find_by_sql($sql) {
    $result = self::$database->query($sql);
    if(!$result) {
      exit('Database query failed.');
    }

    // results into objects
    $object_array = [];
    while($record = $result->fetch_assoc()) {
      $object_array[] = static::instantiate($record);
    }

    $result->free();

    return $object_array;
  }

  static public function find_all($page = 0, $per_page = 0) {
    $sql = "SELECT * FROM " . static::$table_name;
    if ($per_page > 0) {
      $page = max(1, (int) $page);
      $per_page = max(1, min(200, (int) $per_page));
      $offset = ($page - 1) * $per_page;
      $sql .= " LIMIT " . (int) $per_page . " OFFSET " . (int) $offset;
    }
    return static::find_by_sql($sql);
  }

  static public function find_all_by_user_id($id) {
    $stmt = self::$database->prepare(
      "SELECT * FROM " . static::$table_name . " WHERE user_id = ?"
    );
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $result = $stmt->get_result();

    $object_array = [];
    while($record = $result->fetch_assoc()) {
      $object_array[] = static::instantiate($record);
    }
    $stmt->close();
    return $object_array;
  }

  static public function find_by_id($id) {
    $stmt = self::$database->prepare(
      "SELECT * FROM " . static::$table_name . " WHERE id = ?"
    );
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $result = $stmt->get_result();

    $object_array = [];
    while($record = $result->fetch_assoc()) {
      $object_array[] = static::instantiate($record);
    }
    $stmt->close();

    if(!empty($object_array)) {
      return array_shift($object_array);
    } else {
      return false;
    }
  }

  static protected function instantiate($record) {
    $object = new static;
    // Could manually assign values to properties
    // but automatically assignment is easier and re-usable
    foreach($record as $property => $value) {
      if(property_exists($object, $property)) {
        $object->$property = $value;
      }
    }
    return $object;
  }

  static public function find_paginated($per_page = 50, $cursor = null, $direction = 'next') {
    $per_page = max(1, min(200, (int) $per_page));
    $direction = ($direction === 'prev') ? 'prev' : 'next';
    $fetch_limit = $per_page + 1;

    if ($cursor !== null) {
      $cursor = (int) $cursor;
      if ($direction === 'next') {
        $sql = "SELECT * FROM " . static::$table_name . " WHERE id > ? ORDER BY id ASC LIMIT ?";
      } else {
        $sql = "SELECT * FROM " . static::$table_name . " WHERE id < ? ORDER BY id DESC LIMIT ?";
      }
      $stmt = self::$database->prepare($sql);
      $stmt->bind_param("ii", $cursor, $fetch_limit);
    } else {
      $sql = "SELECT * FROM " . static::$table_name . " ORDER BY id ASC LIMIT ?";
      $stmt = self::$database->prepare($sql);
      $stmt->bind_param("i", $fetch_limit);
    }

    $stmt->execute();
    $result = $stmt->get_result();

    $object_array = [];
    while ($record = $result->fetch_assoc()) {
      $object_array[] = static::instantiate($record);
    }
    $stmt->close();

    // For backward pagination, reverse to restore ascending order
    if ($direction === 'prev') {
      $object_array = array_reverse($object_array);
    }

    $has_more = count($object_array) > $per_page;

    if ($has_more) {
      if ($direction === 'next') {
        // Remove the extra item from the end
        array_pop($object_array);
      } else {
        // After reversing, extra item is at the beginning
        array_shift($object_array);
      }
    }

    $next_cursor = null;
    $prev_cursor = null;

    if (!empty($object_array)) {
      $last_item = end($object_array);
      $first_item = reset($object_array);

      if ($direction === 'next' && $has_more) {
        $next_cursor = $last_item->id;
      } elseif ($direction === 'next' && !$has_more) {
        $next_cursor = null;
      } elseif ($direction === 'prev') {
        $next_cursor = $last_item->id;
      }

      if ($cursor !== null) {
        $prev_cursor = $first_item->id;
      }

      // For forward with no cursor (first page), no prev
      if ($direction === 'next' && $cursor === null) {
        $prev_cursor = null;
      }
    }

    return [
      'data' => $object_array,
      'next_cursor' => $next_cursor,
      'prev_cursor' => $prev_cursor,
      'has_more' => $has_more,
    ];
  }

  static public function find_paginated_by_user_id($user_id, $per_page = 50, $cursor = null, $direction = 'next') {
    $user_id = (int) $user_id;
    $per_page = max(1, min(200, (int) $per_page));
    $direction = ($direction === 'prev') ? 'prev' : 'next';
    $fetch_limit = $per_page + 1;

    if ($cursor !== null) {
      $cursor = (int) $cursor;
      if ($direction === 'next') {
        $sql = "SELECT * FROM " . static::$table_name . " WHERE user_id = ? AND id > ? ORDER BY id ASC LIMIT ?";
      } else {
        $sql = "SELECT * FROM " . static::$table_name . " WHERE user_id = ? AND id < ? ORDER BY id DESC LIMIT ?";
      }
      $stmt = self::$database->prepare($sql);
      $stmt->bind_param("iii", $user_id, $cursor, $fetch_limit);
    } else {
      $sql = "SELECT * FROM " . static::$table_name . " WHERE user_id = ? ORDER BY id ASC LIMIT ?";
      $stmt = self::$database->prepare($sql);
      $stmt->bind_param("ii", $user_id, $fetch_limit);
    }

    $stmt->execute();
    $result = $stmt->get_result();

    $object_array = [];
    while ($record = $result->fetch_assoc()) {
      $object_array[] = static::instantiate($record);
    }
    $stmt->close();

    // For backward pagination, reverse to restore ascending order
    if ($direction === 'prev') {
      $object_array = array_reverse($object_array);
    }

    $has_more = count($object_array) > $per_page;

    if ($has_more) {
      if ($direction === 'next') {
        array_pop($object_array);
      } else {
        array_shift($object_array);
      }
    }

    $next_cursor = null;
    $prev_cursor = null;

    if (!empty($object_array)) {
      $last_item = end($object_array);
      $first_item = reset($object_array);

      if ($direction === 'next' && $has_more) {
        $next_cursor = $last_item->id;
      } elseif ($direction === 'prev') {
        $next_cursor = $last_item->id;
      }

      if ($cursor !== null) {
        $prev_cursor = $first_item->id;
      }

      if ($direction === 'next' && $cursor === null) {
        $prev_cursor = null;
      }
    }

    return [
      'data' => $object_array,
      'next_cursor' => $next_cursor,
      'prev_cursor' => $prev_cursor,
      'has_more' => $has_more,
    ];
  }


}

?>