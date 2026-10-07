/**
 * Cornflex dashboard panel: hover tooltip on the 14-day chart.
 */
(function () {
	'use strict';

	var chart = document.querySelector('.cfd-chart');
	if (!chart) {
		return;
	}

	var tip = chart.querySelector('.cfd-tooltip');

	chart.addEventListener('mousemove', function (e) {
		var col = e.target.closest('.cfd-col');
		if (!col) {
			tip.hidden = true;
			return;
		}

		var box = chart.getBoundingClientRect();
		var colBox = col.getBoundingClientRect();

		tip.textContent = col.getAttribute('data-tip');
		tip.style.left = (colBox.left + colBox.width / 2 - box.left) + 'px';
		tip.style.top = (e.clientY - box.top - 10) + 'px';
		tip.hidden = false;
	});

	chart.addEventListener('mouseleave', function () {
		tip.hidden = true;
	});
})();
