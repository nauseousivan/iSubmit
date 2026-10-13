<?php
// dashboards/group_roster_lib.php — "View names": the people behind a group card.
//
// Every staff screen files a group under its LEADER — that is who owns the uploads, who the
// booking is keyed to and whose name is on the tile. But the office signs a clearance, logs a
// defense and hands over a ticket for a whole group, and it needs the actual proponents' names
// before it does any of that. So each card carries one "View names" button and this is where
// the roster behind it comes from.
//
// Its own file because two screens that never see each other need it: the review inbox
// (admin_module_dynamic.php) and the scheduling screen (admin_defense_schedule.php), which is
// included and exits long before the inbox's own helpers are ever defined.

/**
 * Every member of a research group, leader first.
 *
 * Fetched in one query for all groups on the screen rather than per card, since a screen can
 * hold thirty of them.
 *
 * @param int[] $leaderIds
 * @return array<int, array<int, array>>  [leader_id => [member rows, leader at index 0]]
 */
function ws_group_rosters(PDO $pdo, array $leaderIds): array
{
    $leaderIds = array_values(array_unique(array_filter(array_map('intval', $leaderIds))));
    if (!$leaderIds) return [];
    $in = implode(',', $leaderIds);

    // leader_id IS NULL marks the leader's own row; COALESCE folds both into one group key, and the
    // ORDER BY puts the leader at index 0 so the caller never has to hunt for them.
    $rows = $pdo->query("
        SELECT user_id, username, email, student_number, profile_pic, leader_id,
               COALESCE(leader_id, user_id) AS grp
        FROM users
        WHERE user_id IN ($in) OR leader_id IN ($in)
        ORDER BY grp, (leader_id IS NULL) DESC, username ASC
    ")->fetchAll(PDO::FETCH_ASSOC);

    $out = [];
    foreach ($rows as $r) $out[(int) $r['grp']][] = $r;
    return $out;
}

/**
 * The roster as one markup block: the pill button and the panel it opens.
 *
 * Returned as a two-key array rather than printed, because the button and the panel do not sit
 * next to each other — the button belongs on the identity line, the panel below it — and every
 * caller nests them differently. Both are empty strings when the group has no roster at all, so
 * a caller can drop them in unconditionally.
 *
 * Toggled by wsToggleNames(id, btn), which each host page defines.
 *
 * @param array $roster  rows from ws_group_rosters(), leader first
 * @param string $domId  unique id tying the button to its panel
 */
function ws_names_markup(array $roster, string $domId): array
{
    if (!$roster) return ['button' => '', 'panel' => ''];
    $n = count($roster);
    $id = htmlspecialchars($domId, ENT_QUOTES);

    $button = '<button type="button" class="ws-names-btn" onclick="wsToggleNames(\'' . $id . '\', this); event.stopPropagation();"'
        . ' title="Show the members and proponents registered under this group">'
        . '<i data-lucide="users"></i> View names<span class="ws-names-n">' . $n . '</span></button>';

    $panel = '<div id="' . $id . '" class="ws-names-panel" style="display:none;" onclick="event.stopPropagation();">';
    foreach ($roster as $i => $mem) {
        $pfp = $mem['profile_pic'] ?: 'https://api.dicebear.com/9.x/bottts/svg?seed=' . urlencode($mem['username']);
        $meta = $i === 0 ? 'Group leader' : 'Member';
        if (!empty($mem['student_number'])) $meta .= ' &middot; ' . htmlspecialchars($mem['student_number']);
        if (!empty($mem['email']))          $meta .= ' &middot; ' . htmlspecialchars($mem['email']);
        $panel .= '<div class="ws-name-row">'
            . '<img src="' . htmlspecialchars($pfp) . '" alt=""'
            . ' onerror="this.onerror=null;this.src=\'https://api.dicebear.com/9.x/bottts/svg?seed='
            . urlencode($mem['username']) . '\';">'
            . '<div class="ws-name-text"><strong>' . htmlspecialchars($mem['username']) . '</strong>'
            . '<span>' . $meta . '</span></div></div>';
    }
    if ($n === 1) {
        // Not an error — plenty of groups are registered by the leader alone. Saying so beats an
        // empty panel the office has to interpret.
        $panel .= '<div class="ws-names-solo">No other members are registered under this group.</div>';
    }
    $panel .= '</div>';

    return ['button' => $button, 'panel' => $panel];
}
