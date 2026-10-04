# Keeplore

Keeplore helps people understand which possessions earn their place through use. Proposal history provides additional context for deciding which items to keep or get rid of.

## Language

**Item**:
A tracked entity or object for which an interaction or use can be recorded, including games and other item types. Any such item can also have proposal outcomes recorded. Deleting an item deletes its uses and item proposals; a proposal where it was the item chosen instead keeps the name.
_Avoid_: Game (when referring to all supported item types).

**Type**:
One of the owner's own categories for items, such as table game, book or film. Each item has at most one. Deleting a type moves its items to another of the owner's types or leaves them without one.
_Avoid_: Artifact type, ObjectType.

**Item tag**:
One of the owner's free-form labels on an item, such as portable or beach-safe, stored lowercase with spaces collapsed and at most 64 characters. Distinct from BoardGameGeek's own categories. Every read and write of them goes through the Items module, scoped to the owner.
_Avoid_: Category.

**Use**:
A recorded occasion when an item was used, on a date, optionally with people, a Setting (where it was used) and notes. One Record Use submit can record several identical uses at once through its Number of uses. Every read of an owner's uses, their people and the Setting of their most recently recorded use goes through one module, scoped to the owner. Distinct from an item proposal, which is never a use.

**Last use**:
The date of an item's most recent use, or none if it has never been used. The use-by date, the Items page, To get rid of and the agent collection list all read it from one rule.

**Account**:
The Keeplore account someone registers and logs in to, holding their name, email, username and password; it owns the people list, items and Preferences. A password reset needs the key emailed to the account's address, which lasts one day and is used up by the reset.
_Avoid_: User (except in the table's name).

**Agent key**:
A key the owner issues on Settings so a remote agent can read their items, uses and proposals and flip kept over HTTP, and nothing else (ADR-0002). Its token is shown once, when issued; revoking it stops it working at once.
_Avoid_: API key (the master key).

**Person**:
Someone on the owner's people list who can be recorded on uses, item proposals and events. One person can be marked as the owner themself.
_Avoid_: User (the Keeplore account the list belongs to), Player (except for an event's players).

**Playgroup**:
The people from the owner's people list whom the owner is choosing items for on Choose for group. Its size is the number of distinct people in it.
_Avoid_: User group (the page's label), Group (alone).

**Item proposal**:
A suggestion to a group to use a particular item, as observed by the Keeplore user. Its recorded outcome describes the proposal as a whole, with optional participants and a note identifying individual objections.

**Unsuccessful proposal**:
An item proposal recorded as either an explicit decline or “chose something else.” It is not a use of the item and does not restart the time since its last use.

**Explicit decline**:
A direct refusal of a proposed item, including a temporary refusal such as “not tonight,” optionally accompanied by a recorded reason. This outcome takes precedence when the group also chooses something else.
_Avoid_: Dislike (a decline does not necessarily indicate dislike).

**Aversion**:
A legacy record, from before item proposals, that a person was averse to an item on a date. It is recorded and edited only on the archived Aversion pages, and Choose for group shows the latest one. Distinct from an Explicit decline, which is the outcome of an item proposal.
_Avoid_: Response (the legacy table also holds plays), Dislike.

**Chose something else**:
A proposal outcome in which the group selected something else without explicitly declining the proposed item.
_Avoid_: Explicit decline (when another selection is the only observed response).

**Rejection count**:
The number of an item's proposals recorded as explicit declines within the selected date range, or all recorded history by default, counting each proposal once regardless of how many people objected.
_Avoid_: Combined unsuccessful-proposal count.

**Chose something else count**:
The number of an item's proposals recorded as “chose something else” within the selected date range, or all recorded history by default, counted separately from explicit declines.

**Item chosen instead**:
The alternative selected by the group, optionally recorded as an existing item or a name without adding it to the collection. Selection does not establish actual use, so recording it does not create a use or change the alternative's use history.

**Use-by date**:
The date by which an item should next be used, shown as "Interact by". With no recorded use, or a most recent use before acquisition, it is the acquisition date plus the item's interval; otherwise the most recent use plus twice the interval. An item with neither an acquisition date nor a use has none. A use-by date before today is overdue.
_Avoid_: Play by.

**Use-by queue**:
The kept items not flagged to get rid of, plus the secondary collection when asked for, each with its last use, use-by date and whether it is overdue, due today or upcoming. The dashboard, Interact By, the daily email and notifications show it.
_Avoid_: Interaction queue.

**Daily email**:
The email listing the owner's overdue items, items due today and items due in the coming week from the Use-by queue, sent at the hour set in their Preferences while it is on. Nothing is sent when nothing is due.

**Interaction frequency**:
An item's own interval in days, used for its use-by date in place of the default use interval, one of the owner's Preferences. A page's interval setting changes only the default, never an item's own frequency.

**Kept**:
An item the user has chosen to keep in the primary collection.
_Avoid_: Tracked (a legacy form label for the same idea).

**Owned**:
Agent shorthand for Kept. An item is owned exactly when it is kept; format flags never affect ownership.
_Avoid_: Owned (as a separate status), In collection.

**Secondary collection**:
A separate overflow collection an item can belong to whether or not it is kept.

**To get rid of**:
An item flagged for removal, whether or not it is kept.

**Snoozed**:
An item hidden from the dashboard's priority queue until its snooze-until date, set for the owner's default snooze days or a number chosen when snoozing. Snoozing changes no use and no use-by date.

**Physical item**:
An item with a physical form. Independent of whether it is kept.

**Youngest age**:
The age of the youngest person expected to use an item, as chosen on the Items page. It keeps items whose recorded minimum recommended age is at or below it; an item with no recorded minimum age is left out.
_Avoid_: Minimum age (that is the item's own recommended floor).

**Community age**:
The age BoardGameGeek's player-age poll recommends for a game, such as 6 for "6+". Search BGG's "good for ages 6+" keeps games whose community age is 6 or under. Distinct from an item's own recommended minimum age.

**Best player count**:
A player count BoardGameGeek's community voted Best for a game. An open-ended vote such as "Best with 9+" counts for every larger group, unless Search BGG is told to leave open-ended Best out; then it counts only at 9.

**BGG reviewer**:
A BoardGameGeek user, such as Gyges, whose ratings and comments on the owner's items are imported or entered by hand. The owner names their own reviewer on Settings, whether their own BGG account or someone else's, and may have older ones from earlier imports. Settings imports all of their ratings in the background. Items and Search BGG show one column per reviewer; Search BGG fills it from the owner's items that link to the game, kept or not.

**BGG rating**:
A BGG reviewer's rating, comment or both on one of the owner's items, imported from BoardGameGeek or entered by hand on Edit Item. The import never replaces a hand entry; Request data on Edit Item replaces one only when BoardGameGeek has an entry. Every read and write of them goes through one module, scoped to the owner.

**BGG average**:
BoardGameGeek's average rating for the game an item links to, copied onto the item by the BGG average import.

**Type for BoardGameGeek items**:
The owner's type, such as table game, for items filled from a BoardGameGeek match on Create Item, set on Settings. A type the owner already picked on the form stays.
_Avoid_: BGG type (reads like BoardGameGeek's own subtype, such as boardgame or expansion).

**Preferences**:
The owner's own defaults and reminders, set on Settings: the default use interval, default snooze days, default Setting for a use, the daily email and its hour, and native notifications. Each has one default and one allowed range.
_Avoid_: Settings (the page), User settings.

**Digital item**:
An item with a digital form. Independent of whether it is kept. An item can be both physical and digital.

**Event**:
An occasion the user plans which items to bring to, such as a beach week. Each planned item can carry the event's own setting (where it will be played), a note, and a packed mark. The players coming to it are chosen from the user's people list.

**Event plan**:
An Event's planned games as its page shows them under the chosen grouping: the groups, the plain-text list, the games that can stay home and the smaller list, the shopping list, and the players' ages. Distinct from the Event itself, which holds the planned items, players and dates.

**Adult**:
A player 18 or older in the year an event starts, counted from their birth year alone. An event's players are summarized as adults, then children by age.

**Players' ages grouping**:
An event's games grouped by who is coming: a group for each child's age and one for adults, each game under the youngest of them its minimum age allows. Distinct from Youngest age, which filters the Items page rather than grouping.

**Event setting**:
Where a planned item will be used at an event, such as the beach. Belongs to the event, not the item, and is distinct from a use's Setting.

**Use group**:
The people, date, and Setting of the use last saved from Record Use. "Record another use with this group" reopens Record Use with them filled in, so only the item is left to pick. A Quick record does not change it.
_Avoid_: Group (alone; proposals and event groupings also have groups).

**Quick record**:
A use recorded without leaving the page: from the Quick record popup on the dashboard or Use By, or from Edit User's form. A quick record does not change the Use group.

**Quick item action**:
Snoozing an item, keeping or no longer keeping it, or marking it to get rid of or restoring it, without leaving the page: from the dashboard, Interact By, Items, To get rid of, Create Item's lookup or a Daily email link. Each changes only its own field of the item.

**Number of uses**:
How many identical uses one Record Use submit saves, from 1 to 20, such as two rounds of Old Maid with the same family on the same day.

**Games in each group**:
The number of planned games an event should keep in every group its grouping makes, such as 2 in each of "6 players · casual" and "6 players · main". It can hold for only some of the chosen tags, such as casual and main but not kids. Games the plan can leave home and still meet it are spare, marked "Can stay home"; a group with fewer games than the number is short and needs all of them.
_Avoid_: Minimum age (an item's own recommended floor).
