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

-- Equipment lent to a member (#26): the resource, the days out and due, the state when it went and came back,
-- and when the borrower was reminded.
CREATE TABLE llx_vereine_loan(
	rowid INTEGER AUTO_INCREMENT PRIMARY KEY,
	entity INTEGER DEFAULT 1 NOT NULL,
	fk_resource INTEGER NOT NULL,
	fk_adherent INTEGER NOT NULL,
	issued_on DATE NOT NULL,
	due_on DATE NOT NULL,
	returned_on DATE,
	condition_out VARCHAR(255),
	condition_in VARCHAR(255),
	reminded_at DATETIME,
	fk_user_out INTEGER,
	fk_user_in INTEGER,
	datec DATETIME NOT NULL
) ENGINE=innodb;
