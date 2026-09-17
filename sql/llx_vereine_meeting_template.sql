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

-- Agenda templates per kind of meeting with standard texts; without rows the templates of the country profile apply.
CREATE TABLE llx_vereine_meeting_template(
	rowid INTEGER AUTO_INCREMENT PRIMARY KEY,
	entity INTEGER DEFAULT 1 NOT NULL,
	kind VARCHAR(16) NOT NULL,
	position INTEGER DEFAULT 0 NOT NULL,
	title VARCHAR(255) NOT NULL,
	body TEXT,
	mandatory SMALLINT DEFAULT 0 NOT NULL,
	fk_user_modif INTEGER
) ENGINE=innodb;
