// assets/js/ripple.js

document.addEventListener('DOMContentLoaded', () => {
    function createRipple(event) {
        const button = event.currentTarget;

        const circle = document.createElement('span');
        const diameter = Math.max(button.clientWidth, button.clientHeight);
        const radius = diameter / 2;

        const rect = button.getBoundingClientRect();
        
        // Handle keyboard clicks (Enter/Space) where clientX/clientY is 0
        const isKeyboardClick = event.clientX === 0 && event.clientY === 0;
        
        let x, y;
        if (isKeyboardClick) {
            x = rect.width / 2;
            y = rect.height / 2;
        } else {
            x = event.clientX - rect.left;
            y = event.clientY - rect.top;
        }

        circle.style.width = circle.style.height = `${diameter}px`;
        circle.style.left = `${x - radius}px`;
        circle.style.top = `${y - radius}px`;
        circle.classList.add('ripple');

        const existingRipple = button.querySelector('.ripple');
        if (existingRipple) {
            existingRipple.remove();
        }

        button.appendChild(circle);
    }

    function attachRipples() {
        const buttons = document.querySelectorAll('.mat-btn');
        for (const button of buttons) {
            // Only add if not already added to avoid multiple listeners
            if (!button.dataset.rippleAttached) {
                button.classList.add('mat-ripple-active');
                button.addEventListener('mousedown', createRipple);
                // Also trigger on touch
                button.addEventListener('touchstart', (e) => {
                    const touch = e.touches[0];
                    const event = {
                        currentTarget: button,
                        clientX: touch.clientX,
                        clientY: touch.clientY
                    };
                    createRipple(event);
                }, { passive: true });
                button.dataset.rippleAttached = 'true';
            }
        }
    }

    // Attach initial ripples
    attachRipples();

    // Re-attach if DOM changes (useful for dynamic forms/modals)
    const observer = new MutationObserver((mutations) => {
        let shouldAttach = false;
        for (let mutation of mutations) {
            if (mutation.addedNodes.length > 0) {
                shouldAttach = true;
                break;
            }
        }
        if (shouldAttach) attachRipples();
    });

    observer.observe(document.body, { childList: true, subtree: true });
});
