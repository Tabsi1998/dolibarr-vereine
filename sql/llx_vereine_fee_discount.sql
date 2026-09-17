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

-- Fee discounts: by age on the first day of a period, or with a proof such as a student card.
CREATE TABLE llx_vereine_fee_discount(
	rowid INTEGER AUTO_INCREMENT PRIMARY KEY,
	entity INTEGER DEFAULT 1 NOT NULL,
	label VARCHAR(64) NOT NULL,
	kind VARCHAR(16) NOT NULL,
	fk_adherent_type INTEGER DEFAULT 0 NOT NULL,
	age_from INTEGER,
	age_to INTEGER,
	mode VARCHAR(16) NOT NULL,
	value DOUBLE(24,8) DEFAULT 0 NOT NULL,
	active SMALLINT DEFAULT 1 NOT NULL,
	position INTEGER DEFAULT 0 NOT NULL,
	datec DATETIME NOT NULL,
	tms TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
	fk_user_modif INTEGER
) ENGINE=innodb;
