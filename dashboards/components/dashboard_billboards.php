<?php
// dashboards/components/dashboard_billboards.php — the billboard strip on the student home.
//
// Promo-style status cards under the milestone row, in the pattern payment apps use for their
// "for you" banners: one solid, moving card that states where the group actually is and takes
// them straight there, plus a preview of the stage that has not opened yet.
//
// Read-only. Every value it uses was already fetched by student.php for the stage cards.
//
// Expects: $df_booking_peek, $df_stage_peek, $prop_booking_peek, $prop_stage_peek,
//          $manuscript_released (?string — the released_at stamp, or null),
//          $plag_phase_peek (string — plagiarism_checks.status),
//          $prop_p1_peek / $final_p1_peek (bool — has each phase cleared its requirements),
//          $unlock_final (bool — is the Final card open at all; it waits on statistics).

// Promote whichever defense is actually in front of the group. Proposal comes first in the
// journey, so it wins until it is SETTLED (scheduled, and the day has passed); after that the
// final defense takes the slot.
$bb_prop_settled = ($prop_booking_peek !== null)
    && $prop_stage_peek['key'] === 'scheduled'
    && !empty($prop_booking_peek['defense_date'])
    && $prop_booking_peek['defense_date'] < df_today();

// A phase is worth promoting once the group has EARNED it — cleared its phase 1 — not only once
// they have already applied. "Book your defense" is the single most useful thing the strip can
// say, and it used to say nothing at exactly that moment.
$bb_prop_can = ($prop_booking_peek !== null) || !empty($prop_p1_peek);

// FINAL TAKES OVER THE MOMENT THE GROUP IS ACTUALLY IN IT — its seven requirements all approved,
// or its booking past merely being submitted. The strip follows the group; it is not the
// proposal's card with a final card bolted on the end. Deliberately NOT "the proposal date has
// passed": a group can be deep in Final while their proposal defense is days away.
//
// Gated on $unlock_final first. Final waits for statistics now, and there are legacy bookings
// from the retired student-picks-a-date flow whose groups never went near the statistician —
// without this the strip would shout "book your final defense" over a card that is locked shut.
$bb_final_can = $unlock_final
    && (!empty($final_p1_peek)
        || ($df_booking_peek !== null
            && !in_array($df_stage_peek['key'], ['apply', 'appreview'], true)));

if ($bb_final_can) {
    $bb_phase   = 'final';
    $bb_booking = $df_booking_peek;
    $bb_state   = $df_stage_peek['key'];
    $bb_show    = true;
} elseif (!$bb_prop_settled && $bb_prop_can) {
    $bb_phase   = 'proposal';
    $bb_booking = $prop_booking_peek;
    $bb_state   = $prop_stage_peek['key'];   // df_stage_meta(null) is already 'apply'
    $bb_show    = true;
} else {
    $bb_phase   = 'proposal';
    $bb_booking = null;
    $bb_state   = '';
    $bb_show    = false;   // nothing earned yet — the milestone cards already say so
}
$bb_eyebrow = $bb_phase === 'proposal' ? 'Proposal Defense' : 'Final Defense';
$bb_module  = $bb_phase === 'proposal' ? 'module_proposal.php' : 'module_final.php';
$bb_panel   = $bb_phase === 'proposal' ? 'zoom-proposal' : 'zoom-final';

// [from, to, icon, title, subtitle, call to action]
// STUDENTS DO NOT BOOK OR PICK DATES (2026-09-19): the Focal Person endorses the whole section,
// the Coordinator confirms, the Secretary finalises. Nothing here may invite the group to book,
// pick or reschedule — there is no button for any of that on the other side of the card.
// 'appreview' / 'pick' / 'rejected' only come from legacy rows of the retired flow.
$bb_waiting_date = ['#3b1f8f', '#4c1d95', 'calendar-clock',
                    $bb_phase === 'proposal' ? 'Requirements cleared' : 'Final requirements cleared',
                    'Your focal person schedules your whole section together. Your date will appear here.',
                    'View status'];
$bb_cfg = [
    // Earned it, no date yet. The one the strip used to stay silent for.
    'apply'     => $bb_waiting_date,
    'pick'      => $bb_waiting_date,
    'appreview' => ['#b45309', '#78350f', 'clock', 'Documents in review',
                    'The research office is checking your documents. Your focal person schedules you once they are approved.', 'View status'],
    'waiting'   => ['#b45309', '#78350f', 'calendar-clock', 'Defense date proposed',
                    'Your focal person put your section forward. It is not final until the research office confirms it.', 'View status'],
    'scheduled' => ['#047857', '#064e3b', 'calendar-check', 'Your defense is scheduled',
                    $bb_phase === 'proposal'
                        ? 'Bring your printed forms on the day. Your Statistics card is now open.'
                        : 'Bring your printed forms on the day. Groups are heard in slot order.', 'View ticket'],
    'rejected'  => ['#b91c1c', '#7f1d1d', 'calendar-x', 'That date did not push through',
                    'Your focal person will propose another date for your section.', 'View status'],
    'cancelled' => ['#b91c1c', '#7f1d1d', 'calendar-x', 'Your defense date was withdrawn',
                    'Your focal person will propose a new date. Your files are safe — nothing for you to do.', 'View status'],
];
$bb = $bb_cfg[$bb_state] ?? null;
// The eagle's face for each state (2026-10-05): cleared = happy, waiting on the office = thinking,
// a published date = celebrating, a date that fell through = oops.
$bb_pose = ['apply' => 'happy', 'pick' => 'happy', 'appreview' => 'thinking', 'waiting' => 'thinking',
            'scheduled' => 'celebrating', 'rejected' => 'oops', 'cancelled' => 'oops'][$bb_state] ?? null;
// Scheduled groups land straight on their ticket; every other state lands on the panel that
// has their next action. Mirrors the existing `?item=` deep-link module_final already honours.
// Both modules now open their schedule sheet on ?view=schedule, so every un-scheduled state lands
// the group directly in the panel that holds its next action rather than on the card's front page.
$bb_url = ($bb_state === 'scheduled')
    ? $bb_module . '?ticket=1'
    : $bb_module . '?view=schedule';
// Only a date that still stands gets a chip. A proposed one says so and carries no slot (it is
// not a booking until the Secretary signs); a cancelled row keeps its old defense_date after
// cancel_batch, so without the state check the chip advertised a date that had been withdrawn.
$bb_date = null;
if ($bb_booking && !empty($bb_booking['defense_date'])) {
    $bb_day = date('D, M j', strtotime($bb_booking['defense_date']));
    if ($bb_state === 'scheduled') {
        $bb_date = $bb_day . (!empty($bb_booking['slot_no']) ? ' · Slot ' . (int)$bb_booking['slot_no'] : '');
    } elseif ($bb_state === 'waiting') {
        $bb_date = 'Proposed · ' . $bb_day;
    }
}

// ── RELEASED TO SEND THE MANUSCRIPT ──────────────────────────────────────────────────────────
// Once the office says the defense went ahead, this REPLACES whatever the card was showing —
// it does not add a second one. The defense ticket is behind them; sending the manuscript is the
// only thing in front of them, and it happens in the Plagiarism card.
//
// Must sit ABOVE the early return below: a released group's booking still exists, but the guard
// is about promoting a defense, and this is no longer about the defense.
if (!empty($manuscript_released)) {
    $bb_eyebrow = 'Final Manuscript';
    $bb_panel   = 'zoom-plag';
    $bb_url     = 'module_plagiarism.php';
    $bb_show    = true;
    $bb_date    = null;

    // The card keeps moving with them: every plagiarism phase has its own promo, so the strip is
    // never stale and never says "submit" to a group that already has.
    $bb_plag_cfg = [
        'Phase 1: Pending Manuscript' => ['#1d4ed8', '#1e3a8a', 'file-up', 'Send your final manuscript',
            'Your defense is done and the research office has opened your Plagiarism card. Submit your final manuscript to start the check.', 'Submit now'],
        'Phase 1: Manuscript Review' => ['#b45309', '#78350f', 'clock', 'Manuscript with the office',
            'They are checking that the right document arrived. You will be told when to pay.', 'View status'],
        'Phase 1: Manuscript Returned' => ['#b91c1c', '#7f1d1d', 'undo-2', 'Manuscript sent back',
            'The research office needs a corrected copy before the scan can start.', 'See what to fix'],
        'Phase 2: Form Download' => ['#b45309', '#78350f', 'wallet', 'Pay at the finance office',
            'Print RDC Form 13, pay the scan fee, then upload your validated form and receipt.', 'Get the form'],
        'Phase 3: Payment Verification' => ['#b45309', '#78350f', 'clock', 'Payment being verified',
            'Your validated form and receipt are with the office. Your control number comes next.', 'View status'],
        'Phase 4: Registered' => ['#4338ca', '#312e81', 'search', 'Turnitin check running',
            'You are registered and there is nothing left to upload — the result will appear here.', 'View status'],
        'Phase 5: Revision Requested' => ['#b91c1c', '#7f1d1d', 'alert-triangle', 'Plagiarism revision needed',
            'The Turnitin report flagged your manuscript. Read it, revise, and re-upload.', 'Read the report'],
        'Phase 5: Cleared' => ['#047857', '#064e3b', 'award', 'Cleared — ready to bookbind',
            'Your paper passed the Turnitin check. Congratulations!', 'View clearance'],
    ];
    $bb = $bb_plag_cfg[$plag_phase_peek ?? ''] ?? $bb_plag_cfg['Phase 1: Pending Manuscript'];
}

if (!$bb_show || !$bb) {
    return;
}
?>
<style>
    .bb-strip { display: grid; gap: 14px; margin: 4px 0 22px; }
    @media (min-width: 900px) { .bb-strip { grid-template-columns: 1.35fr 1fr; } }

    .bb-card {
        position: relative; overflow: hidden; isolation: isolate;
        border-radius: 20px; padding: 20px 22px; min-height: 132px;
        display: flex; flex-direction: column; justify-content: center;
        color: #fff; border: none; width: 100%; text-align: left;
        font-family: 'Inter', system-ui, sans-serif; cursor: pointer;
        box-shadow: 0 14px 28px -14px rgba(15, 23, 42, .45);
        transition: transform .22s cubic-bezier(.4, 0, .2, 1), box-shadow .22s;
    }
    .bb-card:hover { transform: translateY(-3px); box-shadow: 0 20px 34px -14px rgba(15, 23, 42, .5); }
    .bb-card:active { transform: translateY(-1px); }

    /* A slow drifting highlight: motion with nothing that jumps or distracts. */
    .bb-card::after {
        content: ''; position: absolute; inset: -40% -10%; z-index: -1;
        background: radial-gradient(circle at 30% 30%, rgba(255, 255, 255, .22), transparent 60%);
        animation: bbDrift 9s ease-in-out infinite alternate;
    }
    @keyframes bbDrift {
        from { transform: translate3d(-6%, -4%, 0) scale(1); }
        to   { transform: translate3d(8%, 6%, 0) scale(1.15); }
    }
    @media (prefers-reduced-motion: reduce) { .bb-card::after { animation: none; } }

    .bb-eyebrow {
        display: inline-flex; align-items: center; gap: 6px;
        font-size: 10px; font-weight: 800; letter-spacing: .12em; text-transform: uppercase;
        opacity: .78; margin-bottom: 8px;
    }
    .bb-title { font-size: 19px; font-weight: 800; line-height: 1.2; margin: 0 0 5px; }
    .bb-sub { font-size: 12.5px; line-height: 1.45; opacity: .82; margin: 0; max-width: 46ch; }
    .bb-foot { display: flex; align-items: center; gap: 10px; margin-top: 14px; flex-wrap: wrap; }
    .bb-cta {
        display: inline-flex; align-items: center; gap: 7px;
        background: #fff; color: #0f172a; border-radius: 999px;
        padding: 9px 16px; font-size: 12.5px; font-weight: 750;
    }
    .bb-chip {
        display: inline-flex; align-items: center; gap: 6px;
        background: rgba(255, 255, 255, .2); border-radius: 999px;
        padding: 7px 13px; font-size: 12px; font-weight: 700;
    }
    .bb-glyph { position: absolute; right: -14px; bottom: -20px; z-index: -1; width: 128px; height: 128px; opacity: .16; }
    /* the eagle sits in the corner the glyph used to, looking the part for the state */
    .bb-eagle { position: absolute; right: 2px; bottom: -6px; z-index: 0; width: 118px; height: 112px; object-fit: contain;
        pointer-events: none; filter: drop-shadow(0 8px 14px rgba(0, 0, 0, .25)); transform-origin: 50% 90%;
        transition: transform .3s cubic-bezier(.34, 1.56, .64, 1); }
    .bb-card:hover .bb-eagle { transform: translateY(-4px) rotate(-3deg); }
    .bb-card:has(.bb-eagle) .bb-sub, .bb-card:has(.bb-eagle) .bb-title { padding-right: 92px; }
    @media (max-width: 640px) { .bb-eagle { width: 92px; height: 88px; right: -4px; } .bb-card:has(.bb-eagle) .bb-sub, .bb-card:has(.bb-eagle) .bb-title { padding-right: 70px; } }

    /* the publication card keeps the shape but drops the invitation */
    .bb-card.is-locked { cursor: default; box-shadow: 0 10px 22px -14px rgba(15, 23, 42, .4); }
    .bb-card.is-locked:hover { transform: none; }
    .bb-card.is-locked .bb-cta { background: rgba(255, 255, 255, .22); color: #fff; }

    @media (max-width: 640px) {
        .bb-card { padding: 17px 18px; border-radius: 18px; min-height: 118px; }
        .bb-title { font-size: 17px; }
    }
</style>

<div class="bb-strip">
    <button type="button" class="bb-card"
        style="background: linear-gradient(118deg, <?= $bb[0] ?> 0%, <?= $bb[1] ?> 100%);"
        onclick="pushView('<?= $bb_panel ?>','<?= $bb_url ?>')">
        <?php if ($bb_pose): ?>
            <img class="bb-eagle" src="../assets/mascot/eagle/<?= $bb_pose ?>.svg" alt="" aria-hidden="true" draggable="false">
        <?php else: ?>
        <svg class="bb-glyph" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.2">
            <rect x="3" y="4" width="18" height="18" rx="3" />
            <path d="M16 2v4M8 2v4M3 10h18" />
        </svg>
        <?php endif; ?>
        <span class="bb-eyebrow"><i data-lucide="<?= $bb[2] ?>" style="width:13px;height:13px;"></i> <?= htmlspecialchars($bb_eyebrow) ?></span>
        <h4 class="bb-title"><?= htmlspecialchars($bb[3]) ?></h4>
        <p class="bb-sub"><?= htmlspecialchars($bb[4]) ?></p>
        <span class="bb-foot">
            <span class="bb-cta"><?= htmlspecialchars($bb[5]) ?>
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                    <line x1="5" y1="12" x2="19" y2="12" /><polyline points="12 5 19 12 12 19" />
                </svg>
            </span>
            <?php if ($bb_date): ?>
                <span class="bb-chip"><i data-lucide="calendar" style="width:13px;height:13px;"></i> <?= $bb_date ?></span>
            <?php endif; ?>
        </span>
    </button>

    <?php // Opens once the plagiarism check is cleared — that IS the last gate before publication.
    // The stage itself is not built yet, so an unlocked card says so plainly instead of promising
    // a screen that does not exist. It stops looking like a locked door, which is the point. ?>
    <?php $bb_pub_open = (($plag_phase_peek ?? '') === 'Phase 5: Cleared'); ?>
    <button type="button" class="bb-card<?= $bb_pub_open ? '' : ' is-locked' ?>" onclick="bbLockedNote()"
        style="background: linear-gradient(118deg, <?= $bb_pub_open ? '#7c3aed 0%, #4c1d95' : '#334155 0%, #0f172a' ?> 100%);">
        <?php if ($bb_pub_open): ?><img class="bb-eagle" src="../assets/mascot/eagle/celebrating.svg" alt="" aria-hidden="true" draggable="false"><?php endif; ?>
        <svg class="bb-glyph"<?= $bb_pub_open ? ' hidden' : '' ?> viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.2">
            <path d="M4 19.5V4.5A2.5 2.5 0 0 1 6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5z" />
            <path d="M8 7h8M8 11h6" />
        </svg>
        <span class="bb-eyebrow">
            <i data-lucide="<?= $bb_pub_open ? 'sparkles' : 'lock' ?>" style="width:13px;height:13px;"></i>
            <?= $bb_pub_open ? 'Unlocked' : 'Coming up' ?>
        </span>
        <h4 class="bb-title">Publish your research</h4>
        <p class="bb-sub"><?= $bb_pub_open
            ? 'Your paper is cleared and ready to bookbind. Publication is the last stage of your research journey.'
            : 'Journal publication and dissemination — the last stage of your research journey.' ?></p>
        <span class="bb-foot">
            <span class="bb-cta"><?= $bb_pub_open ? 'Opening soon' : 'Unlocks after final manuscript' ?></span>
        </span>
    </button>
</div>

<script>
    // The publication stage has no module yet, so the card explains itself instead of
    // navigating nowhere.
    function bbLockedNote() {
        var el = document.getElementById('bbLockedNote');
        if (!el) return;
        el.classList.add('show');
        clearTimeout(window.__bbNoteTimer);
        window.__bbNoteTimer = setTimeout(function () { el.classList.remove('show'); }, 3600);
    }
</script>
<style>
    #bbLockedNote {
        position: fixed; left: 50%; bottom: 104px; transform: translate(-50%, 14px);
        background: #0f172a; color: #fff; border-radius: 14px; padding: 12px 18px;
        font-family: 'Inter', system-ui, sans-serif; font-size: 12.5px; font-weight: 600;
        line-height: 1.4; max-width: min(420px, calc(100vw - 40px)); text-align: center;
        box-shadow: 0 18px 36px -14px rgba(15, 23, 42, .6);
        opacity: 0; pointer-events: none; z-index: 800; transition: opacity .22s, transform .22s;
    }
    #bbLockedNote.show { opacity: 1; transform: translate(-50%, 0); }
</style>
<div id="bbLockedNote"><?= $bb_pub_open
    ? 'Your paper is cleared. The publication stage is being built — nothing to do here yet.'
    : 'Publication opens once your final manuscript is approved and cleared.' ?></div>
