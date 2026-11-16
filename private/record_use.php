<?php

/**
 * Record Use submission and shared form helpers.
 *
 * Record Use, Quick record, Edit User, Edit Use, and the HTTP record share
 * record_use_input() for the Item, date, Setting, notes, and People. The
 * browser submit owns saving, the answer and remembered Use group. The
 * Setting a new use opens with is the Uses module's lastSetting(), falling
 * back to the default Setting Preference.
 */

require_once __DIR__ . '/classes/Uses.php';
require_once __DIR__ . '/classes/UseByQueue.php';
require_once __DIR__ . '/app_day.php';

/** The browser Record Use save and answer workflow, scoped to its trusted owner. */
final class RecordUse
{
    private string $today;

    public function __construct(private mysqli $db, private int $userId, ?string $today = null)
    {
        $this->today = $today ?? app_today();
    }

    /**
     * Save the posted Uses and return the complete browser answer. Session
     * keys absent from the update map must be left untouched by the emitter.
     * Only recording validation/not-found errors become answers; unexpected
     * failures propagate, including a queue read after the write committed.
     *
     * @return array{status: int, json: ?array, redirect: ?string, session: array}
     */
    public function submit(array $post, bool $ajax): array
    {
        try {
            $ids = (new Uses($this->db, $this->userId))->record(record_use_input($post));
        } catch (InvalidArgumentException | OutOfBoundsException $error) {
            return $ajax ? [
                'status' => $error instanceof OutOfBoundsException ? 404 : 400,
                'json' => ['ok' => false, 'message' => $error->getMessage()],
                'redirect' => null,
                'session' => [],
            ] : [
                'status' => 302,
                'json' => null,
                'redirect' => $this->returnPath($post),
                'session' => ['message' => $error->getMessage()],
            ];
        }
        if ($ajax) {
            $entry = (new UseByQueue($this->db, $this->userId, $this->today))->entry((int) $post['artifact']['id']);
            return [
                'status' => 200,
                'json' => $this->ajaxPayload($post, $ids, $entry),
                'redirect' => null,
                'session' => [],
            ];
        }
        $session = ['message' => $this->successMessage($post, count($ids))];
        if (($post['return_to'] ?? '') !== 'user-edit') {
            $session['record_use_group'] = $this->group($post);
        }
        return [
            'status' => 302,
            'json' => null,
            'redirect' => $this->returnPath($post),
            'session' => $session,
        ];
    }

    private function returnPath(array $post): string
    {
        $personId = (int) ($post['return_player_id'] ?? 0);
        return ($post['return_to'] ?? '') === 'user-edit' && $personId > 0
            ? '/users/edit.php?id=' . $personId
            : '/uses/record-new.php';
    }

    private function successMessage(array $post, int $useCount): string
    {
        $userCount = count($post['user'] ?? []);
        $word = $userCount === 1 ? 'person' : 'people';
        $times = $useCount > 1 ? " $useCount times" : '';
        return 'The interaction with ' . ($post['artifact']['name'] ?? '')
            . " with $userCount $word was recorded$times.";
    }

    /** Keep the submitted date separate from the queue's authoritative Last use. */
    private function ajaxPayload(array $post, array $ids, ?array $entry): array
    {
        return [
            'ok' => true,
            'message' => $this->successMessage($post, count($ids)),
            'artifact_id' => (int) ($post['artifact']['id'] ?? 0),
            'artifact_name' => $post['artifact']['name'] ?? '',
            'artifact_type' => (string) ($entry['type'] ?? ''),
            'use_id' => end($ids),
            'use_date' => $post['useDate'] ?? '',
            'new_use_by_date' => $entry['use_by_date'] ?? null,
            'most_recent_use_date' => $entry['last_use'] ?? null,
            'is_overdue' => ($entry['status'] ?? null) === 'overdue',
        ];
    }

    private function group(array $post): array
    {
        $people = [];
        foreach ($post['user'] ?? [] as $person) {
            $id = (int) ($person['id'] ?? 0);
            if ($id <= 0 || isset($people[$id])) {
                continue;
            }
            $people[$id] = ['id' => $id, 'name' => trim((string) ($person['name'] ?? ''))];
        }
        return [
            'people' => array_values($people),
            'useDate' => (string) ($post['useDate'] ?? ''),
            'Note' => (string) ($post['Note'] ?? ''),
            'savedOn' => $this->today,
        ];
    }
}

/** Apply only the returned session updates, then send the browser answer. */
function emit_record_use_response(array $answer): never
{
    foreach ($answer['session'] as $key => $value) {
        $_SESSION[$key] = $value;
    }
    if ($answer['json'] !== null) {
        http_response_code($answer['status']);
        header('Content-Type: application/json');
        echo json_encode($answer['json']);
        exit;
    }
    redirect_to(url_for($answer['redirect']));
}

/**
 * The posted Use in the Uses module's input names. Record Use, Quick record,
 * Edit User, Edit Use, and the HTTP record all call this map.
 *
 * A post with useDate, Note, NotesTwo, or an artifact list is a Record Use
 * post, and those names win every field. Otherwise the post is Edit Use or
 * the HTTP record: artifact_id, use_date, note, and notesTwo. The Number of
 * uses is included only when useCount is posted, and it is not clamped here.
 *
 * On a Record Use post every Person id is kept, including a blank name and
 * a blank id. On Edit Use, a row that is not a list, or whose name is
 * missing or exactly '', is left off; a name of only spaces stays. A people
 * value that is not a list records no People.
 */
function record_use_input(array $post): array
{
    $record_use = array_key_exists('useDate', $post)
        || array_key_exists('Note', $post)
        || array_key_exists('NotesTwo', $post)
        || is_array($post['artifact'] ?? null);

    if ($record_use) {
        $artifact = $post['artifact'] ?? null;
        $input = [
            'item_id' => is_array($artifact) ? ($artifact['id'] ?? '') : '',
            'use_date' => $post['useDate'] ?? '',
            'setting' => $post['Note'] ?? '',
            'notes' => $post['NotesTwo'] ?? '',
        ];
    } else {
        $input = [
            'item_id' => $post['artifact_id'] ?? '',
            'use_date' => $post['use_date'] ?? '',
            'setting' => $post['note'] ?? '',
            'notes' => $post['notesTwo'] ?? '',
        ];
    }

    $people = $post['user'] ?? null;
    if (!is_array($people)) {
        $input['player_ids'] = [];
    } elseif ($record_use) {
        $input['player_ids'] = array_column($people, 'id');
    } else {
        $personIds = [];
        foreach ($people as $person) {
            if (!is_array($person) || !array_key_exists('name', $person) || $person['name'] === '') {
                continue;
            }
            if (array_key_exists('id', $person)) {
                $personIds[] = $person['id'];
            }
        }
        $input['player_ids'] = $personIds;
    }
    if (array_key_exists('useCount', $post)) {
        $input['count'] = $post['useCount'];
    }
    return $input;
}

function record_use_participants(
    int $player_id,
    string $player_name,
    int $session_player_id,
    string $session_name
): array {
    $people = [
        ['id' => $player_id, 'name' => $player_name],
    ];
    if ($session_player_id > 0 && $session_player_id !== $player_id) {
        $people[] = ['id' => $session_player_id, 'name' => $session_name];
    }
    return $people;
}

/**
 * What the Record Use form opens with. Asked to record again ($again) with
 * a remembered group, it opens with that group's people, date, and setting
 * (the date only on the day the group was saved, so a later visit gets
 * $fallback's date); otherwise it opens with $fallback and offers the remembered group, if any,
 * as "Record another use with this group".
 */
function record_use_form(?array $last_group, bool $again, array $fallback): array
{
    $has_group = !empty($last_group['people']);
    if ($again && $has_group) {
        return [
            'people' => $last_group['people'],
            'useDate' => ($last_group['useDate'] ?? '') !== ''
                && ($last_group['savedOn'] ?? '') === $fallback['useDate']
                ? $last_group['useDate']
                : $fallback['useDate'],
            'Note' => (string) ($last_group['Note'] ?? ''),
            'offerGroup' => null,
        ];
    }
    return $fallback + ['offerGroup' => $has_group ? $last_group : null];
}
