<?php

/**
 * Edit Item's merge choices: every other item, those with the survivor's name
 * (ignoring case and spacing) first, then by name. Each carries 'same_name'.
 * Items::merge does the merge.
 */
function item_merge_candidates(array $items, array $survivor) {
  $key = function ($title) {
    return strtolower(preg_replace('/\s+/', ' ', trim((string) $title)));
  };
  $survivor_key = $key($survivor['Title'] ?? '');
  $candidates = [];
  foreach ($items as $item) {
    if ((int) $item['id'] === (int) $survivor['id']) {
      continue;
    }
    $item['same_name'] = $key($item['Title'] ?? '') === $survivor_key;
    $candidates[] = $item;
  }
  usort($candidates, function ($a, $b) use ($key) {
    return [!$a['same_name'], $key($a['Title']), (int) $a['id']]
      <=> [!$b['same_name'], $key($b['Title']), (int) $b['id']];
  });
  return $candidates;
}
