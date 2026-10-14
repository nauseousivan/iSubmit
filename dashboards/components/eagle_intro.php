<?php
// components/eagle_intro.php — the "Eagle flight" loading screen (2026-10-07; shared 2026-10-14).
//
// Include it ONCE, right after <body>, on a page whose <body> starts with class="no-intro-delay"
// (auth/login.php and the front page, index.php). It plays on the FIRST of those pages a visitor opens in a
// browser session (sessionStorage 'mcnp_isap_intro_played'), so landing on the front page and then
// pressing Sign in does not play it twice.
//
// The main eagle (ids "sp-", assets/mascot/eagle_svg.php) flaps in from the lower left, loops across a
// drifting doodle sky and lands on the "i" of iSubmit as its dot. Her dotted trail draws behind her — the
// trail IS the progress bar. On landing she bounces, tiptoes and waves (.splash-wave), then the curtain
// fades. The path is built from where the "i" really is, so she lands on it at any font size. Tap skips.
// Reduced motion: she is simply sitting on the "i". While it plays, <body> carries .intro-playing (login
// holds its card back on it); window.endIntro() ends it, and a 6s timer is the net if anything fails.
// Self-contained (theme.css is not loaded on the front page); the colours fall back to the brand violet.
require_once __DIR__ . '/../../assets/mascot/eagle_svg.php';
?>
<style>
    .intro-cinematic-curtain { position: fixed; inset: 0; width: 100%; height: 100vh; background-color: #f1efff; z-index: 100000;
        display: flex; align-items: center; justify-content: center; overflow: hidden; cursor: pointer;
        transition: opacity .5s cubic-bezier(.16, 1, .3, 1), visibility 0s linear .5s; }
    .intro-cinematic-curtain.is-out { opacity: 0; visibility: hidden; }
    body.no-intro-delay .intro-cinematic-curtain { display: none !important; }
    /* The doodle wallpaper of the brand pane, in violet on the sky, drifting past as she flies. */
    .intro-cinematic-curtain::before { content: ""; position: absolute; inset: -160px; background: var(--brand-solid, #5b50f5);
        -webkit-mask: var(--brand-doodles) 0 0 / 160px 160px repeat; mask: var(--brand-doodles) 0 0 / 160px 160px repeat;
        opacity: .75; animation: skyDrift 6s linear infinite; }
    @keyframes skyDrift { to { transform: translateX(-160px); } }   /* exactly one tile, so the loop never jumps */

    .fl-stage { position: relative; width: 360px; height: 270px; }
    .fl-trail { position: absolute; inset: 0; overflow: visible; color: #a39dff; transition: opacity .45s ease; }
    .fl-trail-mask { stroke-dasharray: 1; stroke-dashoffset: 1; }
    .fl-bird { position: absolute; top: 0; left: 0; width: 86px; height: 89px; offset-anchor: 48% 94%; offset-rotate: 0deg;
               offset-distance: 0%; opacity: 0; }
    .fl-tilt { width: 100%; height: 100%; transform-origin: 48% 94%; }
    .splash-eagle { width: 100%; height: 100%; display: block; overflow: visible; filter: drop-shadow(0 8px 12px rgba(76, 29, 149, .22)); }
    .fl-word { position: absolute; left: 0; right: 0; bottom: 18px; text-align: center; margin: 0; font-family: 'Poppins', sans-serif;
               font-size: 64px; font-weight: 800; letter-spacing: -2px; line-height: 1; color: #5b50f5; white-space: nowrap; }
    .fl-word span { display: inline-block; opacity: 0; transform: translateY(18px) scale(.6); }
    .fl-bl { display: inline-block; width: 0; height: 0; vertical-align: baseline; }   /* marks the baseline for the script */
    .fl-skip { position: absolute; bottom: calc(26px + env(safe-area-inset-bottom)); left: 0; right: 0; text-align: center;
               font-family: 'Plus Jakarta Sans', system-ui, sans-serif; font-size: 12px; font-weight: 600; letter-spacing: .4px; color: #8b83c9;
               opacity: 0; transition: opacity .4s ease .8s; }
    @media (max-width: 400px) { .fl-stage { transform: scale(.88); } }

    /* in flight */
    .intro-fly .fl-bird  { opacity: 1; offset-distance: 100%; transition: offset-distance 1.5s cubic-bezier(.45, .05, .35, 1), opacity .2s ease; }
    .intro-fly .fl-trail-mask { stroke-dashoffset: 0; transition: stroke-dashoffset 1.5s cubic-bezier(.45, .05, .35, 1); }
    .intro-fly .fl-tilt  { animation: flTilt 1.5s cubic-bezier(.45, .05, .35, 1) forwards; }
    .intro-fly #sp-wingL { animation: flFlapL .18s ease-in-out infinite alternate; }
    .intro-fly #sp-wingR { animation: flFlapR .18s ease-in-out infinite alternate; }
    .intro-fly .fl-word span { animation: flPop .45s cubic-bezier(.34, 1.56, .64, 1) forwards; animation-delay: calc(.35s + var(--n) * .08s); }
    .intro-fly .fl-skip  { opacity: 1; }
    /* landed */
    .intro-landed .fl-trail { opacity: 0; }
    .intro-landed .fl-tilt  { animation: flLand .4s cubic-bezier(.34, 1.56, .64, 1); }
    .intro-landed #sp-wingL, .intro-landed #sp-wingR { animation: none; }

    #sp-wingL { transform-box: view-box; transform-origin: 70px 166px; }
    #sp-wingR { transform-box: view-box; transform-origin: 190px 166px; }
    #sp-body  { transform-box: view-box; transform-origin: 170px 256px; transition: transform .35s ease; }
    #sp-footL { transform-box: view-box; transform-origin: 90px 256px; transition: transform .35s ease; }
    #sp-cap   { transform-box: view-box; transform-origin: 128px 60px; }
    .splash-wave #sp-body  { transform: rotate(7deg); }
    .splash-wave #sp-footL { transform: translateY(-9px) rotate(16deg); }
    .intro-landed .splash-wave #sp-wingL { animation: spWaveHigh .4s ease-in-out infinite alternate; }
    .splash-wave #sp-wingR { transform: rotate(-8deg); }
    .splash-wave #sp-cap   { animation: spCapBob .4s ease-in-out infinite alternate; }
    @keyframes flFlapL { from { transform: rotate(-12deg); } to { transform: rotate(46deg); } }
    @keyframes flFlapR { from { transform: rotate(12deg); } to { transform: rotate(-46deg); } }
    @keyframes flTilt { 0% { transform: rotate(-10deg); } 35% { transform: rotate(-16deg); } 62% { transform: rotate(12deg); } 88% { transform: rotate(10deg); } 100% { transform: rotate(0); } }
    @keyframes flLand { 0% { transform: scale(1.12, .84); } 55% { transform: scale(.94, 1.08); } 100% { transform: scale(1); } }
    @keyframes flPop { to { opacity: 1; transform: none; } }
    @keyframes spWaveHigh { from { transform: rotate(118deg); } to { transform: rotate(150deg); } }
    @keyframes spCapBob { from { transform: rotate(-2deg); } to { transform: rotate(2deg); } }
    @media (prefers-reduced-motion: reduce) {
        .intro-cinematic-curtain::before { animation: none; }
        .fl-bird, .intro-fly .fl-bird { opacity: 1; offset-distance: 100%; transition: none; }
        .fl-trail { display: none; }
        .fl-word span, .intro-fly .fl-word span { opacity: 1; transform: none; animation: none; }
        .intro-fly .fl-tilt, .intro-landed .fl-tilt, .intro-fly #sp-wingL, .intro-fly #sp-wingR, .splash-wave #sp-cap,
        .intro-landed .splash-wave #sp-wingL { animation: none; }
    }
</style>
<script>
    window.isFirstLoad = !sessionStorage.getItem('mcnp_isap_intro_played');
    if (window.isFirstLoad) {
        document.body.classList.remove('no-intro-delay');
        document.body.classList.add('intro-playing');   // login holds its card back on this until she lands
        sessionStorage.setItem('mcnp_isap_intro_played', 'true');
    }
    // Ends the intro: fade the curtain. Safe to call twice; the 6s timer is the net if anything below fails,
    // so the page can never stay hidden.
    window.endIntro = function () {
        if (!document.body.classList.contains('intro-playing')) return;
        document.body.classList.remove('intro-playing');
        var c = document.getElementById('introCurtain');
        if (c) { c.classList.add('is-out'); setTimeout(function () { c.style.display = 'none'; }, 600); }
    };
    if (window.isFirstLoad) setTimeout(window.endIntro, 6000);
</script>

<!-- iSubmit Loading Curtain: "Eagle flight" -->
<div class="intro-cinematic-curtain" id="introCurtain" aria-hidden="true" onclick="window.endIntro()">
    <div class="fl-stage" id="flStage">
        <svg class="fl-trail" width="360" height="270" viewBox="0 0 360 270">
            <defs><mask id="flMask" maskUnits="userSpaceOnUse" x="-200" y="-200" width="760" height="670">
                <path class="fl-trail-mask" id="flMaskPath" d="" fill="none" stroke="#fff" stroke-width="16" stroke-linecap="round" pathLength="1"/>
            </mask></defs>
            <path id="flTrailPath" d="" fill="none" stroke="currentColor" stroke-width="5" stroke-linecap="round" stroke-dasharray="0 13" mask="url(#flMask)"/>
        </svg>
        <div class="fl-word" id="flWord"><?php
            // "ı" (dotless) — she is the dot. Each letter pops in as she passes.
            foreach (['ı', 'S', 'u', 'b', 'm', 'i', 't'] as $n => $ch) {
                echo '<span style="--n:' . $n . '"' . ($n === 0 ? ' id="flI"' : '') . '>' . $ch . ($n === 0 ? '<i class="fl-bl" id="flBase"></i>' : '') . '</span>';
            } ?></div>
        <div class="fl-bird" id="flBird"><div class="fl-tilt"><?= eagle_plain_svg('sp', 'id="splash-mascot" class="splash-eagle"') ?></div></div>
    </div>
    <div class="fl-skip">Tap to skip</div>
</div>
<script>
    // "Eagle flight" intro (first page of the session only; see the CSS above for the look).
    (function () {
        if (!document.body.classList.contains('intro-playing')) return;
        const curtain = document.getElementById('introCurtain');
        const word = document.getElementById('flWord'), dotless = document.getElementById('flI'), base = document.getElementById('flBase');
        const still = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
        let started = false;

        // Where the stem of the "ı" starts, in stage pixels. offset* ignore the letters' pop-in
        // transforms; the stem height comes from the font itself.
        function landingPoint() {
            const fs = parseFloat(getComputedStyle(word).fontSize);
            let ascent = fs * 0.56;
            try {
                const ctx = document.createElement('canvas').getContext('2d');
                ctx.font = '800 ' + fs + 'px Poppins';
                const m = ctx.measureText('ı');
                if (m.actualBoundingBoxAscent > 0) ascent = m.actualBoundingBoxAscent;
            } catch (e) {}
            return {
                x: word.offsetLeft + dotless.offsetLeft + dotless.offsetWidth / 2 - 1,
                y: word.offsetTop + base.offsetTop - ascent - 6
            };
        }

        function fly() {
            if (started) return;
            started = true;
            const e = landingPoint();
            // In low from the left, a loop high over the stage, then down onto the "ı".
            const d = 'M -70 230 C 40 40, 300 -40, 330 70 C 352 150, ' + (e.x + 90) + ' ' + (e.y - 120) + ', ' + e.x + ' ' + e.y;
            document.getElementById('flTrailPath').setAttribute('d', d);
            document.getElementById('flMaskPath').setAttribute('d', d);
            document.getElementById('flBird').style.offsetPath = "path('" + d + "')";
            const eagle = document.getElementById('splash-mascot');

            if (still) { curtain.classList.add('intro-fly', 'intro-landed'); setTimeout(window.endIntro, 900); return; }
            void curtain.offsetWidth;                         // commit the start position before moving
            curtain.classList.add('intro-fly');
            setTimeout(function () {                          // touchdown
                curtain.classList.add('intro-landed');
                setTimeout(function () { eagle.classList.add('splash-wave'); }, 250);
            }, 1500);
            setTimeout(window.endIntro, 2600);
        }

        // Wait for Poppins (the landing spot depends on it), but never more than 400ms.
        if (document.fonts && document.fonts.load) {
            Promise.race([document.fonts.load('800 64px Poppins'), new Promise(function (r) { setTimeout(r, 400); })]).then(fly, fly);
        } else {
            fly();
        }
    })();
</script>
