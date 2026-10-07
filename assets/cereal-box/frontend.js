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
	var ESTIMATE_SECONDS = 120;
	var POLL_INTERVAL_MS = 3000;
	// A bit longer than the server's own 10-minute job timeout (queue wait included).
	var POLL_GIVE_UP_MS = 11 * 60 * 1000;
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
	}

	function hideError() {
		$('cdp_alert').classList.remove('visible');
	}

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
		var queuePosition = 0;
		updateLoader(secondsLeft, queuePosition);
		var ticker = setInterval(function () {
			// The estimate only starts counting once it's our turn.
			if (!queuePosition) {
				secondsLeft--;
			}
			updateLoader(secondsLeft, queuePosition);
		}, 1000);

		function finish() {
			clearInterval(ticker);
			state.busy = false;
		}

		function fail(message) {
			finish();
			setGeneratingView(false);
			showError(message);
		}

		// Start the job; the server answers right away and keeps working.
		var sent = null;

		shrinkPhoto(state.file)
			.then(function (photo) {
				sent = photo;
				return startJob(photo, 1);
			})
			.then(function (res) {
				if (res && res.success && res.data && res.data.job) {
					pollJob(res.data.job, function (url) {
						finish();
						showResult(url);
					}, fail, function (position) {
						queuePosition = position;
					});
					return;
				}

				var err = (res && res.data) || {};
				if (err.code === 'access_code') {
					finish();
					setGeneratingView(false);
					state.accessCode = '';
					openCodeModal(true);
					return;
				}
				fail(err.message || 'משהו השתבש ביצירת הקופסה. נסו שוב.');
			})
			.catch(function (err) {
				reportError('start', err, sent);
				fail(err && err.status === 413
					? 'התמונה גדולה מדי. נסו תמונה אחרת.'
					: 'לא הצלחנו להתחבר לשרת. בדקו את החיבור ונסו שוב.');
			});
	}

	/**
	 * Upload the photo and start the job. A failed attempt (connection drop,
	 * an error page instead of JSON) is retried before giving up.
	 * Rejects with { status, body } so the failure can be reported.
	 */
	function startJob(photo, retries) {
		var data = new FormData();
		data.append('action', 'cbg_generate');
		data.append('image', photo, photo.name || 'photo.jpg');
		data.append('name', $('cdp_name').value.trim());
		data.append('age', state.age);
		data.append('suffix', state.suffix);
		data.append('hobby', $('cdp_hobby').value.trim());
		data.append('access_code', state.accessCode);

		return fetch(config.ajaxUrl, { method: 'POST', body: data, credentials: 'same-origin' })
			.then(function (res) {
				return res.text().then(function (text) {
					try {
						return JSON.parse(text);
					} catch (e) {
						throw { status: res.status, body: text.slice(0, 300) };
					}
				});
			}, function (e) {
				throw { status: 0, body: String(e && e.message) };
			})
			.catch(function (err) {
				if (retries > 0 && err.status !== 413) {
					return new Promise(function (resolve) {
						setTimeout(resolve, 2000);
					}).then(function () {
						return startJob(photo, retries - 1);
					});
				}
				throw err;
			});
	}

	// Tell the server what went wrong, so failures can be diagnosed later.
	function reportError(stage, err, photo) {
		try {
			var data = new FormData();
			data.append('action', 'cbg_client_error');
			data.append('stage', stage);
			data.append('status', err && err.status !== undefined ? err.status : '');
			data.append('body', err && err.body ? err.body : String(err));
			data.append('file_size', photo ? photo.size : (state.file ? state.file.size : ''));
			data.append('file_type', photo ? photo.type : (state.file ? state.file.type : ''));
			data.append('original_size', state.file ? state.file.size : '');
			data.append('online', navigator.onLine ? '1' : '0');
			fetch(config.ajaxUrl, { method: 'POST', body: data, credentials: 'same-origin', keepalive: true });
		} catch (e) {
			// Reporting is best effort.
		}
	}

	/**
	 * Ask the server every few seconds whether the job is done.
	 *
	 * A failed check (offline, tab paused in the background) is just retried,
	 * and coming back to the tab checks right away.
	 */
	function pollJob(jobId, onDone, onError, onProgress) {
		var startedAt = Date.now();
		var timer = null;
		var finished = false;

		function stop() {
			finished = true;
			clearTimeout(timer);
			document.removeEventListener('visibilitychange', onVisible);
		}

		function schedule() {
			if (finished) {
				return;
			}
			if (Date.now() - startedAt > POLL_GIVE_UP_MS) {
				stop();
				onError('היצירה לקחה יותר מדי זמן. נסו שוב.');
				return;
			}
			clearTimeout(timer);
			timer = setTimeout(check, POLL_INTERVAL_MS);
		}

		function check() {
			if (finished) {
				return;
			}
			var url = config.ajaxUrl + '?action=cbg_job_status&job=' + encodeURIComponent(jobId) + '&_=' + Date.now();

			fetch(url, { credentials: 'same-origin', cache: 'no-store' })
				.then(function (res) {
					return res.json();
				})
				.then(function (res) {
					var job = (res && res.data) || {};
					if (finished) {
						return;
					}
					if (job.status === 'done') {
						stop();
						onDone(job.preview_url);
					} else if (job.status === 'error') {
						stop();
						onError(job.message || 'משהו השתבש ביצירת הקופסה. נסו שוב.');
					} else {
						onProgress(job.status === 'queued' ? (job.position || 1) : 0);
						schedule();
					}
				})
				.catch(schedule);
		}

		function onVisible() {
			if (document.visibilityState === 'visible') {
				clearTimeout(timer);
				check();
			}
		}

		document.addEventListener('visibilitychange', onVisible);
		schedule();
	}

	/**
	 * Big phone photos: resize to at most 2000px and re-encode as JPEG before
	 * upload. Much faster on mobile data; plenty for the AI. Small photos (and
	 * anything the browser can't decode) go up as they are.
	 */
	function shrinkPhoto(file) {
		var MAX_SIDE = 2000;

		return new Promise(function (resolve) {
			if (file.size < 1.5 * 1024 * 1024 || !window.URL || !document.createElement('canvas').toBlob) {
				resolve(file);
				return;
			}

			var url = URL.createObjectURL(file);
			var img = new Image();

			img.onload = function () {
				URL.revokeObjectURL(url);

				var scale = Math.min(1, MAX_SIDE / Math.max(img.naturalWidth, img.naturalHeight));
				var canvas = document.createElement('canvas');
				canvas.width = Math.round(img.naturalWidth * scale);
				canvas.height = Math.round(img.naturalHeight * scale);
				canvas.getContext('2d').drawImage(img, 0, 0, canvas.width, canvas.height);

				canvas.toBlob(function (blob) {
					if (!blob || blob.size >= file.size) {
						resolve(file);
						return;
					}
					blob.name = 'photo.jpg';
					resolve(blob);
				}, 'image/jpeg', 0.9);
			};
			img.onerror = function () {
				URL.revokeObjectURL(url);
				resolve(file);
			};
			img.src = url;
		});
	}

	function formatTime(seconds) {
		var m = Math.floor(seconds / 60);
		var s = seconds % 60;
		return (m < 10 ? '0' : '') + m + ':' + (s < 10 ? '0' : '') + s;
	}

	// Count down the estimate, then count up the extra time.
	function updateLoader(secondsLeft, queuePosition) {
		if (queuePosition) {
			$('cdp_loader_timer').textContent = 'יש הרבה יוצרים עכשיו, אתם במקום ' + queuePosition + ' בתור';
			$('cdp_loader_step_sub').textContent = 'עוד רגע מתחילים...';
			return;
		}

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
		show($('cdp_result_box'), 'flex');
		scrollTop();
	}

	function restart() {
		hide($('cdp_result_box'));
		setGeneratingView(false);
		gotoStep(0, 'backward');
	}

	function orderCompleted() {
		$('cdp_toast_modal').classList.add('visible');
	}

	/* ---------- Footer pop-ups ---------- */

	var openLegal = null;

	function showLegal(slug) {
		var modal = $('cdp_legal_' + slug);
		if (!modal) {
			return;
		}
		closeLegal();
		modal.classList.add('visible');
		modal.querySelector('.cdp-legal-body').scrollTop = 0;
		document.documentElement.classList.add('cdp-modal-open');
		openLegal = modal;
	}

	function closeLegal() {
		if (!openLegal) {
			return;
		}
		openLegal.classList.remove('visible');
		document.documentElement.classList.remove('cdp-modal-open');
		openLegal = null;
		if (/^#(contact|privacy|accessibility)$/.test(location.hash)) {
			history.replaceState(null, '', location.pathname + location.search);
		}
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
			suffix: selectSuffix,
			legal: function (link) {
				showLegal(link.getAttribute('data-legal'));
			},
			'legal-close': closeLegal
		};

		document.addEventListener('click', function (e) {
			// Click on the dark backdrop around a pop-up closes it.
			if (e.target.classList && e.target.classList.contains('cdp-legal-modal')) {
				closeLegal();
				return;
			}

			var btn = e.target.closest('[data-cdp]');
			if (btn && btn.tagName === 'A') {
				e.preventDefault();
			}
			if (btn && actions[btn.getAttribute('data-cdp')]) {
				actions[btn.getAttribute('data-cdp')](btn);
			}
		});

		document.addEventListener('keydown', function (e) {
			if (e.key === 'Escape') {
				closeLegal();
			}
		});

		// Links like /#privacy open their pop-up.
		var hash = location.hash.replace('#', '');
		if (hash) {
			showLegal(hash);
		}

		// Non-button elements with an action (the intro cards) work from the keyboard too.
		document.addEventListener('keydown', function (e) {
			var el = e.target.closest('[data-cdp][role="button"]');
			if (el && (e.key === 'Enter' || e.key === ' ') && actions[el.getAttribute('data-cdp')]) {
				e.preventDefault();
				actions[el.getAttribute('data-cdp')](el);
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
