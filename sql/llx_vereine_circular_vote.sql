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

-- Who of the board took part in a circular resolution, and how.
CREATE TABLE llx_vereine_circular_vote(
	rowid INTEGER AUTO_INCREMENT PRIMARY KEY,
	entity INTEGER DEFAULT 1 NOT NULL,
	fk_circular INTEGER NOT NULL,
	fk_adherent INTEGER NOT NULL,
	person_name VARCHAR(255) NOT NULL,
	function_label VARCHAR(255),
	email VARCHAR(255),
	choice VARCHAR(16),
	voted_at DATETIME,
	invited_at DATETIME,
	datec DATETIME NOT NULL
) ENGINE=innodb;
