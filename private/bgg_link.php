<?php

/**
 * A BoardGameGeek link: the page an item points at, the thing it names, the
 * site name, and how a score is written. Lookup, ratings, the Items write,
 * and the pages read those rules here. This file does not fetch.
 */

function bgg_link_sites() {
  return [
    [
      'name' => 'BoardGameGeek',
      'code' => 'BGG',
      'host' => 'boardgamegeek.com',
      'thing_paths' => ['boardgame', 'boardgameexpansion', 'boardgameaccessory'],
      'canonical_path' => 'boardgame',
    ],
    [
      'name' => 'RPGGeek',
      'code' => 'RPGG',
      'host' => 'rpggeek.com',
      'thing_paths' => ['rpgitem'],
      'canonical_path' => 'rpgitem',
    ],
    [
      'name' => 'VideoGameGeek',
      'code' => 'VGG',
      'host' => 'videogamegeek.com',
      'thing_paths' => ['videogame'],
      'canonical_path' => 'videogame',
    ],
  ];
}

function bgg_link_site_by_code($code) {
  foreach (bgg_link_sites() as $site) {
    if ($site['code'] === $code) {
      return $site;
    }
  }
  return null;
}

function bgg_link_host($url) {
  $host = strtolower((string) parse_url($url, PHP_URL_HOST));
  return preg_replace('/^www\./', '', $host);
}

function bgg_link_site_by_host($host) {
  foreach (bgg_link_sites() as $site) {
    if ($site['host'] === $host) {
      return $site;
    }
  }
  return null;
}

// A typed "boardgamegeek.com/..." or http:// link is upgraded to https.
// A link over 1024 characters, a different host, or a host that only begins
// with one of the geek names is no link.
function normalize_item_bgg_url($value) {
  $url = trim((string) $value);
  $url = preg_replace('#^(https?://)?#i', 'https://', $url, 1);
  if (strlen($url) > 1024 || !bgg_link_is_geek_url($url)) {
    return '';
  }
  return $url;
}

function bgg_link_is_geek_url($url) {
  $labels = [];
  foreach (bgg_link_sites() as $site) {
    $labels[] = preg_quote(preg_replace('/\.com$/', '', $site['host']), '#');
  }
  return (bool) preg_match(
    '#^https://(www\.)?(' . implode('|', $labels) . ')\.com(/[^\s]*)?$#i',
    $url
  );
}

// The site a link is on. A missing or rejected link reads BoardGameGeek,
// the name for no link.
function bgg_site_name_from_url($url) {
  $url = normalize_item_bgg_url($url);
  if ($url === '') {
    return 'BoardGameGeek';
  }
  $site = bgg_link_site_by_host(bgg_link_host($url));
  return $site === null ? 'BoardGameGeek' : $site['name'];
}

// The site name for a source code. An unknown code reads BoardGameGeek.
function bgg_site_name_for_source($source) {
  $site = bgg_link_site_by_code($source);
  return $site === null ? 'BoardGameGeek' : $site['name'];
}

// The thing an item's link names, or 0. The path has to be one of that
// host's thing paths. An /rpg/ family page and a profile are not things.
function bgg_thing_id_from_url($url) {
  $url = normalize_item_bgg_url($url);
  if ($url === '') {
    return 0;
  }
  $site = bgg_link_site_by_host(bgg_link_host($url));
  if ($site === null) {
    return 0;
  }
  $names = implode('|', array_map(fn ($path) => preg_quote($path, '#'), $site['thing_paths']));
  $path = (string) parse_url($url, PHP_URL_PATH);
  if (!preg_match('#^/(' . $names . ')/(\d+)(/|$)#i', $path, $match)) {
    return 0;
  }
  return (int) $match[2];
}

function bgg_canonical_url($id, $source) {
  $id = (int) $id;
  $site = bgg_link_site_by_code($source) ?? bgg_link_site_by_code('BGG');
  return 'https://' . $site['host'] . '/' . $site['canonical_path'] . '/' . $id;
}

// The source code for a fetched item. The subtype and the host are the same
// table as the thing paths. BoardGameGeek is the code when neither matches.
function bgg_source_from_item($item) {
  $subtype = strtolower(trim((string) ($item['subtype'] ?? '')));
  if ($subtype === '' && isset($item['subtypes'][0])) {
    $subtype = strtolower(trim((string) $item['subtypes'][0]));
  }
  $url = (string) ($item['canonical_link'] ?? $item['href'] ?? '');
  foreach (bgg_link_sites() as $site) {
    if ($site['code'] === 'BGG') {
      continue;
    }
    if (in_array($subtype, $site['thing_paths'], true) || str_contains($url, $site['host'])) {
      return $site['code'];
    }
    foreach ($site['thing_paths'] as $path) {
      if (str_contains($url, '/' . $path . '/')) {
        return $site['code'];
      }
    }
  }
  return 'BGG';
}

// A score as people write it: round to two decimals, then drop trailing
// zeros, so 8.00 reads "8" and 7.09 stays "7.09". An empty value becomes 0,
// so callers use this only when the rating is numeric. It does not reject a
// number outside 1 to 10.
function bgg_score_text($score) {
  $rounded = round((float) $score, 2);
  return rtrim(rtrim(number_format($rounded, 2, '.', ''), '0'), '.');
}

// BGG's average of user ratings, as the Items list shows it. Null when it is
// blank or not a 1-10 score, and otherwise the score spelling. This is the
// community average, not the Bayesian Geek Rating.
function bgg_overall_rating_text($value) {
  if (is_string($value)) {
    $value = trim($value);
  }
  if ($value === null || $value === '' || !is_numeric($value)) {
    return null;
  }
  $rating = round((float) $value, 2);
  if ($rating < 1 || $rating > 10) {
    return null;
  }
  return bgg_score_text($rating);
}

const ITEM_BGG_AGE_BASES = ['community', 'publisher'];

// The BGG columns an item write stores. The vote basis and the average only
// mean something alongside a link, so an item without one stores none.
function item_bgg_fields_for_storage($artifact) {
  $url = normalize_item_bgg_url($artifact['bgg_url'] ?? '');
  if ($url === '') {
    return ['bgg_url' => null, 'bgg_player_votes' => null, 'bgg_age_basis' => null, 'BGG_Rat' => null];
  }
  $votes = trim((string) ($artifact['bgg_player_votes'] ?? ''));
  $age_basis = $artifact['bgg_age_basis'] ?? null;
  $rating = $artifact['BGG_Rat'] ?? $artifact['bgg_rat'] ?? null;
  return [
    'bgg_url' => $url,
    'bgg_player_votes' => preg_match('/^\d{1,9}$/', $votes) ? (int) $votes : null,
    'bgg_age_basis' => in_array($age_basis, ITEM_BGG_AGE_BASES, true) ? $age_basis : null,
    'BGG_Rat' => bgg_overall_rating_text($rating),
  ];
}

function item_bgg_link_html($value) {
  $url = normalize_item_bgg_url($value);
  if ($url === '') {
    return '';
  }
  return '<p><a class="item-bgg-link" href="' . h($url) . '" target="_blank" rel="noopener noreferrer">View on ' . bgg_site_name_from_url($url) . '</a></p>';
}

// What an item's BGG numbers rest on. Player counts and Sweet Spot share
// BGG's player-count poll; BGG does not publish the age poll's vote count.
// Each phrase is null when that part of the basis is unknown.
function item_bgg_player_basis_phrase($player_votes) {
  if ($player_votes === null || $player_votes === '') {
    return null;
  }
  $votes = (int) $player_votes;
  return $votes > 0
    ? $votes . ' BGG community ' . ($votes === 1 ? 'vote' : 'votes')
    : 'the BGG publisher listing (no community recommendation)';
}

function item_bgg_age_basis_phrase($age_basis) {
  if ($age_basis === 'community') {
    return 'the BGG community age poll';
  }
  return $age_basis === 'publisher' ? 'the BGG publisher listing' : null;
}

// One sentence for the item page.
function item_bgg_basis_text($player_votes, $age_basis) {
  $parts = [];
  $players = item_bgg_player_basis_phrase($player_votes);
  if ($players !== null) {
    $parts[] = ((int) $player_votes > 0 ? 'player counts and Sweet Spot from ' : 'player counts from ') . $players;
  }
  $age = item_bgg_age_basis_phrase($age_basis);
  if ($age !== null) {
    $parts[] = 'minimum age from ' . $age;
  }
  return $parts === [] ? '' : ucfirst(implode('; ', $parts)) . '.';
}

function item_bgg_basis_html($item) {
  $text = item_bgg_basis_text($item['bgg_player_votes'] ?? null, $item['bgg_age_basis'] ?? null);
  return $text === '' ? '' : '<p class="item-bgg-basis">' . h($text) . '</p>';
}

// Per-field hints for Edit Item, keyed by field group. Sweet Spot has no
// basis when the player counts fell back to the publisher.
const ITEM_BGG_FIELD_GROUPS = ['players', 'sweet_spot', 'age'];

// The stored input a hint is drawn from. Player counts and Sweet spot share
// the player-vote basis. Create Item does not render the hints, so the
// hand-edit rule cannot be learned from them.
function item_bgg_basis_from($group) {
  if ($group === 'players' || $group === 'sweet_spot') {
    return 'bgg_player_votes';
  }
  return $group === 'age' ? 'bgg_age_basis' : null;
}

// Which posted inputs a hand edit drops. The Item form renders this onto
// those inputs for Create Item and Edit Item. Minimum players, maximum
// players, and Sweet spot drop the player-vote basis. Minimum age drops the
// age basis. The link drops both bases and the BGG average, the same columns
// an item with no link stores empty.
function item_bgg_hand_edit_clears() {
  return [
    'MnP' => ['bgg_player_votes'],
    'MxP' => ['bgg_player_votes'],
    'SS' => ['bgg_player_votes'],
    'age' => ['bgg_age_basis'],
    'bgg_url' => ['bgg_player_votes', 'bgg_age_basis', 'BGG_Rat'],
  ];
}

function item_bgg_field_basis_text($item, $group) {
  if ($group === 'age') {
    $age = item_bgg_age_basis_phrase($item['bgg_age_basis'] ?? null);
    if ($age === null) {
      return '';
    }
    return 'From ' . $age . (($item['bgg_age_basis'] ?? null) === 'community' ? ' (BGG does not publish its vote count)' : '');
  }
  if (!in_array($group, ITEM_BGG_FIELD_GROUPS, true)) {
    return '';
  }
  $players = item_bgg_player_basis_phrase($item['bgg_player_votes'] ?? null);
  if ($players === null || ($group === 'sweet_spot' && (int) $item['bgg_player_votes'] <= 0)) {
    return '';
  }
  return 'From ' . $players;
}

function item_bgg_field_bases($item) {
  $bases = [];
  foreach (ITEM_BGG_FIELD_GROUPS as $group) {
    $bases[$group] = item_bgg_field_basis_text($item, $group);
  }
  return $bases;
}

// Always rendered, hidden when empty, so the input's aria-describedby
// resolves and Request BGG Data can fill it in place.
function item_bgg_field_basis_html($item, $group, $input_id) {
  $text = item_bgg_field_basis_text($item, $group);
  $from = item_bgg_basis_from($group);
  return '<p id="' . h($input_id) . '-bgg-basis" class="form-field-hint" data-bgg-basis="' . h($group) . '"'
    . ($from === null ? '' : ' data-bgg-from="' . h($from) . '"')
    . ($text === '' ? ' hidden' : '') . '>' . h($text) . '</p>';
}
