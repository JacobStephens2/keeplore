<?php

class DatabaseObject {

  static protected $database;
  static protected $table_name;
  static protected $columns = [];
  public $errors = [];

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

  static public function find_by_id_and_user_id($id, $user_id) {
    $stmt = self::$database->prepare(
      "SELECT * FROM " . static::$table_name . " WHERE id = ? AND user_id = ?"
    );
    $stmt->bind_param("ii", $id, $user_id);
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

  protected function validate() {
    $this->errors = [];

    // Add custom validations
    
    return $this->errors;
  }

  protected function create() {
    $this->validate();
    if(!empty($this->errors)) { return false; }

    $attributes = $this->attributes();
    $columns = array_keys($attributes);
    $values = array_values($attributes);
    $placeholders = array_fill(0, count($values), '?');
    $types = str_repeat('s', count($values));

    $sql = "INSERT INTO " . static::$table_name . " (";
    $sql .= join(', ', $columns);
    $sql .= ") VALUES (";
    $sql .= join(', ', $placeholders);
    $sql .= ")";

    $stmt = self::$database->prepare($sql);
    $stmt->bind_param($types, ...$values);
    $result = $stmt->execute();
    if($result) {
      $this->id = self::$database->insert_id;
    }
    $stmt->close();
    return $result;
  }

  protected function update() {
    $this->validate();
    if(!empty($this->errors)) { return false; }

    $attributes = $this->attributes();
    $columns = array_keys($attributes);
    $values = array_values($attributes);
    $set_pairs = array_map(function($col) { return "{$col} = ?"; }, $columns);
    $types = str_repeat('s', count($values)) . 'i';
    $values[] = $this->id;

    $sql = "UPDATE " . static::$table_name . " SET ";
    $sql .= join(', ', $set_pairs);
    $sql .= " WHERE id = ? LIMIT 1";

    $stmt = self::$database->prepare($sql);
    $stmt->bind_param($types, ...$values);
    $result = $stmt->execute();
    $stmt->close();
    return $result;
  }

  protected function update_by_user_id() {
    $this->validate();
    if(!empty($this->errors)) { return false; }

    // Check existence
    $check_stmt = self::$database->prepare(
      "SELECT id FROM " . static::$table_name . " WHERE id = ? AND user_id = ?"
    );
    $check_stmt->bind_param("ii", $this->id, $this->user_id);
    $check_stmt->execute();
    $check_result = $check_stmt->get_result();
    if ($check_result->num_rows === 0) {
      $check_stmt->close();
      return 'No record found.';
    }
    $check_stmt->close();

    $attributes = $this->attributes();
    $columns = array_keys($attributes);
    $values = array_values($attributes);
    $set_pairs = array_map(function($col) { return "{$col} = ?"; }, $columns);
    $types = str_repeat('s', count($values)) . 'ii';
    $values[] = $this->id;
    $values[] = $this->user_id;

    $sql = "UPDATE " . static::$table_name . " SET ";
    $sql .= join(', ', $set_pairs);
    $sql .= " WHERE id = ? AND user_id = ? LIMIT 1";

    $stmt = self::$database->prepare($sql);
    $stmt->bind_param($types, ...$values);
    $result = $stmt->execute();
    $stmt->close();
    return $result;
  }

  public function save() {
    // A new record will not have an ID yet
    if(isset($this->id)) {
      return $this->update();
    } else {
      return $this->create();
    }
  }

  public function save_by_user_id() {
    // A new record will not have an ID yet
    if(isset($this->id)) {
      return $this->update_by_user_id();
    } else {
      return $this->create();
    }
  }

  public function merge_attributes($args=[]) {
    foreach($args as $key => $value) {
      if(property_exists($this, $key) && !is_null($value)) {
        $this->$key = $value;
      }
    }
  }

  // Properties which have database columns, excluding ID
  public function attributes() {
    $attributes = [];
    foreach(static::$db_columns as $column) {
      if($column == 'id') { continue; }
      $attributes[$column] = $this->$column;
    }
    return $attributes;
  }

  protected function sanitized_attributes() {
    $sanitized = [];
    foreach($this->attributes() as $key => $value) {
      $sanitized[$key] = self::$database->escape_string($value);
    }
    return $sanitized;
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