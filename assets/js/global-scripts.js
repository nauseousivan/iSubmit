document.addEventListener("DOMContentLoaded", function() {
            const savedTheme = localStorage.getItem('rd-portal-theme');
            if (savedTheme) {
                document.body.className = savedTheme;
            }
        });
        window.addEventListener('storage', function(e) {
            if (e.key === 'rd-portal-theme' && e.newValue) {
                document.body.className = e.newValue;
            }
        });