<?php
// dashboards/staff_settings_lib.php — what the staff Settings screen and its handler share.

/**
 * The avatar gallery. The student profile's set (profile.php "Legends Gallery": DiceBear
 * "adventurer", same seeds) plus four more, so staff and students pick from one family.
 * The handler accepts ONLY these URLs (or an uploaded photo), never an arbitrary link.
 */
function ss_avatar_urls(): array
{
    $seeds = ['Felix', 'Aneka', 'Caleb', 'Jocelyn', 'Ginger', 'Boots', 'Jasper', 'Sheba',
              'Mimi', 'Leo', 'Nala', 'Oscar'];
    return array_map(fn($s) => 'https://api.dicebear.com/9.x/adventurer/svg?seed=' . $s, $seeds);
}

/** "Lea Paddayuman" -> "LP". */
function ss_initials(string $name): string
{
    $parts = array_slice(preg_split('/\s+/', trim($name)) ?: [], 0, 2);
    return strtoupper(implode('', array_map(fn($w) => mb_substr($w, 0, 1), $parts))) ?: '?';
}

/**
 * The line under the name on the Settings card: what this account covers, in the office's
 * words. Never hands the ALL marker to aa_school_tag() (it would say MCNP).
 */
function ss_scope_line(array $user, string $role_label): string
{
    $dept = (string)($user['department'] ?? '');
    if ($dept === (defined('AA_ALL_INSTITUTIONS') ? AA_ALL_INSTITUTIONS : 'ALL') || $dept === '') {
        return $role_label . ', both institutions';
    }
    $tag = function_exists('aa_school_tag') ? aa_school_tag($dept) : $dept;
    return $role_label . ', ' . $tag;
}
