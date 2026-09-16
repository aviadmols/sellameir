(function () {
  'use strict';

  /**
   * Shop banners. The markup is printed in the footer and moved above the
   * product grid here, because that section is built in Elementor and has no
   * PHP hook of its own.
   */
  var TARGET = '.books-grid-section, .store-archive-section';

  function boot() {
    var banners = document.querySelector('[data-sella-banners]');
    var target = document.querySelector(TARGET);
    if (!banners || !target) {
      return;
    }

    target.parentNode.insertBefore(banners, target);
    banners.hidden = false;

    var track = banners.querySelector('.sella-banners__track');
    var slides = banners.querySelectorAll('.sella-banners__slide');
    var dotsBox = banners.querySelector('[data-banner-dots]');
    var index = 0;
    var timer = null;

    if (slides.length < 2) {
      return;
    }

    var dots = [];
    if (dotsBox) {
      for (var i = 0; i < slides.length; i++) {
        var dot = document.createElement('button');
        dot.type = 'button';
        dot.className = 'sella-banners__dot';
        dot.setAttribute('aria-label', 'באנר ' + (i + 1));
        dotsBox.appendChild(dot);
        dots.push(dot);
        bindDot(dot, i);
      }
    }

    function bindDot(dot, position) {
      dot.addEventListener('click', function () {
        stop();
        go(position);
      });
    }

    /**
     * Scroll by the distance to the slide rather than to an absolute offset:
     * scrollLeft counts from the right in RTL, so absolute maths lands on the
     * wrong slide, while a delta behaves the same in both directions.
     */
    function go(position, smooth) {
      index = (position + slides.length) % slides.length;
      var delta = slides[index].getBoundingClientRect().left - track.getBoundingClientRect().left;
      track.scrollBy({ left: delta, behavior: false === smooth ? 'auto' : 'smooth' });
      paint();
    }

    function paint() {
      for (var i = 0; i < dots.length; i++) {
        dots[i].classList.toggle('is-active', i === index);
      }
    }

    /** Which slide is in view after a manual swipe. */
    function readIndex() {
      var closest = 0;
      var best = Infinity;
      for (var i = 0; i < slides.length; i++) {
        var distance = Math.abs(slides[i].getBoundingClientRect().left - track.getBoundingClientRect().left);
        if (distance < best) {
          best = distance;
          closest = i;
        }
      }
      if (closest !== index) {
        index = closest;
        paint();
      }
    }

    var scrollTimer = null;
    track.addEventListener('scroll', function () {
      window.clearTimeout(scrollTimer);
      scrollTimer = window.setTimeout(readIndex, 90);
    }, { passive: true });

    var prev = banners.querySelector('[data-banner-prev]');
    var next = banners.querySelector('[data-banner-next]');
    if (prev) {
      prev.addEventListener('click', function () {
        stop();
        go(index - 1);
      });
    }
    if (next) {
      next.addEventListener('click', function () {
        stop();
        go(index + 1);
      });
    }

    function stop() {
      window.clearInterval(timer);
      timer = null;
    }

    var seconds = parseInt(banners.getAttribute('data-autoplay'), 10) || 0;
    if (seconds > 0 && !window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
      timer = window.setInterval(function () {
        go(index + 1);
      }, seconds * 1000);

      banners.addEventListener('mouseenter', stop);
      banners.addEventListener('touchstart', stop, { passive: true });
    }

    // Start on the first banner, whichever edge the browser parks RTL scroll at.
    go(0, false);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot);
  } else {
    boot();
  }
})();
