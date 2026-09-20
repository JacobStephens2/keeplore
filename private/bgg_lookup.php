<?php

function bgg_search_candidates_from_json($json) {
  $data = json_decode((string) $json, true);
  if (!is_array($data) || !isset($data['items']) || !is_array($data['items'])) {
    return [];
  }

  $candidates = [];
  $seen = [];
  foreach ($data['items'] as $item) {
    if (!is_array($item)) {
      continue;
    }
    $id = isset($item['objectid']) ? (int) $item['objectid'] : 0;
    $name = isset($item['name']) ? trim((string) $item['name']) : '';
    if ($id > 0 && $name !== '' && !isset($seen[$id])) {
      $seen[$id] = true;
      $candidates[] = ['id' => $id, 'name' => $name];
    }
  }
  return $candidates;
}

function bgg_preferred_candidate($candidates, $query) {
  if (!is_array($candidates) || $candidates === []) {
    return null;
  }
  $needle = strtolower(trim((string) $query));
  foreach ($candidates as $candidate) {
    if (!is_array($candidate) || !isset($candidate['name'])) {
      continue;
    }
    if (strtolower((string) $candidate['name']) === $needle) {
      return $candidate;
    }
  }
  return $candidates[0];
}

function bgg_form_fields_from_json($item_json, $dynamic_json) {
  $item_data = json_decode((string) $item_json, true);
  $dynamic_data = json_decode((string) $dynamic_json, true);
  $item = (is_array($item_data) && isset($item_data['item']) && is_array($item_data['item']))
    ? $item_data['item']
    : [];
  $dynamic = (is_array($dynamic_data) && isset($dynamic_data['item']) && is_array($dynamic_data['item']))
    ? $dynamic_data['item']
    : [];

  $id = isset($item['objectid']) ? (int) $item['objectid'] : (isset($item['id']) ? (int) $item['id'] : 0);
  $name = isset($item['name']) ? trim((string) $item['name']) : '';
  $year = isset($item['yearpublished']) ? trim((string) $item['yearpublished']) : '';
  $url = isset($item['canonical_link']) ? trim((string) $item['canonical_link']) : '';
  if ($url === '' && $id > 0) {
    $url = 'https://boardgamegeek.com/boardgame/' . $id;
  }

  $fields = ['Title' => $name];
  $sweet_spot = bgg_sweet_spot_from_polls($dynamic);
  if ($sweet_spot !== null) {
    $fields['SS'] = $sweet_spot;
  }
  $fields['MnP'] = bgg_scalar_string($item['minplayers'] ?? '');
  $fields['MxP'] = bgg_scalar_string($item['maxplayers'] ?? '');
  $fields['MnT'] = bgg_scalar_string($item['minplaytime'] ?? '');
  $fields['MxT'] = bgg_scalar_string($item['maxplaytime'] ?? '');
  $fields['Age'] = bgg_scalar_string($item['minage'] ?? '');

  return [
    'match' => [
      'id' => $id,
      'name' => $name,
      'year' => $year,
      'url' => $url,
    ],
    'fields' => $fields,
  ];
}

function bgg_scalar_string($value) {
  if (is_bool($value) || is_array($value) || $value === null) {
    return '';
  }
  return trim((string) $value);
}

function bgg_sweet_spot_from_polls($dynamic) {
  $best = $dynamic['polls']['userplayers']['best'] ?? null;
  if (!is_array($best) || $best === []) {
    return null;
  }

  $counts = [];
  foreach ($best as $range) {
    if (!is_array($range)) {
      continue;
    }
    $min = isset($range['min']) ? (int) $range['min'] : 0;
    $max = isset($range['max']) ? (int) $range['max'] : $min;
    if ($min <= 0) {
      continue;
    }
    if ($max < $min) {
      $max = $min;
    }
    for ($n = $min; $n <= $max; $n++) {
      $counts[$n] = true;
    }
  }
  if ($counts === []) {
    return null;
  }
  ksort($counts);
  return implode(',', array_map(function ($n) {
    return str_pad((string) $n, 2, '0', STR_PAD_LEFT);
  }, array_keys($counts)));
}

function bgg_api_root() {
  return 'https://api.geekdo.com/api';
}

function bgg_http_get($url) {
  $ch = curl_init($url);
  if ($ch === false) {
    throw new RuntimeException('Could not start BoardGameGeek request.');
  }
  curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 15,
    CURLOPT_USERAGENT => 'Keeplore/1.0 (https://keeplore.app)',
    CURLOPT_HTTPHEADER => ['Accept: application/json'],
  ]);
  $body = curl_exec($ch);
  $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
  $error = curl_error($ch);
  curl_close($ch);
  if ($body === false || $code >= 400 || $code === 0) {
    throw new RuntimeException($error !== '' ? $error : 'BoardGameGeek HTTP ' . $code);
  }
  return $body;
}

function bgg_fetch($url, $get_json) {
  $getter = $get_json ?? 'bgg_http_get';
  return $getter($url);
}

function bgg_search($query, $get_json = null) {
  $query = trim((string) $query);
  if ($query === '') {
    return [
      'ok' => false,
      'error' => 'Enter an item name first.',
      'candidates' => [],
    ];
  }

  $url = bgg_api_root() . '/geekitems?objecttype=thing&search=' . rawurlencode($query) . '&showcount=20';
  try {
    $json = bgg_fetch($url, $get_json);
  } catch (Throwable $e) {
    return [
      'ok' => false,
      'error' => 'Could not reach BoardGameGeek.',
      'candidates' => [],
    ];
  }

  $candidates = bgg_search_candidates_from_json($json);
  if ($candidates === []) {
    return [
      'ok' => false,
      'error' => 'No BoardGameGeek match for that name.',
      'candidates' => [],
    ];
  }

  return [
    'ok' => true,
    'candidates' => $candidates,
    'preferred' => bgg_preferred_candidate($candidates, $query),
  ];
}

function bgg_fields_for_id($object_id, $get_json = null) {
  $object_id = (int) $object_id;
  if ($object_id <= 0) {
    return [
      'ok' => false,
      'error' => 'No BoardGameGeek match for that name.',
    ];
  }

  $item_url = bgg_api_root() . '/geekitems?objectid=' . $object_id . '&objecttype=thing';
  $dynamic_url = bgg_api_root() . '/dynamicinfo?objectid=' . $object_id . '&objecttype=thing';
  try {
    $item_json = bgg_fetch($item_url, $get_json);
    $dynamic_json = bgg_fetch($dynamic_url, $get_json);
  } catch (Throwable $e) {
    return [
      'ok' => false,
      'error' => 'Could not reach BoardGameGeek.',
    ];
  }

  $mapped = bgg_form_fields_from_json($item_json, $dynamic_json);
  if ($mapped['match']['id'] <= 0 || $mapped['match']['name'] === '') {
    return [
      'ok' => false,
      'error' => 'No BoardGameGeek match for that name.',
    ];
  }

  return [
    'ok' => true,
    'match' => $mapped['match'],
    'fields' => $mapped['fields'],
  ];
}

function bgg_lookup_name($query, $get_json = null) {
  $search = bgg_search($query, $get_json);
  if (empty($search['ok'])) {
    return $search;
  }

  $preferred = $search['preferred'];
  $fields = bgg_fields_for_id($preferred['id'], $get_json);
  if (empty($fields['ok'])) {
    return $fields;
  }

  $alternatives = [];
  foreach ($search['candidates'] as $candidate) {
    if ((int) $candidate['id'] !== (int) $preferred['id']) {
      $alternatives[] = $candidate;
    }
  }

  return [
    'ok' => true,
    'match' => $fields['match'],
    'fields' => $fields['fields'],
    'alternatives' => $alternatives,
  ];
}
