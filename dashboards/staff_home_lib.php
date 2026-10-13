<?php
/**
 * The staff home (2026-09-29 redesign) — ONE description of the navigation.
 *
 * The old side nav was ~22 buttons. It is now a rail of seven destinations; each phase is a
 * HUB whose steps are cards, and a card opens the same module window the old button did (the
 * modules are untouched). The rail, the home's Stages cards, the hub pages and the live badge
 * refresh all read THIS model, so a count can never say one thing on the rail and another on
 * a card.
 *
 * `key` is the nav_badge_counts.php JSON key for the step's count (null = no count, the card
 * shows `idle` text). `zero` / `one` / `many` are the card's sub-line; `{n}` is the count.
 * `view` instead of `url` marks an in-shell view (Calendar, Settings), opened by a shell
 * function rather than a module window.
 */

function sh_model(string $role): array
{
    $m = [
        'proposal' => [
            'label' => 'Proposal', 'icon' => 'file-text',
            'sub'   => 'Approve sections, log their dates, clear the requirements.',
            'steps' => [
                ['key' => 'approvals_proposal', 'title' => 'Approvals', 'icon' => 'calendar-check-2',
                 'url' => 'admin_batch_review.php?phase=proposal', 'win' => 'Proposal Approvals',
                 'zero' => 'No sections waiting', 'one' => '1 section waiting for you', 'many' => '{n} sections waiting for you'],
                ['key' => 'schedule_proposal', 'title' => 'Schedule', 'icon' => 'calendar-plus',
                 'url' => 'admin_module_dynamic.php?phase=proposal&view=schedule', 'win' => 'Proposal Scheduling',
                 'zero' => 'Nothing to log', 'one' => '1 section to log', 'many' => '{n} sections to log'],
                ['key' => 'proposal', 'title' => 'Checklist', 'icon' => 'file-check',
                 'url' => 'admin_module_dynamic.php?phase=proposal', 'win' => 'Proposal Requirements',
                 'zero' => 'No documents to review', 'one' => '1 document to review', 'many' => '{n} documents to review'],
            ],
        ],
        'stats' => [
            'label' => 'Statistics', 'icon' => 'sigma',
            'sub'   => 'Follow each request from coded data to released results.',
            'steps' => [
                ['key' => null, 'title' => 'Progress', 'icon' => 'git-commit-horizontal',
                 'url' => 'admin_module_dynamic.php?phase=stats&view=pipeline', 'win' => 'Progress',
                 'idle' => 'Where every request stands'],
                ['key' => 'stats', 'title' => 'Requirements', 'icon' => 'list-checks',
                 'url' => 'admin_module_dynamic.php?phase=stats&view=checklist', 'win' => 'Requirements',
                 'zero' => 'No documents to review', 'one' => '1 document to review', 'many' => '{n} documents to review'],
                ['key' => null, 'title' => 'History', 'icon' => 'history',
                 'url' => 'admin_module_dynamic.php?phase=stats&view=history', 'win' => 'Payment History',
                 'idle' => 'Every payment decision, by month'],
            ],
        ],
        'final' => [
            'label' => 'Final', 'icon' => 'graduation-cap',
            'sub'   => 'The same chain for the final defense, then the manuscript call.',
            'steps' => [
                ['key' => 'approvals_final', 'title' => 'Approvals', 'icon' => 'calendar-check-2',
                 'url' => 'admin_batch_review.php?phase=final', 'win' => 'Final Defense Approvals',
                 'zero' => 'No sections waiting', 'one' => '1 section waiting for you', 'many' => '{n} sections waiting for you'],
                ['key' => 'schedule', 'title' => 'Schedule', 'icon' => 'calendar-check',
                 'url' => 'admin_module_dynamic.php?phase=final&view=schedule', 'win' => 'Defense Scheduling',
                 'zero' => 'Nothing to log', 'one' => '1 section to log', 'many' => '{n} sections to log'],
                ['key' => 'final', 'title' => 'Checklist', 'icon' => 'file-check-2',
                 'url' => 'admin_module_dynamic.php?phase=final', 'win' => 'Final Requirements',
                 'zero' => 'No documents to review', 'one' => '1 document to review', 'many' => '{n} documents to review'],
                ['key' => 'plag_release', 'title' => 'Notify', 'icon' => 'send',
                 'url' => 'admin_module_dynamic.php?phase=plag&view=release', 'win' => 'Notify to Send Manuscript',
                 'zero' => 'No one to notify yet', 'one' => '1 group ready for the manuscript call', 'many' => '{n} groups ready for the manuscript call'],
            ],
        ],
        'plag' => [
            'label' => 'Plagiarism', 'icon' => 'shield-check',
            'sub'   => 'Confirm the manuscript, log the payment, release the result.',
            // THREE STEPS, ONE SCREEN EACH (2026-09-29, Claude Design concept). The hub page leads
            // with the "Every scan, at a glance" board (_staff_home.php), the twin of Statistics'.
            // The payment log (History) is reached from the Payment screen's "Logged payments".
            'steps' => [
                ['key' => 'plag_manu', 'title' => 'Manuscript', 'icon' => 'file-text',
                 'url' => 'admin_module_dynamic.php?phase=plag&view=manuscript', 'win' => 'Confirm manuscripts',
                 'zero' => 'No manuscripts to confirm', 'one' => '1 manuscript to confirm', 'many' => '{n} manuscripts to confirm'],
                ['key' => 'plag_log', 'title' => 'Payment', 'icon' => 'receipt',
                 'url' => 'admin_module_dynamic.php?phase=plag&view=payment', 'win' => 'Log payments',
                 'zero' => 'No payments to log', 'one' => '1 payment to log', 'many' => '{n} payments to log'],
                ['key' => 'plag_check', 'title' => 'Result', 'icon' => 'scan-search',
                 'url' => 'admin_module_dynamic.php?phase=plag&view=result', 'win' => 'Release results',
                 'zero' => 'No scans waiting', 'one' => '1 waiting on your check', 'many' => '{n} waiting on your check'],
            ],
        ],
        'insights' => [
            'label' => 'Insights', 'icon' => 'bar-chart-3',
            'sub'   => 'How the year is going, and every research that came before.',
            'steps' => [
                // 2026-10-01: office_analytics.php replaced analytics.php (which now redirects).
                ['key' => null, 'title' => 'Analytics', 'icon' => 'bar-chart-3',
                 'url' => 'office_analytics.php', 'win' => 'Analytics', 'idle' => 'Groups by course, phase and month'],
                ['key' => null, 'title' => 'Visualize', 'icon' => 'layers',
                 'url' => 'office_visualize.php', 'win' => 'Visualizer', 'idle' => 'The whole office in 3D, open anything in it'],
                ['key' => null, 'title' => 'Research history', 'icon' => 'archive',
                 'url' => 'research_history.php', 'win' => 'Research History', 'idle' => 'Archived research, by group'],
            ],
        ],
        'workspace' => [
            'label' => 'Workspace', 'icon' => 'layout-grid',
            'sub'   => 'Messages, people and your own settings.',
            'steps' => [
                ['key' => null, 'title' => 'Messages', 'icon' => 'message-circle',
                 'url' => 'staff_messages.php', 'win' => 'Messages', 'idle' => 'Talk to groups and staff'],
                ['key' => null, 'title' => 'Staff', 'icon' => 'user-cog',
                 'url' => 'admin_accounts.php', 'win' => 'Staff Accounts', 'idle' => 'Accounts and who covers what'],
                ['key' => null, 'title' => 'Calendar', 'icon' => 'calendar-days',
                 'url' => 'office_calendar.php', 'win' => 'Office calendar', 'idle' => 'The days the office is out'],
                ['key' => null, 'title' => 'Settings', 'icon' => 'settings-2',
                 'view' => 'settings', 'idle' => 'Profile, password and theme'],
            ],
        ],
    ];

    // THE STATISTICIAN'S RAIL: Home · Statistics · Plagiarism · Workspace. Her Progress and
    // Requirements carry her own queues (nav_badge_counts.php 'pipeline' / 'checklist'); on the
    // Director's rail Progress is read-only, so it carries no count there. Plagiarism starts with
    // Notify, as her old nav did. Analytics lives in her Workspace; she has no Calendar or Staff.
    if ($role === 'Statistician') {
        $m['stats']['steps'][0] = array_merge($m['stats']['steps'][0], ['key' => 'pipeline',
            'zero' => 'Nothing waiting on you', 'one' => '1 request waiting on you', 'many' => '{n} requests waiting on you']);
        $m['stats']['steps'][1]['key'] = 'checklist';
        $plag = $m['plag'];
        array_unshift($plag['steps'], $m['final']['steps'][3]);   // Notify
        $plag['sub'] = 'Call for the manuscript, confirm it, log the payment, release the result.';
        return [
            'stats' => $m['stats'],
            'plag'  => $plag,
            'workspace' => [
                'label' => 'Workspace', 'icon' => 'layout-grid',
                'sub'   => 'Messages, numbers and your own settings.',
                'steps' => [
                    $m['workspace']['steps'][0],                                  // Messages
                    $m['insights']['steps'][0],                                   // Analytics
                    $m['workspace']['steps'][3],                                  // Settings
                ],
            ],
        ];
    }
    // THE FOCAL PERSON'S RAIL (2026-10-01): Home · Proposal · Final · Workspace. The same two
    // jobs in each phase — check the requirements, put ready sections on a date — opened as
    // module windows on focal_desk.php (her course -> year -> section -> group drill-down, moved
    // out of the old standalone focal.php). Each hub leads with the sections board
    // (_staff_home.php, fp_board()). Counts: fp_counts() in focal_lib.php, also served by
    // nav_badge_counts.php. Workspace holds her rooms (sections + the Unassigned tray), the Archive and Settings.
    if ($role === 'Focal Person') {
        $fp = function (string $ph, string $label, string $icon, string $sub) {
            return [
                'label' => $label, 'icon' => $icon, 'sub' => $sub,
                'steps' => [
                    ['key' => 'fp_req_' . $ph, 'title' => 'Requirements', 'icon' => 'file-check',
                     'url' => 'focal_desk.php?view=requirements&phase=' . $ph, 'win' => $label . ' requirements',
                     'zero' => 'No documents to review', 'one' => '1 document to review', 'many' => '{n} documents to review'],
                    ['key' => 'fp_date_' . $ph, 'title' => 'Defense dates', 'icon' => 'calendar-plus',
                     'url' => 'focal_desk.php?view=schedule&phase=' . $ph, 'win' => $label . ' defense dates',
                     'zero' => 'No section needs a date', 'one' => '1 section needs a date', 'many' => '{n} sections need a date'],
                ],
            ];
        };
        return [
            'proposal' => $fp('proposal', 'Proposal', 'file-text', 'Check what the groups sent, then put ready sections on a date.'),
            'final'    => $fp('final', 'Final', 'graduation-cap', 'The same two jobs for the final defense.'),
            // REPORTS (2026-10-01, asked for by a focal person): who has sent what and who has not,
            // requirement by requirement, how the dates went, every group with its members — and a 3D
            // overview of all of it. focal_reports.php; one dataset, focal_reports_lib.php. No counts.
            'reports'  => [
                'label' => 'Reports', 'icon' => 'bar-chart-3',
                'sub'   => 'Who has sent what, who has not, and how the dates are going. Export or print any of it.',
                'steps' => [
                    ['key' => null, 'title' => 'Reports', 'icon' => 'table-2', 'url' => 'focal_reports.php', 'win' => 'Reports',
                     'idle' => 'Submissions, requirements, schedule, groups'],
                    ['key' => null, 'title' => 'Visualize', 'icon' => 'layers', 'url' => 'focal_reports.php?view=map', 'win' => 'Overview',
                     'idle' => 'Everything you handle, in one 3D view'],
                ],
            ],
            'workspace' => [
                'label' => 'Workspace', 'icon' => 'layout-grid',
                'sub'   => 'Your rooms, the archive and your own settings.',
                'steps' => [
                    ['key' => 'fp_unassigned', 'title' => 'Sections', 'icon' => 'door-open',
                     'url' => 'focal_desk.php?view=sections', 'win' => 'Sections',
                     'zero' => 'Rooms, and groups without one', 'one' => '1 group has no section', 'many' => '{n} groups have no section'],
                    // Finished business, out of the working screens: Proposal / Final, each with
                    // Requirements (groups that cleared) and Schedule (dates already held).
                    ['key' => null, 'title' => 'Archive', 'icon' => 'archive',
                     'url' => 'focal_desk.php?view=archive', 'win' => 'Archive', 'idle' => 'Cleared requirements and held dates'],
                    $m['workspace']['steps'][3],                                  // Settings
                ],
            ],
        ];
    }
    // THE ADMIN'S RAIL (2026-09-30): Home · Accounts · Research · Insights · Workspace. Accounts is
    // her work (approvals + maintenance); Research is the office's workflow, VIEW ONLY — the same
    // screens, opened read-only, and config/db.php refuses any POST from her outside the account
    // handlers. Its cards carry no counts: nothing there is waiting on her.
    if ($role === 'Admin') {
        $ro = fn(array $s, string $title, string $idle) => array_merge($s, ['key' => null, 'title' => $title, 'idle' => $idle]);
        return [
            'accounts' => [
                'label' => 'Accounts', 'icon' => 'users-round',
                'sub'   => 'Approve new staff accounts and keep every account in order.',
                'steps' => [
                    ['key' => 'acct_pending', 'title' => 'Approvals', 'icon' => 'user-check',
                     'url' => 'admin_accounts.php?show=pending', 'win' => 'Account approvals',
                     'zero' => 'No accounts waiting', 'one' => '1 account waiting for approval', 'many' => '{n} accounts waiting for approval'],
                    ['key' => null, 'title' => 'Staff', 'icon' => 'user-cog',
                     'url' => 'admin_accounts.php', 'win' => 'Staff Accounts', 'idle' => 'Create, fix, reset and disable'],
                    ['key' => null, 'title' => 'Students', 'icon' => 'graduation-cap',
                     'url' => 'admin_students.php', 'win' => 'Student Accounts', 'idle' => 'Details, groups, passwords, sign-in'],
                    // 2026-10-01: requests from people who cannot fix it themselves (auth/help.php).
                    ['key' => 'help_open', 'title' => 'Help Desk', 'icon' => 'life-buoy',
                     'url' => 'admin_help.php', 'win' => 'Help Desk',
                     'zero' => 'No open requests', 'one' => '1 request waiting', 'many' => '{n} requests waiting'],
                ],
            ],
            // 2026-10-01: the system itself — who signs in, and the parameters of the whole system.
            'system' => [
                'label' => 'System', 'icon' => 'shield-check',
                'sub'   => 'Who signs in, and the settings for the whole system.',
                'steps' => [
                    ['key' => 'locked_now', 'title' => 'Access log', 'icon' => 'scroll-text',
                     'url' => 'admin_access_log.php', 'win' => 'Access Log',
                     'zero' => 'Every sign-in attempt', 'one' => '1 account locked out', 'many' => '{n} accounts locked out'],
                    ['key' => null, 'title' => 'System settings', 'icon' => 'sliders-horizontal',
                     'url' => 'admin_settings.php', 'win' => 'System Settings', 'idle' => 'Announcement, maintenance, slots, lockout'],
                    ['key' => null, 'title' => 'QR codes', 'icon' => 'qr-code',
                     'url' => 'admin_qr.php', 'win' => 'QR Codes', 'idle' => 'Posters for the RDC office'],
                    // 2026-10-05: the blank forms students download, per school (config/forms.php).
                    ['key' => null, 'title' => 'Forms', 'icon' => 'file-stack',
                     'url' => 'admin_forms.php', 'win' => 'Forms', 'idle' => 'The blank forms students download'],
                ],
            ],
            'research' => [
                'label' => 'Research', 'icon' => 'eye',
                'sub'   => 'The office\'s workflow, view only. Nothing here can be changed from this account.',
                'steps' => [
                    $ro($m['proposal']['steps'][2], 'Proposal checklist', 'Every group\'s proposal requirements'),
                    $ro($m['stats']['steps'][0], 'Statistics progress', 'Where every request stands'),
                    $ro($m['final']['steps'][2], 'Final checklist', 'Every group\'s final requirements'),
                    $ro($m['plag']['steps'][2], 'Plagiarism results', 'Scans and their results'),
                    $ro($m['proposal']['steps'][0], 'Proposal dates', 'Sections on their way to a date'),
                    $ro($m['final']['steps'][0], 'Final dates', 'Sections on their way to a date'),
                ],
            ],
            // Insights (2026-10-01): HER Analytics + Visualizer are about accounts, not papers.
            'insights'  => array_merge($m['insights'], [
                'sub'   => 'Accounts, institutions, courses, reported issues and sign-ins.',
                'steps' => [
                    ['key' => null, 'title' => 'Analytics', 'icon' => 'bar-chart-3', 'url' => 'admin_analytics.php', 'win' => 'Analytics',
                     'idle' => 'Accounts, help desk and sign-ins'],
                    ['key' => null, 'title' => 'Visualize', 'icon' => 'layers', 'url' => 'admin_visualize.php', 'win' => 'Visualizer',
                     'idle' => 'The whole system in 3D, open anything in it'],
                    $m['insights']['steps'][2],                                   // Research history
                ],
            ]),
            'workspace' => [
                'label' => 'Workspace', 'icon' => 'layout-grid',
                'sub'   => 'Your own settings.',
                'steps' => [$m['workspace']['steps'][3]],                     // Settings
            ],
        ];
    }
    return $m;
}

/** The four workflow phases, in office order — the Stages switcher on Home. */
function sh_workflow_keys(string $role = ''): array
{
    if ($role === 'Admin') return ['accounts'];
    if ($role === 'Focal Person') return ['proposal', 'final'];
    return $role === 'Statistician' ? ['stats', 'plag'] : ['proposal', 'stats', 'final', 'plag'];
}

/** A step's live count, or 0. */
function sh_count(array $step, array $counts): int
{
    return $step['key'] !== null ? (int)($counts[$step['key']] ?? 0) : 0;
}

/** Everything waiting on this viewer inside one phase — the rail badge. */
function sh_phase_total(array $phase, array $counts): int
{
    $n = 0;
    foreach ($phase['steps'] as $s) $n += sh_count($s, $counts);
    return $n;
}

/** A card's sub-line for a count. */
function sh_line(array $step, int $n): string
{
    if ($step['key'] === null) return $step['idle'] ?? '';
    if ($n === 0) return $step['zero'];
    return str_replace('{n}', (string)$n, $n === 1 ? $step['one'] : $step['many']);
}

/**
 * Which phase an activity row belongs to, for a hub page's Recent list.
 *
 * activity_logs has no phase column. A row tied to an upload is exact — the item says it
 * (see the item_id ranges in CLAUDE.md). The rest are matched on the words the writers use;
 * anything unrecognised is 'other' and only shows on Home, never on the wrong hub.
 */
function sh_activity_phase(?int $item_id, string $title, string $desc = ''): string
{
    if ($item_id !== null) {
        if (in_array($item_id, [11, 12, 13, 14, 15, 16, 17], true)) return 'proposal';
        if (in_array($item_id, [20, 21, 22, 23, 24, 25, 26, 27], true)) return 'final';
        if ($item_id >= 30 && $item_id <= 37) return 'stats';
        if (in_array($item_id, [4, 40, 41, 42], true)) return 'plag';
    }
    $t = strtolower($title . ' ' . $desc);
    if (strpos($t, 'statistic') !== false || strpos($t, 'payment acknowledged') !== false) return 'stats';
    if (strpos($t, 'plagiar') !== false || strpos($t, 'manuscript') !== false || strpos($t, 'turnitin') !== false) return 'plag';
    if (strpos($t, 'final defense') !== false || strpos($t, 'final') !== false) return 'final';
    if (strpos($t, 'proposal') !== false || strpos($t, 'capsule') !== false || strpos($t, 'adviser') !== false) return 'proposal';
    return 'other';
}
