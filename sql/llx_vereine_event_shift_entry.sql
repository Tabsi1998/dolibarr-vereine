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

-- One member on one shift (#23). A sign-up is not yet a duty done: the association confirms who is
-- taken, and afterwards notes who really was there. That note is what a volunteer allowance builds on.
CREATE TABLE llx_vereine_event_shift_entry(
	rowid INTEGER AUTO_INCREMENT PRIMARY KEY,
	entity INTEGER DEFAULT 1 NOT NULL,
	fk_shift INTEGER NOT NULL,
	fk_adherent INTEGER NOT NULL,
	status VARCHAR(16) DEFAULT 'requested' NOT NULL,
	source VARCHAR(16) DEFAULT 'dolibarr' NOT NULL,
	hours DECIMAL(6,2),
	note TEXT,
	datec DATETIME NOT NULL,
	tms TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
	fk_user_modif INTEGER
) ENGINE=innodb;
