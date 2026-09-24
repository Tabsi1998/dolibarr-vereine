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

-- One revision of a document of the association's files published for an audience (#156): the board, the
-- members, the public, or one person (#239, fk_adherent); withdrawn or replaced, it stays as a record.
CREATE TABLE llx_vereine_publication(
	rowid INTEGER AUTO_INCREMENT PRIMARY KEY,
	entity INTEGER DEFAULT 1 NOT NULL,
	fk_document INTEGER NOT NULL,
	fk_file INTEGER NOT NULL,
	audience VARCHAR(16) NOT NULL,
	fk_adherent INTEGER DEFAULT 0 NOT NULL,
	published_at DATETIME NOT NULL,
	fk_user INTEGER,
	withdrawn_at DATETIME,
	fk_user_withdrawn INTEGER,
	reason VARCHAR(16)
) ENGINE=innodb;
