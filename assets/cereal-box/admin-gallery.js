/**
 * "הפקות" admin page: enlarge, copy link to the slim version, send on WhatsApp.
 *
 * Config from PHP: window.cornflexBoxGallery = { ajaxUrl, nonce }.
 */
(function () {
	'use strict';

	var config = window.cornflexBoxGallery || {};
	var lightbox = document.querySelector('.cbx-lightbox');
	var toast = document.querySelector('.cbx-toast');
	var toastTimer = null;
	var leanLinks = {};

	function showToast(text) {
		toast.textContent = text;
		toast.hidden = false;
		clearTimeout(toastTimer);
		toastTimer = setTimeout(function () {
			toast.hidden = true;
		}, 2500);
	}

	function openLightbox(src) {
		lightbox.querySelector('img').src = src;
		lightbox.hidden = false;
	}

	function closeLightbox() {
		lightbox.hidden = true;
		lightbox.querySelector('img').removeAttribute('src');
	}

	// Public URL of the slim version; the server makes it on first use.
	function leanLink(id) {
		if (leanLinks[id]) {
			return Promise.resolve(leanLinks[id]);
		}

		var data = new FormData();
		data.append('action', 'cornflex_box_lean_link');
		data.append('nonce', config.nonce);
		data.append('id', id);

		return fetch(config.ajaxUrl, { method: 'POST', body: data, credentials: 'same-origin' })
			.then(function (res) {
				return res.json();
			})
			.then(function (res) {
				if (!res || !res.success) {
					throw new Error((res && res.data) || 'שגיאה');
				}
				leanLinks[id] = res.data.url;
				return res.data.url;
			});
	}

	function copyText(text) {
		if (navigator.clipboard && window.isSecureContext) {
			return navigator.clipboard.writeText(text);
		}
		var input = document.createElement('textarea');
		input.value = text;
		document.body.appendChild(input);
		input.select();
		document.execCommand('copy');
		document.body.removeChild(input);
		return Promise.resolve();
	}

	document.addEventListener('click', function (e) {
		var el = e.target.closest('[data-cbx]');

		if (!el) {
			if (e.target === lightbox || e.target.parentNode === lightbox) {
				closeLightbox();
			}
			return;
		}

		var action = el.getAttribute('data-cbx');

		if (action === 'view') {
			openLightbox(el.getAttribute('data-large'));
		} else if (action === 'close') {
			closeLightbox();
		} else if (action === 'copy') {
			el.disabled = true;
			leanLink(el.getAttribute('data-id'))
				.then(copyText)
				.then(function () {
					showToast('הקישור הועתק');
				})
				.catch(function (err) {
					showToast(err.message);
				})
				.then(function () {
					el.disabled = false;
				});
		} else if (action === 'whatsapp') {
			// Open the window now (still inside the click), fill in the link when ready.
			var win = window.open('', '_blank');
			leanLink(el.getAttribute('data-id'))
				.then(function (url) {
					win.location.href = 'https://wa.me/?text=' + encodeURIComponent('הקופסה שלכם מוכנה! ' + url);
				})
				.catch(function (err) {
					win.close();
					showToast(err.message);
				});
		}
	});

	document.addEventListener('keydown', function (e) {
		if (e.key === 'Escape') {
			closeLightbox();
		}
	});
})();
