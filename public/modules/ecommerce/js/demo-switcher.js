(function () {
    'use strict';

    function initialise(switcher) {
        if (!switcher || switcher.dataset.demoSwitcherInitialised === 'true') return;

        var button = switcher.querySelector('.demo-profile-toggle');
        var panel = switcher.querySelector('.demo-profile-panel');
        if (!button || !panel) return;

        switcher.dataset.demoSwitcherInitialised = 'true';

        function setOpen(open, restoreFocus) {
            button.setAttribute('aria-expanded', open ? 'true' : 'false');
            panel.hidden = !open;
            switcher.classList.toggle('is-open', open);

            if (open) {
                var current = panel.querySelector('[aria-current="page"]') || panel.querySelector('a');
                if (current) current.focus({ preventScroll: true });
            } else if (restoreFocus) {
                button.focus({ preventScroll: true });
            }
        }

        button.addEventListener('click', function (event) {
            event.preventDefault();
            event.stopPropagation();
            setOpen(button.getAttribute('aria-expanded') !== 'true', false);
        });

        document.addEventListener('click', function (event) {
            if (!switcher.contains(event.target)) setOpen(false, false);
        });

        document.addEventListener('keydown', function (event) {
            if (event.key === 'Escape' && button.getAttribute('aria-expanded') === 'true') {
                setOpen(false, true);
            }
        });

        var footer = document.querySelector('footer');
        if (footer && 'IntersectionObserver' in window) {
            new IntersectionObserver(function (entries) {
                switcher.classList.toggle('is-near-footer', entries[0].isIntersecting);
            }, { threshold: 0.05 }).observe(footer);
        }

        var mobileNav = document.querySelector('.hamburger');
        if (mobileNav && 'MutationObserver' in window) {
            var syncMobileNav = function () {
                switcher.classList.toggle('is-mobile-nav-open', mobileNav.classList.contains('list__open'));
            };
            new MutationObserver(syncMobileNav).observe(mobileNav, { attributes: true, attributeFilter: ['class'] });
            syncMobileNav();
        }

        var cookieBanner = document.querySelector('#cookie-consent, .cookie-consent, .cookie-banner, .js-cookie-consent');
        if (cookieBanner && 'IntersectionObserver' in window) {
            new IntersectionObserver(function (entries) {
                switcher.classList.toggle('is-cookie-visible', entries[0].isIntersecting);
            }, { threshold: 0.05 }).observe(cookieBanner);
        }
    }

    function boot() {
        document.querySelectorAll('[data-demo-switcher]').forEach(initialise);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot, { once: true });
    } else {
        boot();
    }
}());
