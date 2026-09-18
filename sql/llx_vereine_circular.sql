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

-- A circular resolution of the board: the motion, the deadline and what came out of it.
CREATE TABLE llx_vereine_circular(
	rowid INTEGER AUTO_INCREMENT PRIMARY KEY,
	entity INTEGER DEFAULT 1 NOT NULL,
	title VARCHAR(255) NOT NULL,
	wording TEXT NOT NULL,
	deadline DATE NOT NULL,
	status VARCHAR(16) NOT NULL,
	started_on DATE NOT NULL,
	reminded_at DATETIME,
	decided_on DATE,
	passed SMALLINT DEFAULT 0 NOT NULL,
	yes INTEGER DEFAULT 0 NOT NULL,
	no INTEGER DEFAULT 0 NOT NULL,
	abstain INTEGER DEFAULT 0 NOT NULL,
	objection INTEGER DEFAULT 0 NOT NULL,
	fk_resolution INTEGER DEFAULT 0 NOT NULL,
	datec DATETIME NOT NULL,
	fk_user_modif INTEGER
) ENGINE=innodb;
