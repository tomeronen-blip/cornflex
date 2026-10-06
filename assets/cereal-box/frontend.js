/**
 * Cereal box wizard: intro → 5 steps → (access code) → generation → 3D box result.
 *
 * Config comes from PHP as window.cornflexBox: { ajaxUrl, requiresCode }.
 */
(function () {
	'use strict';

	var config = window.cornflexBox || {};
	var MAX_FILE_SIZE = 10 * 1024 * 1024;
	var LAST_STEP = 4;
	var ESTIMATE_SECONDS = 90;
	// Spread evenly over the estimate; the last one stays until the box is ready.
	var LOADER_MESSAGES = [
		'התמונה בפנים...',
		'השם כבר על הקופסה...',
		'מוסיפים קצת מהעולם שלהם...',
		'מפזרים את הפתיתים הפריכים...',
		'ממלאים את הקערה...',
		'עוד רגע וזה מוכן...',
		'כמעט מוכן.'
	];

	var state = {
		step: 0,
		age: 8,
		suffix: 'FLAKES',
		file: null,
		accessCode: '',
		busy: false
	};

	function $(id) {
		return document.getElementById(id);
	}

	function show(el, display) {
		el.style.display = display || 'block';
	}

	function hide(el) {
		el.style.display = 'none';
	}

	function scrollTop() {
		window.scrollTo({ top: 0, behavior: 'smooth' });
	}

	// Show the message in the current step, right above its buttons.
	function showError(msg) {
		var box = $('cdp_alert');
		var step = $('cdp_step_' + state.step);
		var row = step && step.querySelector('.cdp-btn-row');
		if (row) {
			step.insertBefore(box, row);
		}
		box.textContent = msg;
		box.classList.add('visible');
		box.scrollIntoView({ behavior: 'smooth', block: 'center' });
	}

	function hideError() {
		$('cdp_alert').classList.remove('visible');
	}

	// Block double-tap zoom on mobile, except inside inputs.
	(function () {
		var lastTouchEnd = 0;
		document.addEventListener('touchend', function (e) {
			var now = Date.now();
			if (now - lastTouchEnd <= 300 && !e.target.closest('input, textarea')) {
				e.preventDefault();
			}
			lastTouchEnd = now;
		}, { passive: false });
	})();

	/* ---------- Navigation ---------- */

	function startJourney() {
		hide($('cdp_intro_section'));
		show($('cdp_wizard_container'), 'flex');
		show($('cdp_back_outside_btn'), 'inline-block');
		scrollTop();
	}

	function backToIntro() {
		hide($('cdp_wizard_container'));
		show($('cdp_intro_section'));
		hide($('cdp_back_outside_btn'));
		hideError();
		scrollTop();
	}

	// The first step before `target` that is missing input, or null.
	function firstInvalidStep(target) {
		if (target >= 1 && !state.file) {
			return { step: 0, message: 'נא לבחור תמונה כדי להמשיך.' };
		}
		if (target >= 2 && !$('cdp_name').value.trim()) {
			return { step: 1, message: 'נא להזין שם באנגלית.' };
		}
		return null;
	}

	// Go to the missing step (if not already there) and explain what's missing.
	function reportInvalid(invalid) {
		if (invalid.step !== state.step) {
			gotoStep(invalid.step, 'backward');
		}
		showError(invalid.message);
	}

	function gotoStep(target, direction) {
		var invalid = direction === 'backward' ? null : firstInvalidStep(target);
		if (invalid) {
			reportInvalid(invalid);
			return;
		}

		hideError();

		if (target === 2) {
			updateChipNames($('cdp_name').value);
		}

		var animClass = direction === 'backward' ? 'slide-in-left' : 'slide-in-right';

		for (var i = 0; i <= LAST_STEP; i++) {
			$('cdp_step_' + i).classList.remove('current', 'slide-in-right', 'slide-in-left');
			$('cdp_prog_' + i).classList.toggle('active', i <= target);
		}

		$('cdp_step_' + target).classList.add('current', animClass);
		state.step = target;
		show($('cdp_back_outside_btn'), 'inline-block');
		scrollTop();
	}

	function handleBack() {
		if (state.step === 0) {
			backToIntro();
		} else {
			gotoStep(state.step - 1, 'backward');
		}
	}

	/* ---------- Inputs ---------- */

	function updateChipNames(name) {
		var clean = name.trim().toUpperCase() || 'DANIEL';
		document.querySelectorAll('.cdp-chip-name-slot').forEach(function (el) {
			el.textContent = clean;
		});
	}

	function changeAge(diff) {
		state.age = Math.max(1, Math.min(99, state.age + diff));
		$('cdp_age_display').textContent = state.age;
	}

	function selectSuffix(btn) {
		document.querySelectorAll('.cdp-chip-btn').forEach(function (el) {
			el.classList.remove('selected');
		});
		btn.classList.add('selected');
		state.suffix = btn.getAttribute('data-suffix');
	}

	function onFileChosen(file) {
		if (!file) {
			return;
		}
		if (['image/jpeg', 'image/png', 'image/webp'].indexOf(file.type) === -1) {
			showError('אפשר להעלות רק JPG, PNG או WEBP.');
			return;
		}
		if (file.size > MAX_FILE_SIZE) {
			showError('התמונה גדולה מדי. אפשר עד 10MB.');
			return;
		}

		hideError();
		state.file = file;

		var thumb = $('cdp_preview_thumb');
		if (thumb.src && thumb.src.indexOf('blob:') === 0) {
			URL.revokeObjectURL(thumb.src);
		}
		thumb.src = URL.createObjectURL(file);
		show(thumb);
		hide($('cdp_drop_icon'));
		$('cdp_drop_text').textContent = 'תמונה מעולה!';
		$('cdp_drop_subtext').textContent = 'רוצים להחליף? לחצו כאן.';
	}

	/* ---------- Access code ---------- */

	function openCodeModal(wrongCode) {
		$('cdp_code_error').classList.toggle('visible', !!wrongCode);
		$('cdp_code_input').value = '';
		$('cdp_code_modal').classList.add('visible');
		$('cdp_code_input').focus();
	}

	function closeCodeModal() {
		$('cdp_code_modal').classList.remove('visible');
	}

	function submitCode() {
		var code = $('cdp_code_input').value.trim();
		if (!code) {
			$('cdp_code_input').focus();
			return;
		}
		state.accessCode = code;
		closeCodeModal();
		generate();
	}

	/* ---------- Generation ---------- */

	function requestGenerate() {
		if (state.busy) {
			return;
		}
		var invalid = firstInvalidStep(LAST_STEP);
		if (invalid) {
			reportInvalid(invalid);
			return;
		}
		if (config.requiresCode && !state.accessCode) {
			openCodeModal(false);
			return;
		}
		generate();
	}

	function setGeneratingView(on) {
		var viewport = document.querySelector('.cdp-step-viewport');
		var progress = document.querySelector('.cdp-progress-bar');
		if (on) {
			hide(viewport);
			hide(progress);
			hide($('cdp_back_outside_btn'));
			show($('cdp_loader_box'), 'flex');
		} else {
			hide($('cdp_loader_box'));
			show(viewport);
			show(progress, 'flex');
			show($('cdp_back_outside_btn'), 'inline-block');
		}
	}

	function generate() {
		state.busy = true;
		hideError();
		setGeneratingView(true);
		scrollTop();

		var secondsLeft = ESTIMATE_SECONDS;
		updateLoader(secondsLeft);
		var ticker = setInterval(function () {
			updateLoader(--secondsLeft);
		}, 1000);

		var data = new FormData();
		data.append('action', 'cbg_generate');
		data.append('image', state.file);
		data.append('name', $('cdp_name').value.trim());
		data.append('age', state.age);
		data.append('suffix', state.suffix);
		data.append('hobby', $('cdp_hobby').value.trim());
		data.append('access_code', state.accessCode);

		fetch(config.ajaxUrl, { method: 'POST', body: data, credentials: 'same-origin' })
			.then(function (res) {
				return res.json();
			})
			.then(function (res) {
				if (res && res.success) {
					showResult(res.data.preview_url);
					return;
				}

				var err = (res && res.data) || {};
				setGeneratingView(false);

				if (err.code === 'access_code') {
					state.accessCode = '';
					openCodeModal(true);
					return;
				}
				showError(err.message || 'משהו השתבש ביצירת הקופסה. נסו שוב.');
			})
			.catch(function () {
				setGeneratingView(false);
				showError('לא הצלחנו להתחבר לשרת. בדקו את החיבור ונסו שוב.');
			})
			.then(function () {
				clearInterval(ticker);
				state.busy = false;
			});
	}

	function formatTime(seconds) {
		var m = Math.floor(seconds / 60);
		var s = seconds % 60;
		return (m < 10 ? '0' : '') + m + ':' + (s < 10 ? '0' : '') + s;
	}

	// Count down the estimate, then count up the extra time.
	function updateLoader(secondsLeft) {
		var elapsed = ESTIMATE_SECONDS - secondsLeft;
		var slot = ESTIMATE_SECONDS / LOADER_MESSAGES.length;
		var index = Math.min(LOADER_MESSAGES.length - 1, Math.floor(elapsed / slot));

		$('cdp_loader_timer').textContent = secondsLeft >= 0
			? 'זמן משוער: ' + formatTime(secondsLeft)
			: 'משלימים ליטוש אחרון... (' + formatTime(-secondsLeft) + ')';
		$('cdp_loader_step_sub').textContent = LOADER_MESSAGES[index];
	}

	function showResult(url) {
		hide($('cdp_loader_box'));
		$('cdp_result_display').src = url;
		extractBrandColor(url);
		show($('cdp_result_box'), 'flex');
		scrollTop();
	}

	// Color the box sides with the average color of the cover's top-left corner.
	function extractBrandColor(src) {
		var img = new Image();
		img.crossOrigin = 'anonymous';
		img.onload = function () {
			var size = 30;
			var sample = Math.floor(size * 0.4);
			var canvas = document.createElement('canvas');
			var ctx = canvas.getContext('2d');
			canvas.width = size;
			canvas.height = size;

			try {
				ctx.drawImage(img, 0, 0, size, size);
				var px = ctx.getImageData(0, 0, sample, sample).data;
				var r = 0, g = 0, b = 0, n = px.length / 4;
				for (var i = 0; i < px.length; i += 4) {
					r += px[i];
					g += px[i + 1];
					b += px[i + 2];
				}
				document.querySelector('.cdp-wrapper').style.setProperty(
					'--brand-color',
					'rgb(' + Math.round(r / n) + ', ' + Math.round(g / n) + ', ' + Math.round(b / n) + ')'
				);
			} catch (e) {
				// Cross-origin image: keep the default side color.
			}
		};
		img.src = src;
	}

	function restart() {
		hide($('cdp_result_box'));
		setGeneratingView(false);
		gotoStep(0, 'backward');
	}

	function orderCompleted() {
		$('cdp_toast_modal').classList.add('visible');
	}

	/* ---------- Wiring ---------- */

	function init() {
		if (!$('cdp_wizard_container')) {
			return;
		}

		var actions = {
			start: startJourney,
			back: handleBack,
			generate: requestGenerate,
			restart: restart,
			order: orderCompleted,
			'code-submit': submitCode,
			'code-cancel': closeCodeModal,
			next: function (btn) {
				gotoStep(parseInt(btn.getAttribute('data-step'), 10), 'forward');
			},
			age: function (btn) {
				changeAge(parseInt(btn.getAttribute('data-diff'), 10));
			},
			suffix: selectSuffix
		};

		document.addEventListener('click', function (e) {
			var btn = e.target.closest('[data-cdp]');
			if (btn && actions[btn.getAttribute('data-cdp')]) {
				actions[btn.getAttribute('data-cdp')](btn);
			}
		});

		var dropzone = $('cdp_dropzone');
		var fileInput = $('cdp_file');

		dropzone.addEventListener('click', function () {
			fileInput.click();
		});
		dropzone.addEventListener('keydown', function (e) {
			if (e.key === 'Enter' || e.key === ' ') {
				e.preventDefault();
				fileInput.click();
			}
		});
		dropzone.addEventListener('dragover', function (e) {
			e.preventDefault();
		});
		dropzone.addEventListener('drop', function (e) {
			e.preventDefault();
			if (e.dataTransfer.files && e.dataTransfer.files[0]) {
				onFileChosen(e.dataTransfer.files[0]);
			}
		});
		fileInput.addEventListener('change', function () {
			onFileChosen(this.files && this.files[0]);
		});

		$('cdp_name').addEventListener('input', function () {
			this.value = this.value.toUpperCase().replace(/[^A-Z\s]/g, '');
			updateChipNames(this.value);
		});

		// Enter moves to the next step in text inputs.
		$('cdp_name').addEventListener('keydown', function (e) {
			if (e.key === 'Enter') {
				gotoStep(2, 'forward');
			}
		});
		$('cdp_hobby').addEventListener('keydown', function (e) {
			if (e.key === 'Enter') {
				requestGenerate();
			}
		});
		$('cdp_code_input').addEventListener('keydown', function (e) {
			if (e.key === 'Enter') {
				submitCode();
			}
		});
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}
})();
