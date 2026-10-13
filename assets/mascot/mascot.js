// assets/mascot/mascot.js — the iSubmit eagle, live (redesigned 2026-10-05).
//
// The pages call the same things they always did — window.Quill.idle(), .coverEyes(), .wave(),
// .state — and the eagle answers with a POSE. 'plain' is the main eagle, drawn inline so her
// feathers move (mascot.php); every other pose is an <img> (assets/mascot/eagle/<pose>.svg):
//   idle()       the resting pose (data-idle: 'plain', or 'wink' when nothing needs you on the
//                staff home)
//   coverEyes()  'covering-eyes' — or 'peeking' when that password field is showing its text
//   wave()       the main eagle waves her wing high and tiptoes, like the old bird
//   happy() / oops() / pose(name)   for anything else
// "Show password": every sign-in page defines a global togglePassword(id, btn); it is wrapped
// here so flipping it turns covering-eyes into peeking and back — no page needed editing.
// A message already on the page sets the first mood: an error makes her worried ('oops') until
// you start typing again, a success makes her happy for a moment.

class MascotEngine {
    constructor(containerId) {
        this.container = document.getElementById(containerId);
        if (!this.container) return;
        this.img = this.container.querySelector('img');
        this.base = this.container.dataset.base || '../assets/mascot/eagle/';
        this.home = this.container.dataset.idle || 'plain';
        this.rest = this.home;
        this.current = this.container.dataset.pose || this.home;
        this.state = 'idle';
        // warm the cache, so a swap is instant the first time too
        ['covering-eyes', 'peeking', 'happy', 'oops', 'wink'].forEach((p) => { const i = new Image(); i.src = this.base + p + '.svg'; });
        this.moodFromPage();
        this.bindEvents();
        this.wrapToggles();
    }

    pose(p) {
        if (!this.img || !p || this.current === p) return;
        this.current = p;
        this.container.dataset.pose = p;                 // CSS shows the inline eagle for 'plain', the <img> otherwise
        if (p !== 'plain') this.img.src = this.base + p + '.svg';
        this.container.classList.remove('eagle-pop');
        void this.container.offsetWidth;               // restart the little pop
        this.container.classList.add('eagle-pop');
    }

    // ── STATES (the names the pages already use) ──
    idle() { if (!this.container) return; this.state = 'idle'; this.pose(this.rest); }

    coverEyes() {
        if (!this.container) return;
        const a = document.activeElement;
        if (a && a.tagName === 'INPUT') this.pw = a;
        this.state = 'shy';
        this.pose(this.pw && this.pw.type === 'text' ? 'peeking' : 'covering-eyes');
    }

    peek() { if (!this.container) return; this.state = 'shy'; this.pose('peeking'); }

    flash(p, ms) {
        if (!this.container) return;
        this.state = p;
        this.pose(p);
        clearTimeout(this.timer);
        this.timer = setTimeout(() => { if (this.state === p) this.idle(); }, ms || 2600);
    }
    happy(ms) { this.flash('happy', ms); }
    oops(ms) { this.flash('oops', ms); }

    wave() {
        if (!this.container || this.state === 'shy') return;
        this.container.classList.remove('eagle-wave');
        void this.container.offsetWidth;
        this.container.classList.add('eagle-wave');   // the wing waves (mascot.css)
        clearTimeout(this.waveTimer);
        this.waveTimer = setTimeout(() => this.container.classList.remove('eagle-wave'), 1900);
        this.flash('plain', 1900);                    // a winking hero waves as the main eagle, then winks again
    }

    // ── WIRING ──
    moodFromPage() {
        const shown = (el) => el && el.offsetParent !== null && el.textContent.trim() !== '';
        const bad = document.querySelector('.mat-alert-error, .msg-error, .ac-alert.bad, [data-eagle="oops"]');
        const good = document.querySelector('.mat-alert-success, .msg-success, .ac-alert.ok, [data-eagle="happy"]');
        if (shown(bad)) {
            this.rest = 'oops';                         // worried until you try again
            this.pose('oops');
            const calm = () => { this.rest = this.home; if (this.state === 'idle') this.idle(); document.removeEventListener('input', calm, true); };
            document.addEventListener('input', calm, true);
        } else if (shown(good)) {
            this.happy(3200);
        }
    }

    bindEvents() {
        this.container.addEventListener('click', () => this.wave());
        this.container.style.cursor = 'pointer';
    }

    wrapToggles() {
        const self = this;
        const orig = window.togglePassword;
        if (typeof orig !== 'function' || orig.__eagle) return;
        const wrapped = function (inputId) {
            const r = orig.apply(this, arguments);
            const input = document.getElementById(inputId);
            if (input) {
                self.pw = input;
                if (input.type === 'text') self.peek();
                else if (document.activeElement === input) self.coverEyes();
                else self.idle();
            }
            return r;
        };
        wrapped.__eagle = true;
        window.togglePassword = wrapped;
    }
}

// Initialize global object when DOM loads (the name stays "Quill": the pages call window.Quill)
document.addEventListener('DOMContentLoaded', () => {
    window.Quill = new MascotEngine('quill-container');
});
