<?php

/**
 * Free-form tags on items. Labels the collection user attaches so questions
 * like "beach-safe" are a filter, not a derivation from player-count
 * columns. Distinct from BGG-imported taxonomy. Scoped per user.
 *
 * Here: the tag rules. The Items module owns storing, reading and
 * filtering by an Item's tags.
 */

function normalize_item_tag($raw) {
  $tag = strtolower(trim((string) $raw));
  $tag = preg_replace('/\s+/u', ' ', $tag);
  if ($tag === '') {
    return null;
  }
  if (mb_strlen($tag) > 64) {
    $tag = mb_substr($tag, 0, 64);
  }
  return $tag;
}

function parse_item_tags_input($raw) {
  if (is_string($raw)) {
    $raw = $raw === '' ? [] : explode(',', $raw);
  }
  if (!is_array($raw)) {
    return [];
  }
  $tags = [];
  foreach ($raw as $value) {
    $tag = normalize_item_tag($value);
    if ($tag !== null) {
      $tags[$tag] = $tag;
    }
  }
  $tags = array_values($tags);
  sort($tags, SORT_STRING);
  return $tags;
}
