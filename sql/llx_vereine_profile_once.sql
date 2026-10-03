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

-- The board lets a member change their own data once without looking at it (#275): when and by whom it was
-- allowed, and the change that used it, or when it was taken back.
CREATE TABLE llx_vereine_profile_once(
	rowid INTEGER AUTO_INCREMENT PRIMARY KEY,
	entity INTEGER DEFAULT 1 NOT NULL,
	fk_adherent INTEGER NOT NULL,
	granted_at DATETIME NOT NULL,
	fk_user_granted INTEGER,
	used_at DATETIME,
	fk_request INTEGER,
	revoked_at DATETIME,
	fk_user_revoked INTEGER
) ENGINE=innodb;
