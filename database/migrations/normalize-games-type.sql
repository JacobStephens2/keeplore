-- Normalize the denormalized games.type strings to one canonical slug
-- per type (issue #9, brief 4).
--
-- games.type is a cache of types.objectType for the row referenced by
-- games.type_id, but history holds several spellings for the same type
-- ('table-game' vs 'table game', 'individual-display' vs
-- 'individual display game' vs 'individual-display-game'). Write paths
-- already store the lookup result, so this backfills existing rows.
--
-- Rerunnable: the WHERE clause matches nothing once every row carries
-- the canonical name. Rows with no type_id are left untouched.

UPDATE games
  JOIN types ON types.id = games.type_id
  SET games.type = types.objectType
  WHERE games.type_id IS NOT NULL
    AND games.type <> types.objectType;
