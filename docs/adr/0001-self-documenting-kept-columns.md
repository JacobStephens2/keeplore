# Self-documenting kept and format column names

Migrating `games` to `is_kept`, `is_in_secondary_collection` (`'yes'` backfilled to 1), and `is_digital` / `is_physical` as pure format flags decoupled from kept, in one hard-rename release with dual read/write aliases for one release, because the legacy names conflate format with kept status and the server kept-filter reads only one of three flags.
