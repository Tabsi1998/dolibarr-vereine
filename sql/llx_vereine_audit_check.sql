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

-- A booking or invoice the auditors checked, with their note.
CREATE TABLE llx_vereine_audit_check(
	rowid INTEGER AUTO_INCREMENT PRIMARY KEY,
	entity INTEGER DEFAULT 1 NOT NULL,
	fiscal_year INTEGER NOT NULL,
	element VARCHAR(16) NOT NULL,
	fk_object INTEGER NOT NULL,
	note VARCHAR(255),
	fk_user INTEGER NOT NULL,
	datec DATETIME NOT NULL
) ENGINE=innodb;
