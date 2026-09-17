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

-- Final versions of the minutes of a meeting, each with its PDF and checksum.
CREATE TABLE llx_vereine_meeting_minutes(
	rowid INTEGER AUTO_INCREMENT PRIMARY KEY,
	entity INTEGER DEFAULT 1 NOT NULL,
	fk_meeting INTEGER NOT NULL,
	version INTEGER DEFAULT 1 NOT NULL,
	approved_on DATE,
	note VARCHAR(255),
	filename VARCHAR(255) NOT NULL,
	doc_sha VARCHAR(64) NOT NULL,
	sent_board DATETIME,
	sent_members DATETIME,
	datec DATETIME NOT NULL,
	fk_user_modif INTEGER
) ENGINE=innodb;
