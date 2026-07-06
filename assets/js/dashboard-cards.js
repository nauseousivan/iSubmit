lucide.createIcons();

// Toast
(function () {
    const toast = document.getElementById('upload-toast');
    if (!toast) return;
    setTimeout(() => toast.classList.add('toast-visible'), 120);
    setTimeout(() => toast.classList.remove('toast-visible'), 2700);
})();

// Download preview modal
function openDownloadModal(url, name) {
    document.getElementById('dm-name').textContent = name;
    document.getElementById('dm-preview-btn').onclick = function () { window.open(url, '_blank'); };
    document.getElementById('dm-download-btn').onclick = function () {
        const a = document.createElement('a');
        a.href = url; a.download = ''; document.body.appendChild(a); a.click(); document.body.removeChild(a);
    };
    document.getElementById('download-modal').classList.add('open');
    lucide.createIcons();
    try { history.pushState({ layer: 'download' }, '', ''); } catch (e) { }
}
function closeDlModal(fromPopstate = false) {
    document.getElementById('download-modal').classList.remove('open');
    if (!fromPopstate) {
        history.back();
    }
}

// Camera / file picker
function triggerCamera(itemId) {
    const input = document.getElementById('file-input-' + itemId);
    input.setAttribute('accept', 'image/*');
    input.setAttribute('capture', 'environment');
    input.click();
}
function triggerFilePicker(itemId) {
    const input = document.getElementById('file-input-' + itemId);
    input.setAttribute('accept', 'image/*,.jpg,.jpeg,.png,.pdf,.doc,.docx');
    input.removeAttribute('capture');
    input.click();
}
function handleFileChange(input) {
    if (!input.files || !input.files.length) return;
    const form = input.closest('form');
    form.querySelectorAll('button').forEach(b => {
        b.disabled = true;
        b.style.opacity = '0.65';
        b.style.pointerEvents = 'none';
    });
    const primary = form.querySelector('.btn-primary');
    if (primary) primary.innerHTML = '<span class="upload-spinner"></span> Uploading...';
    form.submit();
}
function handleUploadStart(form) {
    form.querySelectorAll('button').forEach(b => { b.disabled = true; });
}

// Delete Upload Action
function deleteUpload(uploadId, context) {
    if (!confirm('Are you sure you want to delete this file?')) return;
    const form = document.createElement('form');
    form.method = 'POST';
    form.action = 'upload_handler.php';

    const actionInput = document.createElement('input');
    actionInput.type = 'hidden';
    actionInput.name = 'action';
    actionInput.value = 'delete_upload';
    form.appendChild(actionInput);

    const idInput = document.createElement('input');
    idInput.type = 'hidden';
    idInput.name = 'upload_id';
    idInput.value = uploadId;
    form.appendChild(idInput);

    const contextInput = document.createElement('input');
    contextInput.type = 'hidden';
    contextInput.name = 'module_context';
    contextInput.value = context;
    form.appendChild(contextInput);

    document.body.appendChild(form);
    form.submit();
}

// History panel
function openHistoryPanel(itemId) {
    const panel = document.getElementById('history-panel-' + itemId);
    const backdrop = document.getElementById('history-backdrop');
    if (!panel) return;
    panel.classList.add('open');
    backdrop.classList.add('visible');
    document.body.style.overflow = 'hidden';
    lucide.createIcons();

    try {
        history.pushState({ layer: 'history' }, '', '');
    } catch (e) { }
}
function closeHistoryPanel(fromPopstate = false) {
    document.querySelectorAll('.history-panel.open').forEach(p => p.classList.remove('open'));
    const backdrop = document.getElementById('history-backdrop');
    if (backdrop) backdrop.classList.remove('visible');
    if (!document.querySelector('.wallet-active')) {
        document.body.style.overflow = '';
    }
    if (!fromPopstate) {
        history.back();
    }
}



function expandWalletCard(cardEl, event) {
    if (window.innerWidth > 768) return;
    if (cardEl.classList.contains('wallet-active')) return;
    // Calculate exact position for seamless FLIP animation
    const grid = document.querySelector('.items-grid');
    cardEl.style.transition = 'none';
    cardEl.style.transform = 'translateY(100vh)';

    grid.classList.add('has-active-card');
    document.body.classList.add('has-active-wallet');
    cardEl.classList.add('wallet-active');

    // Force reflow
    void cardEl.offsetWidth;

    cardEl.style.transition = 'transform 0.35s cubic-bezier(0.2,0.8,0.2,1)';
    cardEl.style.transform = 'translateY(0)';

    // PUSH STATE LOGIC for back button
    try {
        const correctHash = parent.document.querySelector('.fullscreen-zoom-overlay.active').id.replace('zoom-', '');
        history.pushState({ walletOpen: true }, '', '#' + correctHash);
    } catch (e) {
        history.pushState({ walletOpen: true }, '', '');
    }
}



// Consolidated popstate listener for back button navigation
window.addEventListener('popstate', function (event) {
    // Priority 0: Form008 Modal
    const form008Modal = document.getElementById('studentForm008Modal');
    if (form008Modal && form008Modal.classList.contains('open')) {
        window.closeForm008Modal(true);
        return;
    }

    // Priority 1: Download Modal
    const dlModal = document.getElementById('download-modal');
    if (dlModal && dlModal.classList.contains('open')) {
        closeDlModal(true);
        return;
    }

    // Priority 2: History Panel
    const openHistory = document.querySelector('.history-panel.open');
    if (openHistory) {
        closeHistoryPanel(true);
        return;
    }

    // Priority 3: Wallet Card
    const openCard = document.querySelector('.wallet-active');
    if (openCard) {
        window.collapseCard(openCard, true);
    }
});

window.collapseCard = function (cardEl, fromPopstate = false) {
    try {
        if (typeof window.event !== 'undefined' && window.event) {
            window.event.stopPropagation();
        }
    } catch (e) { }

    // Animate card down
    cardEl.style.transition = 'transform 0.35s cubic-bezier(0.2,0.8,0.2,1)';
    cardEl.style.transform = 'translateY(100vh)';

    setTimeout(() => {
        const grid = document.querySelector('.items-grid');
        cardEl.classList.remove('wallet-active');
        cardEl.style.transition = '';
        cardEl.style.transform = '';
        grid.classList.remove('has-active-card');
        document.body.classList.remove('has-active-wallet');

        // Remove the placeholder so the card snaps perfectly back into its slot
        if (cardEl._walletPlaceholder) {
            cardEl._walletPlaceholder.remove();
            cardEl._walletPlaceholder = null;
        }
    }, 350);

    if (!fromPopstate) {
        history.back();
    }
};        // Drag to dismiss for wallet cards
document.addEventListener('DOMContentLoaded', () => {
    let cardStartY = 0;
    let cardCurrentY = 0;
    let isCardDragging = false;
    let activeDraggingCard = null;

    document.addEventListener('touchstart', (e) => {
        const card = e.target.closest('.item-card.wallet-active');
        if (!card) return;

        // Only allow drag from the card header, not the scrollable body!
        const header = e.target.closest('.card-inner-bg');
        const body = e.target.closest('.card-body');
        if (body) return; // if they are touching the body (which might scroll), don't drag

        if (header) {
            cardStartY = e.touches[0].clientY;
            isCardDragging = true;
            activeDraggingCard = card;
            card.style.transition = 'none'; // disable CSS transition while dragging
        }
    }, {
        passive: true
    });

    document.addEventListener('touchmove', (e) => {
        if (!isCardDragging || !activeDraggingCard) return;

        cardCurrentY = e.touches[0].clientY;
        const deltaY = cardCurrentY - cardStartY;

        // Only drag downwards
        if (deltaY > 0) {
            activeDraggingCard.style.transform = `translateY(${deltaY}px)`;
        }
    }, {
        passive: true
    });

    document.addEventListener('touchend', (e) => {
        if (!isCardDragging || !activeDraggingCard) return;
        isCardDragging = false;

        activeDraggingCard.style.transition = 'all 0.45s cubic-bezier(0.2, 0.8, 0.2, 1)';

        const deltaY = cardCurrentY - cardStartY;

        if (deltaY > 120) { // threshold
            // Trigger close button
            const backBtn = activeDraggingCard.querySelector('.wallet-back-btn');
            if (backBtn) {
                backBtn.click();
            } else {
                // fallback
                activeDraggingCard.classList.remove('wallet-active');
                document.body.classList.remove('has-active-wallet');
                document.querySelector('.items-grid').classList.remove('has-active-card');
            }
        }

        // Reset transform
        activeDraggingCard.style.transform = '';
        activeDraggingCard = null;
    });
});
