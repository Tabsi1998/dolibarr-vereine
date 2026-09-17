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

-- Functions of the association: board, representation, auditors, how many are needed.
CREATE TABLE llx_vereine_function(
	rowid INTEGER AUTO_INCREMENT PRIMARY KEY,
	entity INTEGER DEFAULT 1 NOT NULL,
	code VARCHAR(32) NOT NULL,
	label VARCHAR(128) NOT NULL,
	board SMALLINT DEFAULT 0 NOT NULL,
	represents SMALLINT DEFAULT 0 NOT NULL,
	auditor SMALLINT DEFAULT 0 NOT NULL,
	min_count INTEGER DEFAULT 0 NOT NULL,
	max_count INTEGER DEFAULT 0 NOT NULL,
	position INTEGER DEFAULT 0 NOT NULL,
	active SMALLINT DEFAULT 1 NOT NULL,
	datec DATETIME NOT NULL,
	tms TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
	fk_user_modif INTEGER
) ENGINE=innodb;
