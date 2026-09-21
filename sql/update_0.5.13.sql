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

-- 0.5.13: a failed invitation can be sent again; the proof counts the attempts.
ALTER TABLE llx_vereine_meeting_invitation ADD COLUMN attempts SMALLINT DEFAULT 1 NOT NULL AFTER error;
ALTER TABLE llx_vereine_meeting_invitation ADD COLUMN tried_at DATETIME AFTER attempts;
-- 0.5.13: an agenda item says whether it is a report, a discussion, a decision or an election.
ALTER TABLE llx_vereine_meeting_note ADD COLUMN kind VARCHAR(16) AFTER body;
