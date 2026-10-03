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

-- What a member took part in (#273): an event, a competition, a helper service. Recorded in Dolibarr or
-- through an application with its own id; confirmed helper shifts are read from the shifts and never kept twice.
CREATE TABLE llx_vereine_participation(
	rowid INTEGER AUTO_INCREMENT PRIMARY KEY,
	entity INTEGER DEFAULT 1 NOT NULL,
	fk_adherent INTEGER NOT NULL,
	kind VARCHAR(32) NOT NULL,
	title VARCHAR(255) NOT NULL,
	day DATE NOT NULL,
	hours DECIMAL(6,2),
	source VARCHAR(16) DEFAULT 'dolibarr' NOT NULL,
	client VARCHAR(64),
	external_id VARCHAR(64),
	datec DATETIME NOT NULL,
	fk_user INTEGER
) ENGINE=innodb;
