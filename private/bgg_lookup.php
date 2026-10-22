<?php

require_once __DIR__ . '/bgg_link.php';
require_once __DIR__ . '/bgg_polls.php';

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
  $names = bgg_search_names($query);
  $needles = array_map('strtolower', $names);
  $comparable_needles = array_map('bgg_comparable_name', $names);
  $exact = [];
  $comparable = [];
  foreach ($candidates as $candidate) {
    if (!is_array($candidate) || !isset($candidate['name'])) {
      continue;
    }
    $name = (string) $candidate['name'];
    if (in_array(strtolower($name), $needles, true)) {
      $exact[] = $candidate;
    } elseif (in_array(bgg_comparable_name($name), $comparable_needles, true)) {
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
  // A body that is not a poll answer still fills the publisher's box numbers.
  // The BGG average below is read from the rest of the body.
  $polls = bgg_polls_from_dynamic_json($dynamic_json);
  $sweet_spot = $polls === false ? null : $polls['sweet_spot'];
  if ($sweet_spot !== null) {
    $fields['SS'] = $sweet_spot;
  }
  // Community polls describe who the game is worth playing with; the
  // publisher's box numbers only fill in when nobody has voted.
  $players = $polls === false ? null : $polls['recommended'];
  $fields['MnP'] = $players !== null ? (string) $players[0] : bgg_scalar_string($item['minplayers'] ?? '');
  $fields['MxP'] = $players !== null ? (string) $players[1] : bgg_scalar_string($item['maxplayers'] ?? '');
  $fields['MnT'] = bgg_scalar_string($item['minplaytime'] ?? '');
  $fields['MxT'] = bgg_scalar_string($item['maxplaytime'] ?? '');
  $community_age = $polls === false ? null : $polls['community_age'];
  // Posted Item form names. Minimum age is `age`; the form maps that to the
  // stored Age column when the item is saved. Picture and BGG average are
  // always present, empty when this match has none, so a replace mark can
  // clear the previous match. A missing link or sweet spot is left out.
  $fields['age'] = $community_age !== null ? (string) $community_age : bgg_scalar_string($item['minage'] ?? '');
  if ($year !== '') {
    $fields['Yr'] = $year;
  }
  $fields['image_url'] = $image;
  $link = normalize_item_bgg_url($url);
  if ($link !== '') {
    $fields['bgg_url'] = $link;
  }
  // Player counts rest on the poll only when it produced a recommended range.
  $fields['bgg_player_votes'] = $players !== null ? (string) $polls['player_votes'] : '0';
  $fields['bgg_age_basis'] = $community_age !== null ? 'community' : 'publisher';
  $fields['BGG_Rat'] = bgg_overall_rating_text($dynamic['stats']['average'] ?? null) ?? '';

  $match = [
    'id' => $id,
    'name' => $name,
    'year' => $year,
    'url' => $url,
    'source' => $source,
    'site_name' => bgg_site_name_for_source($source),
  ];
  if ($image !== '') {
    $match['image'] = $image;
  }

  return [
    'match' => $match,
    'fields' => $fields,
  ];
}

// The community average from a dynamicinfo body, or null when that body
// names an item with no average. False when the body is not that answer,
// which is what BGG sends while a request is still queued.
function bgg_overall_rating_from_dynamic_json($json) {
  $data = json_decode((string) $json, true);
  if (!is_array($data) || !is_array($data['item'] ?? null) || !is_array($data['item']['stats'] ?? null)) {
    return false;
  }
  return bgg_overall_rating_text($data['item']['stats']['average'] ?? null);
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

function bgg_scalar_string($value) {
  if (is_bool($value) || is_array($value) || $value === null) {
    return '';
  }
  return trim((string) $value);
}

function bgg_api_root() {
  return 'https://api.geekdo.com/api';
}

// A thing's community polls, stats and ranks.
function bgg_dynamic_info_url($object_id) {
  return bgg_api_root() . '/dynamicinfo?objectid=' . (int) $object_id . '&objecttype=thing';
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
  $names = bgg_search_names($query);
  $variants = $names;
  foreach ($names as $name) {
    foreach (bgg_colon_variants($name) as $variant) {
      $variants[] = $variant;
    }
  }
  return array_values(array_unique($variants));
}

// Keeplore titles often carry a trailing note BGG does not, like
// "Chess (game)" or "Magic Labyrinth (The)". The typed name stays first.
function bgg_search_names($query) {
  $query = trim((string) $query);
  $names = [$query];
  if (preg_match('/^(.*\S)\s*\((the|a|an)\)$/i', $query, $match)) {
    $names[] = ucfirst(strtolower($match[2])) . ' ' . $match[1];
  } elseif (preg_match('/^(.*\S)\s*\([^()]*\)$/', $query, $match)) {
    $names[] = $match[1];
  }
  return $names;
}

function bgg_colon_variants($name) {
  if (str_contains($name, ':')) {
    return [];
  }
  $words = preg_split('/\s+/', $name, -1, PREG_SPLIT_NO_EMPTY);
  $variants = [];
  for ($i = 1; $i < count($words); $i++) {
    $variants[] = implode(' ', array_slice($words, 0, $i)) . ': ' . implode(' ', array_slice($words, $i));
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
  $comparable_names = array_map('bgg_comparable_name', bgg_search_names($query));
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
    if ($preferred !== null && in_array(bgg_comparable_name($preferred['name']), $comparable_names, true)) {
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
  $dynamic_url = bgg_dynamic_info_url($object_id);
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
  $source = $match['source'] ?? 'BGG';
  return [
    'id' => $id,
    'name' => $name,
    'year' => $match['year'],
    'source' => $source,
    'site_name' => bgg_site_name_for_source($source),
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
        'site_name' => bgg_site_name_for_source('BGG'),
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
