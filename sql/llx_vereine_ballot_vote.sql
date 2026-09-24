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

-- A vote of an open ballot (#161): the right it used, the option, which way it came and the application's id of the request.
CREATE TABLE llx_vereine_ballot_vote(
	rowid INTEGER AUTO_INCREMENT PRIMARY KEY,
	entity INTEGER DEFAULT 1 NOT NULL,
	fk_ballot INTEGER NOT NULL,
	fk_right INTEGER NOT NULL,
	option_code VARCHAR(16) NOT NULL,
	fk_cast_by INTEGER DEFAULT 0 NOT NULL,
	channel VARCHAR(16) NOT NULL,
	client VARCHAR(64),
	external_id VARCHAR(64),
	cast_at DATETIME NOT NULL,
	fk_user INTEGER
) ENGINE=innodb;
