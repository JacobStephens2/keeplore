# Issue #5: proposal outcomes

Source: [GitHub issue #5](https://github.com/JacobStephens2/keeplore/issues/5).

Status: design confirmed, implemented, and locally verified. Canonical terminology lives in [CONTEXT.md](../CONTEXT.md).

## Purpose

Help the owner decide which items to keep or get rid of by answering which items have the most explicit declines and which have most often been proposed before something else was chosen. The issue began with games; the agreed scope covers every tracked entity or object that supports recording an interaction or use.

## Agreed behavior

- The owner records their observations; other people do not need accounts or to respond in Keeplore.
- Record one outcome per item proposal, with optional participants and a note identifying individual objections or other circumstances.
- Offer the two outcomes defined in the glossary. An explicit decline takes precedence when both occur.
- Keep the two counts separate. An unsuccessful proposal leaves the item's last-use date and use schedule unchanged.
- Use an editable proposal date that defaults to today.
- Reasons are an optional free-text note.
- Optionally record the item chosen instead, by selecting an existing item or entering a name without adding it to the collection. This does not automatically record a use of the alternative; actual use is recorded separately.
- Place “Record proposal outcome” beside “Record Use” on the item's page.
- Show proposal history on the item's page, with editing and deletion of individual records. Corrections update the reported counts.
- Link a “Proposal outcomes” report from Analysis. Include separate sortable “Explicit declines” and “Chose something else” columns.
- Report all recorded history by default, with an optional date-range filter.
- Show currently kept items by default, including those marked “Get Rid Of,” with an option to include other tracked items.

## Final review

The user confirmed shared understanding of the complete design, including the broader item scope and “Chose something else” wording.

## Implementation and verification

Proposal records and rankings live in `private/classes/ProposalOutcomes.php`, with pages
under `ui/proposals/`. The item page includes proposal history and a recording link;
Analysis links to the report. The report's currently-kept scope includes primary and
secondary collections, including items marked “Get Rid Of.”

The user confirmed these test interfaces before implementation: record management
(including ownership and unchanged use history) and rankings (counts, filters, sorting).
The full suite passed locally: 139 tests, 204 assertions, including 29 MySQL integration
cases. PHP syntax checks passed for all 104 application/test files. Browser checks covered
recording, editing, item history, sorting, and filters. Local HTTP checks covered CSRF,
creation/deletion, escaped notes, ownership, and invalid date ranges.

The maintainability review kept proposal behavior in the dedicated module, batched
participant ownership checks, and verified transactional edits. A regression test covers
opening proposal history for items without a type.

Before deployment, apply `database/migrations/add-proposal-outcomes.sql`. The migration
was tested for safe reruns. No production database changes or deployment were performed.
