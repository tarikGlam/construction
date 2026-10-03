(function (window, document) {
    'use strict';

    function enabled(value) {
        return ['1', 'true', 'yes', 'on'].indexOf(String(value || '').toLowerCase()) !== -1;
    }

    function controls(element, selector) {
        var section = element.closest('section');
        return section ? section.querySelector(selector) : null;
    }

    function autoplay(element) {
        return enabled(element.dataset.autoplay) ? { delay: 4000 } : false;
    }

    document.querySelectorAll('.category-slider-wrapper').forEach(function (element) {
        new window.Swiper(element, {
            slidesPerView: document.body.classList.contains('fashion-theme') ? 3 : 6,
            spaceBetween: 30,
            lazy: true,
            loop: enabled(element.dataset.loop),
            navigation: {
                nextEl: controls(element, '.category-button-next'),
                prevEl: controls(element, '.category-button-prev')
            },
            autoplay: autoplay(element),
            breakpoints: {
                675: { slidesPerView: 2, spaceBetween: 30 },
                991: { slidesPerView: 4, spaceBetween: 30 },
                1024: { slidesPerView: 6, spaceBetween: 15 }
            }
        });
    });

    document.querySelectorAll('.product-slider-wrapper').forEach(function (element) {
        new window.Swiper(element, {
            slidesPerView: 5,
            spaceBetween: 0,
            lazy: true,
            observer: true,
            observeParents: true,
            loop: enabled(element.dataset.loop),
            navigation: {
                nextEl: controls(element, '.product-button-next'),
                prevEl: controls(element, '.product-button-prev')
            },
            autoplay: autoplay(element),
            breakpoints: {
                675: { slidesPerView: 2, spaceBetween: 30 },
                991: { slidesPerView: 4, spaceBetween: 30 },
                1024: { slidesPerView: 6, spaceBetween: 15 }
            }
        });
    });

    document.querySelectorAll('img[data-src]').forEach(function (image) {
        if (image.dataset.src) {
            image.src = image.dataset.src;
        }
    });
})(window, document);
