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

-- Honours of any kind of the dictionary, with an internal note and whether they may be published (#274).
ALTER TABLE llx_vereine_honour MODIFY COLUMN kind VARCHAR(32) NOT NULL;
ALTER TABLE llx_vereine_honour ADD COLUMN note TEXT AFTER given_on;
ALTER TABLE llx_vereine_honour ADD COLUMN publishable SMALLINT DEFAULT 0 NOT NULL AFTER note;
-- A change taken over at once because the board allowed one change without looking (#275).
ALTER TABLE llx_vereine_profile_request ADD COLUMN direct_once SMALLINT DEFAULT 0 NOT NULL AFTER status;
