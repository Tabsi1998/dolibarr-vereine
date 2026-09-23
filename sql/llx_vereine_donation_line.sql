-- Copyright (C) 2026 IT-Tabelander <https://it.tabelander.co.at>
--
-- This program is free software: you can redistribute it and/or modify
-- it under the terms of the GNU General Public License as published by
-- the Free Software Foundation, either version 3 of the License, or
-- (at your option) any later version.
--
-- This program is distributed in the hope that it will be useful,
-- but WITHOUT ANY WARRANTY; without even the implied warranty of
-- MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
-- GNU General Public License for more details.
--
-- You should have received a copy of the GNU General Public License
-- along with this program.  If not, see https://www.gnu.org/licenses/.

-- One donor's year in a transmission (#6): first, change or cancellation, and whether the tax office took it.
CREATE TABLE llx_vereine_donation_line(
	rowid INTEGER AUTO_INCREMENT PRIMARY KEY,
	entity INTEGER DEFAULT 1 NOT NULL,
	fk_report INTEGER NOT NULL,
	fk_donor INTEGER NOT NULL,
	fiscal_year INTEGER NOT NULL,
	refnr VARCHAR(23) NOT NULL,
	kind VARCHAR(1) NOT NULL,
	amount DOUBLE(24,8) DEFAULT 0 NOT NULL,
	state VARCHAR(16) DEFAULT 'sent' NOT NULL,
	error TEXT
) ENGINE=innodb;
