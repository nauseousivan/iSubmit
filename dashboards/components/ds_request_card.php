<?php
// dashboards/components/ds_request_card.php — the request card body, shared by both places on
// the Scheduling screen that show a group awaiting a date decision.
//
// Renders the card OPENING TAG through the last note, and stops short of `.ds-rc-foot`: the two
// callers need different footers (the legacy per-group queue logs and rejects from here; the
// section-bundle list only ticks). The caller emits its own footer and closes the </div>.
//
// Expects from the includer: $b (a defense_bookings row joined to users, plus 'conflict'),
// $ds_rosters, $ds_app_docs, $ds_item_names, $df_phase, $csrf.
// Optional: $ds_pick — when true the card carries a slot badge and a keep[] tick instead of
// being purely informational.
$ds_pick = $ds_pick ?? false;
?>                    <div class="ds-reqcard<?= $b['conflict'] ? ' is-conflict' : '' ?>">
                        <?php // The requested date leads the card: it is the one thing being decided here. ?>
                        <div class="ds-rc-head">
                            <div class="ds-rc-daybox">
                                <span class="d"><?= $ts ? date('j', $ts) : '—' ?></span>
                                <span class="m"><?= $ts ? date('M', $ts) : '' ?></span>
                            </div>
                            <div class="ds-rc-when">
                                <strong><?= $ts ? date('l', $ts) : 'No date selected' ?></strong>
                                <span><?= $ts ? date('F j, Y', $ts) : '' ?> &middot; asked <?= date('M j, g:i A', strtotime($b['updated_at'])) ?></span>
                            </div>
                            <?php /* Bundle mode: the slot this group holds, and the tick that
                                     decides whether it is actually logged. Untick and the group
                                     comes off the day — see finalize_batch in focal_handler.php. */ ?>
                            <?php if ($ds_pick): ?>
                                <label class="ds-rc-pick" title="Log this group">
                                    <span class="ds-rc-slot">slot <?= (int)($b['slot_no'] ?? 0) ?></span>
                                    <input type="checkbox" class="ds-keep" name="keep[]"
                                           value="<?= (int)$b['user_id'] ?>" checked onchange="dsSyncLog(this.form)">
                                </label>
                            <?php endif; ?>
                        </div>

                        <div class="ds-rc-body">
                            <div class="ds-rc-who">
                                <div class="ds-avatar"><?= htmlspecialchars(strtoupper(substr((string)$b['username'], 0, 1))) ?></div>
                                <div class="ds-rc-whotext">
                                    <strong><?= htmlspecialchars($b['research_group_name'] ?: 'Unnamed group') ?></strong>
                                    <span><?= htmlspecialchars($b['username']) ?><?= $b['program'] ? ' &middot; ' . htmlspecialchars($b['program']) : '' ?></span>
                                </div>
                            </div>
                            <div class="ds-rc-chips">
                                <?php if ($b['student_number']): ?>
                                    <span class="school-id-chip"><i data-lucide="id-card"></i><?= htmlspecialchars($b['student_number']) ?></span>
                                <?php endif; ?>
                                <?= $b_names['button'] ?>
                            </div>
                            <?= $b_names['panel'] ?>

                        <?php if ($b['conflict']): ?>
                            <div class="ds-warn">
                                <i data-lucide="alert-triangle" style="width:15px;height:15px;flex-shrink:0;margin-top:1px;"></i>
                                <span>This date is no longer available — <?= htmlspecialchars($b['conflict']) ?>. Reject it so the group can pick another.</span>
                            </div>
                        <?php endif; ?>

                        <?php // The documents this stage collects, listed in full — this is the
                        // secretary's whole job here: checking that what was sent is the right
                        // thing, and that it has actually been cleared in the Checklist.
                        $ds_docs = $ds_app_docs[$b['user_id']] ?? [];
                        $ds_expected = df_phase2_items($df_phase);
                        // Only documents with a Checklist review surface can block a confirmation.
                        // Proposal's endorsement has none — it is accepted by confirming — so that
                        // phase is never gated. See df_phase2_reviewed_items().
                        $ds_gated = df_phase2_reviewed_items($df_phase);
                        $ds_ok = 0;
                        foreach ($ds_docs as $d) {
                            if (in_array((int)$d['item_id'], $ds_gated, true) && $d['verification_status'] === 'Approved') $ds_ok++;
                        }
                        $ds_all_clear = ($ds_ok === count($ds_gated));
                        // One collapsed row, not six. This screen is about the DATE; the documents
                        // are decided in the Checklist, so each row here is a link into that screen
                        // with the requirement's own review window already open — never a file
                        // preview, which would invite deciding in the wrong place.
                        if ($ds_expected): ?>
                            <button type="button" class="ds-reqtoggle" onclick="dsToggleReqs(<?= (int)$b['booking_id'] ?>)"
                                    aria-expanded="false" id="dsReqBtn-<?= (int)$b['booking_id'] ?>">
                                <i data-lucide="chevron-right" class="ds-reqchev" style="width:15px;height:15px;flex-shrink:0;"></i>
                                <span class="ds-reqlabel">Requirements</span>
                                <span class="ds-reqcount <?= $ds_all_clear ? 'is-clear' : '' ?>">
                                    <?= $ds_ok ?> of <?= count($ds_expected) ?> cleared
                                </span>
                            </button>
                            <div class="ds-reqlist" id="dsReqs-<?= (int)$b['booking_id'] ?>">
                                <?php $ds_by_item = [];
                                foreach ($ds_docs as $d) $ds_by_item[(int)$d['item_id']] = $d;
                                foreach ($ds_expected as $ds_item):
                                    $d = $ds_by_item[$ds_item] ?? null;
                                    $st = $d['verification_status'] ?? null;
                                    $ap = ($st === 'Approved');
                                    $rv = ($st === 'Revision Requested');
                                    $ic = $ap ? 'check-circle-2' : ($rv ? 'undo-2' : ($d ? 'clock' : 'circle-dashed'));
                                    $cl = $ap ? '#059669' : ($rv ? '#dc2626' : ($d ? '#d97706' : '#94a3b8'));
                                    // group + open_item is the existing deep link: it opens the
                                    // group's workspace AND clicks that requirement's
                                    // "Open & decide" button. See admin_module_dynamic.php.
                                    $href = 'admin_module_dynamic.php?phase=' . urlencode($df_phase)
                                          . '&group=' . (int)$b['user_id']
                                          . ($d ? '&open_item=' . $ds_item : '');
                                ?>
                                    <a class="ds-reqrow<?= $d ? '' : ' is-missing' ?>" href="<?= htmlspecialchars($href) ?>"
                                       title="<?= $d ? 'Open this requirement in the Checklist' : 'Not submitted yet' ?>">
                                        <i data-lucide="<?= $ic ?>" style="width:15px;height:15px;flex-shrink:0;color:<?= $cl ?>;"></i>
                                        <span class="ds-reqname"><?= htmlspecialchars($ds_item_names[$ds_item] ?? ('Item ' . $ds_item)) ?></span>
                                        <span class="ds-reqstate"><?= $st ? htmlspecialchars($st) : 'Not submitted' ?></span>
                                        <?php if ($d): ?><i data-lucide="arrow-up-right" style="width:13px;height:13px;flex-shrink:0;opacity:.55;"></i><?php endif; ?>
                                    </a>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                        <?php // The old "Missing: a, b, c" line is gone — every requirement now
                        // carries its own state inside the list above, including "Not submitted". ?>
                        <?php // Confirming the date is the last act: it mints the slot, issues the
                        // ticket and releases the group's reward. It must not happen while any
                        // REVIEWED document is still unreviewed — clear them in Checklist first.
                        if ($ds_gated && !$ds_all_clear): ?>
                            <div class="ds-sub" style="color:#b45309;font-size:11.5px;line-height:1.45;">
                                <i data-lucide="clipboard-list" style="width:13px;height:13px;vertical-align:-2px;"></i>
                                <strong><?= $ds_ok ?> of <?= count($ds_gated) ?> cleared.</strong>
                                Approve the rest in Checklist before logging this date.
                            </div>
                        <?php endif; ?>

