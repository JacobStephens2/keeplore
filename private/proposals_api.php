<?php

require_once __DIR__ . '/classes/ApiCaller.php';
require_once __DIR__ . '/classes/ProposalOutcomes.php';

/**
 * GET /proposals.php: the owner's proposal history report (spec #10,
 * ticket #18), through the ProposalOutcomes module. The query's start and
 * end (Y-m-d), include_other, sort (item_name, explicit_declines or
 * chose_something_else) and direction (asc or desc) shape the report. The
 * master key names the owner in the query's user_id.
 *
 * Returns [status, response fields]; on success the fields carry the
 * proposals and the user_id they belong to.
 */
function report_proposals_over_api(mysqli $db, ApiCaller $caller, array $query): array {
  $owner = $caller->owner($query['user_id'] ?? null);
  if ($owner === null) {
    return [400, ['message' => 'Missing or invalid required parameter: user_id']];
  }

  $include_other = isset($query['include_other']) && $query['include_other'] !== '0' && $query['include_other'] !== '';
  try {
    $proposals = (new ProposalOutcomes($db, $owner))->report(
      (string) ($query['start'] ?? ''),
      (string) ($query['end'] ?? ''),
      $include_other,
      (string) ($query['sort'] ?? 'explicit_declines'),
      (string) ($query['direction'] ?? 'desc')
    );
  } catch (InvalidArgumentException $invalid) {
    return [400, ['message' => $invalid->getMessage()]];
  }
  return [200, ['proposals' => $proposals, 'user_id' => $owner]];
}

?>
