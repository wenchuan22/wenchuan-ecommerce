(function () {
    'use strict';

    var sidebar = document.getElementById('sidebar');
    var navToggle = document.getElementById('navToggle');
    var navBackdrop = document.getElementById('navBackdrop');
    var body = document.body;

    function closeNav() {
        if (!sidebar) {
            return;
        }
        sidebar.classList.remove('is-open');
        body.classList.remove('nav-open');
        if (navToggle) {
            navToggle.setAttribute('aria-expanded', 'false');
        }
    }

    function openNav() {
        if (!sidebar) {
            return;
        }
        sidebar.classList.add('is-open');
        body.classList.add('nav-open');
        if (navToggle) {
            navToggle.setAttribute('aria-expanded', 'true');
        }
    }

    if (navToggle) {
        navToggle.addEventListener('click', function () {
            if (sidebar && sidebar.classList.contains('is-open')) {
                closeNav();
            } else {
                openNav();
            }
        });
    }

    if (navBackdrop) {
        navBackdrop.addEventListener('click', closeNav);
    }

    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape') {
            closeNav();
        }
    });

    window.addEventListener('resize', function () {
        if (window.innerWidth > 1180) {
            closeNav();
        }
    });

    document.querySelectorAll('.flash-close').forEach(function (button) {
        button.addEventListener('click', function () {
            var flash = button.closest('.flash');
            if (!flash) {
                return;
            }
            flash.style.transition = 'opacity .16s ease, transform .16s ease';
            flash.style.opacity = '0';
            flash.style.transform = 'translateY(-4px)';
            window.setTimeout(function () {
                flash.remove();
            }, 170);
        });
    });

    document.querySelectorAll('[data-password-toggle]').forEach(function (button) {
        button.addEventListener('click', function () {
            var wrap = button.closest('.password-wrap');
            var input = wrap ? wrap.querySelector('input') : null;
            if (!input) {
                return;
            }
            var visible = input.getAttribute('type') === 'text';
            input.setAttribute('type', visible ? 'password' : 'text');
            button.textContent = visible ? '显示' : '隐藏';
        });
    });

    document.querySelectorAll('form[data-confirm]').forEach(function (form) {
        form.addEventListener('submit', function (event) {
            var message = form.getAttribute('data-confirm') || '确认执行此操作？';
            if (!window.confirm(message)) {
                event.preventDefault();
            }
        });
    });

    document.querySelectorAll('[data-auto-submit]').forEach(function (control) {
        control.addEventListener('change', function () {
            var form = control.closest('form');
            if (form) {
                form.submit();
            }
        });
    });

    document.querySelectorAll('a[data-confirm]').forEach(function (link) {
        link.addEventListener('click', function (event) {
            var message = link.getAttribute('data-confirm') || '确认执行此操作？';
            if (!window.confirm(message)) {
                event.preventDefault();
            }
        });
    });
})();
