// assets/mascot/mascot.js

class MascotEngine {
    constructor(containerId) {
        this.container = document.getElementById(containerId);
        if (!this.container) return;

        this.svg = document.getElementById('quill-svg');
        this.pupils = document.getElementById('quill-pupils');
        
        this.state = 'idle'; // 'idle', 'shy', 'success'
        
        // Initial setup
        this.idle();
        this.bindEvents();
    }

    // ── STATES ──
    
    idle() {
        if (!this.container) return;
        this.state = 'idle';
        this.container.className = 'quill-idle';
        // Reset pupil position just in case
        if (this.pupils) this.pupils.style.transform = 'translate(0px, 0px)';
    }

    coverEyes() {
        if (!this.container) return;
        this.state = 'shy';
        this.container.className = 'quill-shy';
        // Pupils move down slightly when shy via CSS
    }

    wave() {
        if (!this.container) return;
        this.state = 'wave';
        this.container.className = 'quill-wave';
        
        // Reset back to idle after 2 seconds
        setTimeout(() => {
            if (this.state === 'wave') {
                this.idle();
            }
        }, 2000);
    }

    // ── INTERACTIONS ──

    trackCursor(e) {
        // Only track if we are in idle or success state (not covering eyes)
        if (!this.container || !this.pupils || this.state === 'shy') return;

        // Get SVG position
        const rect = this.svg.getBoundingClientRect();
        
        // Calculate center of the mascot's face (approximate)
        const centerX = rect.left + rect.width / 2;
        const centerY = rect.top + rect.height / 2;

        // Calculate delta
        const deltaX = e.clientX - centerX;
        const deltaY = e.clientY - centerY;

        // Max distance pupils can move (in pixels relative to SVG viewBox scale)
        const maxMove = 3.5; 
        
        // Calculate angle and distance
        const angle = Math.atan2(deltaY, deltaX);
        const distance = Math.min(maxMove, Math.sqrt(deltaX*deltaX + deltaY*deltaY) / 50);

        const moveX = Math.cos(angle) * distance;
        const moveY = Math.sin(angle) * distance;

        // Apply transform to the pupil group
        this.pupils.style.transform = `translate(${moveX}px, ${moveY}px)`;
    }

    bindEvents() {
        document.addEventListener('mousemove', (e) => {
            // Use requestAnimationFrame for performance
            requestAnimationFrame(() => this.trackCursor(e));
        });

        // Click mascot to trigger wave
        if (this.container) {
            this.container.addEventListener('click', () => {
                if (this.state !== 'shy') {
                    this.wave();
                }
            });
            this.container.style.cursor = 'pointer';
        }
    }
}

// Initialize global object when DOM loads
document.addEventListener('DOMContentLoaded', () => {
    window.Quill = new MascotEngine('quill-container');
});
