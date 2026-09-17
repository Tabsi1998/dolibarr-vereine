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

-- Meetings of the board and general assemblies.
CREATE TABLE llx_vereine_meeting(
	rowid INTEGER AUTO_INCREMENT PRIMARY KEY,
	entity INTEGER DEFAULT 1 NOT NULL,
	kind VARCHAR(16) NOT NULL,
	title VARCHAR(255) NOT NULL,
	meeting_day DATE NOT NULL,
	meeting_time VARCHAR(5) NOT NULL,
	place VARCHAR(255),
	format VARCHAR(16) NOT NULL,
	access TEXT,
	agenda TEXT,
	status VARCHAR(16) NOT NULL,
	invited_at DATETIME,
	fk_actioncomm INTEGER,
	datec DATETIME NOT NULL,
	tms TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
	fk_user_modif INTEGER
) ENGINE=innodb;
