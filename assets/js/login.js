/**
 * INFOS ERP — Login page UI interactions
 */
(function () {
    'use strict';

    var toggleBtn = document.getElementById('toggle-password');
    var passwordInput = document.getElementById('password');

    if (toggleBtn && passwordInput) {
        toggleBtn.addEventListener('click', function () {
            var isHidden = passwordInput.type === 'password';
            passwordInput.type = isHidden ? 'text' : 'password';

            var icon = toggleBtn.querySelector('i');
            if (icon) {
                icon.classList.toggle('fa-eye', !isHidden);
                icon.classList.toggle('fa-eye-slash', isHidden);
            }

            toggleBtn.setAttribute('aria-label', isHidden ? 'Hide password' : 'Show password');
            toggleBtn.setAttribute('title', isHidden ? 'Hide password' : 'Show password');
        });
    }
})();
