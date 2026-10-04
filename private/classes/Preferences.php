<?php

/**
 * The owner's Preferences: their own defaults and reminders, set on the
 * Settings page. Each preference has one default and one allowed range,
 * defined here and nowhere else. A value that is missing, empty or out of
 * range reads and saves as the default. Every read and write is scoped to
 * the owner; only ownersDueDailyEmailAt(), for the cron, looks across all
 * owners.
 */
final class Preferences
{
    /**
     * Each preference's kind, default and allowed range. Kinds are float,
     * int, string and bool; only float and int have a range. The float's top
     * is the most its DECIMAL(8,2) column holds.
     */
    private const RULES = [
        'default_use_interval' => ['kind' => 'float', 'default' => 90.0, 'min' => 1, 'max' => 999999.99],
        'default_snooze_days' => ['kind' => 'int', 'default' => 7, 'min' => 1, 'max' => 365],
        'default_setting' => ['kind' => 'string', 'default' => ''],
        'daily_email' => ['kind' => 'bool', 'default' => true],
        'daily_email_hour' => ['kind' => 'int', 'default' => 8, 'min' => 0, 'max' => 23],
        'native_notify_enabled' => ['kind' => 'bool', 'default' => true],
        'native_notify_hour' => ['kind' => 'int', 'default' => 9, 'min' => 0, 'max' => 23],
        'native_notify_lead_days' => ['kind' => 'int', 'default' => 3, 'min' => 0, 'max' => 14],
        'native_notify_past_due' => ['kind' => 'bool', 'default' => true],
    ];

    public function __construct(private mysqli $db, private int $userId)
    {
    }

    /**
     * All of the owner's preferences, typed and each with its default
     * applied (see RULES):
     *
     * - default_use_interval: days; an item's interaction frequency when it
     *   has none of its own
     * - default_snooze_days: how long Snooze hides an item
     * - default_setting: the Setting a new use opens with when the owner has
     *   no earlier use
     * - daily_email, daily_email_hour: whether the daily email goes out, and
     *   at which Eastern hour
     * - native_notify_enabled, native_notify_hour (device-local),
     *   native_notify_lead_days (days before due), native_notify_past_due
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
            $preferences[$name] = self::orDefault($rule, $row[$name] ?? null);
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
                $value = self::orDefault(self::RULES[$name], $value);
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
            if (self::orDefault(self::RULES['daily_email'], $row['daily_email'])
                && self::orDefault(self::RULES['daily_email_hour'], $row['daily_email_hour']) === $hour) {
                $owners[] = (int) $row['id'];
            }
        }
        return $owners;
    }

    /** $value as the rule's kind, or the rule's default when missing, empty or out of range. */
    private static function orDefault(array $rule, $value): float|int|string|bool
    {
        if ($value === null || $value === '') {
            return $rule['default'];
        }
        $range = ['options' => ['min_range' => $rule['min'] ?? null, 'max_range' => $rule['max'] ?? null]];
        $valid = match ($rule['kind']) {
            'float' => filter_var($value, FILTER_VALIDATE_FLOAT, $range + ['flags' => FILTER_NULL_ON_FAILURE]),
            'int' => filter_var($value, FILTER_VALIDATE_INT, $range + ['flags' => FILTER_NULL_ON_FAILURE]),
            'string' => is_scalar($value) ? (string) $value : null,
            'bool' => filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE),
        };
        return $valid ?? $rule['default'];
    }
}
