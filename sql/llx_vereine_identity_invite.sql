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

-- A one-time invitation that turns a name at a client into a binding (#153). Only the hash of the code
-- is kept, so a look into the database hands nobody a way in. It is short lived and dies on first use.
CREATE TABLE llx_vereine_identity_invite(
	rowid INTEGER AUTO_INCREMENT PRIMARY KEY,
	entity INTEGER DEFAULT 1 NOT NULL,
	client VARCHAR(64) NOT NULL,
	fk_adherent INTEGER,
	fk_application INTEGER,
	capabilities VARCHAR(255) DEFAULT '' NOT NULL,
	code_hash VARCHAR(64) NOT NULL,
	expires_at DATETIME NOT NULL,
	used_at DATETIME,
	used_subject VARCHAR(128) DEFAULT '' NOT NULL,
	datec DATETIME NOT NULL,
	fk_user_modif INTEGER
) ENGINE=innodb;
