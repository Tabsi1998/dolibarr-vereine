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

-- What follows from a resolution or was agreed at an agenda item: a task with somebody responsible and a deadline,
-- kept as an agenda event of Dolibarr. An agreement without a resolution has fk_resolution 0 and names its meeting and item.
CREATE TABLE llx_vereine_resolution_task(
	rowid INTEGER AUTO_INCREMENT PRIMARY KEY,
	entity INTEGER DEFAULT 1 NOT NULL,
	fk_resolution INTEGER DEFAULT 0 NOT NULL,
	fk_meeting INTEGER DEFAULT 0 NOT NULL,
	item INTEGER DEFAULT 0 NOT NULL,
	label VARCHAR(255) NOT NULL,
	fk_adherent INTEGER DEFAULT 0 NOT NULL,
	deadline DATE,
	fk_actioncomm INTEGER,
	done_at DATETIME,
	datec DATETIME NOT NULL,
	fk_user_modif INTEGER
) ENGINE=innodb;
