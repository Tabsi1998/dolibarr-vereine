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

-- The website profile of a member (#255): what the association publishes about the member on its own
-- website, kept by the board on the tab Association of the member card. The photo is Dolibarr's own
-- member photo and is not duplicated here. The website reads it only with the consent the association
-- chose for it (VEREINE_WEBSITE_PROFILE_CONSENT).
CREATE TABLE llx_vereine_member_profile(
	rowid INTEGER AUTO_INCREMENT PRIMARY KEY,
	entity INTEGER DEFAULT 1 NOT NULL,
	fk_adherent INTEGER NOT NULL,
	gamertag VARCHAR(40) DEFAULT '' NOT NULL,
	bio TEXT,
	games VARCHAR(255) DEFAULT '' NOT NULL,
	platforms VARCHAR(255) DEFAULT '' NOT NULL,
	datec DATETIME NOT NULL,
	tms TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
	fk_user_modif INTEGER
) ENGINE=innodb;
