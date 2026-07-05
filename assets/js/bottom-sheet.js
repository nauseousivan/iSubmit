document.addEventListener('DOMContentLoaded', () => {
    // Only initialize bottom sheet logic on mobile devices
    if (window.innerWidth > 768) return;

    const sheet = document.querySelector('.bottom-sheet');
    if (!sheet) return;

    const card = sheet.closest('.auth-card');
    if (!card) return;

    let startY = 0;
    let currentY = 0;
    let isDragging = false;

    // Constraints relative to the container rather than the fluctuating window height
    const getContainerHeight = () => card.getBoundingClientRect().height;
    const topExpandedY = 80; // 80px from the top of the container when fully expanded
    let bottomCollapsedY = getContainerHeight() * 0.35; // Starts at 35% down

    // Initial State: 0 means collapsed (bottom), 1 means expanded (top)
    let currentState = 0;

    // Helper to extract current translateY value
    function getTranslateY() {
        const style = window.getComputedStyle(sheet);
        const matrix = new WebKitCSSMatrix(style.transform);
        return matrix.m42;
    }

    sheet.addEventListener('touchstart', (e) => {
        // If the sheet is expanded and the user is scrolling the internal content,
        // we only want to drag the sheet if they are at the absolute top (scrollTop === 0)
        // and they are trying to drag DOWN.
        if (currentState === 1) {
            if (sheet.scrollTop > 0) {
                return; // Let native scrolling happen
            }
        }

        isDragging = true;
        startY = e.touches[0].clientY;
        currentY = getTranslateY();
        sheet.classList.add('dragging');
    }, { passive: true });

    sheet.addEventListener('touchmove', (e) => {
        if (!isDragging) return;

        const touchY = e.touches[0].clientY;
        const deltaY = touchY - startY;

        // If expanded and at top of scroll, only drag if moving downward
        if (currentState === 1 && deltaY < 0) {
            return; // Swiping up when already at top -> let it bounce natively
        }

        // If dragging down from expanded state, prevent default scroll to avoid conflicting with gesture
        if (currentState === 1 && deltaY > 0 && sheet.scrollTop <= 0) {
            e.preventDefault();
        }

        let newY = currentY + deltaY;

        // Resistance at the very top (cannot drag above topExpandedY)
        if (newY < topExpandedY) {
            newY = topExpandedY + (newY - topExpandedY) * 0.3; // Rubber band effect
        }

        // Prevent dragging down past the default collapsed position
        if (newY > bottomCollapsedY) {
            newY = bottomCollapsedY + (newY - bottomCollapsedY) * 0.1; // heavy resistance
        }

        sheet.style.transform = `translateY(${newY}px)`;
    }, { passive: false });

    sheet.addEventListener('touchend', (e) => {
        if (!isDragging) return;
        isDragging = false;
        sheet.classList.remove('dragging');

        const endY = getTranslateY();

        // Determine whether to snap to top or bottom based on velocity/position
        const distanceMoved = endY - currentY; // positive = dragged down, negative = dragged up

        // Ignore simple taps to prevent glitching when clicking inputs
        if (Math.abs(distanceMoved) < 5) return;

        if (currentState === 0) {
            // Started collapsed. If dragged up significantly, expand it.
            if (distanceMoved < -50 || endY < (bottomCollapsedY - 100)) {
                snapToExpanded();
            } else {
                snapToCollapsed();
            }
        } else {
            // Started expanded. If dragged down significantly, collapse it.
            if (distanceMoved > 50 || endY > (topExpandedY + 100)) {
                snapToCollapsed();
            } else {
                snapToExpanded();
            }
        }
    });

    function snapToExpanded() {
        sheet.style.transform = `translateY(${topExpandedY}px)`;
        sheet.style.overflowY = 'auto'; // Enable internal scrolling
        currentState = 1;
    }

    function snapToCollapsed() {
        bottomCollapsedY = getContainerHeight() * 0.35; // Recalculate safely
        sheet.style.transform = `translateY(${bottomCollapsedY}px)`;
        sheet.style.overflowY = 'hidden'; // Lock internal scrolling
        sheet.scrollTop = 0; // Reset scroll
        currentState = 0;
    }

    // Expose to global scope for manual triggering (e.g., when clicking Next Step)
    window.expandBottomSheet = snapToExpanded;
});
