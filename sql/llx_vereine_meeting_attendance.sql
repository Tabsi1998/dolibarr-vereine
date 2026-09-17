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

-- Attendance at a meeting: present with times, excused, absent, or represented by a proxy holder.
CREATE TABLE llx_vereine_meeting_attendance(
	rowid INTEGER AUTO_INCREMENT PRIMARY KEY,
	entity INTEGER DEFAULT 1 NOT NULL,
	fk_meeting INTEGER NOT NULL,
	fk_adherent INTEGER NOT NULL,
	state VARCHAR(16) NOT NULL,
	fk_holder INTEGER DEFAULT 0 NOT NULL,
	arrived VARCHAR(5),
	left_at VARCHAR(5),
	tms TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
	fk_user_modif INTEGER
) ENGINE=innodb;
