/**
 * theme.js — Helios University
 * Handles dark/light mode toggle, persistence, and smooth scroll-reveal animations.
 * Include as: <script src="theme.js"></script> just before </body>.
 */

(function () {
    /* ── Theme ── */
    function applyTheme(theme) {
        document.documentElement.setAttribute('data-theme', theme);
        localStorage.setItem('theme', theme);
    }

    var saved = localStorage.getItem('theme') ||
        (window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light');
    applyTheme(saved);

    document.addEventListener('DOMContentLoaded', function () {
        /* Wire theme toggles */
        document.querySelectorAll('.theme-toggle').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var current = document.documentElement.getAttribute('data-theme');
                applyTheme(current === 'dark' ? 'light' : 'dark');
            });
        });

        /* ── Scroll Reveal ── */
        var reveals = document.querySelectorAll('.reveal');
        if (!reveals.length) return;

        var io = new IntersectionObserver(function (entries) {
            entries.forEach(function (entry) {
                if (entry.isIntersecting) {
                    entry.target.classList.add('visible');
                    io.unobserve(entry.target);
                }
            });
        }, { threshold: 0.12, rootMargin: '0px 0px -40px 0px' });

        reveals.forEach(function (el) { io.observe(el); });
    });
})();