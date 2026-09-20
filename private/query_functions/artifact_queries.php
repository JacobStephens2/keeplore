<?php

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception;

require_once dirname(__DIR__) . '/item_tags.php';

  function compute_artifact_use_by_status($artifact_id, $user_id) {
    global $db;
    $stmt = mysqli_prepare(
      $db,
      "SELECT
        games.Acq,
        games.interaction_frequency_days,
        (SELECT MAX(uses.use_date) FROM uses WHERE uses.artifact_id = games.id) AS most_recent_use,
        (SELECT MAX(responses.PlayDate) FROM responses WHERE responses.Title = games.id) AS most_recent_response
      FROM games
      WHERE games.id = ? AND games.user_id = ?
      LIMIT 1"
    );
    mysqli_stmt_bind_param($stmt, "ii", $artifact_id, $user_id);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $row = mysqli_fetch_assoc($result);
    mysqli_stmt_close($stmt);

    if (!$row) {
      return ['use_by_date' => null, 'most_recent_use_date' => null, 'is_overdue' => false];
    }

    $interval_stmt = mysqli_prepare($db, "SELECT default_use_interval FROM users WHERE id = ?");
    mysqli_stmt_bind_param($interval_stmt, "i", $user_id);
    mysqli_stmt_execute($interval_stmt);
    $interval_row = mysqli_fetch_assoc(mysqli_stmt_get_result($interval_stmt));
    mysqli_stmt_close($interval_stmt);
    $default_interval = (float) ($interval_row['default_use_interval'] ?? (defined('DEFAULT_USE_INTERVAL') ? DEFAULT_USE_INTERVAL : 90));

    $this_interval = $row['interaction_frequency_days'] !== null
      ? (float) $row['interaction_frequency_days']
      : $default_interval;

    $most_recent_raw = null;
    if ($row['most_recent_use'] !== null && $row['most_recent_response'] !== null) {
      $most_recent_raw = strtotime($row['most_recent_use']) >= strtotime($row['most_recent_response'])
        ? $row['most_recent_use'] : $row['most_recent_response'];
    } else {
      $most_recent_raw = $row['most_recent_use'] ?? $row['most_recent_response'];
    }
    $most_recent_date = $most_recent_raw !== null ? substr($most_recent_raw, 0, 10) : null;

    date_default_timezone_set('America/New_York');
    $acq = new DateTime(substr($row['Acq'], 0, 10));
    $now = new DateTime(date('Y-m-d'));

    if ($most_recent_date === null) {
      $base = clone $acq;
      $hours = (int) ($this_interval * 24);
    } else {
      $recent = new DateTime($most_recent_date);
      if ($recent < $acq) {
        $base = clone $acq;
        $hours = (int) ($this_interval * 24);
      } else {
        $base = clone $recent;
        $hours = (int) ($this_interval * 2 * 24);
      }
    }
    $use_by = $base->add(DateInterval::createFromDateString("$hours hours"));

    return [
      'use_by_date' => $use_by->format('Y-m-d'),
      'most_recent_use_date' => $most_recent_date,
      'is_overdue' => $use_by < $now,
    ];
  }

  function set_artifact_to_get_rid_of($artifact_id, $value) {
    global $db;
    $user_id = (int) $_SESSION['user_id'];
    $artifact_id = (int) $artifact_id;
    $value = (int) $value;
    $stmt = mysqli_prepare($db, "UPDATE games SET to_get_rid_of = ? WHERE id = ? AND user_id = ? LIMIT 1");
    mysqli_stmt_bind_param($stmt, "iii", $value, $artifact_id, $user_id);
    $result = mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);
    return $result;
  }

  // Defer an artifact for $days days by setting snoozed_until to that future
  // date. While snoozed_until is in the future the artifact is hidden from the
  // dashboard "Most past due" priority queue. Returns the snooze-until date
  // (Y-m-d) on success, or false on failure.
  function snooze_artifact($artifact_id, $days) {
    global $db;
    $user_id = (int) $_SESSION['user_id'];
    $artifact_id = (int) $artifact_id;
    $days = max(1, (int) $days);
    $snooze_until = (new DateTime('today'))
      ->add(DateInterval::createFromDateString("$days days"))
      ->format('Y-m-d');
    $stmt = mysqli_prepare($db, "UPDATE games SET snoozed_until = ? WHERE id = ? AND user_id = ? LIMIT 1");
    mysqli_stmt_bind_param($stmt, "sii", $snooze_until, $artifact_id, $user_id);
    $result = mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);
    return $result ? $snooze_until : false;
  }

  function find_artifacts_to_get_rid_of() {
    global $db;
    $user_id = (int) $_SESSION['user_id'];
    $stmt = mysqli_prepare($db, "SELECT games.id, games.Title, games.Acq, games.interaction_frequency_days,
        types.objectType AS type,
        games.to_get_rid_of,
        CASE
          WHEN MAX(uses.use_date) IS NULL THEN MAX(responses.PlayDate)
          WHEN MAX(uses.use_date) < MAX(responses.PlayDate) THEN MAX(responses.PlayDate)
          ELSE MAX(uses.use_date)
        END AS MostRecentUseOrResponse
      FROM games
        LEFT JOIN responses ON games.id = responses.Title
        LEFT JOIN uses ON games.id = uses.artifact_id
        LEFT JOIN types ON games.type_id = types.id
      GROUP BY games.id, games.Title, games.Acq, games.interaction_frequency_days, types.objectType, games.user_id, games.to_get_rid_of
      HAVING games.user_id = ? AND games.to_get_rid_of = 1
      ORDER BY games.Title ASC");
    mysqli_stmt_bind_param($stmt, "i", $user_id);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    confirm_result_set($result);
    return $result;
  }

  function find_artifacts_by_user() {
    global $db;

    $user_id = (int) $_SESSION['user_id'];
    $stmt = mysqli_prepare($db, "SELECT games.id, games.Title, games.is_kept, games.Acq FROM games WHERE user_id = ? ORDER BY games.Acq DESC");
    mysqli_stmt_bind_param($stmt, "i", $user_id);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    confirm_result_set($result);
    return $result;
  }
  function find_all_board_artifacts() {
    global $db;

    $sql = "SELECT * FROM games ";
    $sql .= "WHERE type = 'board-game' ";
    $sql .= "ORDER BY is_kept DESC, Acq DESC";
    $result = mysqli_query($db, $sql);
    confirm_result_set($result);
    return $result;
  }

  function find_artifacts_by_user_id($kept, $type, $interval, $sweetSpot = '', $tag = '') {
    global $db;

    $interval = (int)$interval;
    $interval_double = (int)($interval * 2);

    $params = [];
    $param_types = '';

    $sql = "SELECT
        games.Title,
        games.mnp,
        games.mxp,
        games.mnt,
        games.mxt,
        games.Candidate,
        games.UsedRecUserCt,
        games.ss,
        games.id,
        games.is_kept,
        games.is_in_secondary_collection,
        types.objectType AS type,
        games.user_id,
        games.type_id,
        DATE(MAX(responses.PlayDate)) AS MaxPlay,
        DATE(MAX(uses.use_date)) AS MaxUse,
        CASE
          WHEN
            MAX(responses.PlayDate) < games.Acq
            THEN DATE_ADD(games.Acq, INTERVAL " . $interval . " DAY)
          WHEN
            MAX(responses.PlayDate) IS NULL
            THEN DATE_ADD(games.Acq, INTERVAL " . $interval . " DAY)
          ELSE
            DATE_ADD(MAX(responses.PlayDate), INTERVAL " . $interval_double . " DAY)
          END UseBy,
        games.Acq,
        games.is_kept
    FROM
        games
    LEFT JOIN responses ON games.id = responses.Title
    LEFT JOIN uses ON games.id = uses.artifact_id
    LEFT JOIN types ON games.type_id = types.id
    GROUP BY
        games.Acq,
        games.Title,
        games.is_kept,
        games.mnp,
        games.mxp,
        games.ss,
        games.type,
        games.id
    HAVING
        games.user_id = ? ";

        $params[] = $_SESSION['user_id'];
        $param_types .= 's';

        if (strlen($sweetSpot) > 0) {
          $sql .= " AND games.ss LIKE ? ";
          $params[] = '%' . $sweetSpot . '%';
          $param_types .= 's';
          $sql .= " AND games.ss NOT LIKE ? ";
          $params[] = '%1' . $sweetSpot . '%';
          $param_types .= 's';
          $sql .= " AND games.ss NOT LIKE ? ";
          $params[] = '%2' . $sweetSpot . '%';
          $param_types .= 's';
          $sql .= " AND games.ss NOT LIKE ? ";
          $params[] = '%3' . $sweetSpot . '%';
          $param_types .= 's';
          $sql .= " AND games.ss NOT LIKE ? ";
          $params[] = '%' . $sweetSpot . '0%';
          $param_types .= 's';
          $sql .= " AND games.ss NOT LIKE ? ";
          $params[] = '%' . $sweetSpot . '1%';
          $param_types .= 's';
          $sql .= " AND games.ss NOT LIKE ? ";
          $params[] = '%' . $sweetSpot . '2%';
          $param_types .= 's';
          $sql .= " AND games.ss NOT LIKE ? ";
          $params[] = '%' . $sweetSpot . '3%';
          $param_types .= 's';
          $sql .= " AND games.ss NOT LIKE ? ";
          $params[] = '%' . $sweetSpot . '4%';
          $param_types .= 's';
        }

        if (isset($type) && $type != [] && $type != '1') {
          $placeholders = implode(', ', array_fill(0, count($type), '?'));
          $sql .= " AND games.type_id IN ( " . $placeholders . ") ";
          foreach($type as $type_name => $type_id) {
            $params[] = $type_id;
            $param_types .= 's';
          }
        }

        // Kept filter means kept only: the single kept predicate on the new
        // column. Format flags never affect membership.
        if ( $kept == 'yes') {
          $sql .= " AND games.is_kept = 1 ";
        } elseif ( $kept == 'no' ) {
          $sql .= " AND games.is_kept = 0 ";
        } elseif ( $kept == 'secondary_only' ) {
          $sql .= " AND games.is_in_secondary_collection = 1 ";
        }

        $tag_filter = item_tag_user_filter($tag, $_SESSION['user_id']);
        $sql .= $tag_filter['sql'];
        if ($tag_filter['types'] !== '') {
          $param_types .= $tag_filter['types'];
          foreach ($tag_filter['params'] as $tag_param) {
            $params[] = $tag_param;
          }
        }

    $sql .= "
        ORDER BY
        UseBy DESC,
        MaxPlay DESC,
        Acq DESC,
        games.is_kept DESC,
        id ASC
    ";
    $stmt = mysqli_prepare($db, $sql);
    mysqli_stmt_bind_param($stmt, $param_types, ...$params);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    confirm_result_set($result);
    return $result;
  }

  function find_sweet_spots_by_artifact_id($artifact_id) {
    global $db;

    $stmt = mysqli_prepare($db, "SELECT
      sweetspots.id AS id,
      games.Title AS Title,
      sweetspots.SwS AS SwS
      FROM sweetspots
      JOIN games ON games.id = sweetspots.Title
      WHERE sweetspots.Title = ?
      ORDER BY games.Title ASC
    ");
    mysqli_stmt_bind_param($stmt, "i", $artifact_id);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    return $result;
  }

  function find_uses_by_artifact_id($artifact_id) {
    global $db;

    $stmt = mysqli_prepare($db, "SELECT
      uses.id,
      uses.use_date,
      uses.note,
      GROUP_CONCAT(DISTINCT CONCAT(players.FirstName, ' ', players.LastName)
        ORDER BY players.FirstName SEPARATOR ', ') AS players
      FROM uses
      LEFT JOIN uses_players ON uses_players.use_id = uses.id
      LEFT JOIN players ON players.id = uses_players.player_id
      WHERE uses.artifact_id = ?
      GROUP BY uses.id, uses.use_date, uses.note
      ORDER BY uses.use_date DESC,
      uses.id DESC
    ");
    mysqli_stmt_bind_param($stmt, "i", $artifact_id);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    return $result;
  }

  function find_artifact_by_id($id) {
    global $db;

    $stmt = mysqli_prepare($db,
      "SELECT types.objectType AS type_name, games.*
      FROM games
      LEFT JOIN types ON games.type_id = types.id
      WHERE games.id = ?"
    );
    mysqli_stmt_bind_param($stmt, "i", $id);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);

    $subject = mysqli_fetch_assoc($result);
    mysqli_stmt_close($stmt);

    return $subject; // returns an assoc. array
  }

  function update_artifact($artifact) {
    global $db;

    $errors = validate_artifact($artifact);
    if(!empty($errors)) {
      return $errors;
    }

    $type_id = (int) $artifact['type'];
    $type_name = get_type_name($type_id);

    $to_get_rid_of = isset($artifact['to_get_rid_of']) ? (int) $artifact['to_get_rid_of'] : 0;

    $kept = normalize_kept_value($artifact['is_kept']);
    $secondary = normalize_secondary_membership($artifact['is_in_secondary_collection'] ?? null);
    $digital = normalize_format_flag($artifact['is_digital'] ?? null);
    $physical = normalize_format_flag($artifact['is_physical'] ?? null);
    $year = normalize_artifact_year($artifact['Yr'] ?? null);

    $stmt = mysqli_prepare($db,
      "UPDATE games SET
        Title=?, is_kept=?, Acq=?, Candidate=?, UsedRecUserCt=?,
        type_id=?, type=?, SS=?, Notes=?, CandidateGroupDate=?,
        MnT=?, MxT=?, Age=?, Yr=?, is_in_secondary_collection=?, MnP=?, MxP=?,
        interaction_frequency_days=?, to_get_rid_of=?,
        is_digital=?, is_physical=?
      WHERE id=?
      LIMIT 1"
    );
    mysqli_stmt_bind_param($stmt, "sisssissssssssisssissi",
      $artifact['Title'], $kept, $artifact['Acq'],
      $artifact['Candidate'], $artifact['UsedRecUserCt'],
      $type_id, $type_name, $artifact['SS'], $artifact['Notes'],
      $artifact['CandidateGroupDate'], $artifact['MnT'], $artifact['MxT'],
      $artifact['age'], $year, $secondary,
      $artifact['MnP'], $artifact['MxP'], $artifact['interaction_frequency_days'],
      $to_get_rid_of,
      $digital,
      $physical,
      $artifact['id']
    );
    $result = mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);

    if($result) {
      return true;
    } else {
      echo mysqli_error($db);
      db_disconnect($db);
      exit;
    }

  }

  function normalize_artifact_year($year) {
    if ($year === null) {
      return null;
    }
    $trimmed = trim((string) $year);
    return $trimmed === '' ? null : $trimmed;
  }

  function validate_artifact($artifact) {
    $errors = [];

    // Title
    if(is_blank($artifact['Title'])) {
      $errors[] = "Title cannot be blank.";
    } elseif(!has_length($artifact['Title'], ['min' => 2, 'max' => 255])) {
      $errors[] = "Title must be between 2 and 255 characters.";
    }

    // is_kept
    $visible_str = (string) ($artifact['is_kept'] ?? '');
    if(!has_inclusion_of($visible_str, ["0","1"])) {
      $errors[] = "Kept must be true or false.";
    }

    // Numeric fields must be valid integers
    $numeric_fields = ['MnT' => 'Minimum Time', 'MxT' => 'Maximum Time',
                       'MnP' => 'Minimum User Count', 'MxP' => 'Maximum User Count'];
    foreach($numeric_fields as $field => $label) {
      if(isset($artifact[$field]) && $artifact[$field] !== '' && !is_numeric($artifact[$field])) {
        $errors[] = "{$label} must be a number.";
      }
    }

    // MnT <= MxT and MnP <= MxP range checks
    if(isset($artifact['MnT']) && isset($artifact['MxT'])
       && is_numeric($artifact['MnT']) && is_numeric($artifact['MxT'])
       && (int)$artifact['MnT'] > (int)$artifact['MxT']) {
      $errors[] = "Minimum Time cannot exceed Maximum Time.";
    }
    if(isset($artifact['MnP']) && isset($artifact['MxP'])
       && is_numeric($artifact['MnP']) && is_numeric($artifact['MxP'])
       && (int)$artifact['MnP'] > (int)$artifact['MxP']) {
      $errors[] = "Minimum User Count cannot exceed Maximum User Count.";
    }

    // Age must be non-negative
    if(isset($artifact['age']) && $artifact['age'] !== '' && $artifact['age'] !== 0) {
      if(!is_numeric($artifact['age']) || (int)$artifact['age'] < 0) {
        $errors[] = "Minimum Age must be a non-negative number.";
      }
    }

    if (isset($artifact['Yr']) && $artifact['Yr'] !== '') {
      if (!preg_match('/^\d{1,4}$/', (string) $artifact['Yr'])) {
        $errors[] = "Year must be a 1 to 4 digit number.";
      }
    }

    // Acquisition date format
    if(isset($artifact['Acq']) && !empty($artifact['Acq'])) {
      $date = DateTime::createFromFormat('Y-m-d', $artifact['Acq']);
      if(!$date || $date->format('Y-m-d') !== $artifact['Acq']) {
        $errors[] = "Tracking Start Date must be a valid date (YYYY-MM-DD).";
      }
    }

    // Interaction frequency must be positive
    if(isset($artifact['interaction_frequency_days']) && $artifact['interaction_frequency_days'] !== '') {
      if(!is_numeric($artifact['interaction_frequency_days']) || (float)$artifact['interaction_frequency_days'] <= 0) {
        $errors[] = "Interaction Frequency must be a positive number.";
      }
    }

    // Type must be a valid integer
    if(isset($artifact['type']) && $artifact['type'] !== '') {
      if(!is_numeric($artifact['type']) || (int)$artifact['type'] < 0) {
        $errors[] = "Type must be a valid selection.";
      }
    }

    return $errors;
  }

  function insert_artifact($artifact) {
    global $db;

    $errors = validate_artifact($artifact);
    if(!empty($errors)) {
      return $errors;
    }

    $type_id = $artifact['type'];
    $type_name = get_type_name($type_id);

    $kept = normalize_kept_value($artifact['is_kept']);
    $secondary = normalize_secondary_membership($artifact['is_in_secondary_collection'] ?? null);
    $digital = normalize_format_flag($artifact['is_digital'] ?? null);
    $physical = normalize_format_flag($artifact['is_physical'] ?? null);
    $year = normalize_artifact_year($artifact['Yr'] ?? null);

    $sql = "INSERT INTO games (
        Title,
        Notes,
        Acq,
        type_id,
        type,
        is_kept,
        Candidate,
        CandidateGroupDate,
        UsedRecUserCt,
        SS,
        MnT,
        MxT,
        Age,
        Yr,
        MnP,
        MxP,
        user_id,
        interaction_frequency_days,
        is_in_secondary_collection,
        is_digital,
        is_physical
      ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ";
    $stmt = mysqli_prepare($db, $sql);
    mysqli_stmt_bind_param($stmt, 'sssssssssssssssssssss',
      $artifact['Title'],
      $artifact['Notes'],
      $artifact['Acq'],
      $type_id,
      $type_name,
      $kept,
      $artifact['Candidate'],
      $artifact['CandidateGroupDate'],
      $artifact['UsedRecUserCt'],
      $artifact['SS'],
      $artifact['MnT'],
      $artifact['MxT'],
      $artifact['age'],
      $year,
      $artifact['MnP'],
      $artifact['MxP'],
      $_SESSION['user_id'],
      $artifact['interaction_frequency_days'],
      $secondary,
      $digital,
      $physical
    );
    $result = mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);
    // For INSERT statements, $result is true/false

    if($result) {
      return true;
    } else {
      // INSERT failed
      echo mysqli_error($db);
      db_disconnect($db);
      exit;
    }
  }

  function delete_artifact($id) {
    global $db;

    $user_id = isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null;
    delete_item_tags_for_artifact($db, (int) $id, $user_id);

    $sql = "DELETE FROM games WHERE id=? LIMIT 1";
    $stmt = mysqli_prepare($db, $sql);
    mysqli_stmt_bind_param($stmt, "s", $id);
    $result = mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);

    // For DELETE statements, $result is true/false
    if($result) {
      return true;
    } else {
      // DELETE failed
      echo mysqli_error($db);
      db_disconnect($db);
      exit;
    }
  }

  function list_artifacts() {
    global $db;
    $sql = "SELECT ";
    $sql .= "games.id, ";
    $sql .= "games.Title ";
    $sql .= "FROM games ";
    $sql .= "ORDER BY games.Title ASC";
    $result = mysqli_query($db, $sql);
    confirm_result_set($result);
    return $result;
  }

  function list_artifacts_by_query($query) {
    global $db;
    $sql = "SELECT games.id, games.Title FROM games WHERE games.Title LIKE ? ORDER BY games.Title ASC";
    $like_param = '%' . $query . '%';
    $stmt = mysqli_prepare($db, $sql);
    mysqli_stmt_bind_param($stmt, "s", $like_param);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    confirm_result_set($result);
    return $result;
  }

  function list_users_by_query($query) {
    global $db;
    $sql = "SELECT players.id, players.FirstName, players.LastName FROM players WHERE players.FirstName LIKE ? ORDER BY players.FirstName ASC, LastName ASC";
    $like_param = '%' . $query . '%';
    $stmt = mysqli_prepare($db, $sql);
    mysqli_stmt_bind_param($stmt, "s", $like_param);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    confirm_result_set($result);
    return $result;
  }

  function use_by($type, $interval, $sweetSpot, $minimumAge, $shelfSort, $user = null, $hideSnoozed = false) {

    if ($user === null && isset($_SESSION['user_id'])) {
      $user = $_SESSION['user_id'];
    }

    global $db;

    $params = [];
    $param_types = '';

    // Pre-aggregate the most recent use per artifact in a derived table so
    // the main query stays at one row per game (no GROUP BY blowup from
    // joining many uses rows). The `responses` table is no longer joined
    // — the responses→uses migration moved every PlayDate row into uses
    // with use_date >= PlayDate, so MAX(uses.use_date) equals what the
    // old CASE expression returned.
    $sql =
      "SELECT
        games.Title,
        games.mnp,
        games.mxp,
        games.mnt,
        games.mxt,
        games.Candidate,
        games.UsedRecUserCt,
        games.ss,
        games.id,
        types.objectType AS type,
        games.user_id,
        games.age,
        games.type_id,
        games.is_in_secondary_collection,
        recent.MostRecentUse AS MostRecentUseOrResponse,
        games.Acq,
        games.is_kept,
        games.interaction_frequency_days,
        games.to_get_rid_of,
        games.snoozed_until
      FROM games
        LEFT JOIN (
          SELECT artifact_id, MAX(use_date) AS MostRecentUse
          FROM uses
          GROUP BY artifact_id
        ) recent ON recent.artifact_id = games.id
        LEFT JOIN types ON games.type_id = types.id
      WHERE games.user_id = ?
      ";

      $params[] = $user;
      $param_types .= 's';

      $sql .= " AND (games.to_get_rid_of = 0 OR games.to_get_rid_of IS NULL) ";

      if ($hideSnoozed) {
        $sql .= " AND (games.snoozed_until IS NULL OR games.snoozed_until <= CURDATE()) ";
      }

      if ($shelfSort == 'yes') {
        $sql .= " AND (games.is_kept = 1 OR games.is_in_secondary_collection = 1) ";
      } else {
        $sql .= " AND games.is_kept = 1 ";
      }

      if ($sweetSpot !== '') {
        $sql .= "AND
          (
            games.ss LIKE ?
            OR games.ss LIKE ?
            OR games.ss LIKE ?
            OR games.ss LIKE ?
            OR games.ss LIKE ?
            OR games.ss LIKE ?
            OR games.ss LIKE ?
          )
        ";
        $params[] = $sweetSpot;
        $param_types .= 's';
        $params[] = $sweetSpot . ' %';
        $param_types .= 's';
        $params[] = '%0' . $sweetSpot . '%';
        $param_types .= 's';
        $params[] = '%,' . $sweetSpot;
        $param_types .= 's';
        $params[] = '%,' . $sweetSpot . ',%';
        $param_types .= 's';
        $params[] = '%, ' . $sweetSpot;
        $param_types .= 's';
        $params[] = '%, ' . $sweetSpot . ',%';
        $param_types .= 's';
      }

      if ($minimumAge !== '' && $minimumAge !== 0 && $minimumAge !== '0') {
        $sql .= " AND games.age >= ? ";
        $params[] = $minimumAge;
        $param_types .= 's';
      }

      if (gettype($type) === 'array') {
        if (count($type) > 0) {
          $placeholders = implode(',', array_fill(0, count($type), '?'));
          $sql .= "AND games.type_id IN (" . $placeholders . ") ";
          foreach($type as $typeIndividual) {
            $params[] = $typeIndividual;
            $param_types .= 's';
          }
        } else {
          // User unchecked every type filter — return no rows.
          $sql .= " AND 1 = 0 ";
        }
      } elseif ($type === '') {
        // add no type clause
      } else {
        $sql .= "AND types.objectType = ? ";
        $params[] = $type;
        $param_types .= 's';
      }

      $sql .= "
        ORDER BY MostRecentUseOrResponse ASC
      ";
      $stmt = mysqli_prepare($db, $sql);
      mysqli_stmt_bind_param($stmt, $param_types, ...$params);
      mysqli_stmt_execute($stmt);
      $result = mysqli_stmt_get_result($stmt);
      confirm_result_set($result);
      return $result;
  }

  function first_play_by() {
    global $db;

      $sql ="SELECT
      games.Title,
      games.mnp,
      games.mxp,
      games.ss,
      games.type,
      CASE
          WHEN MAX(responses.PlayDate) < games.Acq THEN DATE_ADD(games.Acq, INTERVAL 180 DAY)
          WHEN MAX(responses.PlayDate) IS NULL THEN DATE_ADD(games.Acq, INTERVAL 180 DAY)
          ELSE DATE_ADD(MAX(responses.PlayDate), INTERVAL 360 DAY)
      END PlayBy,
      games.Acq,
      MAX(responses.PlayDate) AS MaxPlay,
      games.is_kept
    FROM games
      LEFT JOIN responses ON games.id = responses.Title
    GROUP BY games.Acq,
      games.Title,
      games.is_kept, games.mnp, games.mxp, games.ss, games.type

    HAVING (games.is_kept) = 1
    and games.type = 'board-game'
    ORDER BY MostRecentUse DESC, MaxPlay DESC
    LIMIT 1
    ";

    $result = mysqli_query($db, $sql);
    confirm_result_set($result);
    return $result;

    /* Sample query
      SELECT
          games.Title,
          games.mnp,
          games.mxp,
          games.Candidate,
          games.UsedRecUserCt,
          games.ss,
          games.id,
          games.type,
          games.user_id,
          CASE
              WHEN MAX(responses.PlayDate) < games.Acq THEN DATE_ADD(games.Acq, INTERVAL 180 DAY)
              WHEN MAX(responses.PlayDate) IS NULL THEN DATE_ADD(games.Acq, INTERVAL 180 DAY)
              ELSE DATE_ADD(MAX(responses.PlayDate),
                  INTERVAL 360 DAY)
          END PlayBy,
          MAX(responses.PlayDate) AS MaxPlay,
          games.Acq,
          games.is_kept
      FROM
          games
              LEFT JOIN
          responses ON games.id = responses.Title
      GROUP BY games.Acq , games.Title , games.is_kept , games.mnp , games.mxp , games.ss , games.type , games.id
      HAVING games.user_id = 8 AND games.is_kept = 1
          AND games.ss LIKE '%3%'
          AND games.type IN ('game' , 'board-game',
          'card-game',
          'childrens-game',
          'gambling-game',
          'miniatures-game',
          'mobile-game',
          'role-playing-game',
          'sport',
          'vr-game',
          'book',
          'audiobook',
          'drink',
          'food',
          'equipment',
          'film',
          'instrument',
          'toy',
          'other')
      ORDER BY PlayBy ASC
    */
  }

function email_artifact_use_notice($user_id) {

  $sweetSpot = '';
  $minimumAge = 0;
  $shelfSort = 'no';
  $type = '';

  global $db;
  $stmt = mysqli_prepare($db, "SELECT default_use_interval FROM users WHERE id = ?");
  mysqli_stmt_bind_param($stmt, "i", $user_id);
  mysqli_stmt_execute($stmt);
  $result = mysqli_stmt_get_result($stmt);
  $row = mysqli_fetch_array($result);
  mysqli_stmt_close($stmt);
  $interval = ($row !== null) ? $row[0] : DEFAULT_USE_INTERVAL;

  $artifact_set = use_by($type, $interval, $sweetSpot, $minimumAge, $shelfSort, $user_id);

  $due_today_array = array();
  $overdue_array = array();
  $due_in_coming_week = array();

  $i = 0;
  while($artifact = mysqli_fetch_assoc($artifact_set)) {

      if ($artifact['interaction_frequency_days'] !== null) {
        $this_interval = $artifact['interaction_frequency_days'];
      } else {
        $this_interval = $interval;
      }

      date_default_timezone_set('America/New_York');
      $DateTimeNow = new DateTime(date('Y-m-d'));
      $DateTimeMostRecentUse = ($artifact['MostRecentUseOrResponse'] !== NULL)
          ? new DateTime(substr($artifact['MostRecentUseOrResponse'],0,10))
          : new DateTime('1970-01-01');
      if ($artifact['MostRecentUseOrResponse'] === NULL) {
          $date_of_most_recent_use = 'No interactions';
      } else {
          $date_of_most_recent_use = $DateTimeMostRecentUse->format('Y-m-d');
      }
      $DateTimeAcquisition = new DateTime(substr($artifact['Acq'],0,10));
      $intervalInHours = $this_interval * 24;

      if ($DateTimeMostRecentUse < $DateTimeAcquisition || $artifact['MostRecentUseOrResponse'] === NULL) {
          $DateInterval = DateInterval::createFromDateString("$intervalInHours hour");
          $useByDate = date_add($DateTimeAcquisition, $DateInterval);
      } else {
          $doubledInterval = $intervalInHours * 2;
          $DateInterval = DateInterval::createFromDateString("$doubledInterval hour");
          $useByDate = date_add($DateTimeMostRecentUse, $DateInterval);
      }

      $diff_days = $useByDate->diff($DateTimeNow)->days;

      if ($useByDate->format('Y-m-d') === $DateTimeNow->format('Y-m-d')) { // due today
          $due_today_array[$i]['artifact'] = h($artifact['Title']);
          $due_today_array[$i]['artifact_id'] = h($artifact['id']);
          $due_today_array[$i]['most_recent_use'] = $date_of_most_recent_use;
          $due_today_array[$i]['interval'] = $this_interval;
      } elseif ($diff_days > 0 && $diff_days < 8 && $useByDate->format('Y-m-d') > $DateTimeNow->format('Y-m-d')) { // due in coming week
          $due_in_coming_week[$i]['artifact'] = h($artifact['Title']);
          $due_in_coming_week[$i]['artifact_id'] = h($artifact['id']);
          $due_in_coming_week[$i]['use_by_date'] = $useByDate->format('Y-m-d');
          $due_in_coming_week[$i]['most_recent_use'] = $date_of_most_recent_use;
          $due_in_coming_week[$i]['interval'] = $this_interval;
      } elseif ($useByDate->format('Y-m-d') < $DateTimeNow->format('Y-m-d')) { // due in past
          $overdue_array[$i]['artifact'] = h($artifact['Title']);
          $overdue_array[$i]['artifact_id'] = h($artifact['id']);
          $overdue_array[$i]['use_by_date'] = $useByDate->format('Y-m-d');
          $overdue_array[$i]['most_recent_use'] = $date_of_most_recent_use;
          $overdue_array[$i]['interval'] = $this_interval;
      }
      $i++;
  }

  $count_to_notify_about =
    count($due_today_array)
    + count($overdue_array)
    + count($due_in_coming_week)
  ;

  if($count_to_notify_about > 0) { // email this list to the user

      // get user email address
      $email_stmt = mysqli_prepare($db, "SELECT email FROM users WHERE id = ?");
      mysqli_stmt_bind_param($email_stmt, "i", $user_id);
      mysqli_stmt_execute($email_stmt);
      $email_result = mysqli_stmt_get_result($email_stmt);
      $email_row = mysqli_fetch_array($email_result);
      mysqli_stmt_close($email_stmt);
      $email = ($email_row !== null) ? $email_row[0] : null;
      if ($email === null) { return 0; }
      // Skip RFC 2606 reserved-TLD addresses (e.g. the seeded demo user
      // demo@artifact.example). They can never receive mail, so sending to
      // them is a guaranteed hard bounce that erodes the domain's Resend
      // sender reputation. Real recipients are unaffected.
      if (preg_match('/\.(example|test|invalid|localhost)$/i', $email)) { return 0; }

      $mail = new PHPMailer(true);

      // Server settings
      $mail->isSMTP();
      $mail->Host       = SMTP_HOST;
      $mail->SMTPAuth   = true;
      $mail->Username   = SMTP_USER;
      $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
      $mail->Password   = SMTP_PASS;
      $mail->Port       = SMTP_PORT;

      // Recipients
      $mail->setFrom(SMTP_FROM_EMAIL, APP_NAME);
      $mail->addAddress($email);
      $mail->addReplyTo(DEV_EMAIL, DEV_NAME);

      // Content
      $mail->isHTML(true);


      try {
          $mail->Subject = "Interactions Due";
          $body = '';

          $overdue_count = count($overdue_array);
          $due_today_count = count($due_today_array);
          $due_in_coming_week_count = count($due_in_coming_week);

          $body .= '
              <h2 style="margin:0 0 0.5rem;">Summary</h2>
              <p style="margin:0 0 0.25rem;">
                  <strong>' . $count_to_notify_about . '</strong> ' .
                  ($count_to_notify_about === 1 ? 'item needs' : 'items need') .
                  ' attention.
              </p>
              <p style="margin:0 0 0.75rem;">
                  <a href="https://' . DOMAIN . '/artifacts/useby.php">View interact by list</a>
              </p>
              <table cellpadding="6" cellspacing="0" style="border-collapse:collapse;margin-bottom:1.25rem;">
                <tr>
                  <td style="font-weight:bold;color:#b63d2f;">Overdue</td>
                  <td style="font-weight:bold;">' . $overdue_count . '</td>
                </tr>
                <tr>
                  <td style="font-weight:bold;">Due today</td>
                  <td style="font-weight:bold;">' . $due_today_count . '</td>
                </tr>
                <tr>
                  <td style="font-weight:bold;">Due in the coming week</td>
                  <td style="font-weight:bold;">' . $due_in_coming_week_count . '</td>
                </tr>
              </table>
              <hr>
          ';

          if ($overdue_count > 0) {
              $body .= '
                  <h1>Interactions overdue</h1>
                  <ul>
              ';

              foreach($overdue_array as $overdue) {
                  $name = $overdue['artifact'];
                  $most_recent_use = $overdue['most_recent_use'];
                  $use_by_date = $overdue['use_by_date'];
                  $id = $overdue['artifact_id'];
                  $interval = $overdue['interval'];
                  $get_rid_of_url = 'https://' . DOMAIN . '/artifacts/mark-get-rid-of.php?artifact_id=' . $id . '&artifact_name=' . urlencode($name) . '&return_to=useby';
                  $snooze_url = 'https://' . DOMAIN . '/artifacts/snooze.php?artifact_id=' . $id . '&artifact_name=' . urlencode($name) . '&return_to=useby';
                  if ($most_recent_use === 'No interactions') {
                      $body .= "
                          <li>
                              <a href='https://" . DOMAIN . "/artifacts/edit.php?id=$id'>$name</a>:
                              <a href='https://" . DOMAIN . "/uses/record-new?artifact_id=$id'>Record Interaction</a>
                              | <a href='$snooze_url'>Snooze</a>
                              | <a href='$get_rid_of_url'>Get Rid Of</a>
                              $most_recent_use, interact by $use_by_date (" . date('l', strtotime($use_by_date)) . ", interval: $interval days)
                          </li>
                      ";
                  } else {
                      $body .= "
                          <li>
                              <a href='https://" . DOMAIN . "/artifacts/edit.php?id=$id'>$name</a>:
                              <a href='https://" . DOMAIN . "/uses/record-new?artifact_id=$id'>Record Interaction</a>
                              | <a href='$snooze_url'>Snooze</a>
                              | <a href='$get_rid_of_url'>Get Rid Of</a>
                              last interacted $most_recent_use, interact by $use_by_date (" . date('l', strtotime($use_by_date)) . " interval: $interval days)
                          </li>
                      ";
                  }
              }

              $body .= '
                  </ul>
              ';
          }

          if (count($due_today_array) > 0) {
              $body .= '
                  <h1>Interactions due today</h1>
                  <ul>
              ';

              foreach($due_today_array as $due_today) {
                  $name = $due_today['artifact'];
                  $most_recent_use = $due_today['most_recent_use'];
                  $id = $due_today['artifact_id'];
                  $interval = $due_today['interval'];
                  $snooze_url = 'https://' . DOMAIN . '/artifacts/snooze.php?artifact_id=' . $id . '&artifact_name=' . urlencode($name) . '&return_to=useby';
                  $body .= "
                      <li>
                          <a href='https://" . DOMAIN . "/artifacts/edit.php?id=$id'>$name</a>:
                          <a href='https://" . DOMAIN . "/uses/record-new?artifact_id=$id'>Record Interaction</a>
                          | <a href='$snooze_url'>Snooze</a>
                          last interacted $most_recent_use (interval: $interval days)
                      </li>
                  ";
              }

              $body .= '
                  </ul>
              ';
          }

          if (count($due_in_coming_week) > 0) {
              $body .= '
                  <h1>Interactions due in coming week</h1>
                  <ul>
              ';

              foreach($due_in_coming_week as $artifact) {
                  $name = $artifact['artifact'];
                  $most_recent_use = $artifact['most_recent_use'];
                  $use_by_date = $artifact['use_by_date'];
                  $id = $artifact['artifact_id'];
                  $interval = $artifact['interval'];
                  $snooze_url = 'https://' . DOMAIN . '/artifacts/snooze.php?artifact_id=' . $id . '&artifact_name=' . urlencode($name) . '&return_to=useby';
                  if ($most_recent_use === 'No interactions') {
                      $body .= "
                          <li>
                              <a href='https://" . DOMAIN . "/artifacts/edit.php?id=$id'>$name</a>:
                              <a href='https://" . DOMAIN . "/uses/record-new?artifact_id=$id'>Record Interaction</a>
                              | <a href='$snooze_url'>Snooze</a>
                              $most_recent_use, interact by $use_by_date (" . date('l', strtotime($use_by_date)) . ", interval: $interval days)
                          </li>
                      ";
                  } else {
                      $body .= "
                          <li>
                              <a href='https://" . DOMAIN . "/artifacts/edit.php?id=$id'>$name</a>:
                              <a href='https://" . DOMAIN . "/uses/record-new?artifact_id=$id'>Record Interaction</a>
                              | <a href='$snooze_url'>Snooze</a>
                              last interacted $most_recent_use, interact by $use_by_date (" . date('l', strtotime($use_by_date)) . ", interval: $interval days)
                          </li>
                      ";
                  }
              }

              $body .= '
                  </ul>
              ';
          }

          $body .= '
              <p>Record uses at <a href="https://' . DOMAIN . '/uses/record-new.php">' . DOMAIN . '</a></p>
          ';


          $mail->Body = $body;

          $mail_result = $mail->send();

       } catch (Exception $Exception) {

          try {
              $mail->Subject = "Error with Keeplore Uses Due Today Email";
              $mail->Body = '<p>The following Exception was thrown when trying to email an interact by list:</p>
                  <pre>' . print_r($Exception, true) . '</pre>
              ';
              $mail->send();

          } catch (Exception $Exception) {
              file_put_contents(__FILE__ . '.log',
                  'Email exception caught in email notification to dev of error at '
                  . date('Y-m-d H:i:s') . "\n"
                  . print_r($Exception, true) . "\n",
                  FILE_APPEND
              );
          }

       }

  };

  return $count_to_notify_about;
}

?>
