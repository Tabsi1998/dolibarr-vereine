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

-- What changed about an object an external application may follow (#154). The row carries a note of
-- the change, never the change itself: no names, no amounts, no invoice contents, no signatures.
-- The row is written inside the transaction of the change, so a rollback takes it with it.
CREATE TABLE llx_vereine_change(
	rowid INTEGER AUTO_INCREMENT PRIMARY KEY,
	entity INTEGER DEFAULT 1 NOT NULL,
	-- Stable over repetitions: the same change written twice keeps the same event_id.
	event_id VARCHAR(64) NOT NULL,
	object_type VARCHAR(32) NOT NULL,
	object_id INTEGER NOT NULL,
	revision INTEGER DEFAULT 1 NOT NULL,
	change_kind VARCHAR(16) NOT NULL,
	occurred_at DATETIME NOT NULL,
	fk_user INTEGER,
	datec DATETIME NOT NULL
) ENGINE=innodb;
