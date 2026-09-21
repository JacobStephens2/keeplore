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

function bgg_comparable_name($name) {
  $name = strtolower(trim((string) $name));
  $name = str_replace(':', ' ', $name);
  $name = preg_replace('/\s+/', ' ', $name);
  return trim($name);
}

function bgg_preferred_candidate($candidates, $query) {
  if (!is_array($candidates) || $candidates === []) {
    return null;
  }
  $needle = strtolower(trim((string) $query));
  $comparable_needle = bgg_comparable_name($query);
  $exact = [];
  $comparable = [];
  foreach ($candidates as $candidate) {
    if (!is_array($candidate) || !isset($candidate['name'])) {
      continue;
    }
    $name = (string) $candidate['name'];
    if (strtolower($name) === $needle) {
      $exact[] = $candidate;
    } elseif (bgg_comparable_name($name) === $comparable_needle) {
      $comparable[] = $candidate;
    }
  }
  $pool = $exact !== [] ? $exact : ($comparable !== [] ? $comparable : $candidates);
  foreach ($pool as $candidate) {
    if (($candidate['source'] ?? '') === 'BGG') {
      return $candidate;
    }
  }
  return $pool[0];
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
  $source = bgg_source_from_item($item);
  $url = isset($item['canonical_link']) ? trim((string) $item['canonical_link']) : '';
  if ($url === '' && $id > 0) {
    $url = bgg_canonical_url($id, $source);
  }
  $image = bgg_image_url_from_item($item);

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
  if ($year !== '') {
    $fields['Yr'] = $year;
  }
  if ($image !== '') {
    $fields['image_url'] = $image;
  }

  $match = [
    'id' => $id,
    'name' => $name,
    'year' => $year,
    'url' => $url,
    'source' => $source,
  ];
  if ($image !== '') {
    $match['image'] = $image;
  }

  return [
    'match' => $match,
    'fields' => $fields,
  ];
}

function bgg_image_url_from_item($item) {
  if (!is_array($item)) {
    return '';
  }
  $candidates = [
    $item['imageurl'] ?? '',
    is_array($item['images'] ?? null) ? ($item['images']['previewthumb'] ?? '') : '',
    is_array($item['images'] ?? null) ? ($item['images']['original'] ?? '') : '',
  ];
  foreach ($candidates as $candidate) {
    $url = normalize_item_image_url($candidate);
    if ($url !== '') {
      return $url;
    }
  }
  return '';
}

function bgg_source_from_item($item) {
  $subtype = strtolower(trim((string) ($item['subtype'] ?? '')));
  if ($subtype === '' && isset($item['subtypes'][0])) {
    $subtype = strtolower(trim((string) $item['subtypes'][0]));
  }
  $url = (string) ($item['canonical_link'] ?? $item['href'] ?? '');
  if ($subtype === 'rpgitem' || str_contains($url, 'rpggeek.com') || str_contains($url, '/rpgitem/')) {
    return 'RPGG';
  }
  if ($subtype === 'videogame' || str_contains($url, 'videogamegeek.com') || str_contains($url, '/videogame/')) {
    return 'VGG';
  }
  return 'BGG';
}

function bgg_canonical_url($id, $source) {
  $id = (int) $id;
  if ($source === 'RPGG') {
    return 'https://rpggeek.com/rpgitem/' . $id;
  }
  if ($source === 'VGG') {
    return 'https://videogamegeek.com/videogame/' . $id;
  }
  return 'https://boardgamegeek.com/boardgame/' . $id;
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

function bgg_curl_options() {
  return [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 15,
    CURLOPT_USERAGENT => 'Keeplore/1.0 (https://keeplore.app)',
    CURLOPT_HTTPHEADER => ['Accept: application/json'],
  ];
}

function bgg_http_get($url) {
  $ch = curl_init($url);
  if ($ch === false) {
    throw new RuntimeException('Could not start BoardGameGeek request.');
  }
  curl_setopt_array($ch, bgg_curl_options());
  $body = curl_exec($ch);
  $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
  $error = curl_error($ch);
  curl_close($ch);
  if ($body === false || $code >= 400 || $code === 0) {
    throw new RuntimeException($error !== '' ? $error : 'BoardGameGeek HTTP ' . $code);
  }
  return $body;
}

function bgg_http_get_many($urls) {
  $mh = curl_multi_init();
  $handles = [];
  foreach ($urls as $key => $url) {
    $ch = curl_init($url);
    if ($ch === false) {
      continue;
    }
    curl_setopt_array($ch, bgg_curl_options());
    curl_multi_add_handle($mh, $ch);
    $handles[$key] = $ch;
  }

  do {
    $status = curl_multi_exec($mh, $active);
    if ($active) {
      curl_multi_select($mh, 1.0);
    }
  } while ($active && $status === CURLM_OK);

  $bodies = [];
  foreach ($handles as $key => $ch) {
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $body = curl_multi_getcontent($ch);
    $bodies[$key] = ($body !== false && $code > 0 && $code < 400) ? $body : null;
    curl_multi_remove_handle($mh, $ch);
    curl_close($ch);
  }
  curl_multi_close($mh);
  return $bodies;
}

function bgg_fetch($url, $get_json) {
  $getter = $get_json ?? 'bgg_http_get';
  return $getter($url);
}

function bgg_search_query_variants($query) {
  $query = trim((string) $query);
  if ($query === '') {
    return [];
  }
  $variants = [$query];
  if (str_contains($query, ':')) {
    return $variants;
  }
  $words = preg_split('/\s+/', $query, -1, PREG_SPLIT_NO_EMPTY);
  $count = count($words);
  if ($count < 2) {
    return $variants;
  }
  for ($i = 1; $i < $count; $i++) {
    $with_colon = implode(' ', array_slice($words, 0, $i)) . ': ' . implode(' ', array_slice($words, $i));
    if ($with_colon !== $query) {
      $variants[] = $with_colon;
    }
  }
  return $variants;
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

  $candidates = [];
  $fallback = [];
  $comparable_query = bgg_comparable_name($query);
  foreach (bgg_search_query_variants($query) as $index => $variant) {
    $url = bgg_api_root() . '/geekitems?objecttype=thing&search=' . rawurlencode($variant) . '&showcount=20';
    try {
      $json = bgg_fetch($url, $get_json);
    } catch (Throwable $e) {
      if ($index === 0) {
        return [
          'ok' => false,
          'error' => 'Could not reach BoardGameGeek.',
          'candidates' => [],
        ];
      }
      continue;
    }
    $found = bgg_search_candidates_from_json($json);
    if ($found === []) {
      continue;
    }
    if ($fallback === []) {
      $fallback = $found;
    }
    $preferred = bgg_preferred_candidate($found, $query);
    if ($preferred !== null && bgg_comparable_name($preferred['name']) === $comparable_query) {
      $candidates = $found;
      break;
    }
  }
  if ($candidates === []) {
    $candidates = $fallback;
  }

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

function bgg_candidate_from_item_json($json, $fallback) {
  $mapped = bgg_form_fields_from_json($json, '{}');
  $match = $mapped['match'];
  $id = $match['id'] > 0 ? $match['id'] : (int) $fallback['id'];
  $name = $match['name'] !== '' ? $match['name'] : (string) $fallback['name'];
  return [
    'id' => $id,
    'name' => $name,
    'year' => $match['year'],
    'source' => $match['source'] ?? 'BGG',
  ];
}

function bgg_enrich_candidates($candidates, $get_json = null) {
  if (!is_array($candidates) || $candidates === []) {
    return [];
  }

  $urls = [];
  foreach ($candidates as $candidate) {
    $id = (int) $candidate['id'];
    $urls[$id] = bgg_api_root() . '/geekitems?objectid=' . $id . '&objecttype=thing';
  }

  if ($get_json === null) {
    $bodies = bgg_http_get_many($urls);
  } else {
    $bodies = [];
    foreach ($urls as $id => $url) {
      try {
        $bodies[$id] = bgg_fetch($url, $get_json);
      } catch (Throwable $e) {
        $bodies[$id] = null;
      }
    }
  }

  $enriched = [];
  foreach ($candidates as $candidate) {
    $id = (int) $candidate['id'];
    $json = $bodies[$id] ?? null;
    if (!is_string($json) || $json === '') {
      $enriched[] = [
        'id' => $id,
        'name' => $candidate['name'],
        'year' => '',
        'source' => 'BGG',
      ];
      continue;
    }
    $enriched[] = bgg_candidate_from_item_json($json, $candidate);
  }
  return $enriched;
}

function bgg_lookup_name($query, $get_json = null) {
  $search = bgg_search($query, $get_json);
  if (empty($search['ok'])) {
    return $search;
  }

  $candidates = bgg_enrich_candidates($search['candidates'], $get_json);
  $preferred = bgg_preferred_candidate($candidates, $query);
  $fields = bgg_fields_for_id($preferred['id'], $get_json);
  if (empty($fields['ok'])) {
    return $fields;
  }

  $alternatives = [];
  foreach ($candidates as $candidate) {
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
