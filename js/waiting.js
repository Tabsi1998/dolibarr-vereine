/* Copyright (C) 2026 IT-Tabelander <https://it.tabelander.co.at>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program. If not, see <https://www.gnu.org/licenses/>.
 */

/*
 * What waits for the user (#267): the counter in the top bar follows every half minute, and anything new pops up
 * once with a link to where it is done - a vote in a meeting, a circular resolution, a signature. Nothing is sent;
 * the page asks the module what waits for the user logged in and shows it.
 */
jQuery(function ($) {
	var counter = $('[data-vereine-waiting-url]').first();
	if (!counter.length) {
		return;
	}
	var address = counter.attr('data-vereine-waiting-url');
	var shown = {};
	try {
		shown = JSON.parse(window.sessionStorage.getItem('vereineWaiting') || '{}') || {};
	} catch (error) {
		shown = {};
	}

	function remember() {
		try {
			window.sessionStorage.setItem('vereineWaiting', JSON.stringify(shown));
		} catch (error) {
			// Without storage a notice may show again on the next page; nothing else changes.
		}
	}

	function check() {
		$.getJSON(address).done(function (answer) {
			if (!answer || !$.isArray(answer.items)) {
				return;
			}
			counter.find('.vereine-waiting-count').text(answer.items.length);
			counter.attr('data-vereine-waiting', answer.items.length);
			counter.toggle(answer.items.length > 0);
			if (answer.items.length) {
				counter.find('a').attr('href', answer.items[0].url);
			}
			$.each(answer.items, function (index, item) {
				if (shown[item.key]) {
					return;
				}
				shown[item.key] = 1;
				var link = $('<a>').attr('href', item.url).text(item.text);
				if ($.jnotify) {
					$.jnotify($('<div>').append(link).html(), 'warning', true);
				}
			});
			remember();
		});
	}

	check();
	window.setInterval(check, 30000);
});
