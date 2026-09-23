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
CREATE TABLE llx_vereine_channel(
	rowid INTEGER AUTO_INCREMENT PRIMARY KEY,
	entity INTEGER DEFAULT 1 NOT NULL,
	network VARCHAR(32) NOT NULL,
	label VARCHAR(64) NOT NULL,
	target VARCHAR(255) NOT NULL,
	stream SMALLINT DEFAULT 0 NOT NULL,
	public SMALLINT DEFAULT 1 NOT NULL,
	position INTEGER DEFAULT 0 NOT NULL,
	datec DATETIME NOT NULL,
	tms TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
	fk_user_modif INTEGER
) ENGINE=innodb;
