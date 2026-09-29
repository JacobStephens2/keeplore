# Keeplore

Keeplore helps people understand which possessions earn their place through use. Proposal history provides additional context for deciding which items to keep or get rid of.

## Language

**Item**:
A tracked entity or object for which an interaction or use can be recorded, including games and other item types. Any such item can also have proposal outcomes recorded.
_Avoid_: Game (when referring to all supported item types).

**Item proposal**:
A suggestion to a group to use a particular item, as observed by the Keeplore user. Its recorded outcome describes the proposal as a whole, with optional participants and a note identifying individual objections.

**Unsuccessful proposal**:
An item proposal recorded as either an explicit decline or “chose something else.” It is not a use of the item and does not restart the time since its last use.

**Explicit decline**:
A direct refusal of a proposed item, including a temporary refusal such as “not tonight,” optionally accompanied by a recorded reason. This outcome takes precedence when the group also chooses something else.
_Avoid_: Dislike (a decline does not necessarily indicate dislike).

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

**Digital item**:
An item with a digital form. Independent of whether it is kept. An item can be both physical and digital.

**Event**:
An occasion the user plans which items to bring to, such as a beach week. Each planned item can carry the event's own setting (where it will be played), a note, and a packed mark. The players coming to it are chosen from the user's people list.

**Adult**:
A player 18 or older in the year an event starts, counted from their birth year alone. An event's players are summarized as adults, then children by age.

**Players' ages grouping**:
An event's games grouped by who is coming: a group for each child's age and one for adults, each game under the youngest of them its minimum age allows. Distinct from Youngest age, which filters the Items page rather than grouping.

**Event setting**:
Where a planned item will be used at an event, such as the beach. Belongs to the event, not the item, and is distinct from a use's Setting.

**Games in each group**:
The number of planned games an event should keep in every group its grouping makes, such as 2 in each of "6 players · casual" and "6 players · main". It can hold for only some of the chosen tags, such as casual and main but not kids. Games the plan can leave home and still meet it are spare, marked "Can stay home"; a group with fewer games than the number is short and needs all of them.
_Avoid_: Minimum age (an item's own recommended floor).
