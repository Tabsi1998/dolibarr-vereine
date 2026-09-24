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

-- A count of a closed ballot (#163): the snapshot it rests on, its proof as PDF with the checksum, whether it is provisional,
-- confirmed or replaced by a later count with the reason, and the vote of the meeting its confirmation made.
CREATE TABLE llx_vereine_ballot_result(
	rowid INTEGER AUTO_INCREMENT PRIMARY KEY,
	entity INTEGER DEFAULT 1 NOT NULL,
	fk_ballot INTEGER NOT NULL,
	revision INTEGER NOT NULL,
	snapshot MEDIUMTEXT NOT NULL,
	reason VARCHAR(255),
	status VARCHAR(16) NOT NULL,
	filename VARCHAR(255),
	doc_sha VARCHAR(64),
	fk_vote INTEGER DEFAULT 0 NOT NULL,
	datec DATETIME NOT NULL,
	fk_user INTEGER,
	confirmed_at DATETIME,
	fk_user_confirmed INTEGER
) ENGINE=innodb;
