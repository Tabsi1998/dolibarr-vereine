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

-- A request for access to one's own data (#10, Art. 15 GDPR): when it came in, how the person was
-- checked, when the copy went out and the checksum of what went out. The copy itself is not kept.
CREATE TABLE llx_vereine_arrear(
	rowid INTEGER AUTO_INCREMENT PRIMARY KEY,
	entity INTEGER DEFAULT 1 NOT NULL,
	fk_case INTEGER NOT NULL,
	fk_facture INTEGER NOT NULL,
	fk_adherent INTEGER NOT NULL,
	fk_subscription INTEGER DEFAULT 0 NOT NULL,
	level INTEGER DEFAULT 0 NOT NULL,
	state VARCHAR(16) NOT NULL,
	case_revision INTEGER DEFAULT 0 NOT NULL,
	event_id VARCHAR(128),
	fk_meeting INTEGER DEFAULT 0 NOT NULL,
	datec DATETIME NOT NULL,
	date_state DATETIME,
	tms TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=innodb;
