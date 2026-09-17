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

-- One person who has to sign a document, with when and how the signature was given.
CREATE TABLE llx_vereine_signature_person(
	rowid INTEGER AUTO_INCREMENT PRIMARY KEY,
	entity INTEGER DEFAULT 1 NOT NULL,
	fk_signature INTEGER NOT NULL,
	fk_adherent INTEGER NOT NULL,
	function_code VARCHAR(32) NOT NULL,
	function_label VARCHAR(255) NOT NULL,
	person_name VARCHAR(255) NOT NULL,
	signed_at DATETIME,
	way VARCHAR(16),
	fk_user_signed INTEGER
) ENGINE=innodb;
