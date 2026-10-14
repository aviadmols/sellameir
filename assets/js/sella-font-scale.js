/**
 * "הקטנה גורפת ב-1px": every font-size the site's stylesheets declare, at
 * every breakpoint, comes out 1px smaller. Printed inline at the end of
 * <head> (inc/sella-typography.php), so the head stylesheets are already
 * loaded and rewritten before the first paint; sheets and inline styles
 * added later (footer, popups, AJAX fragments) are rewritten as they come.
 *
 * px values lose 1px, rem/vw/calc/clamp/var() values become calc(x - 1px),
 * em and % values are left alone, as they follow the parent's smaller size.
 * Rules that style form fields keep them at 16px or more, since iOS zooms
 * into a field under 16px when it is tapped. Sheets from other domains
 * (Font Awesome, Swiper, UserWay) cannot be read and are skipped.
 */
(function () {
	'use strict';

	var STEP = 1;
	var FIELD_MIN = 16;
	var seenSheets = new WeakSet();
	// An attribute rather than a WeakSet, so that copies of an element
	// (Swiper's loop clones its slides) are not made smaller a second time.
	var DONE_ATTR = 'data-sella-fs';
	var fieldSelector = /input|select|textarea|elementor-field/i;
	var scalable = /(rem|vw|vh|vmin|vmax|calc\(|clamp\(|min\(|max\(|var\()/i;

	function smaller(value, isField) {
		var px = /^(-?\d*\.?\d+)px$/i.exec(value);

		if (px) {
			var size = parseFloat(px[1]);
			if (size <= STEP) {
				return null;
			}
			var next = size - STEP;
			if (isField && size >= FIELD_MIN) {
				next = Math.max(next, FIELD_MIN);
			}
			return next === size ? null : Math.round(next * 100) / 100 + 'px';
		}

		if (!scalable.test(value)) {
			return null;
		}

		return isField
			? 'max(min(' + value + ', ' + FIELD_MIN + 'px), ' + value + ' - ' + STEP + 'px)'
			: 'calc(' + value + ' - ' + STEP + 'px)';
	}

	function rewrite(style, isField) {
		var value = style.getPropertyValue('font-size');
		if (!value) {
			return;
		}
		var next = smaller(value.trim(), isField);
		if (next) {
			style.setProperty('font-size', next, style.getPropertyPriority('font-size'));
		}
	}

	function walk(rules) {
		for (var i = 0; i < rules.length; i++) {
			var rule = rules[i];
			if (rule.styleSheet) {
				scanSheet(rule.styleSheet);
			}
			if (rule.style) {
				rewrite(rule.style, fieldSelector.test(rule.selectorText || ''));
			}
			if (rule.cssRules) {
				walk(rule.cssRules);
			}
		}
	}

	function scanSheet(sheet) {
		if (seenSheets.has(sheet)) {
			return;
		}
		var rules;
		try {
			rules = sheet.cssRules;
		} catch (e) {
			// Another domain's sheet; it stays as it is.
			seenSheets.add(sheet);
			return;
		}
		if (!rules) {
			// Still loading: the load listener below comes back for it.
			return;
		}
		seenSheets.add(sheet);
		walk(rules);
	}

	function scan() {
		var sheets = document.styleSheets;
		for (var i = 0; i < sheets.length; i++) {
			scanSheet(sheets[i]);
		}

		var styled = document.querySelectorAll('[style*="font-size"]:not([' + DONE_ATTR + '])');
		for (var j = 0; j < styled.length; j++) {
			styled[j].setAttribute(DONE_ATTR, '');
			rewrite(styled[j].style, /^(INPUT|SELECT|TEXTAREA)$/.test(styled[j].tagName));
		}
	}

	var queued = false;
	function queueScan() {
		if (queued) {
			return;
		}
		queued = true;
		(window.requestAnimationFrame || setTimeout)(function () {
			queued = false;
			scan();
		});
	}

	scan();

	// A <link> stylesheet's load event does not bubble, so listen in capture.
	document.addEventListener('load', function (event) {
		if (event.target && event.target.tagName === 'LINK') {
			queueScan();
		}
	}, true);
	document.addEventListener('DOMContentLoaded', scan);
	window.addEventListener('load', scan);

	if (window.MutationObserver) {
		new MutationObserver(queueScan).observe(document.documentElement, {
			childList: true,
			subtree: true
		});
	}
})();
