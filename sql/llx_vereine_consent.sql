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

-- Consents of members: every consent and every withdrawal is a row, nothing is changed or deleted.
CREATE TABLE llx_vereine_consent(
	rowid INTEGER AUTO_INCREMENT PRIMARY KEY,
	entity INTEGER DEFAULT 1 NOT NULL,
	fk_adherent INTEGER NOT NULL,
	code VARCHAR(32) NOT NULL,
	version INTEGER NOT NULL,
	given SMALLINT NOT NULL,
	source VARCHAR(16) NOT NULL,
	date_event DATETIME NOT NULL,
	note VARCHAR(255),
	fk_user INTEGER,
	tms TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=innodb;
