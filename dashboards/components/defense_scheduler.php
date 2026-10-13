<?php
// dashboards/components/defense_scheduler.php — the Apply -> Schedule stage panel.
//
// Renders RDC "Application for Research Defense" steps 1-3 above the requirement cards:
//   apply (Form 002)  ->  pick a date on the office calendar  ->  office logs it  ->  unlock
//
// BOTH modules render this inside a full-screen sheet (#propStageOverlay), so the requirement
// cards stay mounted underneath and Back is always a close. Two shapes, depending on the stage:
//   * not yet scheduled -> the panel: document cards on one side, the office calendar on the
//     other, submitted together as one request.
//   * scheduled         -> the ticket, in a modal the "Ticket" pill opens.
//
// The sheet's CSS and open/close JS live at the bottom of this file, shared by both callers.
//
// Expects the includer to have set:
//   $df_booking  ?array  from df_get_booking()
//   $df_stage     array  from df_stage_meta()
//   $df_phase    string  'final' | 'proposal'
//   $df_csrf     string  $_SESSION['csrf_token']
//   $df_cal       array  date => ['state','reason'] for the whole booking window
//   $df_soonest  ?string the next bookable date
//   $df_app_docs  array  one entry per document this stage collects:
//                        ['item','label','form','note','steps','uploaded'] — see
//                        df_phase2_items(): proposal asks for one, final for six.
//   $df_released ?string optional; the released_at stamp once the office has told this group
//                        it may send its final manuscript. Shows the ticket's CTA.
//   $df_readonly  bool   optional; renders the panel as a greyed-out preview for a group that
//                        has not earned the stage yet. Nothing in it is submittable.

$df_readonly = !empty($df_readonly);
$df_app_docs = $df_app_docs ?? [];
// The calendar only opens once every application document is in — otherwise a group could hold
// a date while the office is still missing half its paperwork.
$df_app_missing = array_values(array_filter($df_app_docs, function ($d) {
    return empty($d['uploaded']);
}));

$df_stage_key = $df_stage['key'];
// Requirements cleared (the focal person can only endorse a group once they are) — decides both
// where the bead rail stands with no booking yet, and what the status block below may promise.
$df_p1_cleared = !$df_readonly && df_phase1_cleared($pdo, (int)$df_uid, $df_phase);
$df_step_index = [
    'apply' => $df_p1_cleared ? 1 : 0, 'appreview' => 0, 'pick' => 1, 'rejected' => 1, 'cancelled' => 1, 'waiting' => 1, 'scheduled' => 2,
][$df_stage_key] ?? 0;
// The calendar is the NEXT step, not a parallel one: it appears only once every document for this
// stage is in. Picking a date is the last thing the group does here, so showing an empty calendar
// beside a stack of un-uploaded documents just puts two jobs on screen at once.
// The locked preview shows it anyway — greyed out — so a group can see how booking works before
// they qualify.
// STUDENTS NO LONGER PICK DATES (2026-09-19). The Focal Person of the course consolidates a
// whole section onto one day and endorses it; the Coordinator confirms and the Secretary
// finalises. So the calendar is gone from this panel entirely — including the locked preview,
// which used to teach a booking flow that no longer exists — and what stands in its place is an
// honest status: waiting, proposed (not yet real), or confirmed.
$df_show_calendar = false;
require_once __DIR__ . '/../focal_lib.php';
$df_sched = fp_student_schedule($pdo, (int)$df_uid, $df_phase);
$df_is_ticket = ($df_stage_key === 'scheduled') && !$df_readonly;
$df_slot = $df_booking['slot_no'] ?? null;

// Is there anything to put BESIDE the calendar? The guide half holds the how-to (preview) or the
// application document cards. Once every document is in there is nothing left to explain, so the
// panel drops to one column and the calendar sits centred — the shape it had before the split.
// df_application_items() returns [] for both phases now (no application stage), so the document
// cards only exist on legacy data — without $df_app_docs here the 'apply' state drew an EMPTY grid
// with two orphan scroll arrows, and stripped the panel's card as if cards were on screen.
$df_show_docs = !$df_readonly && $df_app_docs
    && (in_array($df_stage_key, ['apply', 'appreview'], true) || $df_app_missing);
$df_has_guide = $df_readonly || $df_show_docs;
$df_split = $df_show_calendar && $df_has_guide;
?>
<style>
    /* Stands where the date picker used to be — see the note beside $df_show_calendar. */
    .df-await {
        text-align: center; padding: 30px 22px; border-radius: 16px;
        background: #f8fafc; border: 1px solid #e2e8f0;
    }
    .df-await-i { width: 30px; height: 30px; color: #94a3b8; margin-bottom: 11px; }
    .df-await-i.ok { color: #16a34a; }
    .df-await-i.wait { color: #7c3aed; }
    .df-await-t { font-family: 'Inter', system-ui, sans-serif; font-size: 15.5px; font-weight: 750; color: #0f172a; }
    .df-await-d { font-family: 'Inter', system-ui, sans-serif; font-size: 20px; font-weight: 800; color: #16a34a; margin-top: 5px; }
    .df-await-s { font-size: 12.5px; color: #64748b; line-height: 1.6; margin-top: 8px; max-width: 420px; margin-left: auto; margin-right: auto; }
    body.theme-dark .df-await { background: #131a22; border-color: rgba(255,255,255,.07); }
    body.theme-dark .df-await-t { color: #f8fafc; }
    body.theme-dark .df-await-s { color: #94a3b8; }

    .df-panel {
        background: #fff;
        border: 1px solid #f1f5f9;
        border-radius: 20px;
        padding: 18px;
        margin-bottom: 18px;
        box-shadow: 0 8px 16px -4px rgba(0, 0, 0, 0.05);
        font-family: 'Inter', system-ui, sans-serif;
        box-sizing: border-box;
    }

    /* No card of its own while the document cards are up — they are the surfaces. The head and
       the bead rail stay; only the sheet behind them goes. */
    .df-bare {
        background: transparent; border: none; box-shadow: none; padding: 0;
    }

    .df-rail { display: flex; align-items: center; gap: 8px; margin: 2px auto 14px; padding: 0 4px; max-width: 560px; }
    .df-bead { display: flex; align-items: center; gap: 7px; flex: 0 0 auto; min-width: 0; }
    .df-bead-dot {
        width: 22px; height: 22px; border-radius: 50%; flex-shrink: 0;
        display: flex; align-items: center; justify-content: center;
        font-size: 11px; font-weight: 700;
        background: #f1f5f9; color: #94a3b8; border: 1px solid #e2e8f0;
        transition: all .25s cubic-bezier(.4, 0, .2, 1);
    }
    .df-bead-label { font-size: 11px; font-weight: 700; color: #94a3b8; letter-spacing: .02em; white-space: nowrap; }
    .df-bead.is-done .df-bead-dot { background: #10b981; border-color: #10b981; color: #fff; }
    .df-bead.is-done .df-bead-label { color: #059669; }
    .df-bead.is-current .df-bead-dot { background: #7c3aed; border-color: #7c3aed; color: #fff; box-shadow: 0 0 0 4px rgba(124, 58, 237, .12); }
    .df-bead.is-current .df-bead-label { color: #0f172a; }
    .df-rail-line { height: 2px; flex: 1 1 auto; min-width: 12px; background: #e2e8f0; border-radius: 2px; }

    .df-head { display: flex; align-items: flex-start; gap: 12px; margin-bottom: 14px; }
    .df-head-icon {
        width: 38px; height: 38px; border-radius: 12px; flex-shrink: 0;
        display: flex; align-items: center; justify-content: center;
        background: #f5f3ff; color: #7c3aed;
    }
    .df-head h3 { margin: 0 0 3px; font-size: 15.5px; font-weight: 750; color: #0f172a; line-height: 1.25; }
    .df-head p { margin: 0; font-size: 12.5px; color: #64748b; line-height: 1.45; }

    .df-panel.tone-review .df-head-icon { background: #fffbeb; color: #d97706; }
    .df-panel.tone-revision .df-head-icon { background: #fef2f2; color: #dc2626; }
    .df-panel.tone-revision { border-color: #fecaca; }

    .df-remarks {
        background: #fef2f2; border: 1px solid #fecaca; color: #b91c1c;
        border-radius: 12px; padding: 10px 12px; font-size: 12.5px; line-height: 1.45; margin-bottom: 14px;
    }
    .df-remarks strong { font-weight: 750; }

    .df-steps {
        background: #f8fafc; border: 1px solid #f1f5f9; border-radius: 12px;
        padding: 0 12px; margin-bottom: 16px;
    }
    .df-step {
        display: flex; align-items: center; gap: 12px; padding: 10px 0;
        border-bottom: 1px solid #f1f5f9; font-size: 13px; color: #334155; line-height: 1.35;
    }
    .df-step.is-last { border-bottom: none; }
    .df-step-num {
        background: #e2e8f0; width: 22px; height: 22px; border-radius: 50%;
        display: flex; align-items: center; justify-content: center;
        font-weight: 600; font-size: 11px; color: #475569; flex-shrink: 0;
    }

    .df-actions { display: flex; flex-direction: column; gap: 9px; }

    .df-filecard {
        display: flex; align-items: center; gap: 10px; margin-top: 12px;
        background: #f8fafc; border: 1px solid #f1f5f9; border-radius: 12px; padding: 9px 11px;
    }
    .df-filecard i { color: #64748b; flex-shrink: 0; }
    .df-filecard-name { flex: 1; min-width: 0; font-size: 12px; font-weight: 650; color: #334155; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
    .df-filecard-meta { font-size: 10.5px; color: #94a3b8; }

    /* ---------------- month calendar ---------------- */
    .df-cal { border: 1px solid #f1f5f9; border-radius: 16px; padding: 13px; background: #fff;
              max-width: 420px; margin: 0 auto; }
    .df-cal-top { display: flex; align-items: center; justify-content: space-between; gap: 8px; margin-bottom: 11px; }
    .df-cal-month { font-size: 14px; font-weight: 750; color: #0f172a; }
    .df-cal-nav { display: flex; gap: 6px; }
    .df-cal-nav button {
        width: 28px; height: 28px; border-radius: 9px; border: 1px solid #e2e8f0;
        background: #fff; cursor: pointer; color: #475569;
        display: flex; align-items: center; justify-content: center; padding: 0;
    }
    .df-cal-nav button:hover:not(:disabled) { background: #f8fafc; border-color: #cbd5e1; }
    .df-cal-nav button:disabled { opacity: .35; cursor: not-allowed; }

    .df-grid { display: grid; grid-template-columns: repeat(7, 1fr); gap: 4px; }
    .df-dow {
        text-align: center; font-size: 10px; font-weight: 750; color: #94a3b8;
        text-transform: uppercase; letter-spacing: .04em; padding-bottom: 4px;
    }
    .df-day {
        position: relative; height: 40px;
        border-radius: 11px; border: 1.5px solid transparent; background: #f8fafc;
        display: flex; flex-direction: column; align-items: center; justify-content: center;
        font-size: 13px; font-weight: 650; color: #334155;
        cursor: pointer; padding: 0;
        transition: all .16s cubic-bezier(.4, 0, .2, 1);
        font-family: inherit;
    }
    .df-day.is-blank { background: transparent; cursor: default; }
    .df-day[aria-disabled="true"] { cursor: not-allowed; }
    .df-day.is-open:hover { background: #f5f3ff; border-color: #c4b5fd; transform: translateY(-1px); }
    .df-day.is-selected { background: #7c3aed; border-color: #7c3aed; color: #fff; box-shadow: 0 4px 12px rgba(124, 58, 237, .28); }
    .df-day.is-today::after {
        content: ''; position: absolute; bottom: 5px; width: 4px; height: 4px;
        border-radius: 50%; background: #7c3aed;
    }
    .df-day.is-selected.is-today::after { background: #fff; }
    .df-day.is-mine { background: #ecfdf5; border-color: #6ee7b7; color: #047857; }
    .df-day.is-off { background: #fff; color: #cbd5e1; cursor: not-allowed; }
    .df-day.is-taken { background: #fef2f2; color: #f87171; cursor: not-allowed; }
    .df-day.is-blocked { background: #fff7ed; color: #fb923c; cursor: not-allowed; }
    .df-day.is-blocked::before, .df-day.is-taken::before {
        content: ''; position: absolute; left: 22%; right: 22%; height: 1.5px;
        background: currentColor; opacity: .5; transform: rotate(-14deg);
    }

    .df-legend { display: flex; flex-wrap: wrap; gap: 10px; margin-top: 11px; padding-top: 10px; border-top: 1px solid #f1f5f9; }
    .df-legend span { display: inline-flex; align-items: center; gap: 5px; font-size: 10.5px; font-weight: 600; color: #64748b; }
    .df-legend i { width: 9px; height: 9px; border-radius: 3px; display: block; }

    .df-pickbar {
        max-width: 420px; margin: 13px auto 0;
        display: flex; align-items: center; gap: 10px;
        background: #f8fafc; border: 1px solid #f1f5f9; border-radius: 14px; padding: 11px 13px;
    }
    .df-pickbar-txt { flex: 1; min-width: 0; font-size: 12.5px; color: #64748b; line-height: 1.35; }
    .df-pickbar-txt strong { display: block; color: #0f172a; font-size: 13.5px; font-weight: 750; }
    /* Request stays disabled until a date is picked AND every document is in. Nothing in
       dashboard-cards.css dims a disabled .btn, so a blocked button looked entirely clickable —
       the reason it is blocked is spelled out beside it, but the button has to look it too. */
    .df-pickbar .btn:disabled {
        opacity: .4; cursor: not-allowed; box-shadow: none; filter: grayscale(.5);
    }
    .df-pickbar .btn:disabled:hover { filter: grayscale(.5); }
    .df-hint {
        margin: 10px auto 0; max-width: 420px; display: block; font-size: 11.5px; color: #7c3aed; text-align: center;
        background: none; border: none; cursor: pointer; width: 100%; font-family: inherit; font-weight: 650;
    }
    .df-hint:hover { text-decoration: underline; }
    .df-daynote {
        margin-top: 9px; font-size: 11.5px; color: #b45309; background: #fffbeb;
        border: 1px solid #fde68a; border-radius: 10px; padding: 8px 10px; line-height: 1.4;
    }

    /* ---------------- the ticket ---------------- */
    .df-ticket {
        position: relative; overflow: hidden; isolation: isolate;
        background: linear-gradient(150deg, #0f172a 0%, #3b1f8f 62%, #5b21b6 100%);
        border-radius: 20px; color: #fff; padding: 20px;
        box-shadow: 0 18px 38px -14px rgba(59, 31, 143, .55);
    }
    .df-ticket-label { font-size: 9.5px; font-weight: 800; letter-spacing: .14em; text-transform: uppercase; opacity: .62; }
    .df-ticket-title { font-size: 16px; font-weight: 800; line-height: 1.25; margin: 4px 0 16px; }
    .df-ticket-perf { position: relative; height: 1px; background: rgba(255, 255, 255, .22); margin: 16px -20px; }
    .df-ticket-perf::before, .df-ticket-perf::after {
        content: ''; position: absolute; top: -9px; width: 18px; height: 18px; border-radius: 50%;
        background: #fff;
    }
    .df-ticket-perf::before { left: -9px; }
    .df-ticket-perf::after { right: -9px; }
    .df-ticket-row { display: flex; gap: 18px; }
    .df-ticket-cell { min-width: 0; }
    .df-ticket-cell .k { font-size: 9px; font-weight: 800; letter-spacing: .1em; text-transform: uppercase; opacity: .55; }
    .df-ticket-cell .v { font-size: 14px; font-weight: 750; margin-top: 3px; }
    .df-ticket-big { font-family: 'JetBrains Mono', monospace; font-size: 34px; font-weight: 800; line-height: 1; }
    .df-ticket-big small { display: block; font-family: 'Inter', sans-serif; font-size: 11px; font-weight: 700; letter-spacing: .08em; text-transform: uppercase; opacity: .7; margin-top: 5px; }
    .df-ticket-past { background: rgba(255, 255, 255, .14); border-radius: 20px; padding: 3px 9px; font-size: 10px; font-weight: 750; display: inline-block; }

    /* modal shell for the ticket */
    .df-modal {
        position: fixed; inset: 0; z-index: 900; display: none;
        align-items: center; justify-content: center; padding: 22px;
        background: #fff;
    }
    .df-modal.open { display: flex; animation: dfFade .2s ease; }
    .df-modal-inner { width: 100%; max-width: 360px; animation: dfPop .26s cubic-bezier(.2, .9, .3, 1.2); }
    .df-modal-actions { display: flex; gap: 9px; margin-top: 13px; }
    .df-modal-close {
        flex: 1; padding: 11px; border-radius: 13px; border: none;
        font-family: inherit; font-size: 13px; font-weight: 700; cursor: pointer;
        display: inline-flex; align-items: center; justify-content: center; gap: 7px;
    }
    .df-modal-primary { background: #0f172a; color: #fff; }
    .df-modal-ghost { background: #f1f5f9; color: #334155; }
    .df-modal-close:disabled { opacity: .6; cursor: default; }
    /* The perforation notches are circles painted in the modal's colour, so they move with it. */
    body.theme-dark .df-modal,
    body.theme-dark .df-ticket-perf::before, body.theme-dark .df-ticket-perf::after { background: #0f151b; }
    @keyframes dfFade { from { opacity: 0; } to { opacity: 1; } }
    @keyframes dfPop { from { opacity: 0; transform: translateY(14px) scale(.96); } to { opacity: 1; transform: none; } }

    /* Filter pills and the ticket opener share a row but are separate controls.
       align-items:stretch is what makes the pill exactly as tall as the pill group. */
    .df-tabs-row { display: flex; align-items: stretch; gap: 8px; }
    .df-tabs-row .status-filter-tabs { flex: 1; min-width: 0; margin-bottom: 0; }

    @media (min-width: 1025px) {
        /* dashboard-cards.css gives .status-filter-tabs order:-1 so it sits ABOVE the next-step
           banner inside .proposal-hero-stack. Now that the tabs live in this wrapper, the
           wrapper has to carry that order — otherwise the whole row drops below the banner.
           The tabs keep their fixed 480px and the pill sits flush to their right. */
        .df-tabs-row { order: -1; gap: 10px; margin-bottom: 16px; }
        .df-tabs-row .status-filter-tabs { flex: 0 0 auto; order: 0; margin-bottom: 0; }
        /* Final has one requirement card and therefore no filter tabs, so the row has nothing
           fixed-width inside it to give it shape. .proposal-hero-stack centres its children on
           desktop, so without this the row shrinks to its content and floats. 480px is the same
           width the next-step banner is capped at, so the two line up. */
        .df-tabs-row.is-solo { width: 480px; max-width: 480px; align-items: center; }
    }

    .df-ticket-mascot {
        position: absolute; right: 2px; bottom: 2px; width: 148px; height: auto;
        opacity: .5; mix-blend-mode: screen; pointer-events: none; user-select: none;
    }

    /* the pill that replaces the panel once scheduled */
    .df-view-pill {
        display: inline-flex; align-items: center; gap: 6px; flex-shrink: 0;
        align-self: stretch; padding: 0 18px; border-radius: 999px;
        background: linear-gradient(135deg, #3b1f8f, #7c3aed); color: #fff;
        border: none; cursor: pointer; font-family: inherit; font-size: 12.5px; font-weight: 700;
        box-shadow: 0 4px 12px -3px rgba(124, 58, 237, .45);
    }
    .df-view-pill:hover { filter: brightness(1.08); }

    /* Pill states. Both modules render this pill, so the states live here rather than being
       duplicated into each one's inline <style>. */
    /* Not yet earned — still purple, because it is a real part of the journey and greying it
       out made it read as broken rather than "coming". Just quieter. */
    .df-view-pill.is-soon { background: linear-gradient(135deg, #6d5bb8, #9d84e8); opacity: .82; box-shadow: none; }
    /* Earned, nothing booked yet — full colour AND pulsing, because booking a date is now
       the single next thing to do and it lives behind this button. */
    .df-view-pill.is-ready {
        background: linear-gradient(135deg, #3b1f8f, #7c3aed);
        animation: pillPulsePurple 2.6s ease-in-out infinite;
    }
    @keyframes pillPulsePurple {
        0%, 100% { box-shadow: 0 4px 12px -3px rgba(124, 58, 237, .5), 0 0 0 0 rgba(124, 58, 237, .45); }
        55%      { box-shadow: 0 4px 12px -3px rgba(124, 58, 237, .5), 0 0 0 7px rgba(124, 58, 237, 0); }
    }
    @media (prefers-reduced-motion: reduce) { .df-view-pill.is-ready { animation: none; } }
    /* Ticket in hand. Green, and a slow pulse so the eye lands on it: the next move is a
       face-to-face defense, which is easy to forget is even in this card. */
    .df-view-pill.is-ticket {
        background: linear-gradient(135deg, #047857, #10b981);
        box-shadow: 0 4px 12px -3px rgba(16, 185, 129, .5);
        animation: pillPulse 2.6s ease-in-out infinite;
    }
    @keyframes pillPulse {
        0%, 100% { box-shadow: 0 4px 12px -3px rgba(16, 185, 129, .5), 0 0 0 0 rgba(16, 185, 129, .45); }
        55%      { box-shadow: 0 4px 12px -3px rgba(16, 185, 129, .5), 0 0 0 7px rgba(16, 185, 129, 0); }
    }
    @media (prefers-reduced-motion: reduce) { .df-view-pill.is-ticket { animation: none; } }

    /* Where-am-I label beside the pill. Final has one requirement card and therefore no filter
       tabs, so without this the pill would float on an otherwise empty row. */
    .df-phase-label {
        display: inline-flex; align-items: center; gap: 6px; flex: 1; min-width: 0;
        font-family: 'Inter', system-ui, sans-serif; font-size: 12px; font-weight: 700;
        color: #64748b; letter-spacing: .01em;
    }
    .df-phase-label i { flex-shrink: 0; }
    body.theme-dark .df-phase-label { color: #94a3b8; }

    /* Full-screen sheet the panel opens in. Shared by both modules: the requirement cards stay
       mounted underneath, so Back is always a close and never a navigation. */
    .prop-stage-overlay {
        display: none; position: fixed; inset: 0; z-index: 900;
        background: rgba(15, 23, 42, .45); backdrop-filter: blur(6px); -webkit-backdrop-filter: blur(6px);
        padding: 0; box-sizing: border-box;
    }
    /* Visibility must not depend on the animation finishing: opacity is set outright on
       .open and the keyframes only ease the sheet in. An interrupted or never-started
       animation used to leave the whole overlay at opacity 0 — open, but invisible, with
       the requirement cards showing through it. */
    .prop-stage-overlay.open { display: block; opacity: 1; }
    .prop-stage-overlay.open .prop-stage-sheet { animation: propStageIn .22s cubic-bezier(.2,.9,.3,1); }
    @keyframes propStageIn { from { opacity: .4; transform: translateY(8px); } to { opacity: 1; transform: none; } }
    @media (prefers-reduced-motion: reduce) { .prop-stage-overlay.open .prop-stage-sheet { animation: none; } }
    .prop-stage-sheet {
        background: #f8fafc; height: 100%; width: 100%;
        display: flex; flex-direction: column; overflow: hidden;
    }
    .prop-stage-bar {
        display: flex; align-items: center; gap: 12px; flex-shrink: 0;
        padding: 13px 16px; background: #fff; border-bottom: 1px solid #e2e8f0;
    }
    .prop-stage-back {
        display: inline-flex; align-items: center; gap: 7px;
        padding: 8px 13px; border-radius: 11px; border: 1px solid #e2e8f0; background: #f8fafc;
        font-family: 'Inter', system-ui, sans-serif; font-size: 12.5px; font-weight: 700;
        color: #334155; cursor: pointer;
    }
    .prop-stage-back:hover { background: #f1f5f9; border-color: #cbd5e1; }
    .prop-stage-title { font-family: 'Inter', system-ui, sans-serif; font-size: 14px; font-weight: 750; color: #0f172a; }
    .prop-stage-body { flex: 1; overflow-y: auto; padding: 18px 16px 40px; }
    body.theme-dark .prop-stage-sheet { background: #0f151b; }
    body.theme-dark .prop-stage-bar { background: #151c24; border-bottom-color: rgba(255,255,255,.06); }
    body.theme-dark .prop-stage-back { background: #1b232d; border-color: #334155; color: #cbd5e1; }
    body.theme-dark .prop-stage-title { color: #f8fafc; }

    /* One card per application document. Final needs two (Form 002 + Minutes), proposal one.
       Each carries its own heading + explanation, because "what even is a Minutes of the
       Proposal Defense" is a real question and the answer belongs next to the upload. */
    .df-doc-row {
        display: block; padding: 14px; border-radius: 14px;
        background: #f8fafc; border: 1px solid #eef2f7;
    }
    .df-doc-row + .df-doc-row { margin-top: 10px; }
    .df-doc-head { display: flex; align-items: center; gap: 9px; margin-bottom: 4px; }
    .df-doc-name { font-size: 13.5px; font-weight: 750; color: #0f172a; line-height: 1.3; }
    .df-doc-tick {
        display: inline-flex; align-items: center; gap: 4px; margin-left: auto; flex-shrink: 0;
        padding: 3px 9px; border-radius: 999px; background: #d1fae5; color: #047857;
        font-size: 10.5px; font-weight: 800; text-transform: uppercase; letter-spacing: .04em;
    }
    /* Same chip, opposite verdict — the office sent this one back and it needs replacing. */
    .df-doc-back {
        display: inline-flex; align-items: center; gap: 4px; margin-left: auto; flex-shrink: 0;
        padding: 3px 9px; border-radius: 999px; background: #fee2e2; color: #b91c1c;
        font-size: 10.5px; font-weight: 800; text-transform: uppercase; letter-spacing: .04em;
    }
    /* The document cards reuse .item-card / .items-grid wholesale, so at >=1025px they become the
       same horizontal scroller the requirement grid uses — with their own < > buttons rendered
       right below (the module's pair drives the module's own grid, not this one). */
    .df-doc-note { margin: 0 0 11px; font-size: 12px; color: #64748b; line-height: 1.5; }
    .df-doc-btns { display: flex; gap: 9px; align-items: stretch; }
    .df-doc-btns > * { flex: 1; min-width: 0; }
    .df-doc-btns .btn { width: 100%; }
    .df-doc-done {
        display: flex; align-items: center; justify-content: center; gap: 8px;
        padding: 12px; border-radius: 12px; background: #ecfdf5; border: 1px solid #a7f3d0;
        color: #047857; font-size: 13px; font-weight: 700;
    }

    /* Web: the how-to and the calendar are two halves of one instruction, so they sit side by
       side instead of the guide running the full width of the panel with the calendar far below.
       Phones keep the stacked order — guide first, then the calendar. */
    /* The panel itself never runs the full width of a desktop module — a column of instructions
       stretched across 1200px is unreadable and looked like an empty page with text stuck to the
       edges. Everything below is centred inside this measure. */
    .df-panel { max-width: 900px; margin-left: auto; margin-right: auto; }

    @media (min-width: 900px) {
        /* Guide + calendar side by side: two halves of one instruction. */
        .df-panel.df-split { max-width: 1000px; }
        .df-panel.df-split .df-split-wrap {
            display: grid; grid-template-columns: minmax(0, 1fr) 372px;
            gap: 20px; align-items: start;
        }
        .df-panel.df-split .df-steps { margin-bottom: 12px; }
        .df-panel.df-split .df-cal { margin: 0; max-width: none; }
    }
    /* Nothing left to explain — the calendar is the whole panel, so centre it rather than
       leaving it pinned to one side of an empty grid. */
    .df-panel.df-calonly { max-width: 560px; }
    .df-panel.df-calonly .df-split-wrap { max-width: 430px; margin: 0 auto; }
    .df-panel .df-cal { margin: 0 auto; }
    /* No calendar yet: a column of upload cards, held to a comfortable reading measure. */
    .df-panel:not(.df-split):not(.df-calonly) .df-split-wrap { max-width: 640px; margin: 0 auto; }

    /* Neutral "not yet" note. .df-remarks is the red one and is for the office's rejections —
       using it here made a normal waiting state read as an error. */
    .df-hint {
        display: flex; align-items: flex-start; gap: 9px;
        background: #f5f3ff; border: 1px solid #ddd6fe; color: #5b21b6;
        border-radius: 12px; padding: 11px 13px; font-size: 12.5px; line-height: 1.45; margin-top: 10px;
    }
    .df-hint i { flex-shrink: 0; margin-top: 1px; opacity: .8; }
    body.theme-dark .df-hint { background: rgba(124, 58, 237, .12); border-color: rgba(167, 139, 250, .3); color: #c4b5fd; }

    /* Preview mode: the whole stage shown but inert, for a group that has not earned it yet.
       Everything below the note is dimmed and unclickable — they can read, not act. */
    .df-preview { position: relative; }
    .df-preview .df-head,
    .df-preview .df-steps,
    .df-preview .df-cal-wrap,
    .df-preview .df-pickbar { opacity: .5; filter: grayscale(.85); pointer-events: none; user-select: none; }
    .df-preview-note {
        display: flex; align-items: flex-start; gap: 10px;
        padding: 13px 15px; margin-bottom: 18px; border-radius: 12px;
        background: #f8fafc; border: 1px dashed #cbd5e1;
        color: #475569; font-size: 12.5px; font-weight: 600; line-height: 1.5;
    }
    .df-preview-note i { flex-shrink: 0; margin-top: 1px; color: #94a3b8; }

    /* What happens after the defense — sits under the ticket inside the same modal, so the
       ticket answers "when" and this answers "and then what". */
    .df-ticket-after {
        margin-top: 14px; padding: 15px 16px; border-radius: 16px;
        background: #f8fafc; border: 1px solid #eef2f7;
        font-family: 'Inter', system-ui, sans-serif; text-align: left;
    }
    .df-after-head {
        display: flex; align-items: center; gap: 8px; margin-bottom: 10px;
        font-size: 13.5px; font-weight: 800; color: #0f172a;
    }
    .df-after-head i { color: #7c3aed; flex-shrink: 0; }
    .df-after-list { margin: 0; padding-left: 20px; display: flex; flex-direction: column; gap: 8px; }
    .df-after-list li { font-size: 12.5px; color: #475569; line-height: 1.5; }
    .df-after-list strong { color: #0f172a; font-weight: 750; }
    .df-after-cta {
        display: flex; align-items: center; justify-content: center; gap: 8px;
        width: 100%; margin-top: 13px; padding: 12px; border: none; border-radius: 12px;
        background: linear-gradient(135deg, #3b1f8f, #7c3aed); color: #fff;
        font-family: inherit; font-size: 13px; font-weight: 750; cursor: pointer;
        box-shadow: 0 6px 16px -6px rgba(124, 58, 237, .6);
    }
    .df-after-cta:hover { filter: brightness(1.08); }
    body.theme-dark .df-ticket-after { background: #1b232d; border-color: #334155; }
    body.theme-dark .df-after-head { color: #f8fafc; }
    body.theme-dark .df-after-list li { color: #cbd5e1; }
    body.theme-dark .df-after-list strong { color: #f8fafc; }

    /* Slots left on a day, printed under the date number in the picker. */
    .df-slot-left { display: block; font-size: 9px; font-weight: 700; opacity: .75; line-height: 1; margin-top: 2px; }

    /* Locked requirement cards (only reachable in edge cases — normally they are not rendered) */
    .item-card.is-locked .card-inner-bg { background: #fcfcfd; }
    .item-card.is-locked .card-title { color: #64748b; }
    .df-locked-note {
        display: flex; align-items: center; justify-content: center; gap: 8px;
        margin-top: 4px; padding: 11px 12px; border-radius: 12px;
        background: #f8fafc; border: 1px dashed #e2e8f0;
        color: #94a3b8; font-size: 12px; font-weight: 650;
    }

    @media (max-width: 640px) {
        .df-panel { padding: 15px; border-radius: 18px; }
        .df-bead-label { display: none; }
        .df-rail-line { flex: 1; }
        .df-day { height: 38px; font-size: 12.5px; border-radius: 10px; }
        .df-cal { padding: 10px; }
    }

    body.theme-dark .df-panel, body.theme-dark .df-cal { background: #151c24; border-color: rgba(255, 255, 255, .06); }
    body.theme-dark .df-head h3, body.theme-dark .df-cal-month { color: #f8fafc; }
    body.theme-dark .df-head p, body.theme-dark .df-filecard-name { color: #94a3b8; }
    body.theme-dark .df-day { background: #1b232d; color: #cbd5e1; }
    body.theme-dark .df-day.is-off { background: transparent; color: #475569; }
    body.theme-dark .df-filecard, body.theme-dark .df-pickbar,
    body.theme-dark .df-steps { background: #1b232d; border-color: #334155; }
    body.theme-dark .df-step { border-bottom-color: rgba(255,255,255,.06); color: #cbd5e1; }
    body.theme-dark .df-step-num { background: rgba(255,255,255,.15); color: #cbd5e1; }
</style>

<?php if ($df_is_ticket): ?>
    <?php
    $ts = strtotime($df_booking['defense_date']);
    $is_past = $df_booking['defense_date'] < df_today();
    ?>
    <!-- Scheduled: the ticket lives in a modal so it stops covering the requirements. The
         opener pill is rendered by module_final.php next to the filter tabs. -->
    <div class="df-modal" id="dfTicketModal" onclick="if(event.target===this)dfCloseTicket()">
        <div class="df-modal-inner">
            <div class="df-ticket" id="dfTicketCard">
                <img src="../assets/images/mascott_party.png" alt="" class="df-ticket-mascot">
                <div class="df-ticket-label">Research &amp; Development Center</div>
                <div class="df-ticket-title"><?= htmlspecialchars(ucwords(df_phase_label($df_phase))) ?><?= $is_past ? ' <span class="df-ticket-past">Date passed</span>' : '' ?></div>

                <div class="df-ticket-row" style="align-items:flex-end;">
                    <div class="df-ticket-cell" style="flex:1;">
                        <div class="df-ticket-big">
                            <?= date('j', $ts) ?>
                            <small><?= date('F Y', $ts) ?></small>
                        </div>
                    </div>
                    <div class="df-ticket-cell" style="text-align:right;">
                        <div class="k">Day</div>
                        <div class="v"><?= date('D', $ts) ?></div>
                    </div>
                    <?php if ($df_slot): ?>
                        <div class="df-ticket-cell" style="text-align:right;">
                            <div class="k">Slot</div>
                            <div class="v"><?= (int)$df_slot ?> <small style="opacity:.7;font-weight:600;">of <?= DEFENSE_SLOTS_PER_SCHOOL_PER_DAY ?></small></div>
                        </div>
                    <?php endif; ?>
                </div>

                <div class="df-ticket-perf"></div>

                <div class="df-ticket-row" style="flex-direction:column;gap:11px;">
                    <div class="df-ticket-cell">
                        <div class="k">Research Group</div>
                        <div class="v" style="font-size:12.5px;line-height:1.35;"><?= htmlspecialchars($df_group_name ?: 'Your group') ?></div>
                    </div>
                    <div class="df-ticket-row" style="gap:18px;">
                        <div class="df-ticket-cell" style="flex:1;">
                            <div class="k">Status</div>
                            <div class="v" style="font-size:12.5px;">Logged &amp; recorded</div>
                            <?php if ($df_slot): ?>
                                <div class="k" style="margin-top:5px;font-weight:600;text-transform:none;letter-spacing:0;">Groups are heard in slot order</div>
                            <?php endif; ?>
                        </div>
                        <div class="df-ticket-cell">
                            <div class="k">Confirmed</div>
                            <div class="v" style="font-size:12.5px;"><?= $df_booking['decided_at'] ? date('M j', strtotime($df_booking['decided_at'])) : '—' ?></div>
                        </div>
                    </div>
                </div>
            </div>
            <?php // The ticket alone answers "when", but not "and then what?" — which for a
            // proposal defense is the whole point, because the next move (getting the Minutes
            // from the adviser) happens off-system and is easy to miss entirely. ?>
            <div class="df-ticket-after">
                <div class="df-after-head">
                    <i data-lucide="party-popper" style="width:16px;height:16px;"></i>
                    <span>Good luck<?= $df_phase === 'proposal' ? ' on your proposal defense' : '' ?>!</span>
                </div>
                <?php if ($df_phase === 'proposal'): ?>
                    <ol class="df-after-list">
                        <li>Present and defend your proposal on the day — go in when your slot is called.</li>
                        <li>Your teacher writes the <strong>Minutes of the Proposal Defense</strong> during it. Ask your adviser for a copy afterwards — they keep it.</li>
                        <li>Your <strong>Statistics</strong> card is now open — work through your data with the statistician. Final Defense opens once your results are released, and the Minutes is one of its requirements.</li>
                    </ol>
                    <?php // Final waits for statistics (2026-09-25), so this used to send the group to a
                          // locked card. Statistics is what the proposal ticket actually unlocks. ?>
                    <button type="button" class="df-after-cta" onclick="dfGoStats()">
                        <i data-lucide="arrow-right" style="width:15px;height:15px;"></i> I'm ready — open Statistics
                    </button>
                <?php else: ?>
                    <?php // By the time a final ticket exists every document is already approved —
                    // that is what let the office confirm the date — so this list looks forward,
                    // not back at requirements the group has finished. ?>
                    <ol class="df-after-list">
                        <li>Print your Form 005 and Form 006 and bring them on the day.</li>
                        <li>Present and defend your research — go in when your slot is called.</li>
                        <li>After the panel's decision, the research office will open your Plagiarism card so you can send your final manuscript.</li>
                    </ol>
                    <?php // Deliberately hidden until the office says the defense went ahead. The
                    // system cannot know that on its own, and offering the button early would
                    // invite a manuscript nobody is ready to receive. Same signal that swaps the
                    // dashboard billboard — see admin_module_dynamic.php's release_manuscript. ?>
                    <?php if (!empty($df_released)): ?>
                        <button type="button" class="df-after-cta" onclick="dfGoPlag()">
                            <i data-lucide="file-up" style="width:15px;height:15px;"></i> I'm ready — send my final manuscript
                        </button>
                    <?php endif; ?>
                <?php endif; ?>
            </div>

            <div class="df-modal-actions">
                <button type="button" class="df-modal-close df-modal-primary" onclick="dfDownloadTicket(this)">
                    <i data-lucide="download" style="width:16px;height:16px;"></i> Download
                </button>
                <button type="button" class="df-modal-close df-modal-ghost" onclick="dfCloseTicket()">Close</button>
            </div>
        </div>
    </div>

<?php else: ?>
    <!-- The step rail sits outside the card: it tracks the whole stage, not this one panel. -->
    <div class="df-rail">
            <?php
            // The group no longer applies or picks: requirements clear, the focal person proposes
            // the section's date, the office confirms. Same three steps, the real names.
            $df_beads = [[0, 'Requirements'], [1, 'Date proposed'], [2, 'Scheduled']];
            foreach ($df_beads as [$bi, $blabel]):
                $bclass = $bi < $df_step_index ? 'is-done' : ($bi === $df_step_index ? 'is-current' : '');
            ?>
                <?php if ($bi > 0): ?><div class="df-rail-line"></div><?php endif; ?>
                <div class="df-bead <?= $bclass ?>">
                    <div class="df-bead-dot">
                        <?php if ($bi < $df_step_index): ?>
                            <i data-lucide="check" style="width:12px;height:12px;"></i>
                        <?php else: ?>
                            <?= $bi + 1 ?>
                        <?php endif; ?>
                    </div>
                    <span class="df-bead-label"><?= $blabel ?></span>
                </div>
            <?php endforeach; ?>
    </div>

    <?php // `df-bare` drops the panel's own white card while document cards are on screen. Those
    // cards are already white surfaces, so the panel behind them read as a second sheet with a
    // visible gutter around it. The calendar keeps its frame either way — .df-cal owns one. ?>
    <div class="df-panel tone-<?= htmlspecialchars($df_stage['tone']) ?><?= $df_readonly ? ' df-preview' : '' ?><?= $df_split ? ' df-split' : '' ?><?= ($df_show_calendar && !$df_has_guide) ? ' df-calonly' : '' ?><?= (!$df_readonly && $df_has_guide) ? ' df-bare' : '' ?>">
        <?php if ($df_readonly): ?>
            <div class="df-preview-note">
                <i data-lucide="lock" style="width:16px;height:16px;"></i>
                <span>Your <?= htmlspecialchars(df_phase_label($df_phase)) ?> is scheduled once the
                requirements are complete and approved. Here is how that works.</span>
            </div>
        <?php endif; ?>
        <div class="df-head">
            <div class="df-head-icon">
                <i data-lucide="<?= $df_stage_key === 'waiting' ? 'clock' : (in_array($df_stage_key, ['rejected', 'cancelled'], true) ? 'alert-triangle' : ($df_stage_key === 'pick' ? 'calendar' : 'file-text')) ?>" style="width:19px;height:19px;"></i>
            </div>
            <div style="min-width:0;">
                <h3><?= htmlspecialchars($df_stage['headline']) ?></h3>
                <?php if ($df_stage_key !== 'apply'): ?>
                    <p><?= htmlspecialchars($df_stage['blurb']) ?></p>
                <?php endif; ?>
            </div>
        </div>

        <div class="df-split-wrap">
        <div class="df-split-guide">
        <?php // Only the locked preview needs this generic list; once the stage is live each
        // application document carries its own heading and explanation instead. ?>
        <?php if ($df_readonly): ?>
            <div class="df-steps">
                <?php
                // Students do not pick a date: the focal person schedules the section.
                $df_apply_steps = $df_readonly
                    ? [
                        'Upload all ' . count(fp_review_items($df_phase)) . ' requirements in this card',
                        'Wait for them to be approved',
                        'Your focal person proposes one defense date for your whole section',
                        'The research office confirms it',
                        'Your ticket appears here, with your slot number',
                    ]
                    : ($df_phase === 'proposal'
                        ? [
                            'Download the endorsement form you just earned',
                            'Have it signed by your research adviser',
                            'Capture a photo or scan it',
                            'Upload it using the button below',
                            'Then pick your defense date from the calendar',
                        ]
                        : [
                            'Download the blank Form 002 below',
                            'Fill out the form completely and have it signed',
                            'Upload it, along with the Minutes of your proposal defense',
                            'Capture a photo or scan of each',
                            'Then pick your defense date from the calendar',
                        ]);
                $df_step_total = count($df_apply_steps);
                foreach ($df_apply_steps as $si => $stext):
                ?>
                    <div class="df-step<?= $si === $df_step_total - 1 ? ' is-last' : '' ?>">
                        <span class="df-step-num"><?= $si + 1 ?></span>
                        <span><?= htmlspecialchars($stext) ?></span>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <?php if (in_array($df_stage_key, ['rejected', 'cancelled'], true) && !empty($df_booking['remarks'])): ?>
            <div class="df-remarks"><strong>From the research office:</strong> <?= htmlspecialchars($df_booking['remarks']) ?></div>
        <?php endif; ?>

        <?php // Application flow step 1: one download + upload pair per document this phase needs.
        // Stays visible while any document is still missing even after the booking row exists,
        // so a half-finished application can always be completed.
        if ($df_show_docs): ?>
            <?php // These are the SAME Apple-Wallet .item-card the requirement grid uses — same
            // markup, same dashboard-cards.css, so they stack on mobile and grid on web exactly
            // like every other document card in the portal. `df-items` marks the grid as belonging
            // to this sheet so the module's filter tabs leave it alone. ?>
            <div class="items-grid df-items">
            <?php $dci = 0; foreach ($df_app_docs as $doc):
                $dci++;
                $d_status = $doc['status'] ?? null;
                $d_back   = ($d_status === 'Revision Requested');
                if ($d_status === 'Approved')            { $d_class = 'approved';  $d_pill = 'Approved'; }
                elseif ($d_back)                         { $d_class = 'revision';  $d_pill = 'Revision Needed'; }
                elseif ($d_status !== null)              { $d_class = 'review';    $d_pill = 'Under Review'; }
                else                                     { $d_class = 'no-upload'; $d_pill = 'No Upload'; }
            ?>
                <div class="item-card <?= $d_class ?>" id="req-item-<?= (int)$doc['item'] ?>"
                     onclick="expandWalletCard(this, event)" style="z-index: <?= $dci ?>;">
                    <div class="card-inner-bg">
                        <div class="card-header">
                            <div class="status-icon-box num-indicator"><?= str_pad($dci, 2, '0', STR_PAD_LEFT) ?></div>
                            <div>
                                <h3 class="card-title"><?= htmlspecialchars($doc['label']) ?></h3>
                                <p class="card-meta"><?= htmlspecialchars($doc['note'] ?: ($doc['description'] ?? '')) ?></p>
                            </div>
                        </div>

                        <div class="card-body" onclick="event.stopPropagation()">
                            <div style="display:flex; align-items:center; justify-content:space-between; gap:8px; margin-bottom:16px;">
                                <div class="status-pill <?= $d_class ?>" style="margin-bottom:0;"><?= $d_pill ?></div>
                            </div>

                            <?php // The office's own words, so the group knows what to change before
                            // they re-upload. Without this a rejection is just a button that reappeared. ?>
                            <?php if ($d_back): ?>
                                <div class="instruction-box" style="background:#fef2f2; color:#b91c1c; border:1px solid #fecaca; margin-bottom:16px;">
                                    <strong>Sent back:</strong>
                                    <?= !empty($doc['remarks']) ? '&ldquo;' . htmlspecialchars($doc['remarks']) . '&rdquo;' : 'Please re-upload a corrected copy.' ?>
                                </div>
                            <?php endif; ?>

                            <?php if (!empty($doc['steps'])): ?>
                                <div class="card-steps" style="background: #f8fafc; border-radius: 12px; padding: 0 12px; margin-bottom: 16px; border: 1px solid #f1f5f9;">
                                    <?php $dsn = count($doc['steps']);
                                    foreach ($doc['steps'] as $si => $stext): ?>
                                        <div style="padding: 10px 0;<?= $si === $dsn - 1 ? '' : ' border-bottom: 1px solid #f1f5f9;' ?> display: flex; align-items: center; gap: 12px; font-size: 13px; color: #334155;">
                                            <div style="background: #e2e8f0; width:22px; height:22px; border-radius: 50%; display:flex; align-items:center; justify-content:center; font-weight:600; font-size:11px; color:#475569; flex-shrink:0;"><?= $si + 1 ?></div>
                                            <?= htmlspecialchars($stext) ?>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>

                            <?php if (!empty($doc['form'])): ?>
                                <div style="margin-bottom:14px;">
                                    <button type="button" class="btn btn-outline" onclick="openDownloadModal('<?= htmlspecialchars($doc['form']) ?>', '<?= htmlspecialchars($doc['label'], ENT_QUOTES) ?>')">
                                        <i data-lucide="download" style="width:18px;height:18px;"></i> Download Form
                                    </button>
                                </div>
                            <?php endif; ?>

                            <?php if (empty($doc['uploaded'])): ?>
                                <form action="upload_handler.php" method="POST" enctype="multipart/form-data" target="_parent" onsubmit="handleUploadStart(this)" style="display:flex;flex-direction:column;gap:0;">
                                    <input type="hidden" name="module_context" value="<?= htmlspecialchars($df_phase) ?>">
                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($df_csrf) ?>">
                                    <input type="hidden" name="item_id" value="<?= (int)$doc['item'] ?>">
                                    <input type="file" name="research_file" id="file-input-<?= (int)$doc['item'] ?>" style="display:none;" accept="image/*,.jpg,.jpeg,.png,.pdf,.doc,.docx" onchange="handleFileChange(this)">
                                    <button type="button" id="file-btn-<?= (int)$doc['item'] ?>" class="btn btn-primary" style="margin-top:4px;" onclick="triggerFilePicker(<?= (int)$doc['item'] ?>)">
                                        <i data-lucide="upload" style="width:18px;height:18px;"></i> Upload File
                                    </button>
                                </form>
                            <?php endif; ?>

                            <?php if (!empty($doc['file_path'])):
                                $d_fname = $doc['filename'];
                                $d_ext   = strtolower(pathinfo($d_fname, PATHINFO_EXTENSION));
                                $d_isimg = in_array($d_ext, ['jpg', 'jpeg', 'png', 'gif', 'webp']);
                                $d_show  = strlen($d_fname) > 28 ? substr($d_fname, 0, 25) . '...' : $d_fname;
                            ?>
                                <div class="apple-file-card">
                                    <div class="afc-thumb-wrap">
                                        <?php if ($d_isimg): ?>
                                            <img src="<?= htmlspecialchars($doc['file_path']) ?>" alt="Preview" class="afc-thumb">
                                        <?php else: ?>
                                            <i data-lucide="file-text" style="width:20px;height:20px;color:#64748b;"></i>
                                        <?php endif; ?>
                                    </div>
                                    <div class="afc-info">
                                        <div class="afc-name" title="<?= htmlspecialchars($d_fname) ?>"><?= htmlspecialchars($d_show) ?></div>
                                        <div class="afc-meta"><?= $doc['uploaded_at'] ? date('M j, Y', strtotime($doc['uploaded_at'])) . ' &middot; ' . date('g:i A', strtotime($doc['uploaded_at'])) : '' ?></div>
                                    </div>
                                    <div class="afc-actions">
                                        <button type="button" class="afc-btn" onclick="openDownloadModal('<?= htmlspecialchars($doc['file_path']) ?>', '<?= htmlspecialchars($d_fname, ENT_QUOTES) ?>'); event.stopPropagation();">View</button>
                                        <?php if ($d_status === 'Pending'): // only an un-reviewed draft can be pulled back ?>
                                            <button type="button" class="afc-btn afc-delete" onclick="deleteUpload(<?= (int)$doc['upload_id'] ?>, '<?= htmlspecialchars($df_phase) ?>'); event.stopPropagation();">
                                                <i data-lucide="trash-2" style="width:14px;height:14px;"></i>
                                            </button>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
            </div>
            <?php // Its own scroller controls: at >=1025px .items-grid is a horizontal rail, and
            // the module's pair below drives the module's own grid. Same markup, same behaviour. ?>
            <div class="items-scroll-nav">
                <button type="button" class="items-scroll-btn scroll-prev" title="Previous">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="15 18 9 12 15 6" /></svg>
                </button>
                <button type="button" class="items-scroll-btn scroll-next" title="Next">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 18 15 12 9 6" /></svg>
                </button>
            </div>
            <?php if ($df_app_missing): ?>
                <div class="df-hint">
                    <i data-lucide="info" style="width:15px;height:15px;"></i>
                    <span>The date picker opens once all <?= count($df_app_docs) ?>
                          document<?= count($df_app_docs) === 1 ? '' : 's' ?> above <?= count($df_app_docs) === 1 ? 'is' : 'are' ?> uploaded.</span>
                </div>
            <?php endif; ?>
        <?php endif; ?>
        </div><!-- /.df-split-guide -->

        <?php /* Where the calendar used to be. The group no longer picks a day, so all that is
                 owed here is a truthful status. A proposed date is labelled as proposed — it is
                 not a booking until the Secretary signs, and a group must not plan around it. */ ?>
        <?php if (!$df_readonly): ?>
            <div class="df-await">
                <?php if ($df_sched && $df_sched['confirmed']): ?>
                    <i data-lucide="calendar-check" class="df-await-i ok"></i>
                    <div class="df-await-t">Your defense is set</div>
                    <div class="df-await-d"><?= htmlspecialchars(date('l, F j, Y', strtotime($df_sched['defense_date']))) ?></div>
                    <div class="df-await-s">Slot <?= (int)$df_sched['slot_no'] ?><?= $df_sched['section_label'] ? ' &middot; ' . htmlspecialchars($df_sched['section_label']) : '' ?>
                        &mdash; groups are heard in slot order, so come early.</div>
                <?php elseif ($df_sched): ?>
                    <i data-lucide="calendar-clock" class="df-await-i wait"></i>
                    <div class="df-await-t">Proposed for <?= htmlspecialchars(date('F j, Y', strtotime($df_sched['defense_date']))) ?></div>
                    <div class="df-await-s">Not final yet. Your focal person has put your section forward
                        for this day and it is being confirmed by the research office. You will be told
                        here the moment it is approved &mdash; please do not plan around it until then.</div>
                <?php // "Nothing more for you to do" is only true once the requirements are
                      // approved. Final has no read-only preview, so a group mid-requirements
                      // reached the branch below and was told it was finished. ?>
                <?php elseif ($df_app_missing || !$df_p1_cleared): ?>
                    <i data-lucide="file-check" class="df-await-i"></i>
                    <div class="df-await-t">Finish your requirements first</div>
                    <div class="df-await-s">Once every requirement in this card is approved, your focal person
                        schedules your whole section together.</div>
                <?php else: ?>
                    <i data-lucide="hourglass" class="df-await-i"></i>
                    <div class="df-await-t">Waiting for your defense date</div>
                    <div class="df-await-s">Everything you owe is in. Your focal person schedules the whole
                        section at once, so your date arrives with the rest of your classmates'. Nothing
                        more for you to do.</div>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <?php if ($df_show_calendar): ?>
        <div class="df-split-cal">
            <!-- Application flow step 2: the office calendar. Open by default; closed days and days another
                 group already holds are not selectable. -->
            <form action="defense_handler.php" method="POST" target="_parent" id="dfPickForm">
                <input type="hidden" name="action" value="select_date">
                <input type="hidden" name="phase" value="<?= htmlspecialchars($df_phase) ?>">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($df_csrf) ?>">
                <input type="hidden" name="defense_date" id="dfChosenDate" value="<?= htmlspecialchars($df_booking['defense_date'] ?? '') ?>">

                <div class="df-cal">
                    <div class="df-cal-top">
                        <span class="df-cal-month" id="dfCalMonth">—</span>
                        <div class="df-cal-nav">
                            <button type="button" id="dfPrev" title="Previous month">
                                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="15 18 9 12 15 6" /></svg>
                            </button>
                            <button type="button" id="dfNext" title="Next month">
                                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 18 15 12 9 6" /></svg>
                            </button>
                        </div>
                    </div>
                    <div class="df-grid" id="dfGrid"></div>
                    <div class="df-legend">
                        <span><i style="background:#f8fafc;border:1.5px solid #e2e8f0;"></i>Open</span>
                        <span><i style="background:#7c3aed;"></i>Your pick</span>
                        <span><i style="background:#fef2f2;border:1.5px solid #fecaca;"></i>Taken</span>
                        <span><i style="background:#fff7ed;border:1.5px solid #fed7aa;"></i>Closed</span>
                    </div>
                    <div class="df-daynote" id="dfDayNote" style="display:none;"></div>
                </div>

                <?php if ($df_soonest): ?>
                    <button type="button" class="df-hint" id="dfSoonest" data-date="<?= htmlspecialchars($df_soonest) ?>">
                        Soonest available: <?= date('D, M j', strtotime($df_soonest)) ?> — jump to it
                    </button>
                <?php endif; ?>

                <div class="df-pickbar">
                    <div class="df-pickbar-txt" id="dfPickText">
                        <strong>No date chosen</strong>
                        Tap an open day on the calendar.
                    </div>
                    <button type="submit" class="btn btn-primary" id="dfSubmit" style="width:auto;padding:9px 15px;" disabled>
                        <i data-lucide="calendar-check" style="width:16px;height:16px;"></i> Request
                    </button>
                </div>
            </form>
        </div><!-- /.df-split-cal -->
        <?php endif; ?>
        </div><!-- /.df-split-wrap -->

        <?php if ($df_booking && !empty($df_booking['application_filename'])): ?>
            <div class="df-filecard">
                <i data-lucide="file-text" style="width:16px;height:16px;"></i>
                <span class="df-filecard-name" title="<?= htmlspecialchars($df_booking['application_filename']) ?>"><?= htmlspecialchars($df_booking['application_filename']) ?></span>
                <span class="df-filecard-meta"><?= $df_booking['application_status'] === 'Approved' ? 'Accepted' : 'Submitted' ?></span>
                <button type="button" class="afc-btn" onclick="openDownloadModal('<?= htmlspecialchars($df_booking['application_path']) ?>', '<?= htmlspecialchars($df_booking['application_filename']) ?>')">View</button>
            </div>
        <?php endif; ?>
    </div>
<?php endif; ?>

<script>
    <?php if ($df_is_ticket): ?>
    // Single source for both the rendered ticket and the downloaded PNG.
    window.DF_TICKET = <?= json_encode([
        'day'       => date('j', $ts),
        'monthYear' => date('F Y', $ts),
        'weekday'   => date('D', $ts),
        'group'     => $df_group_name ?: 'Your group',
        'status'    => 'Logged & recorded',
        'confirmed' => $df_booking['decided_at'] ? date('M j', strtotime($df_booking['decided_at'])) : '—',
        'iso'       => $df_booking['defense_date'],
        'title'     => ucwords(df_phase_label($df_phase)),
        'slot'      => $df_slot ? 'Slot ' . (int)$df_slot . ' of ' . DEFENSE_SLOTS_PER_SCHOOL_PER_DAY : null,
        'file'      => str_replace(' ', '-', ucwords(df_phase_label($df_phase))),
    ], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    <?php endif; ?>

    // "I'm ready" on the proposal ticket. The module runs in an iframe, so switching cards means
    // asking the dashboard to do it — pushView lives on the parent. Falls back to a plain
    // navigation if this is ever opened standalone.
    function dfGoFinal() {
        try {
            if (window.parent && window.parent !== window && typeof window.parent.pushView === 'function') {
                window.parent.pushView('zoom-final', 'module_final.php');
                return;
            }
        } catch (e) { /* cross-origin — fall through */ }
        window.top.location.href = 'student.php?module=final';
    }

    // "I'm ready — open Statistics" on the proposal ticket: a scheduled proposal is what opens it.
    function dfGoStats() {
        try {
            if (window.parent && window.parent !== window && typeof window.parent.pushView === 'function') {
                window.parent.pushView('zoom-stats', 'module_statistics.php');
                return;
            }
        } catch (e) { /* cross-origin — fall through */ }
        window.top.location.href = 'student.php?module=stats';
    }

    // "I'm ready — send my final manuscript". The manuscript is submitted in the Plagiarism card,
    // so the button hands the student straight there rather than explaining where to go.
    function dfGoPlag() {
        try {
            if (window.parent && window.parent !== window && typeof window.parent.pushView === 'function') {
                window.parent.pushView('zoom-plag', 'module_plagiarism.php');
                return;
            }
        } catch (e) { /* cross-origin — fall through */ }
        window.top.location.href = 'student.php?module=plag';
    }

    function dfOpenTicket() {
        var m = document.getElementById('dfTicketModal');
        if (m) m.classList.add('open');
    }

    // The ticket is painted onto a canvas rather than screenshotted with a library: it keeps
    // the download dependency-free, and gives a crisp 2x PNG that reads well on a phone.
    function dfDownloadTicket(btn) {
        var d = window.DF_TICKET;
        if (!d) return;
        var label = btn.innerHTML;
        btn.disabled = true;
        btn.textContent = 'Preparing…';

        var S = 2, W = 360, H = 430;
        var c = document.createElement('canvas');
        c.width = W * S; c.height = H * S;
        var x = c.getContext('2d');
        x.scale(S, S);

        function rr(px, py, w, h, r) {
            x.beginPath();
            x.moveTo(px + r, py);
            x.arcTo(px + w, py, px + w, py + h, r);
            x.arcTo(px + w, py + h, px, py + h, r);
            x.arcTo(px, py + h, px, py, r);
            x.arcTo(px, py, px + w, py, r);
            x.closePath();
        }

        function paint(mascot) {
            x.clearRect(0, 0, W, H);
            var g = x.createLinearGradient(0, 0, W, H);
            g.addColorStop(0, '#0f172a');
            g.addColorStop(0.62, '#3b1f8f');
            g.addColorStop(1, '#5b21b6');
            rr(0, 0, W, H, 26); x.fillStyle = g; x.fill();

            if (mascot) {
                x.save();
                rr(0, 0, W, H, 26); x.clip();
                x.globalAlpha = 0.5;
                x.globalCompositeOperation = 'screen';
                x.drawImage(mascot, W - 168, H - 172, 160, 160);
                x.globalCompositeOperation = 'source-over';
                x.restore();
            }

            var P = 28;
            x.textBaseline = 'top';
            x.fillStyle = 'rgba(255,255,255,.62)';
            x.font = '800 10px Inter, system-ui, sans-serif';
            x.fillText('RESEARCH & DEVELOPMENT CENTER', P, 34);

            x.fillStyle = '#fff';
            x.font = '800 22px Inter, system-ui, sans-serif';
            x.fillText(d.title, P, 52);

            x.font = '800 62px "JetBrains Mono", monospace';
            x.fillText(d.day, P, 106);
            x.fillStyle = 'rgba(255,255,255,.72)';
            x.font = '700 12px Inter, system-ui, sans-serif';
            x.fillText(d.monthYear.toUpperCase(), P, 176);

            x.textAlign = 'right';
            x.fillStyle = 'rgba(255,255,255,.55)';
            x.font = '800 9px Inter, system-ui, sans-serif';
            x.fillText('DAY', W - P, 150);
            x.fillStyle = '#fff';
            x.font = '750 15px Inter, system-ui, sans-serif';
            x.fillText(d.weekday, W - P, 163);
            x.textAlign = 'left';

            // perforation
            var py = 218;
            x.strokeStyle = 'rgba(255,255,255,.28)';
            x.lineWidth = 1; x.setLineDash([5, 5]);
            x.beginPath(); x.moveTo(18, py); x.lineTo(W - 18, py); x.stroke();
            x.setLineDash([]);
            x.fillStyle = '#fff';
            x.beginPath(); x.arc(0, py, 10, 0, Math.PI * 2); x.fill();
            x.beginPath(); x.arc(W, py, 10, 0, Math.PI * 2); x.fill();

            var y = py + 24;
            x.fillStyle = 'rgba(255,255,255,.55)';
            x.font = '800 9px Inter, system-ui, sans-serif';
            x.fillText('RESEARCH GROUP', P, y);
            y += 15;
            x.fillStyle = '#fff';
            x.font = '750 13px Inter, system-ui, sans-serif';
            // wrap the group name — these run long
            var words = String(d.group).split(' '), line = '', maxW = W - P * 2, lines = 0;
            for (var i = 0; i < words.length && lines < 4; i++) {
                var test = line ? line + ' ' + words[i] : words[i];
                if (x.measureText(test).width > maxW && line) {
                    x.fillText(line, P, y); y += 17; lines++; line = words[i];
                } else { line = test; }
            }
            if (line && lines < 4) { x.fillText(line, P, y); y += 17; }

            y += 12;
            x.fillStyle = 'rgba(255,255,255,.55)';
            x.font = '800 9px Inter, system-ui, sans-serif';
            x.fillText('STATUS', P, y);
            x.fillText('CONFIRMED', W - P - 96, y);
            y += 14;
            x.fillStyle = '#fff';
            x.font = '750 13px Inter, system-ui, sans-serif';
            x.fillText(d.slot ? d.status + '  ·  ' + d.slot : d.status, P, y);
            x.fillText(d.confirmed, W - P - 96, y);

            c.toBlob(function (blob) {
                var url = URL.createObjectURL(blob);
                var a = document.createElement('a');
                a.href = url;
                a.download = d.file + '-' + d.iso + '.png';
                document.body.appendChild(a);
                a.click();
                document.body.removeChild(a);
                setTimeout(function () { URL.revokeObjectURL(url); }, 1500);
                btn.disabled = false;
                btn.innerHTML = label;
                if (window.lucide) lucide.createIcons();
            }, 'image/png');
        }

        var img = new Image();
        img.onload = function () { paint(img); };
        img.onerror = function () { paint(null); }; // the ticket is still worth having without it
        img.src = '../assets/images/mascott_party.png';
    }
    function dfCloseTicket() {
        var m = document.getElementById('dfTicketModal');
        if (m) m.classList.remove('open');
    }
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape') dfCloseTicket(); });

    // Deep link from the dashboard billboard: ?ticket=1 opens the ticket straight away.
    (function () {
        if (!new URLSearchParams(window.location.search).has('ticket')) return;
        function open() { dfOpenTicket(); }
        if (document.readyState === 'complete') setTimeout(open, 120);
        else window.addEventListener('load', function () { setTimeout(open, 120); });
    })();

    <?php if ($df_show_calendar): ?>
    (function () {
        // Availability comes from the server for the whole booking window, so what is clickable
        // here is exactly what defense_handler.php will accept.
        var CAL = <?= json_encode($df_cal, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
        var MIN = <?= json_encode(df_window_start()) ?>;
        var MAX = <?= json_encode(df_window_end()) ?>;
        var chosen = <?= json_encode($df_booking['defense_date'] ?? null) ?>;
        var SLOTS = <?= (int)DEFENSE_SLOTS_PER_SCHOOL_PER_DAY ?>;
        // Preview mode: the calendar is rendered so the group can see how booking works, but
        // nothing in it responds. The panel is dimmed by CSS; this stops the clicks.
        var READONLY = <?= $df_readonly ? 'true' : 'false' ?>;

        var MONTHS = ['January','February','March','April','May','June','July','August','September','October','November','December'];
        var DOW = ['S','M','T','W','T','F','S'];
        var grid = document.getElementById('dfGrid');
        var monthLabel = document.getElementById('dfCalMonth');
        var note = document.getElementById('dfDayNote');
        var pickText = document.getElementById('dfPickText');
        var submit = document.getElementById('dfSubmit');
        var hidden = document.getElementById('dfChosenDate');

        var start = new Date((chosen || MIN) + 'T00:00:00');
        var view = { y: start.getFullYear(), m: start.getMonth() };
        var minD = new Date(MIN + 'T00:00:00'), maxD = new Date(MAX + 'T00:00:00');

        function pad(n) { return String(n).padStart(2, '0'); }
        function key(y, m, d) { return y + '-' + pad(m + 1) + '-' + pad(d); }

        function render() {
            monthLabel.textContent = MONTHS[view.m] + ' ' + view.y;
            grid.innerHTML = '';
            DOW.forEach(function (d) {
                var el = document.createElement('div');
                el.className = 'df-dow';
                el.textContent = d;
                grid.appendChild(el);
            });

            var first = new Date(view.y, view.m, 1).getDay();
            var days = new Date(view.y, view.m + 1, 0).getDate();
            for (var i = 0; i < first; i++) {
                var blank = document.createElement('div');
                blank.className = 'df-day is-blank';
                grid.appendChild(blank);
            }
            for (var d = 1; d <= days; d++) {
                var k = key(view.y, view.m, d);
                var info = CAL[k] || { state: 'out', reason: 'Outside the booking window' };
                var btn = document.createElement('button');
                btn.type = 'button';
                btn.dataset.date = k;
                // Days now hold many groups, so say how much room is left rather than just
                // open/taken. Only worth printing once a day has actually started filling up.
                if (info.slots_left !== undefined && info.slots_left < SLOTS && info.slots_left > 0) {
                    btn.innerHTML = d + '<span class="df-slot-left">' + info.slots_left + ' left</span>';
                } else {
                    btn.textContent = d;
                }

                var cls = 'df-day';
                if (info.state === 'open')         cls += ' is-open';
                else if (info.state === 'mine')    cls += ' is-open is-mine';
                else if (info.state === 'taken')   cls += ' is-taken';
                else if (info.state === 'blocked') cls += ' is-blocked';
                else                                cls += ' is-off';
                if (info.is_today) cls += ' is-today';
                if (chosen === k) cls += ' is-selected';
                btn.className = cls;

                if (info.reason) btn.title = info.reason;
                // aria-disabled, not the `disabled` property: a genuinely disabled button never
                // fires a click, so it could not tell the student WHY the day is unavailable.
                var selectable = !READONLY && (info.state === 'open' || info.state === 'mine');
                if (!selectable) btn.setAttribute('aria-disabled', 'true');
                btn.addEventListener('click', function () {
                    if (READONLY) return;
                    var i = CAL[this.dataset.date];
                    if (i && (i.state === 'open' || i.state === 'mine')) {
                        choose(this.dataset.date);
                    } else if (i && i.reason) {
                        note.textContent = i.reason;
                        note.style.display = 'block';
                    }
                });
                grid.appendChild(btn);
            }

            // Bound the nav to the months the window actually covers.
            var firstOfView = new Date(view.y, view.m, 1);
            var lastOfView = new Date(view.y, view.m + 1, 0);
            document.getElementById('dfPrev').disabled = firstOfView <= new Date(minD.getFullYear(), minD.getMonth(), 1);
            document.getElementById('dfNext').disabled = lastOfView >= new Date(maxD.getFullYear(), maxD.getMonth() + 1, 0);

            if (window.lucide) lucide.createIcons();
        }

        function choose(k) {
            var info = CAL[k];
            if (!info || !(info.state === 'open' || info.state === 'mine')) return;
            chosen = k;
            hidden.value = k;
            var dt = new Date(k + 'T00:00:00');
            pickText.innerHTML = '<strong>' + dt.toLocaleDateString(undefined, { weekday: 'long', month: 'long', day: 'numeric' }) + '</strong>'
                + (info.state === 'mine' ? 'This is the date you already requested.' : 'Tap Request to send this to the office.');
            submit.disabled = false;
            note.style.display = 'none';
            render();
        }

        document.getElementById('dfPrev').addEventListener('click', function () {
            view.m--; if (view.m < 0) { view.m = 11; view.y--; } render();
        });
        document.getElementById('dfNext').addEventListener('click', function () {
            view.m++; if (view.m > 11) { view.m = 0; view.y++; } render();
        });
        var soon = document.getElementById('dfSoonest');
        if (soon) soon.addEventListener('click', function () {
            var d = new Date(this.dataset.date + 'T00:00:00');
            view = { y: d.getFullYear(), m: d.getMonth() };
            choose(this.dataset.date);
        });

        render();
        if (chosen) choose(chosen);
    })();
    <?php endif; ?>
</script>

<script>
    /* ------------------------------------------------------------------------
       The full-screen stage sheet. Shared by module_proposal.php and
       module_final.php: both render #propStageOverlay around this component, so
       the open/close logic belongs here rather than duplicated in each module.
       Opening never navigates, so the requirement cards and the progress behind
       the sheet are exactly where they were when it closes.
       ---------------------------------------------------------------------- */
    function dfOpenStage() {
        var o = document.getElementById('propStageOverlay');
        if (!o) return;
        o.classList.add('open');
        document.body.style.overflow = 'hidden';
        if (window.lucide) lucide.createIcons();
    }
    function dfCloseStage() {
        var o = document.getElementById('propStageOverlay');
        if (!o) return;
        o.classList.remove('open');
        document.body.style.overflow = '';
    }
    // Once scheduled the component renders ONLY the ticket modal, and that modal is a child
    // of the stage overlay — so the overlay must be open for it to be visible at all.
    // Opening the pill straight onto dfOpenTicket() showed nothing but a blank sheet.
    function dfOpenTicketStage() {
        dfOpenStage();
        if (typeof dfOpenTicket === 'function') setTimeout(dfOpenTicket, 30);
    }

    // ...and closing the ticket must take the (otherwise empty) sheet with it. This wraps
    // dfCloseTicket, so it has to run AFTER the ticket script above has defined it.
    (function () {
        var origClose = window.dfCloseTicket;
        window.dfCloseTicket = function () {
            if (typeof origClose === 'function') origClose();
            dfCloseStage();
        };
    })();

    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') dfCloseStage();
    });

    // Deep links: ?view=schedule from the dashboard card and from upload_handler.php /
    // defense_handler.php redirects; ?ticket=1 from the billboard.
    (function () {
        var q = new URLSearchParams(window.location.search);
        if (!q.has('view') && !q.has('ticket')) return;
        if (q.get('view') !== 'schedule' && !q.has('ticket')) return;
        function open() {
            dfOpenStage();
            if (q.has('ticket') && typeof dfOpenTicket === 'function') setTimeout(dfOpenTicket, 160);
        }
        if (document.readyState === 'complete') setTimeout(open, 200);
        else window.addEventListener('load', function () { setTimeout(open, 200); });
    })();
</script>
