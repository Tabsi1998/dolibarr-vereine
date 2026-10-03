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

-- 1.7.0: the change feed names the state a ballot or an assembly reached (#271); its webhooks carry it too.
ALTER TABLE llx_vereine_change ADD COLUMN state VARCHAR(16) AFTER change_kind;
ALTER TABLE llx_vereine_hook_delivery ADD COLUMN state VARCHAR(16) AFTER change_kind;
-- 1.7.0: an assembly begins and ends at a moment of its own (#271).
ALTER TABLE llx_vereine_meeting ADD COLUMN started_at DATETIME AFTER invited_at;
ALTER TABLE llx_vereine_meeting ADD COLUMN ended_at DATETIME AFTER started_at;
