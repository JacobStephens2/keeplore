<?php

require_once dirname(__DIR__) . '/functions.php';
require_once __DIR__ . '/Mailer.php';
require_once __DIR__ . '/People.php';

/** Invalid account input: every problem found, in the order checked. */
final class AccountInvalid extends InvalidArgumentException
{
    public function __construct(public readonly array $errors)
    {
        parent::__construct(implode(' ', $errors));
    }
}

/**
 * Keeplore Accounts: the account someone registers and logs in to. Unlike
 * the owner-scoped modules, Accounts looks across every account, because a
 * password reset runs before anyone is logged in; each method names the
 * account it touches.
 *
 * An account is an array of id, first_name, last_name, name (first and last
 * joined), email, username, user_group and person_id (int or null). It never
 * carries the password hash.
 *
 * A profile needs a first and last name of 2 to 255 characters, a valid
 * email of at most 255 characters and a username of 8 to 255 characters;
 * no two accounts share an email or a username. A password must have at
 * least 12 characters, with an uppercase letter, a lowercase letter, a
 * number and a symbol, and a matching confirmation. Invalid input throws
 * AccountInvalid with every problem, and nothing is written.
 *
 * A password reset needs a key emailed to the account's address. A key lasts
 * one day, and a successful reset uses up every key for that address.
 */
final class Accounts
{
    private const COLUMNS = 'id, first_name, last_name, email, username, user_group';
    private const RESET_KEY_LIFETIME = '+1 day';
    private const INVALID_RESET_LINK = 'This reset link is invalid or has expired.';

    /** $now is a Y-m-d H:i:s time; the current time when omitted. */
    public function __construct(private mysqli $db, private Mailer $mailer, private ?string $now = null)
    {
    }

    /** The account with this id, or null if there is none. */
    public function find(int $id): ?array
    {
        $row = $this->rows('SELECT ' . self::COLUMNS . ' FROM users WHERE id = ?', 'i', [$id])[0] ?? null;
        return $row === null ? null : $this->account($row);
    }

    /**
     * The account whose username or email is $usernameOrEmail, if $password
     * is its password. Null alike for an unknown name and a wrong password.
     */
    public function logIn(string $usernameOrEmail, string $password): ?array
    {
        $row = $this->rows(
            'SELECT ' . self::COLUMNS . ', hashed_password FROM users WHERE username = ? OR email = ? ORDER BY username = ? DESC LIMIT 1',
            'sss', [$usernameOrEmail, $usernameOrEmail, $usernameOrEmail]
        )[0] ?? null;
        if ($row === null || !password_verify($password, (string) $row['hashed_password'])) {
            return null;
        }
        return $this->account($row);
    }

    /**
     * Create an account in user group 1 from first_name, last_name, email,
     * username, password and confirm_password, and return it. The developer
     * is sent a new-account notice naming the optional device; a notice that
     * fails is logged and the account is still created.
     *
     * @throws AccountInvalid for a profile or password that breaks the rules.
     */
    public function register(array $input): array
    {
        $profile = self::profile($input);
        $password = self::text($input, 'password', false);
        $errors = [
            ...$this->profileProblems($profile),
            ...self::passwordProblems($password, self::text($input, 'confirm_password', false)),
        ];
        if ($errors !== []) {
            throw new AccountInvalid($errors);
        }

        try {
            $this->statement(
                'INSERT INTO users (first_name, last_name, email, username, hashed_password, user_group) VALUES (?, ?, ?, ?, ?, 1)',
                'sssss', [...array_values($profile), password_hash($password, PASSWORD_BCRYPT)]
            )->close();
        } catch (mysqli_sql_exception $duplicate) {
            // Another registration took the email or username since the check.
            if ($duplicate->getCode() !== 1062) {
                throw $duplicate;
            }
            throw new AccountInvalid($this->profileProblems($profile));
        }
        $account = $this->find((int) $this->db->insert_id);

        try {
            $this->mailer->send(DEV_EMAIL, APP_NAME . ' — New Account Created', $this->newAccountNotice($account, self::text($input, 'device') ?: 'unknown'));
        } catch (RuntimeException $failure) {
            error_log('Failed to send the new-account notice: ' . $failure->getMessage());
        }
        return $account;
    }

    /**
     * Email a reset link to the account with this email. Nothing visible
     * happens when no account has it, so the caller can answer the same way
     * either way.
     *
     * @throws RuntimeException when the email is not sent.
     */
    public function requestPasswordReset(string $email): void
    {
        $accountEmail = $this->rows('SELECT email FROM users WHERE email = ?', 's', [trim($email)])[0]['email'] ?? null;
        if ($accountEmail === null) {
            return;
        }

        $key = bin2hex(random_bytes(32));
        $expires = strtotime(self::RESET_KEY_LIFETIME, $this->now());
        // selector, token and expires are unused, but production's table has them.
        $this->statement(
            'INSERT INTO password_reset_temp (email, `key`, expDate, selector, token, expires) VALUES (?, ?, ?, ?, ?, ?)',
            'sssssi', [$accountEmail, $key, date('Y-m-d H:i:s', $expires), bin2hex(random_bytes(8)), bin2hex(random_bytes(32)), $expires]
        )->close();

        $this->mailer->send($accountEmail, 'Password Reset — ' . APP_NAME, self::resetEmail($accountEmail, $key));
    }

    /** Whether $key is an unexpired reset key for $email. */
    public function resetLinkIsValid(string $email, string $key): bool
    {
        return $this->keyIsValid($email, $key, '');
    }

    /**
     * Set the password of the account with $email, proving the reset with
     * $key, and use up every reset key for that email.
     *
     * @throws AccountInvalid for a bad key or a password that breaks the rules.
     */
    public function resetPassword(string $email, string $key, string $password, string $confirm): void
    {
        $this->db->begin_transaction();
        try {
            // Locking the email's keys makes a second reset with the same key wait, then fail.
            $errors = $this->keyIsValid($email, $key, ' FOR UPDATE') ? [] : [self::INVALID_RESET_LINK];
            $errors = [...$errors, ...self::passwordProblems($password, $confirm)];
            if ($errors !== []) {
                throw new AccountInvalid($errors);
            }
            $this->statement(
                'UPDATE users SET hashed_password = ? WHERE email = ?',
                'ss', [password_hash($password, PASSWORD_BCRYPT), $email]
            )->close();
            $this->statement('DELETE FROM password_reset_temp WHERE email = ?', 's', [$email])->close();
            $this->db->commit();
        } catch (Throwable $failure) {
            $this->db->rollback();
            throw $failure;
        }
    }

    /** Whether $key is an unexpired reset key for $email, read with the $lock clause. */
    private function keyIsValid(string $email, string $key, string $lock): bool
    {
        if ($key === '') {
            return false;
        }
        $keys = $this->rows(
            'SELECT `key` FROM password_reset_temp WHERE email = ? AND expDate >= ?' . $lock,
            'ss', [$email, date('Y-m-d H:i:s', $this->now())]
        );
        foreach ($keys as $row) {
            if (hash_equals($row['key'], $key)) {
                return true;
            }
        }
        return false;
    }

    /** The profile fields of $input, trimmed, in column order. */
    private static function profile(array $input): array
    {
        $profile = [];
        foreach (['first_name', 'last_name', 'email', 'username'] as $field) {
            $profile[$field] = self::text($input, $field);
        }
        return $profile;
    }

    /** $input[$field] as a string, trimmed unless $trim is false; '' when missing or not text. */
    private static function text(array $input, string $field, bool $trim = true): string
    {
        $value = $input[$field] ?? '';
        $value = is_scalar($value) ? (string) $value : '';
        return $trim ? trim($value) : $value;
    }

    /** Every profile rule $profile breaks, with the account $id's own email and username not counting as taken. */
    private function profileProblems(array $profile, int $id = 0): array
    {
        $errors = [];
        foreach (['first_name' => 'First name', 'last_name' => 'Last name'] as $field => $label) {
            $length = mb_strlen($profile[$field]);
            if ($length === 0) {
                $errors[] = "$label cannot be blank.";
            } elseif ($length < 2 || $length > 255) {
                $errors[] = "$label must be between 2 and 255 characters.";
            }
        }

        $email = $profile['email'];
        if ($email === '') {
            $errors[] = 'Email cannot be blank.';
        } elseif (mb_strlen($email) > 255) {
            $errors[] = 'Email must be at most 255 characters.';
        } elseif (preg_match('/\A[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}\z/i', $email) !== 1) {
            $errors[] = 'Email must be a valid format.';
        } elseif ($this->taken('email', $email, $id)) {
            $errors[] = 'That email already belongs to an account. Log in or reset your password.';
        }

        $username = $profile['username'];
        $length = mb_strlen($username);
        if ($length === 0) {
            $errors[] = 'Username cannot be blank.';
        } elseif ($length < 8 || $length > 255) {
            $errors[] = 'Username must be between 8 and 255 characters.';
        } elseif ($this->taken('username', $username, $id)) {
            $errors[] = 'That username is taken. Try another.';
        }
        return $errors;
    }

    /** Whether an account other than $id has $value in $column. */
    private function taken(string $column, string $value, int $id): bool
    {
        return (bool) $this->rows("SELECT id FROM users WHERE $column = ? AND id <> ?", 'si', [$value, $id]);
    }

    /** Every password rule $password and its confirmation break. */
    private static function passwordProblems(string $password, string $confirm): array
    {
        $errors = [];
        if (trim($password) === '') {
            $errors[] = 'Password cannot be blank.';
        } else {
            $rules = [
                'Password must contain 12 or more characters.' => strlen($password) >= 12,
                'Password must contain at least 1 uppercase letter.' => preg_match('/[A-Z]/', $password) === 1,
                'Password must contain at least 1 lowercase letter.' => preg_match('/[a-z]/', $password) === 1,
                'Password must contain at least 1 number.' => preg_match('/[0-9]/', $password) === 1,
                'Password must contain at least 1 symbol.' => preg_match('/[^A-Za-z0-9\s]/', $password) === 1,
            ];
            $errors = array_keys(array_filter($rules, fn (bool $met) => !$met));
        }

        if (trim($confirm) === '') {
            $errors[] = 'Confirm password cannot be blank.';
        } elseif ($password !== $confirm) {
            $errors[] = 'Password and confirm password must match.';
        }
        return $errors;
    }

    private function newAccountNotice(array $account, string $device): string
    {
        $lines = [
            'Name' => $account['name'],
            'Username' => $account['username'],
            'Email' => $account['email'],
            'Date' => gmdate('c', $this->now()),
            'Device' => mb_substr($device, 0, 1024),
        ];
        $html = '<p>A new account was created on ' . h(APP_NAME) . '.</p>';
        foreach ($lines as $label => $value) {
            $html .= '<p>' . $label . ': ' . h($value) . '</p>';
        }
        return $html;
    }

    private static function resetEmail(string $email, string $key): string
    {
        $link = h('https://' . DOMAIN . '/reset-password/reset-password.php?'
            . http_build_query(['key' => $key, 'email' => $email, 'action' => 'reset'], '', '&', PHP_QUERY_RFC3986));
        return '<p>Dear user,</p>'
            . '<p>Please click on the following link to reset your password.</p>'
            . '<p>-------------------------------------------------------------</p>'
            . '<p><a href="' . $link . '" target="_blank">' . $link . '</a></p>'
            . '<p>-------------------------------------------------------------</p>'
            . '<p>Copy the link to your browser. The link will expire after 1 day.</p>'
            . '<p>If you did not request this reset password email, no action is needed. Your password will not be reset.</p>'
            . '<p>Thanks,</p>'
            . '<p>' . h(APP_NAME) . '</p>';
    }

    private function account(array $row): array
    {
        $id = (int) $row['id'];
        return [
            'id' => $id,
            'first_name' => (string) $row['first_name'],
            'last_name' => (string) $row['last_name'],
            'name' => trim($row['first_name'] . ' ' . $row['last_name']),
            'email' => (string) $row['email'],
            'username' => (string) $row['username'],
            'user_group' => (int) $row['user_group'],
            'person_id' => (new People($this->db, $id))->me(),
        ];
    }

    private function now(): int
    {
        return $this->now === null ? time() : strtotime($this->now);
    }

    private function statement(string $sql, string $types, array $params): mysqli_stmt
    {
        $stmt = $this->db->prepare($sql);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        return $stmt;
    }

    private function rows(string $sql, string $types, array $params): array
    {
        $stmt = $this->statement($sql, $types, $params);
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        return $rows;
    }
}
