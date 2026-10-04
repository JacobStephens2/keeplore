<?php

/**
 * A person's recorded uses on Edit User, as the Uses module reads them:
 * rank items by how often the person took part, and share Item/Type table
 * cells between the ranking and the chronological list.
 */

function rank_items_by_player_uses(array $uses) {
  $by_item = [];
  foreach ($uses as $use) {
    $item_id = (int) ($use['item_id'] ?? 0);
    if ($item_id === 0) {
      continue;
    }
    if (!isset($by_item[$item_id])) {
      $by_item[$item_id] = [
        'item_id' => $item_id,
        'item_title' => $use['item_title'] ?? '',
        'item_type' => $use['item_type'] ?? '',
        'use_count' => 0,
      ];
    }
    $by_item[$item_id]['use_count']++;
  }
  $ranked = array_values($by_item);
  usort($ranked, function ($a, $b) {
    $by_count = $b['use_count'] <=> $a['use_count'];
    if ($by_count !== 0) {
      return $by_count;
    }
    return strcasecmp($a['item_title'], $b['item_title']);
  });
  return $ranked;
}

/**
 * Item and Type table cells for a person's use or ranking row.
 *
 * Both Edit User tables share this pair: a link to Edit Item and the
 * item type. Callers supply the first-column cell themselves.
 */
function player_use_item_cells(array $row) {
  $id = h(u($row['item_id'] ?? ''));
  $title = h($row['item_title'] ?? '');
  $type = h($row['item_type'] ?? '');
  $href = url_for('/artifacts/edit.php?id=' . $id);
  return '<td><a href="' . $href . '">' . $title . '</a></td>'
    . '<td>' . $type . '</td>';
}
