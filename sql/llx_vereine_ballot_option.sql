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

-- The options of a ballot with codes that never change: yes, no, abstain, or one per candidate with the candidate's consent.
CREATE TABLE llx_vereine_ballot_option(
	rowid INTEGER AUTO_INCREMENT PRIMARY KEY,
	fk_ballot INTEGER NOT NULL,
	code VARCHAR(16) NOT NULL,
	label VARCHAR(255),
	fk_adherent INTEGER DEFAULT 0 NOT NULL,
	consent SMALLINT DEFAULT 0 NOT NULL,
	position INTEGER DEFAULT 0 NOT NULL
) ENGINE=innodb;
