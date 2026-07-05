document.addEventListener('DOMContentLoaded', () => {
    // Only apply on non-touch (desktop) devices
    if (window.matchMedia('(hover: none) and (pointer: coarse)').matches) {
        return;
    }

    const canvas = document.createElement('canvas');
    canvas.id = 'constellationCanvas';
    Object.assign(canvas.style, {
        position: 'fixed',
        top: '0',
        left: '0',
        width: '100vw',
        height: '100vh',
        pointerEvents: 'none',
        zIndex: '0', // Behind auth wrapper
        opacity: '0.8'
    });

    document.body.appendChild(canvas);
    // Make sure the auth wrapper is on top
    const wrapper = document.querySelector('.auth-wrapper');
    if(wrapper) {
        wrapper.style.position = 'relative';
        wrapper.style.zIndex = '1';
    }

    const ctx = canvas.getContext('2d');
    let width, height;
    let particles = [];

    function getThemeColor() {
        const theme = document.body.getAttribute('data-theme');
        if (theme === 'mcnp') return '26, 86, 219'; // Blue
        if (theme === 'isap') return '225, 29, 72'; // Red
        return '108, 99, 255'; // Default Purple
    }

    const init = () => {
        width = canvas.width = window.innerWidth;
        height = canvas.height = window.innerHeight;
        particles = [];
        
        // Number of particles depends on screen size (prevent crowding)
        const numParticles = Math.floor((width * height) / 12000);
        
        for (let i = 0; i < numParticles; i++) {
            particles.push({
                x: Math.random() * width,
                y: Math.random() * height,
                vx: (Math.random() - 0.5) * 0.8,
                vy: (Math.random() - 0.5) * 0.8,
                radius: Math.random() * 1.5 + 0.5
            });
        }
    };

    let mouse = { x: null, y: null, radius: 180 };

    window.addEventListener('mousemove', (e) => {
        mouse.x = e.clientX;
        mouse.y = e.clientY;
    });
    
    window.addEventListener('mouseout', () => {
        mouse.x = null;
        mouse.y = null;
    });

    window.addEventListener('resize', init);

    const animate = () => {
        ctx.clearRect(0, 0, width, height);
        const currentColor = getThemeColor();

        for (let i = 0; i < particles.length; i++) {
            let p = particles[i];

            p.x += p.vx;
            p.y += p.vy;

            // Bounce off edges smoothly
            if (p.x < 0 || p.x > width) p.vx *= -1;
            if (p.y < 0 || p.y > height) p.vy *= -1;

            // Draw particle
            ctx.beginPath();
            ctx.arc(p.x, p.y, p.radius, 0, Math.PI * 2);
            ctx.fillStyle = `rgba(${currentColor}, 0.6)`;
            ctx.fill();

            // Connect particle to mouse
            if (mouse.x != null && mouse.y != null) {
                let dx = mouse.x - p.x;
                let dy = mouse.y - p.y;
                let dist = Math.sqrt(dx * dx + dy * dy);

                if (dist < mouse.radius) {
                    // Push particles away slightly (interactive physics)
                    const force = (mouse.radius - dist) / mouse.radius;
                    p.x -= (dx / dist) * force * 0.5;
                    p.y -= (dy / dist) * force * 0.5;

                    ctx.beginPath();
                    ctx.strokeStyle = `rgba(${currentColor}, ${0.4 * (1 - dist / mouse.radius)})`;
                    ctx.lineWidth = 1;
                    ctx.moveTo(p.x, p.y);
                    ctx.lineTo(mouse.x, mouse.y);
                    ctx.stroke();
                }
            }

            // Connect particles to each other
            for (let j = i + 1; j < particles.length; j++) {
                let p2 = particles[j];
                let dx = p.x - p2.x;
                let dy = p.y - p2.y;
                let dist = Math.sqrt(dx * dx + dy * dy);

                if (dist < 120) {
                    ctx.beginPath();
                    ctx.strokeStyle = `rgba(${currentColor}, ${0.2 * (1 - dist / 120)})`;
                    ctx.lineWidth = 0.5;
                    ctx.moveTo(p.x, p.y);
                    ctx.lineTo(p2.x, p2.y);
                    ctx.stroke();
                }
            }
        }
        requestAnimationFrame(animate);
    };

    init();
    animate();
});
