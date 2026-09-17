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

-- Votes and elections in a meeting with their result and what they changed.
CREATE TABLE llx_vereine_meeting_vote(
	rowid INTEGER AUTO_INCREMENT PRIMARY KEY,
	entity INTEGER DEFAULT 1 NOT NULL,
	fk_meeting INTEGER NOT NULL,
	item INTEGER NOT NULL,
	kind VARCHAR(16) NOT NULL,
	title VARCHAR(255) NOT NULL,
	secret SMALLINT DEFAULT 0 NOT NULL,
	yes INTEGER DEFAULT 0 NOT NULL,
	no INTEGER DEFAULT 0 NOT NULL,
	abstain INTEGER DEFAULT 0 NOT NULL,
	tie VARCHAR(3),
	vote_time VARCHAR(5),
	majority VARCHAR(16) NOT NULL,
	passed SMALLINT DEFAULT 0 NOT NULL,
	fk_function INTEGER DEFAULT 0 NOT NULL,
	fk_candidate INTEGER DEFAULT 0 NOT NULL,
	applied VARCHAR(64),
	datec DATETIME NOT NULL,
	fk_user_modif INTEGER
) ENGINE=innodb;
