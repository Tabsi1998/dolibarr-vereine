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

-- A vote of the members on an agenda item of a general assembly (#160): the question, how it may be cast,
-- its status, and the rules frozen when it was released (majority, proxies, version of the statutes).
CREATE TABLE llx_vereine_ballot(
	rowid INTEGER AUTO_INCREMENT PRIMARY KEY,
	entity INTEGER DEFAULT 1 NOT NULL,
	fk_meeting INTEGER NOT NULL,
	item INTEGER DEFAULT 0 NOT NULL,
	kind VARCHAR(16) NOT NULL,
	question VARCHAR(255) NOT NULL,
	secret SMALLINT DEFAULT 0 NOT NULL,
	channels VARCHAR(32) NOT NULL,
	status VARCHAR(16) NOT NULL,
	closes VARCHAR(5),
	rules TEXT,
	quorum TEXT,
	fk_function INTEGER DEFAULT 0 NOT NULL,
	fk_vote INTEGER DEFAULT 0 NOT NULL,
	released_at DATETIME,
	opened_at DATETIME,
	closed_at DATETIME,
	datec DATETIME NOT NULL,
	tms TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
	fk_user_creat INTEGER,
	fk_user_modif INTEGER
) ENGINE=innodb;
