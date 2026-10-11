/**
 * מהדורת האלף page: keep the hero clear of a floating header, and open a
 * gallery photo in a lightbox.
 */
(function () {
	'use strict';

	var header = document.querySelector('.sella-n1000-header-over .elementor-location-header');
	if (header) {
		var fit = function () {
			document.body.style.setProperty('--n1000-header-h', header.offsetHeight + 'px');
		};
		fit();
		if ('ResizeObserver' in window) {
			new ResizeObserver(fit).observe(header);
		} else {
			window.addEventListener('resize', fit);
		}
	}

	var box = document.querySelector('.sella-n1000__lightbox');
	if (!box) {
		return;
	}
	var img = box.querySelector('img');
	var closeBtn = box.querySelector('.sella-n1000__lightbox-close');

	function open(src) {
		img.src = src;
		box.classList.add('is-open');
		box.setAttribute('aria-hidden', 'false');
		document.documentElement.classList.add('sella-n1000-locked');
		closeBtn.focus();
	}

	function close() {
		box.classList.remove('is-open');
		box.setAttribute('aria-hidden', 'true');
		document.documentElement.classList.remove('sella-n1000-locked');
	}

	document.querySelectorAll('.sella-n1000__thumb').forEach(function (thumb) {
		thumb.addEventListener('click', function () {
			open(thumb.getAttribute('data-full'));
		});
	});

	closeBtn.addEventListener('click', close);
	box.addEventListener('click', function (e) {
		if (e.target === box) {
			close();
		}
	});
	document.addEventListener('keydown', function (e) {
		if ('Escape' === e.key && box.classList.contains('is-open')) {
			close();
		}
	});
})();
