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

-- A helper shift of an event (#23): when, how many people it needs and who looks after it.
CREATE TABLE llx_vereine_event_shift(
	rowid INTEGER AUTO_INCREMENT PRIMARY KEY,
	entity INTEGER DEFAULT 1 NOT NULL,
	fk_event INTEGER NOT NULL,
	label VARCHAR(255) NOT NULL,
	shift_day DATE NOT NULL,
	start_time VARCHAR(5) DEFAULT '' NOT NULL,
	end_time VARCHAR(5) DEFAULT '' NOT NULL,
	capacity INTEGER DEFAULT 1 NOT NULL,
	function_code VARCHAR(32) DEFAULT '' NOT NULL,
	note TEXT,
	position INTEGER DEFAULT 0 NOT NULL,
	datec DATETIME NOT NULL,
	tms TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
	fk_user_modif INTEGER
) ENGINE=innodb;
