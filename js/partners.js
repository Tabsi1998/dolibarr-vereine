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
 * Members and third parties (partners.php): "select all" per section and the
 * dialog with the steps for one row. The steps are forms and links in the page;
 * this file only shows them. Nothing is sent without a click on a step.
 */
jQuery(function ($) {
	function sectionBoxes(table, name) {
		return table.find('input[type="checkbox"]').filter(function () {
			return this.name === name && !this.disabled;
		});
	}

	$('.vereine-select-all').on('change', function () {
		sectionBoxes($(this).closest('table'), $(this).data('select')).prop('checked', this.checked);
	});

	$('input[type="checkbox"][name^="sel_"]').on('change', function () {
		var table = $(this).closest('table');
		var boxes = sectionBoxes(table, this.name);
		table.find('.vereine-select-all').prop('checked', boxes.length > 0 && boxes.filter(':checked').length === boxes.length);
	});

	function openDialog(id) {
		var dialog = $(document.getElementById(id));
		if (!dialog.length) {
			return;
		}
		if (!$.fn.dialog) {
			// Without jQuery UI the steps simply appear below the page.
			dialog.show();
			dialog[0].scrollIntoView();
			return;
		}
		dialog.dialog({
			modal: true,
			width: Math.min(520, $(window).width() - 32),
			buttons: [{
				text: dialog.data('cancel'),
				click: function () {
					$(this).dialog('close');
				}
			}]
		});
	}

	$('.vereine-row-button').on('click', function (event) {
		event.preventDefault();
		openDialog($(this).data('dialog'));
	});

	$('tr[data-dialog]').on('click', function (event) {
		// Names, ticks and buttons in the row keep doing what they do.
		if ($(event.target).closest('a, button, input, label, select, textarea').length) {
			return;
		}
		openDialog($(this).data('dialog'));
	});
});
