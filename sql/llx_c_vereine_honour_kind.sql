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

-- Kinds of honours (#274), a dictionary of Dolibarr the association extends itself. A jubilee and an
-- honorary membership keep what they do; every other kind is an honour with what it was given for.
CREATE TABLE llx_c_vereine_honour_kind(
	rowid INTEGER AUTO_INCREMENT PRIMARY KEY,
	entity INTEGER DEFAULT 1 NOT NULL,
	code VARCHAR(32) NOT NULL,
	label VARCHAR(128) NOT NULL,
	position INTEGER DEFAULT 0 NOT NULL,
	active TINYINT DEFAULT 1 NOT NULL
) ENGINE=innodb;
