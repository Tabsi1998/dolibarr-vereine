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

-- One point of an event template (#23): its phase, how many days before or after the day it is due,
-- and the function that looks after it.
CREATE TABLE llx_vereine_event_template_task(
	rowid INTEGER AUTO_INCREMENT PRIMARY KEY,
	entity INTEGER DEFAULT 1 NOT NULL,
	fk_template INTEGER NOT NULL,
	phase VARCHAR(16) DEFAULT 'before' NOT NULL,
	label VARCHAR(255) NOT NULL,
	function_code VARCHAR(32) DEFAULT '' NOT NULL,
	offset_days INTEGER DEFAULT 0 NOT NULL,
	source VARCHAR(64) DEFAULT '' NOT NULL,
	note TEXT,
	position INTEGER DEFAULT 0 NOT NULL,
	datec DATETIME NOT NULL,
	tms TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
	fk_user_modif INTEGER
) ENGINE=innodb;
