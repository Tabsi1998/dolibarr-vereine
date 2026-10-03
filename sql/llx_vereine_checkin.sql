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

-- Check-ins at general assemblies through applications (#272): which application marked whom present or took
-- it back, in the name of whom on the board, when and why. The id of the request makes a repetition harmless.
CREATE TABLE llx_vereine_checkin(
	rowid INTEGER AUTO_INCREMENT PRIMARY KEY,
	entity INTEGER DEFAULT 1 NOT NULL,
	fk_meeting INTEGER NOT NULL,
	fk_adherent INTEGER NOT NULL,
	external_id VARCHAR(64) NOT NULL,
	client VARCHAR(64) NOT NULL,
	fk_actor INTEGER NOT NULL,
	action VARCHAR(8) NOT NULL,
	arrived VARCHAR(5),
	reason VARCHAR(255) DEFAULT '' NOT NULL,
	fk_user INTEGER,
	datec DATETIME NOT NULL
) ENGINE=innodb;
