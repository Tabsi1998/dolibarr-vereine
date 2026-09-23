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

-- An event of the association (#23), kept as a project of Dolibarr so budget, documents and time stay
-- where Dolibarr already keeps them.
CREATE TABLE llx_vereine_event(
	rowid INTEGER AUTO_INCREMENT PRIMARY KEY,
	entity INTEGER DEFAULT 1 NOT NULL,
	fk_template INTEGER,
	label VARCHAR(255) NOT NULL,
	event_day DATE NOT NULL,
	end_day DATE,
	place VARCHAR(255) DEFAULT '' NOT NULL,
	public TINYINT DEFAULT 0 NOT NULL,
	-- Who leads the sign-up: nobody, Dolibarr's own event organisation, or an external client (#165).
	registration VARCHAR(16) DEFAULT 'none' NOT NULL,
	external_ref VARCHAR(64) DEFAULT '' NOT NULL,
	status VARCHAR(16) DEFAULT 'planned' NOT NULL,
	fk_projet INTEGER,
	fk_actioncomm INTEGER,
	note TEXT,
	datec DATETIME NOT NULL,
	tms TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
	fk_user_modif INTEGER
) ENGINE=innodb;
