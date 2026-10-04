<?php

/**
 * The owner's Preferences: their own defaults and reminders, set on the
 * Settings page. Each preference has one default and one allowed range,
 * defined here and nowhere else. A value that is missing, empty or out of
 * range reads and saves as the default. Every read and write is scoped to
 * the owner.
 */
final class Preferences
{
    /**
     * Each preference: its kind, default, and allowed range. Kinds are
     * decimal (float), int, string and bool; bools have no range.
     */
    private const RULES = [
        'default_use_interval' => ['decimal', 90.0, 1, 999999.99],
        'default_snooze_days' => ['int', 7, 1, 365],
        'default_setting' => ['string', '', null, null],
        'daily_email' => ['bool', true, null, null],
        'daily_email_hour' => ['int', 8, 0, 23],
        'native_notify_enabled' => ['bool', true, null, null],
        'native_notify_hour' => ['int', 9, 0, 23],
        'native_notify_lead_days' => ['int', 3, 0, 14],
        'native_notify_past_due' => ['bool', true, null, null],
    ];

    public function __construct(private mysqli $db, private int $userId)
    {
    }

    /**
     * All of the owner's preferences, each with its default applied:
     *
     * - default_use_interval (float, days, 1 or more; 90): an item's
     *   interaction frequency when it has none of its own
     * - default_snooze_days (int, 1–365; 7): how long Snooze hides an item
     * - default_setting (string; ''): the Setting a new use opens with when
     *   the owner has no earlier use
     * - daily_email (bool; on), daily_email_hour (int, 0–23 Eastern; 8)
     * - native_notify_enabled (bool; on), native_notify_hour (int, 0–23
     *   device-local; 9), native_notify_lead_days (int, 0–14; 3),
     *   native_notify_past_due (bool; on)
     */
    public function get(): array
    {
        $stmt = $this->db->prepare('SELECT ' . implode(', ', array_keys(self::RULES)) . ' FROM users WHERE id = ?');
        $stmt->bind_param('i', $this->userId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc() ?? [];
        $stmt->close();
        $preferences = [];
        foreach (self::RULES as $name => $rule) {
            $preferences[$name] = self::valid($rule, $row[$name] ?? null);
        }
        return $preferences;
    }

    /**
     * Write only the preferences in $changes, keyed as get() returns them,
     * and return the preferences as now stored. An unknown key throws
     * InvalidArgumentException and nothing is written.
     */
    public function save(array $changes): array
    {
        $unknown = array_diff_key($changes, self::RULES);
        if ($unknown !== []) {
            throw new InvalidArgumentException('Unknown preference: ' . implode(', ', array_keys($unknown)));
        }
        if ($changes !== []) {
            $assignments = [];
            $values = [];
            foreach ($changes as $name => $value) {
                $assignments[] = "{$name} = ?";
                $value = self::valid(self::RULES[$name], $value);
                $values[] = is_bool($value) ? (int) $value : $value;
            }
            $values[] = $this->userId;
            $stmt = $this->db->prepare('UPDATE users SET ' . implode(', ', $assignments) . ' WHERE id = ?');
            $stmt->bind_param(str_repeat('s', count($values)), ...array_map('strval', $values));
            $stmt->execute();
            $stmt->close();
        }
        return $this->get();
    }

    /** The owners who get the daily email at this hour (0–23, Eastern). */
    public static function ownersDueDailyEmailAt(mysqli $db, int $hour): array
    {
        $owners = [];
        foreach ($db->query('SELECT id, daily_email, daily_email_hour FROM users ORDER BY id') as $row) {
            if (self::valid(self::RULES['daily_email'], $row['daily_email'])
                && self::valid(self::RULES['daily_email_hour'], $row['daily_email_hour']) === $hour) {
                $owners[] = (int) $row['id'];
            }
        }
        return $owners;
    }

    /** $value as the rule's kind, or the rule's default when missing, empty or out of range. */
    private static function valid(array $rule, $value): float|int|string|bool
    {
        [$kind, $default, $min, $max] = $rule;
        if ($value === null || (is_string($value) && trim($value) === '')) {
            return $default;
        }
        $range = ['options' => ['min_range' => $min, 'max_range' => $max]];
        $valid = match ($kind) {
            'decimal' => filter_var($value, FILTER_VALIDATE_FLOAT, $range + ['flags' => FILTER_NULL_ON_FAILURE]),
            'int' => filter_var($value, FILTER_VALIDATE_INT, $range + ['flags' => FILTER_NULL_ON_FAILURE]),
            'string' => is_scalar($value) ? (string) $value : null,
            'bool' => filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE),
        };
        return $valid ?? $default;
    }
}
