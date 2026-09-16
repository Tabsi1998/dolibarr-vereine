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

-- Tax profiles of the association: sphere, VAT treatment, rate and invoice note.
-- Standard profiles are added on activation when their code is missing; the
-- association's changes are never overwritten.

CREATE TABLE llx_vereine_taxprofile(
	rowid INTEGER AUTO_INCREMENT PRIMARY KEY,
	entity INTEGER DEFAULT 1 NOT NULL,
	code VARCHAR(32) NOT NULL,
	label VARCHAR(255) NOT NULL,
	sphere VARCHAR(16) NOT NULL,
	treatment VARCHAR(16) NOT NULL,
	rate DOUBLE(6,3) DEFAULT 0 NOT NULL,
	note TEXT,
	active SMALLINT DEFAULT 1 NOT NULL,
	standard SMALLINT DEFAULT 0 NOT NULL,
	position INTEGER DEFAULT 0 NOT NULL,
	datec DATETIME NOT NULL,
	tms TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
	fk_user_modif INTEGER
) ENGINE=innodb;
