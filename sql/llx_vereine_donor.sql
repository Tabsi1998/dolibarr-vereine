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

-- A donor as the donation report needs one (#6): the date of birth encrypted with Dolibarr's key, the
-- reference number of the payer, and the encrypted identifier for taxes (vbPK SA) from the register.
CREATE TABLE llx_vereine_donor(
	rowid INTEGER AUTO_INCREMENT PRIMARY KEY,
	entity INTEGER DEFAULT 1 NOT NULL,
	fk_soc INTEGER,
	fk_adherent INTEGER,
	firstname VARCHAR(128),
	lastname VARCHAR(128),
	birth VARCHAR(255),
	refnr VARCHAR(23) NOT NULL,
	vbpk VARCHAR(172),
	vbpk_state VARCHAR(16),
	given_on DATE,
	datec DATETIME NOT NULL,
	tms TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
	fk_user_modif INTEGER
) ENGINE=innodb;
