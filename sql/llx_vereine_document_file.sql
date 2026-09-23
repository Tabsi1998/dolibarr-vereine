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

-- Every file of a finished document with its SHA-256: as built, signed with ID Austria, the signed paper as a scan (#123).
CREATE TABLE llx_vereine_document_file(
	rowid INTEGER AUTO_INCREMENT PRIMARY KEY,
	fk_document INTEGER NOT NULL,
	sha256 VARCHAR(64) NOT NULL,
	relpath VARCHAR(255) NOT NULL,
	what VARCHAR(16) DEFAULT 'built' NOT NULL,
	datec DATETIME NOT NULL
) ENGINE=innodb;
