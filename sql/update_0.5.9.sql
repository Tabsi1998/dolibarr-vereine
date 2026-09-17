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

-- 0.5.9: a meeting knows who presided and who kept the minutes.
-- Dolibarr runs update files on every activation and ignores a column that exists already.
ALTER TABLE llx_vereine_meeting ADD COLUMN fk_chair INTEGER DEFAULT 0 NOT NULL;
ALTER TABLE llx_vereine_meeting ADD COLUMN fk_keeper INTEGER DEFAULT 0 NOT NULL;
