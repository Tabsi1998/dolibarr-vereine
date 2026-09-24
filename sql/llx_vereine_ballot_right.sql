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

-- The voting rights of a ballot, frozen when it opens (#160): one per invited member, who holds it (the member or the
-- holder of a proxy), why, and when it was used. A right is used once, whichever way the vote came (#161).
CREATE TABLE llx_vereine_ballot_right(
	rowid INTEGER AUTO_INCREMENT PRIMARY KEY,
	entity INTEGER DEFAULT 1 NOT NULL,
	fk_ballot INTEGER NOT NULL,
	fk_adherent INTEGER NOT NULL,
	fk_holder INTEGER DEFAULT 0 NOT NULL,
	reason VARCHAR(16) NOT NULL,
	eligible SMALLINT DEFAULT 0 NOT NULL,
	used_at DATETIME,
	channel VARCHAR(16)
) ENGINE=innodb;
