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

-- The counted totals of a secret election on paper (#276): votes per option and the invalid ballot papers, never a
-- vote of a person. Who got a ballot paper is the used voting right; what was on it only ever appears as a sum here.
CREATE TABLE llx_vereine_ballot_total(
	rowid INTEGER AUTO_INCREMENT PRIMARY KEY,
	entity INTEGER DEFAULT 1 NOT NULL,
	fk_ballot INTEGER NOT NULL,
	option_code VARCHAR(32) NOT NULL,
	votes INTEGER DEFAULT 0 NOT NULL,
	fk_user INTEGER,
	datec DATETIME NOT NULL
) ENGINE=innodb;
