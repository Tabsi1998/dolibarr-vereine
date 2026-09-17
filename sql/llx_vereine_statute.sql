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

-- Versions of the statutes: generated from the rules or uploaded, with the day of the resolution and the PDF.
CREATE TABLE llx_vereine_statute(
	rowid INTEGER AUTO_INCREMENT PRIMARY KEY,
	entity INTEGER DEFAULT 1 NOT NULL,
	version INTEGER NOT NULL,
	decided_on DATE NOT NULL,
	valid_from DATE NOT NULL,
	source VARCHAR(16) NOT NULL,
	filename VARCHAR(255) NOT NULL,
	sha256 VARCHAR(64) NOT NULL,
	content MEDIUMTEXT,
	note VARCHAR(255),
	datec DATETIME NOT NULL,
	tms TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
	fk_user_modif INTEGER
) ENGINE=innodb;
