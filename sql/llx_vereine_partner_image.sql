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

-- Pictures of a partner or sponsor for the website (#278): a logo and a banner, each for a light and for a dark
-- background. The file lies with the third party in Dolibarr's documents and goes when the third party goes.
CREATE TABLE llx_vereine_partner_image(
	rowid INTEGER AUTO_INCREMENT PRIMARY KEY,
	entity INTEGER DEFAULT 1 NOT NULL,
	fk_soc INTEGER NOT NULL,
	kind VARCHAR(16) NOT NULL,
	variant VARCHAR(8) NOT NULL,
	filename VARCHAR(64) NOT NULL,
	content_type VARCHAR(32) NOT NULL,
	filesize INTEGER NOT NULL,
	width INTEGER DEFAULT 0 NOT NULL,
	height INTEGER DEFAULT 0 NOT NULL,
	sha256 VARCHAR(64) NOT NULL,
	datec DATETIME NOT NULL,
	fk_user INTEGER
) ENGINE=innodb;
