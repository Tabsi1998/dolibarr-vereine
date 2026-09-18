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

-- The register of resolutions: every resolution of a meeting or a circular resolution, searchable.
CREATE TABLE llx_vereine_resolution(
	rowid INTEGER AUTO_INCREMENT PRIMARY KEY,
	entity INTEGER DEFAULT 1 NOT NULL,
	ref VARCHAR(32) NOT NULL,
	source VARCHAR(16) NOT NULL,
	fk_meeting INTEGER DEFAULT 0 NOT NULL,
	fk_vote INTEGER DEFAULT 0 NOT NULL,
	organ VARCHAR(16) NOT NULL,
	kind VARCHAR(16) NOT NULL,
	resolution_day DATE NOT NULL,
	item INTEGER DEFAULT 0 NOT NULL,
	title VARCHAR(255) NOT NULL,
	wording TEXT,
	category VARCHAR(24) NOT NULL,
	passed SMALLINT DEFAULT 0 NOT NULL,
	yes INTEGER DEFAULT 0 NOT NULL,
	no INTEGER DEFAULT 0 NOT NULL,
	abstain INTEGER DEFAULT 0 NOT NULL,
	majority VARCHAR(16) NOT NULL,
	valid_from DATE,
	valid_to DATE,
	fk_adherent INTEGER DEFAULT 0 NOT NULL,
	fk_facture INTEGER DEFAULT 0 NOT NULL,
	money SMALLINT DEFAULT 0 NOT NULL,
	applied VARCHAR(64),
	note TEXT,
	datec DATETIME NOT NULL,
	fk_user_modif INTEGER
) ENGINE=innodb;
